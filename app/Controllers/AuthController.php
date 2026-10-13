<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\RateLimit;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Mailer;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\WebAuthn;

/**
 * 登录 / 注册 / 找回密码 / 二次验证
 * ============================================================================
 * 这个文件里集中了所有「未登录状态」能触达的入口，是最需要小心的部分。
 *
 * 三条贯穿始终的原则：
 *   1. 所有失败提示都模糊化 —— 不告诉攻击者「这个邮箱存在吗」「密码错在哪一位」
 *   2. 所有入口都限流 —— 未登录接口是被爆破的第一目标
 *   3. 所有状态变更都写审计 —— 出了事要能还原现场
 * ============================================================================
 */
final class AuthController extends BaseController
{
    /** 登录后要跳回哪（优先级：显式参数 > 会话里记的 > 默认） */
    private static function nextAfterLogin(Request $request): string
    {
        $next = $request->str('next', '', 300);
        if ($next !== '') {
            return self::safeNext($next);
        }
        return Auth::isAdmin() ? '/admin' : '/developer';
    }

    // ================================================================ 登录页

    public static function loginPage(Request $request): void
    {
        if (Auth::check()) {
            Response::redirect(self::safeNext($request->str('next', '', 300)));
        }

        $passkeyCount = DB::conn()->count(
            "SELECT COUNT(*) FROM webauthn_credentials wc JOIN users u ON u.id = wc.user_id WHERE u.status = 'active'"
        );
        $enabled = UserService::enabledProviders();

        self::page('auth/login', [
            'next'          => self::safeNext($request->str('next', '', 300), ''),
            'providers'     => $enabled,
            'passkeyEnabled'=> Settings::bool('security.webauthn_enabled', true) && $passkeyCount > 0,
            'allowRegister' => Settings::bool('security.allow_register', true),
            'flash'         => self::takenFlash(),
            'bodyClass'     => 'page-auth',
        ]);
    }

    // ================================================================ 密码登录

    public static function login(Request $request): void
    {
        $email    = $request->email('email');
        $password = $request->raw('password', '', 200);
        $ip       = $request->ip();

        if ($email === '' || $password === '') {
            self::invalid($request, '请填写邮箱和密码');
        }

        // 限流：同邮箱 8 次 / 15 分钟，同 IP 40 次 / 15 分钟
        $guard = RateLimit::guardLogin($email, $ip);
        if (!$guard['allowed']) {
            Audit::log('auth', 'login.ratelimit', [
                'actor' => $email, 'result' => 'denied',
                'detail' => '登录请求过于频繁，已临时拦截',
            ]);
            self::invalid($request, '尝试次数过多，请 ' . ceil($guard['retry_after'] / 60) . ' 分钟后再试');
        }

        $result = UserService::authenticate($email, $password);

        if (!$result['ok']) {
            RateLimit::recordLoginFailure($email, $ip);
            Audit::log('auth', 'login.fail', [
                'actor'  => $email,
                'result' => 'fail',
                'detail' => $result['error'],
            ]);
            self::invalid($request, $result['error']);
        }

        $user = $result['user'];

        // 算法升级：登录成功后顺手重算哈希
        if ($result['needs_rehash']) {
            DB::conn()->update('users', [
                'password_hash' => Crypto::hashPassword($password),
                'algo'          => 'bcrypt',
            ], 'id = ?', [(int) $user['id']]);
        }

        // ★ 成功即清零失败计数
        RateLimit::clearLoginFailure($email, $ip);

        // 管理员若被要求强制两步验证
        if (self::shouldForce2fa((int) $user['id'], $request)) {
            Response::redirect('/confirm?action=' . urlencode('登录管理员账号')
                . '&next=' . urlencode(self::nextAfterLogin($request)));
        }

        Auth::login((int) $user['id'], $request->bool('remember'), 'password');
        UserService::markLoginSuccess((int) $user['id'], $ip);

        Audit::log('auth', 'login.ok', [
            'actor_id' => (int) $user['id'],
            'actor'    => $email,
            'detail'   => '密码登录成功 · ' . $request->deviceLabel() . ' · ' . $ip,
        ]);

        self::afterLoginRedirect($request);
    }

    /** 登录成功后的统一跳转（处理 AJAX 与普通表单两种情况） */
    private static function afterLoginRedirect(Request $request): void
    {
        $next = self::nextAfterLogin($request);
        if ($request->wantsJson()) {
            Response::ok(['redirect' => $next], '登录成功');
        }
        Response::redirect($next);
    }

    private static function shouldForce2fa(int $userId, Request $request): bool
    {
        if (!Settings::bool('security.require_2fa_admin', false)) {
            return false;
        }
        $isAdmin = DB::conn()->count('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$userId]) > 0;
        if (!$isAdmin) {
            return false;
        }
        // 已经通过二次验证的就不重复要求
        return !Auth::confirmedRecently();
    }

    // ================================================================ 验证码登录

    public static function sendLoginCode(Request $request): void
    {
        $email = $request->email('email');
        if ($email === '') {
            Response::error('请输入有效的邮箱地址', 422);
        }

        $result = UserService::issueCode($email, 'login_code', $request->ip());

        if (!$result['ok']) {
            Response::error($result['error'], 429);
        }

        // ★ 无论邮箱是否存在，返回都一样 —— 防账号枚举
        Response::ok(['sent' => true], '如果该邮箱已注册，验证码已发送，请查收');
    }

    public static function loginWithCode(Request $request): void
    {
        $email = $request->email('email');
        $code  = $request->str('code', '', 10);
        $ip    = $request->ip();

        if ($email === '' || $code === '') {
            Response::error('请填写邮箱和验证码', 422);
        }

        $guard = RateLimit::guardLogin($email, $ip);
        if (!$guard['allowed']) {
            Response::error('尝试次数过多，请稍后再试', 429);
        }

        $check = UserService::verifyCode($email, 'login_code', $code);
        if (!$check['ok']) {
            RateLimit::recordLoginFailure($email, $ip);
            Response::error($check['error'], 422);
        }

        $user = UserService::findByEmail($email);
        if ($user === null) {
            Response::error('该邮箱尚未注册', 422);
        }
        if ((string) $user['status'] !== 'active') {
            Response::error('该账号当前不可登录，请联系管理员', 403);
        }

        RateLimit::clearLoginFailure($email, $ip);
        Auth::login((int) $user['id'], false, 'email_code');
        UserService::markLoginSuccess((int) $user['id'], $ip);

        Audit::log('auth', 'login.ok', [
            'actor_id' => (int) $user['id'], 'actor' => $email,
            'detail'   => '邮箱验证码登录成功 · ' . $request->deviceLabel() . ' · ' . $ip,
        ]);

        self::afterLoginRedirect($request);
    }

    // ================================================================ 安全密钥登录

    /**
     * 获取登录挑战。
     * ★ 这个端点**不能要求登录** —— 它就是登录入口。
     */
    public static function passkeyBegin(Request $request): void
    {
        if (!Settings::bool('security.webauthn_enabled', true)) {
            Response::error('本站未启用安全密钥登录', 403);
        }

        $email = $request->email('email');
        $userId = 0;
        if ($email !== '') {
            // 传了邮箱则只允许该用户的凭据（体验更好：直接弹指纹）
            $user = UserService::findByEmail($email);
            if ($user !== null && (string) $user['status'] === 'active') {
                $userId = (int) $user['id'];
                // 有邮箱但没注册密钥 → 明确告知，避免浏览器白弹一次
                if (WebAuthn::countFor($userId) === 0) {
                    Response::error('这个账号还没有添加安全密钥，请改用密码或验证码登录', 404);
                }
            }
        }

        $webauthn = new WebAuthn();
        $options  = $webauthn->beginLogin($userId);

        Audit::log('auth', 'webauthn.begin', [
            'actor' => $email, 'detail' => '发起安全密钥登录',
        ]);

        Response::ok($options);
    }

    public static function passkeyFinish(Request $request): void
    {
        if (!Settings::bool('security.webauthn_enabled', true)) {
            Response::error('本站未启用安全密钥登录', 403);
        }

        $body = $request->json();
        if (empty($body['id']) || empty($body['signature'])) {
            Response::error('提交的数据不完整', 422);
        }

        $webauthn = new WebAuthn();
        $result   = $webauthn->finishLogin($body);

        if (!$result['ok']) {
            Audit::log('auth', 'webauthn.fail', [
                'result' => 'fail', 'detail' => $result['error'],
            ]);
            Response::error($result['error'], 401);
        }

        $user = UserService::findById($result['user_id']);
        if ($user === null || (string) $user['status'] !== 'active') {
            Response::error('账号不可用', 403);
        }

        Auth::login((int) $user['id'], false, 'webauthn');
        UserService::markLoginSuccess((int) $user['id'], $request->ip());

        Audit::log('auth', 'login.ok', [
            'actor_id' => (int) $user['id'], 'actor' => (string) $user['email'],
            'detail'   => '安全密钥登录成功 · ' . $request->deviceLabel(),
        ]);

        Response::ok(['redirect' => self::nextAfterLogin($request)], '登录成功');
    }

    // ================================================================ 注册

    public static function registerPage(Request $request): void
    {
        if (!Settings::bool('security.allow_register', true)) {
            Response::html(
                '<!doctype html><meta charset="utf-8"><title>暂停注册</title>'
                . '<div style="font:15px/1.8 system-ui;max-width:460px;margin:90px auto;padding:30px;'
                . 'border:1px solid #e5e7eb;border-radius:14px;text-align:center">'
                . '<h2 style="margin:0 0 10px">暂未开放注册</h2>'
                . '<p style="color:#6b7280">管理员已关闭自助注册，请联系管理员开通账号。</p>'
                . '<a href="/login" style="color:#ee7318;font-weight:600">返回登录</a></div>',
                403
            );
        }
        if (Auth::check()) {
            Response::redirect('/developer');
        }

        self::page('auth/register', [
            'flash'    => self::takenFlash(),
            'minPassword' => Settings::int('security.password_min', 10),
        ]);
    }

    public static function registerSend(Request $request): void
    {
        if (!Settings::bool('security.allow_register', true)) {
            Response::error('管理员已关闭自助注册', 403);
        }

        $email = $request->email('email');
        if ($email === '') {
            Response::error('请输入有效的邮箱地址', 422);
        }

        // 已注册的分支在 issueCode 内部处理（改发提示邮件），对外响应一致
        $result = UserService::issueCode($email, 'verify_email', $request->ip());
        if (!$result['ok']) {
            Response::error($result['error'], 429);
        }

        Response::ok(['sent' => true], '验证码已发送，请查收邮件（若没收到请检查垃圾邮件）');
    }

    public static function registerVerify(Request $request): void
    {
        $email = $request->email('email');
        $code  = $request->str('code', '', 10);

        $check = UserService::verifyCode($email, 'verify_email', $code);
        if (!$check['ok']) {
            Response::error($check['error'], 422);
        }

        // 已注册的邮箱在这里被拦住 —— 但此时用户已经证明了邮箱所有权，
        // 所以可以安全地告诉他「去登录」而不是模糊提示
        if (UserService::findByEmail($email) !== null) {
            Response::error('该邮箱已经注册过了，请直接登录或使用「忘记密码」', 409);
        }

        Response::ok(['verified' => true, 'email' => $email], '邮箱验证通过，请设置密码');
    }

    public static function registerComplete(Request $request): void
    {
        if (!Settings::bool('security.allow_register', true)) {
            Response::error('管理员已关闭自助注册', 403);
        }

        $email    = $request->email('email');
        $code     = $request->str('code', '', 10);
        $password = $request->raw('password', '', 200);
        $name     = $request->str('display_name', '', 60);

        if (mb_strlen($password) < Settings::int('security.password_min', 10)) {
            Response::error('密码至少需要 ' . Settings::int('security.password_min', 10) . ' 位', 422);
        }

        // 再校验一次验证码：防止有人跳过 verify 步骤直接调本接口
        $check = UserService::verifyCode($email, 'verify_email', $code);
        if (!$check['ok']) {
            Response::error($check['error'], 422);
        }

        $create = UserService::create($email, $password, $name);
        if (!$create['ok']) {
            Response::error($create['error'], 422);
        }

        $userId = (int) $create['user']['id'];
        Auth::login($userId, false, 'register');
        UserService::markLoginSuccess($userId, $request->ip());

        Response::ok(['redirect' => '/account'], '注册成功，欢迎加入');
    }

    // ================================================================ 找回密码

    public static function forgotPage(Request $request): void
    {
        self::page('auth/forgot', [
            'flash'       => self::takenFlash(),
            'minPassword' => Settings::int('security.password_min', 10),
        ]);
    }

    public static function forgotSend(Request $request): void
    {
        $email = $request->email('email');
        if ($email === '') {
            Response::error('请输入有效的邮箱地址', 422);
        }

        $result = UserService::issueCode($email, 'reset_password', $request->ip());
        if (!$result['ok']) {
            Response::error($result['error'], 429);
        }

        // 同样不暴露邮箱是否存在
        Response::ok(['sent' => true], '如果该邮箱已注册，重置验证码已发送');
    }

    public static function forgotReset(Request $request): void
    {
        $email    = $request->email('email');
        $code     = $request->str('code', '', 10);
        $password = $request->raw('password', '', 200);

        $check = UserService::verifyCode($email, 'reset_password', $code);
        if (!$check['ok']) {
            Response::error($check['error'], 422);
        }

        $user = UserService::findByEmail($email);
        if ($user === null) {
            Response::error('账号不存在', 422);
        }

        $set = UserService::setPassword((int) $user['id'], $password);
        if (!$set['ok']) {
            Response::error($set['error'], 422);
        }

        Audit::log('security', 'password.reset', [
            'actor_id' => (int) $user['id'], 'actor' => $email,
            'detail' => '通过邮箱验证码重置密码，已登出全部会话',
        ]);

        Response::ok([
            'redirect' => '/login?flash=' . urlencode('密码已重置，请用新密码登录'),
        ], '密码已重置，所有设备均已登出');
    }

    public static function forgotByRecovery(Request $request): void
    {
        $email    = $request->email('email');
        $code     = $request->str('recovery_code', '', 40);
        $password = $request->raw('password', '', 200);

        $result = UserService::useRecoveryCode($email, $code, $password);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        Response::ok([
            'redirect' => '/login?flash=' . urlencode('已用恢复码重置密码，请登录'),
        ], '密码已重置');
    }

    // ================================================================ 退出

    public static function logout(Request $request): void
    {
        Auth::logout();
        Response::redirect('/login?flash=' . urlencode('已安全退出'));
    }

    // ================================================================ 二次验证

    public static function confirmPage(Request $request): void
    {
        Auth::requireLogin();

        if (Auth::confirmedRecently()) {
            Response::redirect(self::safeNext($request->str('next', '', 300)));
        }

        $user = Auth::user();
        self::page('auth/confirm', [
            'action'      => $request->str('action', '继续', 60),
            'next'        => self::safeNext($request->str('next', '', 300)),
            'hasPasskey'  => WebAuthn::countFor((int) $user['id']) > 0,
            'passkeyEnabled' => Settings::bool('security.webauthn_enabled', true),
            'flash'       => self::takenFlash(),
        ]);
    }

    public static function confirm(Request $request): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $next = self::safeNext($request->str('next', '', 300));

        $mode = $request->str('mode', 'password', 20);

        // 方式一：重新输入密码
        if ($mode === 'password') {
            $password = $request->raw('password', '', 200);
            $result = UserService::authenticate((string) $user['email'], $password);
            if (!$result['ok']) {
                Audit::log('security', 'confirm.fail', [
                    'result' => 'fail', 'detail' => '二次验证：密码错误',
                ]);
                self::invalid($request, '密码不正确');
            }
        } elseif ($mode === 'passkey') {
            // 方式二：用已注册的安全密钥验证（前端已完成断言，这里只认标记）
            $body = $request->json();
            $webauthn = new WebAuthn();
            $r = $webauthn->finishLogin($body);
            if (!$r['ok'] || $r['user_id'] !== (int) $user['id']) {
                self::invalid($request, '安全密钥验证失败');
            }
        } else {
            self::invalid($request, '不支持的验证方式');
        }

        Auth::markConfirmed();
        Audit::log('security', 'confirm.ok', [
            'actor_id' => (int) $user['id'],
            'detail' => '通过二次验证，方式：' . $mode,
        ]);

        if ($request->wantsJson()) {
            Response::ok(['redirect' => $next], '验证通过');
        }
        Response::redirect($next);
    }
}
