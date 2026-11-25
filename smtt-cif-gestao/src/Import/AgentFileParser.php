<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Import;

use RuntimeException;

final class AgentFileParser
{
    private const MAX_BYTES = 10_485_760; // 10 MiB
    private const MAX_ROWS = 5000;

    /** @return array{format:string,rows:list<array{line:int,data:array<string,string>}>} */
    public function parse(string $path, string $originalName): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo de importação não foi localizado.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0) {
            throw new RuntimeException('O arquivo enviado está vazio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('O arquivo excede o limite de 10 MB.');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($extension) {
            'csv' => ['format' => 'CSV', 'rows' => $this->parseCsv($path)],
            'xlsx' => ['format' => 'XLSX', 'rows' => $this->parseXlsx($path)],
            default => throw new RuntimeException('Formato não suportado. Utilize arquivo CSV ou XLSX.'),
        };
    }

    /** @return list<array{line:int,data:array<string,string>}> */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo CSV.');
        }

        try {
            $first = fgets($handle);
            if (!is_string($first)) {
                throw new RuntimeException('O CSV não contém cabeçalho.');
            }
            $delimiter = $this->detectDelimiter($first);
            rewind($handle);

            $header = fgetcsv($handle, 0, $delimiter);
            if (!is_array($header)) {
                throw new RuntimeException('Não foi possível interpretar o cabeçalho do CSV.');
            }
            $headers = $this->normalizeHeaders($header);
            $this->assertRequiredHeaders($headers);

            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                ++$line;
                if ($line > self::MAX_ROWS + 1) {
                    throw new RuntimeException('A importação aceita no máximo ' . self::MAX_ROWS . ' linhas de dados por arquivo.');
                }
                if ($this->rowIsEmpty($values)) {
                    continue;
                }
                $rows[] = ['line' => $line, 'data' => $this->combineRow($headers, $values)];
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{line:int,data:array<string,string>}> */
    private function parseXlsx(string $path): array
    {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('A importação XLSX requer as extensões PHP zip e SimpleXML. Utilize CSV neste servidor ou habilite essas extensões.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($path);
        if ($opened !== true) {
            throw new RuntimeException('Não foi possível abrir o arquivo XLSX.');
        }

        try {
            $this->assertSafeZip($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if (!is_string($sheetXml)) {
                throw new RuntimeException('A primeira planilha do XLSX não pôde ser lida.');
            }
            $sheet = $this->loadXml($sheetXml, 'planilha XLSX');
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rowNodes = $sheet->xpath('//m:sheetData/m:row');
            if (!is_array($rowNodes) || $rowNodes === []) {
                throw new RuntimeException('O XLSX não contém linhas na primeira planilha.');
            }

            $matrix = [];
            foreach ($rowNodes as $rowNode) {
                $rowNumber = isset($rowNode['r']) ? (int) $rowNode['r'] : count($matrix) + 1;
                if ($rowNumber > self::MAX_ROWS + 1) {
                    throw new RuntimeException('A importação aceita no máximo ' . self::MAX_ROWS . ' linhas de dados por arquivo.');
                }
                $cells = [];
                $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cellNodes = $rowNode->xpath('./m:c');
                if (is_array($cellNodes)) {
                    foreach ($cellNodes as $cell) {
                        $ref = (string) ($cell['r'] ?? '');
                        $index = $this->columnIndex($ref);
                        if ($index < 0) {
                            continue;
                        }
                        $cells[$index] = $this->xlsxCellValue($cell, $sharedStrings);
                    }
                }
                if ($cells !== []) {
                    ksort($cells);
                    $max = max(array_keys($cells));
                    $filled = [];
                    for ($i = 0; $i <= $max; ++$i) {
                        $filled[] = $cells[$i] ?? '';
                    }
                    $matrix[] = ['line' => $rowNumber, 'values' => $filled];
                }
            }

            if ($matrix === []) {
                throw new RuntimeException('O XLSX não contém cabeçalho utilizável.');
            }
            $first = array_shift($matrix);
            $headers = $this->normalizeHeaders($first['values']);
            $this->assertRequiredHeaders($headers);

            $rows = [];
            foreach ($matrix as $row) {
                if ($this->rowIsEmpty($row['values'])) {
                    continue;
                }
                $rows[] = ['line' => (int) $row['line'], 'data' => $this->combineRow($headers, $row['values'])];
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function assertSafeZip(\ZipArchive $zip): void
    {
        if ($zip->numFiles > 200) {
            throw new RuntimeException('O XLSX contém uma quantidade inesperada de arquivos internos.');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '../') || str_starts_with($name, '/')) {
                throw new RuntimeException('O XLSX contém caminho interno inválido.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > 52_428_800) { // 50 MiB descompactados
                throw new RuntimeException('O XLSX excede o limite de conteúdo descompactado permitido.');
            }
        }
    }

    /** @return list<string> */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml)) {
            return [];
        }
        $doc = $this->loadXml($xml, 'sharedStrings');
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $nodes = $doc->xpath('//m:si');
        $strings = [];
        if (!is_array($nodes)) {
            return $strings;
        }
        foreach ($nodes as $node) {
            $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = $node->xpath('.//m:t');
            $value = '';
            if (is_array($texts)) {
                foreach ($texts as $text) {
                    $value .= (string) $text;
                }
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private function firstWorksheetPath(\ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($workbookXml) || !is_string($relsXml)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = $this->loadXml($workbookXml, 'workbook');
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbook->xpath('//m:sheets/m:sheet');
        if (!is_array($sheets) || $sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }
        $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relId = (string) ($attrs['id'] ?? '');
        if ($relId === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = $this->loadXml($relsXml, 'relacionamentos do workbook');
        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationships = $rels->xpath('//r:Relationship');
        if (is_array($relationships)) {
            foreach ($relationships as $relationship) {
                if ((string) ($relationship['Id'] ?? '') !== $relId) {
                    continue;
                }
                $target = ltrim((string) ($relationship['Target'] ?? ''), '/');
                if ($target !== '') {
                    if (str_starts_with($target, 'xl/')) {
                        return $target;
                    }
                    return 'xl/' . $target;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @param list<string> $sharedStrings */
    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//m:is//m:t');
            $value = '';
            if (is_array($texts)) {
                foreach ($texts as $text) {
                    $value .= (string) $text;
                }
            }
            return trim($value);
        }

        $values = $cell->xpath('./m:v');
        $raw = is_array($values) && isset($values[0]) ? (string) $values[0] : '';
        if ($type === 's') {
            $index = (int) $raw;
            return trim($sharedStrings[$index] ?? '');
        }
        return trim($raw);
    }

    private function loadXml(string $xml, string $label): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if (!$parsed instanceof \SimpleXMLElement) {
                throw new RuntimeException('XML inválido em ' . $label . '.');
            }
            return $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ';';
    }

    /** @param list<mixed> $headers @return list<string> */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            $value = $this->toUtf8((string) $header);
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
            $value = strtolower(trim($value));
            if (function_exists('remove_accents')) {
                $value = remove_accents($value);
            } else {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
                if (is_string($converted)) {
                    $value = strtolower($converted);
                }
            }
            $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
            $value = trim($value, '_');
            $normalized[] = $this->headerAlias($value);
        }
        return $normalized;
    }

    private function headerAlias(string $header): string
    {
        return match ($header) {
            'matricula', 'registro', 'registro_funcional' => 'matricula',
            'nome', 'nome_completo' => 'nome_completo',
            'cpf' => 'cpf',
            'naturalidade' => 'naturalidade',
            'nascimento', 'data_nascimento' => 'data_nascimento',
            'tipo_sangue', 'tipo_sanguineo', 'sangue' => 'tipo_sanguineo',
            'admissao', 'data_admissao' => 'data_admissao',
            'situacao', 'status', 'situacao_funcional' => 'situacao_funcional',
            'fonte', 'referencia', 'fonte_referencia' => 'fonte_referencia',
            default => $header,
        };
    }

    /** @param list<string> $headers */
    private function assertRequiredHeaders(array $headers): void
    {
        $required = [
            'matricula', 'nome_completo', 'cpf', 'naturalidade', 'data_nascimento',
            'tipo_sanguineo', 'data_admissao', 'situacao_funcional',
        ];
        $missing = array_values(array_diff($required, $headers));
        if ($missing !== []) {
            throw new RuntimeException('Colunas obrigatórias ausentes: ' . implode(', ', $missing) . '.');
        }
        if (count($headers) !== count(array_unique($headers))) {
            throw new RuntimeException('O arquivo possui colunas duplicadas no cabeçalho.');
        }
    }

    /** @param list<string> $headers @param list<mixed> $values @return array<string,string> */
    private function combineRow(array $headers, array $values): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $row[$header] = $this->toUtf8((string) ($values[$index] ?? ''));
        }
        return $row;
    }

    /** @param list<mixed> $values */
    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function toUtf8(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('//u', $value) === 1) {
            return $value;
        }
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        return is_string($converted) ? trim($converted) : $value;
    }

    private function columnIndex(string $cellReference): int
    {
        if (preg_match('/^([A-Z]+)[0-9]+$/i', $cellReference, $matches) !== 1) {
            return -1;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; ++$i) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }
}
