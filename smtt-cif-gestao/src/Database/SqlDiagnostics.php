<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Database;

use mysqli_sql_exception;

final class SqlDiagnostics
{
    /**
     * Diagnóstico seguro para exibição administrativa. Não inclui senha,
     * payload, DSN completo ou outro segredo.
     */
    public static function describeMysqli(mysqli_sql_exception $e): string
    {
        $native = (int) $e->getCode();
        $sqlState = method_exists($e, 'getSqlState') ? (string) $e->getSqlState() : 'n/d';

        $meaning = match ($native) {
            1044 => 'o usuário SQL autenticou, mas não possui permissão para acessar/criar o banco solicitado',
            1045 => 'o servidor recusou o usuário/senha SQL',
            1049 => 'o banco solicitado não existe',
            1142 => 'o usuário SQL não possui privilégio para executar a operação solicitada',
            1227 => 'o usuário SQL não possui privilégio administrativo suficiente para esta operação',
            2002 => 'não foi possível abrir a conexão MySQL/MariaDB pelo host/socket configurado',
            2003 => 'o servidor MySQL/MariaDB recusou ou não recebeu a conexão TCP',
            2005 => 'o host MySQL/MariaDB não pôde ser resolvido',
            default => 'o servidor MySQL/MariaDB recusou a operação',
        };

        return sprintf(
            'SQLSTATE %s, código MySQL/MariaDB %s: %s.',
            $sqlState !== '' ? $sqlState : 'n/d',
            $native > 0 ? (string) $native : 'n/d',
            $meaning
        );
    }

    public static function describeConnectFailure(int $native, string $nativeMessage, string $host, ?int $port): string
    {
        $meaning = match ($native) {
            1044 => 'o usuário SQL autenticou, mas não possui permissão para acessar o banco solicitado',
            1045 => 'o servidor recusou o usuário/senha SQL',
            2002 => 'não foi possível abrir a conexão MySQL/MariaDB',
            2003 => 'o servidor MySQL/MariaDB recusou ou não recebeu a conexão TCP',
            2005 => 'o host MySQL/MariaDB não pôde ser resolvido',
            default => 'a conexão MySQL/MariaDB falhou',
        };

        $effectivePort = $port;
        $portSource = 'explícita';
        if ($effectivePort === null) {
            $iniPort = ini_get('mysqli.default_port');
            $effectivePort = is_numeric($iniPort) ? (int) $iniPort : 0;
            $portSource = 'mysqli.default_port';
        }

        $endpoint = $host . ':' . ($effectivePort > 0 ? (string) $effectivePort : 'default');
        $nativeMessage = trim($nativeMessage);
        $nativeSuffix = $nativeMessage !== '' ? ' Mensagem nativa: ' . $nativeMessage . '.' : '';

        return sprintf(
            'Falha de conexão com %s usando %s (%s). Código MySQL/MariaDB %s: %s.%s',
            $endpoint,
            $portSource,
            $port === null ? 'porta não forçada pelo plugin' : 'porta configurada',
            $native > 0 ? (string) $native : 'n/d',
            $meaning,
            $nativeSuffix
        );
    }
}
