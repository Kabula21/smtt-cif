<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Security;

final class CsrfToken
{
    private const COOKIE = 'smtt_cif_csrf';
    private const LIFETIME = 43200; // 12 horas

    public function ensureCookie(): void
    {
        $this->secret();
    }

    public function token(string $action): string
    {
        $secret = $this->secret();
        $mac = hash_hmac('sha256', $action . '|' . $secret, $this->serverKey(), true);
        return $this->base64Url($mac);
    }

    public function verify(string $token, string $action): bool
    {
        if ($token === '') {
            return false;
        }
        $cookie = isset($_COOKIE[self::COOKIE]) ? trim((string)$_COOKIE[self::COOKIE]) : '';
        if ($cookie === '' || preg_match('/^[A-Za-z0-9_-]{40,60}$/', $cookie) !== 1) {
            return false;
        }
        $expected = $this->base64Url(hash_hmac('sha256', $action . '|' . $cookie, $this->serverKey(), true));
        return hash_equals($expected, $token);
    }

    public function field(string $action, string $name='smtt_cif_nonce'): string
    {
        return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($this->token($action)) . '">';
    }

    public function url(string $url, string $action): string
    {
        return add_query_arg('_cifcsrf', rawurlencode($this->token($action)), $url);
    }

    public function rotate(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', [
                'expires' => time() - 3600,
                'path' => $this->cookiePath(),
                'secure' => function_exists('is_ssl') ? is_ssl() : false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $this->secret();
    }

    private function secret(): string
    {
        $current = isset($_COOKIE[self::COOKIE]) ? trim((string)$_COOKIE[self::COOKIE]) : '';
        if ($current !== '' && preg_match('/^[A-Za-z0-9_-]{40,60}$/', $current) === 1) {
            return $current;
        }

        $secret = $this->base64Url(random_bytes(32));
        if (!headers_sent()) {
            setcookie(self::COOKIE, $secret, [
                'expires' => time() + self::LIFETIME,
                'path' => $this->cookiePath(),
                'secure' => function_exists('is_ssl') ? is_ssl() : false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $secret;
        return $secret;
    }

    private function serverKey(): string
    {
        if (function_exists('wp_salt')) {
            return wp_salt('nonce');
        }
        if (defined('AUTH_SALT') && (string)AUTH_SALT !== '') {
            return (string)AUTH_SALT;
        }
        return hash('sha256', __FILE__ . php_uname(), true);
    }

    private function cookiePath(): string
    {
        $path = function_exists('wp_parse_url') ? wp_parse_url(home_url('/cif'), PHP_URL_PATH) : '/cif';
        return is_string($path) && $path !== '' ? rtrim($path, '/') : '/cif';
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
