<?php
declare(strict_types=1);

/**
 * Vobl ID · 唯一入口
 * ============================================================================
 * Web 根目录必须指向本目录（public/），否则 app/ config/ storage/ 可能被直接下载。
 * 宝塔面板：站点根目录填 .../vobl-id-php/public
 * ============================================================================
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Vobl\Controllers\AccountController;
use Vobl\Controllers\Admin\AuditController;
use Vobl\Controllers\Admin\ClientController;
use Vobl\Controllers\Admin\DashboardController;
use Vobl\Controllers\Admin\MailController;
use Vobl\Controllers\Admin\PaymentAdminController;
use Vobl\Controllers\Admin\RoleController;
use Vobl\Controllers\Admin\SettingsController;
use Vobl\Controllers\Admin\UserController;
use Vobl\Controllers\InstallController;
use Vobl\Controllers\AuthController;
use Vobl\Controllers\DeveloperController;
use Vobl\Controllers\OAuthController;
use Vobl\Controllers\PaymentController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Csrf;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Core\Router;
use Vobl\Services\UserService;

$request = Request::capture();
$router  = new Router();

/* ============================================================================
 * 无需 CSRF 校验的路径
 * ----------------------------------------------------------------------------
 * 这些端点是「机器对机器」调用，没有浏览器会话，也不该被 CSRF 保护：
 *   · 支付异步通知  —— 由支付平台服务器发起，靠签名校验真实性
 *   · OAuth 令牌端点 —— 由接入方服务器发起，靠 client_secret / PKCE 校验
 *   · WebAuthn 断言 —— 靠挑战值一次性校验
 * 它们的共同点是：都有比 CSRF 令牌更强的验证手段。
 * ========================================================================== */
$csrfExempt = static function (string $path, string $method): bool {
    if (str_starts_with($path, '/pay/notify/')) {
        return true;
    }
    if ($path === '/oauth/token') {
        return true;
    }
    if (str_starts_with($path, '/login/passkey') || str_starts_with($path, '/account/passkeys/finish')) {
        return true;
    }
    return false;
};

if (!$csrfExempt($request->path(), $request->method())) {
    Csrf::verifyIfWrite($request);
}

/* ================================ 门户与账号 ================================ */

$router->get('/', static function () {
    Response::redirect(Auth::check() ? '/developer' : '/login');
});

// ------------------------------------------------------------------ 登录
$router->get('/login', [AuthController::class, 'loginPage']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/login/code/send', [AuthController::class, 'sendLoginCode']);
$router->post('/login/code', [AuthController::class, 'loginWithCode']);
$router->post('/login/passkey/begin', [AuthController::class, 'passkeyBegin']);
$router->post('/login/passkey/finish', [AuthController::class, 'passkeyFinish']);

// ------------------------------------------------------------------ 注册
$router->get('/register', [AuthController::class, 'registerPage']);
$router->post('/register/send', [AuthController::class, 'registerSend']);
$router->post('/register/verify', [AuthController::class, 'registerVerify']);
$router->post('/register/complete', [AuthController::class, 'registerComplete']);

// ------------------------------------------------------------------ 找回密码
$router->get('/forgot', [AuthController::class, 'forgotPage']);
$router->post('/forgot/send', [AuthController::class, 'forgotSend']);
$router->post('/forgot/reset', [AuthController::class, 'forgotReset']);
$router->post('/forgot/recovery', [AuthController::class, 'forgotByRecovery']);

// ------------------------------------------------------------------ 退出 / 二次验证
$router->get('/logout', [AuthController::class, 'logout']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/confirm', [AuthController::class, 'confirmPage']);
$router->post('/confirm', [AuthController::class, 'confirm']);

/* ================================ 账号中心 ================================ */

$router->get('/account', [AccountController::class, 'security']);
$router->post('/account/profile', [AccountController::class, 'saveProfile']);
$router->post('/account/password', [AccountController::class, 'changePassword']);
$router->post('/account/email/send', [AccountController::class, 'emailSend']);
$router->post('/account/email/confirm', [AccountController::class, 'emailConfirm']);
$router->get('/account/sessions', [AccountController::class, 'sessions']);
$router->post('/account/sessions/revoke', [AccountController::class, 'revokeSession']);
$router->post('/account/sessions/revoke-all', [AccountController::class, 'revokeAll']);
$router->get('/account/recovery', [AccountController::class, 'recoveryCodes']);
$router->post('/account/recovery/generate', [AccountController::class, 'generateRecovery']);
$router->post('/account/identities/unbind', [AccountController::class, 'unbindIdentity']);
$router->post('/account/passkeys/begin', [AccountController::class, 'passkeyBegin']);
$router->post('/account/passkeys/finish', [AccountController::class, 'passkeyFinish']);
$router->post('/account/passkeys/delete', [AccountController::class, 'passkeyDelete']);

/* ============================== 开放平台 / 钱包 ============================== */

$router->get('/developer', [DeveloperController::class, 'index']);
$router->post('/developer/clients', [DeveloperController::class, 'create']);
$router->post('/developer/clients/update', [DeveloperController::class, 'update']);
$router->post('/developer/clients/delete', [DeveloperController::class, 'delete']);
$router->post('/developer/clients/secret', [DeveloperController::class, 'resetSecret']);

$router->get('/wallet', [PaymentController::class, 'index']);
$router->post('/wallet/order', [PaymentController::class, 'createOrder']);
$router->get('/pay/return', [PaymentController::class, 'payReturn']);
$router->post('/pay/notify/{gateway}', [PaymentController::class, 'notify']);
$router->get('/pay/notify/{gateway}', [PaymentController::class, 'notify']);

/* ============================== 单点登录（SSO） ============================== */

$router->get('/authorize', [OAuthController::class, 'authorize']);
$router->post('/authorize/decision', [OAuthController::class, 'decision']);
$router->post('/oauth/token', [OAuthController::class, 'token']);
$router->get('/oauth/userinfo', [OAuthController::class, 'userinfo']);
$router->get('/oauth/logout', [OAuthController::class, 'logout']);
$router->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'discovery']);
$router->get('/.well-known/openid-configuration', [OAuthController::class, 'discovery']);

/* ================================ 后台管理 ================================ */

$router->get('/admin', [DashboardController::class, 'index']);

// 用户
$router->get('/admin/users', [UserController::class, 'index']);
$router->get('/admin/users/detail', [UserController::class, 'detail']);
$router->post('/admin/users/status', [UserController::class, 'status']);
$router->post('/admin/users/roles', [UserController::class, 'saveRoles']);
$router->post('/admin/users/wallet', [UserController::class, 'adjustWallet']);
$router->post('/admin/users/password', [UserController::class, 'resetPassword']);
$router->post('/admin/users/revoke', [UserController::class, 'revokeSessions']);

// 角色权限
$router->get('/admin/roles', [RoleController::class, 'index']);
$router->post('/admin/roles/save', [RoleController::class, 'save']);
$router->post('/admin/roles/delete', [RoleController::class, 'delete']);

// 邮件 / SMTP
$router->get('/admin/mail', [MailController::class, 'index']);
$router->post('/admin/mail/save', [MailController::class, 'save']);
$router->post('/admin/mail/test', [MailController::class, 'test']);
$router->post('/admin/mail/send-test', [MailController::class, 'sendTest']);

// 支付与充值
$router->get('/admin/payment', [PaymentAdminController::class, 'index']);
$router->post('/admin/payment/save', [PaymentAdminController::class, 'saveGateway']);
$router->post('/admin/payment/toggle', [PaymentAdminController::class, 'toggle']);
$router->post('/admin/payment/default', [PaymentAdminController::class, 'setDefault']);
$router->get('/admin/orders', [PaymentAdminController::class, 'orders']);
$router->post('/admin/orders/refund', [PaymentAdminController::class, 'refund']);
$router->get('/admin/wallets', [PaymentAdminController::class, 'wallets']);

// 应用（SSO 客户端）
$router->get('/admin/clients', [ClientController::class, 'index']);
$router->post('/admin/clients/save', [ClientController::class, 'save']);
$router->post('/admin/clients/delete', [ClientController::class, 'delete']);
$router->post('/admin/clients/secret', [ClientController::class, 'resetSecret']);

// 安全策略与常规设置
$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings/save', [SettingsController::class, 'save']);
$router->post('/admin/settings/security', [SettingsController::class, 'saveSecurity']);
$router->post('/admin/settings/providers', [SettingsController::class, 'saveProviders']);

// 审计日志
$router->get('/admin/audit', [AuditController::class, 'index']);
$router->get('/admin/audit/export', [AuditController::class, 'export']);

/* ================================ 安装引导 ================================ */

$router->get('/install', [InstallController::class, 'wizard']);
$router->post('/install', [InstallController::class, 'submit']);

/* ================================ 兜底与启动 ================================ */

$router->fallback(static function (Request $req): void {
    if ($req->wantsJson()) {
        Response::error('接口不存在', 404, 'not_found');
    }
    Response::html(
        '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>页面不存在</title><style>'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#faf8f6;'
        . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,Segoe UI,PingFang SC,sans-serif;color:#1f1710}'
        . '.c{text-align:center;padding:0 24px}h1{font-size:56px;margin:0;letter-spacing:-.04em;color:#ee7318}'
        . 'p{color:#94837a;margin:10px 0 24px}'
        . 'a{display:inline-block;padding:12px 24px;background:#ff8a24;color:#401a00;'
        . 'text-decoration:none;border-radius:11px;font-weight:600}'
        . '</style></head><body><div class="c"><h1>404</h1>'
        . '<p>这个地址没有对应的页面</p><a href="/">回到首页</a></div></body></html>',
        404
    );
});

// 记录一次访问（供会话活跃度使用），然后分发
try {
    Auth::touch();
} catch (\Throwable $e) {
    error_log('[vobl] touch 失败：' . $e->getMessage());
}

$router->dispatch($request);
