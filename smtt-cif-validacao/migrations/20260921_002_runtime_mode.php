<?php

declare(strict_types=1);

return [
    'version' => '20260921_002_runtime_mode',
    'description' => 'Adiciona modo explícito local/homologação ou produção sem quebrar instalações 0.1.0.',
    'up' => [
        <<<'SQL'
INSERT INTO plugin_settings(setting_key,setting_value,description,updated_at)
SELECT
    'runtime.mode',
    CASE WHEN JSON_UNQUOTE(setting_value) = 'production' THEN '"production"' ELSE '"local"' END,
    'Modo explícito do portal CIF: local/homologação ou produção.',
    UTC_TIMESTAMP(6)
FROM plugin_settings
WHERE setting_key = 'sync.environment'
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)
SQL,
        <<<'SQL'
INSERT INTO plugin_settings(setting_key,setting_value,description,updated_at)
VALUES('runtime.mode', '"local"', 'Modo explícito do portal CIF: local/homologação ou produção.', UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)
SQL,
    ],
];
