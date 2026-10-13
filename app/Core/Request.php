<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 请求封装。所有用户输入必须从这里取，且取值时即做类型约束。
 */
final class Request
{
    private array $query;
    private array $post;
    private array $server;
    private array $cookies;
    private array $files;
    private ?array $jsonCache = null;
    private string $rawBody;

    public function __construct(
        array $query = null,
        array $post = null,
        array $server = null,
        array $cookies = null,
        array $files = null,
        string $rawBody = null
    ) {
        $this->query   = $query   ?? $_GET;
        $this->post    = $post    ?? $_POST;
        $this->server  = $server  ?? $_SERVER;
        $this->cookies = $cookies ?? $_COOKIE;
        $this->files   = $files   ?? $_FILES;
        $this->rawBody = $rawBody ?? (string) file_get_contents('php://input');
    }

    public static function capture(): self
    {
        return new self();
    }

    // ---------------------------------------------------------------- 基础

    public function method(): string
    {
        $m = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        // 表单里用 _method 覆盖，方便只用 GET/POST 也能做 RESTful
        if ($m === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $m;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** 只要路径部分，不含查询串 */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $path = '/' . trim(rawurldecode($uri), '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** 是否 AJAX / 期待 JSON 响应 */
    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        $xhr    = strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xhr === 'xmlhttprequest') {
            return true;
        }
        return stripos($accept, 'application/json') !== false;
    }

    // ---------------------------------------------------------------- 取值

    /**
     * 按优先级取值：POST 字段 → JSON 请求体 → 查询串。
     *
     * ★ JSON 请求体必须纳入取值链。
     *   PHP 只会自动解析 $_POST（且仅限 form-urlencoded / multipart），
     *   application/json 的请求体不会进 $_POST —— 而本站前端的 AJAX 提交
     *   用的正是 JSON（见 assets/app.js 的 api.post）。
     *   漏掉这一步的症状非常隐蔽：CSRF 校验通过、请求返回 200，
     *   但所有字段都取到默认值，表现为「请填写邮箱和密码」这类莫名其妙的校验失败。
     */
    private function pick(string $key, $default)
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    /** 取字符串，自动 trim 并限制最大长度（防止超长输入打爆数据库/日志） */
    public function str(string $key, string $default = '', int $maxLen = 500): string
    {
        $v = $this->pick($key, $default);
        if (is_array($v)) {
            return $default;
        }
        $s = trim((string) $v);
        // 去掉控制字符，避免日志注入与响应头注入
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
            $s = mb_substr($s, 0, $maxLen);
        }
        return $s;
    }

    /** 原始字符串，不 trim（用于密码） */
    public function raw(string $key, string $default = '', int $maxLen = 200): string
    {
        $v = $this->pick($key, $default);
        if (is_array($v)) {
            return $default;
        }
        $s = (string) $v;
        if ($maxLen > 0 && strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
        }
        return $s;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->pick($key, null);
        if ($v === null || $v === '' || is_array($v)) {
            return $default;
        }
        if (!is_numeric($v)) {
            return $default;
        }
        return (int) $v;
    }

    /** 金额：元 → 分。绝不用浮点累加，这里一次性转换 */
    public function amountCents(string $key): int
    {
        $v = $this->pick($key, null);
        if ($v === null || $v === '' || is_array($v)) {
            return 0;
        }
        $s = trim((string) $v);
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) {
            return 0;
        }
        $parts = explode('.', $s, 2);
        $yuan  = (int) $parts[0];
        $cent  = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;
        return $yuan * 100 + $cent;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->pick($key, null);
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }

    public function arr(string $key, array $default = []): array
    {
        $v = $this->pick($key, null);
        if (!is_array($v)) {
            return $default;
        }
        return $v;
    }

    /** 邮箱：小写化 + trim + 格式校验。全站唯一入口，必须用它。 */
    public function email(string $key, string $default = ''): string
    {
        $v = strtolower(trim($this->str($key, $default, 190)));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /** JSON 请求体（令牌端点、WebAuthn 用） */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $data = [];
        $ct = (string) ($this->server['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') !== false && $this->rawBody !== '') {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif ($this->rawBody !== '') {
            // 兼容 application/x-www-form-urlencoded —— PHP 只对 POST 自动解析
            $parsed = [];
            parse_str($this->rawBody, $parsed);
            $data = $parsed;
        }
        $this->jsonCache = $data;
        return $data;
    }

    public function jsonStr(string $key, string $default = '', int $maxLen = 500): string
    {
        $v = $this->json()[$key] ?? $default;
        if (is_array($v)) {
            return $default;
        }
        $s = trim((string) $v);
        return $maxLen > 0 && mb_strlen($s) > $maxLen ? mb_substr($s, 0, $maxLen) : $s;
    }

    public function cookie(string $key, string $default = ''): string
    {
        $v = $this->cookies[$key] ?? $default;
        return is_array($v) ? $default : (string) $v;
    }

    // ---------------------------------------------------------------- 网络信息

    /**
     * 真实客户端 IP。
     * ★ 只有在确认站点位于可信反向代理之后（Cloudflare），才能信任这些头，
     *   否则攻击者可以伪造 X-Forwarded-For 绕过限流。用 trust_proxy 开关控制。
     */
    public function ip(): string
    {
        $trust = (bool) Config::get('app.trust_proxy', false);
        if ($trust) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                $val = (string) ($this->server[$h] ?? '');
                if ($val !== '') {
                    $first = trim(explode(',', $val)[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) {
                        return $first;
                    }
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if (Config::get('app.trust_proxy', false)) {
            $proto = strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto === 'https') {
                return true;
            }
        }
        return (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? '');
    }

    /** 当前站点根地址（用于拼接回调地址、邮件里的链接） */
    public function origin(): string
    {
        $configured = (string) Config::get('app.url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        return ($this->isSecure() ? 'https://' : 'http://') . $this->host();
    }

    /** 把 UA 翻译成人话，用于会话列表展示 */
    public function deviceLabel(): string
    {
        $ua = $this->userAgent();
        $os = '未知系统';
        foreach ([
            'Windows' => 'Windows', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android',
            'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'Linux' => 'Linux',
            'HarmonyOS' => 'HarmonyOS',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $os = $label;
                break;
            }
        }
        $br = '未知浏览器';
        foreach ([
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'MiuiBrowser' => '小米浏览器',
            'HuaweiBrowser' => '华为浏览器', 'MicroMessenger' => '微信内置',
            'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                $br = $label;
                break;
            }
        }
        return $br . ' · ' . $os;
    }
}
