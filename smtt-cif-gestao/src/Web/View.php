<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Web;

use RuntimeException;
use SMTT\CIF\Gestao\Security\CsrfToken;

final class View
{
    public function __construct(
        private readonly string $templatesPath,
        private readonly ?CsrfToken $csrf = null
    ) {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): never
    {
        $path = rtrim($this->templatesPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($template, '/\\') . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Template CIF não encontrado: ' . $template);
        }

        if (function_exists('status_header')) {
            status_header($status);
        } else {
            http_response_code($status);
        }

        if ($this->csrf !== null) {
            $this->csrf->ensureCookie();
        }

        $data['assetBaseUrl'] = plugins_url('assets/', SMTT_CIF_GESTAO_FILE);
        $data['cifBaseUrl'] = home_url('/cif');
        $data['pluginVersion'] = SMTT_CIF_GESTAO_VERSION;

        extract($data, EXTR_SKIP);
        require $path;
        exit;
    }
}
