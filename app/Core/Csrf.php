<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * CSRF 防护
 * ============================================================================
 * 三层防护，缺一不可：
 *   1. 会话 Cookie 设 SameSite=Lax —— 跨站 POST 不会带上 Cookie（第一道）
 *   2. 表单 token —— 令牌与当前会话绑定，攻击者拿不到（第二道）
 *   3. 关键操作二次验证 —— 改密码、绑密钥、提现等再验一次身份（第三道，见 Auth::confirmIdentity）
 *
 * token 的生成方式刻意做成「派生」而不是「存储」：
 *   已登录 → HMAC(会话ID, app_secret)，无需额外存储，也随会话销毁自动失效
 *   未登录 → 双重提交 Cookie（表单隐藏域的值必须与 Cookie 一致）
 * ============================================================================
 */
final class Csrf
{
    private const COOKIE = 'vobl_csrf';

    public static function token(): string
    {
        $secret = (string) Config::require('keys.app_secret');

        $sessionId = Auth::sessionId();
        if ($sessionId !== '') {
            return Crypto::hmac('csrf:' . $sessionId, $secret);
        }

        // 未登录状态：用双重提交 Cookie
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || strlen($cookie) < 32) {
            $cookie = Crypto::randHex(32);
            Response::setCookie(self::COOKIE, $cookie, 7200, '/', null, false, 'Lax');
        }
        return $cookie;
    }

    /** 输出表单隐藏域 */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    /**
     * 校验。所有改变状态的 POST/PUT/DELETE 都必须先过这里。
     * 校验失败直接终止请求（不要「只记录不拦截」）。
     */
    public static function verify(Request $request): void
    {
        $expected = self::token();
        $submitted = $request->str('_token', '', 200);
        if ($submitted === '') {
            $submitted = (string) ($request->json()['_token'] ?? '');
        }
        // 也支持放在请求头里（AJAX 用）
        if ($submitted === '') {
            $submitted = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        if ($submitted === '' || !Crypto::equals($expected, $submitted)) {
            Audit::log('security', 'csrf.reject', [
                'result' => 'denied',
                'detail' => 'CSRF 令牌校验失败：' . $request->method() . ' ' . $request->path(),
            ]);
            if ($request->wantsJson()) {
                Response::error('请求校验失败，请刷新页面后重试', 419, 'csrf_failed');
            }
            Response::html(
                '<!doctype html><meta charset="utf-8"><title>请求校验失败</title>'
                . '<div style="font:15px/1.7 system-ui;max-width:520px;margin:80px auto;padding:28px;'
                . 'border:1px solid #e5e7eb;border-radius:14px">'
                . '<h2 style="margin:0 0 10px">请求校验失败</h2>'
                . '<p style="color:#6b7280;margin:0 0 18px">页面停留过久或来源异常，出于安全考虑已拦截本次提交。</p>'
                . '<a href="javascript:history.back()" style="color:#ee7318;font-weight:600">返回上一步</a>'
                . '</div>',
                419
            );
        }
    }

    /** 只对写操作自动校验的便捷判断 */
    public static function verifyIfWrite(Request $request): void
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            self::verify($request);
        }
    }
}
