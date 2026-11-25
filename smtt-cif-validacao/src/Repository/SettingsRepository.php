<?php

declare(strict_types=1);

namespace SMTT\CIF\Validacao\Repository;

use JsonException;
use mysqli;
use RuntimeException;

final class SettingsRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM plugin_settings WHERE setting_key=? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return $default;
        }
        try {
            return json_decode((string)$row['setting_value'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Configuração pública CIF corrompida: ' . $key, 0, $e);
        }
    }

    public function set(string $key, mixed $value, string $description = ''): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Não foi possível serializar a configuração.', 0, $e);
        }
        $stmt = $this->db->prepare('INSERT INTO plugin_settings(setting_key,setting_value,description,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description),updated_at=VALUES(updated_at)');
        $stmt->bind_param('sss', $key, $json, $description);
        $stmt->execute();
        $stmt->close();
    }
}
