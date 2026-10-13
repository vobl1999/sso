<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Mailer;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\WebAuthn;

/**
 * 后台 · 系统设置与安全策略
 * ============================================================================
 * 需求「所有功能均可在后台进行操作与管理」的落点之一。
 *
 * 这一页管三件事：
 *   ① 站点信息（名称、地址、备案号）—— 影响邮件模板与回调地址拼接
 *   ② 登录策略（注册开关、密码强度、会话时长、是否强制管理员 2FA）
 *   ③ 登录方式开关（邮箱 / 微软 / GitHub …）—— 出故障时改一个值就能摘掉
 *
 * ★ 这里有个刻意的设计：策略存在数据库而不是配置文件里。
 *   目的是让"改一个安全策略"变成运营动作，而不是"找运维改文件再重启"。
 * ============================================================================
 */
final class SettingsController extends BaseController
{
    /** 支持的第三方登录方式（腾讯云已按要求移除） */
    public const PROVIDERS = [
        'email'     => ['name' => '邮箱与密码', 'desc' => '系统自带，始终建议开启'],
        'microsoft' => ['name' => '微软账号', 'desc' => '返回已验证邮箱，可自动并入已有账号'],
        'github'    => ['name' => 'GitHub', 'desc' => '需额外申请 user:email 权限以获取邮箱'],
        'wechat'    => ['name' => '微信', 'desc' => '不返回邮箱，只能手动绑定，需企业主体'],
        'qq'        => ['name' => 'QQ', 'desc' => '不返回邮箱，只能手动绑定'],
    ];

    /** 数据库版本（驱动无关，且失败时不能把页面打掉） */
    private static function dbVersion(): string
    {
        $driver = DB::conn()->driver();
        try {
            if ($driver === 'sqlite') {
                return 'SQLite ' . DB::conn()->val('SELECT sqlite_version()', [], '未知');
            }
            return 'MySQL ' . DB::conn()->val('SELECT VERSION()', [], '未知');
        } catch (\Throwable $e) {
            // 版本查不到无关紧要，不能因此让整个设置页 500
            return $driver . '（版本读取失败）';
        }
    }

    // ================================================================ 综合设置页

    public static function index(Request $request): void
    {
        Auth::requireCapability('settings.view');

        $enabledProviders = UserService::enabledProviders();

        self::adminPage('admin/settings', [
            'title'      => '系统设置',
            'general'    => Settings::group('general'),
            'security'   => Settings::group('security'),
            'auth'       => Settings::group('auth'),
            'payment'    => Settings::group('payment'),
            'providers'  => self::PROVIDERS,
            'enabledProviders' => $enabledProviders,
            'canManage'  => Auth::can('settings.manage'),
            // 环境自检，方便排查
            'env' => [
                'php'        => PHP_VERSION,
                'mysql'      => self::dbVersion(),
                'openssl'    => extension_loaded('openssl') ? '已加载' : '未加载',
                'curl'       => extension_loaded('curl') ? '已加载' : '未加载',
                'sockets'    => extension_loaded('sockets') ? '已加载' : '未加载',
                'storage'    => is_writable((string) Config::get('paths.storage')) ? '可写' : '不可写',
                'debug'      => Config::isDebug() ? '开启（生产环境应关闭）' : '关闭',
                'install'    => Config::get('install.allow_bootstrap_admin', false)
                                    ? '安装入口仍开启（建完管理员后应关闭）' : '已关闭',
            ],
            'stats' => [
                'users'     => DB::conn()->count('SELECT COUNT(*) FROM users'),
                'sessions'  => DB::conn()->count('SELECT COUNT(*) FROM sessions WHERE revoked_at = 0 AND expires_at > ?', [time()]),
                'clients'   => DB::conn()->count('SELECT COUNT(*) FROM oauth_clients'),
                'passkeys'  => DB::conn()->count('SELECT COUNT(*) FROM webauthn_credentials'),
                'audit'     => DB::conn()->count('SELECT COUNT(*) FROM audit_logs'),
                'orders'    => DB::conn()->count('SELECT COUNT(*) FROM payment_orders'),
            ],
            'flash'      => self::takenFlash(),
        ]);
    }

    /** 站点信息 */
    public static function save(Request $request): void
    {
        Auth::requireCapability('settings.manage');

        $url = trim($request->str('site.url', '', 255));
        if ($url !== '') {
            $p = parse_url($url);
            if ($p === false || !isset($p['scheme'], $p['host'])) {
                Response::error('站点地址格式不正确，需要形如 https://sso.example.com', 422);
            }
            if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
                Response::error('站点地址必须以 http:// 或 https:// 开头', 422);
            }
            $url = rtrim($url, '/');
        }

        Settings::putMany([
            'site.name' => $request->str('site.name', 'Vobl ID', 60),
            'site.url'  => $url,
            'site.icp'  => $request->str('site.icp', '', 60),
        ], 'general');

        // 站点地址变了，邮件里的链接和支付回调都会跟着变 —— 提醒一下
        self::back('/admin/settings',
            '站点信息已保存。注意：站点地址会影响邮件里的链接与支付回调地址，'
            . '如已配置支付通道，建议去「支付与充值」确认一遍回调地址。');
    }

    /** 安全策略 + 登录方式 */
    public static function saveSecurity(Request $request): void
    {
        Auth::requireCapability('security.manage');

        $minPassword = max(6, min(64, $request->int('security.password_min', 10)));
        $sessionTtl  = max(300, min(30 * 86400, $request->int('security.session_ttl', 604800)));

        Settings::putMany([
            'security.password_min'       => (string) $minPassword,
            'security.session_ttl'        => (string) $sessionTtl,
            'security.require_2fa_admin'  => $request->bool('security.require_2fa_admin') ? '1' : '0',
            'security.webauthn_enabled'   => $request->bool('security.webauthn_enabled') ? '1' : '0',
            'security.allow_register'     => $request->bool('security.allow_register') ? '1' : '0',
            'security.cookie_remember'    => $request->bool('security.cookie_remember') ? '1' : '0',
        ], 'security');

        Audit::log('admin', 'security.policy', [
            'actor_id' => Auth::id(),
            'detail'   => sprintf(
                '更新安全策略：密码最少 %d 位、会话 %d 天、强制管理员两步验证 %s、安全密钥 %s、开放注册 %s',
                $minPassword,
                (int) round($sessionTtl / 86400),
                $request->bool('security.require_2fa_admin') ? '开' : '关',
                $request->bool('security.webauthn_enabled') ? '开' : '关',
                $request->bool('security.allow_register') ? '开' : '关'
            ),
        ]);

        self::back('/admin/settings', '安全策略已保存');
    }

    /** 登录方式开关 */
    public static function saveProviders(Request $request): void
    {
        Auth::requireCapability('settings.manage');

        $chosen = array_values(array_intersect($request->arr('providers'), array_keys(self::PROVIDERS)));

        // 邮箱是身份主体，不允许关掉 —— 关了就没人能注册和登录了
        if (!in_array('email', $chosen, true)) {
            $chosen[] = 'email';
        }

        Settings::putMany([
            'providers.enabled' => implode(',', $chosen),
        ], 'auth');

        // 启用某个第三方登录前，要先在对应 provider 页面登记好回调地址
        $hints = [
            'microsoft' => '/oauth/callback/microsoft',
            'github'    => '/oauth/callback/github',
            'wechat'    => '/oauth/callback/wechat',
            'qq'        => '/oauth/callback/qq',
        ];
        $needCallback = array_intersect(array_keys($hints), $chosen);
        $url = rtrim((string) Settings::get('site.url', ''), '/');

        $msg = '登录方式已更新：' . implode('、', array_map(
            static fn($c) => self::PROVIDERS[$c]['name'] ?? $c,
            $chosen
        ));

        if ($needCallback !== [] && $url !== '') {
            $list = [];
            foreach ($needCallback as $p) {
                $list[] = self::PROVIDERS[$p]['name'] . ' → ' . $url . $hints[$p];
            }
            $msg .= '。请确认已在各平台登记回调地址：' . implode('；', $list);
        }

        Audit::log('admin', 'auth.providers', [
            'actor_id' => Auth::id(),
            'detail'   => '启用登录方式：' . implode(',', $chosen),
        ]);

        self::back('/admin/settings', $msg);
    }
}
