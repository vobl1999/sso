<?php
declare(strict_types=1);

/**
 * 本地开发数据库初始化（SQLite）
 * ============================================================================
 * 让你在没有 MySQL 的机器上也能把整套系统跑起来试。
 *
 * 用法：
 *   php tools/setup-sqlite.php            # 创建 storage/dev.sqlite 并写入基础数据
 *   php tools/setup-sqlite.php --fresh    # 先删除再重建
 *
 * 然后把 config/config.php 里的 db 段改成：
 *   'driver'   => 'sqlite',
 *   'database' => __DIR__ . '/../storage/dev.sqlite',
 *
 * ⚠️ 仅用于本地开发。生产环境请用 MySQL（sql/schema.mysql.sql）。
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') {
    exit('本文件只能通过命令行运行');
}

$root = dirname(__DIR__);
$fresh = in_array('--fresh', $argv, true);
$dbFile = $root . '/storage/dev.sqlite';

if ($fresh && is_file($dbFile)) {
    unlink($dbFile);
    echo "已删除旧的开发数据库\n";
}

if (!is_dir($root . '/storage')) {
    mkdir($root . '/storage', 0755, true);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$schema = (string) file_get_contents($root . '/tools/schema.sqlite.sql');
$pdo->exec($schema);
echo "表结构已创建\n";

// ---------------------------------------------------------------- 默认配置
$now = time();
$defaults = [
    ['site.name', 'Vobl ID', 'general', 0],
    ['site.url', 'http://127.0.0.1:8787', 'general', 0],
    ['site.icp', '', 'general', 0],
    ['mail.driver', 'smtp', 'smtp', 0],
    ['mail.host', '', 'smtp', 0],
    ['mail.port', '465', 'smtp', 0],
    ['mail.encryption', 'ssl', 'smtp', 0],
    ['mail.auth_method', 'auto', 'smtp', 0],
    ['mail.username', '', 'smtp', 0],
    ['mail.password', '', 'smtp', 1],
    ['mail.from_address', '', 'smtp', 0],
    ['mail.from_name', 'Vobl ID', 'smtp', 0],
    ['mail.reply_to', '', 'smtp', 0],
    ['mail.timeout', '15', 'smtp', 0],
    ['mail.daily_limit', '900', 'smtp', 0],
    ['mail.code_ttl', '600', 'smtp', 0],
    ['security.password_min', '10', 'security', 0],
    ['security.session_ttl', '604800', 'security', 0],
    ['security.require_2fa_admin', '0', 'security', 0],
    ['security.webauthn_enabled', '1', 'security', 0],
    ['security.allow_register', '1', 'security', 0],
    ['security.cookie_remember', '0', 'security', 0],
    ['providers.enabled', 'email', 'auth', 0],
    ['payment.currency', 'CNY', 'payment', 0],
    ['payment.recharge_min', '100', 'payment', 0],
    ['payment.recharge_max', '1000000', 'payment', 0],
    ['payment.bonus_rate', '0', 'payment', 0],
    ['payment.allow_manual', '0', 'payment', 0],
];
$stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (`key`, `value`, `group_name`, `is_secret`, `updated_at`) VALUES (?, ?, ?, ?, ?)');
foreach ($defaults as [$k, $v, $g, $s]) {
    $stmt->execute([$k, $v, $g, $s, $now]);
}
echo '默认配置已写入（' . count($defaults) . " 项）\n";

// ---------------------------------------------------------------- 支付通道
$gateways = [
    ['mock', '模拟支付（仅测试用）', 'Vobl\\Services\\Payment\\MockGateway',
     json_encode(['key' => 'mock-secret-key'], JSON_UNESCAPED_UNICODE), 1, 1, 0,
     '仅用于本地跑通充值流程。上线前请停用。'],
    ['epay', '易支付（聚合通道）', 'Vobl\\Services\\Payment\\EpayGateway',
     json_encode(['api_url' => '', 'pid' => '', 'key' => '', 'pay_type_alipay' => 'alipay', 'pay_type_wechat' => 'wxpay'], JSON_UNESCAPED_UNICODE),
     0, 0, 1, ''],
    ['alipay', '支付宝 · 电脑网站支付', 'Vobl\\Services\\Payment\\AlipayGateway',
     json_encode(['app_id' => '', 'private_key' => '', 'alipay_public_key' => '',
                  'gateway' => 'https://openapi.alipay.com/gateway.do',
                  'product_code' => 'FAST_INSTANT_TRADE_PAY'], JSON_UNESCAPED_UNICODE),
     0, 0, 2, ''],
    ['wechat', '微信支付 · Native 扫码（APIv2）', 'Vobl\\Services\\Payment\\WechatGateway',
     json_encode(['app_id' => '', 'mch_id' => '', 'api_key' => '', 'sign_type' => 'MD5'], JSON_UNESCAPED_UNICODE),
     0, 0, 3, ''],
];
$gStmt = $pdo->prepare('INSERT OR REPLACE INTO payment_gateways
    (code, name, driver, config, enabled, is_default, sort_order, note, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($gateways as $g) {
    $gStmt->execute([...$g, $now, $now]);
}
echo '支付通道已写入（' . count($gateways) . " 个）\n";

// ---------------------------------------------------------------- 演示账号
$demoEmail = 'demo@vobl.local';
$exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$exists->execute([$demoEmail]);
if ($exists->fetchColumn() === false) {
    $pdo->prepare('INSERT INTO users
        (email, email_verified, display_name, password_hash, algo, avatar, status,
         totp_secret, totp_enabled, failed_count, locked_until, last_login_at, last_login_ip,
         created_at, updated_at)
        VALUES (?, 1, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?)')
        ->execute([
            $demoEmail, '演示用户',
            password_hash('Demo-Passw0rd!23', PASSWORD_DEFAULT, ['cost' => 12]),
            'bcrypt', '', 'active', '', '', $now, $now,
        ]);
    $uid = (int) $pdo->lastInsertId();

    // 给演示账号超级管理员，方便直接体验后台
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'super_admin'")->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, ?, 0, ?)')
        ->execute([$uid, $roleId, $now]);

    // 钱包
    $pdo->prepare('INSERT INTO wallets (user_id, balance, frozen, currency, updated_at) VALUES (?, 0, 0, ?, ?)')
        ->execute([$uid, 'CNY', $now]);

    echo "演示账号已创建\n";
    echo "  邮箱：" . $demoEmail . "\n";
    echo "  密码：Demo-Passw0rd!23\n";
    echo "  角色：超级管理员（可直接进后台）\n";
} else {
    echo "演示账号已存在，跳过\n";
}

echo "\n完成。数据库：" . $dbFile . "\n";
echo "记得把 config/config.php 的 db 段指向它（driver=sqlite）。\n";
echo "启动：php -S 127.0.0.1:8787 -t public public/router.php\n";
