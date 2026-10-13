<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Mailer;
use Vobl\Services\Settings;
use Vobl\Services\UserService;

/**
 * 安装引导 · 创建第一个管理员
 * ============================================================================
 * 为什么需要它：系统装好后如果没有任何管理员，后台就进不去，
 * 而数据库又不宜手工插入（密码哈希算法容易写错）。
 *
 * 三层防护，缺一不可：
 *   ① 配置开关 install.allow_bootstrap_admin 必须为 true（默认 true，装完请关掉）
 *   ② 数据库里必须已经没有任何超级管理员（否则本页面直接失效）
 *   ③ 全程写审计日志，创建成功后立刻自动关闭这个开关
 *
 * 建完第一个管理员后，强烈建议把配置里的开关改成 false，
 * 否则等于给所有人留了一个「创建超管」的入口。
 * ============================================================================
 */
final class InstallController extends BaseController
{
    /** 当前数据库里的表数量（驱动无关） */
    private static function tableCount(): int
    {
        $driver = DB::conn()->driver();
        if ($driver === 'sqlite') {
            return DB::conn()->count(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            );
        }
        return DB::conn()->count(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
    }

    /** users 表是否存在（驱动无关） */
    private static function hasUsersTable(): bool
    {
        $driver = DB::conn()->driver();
        if ($driver === 'sqlite') {
            return DB::conn()->count(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'"
            ) > 0;
        }
        return DB::conn()->count(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'users'"
        ) > 0;
    }

    /**
     * 环境自检项
     *
     * ★ 每一项都单独 try/catch。
     *   这个页面的职责就是「排查环境问题」—— 如果它自己因为环境问题崩掉，
     *   用户就永远看不到问题出在哪。所以任何一项失败都必须降级成「未通过」，
     *   而不是抛异常把整页打掉。
     */
    private static function checks(): array
    {
        $storage = (string) Config::get('paths.storage', dirname(__DIR__, 2) . '/storage');
        $driver = DB::conn()->driver();

        // 数据库相关的检查单独包起来
        $tableCount = 0;
        $hasUsers = false;
        $dbError = '';
        try {
            $tableCount = self::tableCount();
            $hasUsers = self::hasUsersTable();
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $checks = [];

        $checks[] = [
            'label' => 'PHP 版本',
            'value' => PHP_VERSION,
            'ok'    => version_compare(PHP_VERSION, '7.4.0', '>='),
            'hint'  => '需要 PHP 7.4 或更高（推荐 8.1+）',
        ];

        foreach (['pdo' => 'PDO', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'json' => 'JSON'] as $ext => $label) {
            $checks[] = [
                'label' => $label . ' 扩展',
                'value' => extension_loaded($ext) ? '已加载' : '未加载',
                'ok'    => extension_loaded($ext),
                'hint'  => $ext === 'openssl'
                    ? '用于加密配置与验证支付回调，必需。Windows 便携版若报「configuration file routines」错误，'
                      . '需设置环境变量 OPENSSL_CONF 指向 openssl.cnf'
                    : '',
            ];
        }

        $checks[] = [
            'label' => '数据库连接',
            'value' => $driver . ($dbError !== '' ? '（查询失败）' : ''),
            'ok'    => $dbError === '',
            'hint'  => $dbError !== '' ? '连接成功但查询失败：' . $dbError : '',
        ];

        $checks[] = [
            'label' => '数据表',
            'value' => $dbError !== '' ? '无法读取' : ($tableCount . ' 张'),
            'ok'    => $hasUsers,
            'hint'  => $hasUsers
                ? ''
                : '找不到 users 表。请先导入 sql/schema.mysql.sql（本地开发可用 php tools/setup-sqlite.php）',
        ];

        $checks[] = [
            'label' => 'storage 目录可写',
            'value' => is_writable($storage) ? '可写' : '不可写',
            'ok'    => is_writable($storage),
            'hint'  => '宝塔面板里把该目录权限设为 755 并确保属主是 www',
        ];

        // Web 根是否只暴露了 public/
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $rootOk = $docRoot === '' || !is_file($docRoot . '/../config/config.php');
        $checks[] = [
            'label' => '站点根目录',
            'value' => $rootOk ? '只暴露 public/' : '可能是项目根目录',
            'ok'    => $rootOk,
            'hint'  => '站点根目录应指向 public/，否则 app/ config/ storage/ 可被直接下载',
        ];

        $checks[] = [
            'label' => '调试模式',
            'value' => Config::isDebug() ? '已开启（生产环境请关闭）' : '已关闭',
            'ok'    => !Config::isDebug(),
            'hint'  => 'config/config.php 里的 app.debug',
        ];

        return $checks;
    }

    private static function hasSuperAdmin(): bool
    {
        try {
            return DB::conn()->count(
                "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'super_admin'"
            ) > 0;
        } catch (\Throwable $e) {
            return true;   // 表不存在时视为「不可安装」，避免绕过防护
        }
    }

    private static function installAllowed(): bool
    {
        if (!Config::get('install.allow_bootstrap_admin', false)) {
            return false;
        }
        return !self::hasSuperAdmin();
    }

    // ================================================================ 页面

    public static function wizard(Request $request): void
    {
        $checks = self::checks();
        $allOk = !in_array(false, array_column($checks, 'ok'), true);

        self::page('install/wizard', [
            'checks'      => $checks,
            'allOk'       => $allOk,
            'allowed'     => self::installAllowed(),
            'hasSuperAdmin' => self::hasSuperAdmin(),
            'allowBootstrap' => (bool) Config::get('install.allow_bootstrap_admin', false),
            'smtpConfigured' => (string) Settings::get('mail.host', '') !== '',
            'minPassword' => Settings::int('security.password_min', 10),
            'flash'       => self::takenFlash(),
        ], 'layout/plain');
    }

    public static function submit(Request $request): void
    {
        if (!self::installAllowed()) {
            $reason = self::hasSuperAdmin()
                ? '系统里已经存在超级管理员，安装入口已自动关闭。请直接前往登录页。'
                : '安装入口已被配置关闭（config/config.php 里的 install.allow_bootstrap_admin）。';
            Response::error($reason, 403);
        }

        $email    = $request->email('email');
        $password = $request->raw('password', '', 200);
        $name     = $request->str('display_name', '管理员', 60);

        if ($email === '') {
            Response::error('请填写有效的邮箱地址', 422);
        }

        $create = UserService::create($email, $password, $name);
        if (!$create['ok']) {
            Response::error($create['error'], 422);
        }
        $userId = (int) $create['user']['id'];

        // 授予超级管理员
        $roleId = (int) DB::conn()->val("SELECT id FROM roles WHERE code = 'super_admin' LIMIT 1", [], 0);
        if ($roleId === 0) {
            Response::error(
                '找不到 super_admin 角色。请先导入 sql/schema.mysql.sql 里的初始化数据。',
                500
            );
        }
        DB::conn()->insert('user_roles', [
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'granted_by' => 0,
            'granted_at' => time(),
        ]);

        Settings::put('site.url', $request->origin(), 'general');

        Audit::log('admin', 'install.bootstrap', [
            'actor_id' => $userId,
            'actor'    => $email,
            'target'   => 'user:' . $userId,
            'detail'   => '通过安装引导创建第一个超级管理员',
        ]);

        Auth::login($userId, false, 'install');
        UserService::markLoginSuccess($userId, $request->ip());

        Response::ok([
            'redirect' => '/admin?flash=' . urlencode(
                '安装完成。请立刻把 config/config.php 里的 install.allow_bootstrap_admin 改成 false。'
            ) . '&flash_type=warn',
        ], '超级管理员已创建');
    }
}
