<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 响应工具。统一注入安全响应头。
 */
final class Response
{
    private static array $pendingCookies = [];

    private static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
            // 本站是自建账号系统，CSP 收紧到只允许同源脚本，能挡掉绝大多数 XSS 利用
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "img-src 'self' data: https:",
                "style-src 'self' 'unsafe-inline'",
                "script-src 'self'",
                "font-src 'self' data:",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]),
        ];
    }

    private static function sendHeaders(array $extra = []): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (self::securityHeaders() as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($extra as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    // ---------------------------------------------------------------- HTML

    public static function html(string $body, int $status = 200, array $headers = []): void
    {
        self::sendHeaders(['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
        http_response_code($status);
        echo $body;
        self::flushCookies();
        exit;
    }

    // ---------------------------------------------------------------- JSON

    public static function json($data, int $status = 200, array $headers = []): void
    {
        self::sendHeaders([
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::flushCookies();
        exit;
    }

    /** 成功响应，统一结构 */
    public static function ok($data = null, string $message = ''): void
    {
        self::json([
            'ok'      => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /** 失败响应。对外只给可读的原因，绝不暴露堆栈或 SQL */
    public static function error(string $message, int $status = 400, ?string $code = null): void
    {
        self::json([
            'ok'    => false,
            'error' => $message,
            'code'  => $code,
        ], $status);
    }

    // ---------------------------------------------------------------- 跳转

    public static function redirect(string $location, int $status = 302, array $headers = []): void
    {
        self::sendHeaders(['Location' => $location, 'Cache-Control' => 'no-store', ...$headers]);
        http_response_code($status);
        self::flushCookies();
        exit;
    }

    // ---------------------------------------------------------------- 纯文本

    public static function text(string $body, int $status = 200, array $headers = []): void
    {
        self::sendHeaders(['Content-Type' => 'text/plain; charset=utf-8', ...$headers]);
        http_response_code($status);
        echo $body;
        self::flushCookies();
        exit;
    }

    // ---------------------------------------------------------------- Cookie

    public static function setCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        ?string $domain = null,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): void {
        $secure = self::isHttps();
        $opts = [
            'expires'  => $maxAge > 0 ? time() + $maxAge : 0,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ];
        $domain = $domain ?? (string) Config::get('app.cookie_domain', '');
        if ($domain !== '') {
            $opts['domain'] = $domain;
        }

        // PHP 7.3+ 支持数组形式，能正确输出 SameSite
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, $opts);
        } else {
            setcookie($name, $value, $opts['expires'], $path . '; samesite=' . $sameSite, $domain, $secure, $httpOnly);
        }
        // 同时写入当前请求的 $_COOKIE，让同一次请求内后续逻辑能读到
        $_COOKIE[$name] = $value;
    }

    public static function clearCookie(string $name, string $path = '/'): void
    {
        self::setCookie($name, '', -3600, $path);
        unset($_COOKIE[$name]);
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (Config::get('app.trust_proxy', false)) {
            if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
                return true;
            }
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    /** 兼容占位：PHP 的 setcookie 立即生效，这里保留是为了将来引入缓冲 */
    private static function flushCookies(): void
    {
        self::$pendingCookies = [];
    }
}
