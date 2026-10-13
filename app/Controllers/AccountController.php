<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\Wallet;
use Vobl\Services\WebAuthn;

/**
 * 账号中心 · 安全设置
 * ============================================================================
 * 设计目标：让用户一眼看清「我的账号现在有多安全」，并且能自助处理
 * 改密码、换邮箱、加删安全密钥、踢掉不认识的设备。
 *
 * 所有敏感操作（改密码 / 换邮箱 / 绑删密钥 / 生成恢复码）都必须先过
 * Auth::requireConfirm()，也就是重新验证一次身份。
 * 理由：会话被劫持时，攻击者能做的破坏程度取决于这一步。
 * ============================================================================
 */
final class AccountController extends BaseController
{
    // ================================================================ 主页面

    public static function security(Request $request): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        $sessions = DB::conn()->all(
            'SELECT id, ip, device, ua, created_at, last_seen, expires_at
             FROM sessions
             WHERE user_id = ? AND revoked_at = 0 AND expires_at > ?
             ORDER BY last_seen DESC',
            [$userId, time()]
        );

        $currentSid = Auth::sessionId();
        foreach ($sessions as &$s) {
            $s['is_current'] = ((string) $s['id'] === $currentSid);
        }
        unset($s);

        $grants = DB::conn()->all(
            'SELECT g.client_id, g.scopes, g.granted_at, c.name, c.icon, c.accent
             FROM oauth_grants g
             LEFT JOIN oauth_clients c ON c.client_id = g.client_id
             WHERE g.user_id = ? AND g.revoked_at = 0
             ORDER BY g.granted_at DESC',
            [$userId]
        );

        $recovery = UserService::recoveryCodeStats($userId);

        self::page('account/security', [
            'user'         => $user,
            'sessions'     => $sessions,
            'identities'   => UserService::identitiesOf($userId),
            'passkeys'     => WebAuthn::listFor($userId),
            'grants'       => $grants,
            'recovery'     => $recovery,
            'wallet'       => Wallet::of($userId),
            'providers'    => UserService::enabledProviders(),
            'webauthnEnabled' => Settings::bool('security.webauthn_enabled', true),
            'minPassword'  => Settings::int('security.password_min', 10),
            'flash'        => self::takenFlash(),
        ]);
    }

    // ================================================================ 资料

    public static function saveProfile(Request $request): void
    {
        Auth::requireLogin();
        $userId = Auth::id();
        $name = $request->str('display_name', '', 60);

        DB::conn()->update('users', [
            'display_name' => $name,
            'updated_at'   => time(),
        ], 'id = ?', [$userId]);

        Audit::log('admin', 'profile.update', [
            'actor_id' => $userId, 'detail' => '更新显示名称为：' . $name,
        ]);

        self::back('/account', '资料已保存');
    }

    // ================================================================ 密码

    public static function changePassword(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('修改登录密码', '/account');

        $current = $request->raw('current_password', '', 200);
        $new     = $request->raw('new_password', '', 200);
        $confirm = $request->raw('confirm_password', '', 200);
        $user    = Auth::user();

        if ($new !== $confirm) {
            self::invalid($request, '两次输入的新密码不一致');
        }

        // 先验证当前密码 —— 防止会话被劫持后直接改密码锁死原主人
        $check = UserService::authenticate((string) $user['email'], $current);
        if (!$check['ok']) {
            Audit::log('security', 'password.change_fail', [
                'actor_id' => Auth::id(), 'result' => 'fail',
                'detail' => '当前密码校验失败',
            ]);
            self::invalid($request, '当前密码不正确');
        }

        if ($current === $new) {
            self::invalid($request, '新密码不能与当前密码相同');
        }

        $result = UserService::setPassword(Auth::id(), $new);
        if (!$result['ok']) {
            self::invalid($request, $result['error']);
        }

        self::back('/account', '密码已修改，其他 ' . $result['revoked'] . ' 个设备已全部登出');
    }

    // ================================================================ 邮箱

    public static function emailSend(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('更换登录邮箱', '/account');

        $newEmail = $request->email('new_email');
        if ($newEmail === '') {
            Response::error('请输入有效的邮箱地址', 422);
        }

        $result = UserService::changeEmail(Auth::id(), $newEmail);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        Response::ok(['sent' => true, 'email' => $newEmail], '验证码已发送到新邮箱，请查收');
    }

    public static function emailConfirm(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('确认更换登录邮箱', '/account');

        $newEmail = $request->email('new_email');
        $code     = $request->str('code', '', 10);

        $result = UserService::confirmEmailChange(Auth::id(), $newEmail, $code);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        Response::ok(['redirect' => '/account?flash=' . urlencode('登录邮箱已更换')], '邮箱已更换');
    }

    // ================================================================ 会话管理

    public static function sessions(Request $request): void
    {
        Auth::requireLogin();
        $rows = DB::conn()->all(
            'SELECT id, ip, device, created_at, last_seen, expires_at
             FROM sessions WHERE user_id = ? AND revoked_at = 0 AND expires_at > ?
             ORDER BY last_seen DESC',
            [Auth::id(), time()]
        );
        $current = Auth::sessionId();
        foreach ($rows as &$r) {
            $r['is_current'] = ((string) $r['id'] === $current);
        }
        Response::ok(['sessions' => $rows]);
    }

    public static function revokeSession(Request $request): void
    {
        Auth::requireLogin();
        $sid = $request->str('session_id', '', 40);
        if ($sid === '') {
            Response::error('缺少会话标识', 422);
        }
        if ($sid === Auth::sessionId()) {
            Response::error('不能吊销当前正在使用的会话，请直接使用「退出登录」', 422);
        }

        $ok = Auth::revokeSession(Auth::id(), $sid);
        if ($ok) {
            Audit::log('security', 'session.revoke', [
                'actor_id' => Auth::id(), 'target' => $sid,
                'detail' => '吊销了一个登录会话',
            ]);
        }
        Response::ok(['revoked' => $ok], $ok ? '该设备已登出' : '会话不存在或已失效');
    }

    public static function revokeAll(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('登出其他所有设备', '/account');

        $count = Auth::revokeAll(Auth::id(), Auth::sessionId());

        Audit::log('security', 'session.revoke_all', [
            'actor_id' => Auth::id(),
            'detail' => '登出其他设备共 ' . $count . ' 个',
        ]);

        self::back('/account', '已登出其他 ' . $count . ' 个设备');
    }

    // ================================================================ 恢复码

    public static function recoveryCodes(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('查看恢复码', '/account');

        // 恢复码只在生成时展示一次，查看历史不可能
        Response::error('出于安全考虑，恢复码只在生成时显示一次。请点击「重新生成」获取新的一组。', 410);
    }

    public static function generateRecovery(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('重新生成恢复码', '/account');

        $codes = UserService::generateRecoveryCodes(Auth::id(), 10);
        Response::ok(['codes' => $codes], '恢复码已生成，请立即保存，关闭后无法再次查看');
    }

    // ================================================================ 第三方绑定

    public static function unbindIdentity(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('解绑登录方式', '/account');

        $provider = $request->str('provider', '', 32);
        if (in_array($provider, ['microsoft', 'github', 'wechat', 'qq', 'google'], true) === false) {
            Response::error('不支持的登录方式', 422);
        }

        $result = UserService::unbindIdentity(Auth::id(), $provider);
        if (!$result['ok']) {
            Response::error($result['error'], $result['status'] ?? 400);
        }

        Response::ok(['redirect' => '/account?flash=' . urlencode('已解绑')], '已解绑');
    }

    // ================================================================ 安全密钥

    public static function passkeyBegin(Request $request): void
    {
        Auth::requireLogin();
        if (!Settings::bool('security.webauthn_enabled', true)) {
            Response::error('本站未启用安全密钥', 403);
        }
        Auth::requireConfirm('添加安全密钥', '/account');

        $user = Auth::user();
        $webauthn = new WebAuthn();
        $options = $webauthn->beginRegistration(
            (int) $user['id'],
            (string) $user['email'],
            (string) $user['display_name']
        );

        Audit::log('security', 'webauthn.register_begin', [
            'actor_id' => Auth::id(), 'detail' => '开始注册安全密钥',
        ]);

        Response::ok($options);
    }

    public static function passkeyFinish(Request $request): void
    {
        Auth::requireLogin();
        if (!Settings::bool('security.webauthn_enabled', true)) {
            Response::error('本站未启用安全密钥', 403);
        }

        $body = $request->json();
        $webauthn = new WebAuthn();
        $result = $webauthn->finishRegistration(
            Auth::id(),
            $body,
            (string) ($body['deviceName'] ?? '')
        );

        if (!$result['ok']) {
            Audit::log('security', 'webauthn.register_fail', [
                'actor_id' => Auth::id(), 'result' => 'fail', 'detail' => $result['error'],
            ]);
            Response::error($result['error'], 422);
        }

        Audit::log('security', 'webauthn.register_ok', [
            'actor_id' => Auth::id(),
            'detail' => '注册安全密钥成功：' . ($result['credential']['name'] ?? ''),
        ]);

        Response::ok(['redirect' => '/account?flash=' . urlencode('安全密钥已添加')], '添加成功');
    }

    public static function passkeyDelete(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('移除安全密钥', '/account');

        $id = $request->str('id', '', 40);
        $result = WebAuthn::delete(Auth::id(), $id);

        if (!$result['ok']) {
            // 404 = 不存在，409 = 最后一把 —— 两种情况提示不同
            Response::error($result['error'], $result['status']);
        }

        Audit::log('security', 'webauthn.delete', [
            'actor_id' => Auth::id(), 'target' => $id, 'detail' => '移除了一把安全密钥',
        ]);

        Response::ok(['redirect' => '/account?flash=' . urlencode('已移除')], '已移除');
    }
}
