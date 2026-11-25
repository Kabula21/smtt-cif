<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Import;

use RuntimeException;

final class AgentTemplateBuilder
{
    /** @return list<string> */
    public function headers(): array
    {
        return [
            'matricula',
            'nome_completo',
            'cpf',
            'naturalidade',
            'data_nascimento',
            'tipo_sanguineo',
            'data_admissao',
            'situacao_funcional',
            'fonte_referencia',
        ];
    }

    public function csv(): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Não foi possível gerar o modelo CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $this->headers(), ';');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($content)) {
            throw new RuntimeException('Não foi possível finalizar o modelo CSV.');
        }
        return $content;
    }

    public function xlsxFile(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('A geração do modelo XLSX requer a extensão PHP zip. Utilize o modelo CSV neste servidor.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'smtt-cif-xlsx-');
        if (!is_string($temp)) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para o modelo XLSX.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            throw new RuntimeException('Não foi possível montar o modelo XLSX.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml([$this->headers()]));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml($this->instructions()));
        } finally {
            $zip->close();
        }

        return $temp;
    }

    private function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
    }

    private function rootRels(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function workbook(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="AGENTES" sheetId="1" r:id="rId1"/>
    <sheet name="INSTRUCOES" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRels(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>
XML;
    }

    /** @param list<list<string>> $rows */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $xml .= '<row r="' . $number . '">';
            foreach ($row as $colIndex => $value) {
                $ref = $this->columnName($colIndex) . $number;
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' .
                    htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
                    '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /** @return list<list<string>> */
    private function instructions(): array
    {
        return [
            ['CAMPO', 'ORIENTAÇÃO'],
            ['matricula', 'Obrigatória. Até 30 caracteres. Deve ser única no Sistema CIF.'],
            ['nome_completo', 'Obrigatório. Nome completo do Agente de Trânsito.'],
            ['cpf', 'Obrigatório. Pode ser informado com ou sem pontuação.'],
            ['naturalidade', 'Obrigatória.'],
            ['data_nascimento', 'Obrigatória. Preferencialmente no formato AAAA-MM-DD.'],
            ['tipo_sanguineo', 'A+, A-, B+, B-, AB+, AB-, O+ ou O-.'],
            ['data_admissao', 'Obrigatória. Preferencialmente no formato AAAA-MM-DD.'],
            ['situacao_funcional', 'ATIVO, AFASTADO, EXONERADO, VACANCIA ou FALECIDO.'],
            ['fonte_referencia', 'Opcional. Referência do cadastro de pessoal, processo ou documento de origem.'],
            ['', 'A primeira linha da aba AGENTES deve permanecer como cabeçalho. Uma linha inválida não é importada; ela será indicada na pré-visualização.'],
        ];
    }

    private function columnName(int $index): string
    {
        $index += 1;
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}
