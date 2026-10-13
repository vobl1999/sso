<?php
declare(strict_types=1);

namespace Vobl\Core;

use Vobl\Services\Settings;

/**
 * 身份与会话
 * ============================================================================
 * 登录状态存在 Cookie 里，但库里只保存令牌的 SHA-256 摘要：
 *
 *   浏览器 Cookie:  vobl_sid=<64位十六进制明文令牌>
 *   数据库 sessions: token_hash = sha256(明文令牌)
 *
 * 这样即使数据库泄露，攻击者也无法直接拿去登录（摘要不可逆）。
 *
 * 会话失效的三条路径：
 *   1. 用户主动退出 —— 标记 revoked_at
 *   2. 到期 —— expires_at 过期
 *   3. 全员踢下线 —— 删除该用户全部会话（改密码、重置密码时自动触发）
 *
 * 「记住我」默认关闭（security.cookie_remember=0）：
 *   关闭时下发的是会话 Cookie（无 Max-Age），关掉浏览器就失效，
 *   公用电脑上用完不留痕迹。
 * ============================================================================
 */
final class Auth
{
    /** 当前请求的会话行缓存 */
    private static ?array $session = null;
    private static ?array $user = null;
    private static ?array $capabilities = null;
    private static string $sessionId = '';
    private static int $lastConfirmAt = 0;

    // ================================================================ 会话读取

    public static function sessionId(): string
    {
        if (self::$sessionId === '' && self::$session !== null) {
            return (string) self::$session['id'];
        }
        return self::$sessionId;
    }

    /** 加载当前会话（每个请求只查一次库） */
    private static function load(): void
    {
        if (self::$session !== null) {
            return;
        }
        $name  = (string) Config::get('app.cookie_name', 'vobl_sid');
        $token = (string) ($_COOKIE[$name] ?? '');

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            self::$session = null;
            self::$sessionId = '';
            return;
        }

        $hash = Crypto::hashToken($token);
        $row = DB::conn()->one(
            'SELECT * FROM sessions WHERE token_hash = ? AND revoked_at = 0 AND expires_at > ? LIMIT 1',
            [$hash, time()]
        );

        if ($row === null) {
            // 令牌无效 / 过期 / 已吊销 —— 顺手清掉浏览器里的脏 Cookie
            Response::clearCookie($name);
            self::$session = null;
            self::$sessionId = '';
            return;
        }

        self::$session   = $row;
        self::$sessionId = (string) $row['id'];
    }

    public static function session(): ?array
    {
        self::load();
        return self::$session;
    }

    public static function check(): bool
    {
        return self::session() !== null;
    }

    public static function id(): int
    {
        $s = self::session();
        return $s === null ? 0 : (int) $s['user_id'];
    }

    /** 当前用户完整信息（不含密码哈希） */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$user === null) {
            // ★ 刻意不查询 password_hash：模板层没有任何理由看到密码哈希。
            //   模板需要判断「有没有设密码」时，用下面这个布尔字段 has_password。
            $row = DB::conn()->one(
                "SELECT id, email, email_verified, display_name, avatar, status,
                        totp_enabled, created_at, last_login_at, last_login_ip,
                        (CASE WHEN password_hash <> '' THEN 1 ELSE 0 END) AS has_password
                 FROM users WHERE id = ? LIMIT 1",
                [self::id()]
            );
            if ($row === null || $row['status'] !== 'active') {
                self::logout();
                return null;
            }
            $row['has_password'] = (int) $row['has_password'] === 1;
            self::$user = $row;
        }
        return self::$user;
    }

    // ================================================================ 会话创建

    /**
     * 建立会话（登录成功后调用）。
     * 先销毁旧会话 → 防会话固定攻击（Session Fixation）。
     */
    public static function login(int $userId, bool $remember = false, string $method = 'password'): string
    {
        // ★ 防会话固定：登录前把当前浏览器可能持有的旧会话全部作废
        $oldName = (string) Config::get('app.cookie_name', 'vobl_sid');
        $oldToken = (string) ($_COOKIE[$oldName] ?? '');
        if ($oldToken !== '') {
            DB::conn()->query('DELETE FROM sessions WHERE token_hash = ?', [Crypto::hashToken($oldToken)]);
        }

        $ttl = (int) Settings::get('security.session_ttl', 604800);
        $rememberEnabled = Settings::get('security.cookie_remember', '0') === '1';

        $token = Crypto::randHex(32);          // 32 字节 = 64 位十六进制
        $sid   = Crypto::randHex(16);
        $now   = time();
        // 记住我开启且用户勾选 → 用长 TTL；否则会话 Cookie
        $expires = $remember && $rememberEnabled ? $now + $ttl : $now + max($ttl, 86400);

        $req = Request::capture();
        DB::conn()->insert('sessions', [
            'id'         => $sid,
            'user_id'    => $userId,
            'token_hash' => Crypto::hashToken($token),
            'ip'         => $req->ip(),
            'ua'         => $req->userAgent(),
            'device'     => $req->deviceLabel(),
            'created_at' => $now,
            'last_seen'  => $now,
            'expires_at' => $expires,
            'revoked_at' => 0,
        ]);

        Response::setCookie(
            $oldName,
            $token,
            ($remember && $rememberEnabled) ? $ttl : 0,   // 0 = 会话 Cookie
            '/'
        );

        // 清除缓存，让本次请求后续逻辑能看到新会话
        self::$session = null;
        self::$user = null;
        self::$capabilities = null;
        self::$sessionId = $sid;
        self::$lastConfirmAt = $now;

        return $sid;
    }

    /** 触碰会话：更新最后活跃时间（每 5 分钟写一次，避免每请求都写库） */
    public static function touch(): void
    {
        $s = self::session();
        if ($s === null) {
            return;
        }
        if (time() - (int) $s['last_seen'] < 300) {
            return;
        }
        DB::conn()->query('UPDATE sessions SET last_seen = ? WHERE id = ?', [time(), $s['id']]);
    }

    // ================================================================ 会话销毁

    public static function logout(): void
    {
        $s = self::session();
        if ($s !== null) {
            DB::conn()->query('UPDATE sessions SET revoked_at = ? WHERE id = ?', [time(), $s['id']]);
            Audit::log('auth', 'logout', ['actor_id' => (int) $s['user_id']]);
        }
        $name = (string) Config::get('app.cookie_name', 'vobl_sid');
        Response::clearCookie($name);
        self::reset();
    }

    /** 踢掉某用户的全部会话。改密码、重置密码、管理员强制下线时必调 */
    public static function revokeAll(int $userId, string $keepSessionId = ''): int
    {
        if ($keepSessionId !== '') {
            return DB::conn()->query(
                'UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at = 0',
                [time(), $userId, $keepSessionId]
            )->rowCount();
        }
        return DB::conn()->query(
            'UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at = 0',
            [time(), $userId]
        )->rowCount();
    }

    public static function revokeSession(int $userId, string $sessionId): bool
    {
        return DB::conn()->query(
            'UPDATE sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at = 0',
            [time(), $sessionId, $userId]
        )->rowCount() > 0;
    }

    private static function reset(): void
    {
        self::$session = null;
        self::$user = null;
        self::$capabilities = null;
        self::$sessionId = '';
    }

    // ================================================================ 权限

    /**
     * 当前用户的能力位集合。
     * 权限模型是「能力位」而不是「角色名」判断 ——
     * 加一个「审计员」是配置一条数据，不是改代码。
     */
    public static function capabilities(): array
    {
        if (self::$capabilities !== null) {
            return self::$capabilities;
        }
        $userId = self::id();
        if ($userId === 0) {
            return self::$capabilities = [];
        }

        $rows = DB::conn()->all(
            'SELECT r.capabilities FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?',
            [$userId]
        );

        $caps = [];
        foreach ($rows as $row) {
            $list = json_decode((string) $row['capabilities'], true);
            if (!is_array($list)) {
                continue;
            }
            if (in_array('*', $list, true)) {
                // 超级管理员：拥有全部权限
                return self::$capabilities = ['*'];
            }
            foreach ($list as $cap) {
                $caps[(string) $cap] = true;
            }
        }
        return self::$capabilities = array_keys($caps);
    }

    public static function can(string $capability): bool
    {
        $caps = self::capabilities();
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }

    public static function isAdmin(): bool
    {
        return self::capabilities() !== [];
    }

    public static function hasRole(string $code): bool
    {
        if (self::id() === 0) {
            return false;
        }
        return DB::conn()->count(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.code = ?',
            [self::id(), $code]
        ) > 0;
    }

    /** 超级管理员数量，用于「不能删除最后一个超管」的校验 */
    public static function superAdminCount(): int
    {
        return DB::conn()->count(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'super_admin'"
        );
    }

    // ================================================================ 强制校验

    public static function requireLogin(string $redirectTo = ''): void
    {
        if (self::check()) {
            return;
        }
        if (Request::capture()->wantsJson()) {
            Response::error('请先登录', 401, 'unauthenticated');
        }
        $next = $redirectTo !== '' ? $redirectTo : Request::capture()->path();
        Response::redirect('/login?next=' . urlencode($next));
    }

    public static function requireCapability(string $capability): void
    {
        self::requireLogin();
        if (self::can($capability)) {
            return;
        }
        Audit::log('security', 'permission.denied', [
            'result' => 'denied',
            'target' => $capability,
            'detail' => '缺少能力位：' . $capability,
        ]);
        if (Request::capture()->wantsJson()) {
            Response::error('没有权限执行该操作', 403, 'forbidden');
        }
        Response::html(
            '<!doctype html><meta charset="utf-8"><title>没有权限</title>'
            . '<div style="font:15px/1.7 system-ui;max-width:520px;margin:80px auto;padding:28px;'
            . 'border:1px solid #e5e7eb;border-radius:14px">'
            . '<h2 style="margin:0 0 10px">没有权限</h2>'
            . '<p style="color:#6b7280">当前账号缺少「' . e($capability) . '」权限，请联系管理员授权。</p>'
            . '<a href="/admin" style="color:#ee7318;font-weight:600">返回后台首页</a></div>',
            403
        );
    }

    // ================================================================ 二次验证

    /**
     * 敏感操作前的身份再确认。
     * 绑定安全密钥、改密码、改邮箱、管理员调整余额等操作必须调用。
     *
     * 默认 15 分钟内验证过就不再要求，避免频繁打扰。
     */
    public static function confirmedRecently(int $windowSeconds = 900): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::$lastConfirmAt > 0 && time() - self::$lastConfirmAt < $windowSeconds) {
            return true;
        }
        $s = self::session();
        $key = 'confirm_at_' . $s['id'];
        $ts = (int) ($_COOKIE[$key] ?? 0);
        if ($ts > 0 && time() - $ts < $windowSeconds) {
            self::$lastConfirmAt = $ts;
            return true;
        }
        return false;
    }

    /** 标记已通过二次验证 */
    public static function markConfirmed(): void
    {
        $s = self::session();
        if ($s === null) {
            return;
        }
        self::$lastConfirmAt = time();
        Response::setCookie('confirm_at_' . $s['id'], (string) time(), 1800, '/');
    }

    /**
     * 要求二次验证，未通过则跳转到验证页。
     * $capability 描述要做什么，展示给用户。
     */
    public static function requireConfirm(string $action, string $returnTo): void
    {
        self::requireLogin();
        if (self::confirmedRecently()) {
            return;
        }
        $req = Request::capture();
        if ($req->wantsJson()) {
            Response::error('该操作需要重新验证身份', 403, 'confirm_required');
        }
        Response::redirect('/confirm?action=' . urlencode($action) . '&next=' . urlencode($returnTo));
    }
}
