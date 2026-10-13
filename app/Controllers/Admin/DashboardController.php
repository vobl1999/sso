<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Services\Payment\GatewayManager;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\Wallet;
use Vobl\Services\WebAuthn;

/**
 * 后台首页 · 数据看板
 */
final class DashboardController extends BaseController
{
    public static function index(Request $request): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            Response::redirect('/developer');
        }

        $db = DB::conn();
        $todayStart = strtotime('today');
        $weekStart  = strtotime('-6 days midnight');

        // 用户概况
        $userStats = [
            'total'         => $db->count('SELECT COUNT(*) FROM users'),
            'active'        => $db->count('SELECT COUNT(*) FROM users WHERE status = ?', ['active']),
            'unverified'    => $db->count('SELECT COUNT(*) FROM users WHERE email_verified = 0'),
            'locked'        => $db->count('SELECT COUNT(*) FROM users WHERE status = ?', ['locked']),
            'new_today'     => $db->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$todayStart]),
            'new_week'      => $db->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$weekStart]),
            'with_passkey'  => $db->count('SELECT COUNT(DISTINCT user_id) FROM webauthn_credentials'),
            'last_24h_login'=> $db->count('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [time() - 86400]),
        ];

        // 安全状况
        $security = [
            'fail_last_24h' => $db->count(
                'SELECT COUNT(*) FROM audit_logs WHERE category = ? AND result <> ? AND ts >= ?',
                ['auth', 'ok', time() - 86400]
            ),
            'denied_last_24h' => $db->count(
                'SELECT COUNT(*) FROM audit_logs WHERE result = ? AND ts >= ?',
                ['denied', time() - 86400]
            ),
            'smtp' => (string) Settings::get('mail.host', '') !== '',
            'webauthn' => Settings::bool('security.webauthn_enabled', true),
            'require_2fa_admin' => Settings::bool('security.require_2fa_admin', false),
            'passkey_total' => $db->count('SELECT COUNT(*) FROM webauthn_credentials'),
        ];

        // 支付概况
        $payment = Wallet::stats();
        $defaultGateway = GatewayManager::default();

        // 趋势：最近 7 天每日注册数
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $start = strtotime("-{$i} days midnight");
            $end = $start + 86400;
            $trend[] = [
                'date'  => date('m-d', $start),
                'users' => $db->count('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?', [$start, $end]),
                'logins'=> $db->count(
                    'SELECT COUNT(*) FROM audit_logs WHERE category = ? AND action = ? AND ts >= ? AND ts < ?',
                    ['auth', 'login.ok', $start, $end]
                ),
            ];
        }

        // 最近动态
        $recent = Audit::paginate([], 1, 12)['rows'];

        self::adminPage('admin/dashboard', [
            'title'      => '概览',
            'userStats'  => $userStats,
            'security'   => $security,
            'payment'    => $payment,
            'trend'      => $trend,
            'recent'     => $recent,
            'defaultGateway' => $defaultGateway,
            'clientCount' => $db->count('SELECT COUNT(*) FROM oauth_clients WHERE status = ?', ['active']),
            'flash'      => self::takenFlash(),
        ]);
    }
}
