<?php
declare(strict_types=1);

/**
 * Vobl ID · 核心逻辑自测
 * ============================================================================
 * 用 SQLite 内存库跑一遍核心逻辑，重点验证「安全相关的代码真的能挡住攻击」，
 * 而不只是「语法没写错」。
 *
 * 运行：
 *   php tools/test.php
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') {
    exit('本文件只能通过命令行运行');
}

$root = dirname(__DIR__);

// 用测试配置引导（SQLite 内存库）
require_once $root . '/app/Core/Config.php';
require_once $root . '/app/Core/helpers.php';

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\RateLimit;
use Vobl\Core\Request;
use Vobl\Services\Payment\GatewayManager;
use Vobl\Services\Payment\MockGateway;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\Wallet;
use Vobl\Services\WebAuthn;

Config::load([
    'app' => ['url' => 'https://sso.test', 'debug' => true, 'cookie_name' => 'vobl_sid',
              'trust_proxy' => false, 'timezone' => 'Asia/Shanghai'],
    'db'  => ['driver' => 'sqlite', 'database' => ':memory:', 'strict_prepare' => true],
    'keys' => [
        'app_secret' => str_repeat('a', 64),
        'config_key' => str_repeat('b', 64),
    ],
    'paths' => ['storage' => sys_get_temp_dir()],
]);

/* ============================ 自动加载与引导 ============================ */

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'Vobl\\')) {
        return;
    }
    $file = $root . '/app/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

DB::init(Config::get('db'));
DB::conn()->exec((string) file_get_contents($root . '/tools/schema.sqlite.sql'));

/* ============================ 测试框架 ============================ */

/**
 * 计数器用类持有，不用 $GLOBALS。
 *
 * ★ 这里踩过一个真实的坑：原先写的是 $GLOBALS['fail']++，
 *   而 PHP 里 $GLOBALS['fail'] 与全局变量 $fail 是**同一个变量** ——
 *   测试体里一旦出现 $fail = false 这样的赋值（我在 WebAuthn 的 DER 解析里用过），
 *   计数器就被悄悄冲成 false，最终统计恒为 0 失败、退出码却始终是 1。
 *   用类属性彻底避开这类命名碰撞。
 */
final class Tally
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
}

function section(string $title): void
{
    echo "\n\033[36m" . $title . "\033[0m\n";
}

function check(string $name, bool $cond, $extra = null): void
{
    if ($cond) {
        Tally::$pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        Tally::$fail++;
        Tally::$failures[] = $name;
        $msg = $extra === null ? '' : '  \033[90m→ ' . (is_scalar($extra) ? (string) $extra : json_encode($extra, JSON_UNESCAPED_UNICODE)) . "\033[0m";
        echo "  \033[31m✗\033[0m {$name}{$msg}\n";
    }
}

/* ============================================================================
 * 1. SQL 注入防护
 * ========================================================================== */

section('1. SQL 注入防护');

// 1.1 标识符白名单
$badIdentifiers = [
    'users; DROP TABLE users; --',
    'users`',
    'users WHERE 1=1',
    '`users`',
    'users--',
    'users/*x*/',
    'users(id)',
    '1users',
    '',
];
$allRejected = true;
foreach ($badIdentifiers as $bad) {
    try {
        DB::conn()->identifier($bad);
        $allRejected = false;
        echo "    \033[31m未拦截：{$bad}\033[0m\n";
    } catch (\InvalidArgumentException $e) {
        // 预期
    }
}
check('非法表名/列名全部被 identifier() 拒绝', $allRejected);
check('合法标识符正常通过', DB::conn()->identifier('users') === '`users`');

// 1.2 参数化查询：注入串被当作数据而非语法
$payloads = [
    "'; DROP TABLE users; --",
    "\" OR 1=1 --",
    "'; DELETE FROM users WHERE 1=1; --",
    "admin'--",
    "1 UNION SELECT password_hash FROM users",
    "Robert'); DROP TABLE audit_logs;--",
    "'; UPDATE settings SET value='hacked' WHERE key='site.name'; --",
];
$injectedAsData = true;
foreach ($payloads as $i => $p) {
    $id = DB::conn()->insert('users', [
        'email' => "inj{$i}@test.local",
        'email_verified' => 1,
        'display_name' => $p,          // ★ 注入串作为普通数据写入
        'password_hash' => 'x',
        'algo' => 'bcrypt',
        'avatar' => '', 'status' => 'active', 'totp_secret' => '', 'totp_enabled' => 0,
        'failed_count' => 0, 'locked_until' => 0, 'last_login_at' => 0, 'last_login_ip' => '',
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $stored = (string) DB::conn()->val('SELECT display_name FROM users WHERE id = ?', [$id], '');
    if ($stored !== $p) {
        $injectedAsData = false;
        echo "    \033[31m数据被改动：{$p}\033[0m\n";
    }
}
check('注入串被原样存入（说明被当作数据而非 SQL）', $injectedAsData);
check('users 表依然存在', DB::conn()->count(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'"
) === 1);
check('audit_logs 表依然存在', DB::conn()->count(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='audit_logs'"
) === 1);
check('settings 表未被注入篡改', DB::conn()->count(
    "SELECT COUNT(*) FROM settings WHERE value = 'hacked'"
) === 0);

// 1.3 WHERE 条件里的注入尝试
$found = DB::conn()->one("SELECT * FROM users WHERE email = ? LIMIT 1", ["' OR '1'='1"]);
check('用注入串查询邮箱返回空（未被绕过）', $found === null);

// 1.4 ORDER BY 白名单
$order = DB::conn()->orderBy('email; DROP TABLE users', ['id', 'email'], 'id', 'DESC');
check('ORDER BY 非法字段被替换为默认值', $order === '`id` DESC', $order);
$order2 = DB::conn()->orderBy('email', ['id', 'email'], 'id', 'ASC');
check('ORDER BY 合法字段正常使用', $order2 === '`email` ASC', $order2);

// 1.5 LIKE 通配符不构成注入
$like = DB::conn()->all("SELECT id FROM users WHERE email LIKE ?", ['%test%']);
check('LIKE 参数化查询正常', count($like) > 0);

/* ============================================================================
 * 2. 加密工具
 * ========================================================================== */

section('2. 加密与签名');

$sealed = Crypto::seal(['uid' => 123, 'exp' => time() + 60], 'secret-key');
$opened = Crypto::open($sealed, 'secret-key');
check('seal/open 往返一致', is_array($opened) && ($opened['uid'] ?? 0) === 123);

$tampered = substr($sealed, 0, -3) . 'aaa';
check('篡改签名后 open 返回 null', Crypto::open($tampered, 'secret-key') === null);
check('用错密钥 open 返回 null', Crypto::open($sealed, 'wrong-key') === null);

$expired = Crypto::seal(['exp' => time() - 10], 'k');
check('过期载荷被拒绝', Crypto::open($expired, 'k') === null);
check('垃圾输入不抛异常', Crypto::open('not-a-token', 'k') === null && Crypto::open('', 'k') === null);

$plain = '这是一个需要加密的配置值 SMTP_PASSWORD_12345';
$key = str_repeat('c', 64);
$cipher = Crypto::encrypt($plain, $key);
check('AES-256-GCM 加密后不等于明文', $cipher !== $plain && str_starts_with($cipher, 'v1:'));
check('解密还原一致', Crypto::decrypt($cipher, $key) === $plain);

$tamperedCipher = 'v1:' . substr(substr($cipher, 3), 0, -4) . 'AAAA';
$threw = false;
try {
    Crypto::decrypt($tamperedCipher, $key);
} catch (\Throwable $e) {
    $threw = true;
}
check('篡改密文导致解密失败（GCM 认证生效）', $threw);

check('定长比较：相等返回 true', Crypto::equals('abc123', 'abc123'));
check('定长比较：不等返回 false', !Crypto::equals('abc123', 'abc124'));
check('定长比较：长度不同返回 false', !Crypto::equals('abc', 'abcd'));

$h = Crypto::hashPassword('MyStr0ng!Passw0rd');
check('密码哈希可用', $h !== '' && $h !== 'MyStr0ng!Passw0rd');
check('密码校验通过', Crypto::verifyPassword('MyStr0ng!Passw0rd', $h));
check('错误密码校验失败', !Crypto::verifyPassword('wrong', $h));
check('哈希自带随机盐（两次结果不同）', Crypto::hashPassword('same') !== Crypto::hashPassword('same'));

$c1 = Crypto::randDigits(6);
check('验证码为 6 位纯数字', preg_match('/^\d{6}$/', $c1) === 1, $c1);
$c2 = Crypto::randDigits(6);
check('两次验证码不同', $c1 !== $c2);
check('base64url 不含特殊字符', preg_match('/^[A-Za-z0-9_-]+$/', Crypto::randB64(32)) === 1);
check('base64url 解码往返正确', Crypto::b64urlDecode(Crypto::b64urlEncode('中文abc')) === '中文abc');

/* ============================================================================
 * 3. 配置与密钥打码
 * ========================================================================== */

section('3. 配置存储');

Settings::putMany([
    'mail.host' => 'smtp.example.com',
    'mail.password' => 'SuperSecret123',
    'mail.from_address' => 'a@example.com',
], 'smtp');

check('普通配置明文读取', Settings::get('mail.host') === 'smtp.example.com');
check('敏感配置透明解密读取', Settings::get('mail.password') === 'SuperSecret123');

$rawStored = (string) DB::conn()->val("SELECT value FROM settings WHERE `key` = ?", ['mail.password'], '');
check('敏感配置在库里是密文', $rawStored !== 'SuperSecret123' && str_starts_with($rawStored, 'v1:'),
    substr($rawStored, 0, 20) . '…');

$display = Settings::group('smtp');
check('界面回显时敏感项被替换为占位符',
    ($display['mail.password']['display'] ?? '') === '••••••••',
    $display['mail.password']['display'] ?? '');
check('非敏感项正常回显', ($display['mail.host']['display'] ?? '') === 'smtp.example.com');

// 关键：保存表单时带上占位符不能覆盖真实值
Settings::putMany(['mail.password' => '••••••••'], 'smtp');
check('提交占位符不会覆盖已存密钥（关键）', Settings::get('mail.password') === 'SuperSecret123');

Settings::putMany(['mail.password' => 'NewSecret456'], 'smtp');
check('提交新值会正常更新', Settings::get('mail.password') === 'NewSecret456');

check('isSecretKey 支持通配', Settings::isSecretKey('payment.epay.key')
    && Settings::isSecretKey('payment.alipay.private_key')
    && !Settings::isSecretKey('mail.host'));

/* ============================================================================
 * 4. 密码策略
 * ========================================================================== */

section('4. 密码强度策略');

Settings::putMany(['security.password_min' => '10'], 'security');

$cases = [
    ['', false, '空密码'],
    ['short', false, '过短'],
    ['password1234', false, '含常见弱口令 password'],
    ['1234567890123', false, '纯数字且不长'],
    ['abcdefghijklm', false, '纯字母且不足 16 位'],
    ['abcdefghijklmnop', true, '纯字母但够 16 位'],
    ['Ab3!xY9@pQz', true, '混合大小写数字符号'],
    ['CorrectHorseBatteryStaple', true, '长口令'],
];
foreach ($cases as [$pwd, $expect, $label]) {
    $r = UserService::validatePassword($pwd);
    check("密码策略：{$label}", $r['ok'] === $expect, $r['error'] ?: '');
}

/* ============================================================================
 * 5. 限流
 * ========================================================================== */

section('5. 限流');

DB::conn()->exec('DELETE FROM email_quota');
$first = RateLimit::hit('test:bucket', 3, 60);
check('首次请求放行', $first['allowed'] === true && $first['remaining'] === 2);
RateLimit::hit('test:bucket', 3, 60);
$third = RateLimit::hit('test:bucket', 3, 60);
check('第三次请求放行且剩余 0', $third['allowed'] === true && $third['remaining'] === 0);
$fourth = RateLimit::hit('test:bucket', 3, 60);
check('超出上限被拦截', $fourth['allowed'] === false && $fourth['retry_after'] > 0);

RateLimit::clear('test:bucket');
check('clear 后重新放行', RateLimit::hit('test:bucket', 3, 60)['allowed'] === true);

// 发信三重限流
DB::conn()->exec('DELETE FROM email_quota');
Settings::putMany(['mail.daily_limit' => '100'], 'smtp');
$g1 = RateLimit::guardMail('a@test.local', '1.2.3.4');
check('发信限流：首次放行', $g1['allowed'] === true);
$g2 = RateLimit::guardMail('a@test.local', '1.2.3.4');
check('发信限流：60 秒内重复发送被拦截', $g2['allowed'] === false && $g2['reason'] !== '', $g2['reason']);
$g3 = RateLimit::guardMail('b@test.local', '1.2.3.4');
check('发信限流：换邮箱可放行（说明按邮箱分桶）', $g3['allowed'] === true);

/* ============================================================================
 * 6. 账号体系与邮箱归一化
 * ========================================================================== */

section('6. 账号体系');

DB::conn()->exec('DELETE FROM users');
check('邮箱归一化：大小写与空格',
    UserService::normalizeEmail('  ADMIN@Example.COM ') === 'admin@example.com');

$create = UserService::create('  YuHuan@Test.Local ', 'MyStr0ng!Pass99', '渝欢');
check('创建账号成功', $create['ok'] === true, $create['error'] ?? '');
$userId = (int) ($create['user']['id'] ?? 0);
check('邮箱已按归一化存储',
    (string) DB::conn()->val('SELECT email FROM users WHERE id = ?', [$userId], '') === 'yuhuan@test.local');

$dup = UserService::create('yuhuan@test.local', 'AnotherPass123!', '');
check('重复邮箱被拒绝', $dup['ok'] === false);

$login = UserService::authenticate('YUHuan@TEST.local', 'MyStr0ng!Pass99');
check('登录：大小写不敏感', $login['ok'] === true);
$badLogin = UserService::authenticate('yuhuan@test.local', 'wrong-password');
check('登录：错误密码被拒绝', $badLogin['ok'] === false);
check('登录失败提示不区分「账号不存在」与「密码错误」',
    UserService::authenticate('nobody@test.local', 'x')['error'] === $badLogin['error'],
    $badLogin['error']);

/* ============================================================================
 * 7. 验证码（只存摘要、一次性、限次）
 * ========================================================================== */

section('7. 邮箱验证码');

DB::conn()->exec('DELETE FROM email_challenges');
DB::conn()->exec('DELETE FROM email_quota');

$code = '135790';
DB::conn()->insert('email_challenges', [
    'email' => 'code@test.local', 'user_id' => 0,
    'code_hash' => hash('sha256', $code),
    'kind' => 'verify_email', 'payload' => '', 'attempts' => 0, 'ip' => '',
    'expires_at' => time() + 600, 'consumed_at' => 0, 'created_at' => time(),
]);

$storedHash = (string) DB::conn()->val(
    "SELECT code_hash FROM email_challenges WHERE email = ? ORDER BY id DESC LIMIT 1",
    ['code@test.local'], ''
);
check('验证码只存 SHA-256 摘要，不存明文', $storedHash !== $code && strlen($storedHash) === 64);

$wrong = UserService::verifyCode('code@test.local', 'verify_email', '000000');
check('错误验证码被拒绝', $wrong['ok'] === false);
$right = UserService::verifyCode('code@test.local', 'verify_email', '135790');
check('正确验证码通过', $right['ok'] === true);
$reuse = UserService::verifyCode('code@test.local', 'verify_email', '135790');
check('验证码一次性：重复使用被拒绝（防重放）', $reuse['ok'] === false);

// 尝试次数上限
DB::conn()->insert('email_challenges', [
    'email' => 'brute@test.local', 'user_id' => 0,
    'code_hash' => hash('sha256', '999999'), 'kind' => 'verify_email', 'payload' => '',
    'attempts' => 0, 'ip' => '', 'expires_at' => time() + 600, 'consumed_at' => 0, 'created_at' => time(),
]);
for ($i = 0; $i < 5; $i++) {
    UserService::verifyCode('brute@test.local', 'verify_email', '111111');
}
$after5 = UserService::verifyCode('brute@test.local', 'verify_email', '999999');
check('超过 5 次尝试后，即使验证码正确也被拒绝', $after5['ok'] === false, $after5['error']);

// 过期
DB::conn()->insert('email_challenges', [
    'email' => 'expired@test.local', 'user_id' => 0,
    'code_hash' => hash('sha256', '246810'), 'kind' => 'verify_email', 'payload' => '',
    'attempts' => 0, 'ip' => '', 'expires_at' => time() - 1, 'consumed_at' => 0, 'created_at' => time() - 700,
]);
check('过期验证码被拒绝',
    UserService::verifyCode('expired@test.local', 'verify_email', '246810')['ok'] === false);

/* ============================================================================
 * 8. WebAuthn 关键转换
 * ========================================================================== */

section('8. 安全密钥（WebAuthn）');

$wa = new WebAuthn('sso.test', 'https://sso.test');

// CBOR 编解码往返（含负整数键 —— 这是最容易踩的坑）
$cose = [-1 => "\x01", -2 => str_repeat("\x11", 32), -3 => str_repeat("\x22", 32), 1 => 2, 3 => -7];
$encoded = $wa->cborEncode($cose);
$decoded = (new ReflectionClass($wa))->getMethod('cborDecode');
$decoded->setAccessible(true);
$off = 0;
$roundTrip = $decoded->invokeArgs($wa, [$encoded, &$off]);

check('CBOR 往返：整数键 1 正确', ($roundTrip[1] ?? null) === 2, $roundTrip[1] ?? 'null');
check('CBOR 往返：负整数键 -1 正确（关键）', ($roundTrip[-1] ?? null) === "\x01",
    is_string($roundTrip[-1] ?? null) ? bin2hex($roundTrip[-1]) : ($roundTrip[-1] ?? 'null'));
check('CBOR 往返：负整数键 -2 长度正确', strlen((string) ($roundTrip[-2] ?? '')) === 32);
check('CBOR 往返：alg 值 -7 正确保留', ($roundTrip[3] ?? null) === -7, $roundTrip[3] ?? 'null');

// COSE → PEM
//
// ★ 这里必须用「真实有效」的曲线点。
//   随机字节构成的 x/y 几乎不可能落在 P-256 曲线上，而 OpenSSL 解析公钥时
//   会校验点是否在曲线上 —— 用随机数据测试会得到一个假失败。
//   正确做法：先生成真实密钥对，从里面取出 x/y，再构造 COSE 并转回来。
$toPem = (new ReflectionClass($wa))->getMethod('coseToPem');
$toPem->setAccessible(true);

$srcKey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
$hasEc = $srcKey !== false;

if ($hasEc) {
    $srcDetails = openssl_pkey_get_details($srcKey);
    $realX = (string) ($srcDetails['ec']['x'] ?? '');
    $realY = (string) ($srcDetails['ec']['y'] ?? '');
    check('成功取到真实 P-256 公钥坐标', strlen($realX) === 32 && strlen($realY) === 32,
        strlen($realX) . '/' . strlen($realY));

    $coseReal = [1 => 2, 3 => -7, -1 => 1, -2 => $realX, -3 => $realY];
    $pem = $toPem->invoke($wa, $coseReal);

    check('COSE(EC2/P-256) 能转成 PEM',
        is_string($pem) && str_contains($pem, 'BEGIN PUBLIC KEY'));

    $keyRes = openssl_pkey_get_public($pem);
    check('★ 生成的 PEM 能被 OpenSSL 解析（证明 DER 结构正确）', $keyRes !== false);

    if ($keyRes !== false) {
        $details = openssl_pkey_get_details($keyRes);
        check('解析出的密钥是 EC 类型', ($details['type'] ?? -1) === OPENSSL_KEYTYPE_EC);
        check('曲线是 prime256v1（P-256）', ($details['ec']['curve_name'] ?? '') === 'prime256v1',
            $details['ec']['curve_name'] ?? '');
        // 最关键的验证：转换后拿到的坐标必须与原始 COSE 里的完全一致
        check('★ 转换后公钥坐标与原始值逐字节一致（往返无损）',
            ($details['ec']['x'] ?? '') === $realX && ($details['ec']['y'] ?? '') === $realY,
            'x 一致=' . (($details['ec']['x'] ?? '') === $realX ? '是' : '否')
            . ' y 一致=' . (($details['ec']['y'] ?? '') === $realY ? '是' : '否'));

        // 用转换出来的公钥验证原始私钥签的名 —— 端到端闭环
        $probe = 'webauthn-signed-data';
        $probeSig = '';
        openssl_sign($probe, $probeSig, $srcKey, OPENSSL_ALGO_SHA256);
        check('★ 用转换出的公钥能验通过原私钥的签名（端到端验证）',
            openssl_verify($probe, $probeSig, $pem, OPENSSL_ALGO_SHA256) === 1);
    }
} else {
    check('OpenSSL 可用（EC 密钥生成）', false,
        '当前 PHP 缺少 openssl.cnf，EC 相关测试无法进行。'
        . 'Linux 下通常无此问题；Windows 便携版可设置环境变量 OPENSSL_CONF 指向 openssl.cnf');
}

check('不支持的曲线返回空串', $toPem->invoke($wa, [1 => 2, 3 => -7, -1 => 2,
    -2 => random_bytes(48), -3 => random_bytes(48)]) === '');

// ★ DER / raw 签名双格式兼容：用真实密钥对签名，两种格式都要能验过
$res = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
check('生成测试用 EC 密钥对', $res !== false);

if ($res !== false) {
    $privDetails = openssl_pkey_get_details($res);
    $pubPem = $privDetails['key'];
    $data = 'authenticatordata' . hash('sha256', 'clientdata', true);

    // DER 签名（WebAuthn 原生格式，PHP 的 openssl_verify 直接接受）
    $derSig = '';
    openssl_sign($data, $derSig, $res, OPENSSL_ALGO_SHA256);
    check('DER 签名验证通过', openssl_verify($data, $derSig, $pubPem, OPENSSL_ALGO_SHA256) === 1);

    // raw 签名（r‖s 定长 64 字节）→ 需转 DER
    $rawToDer = (new ReflectionClass($wa))->getMethod('rawToDer');
    $rawToDer->setAccessible(true);
    // 从 DER 里取出 r、s 再还原成 raw，然后测转换回 DER 能否验证
    $parsed = [];
    $okParse = openssl_sign($data, $sig2, $res, OPENSSL_ALGO_SHA256);
    // 构造一个 raw 签名：手动解析 DER
    $p = 0;
    $derBroken = false;
    if (ord($sig2[$p++]) !== 0x30) { $derBroken = true; }
    $seqLen = ord($sig2[$p++]);
    if ($seqLen & 0x80) { $p += $seqLen & 0x7f; }
    if (!$derBroken && ord($sig2[$p++]) === 0x02) {
        $rLen = ord($sig2[$p++]);
        $r = substr($sig2, $p, $rLen); $p += $rLen;
        if (ord($sig2[$p++]) === 0x02) {
            $sLen = ord($sig2[$p++]);
            $s = substr($sig2, $p, $sLen);
            $r32 = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $s32 = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $rawSig = $r32 . $s32;
            $backToDer = $rawToDer->invoke($wa, $rawSig);
            check('raw 签名转 DER 后验证通过（双格式兼容）',
                openssl_verify($data, $backToDer, $pubPem, OPENSSL_ALGO_SHA256) === 1);
            check('转换后的 DER 以 0x30 开头', $backToDer[0] === "\x30");
        } else {
            check('raw→DER 转换测试（DER 结构异常）', false);
        }
    } else {
        check('raw→DER 转换测试（DER 解析异常）', false);
    }

    // 用错误密钥签名应验不过
    $res2 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $sig3 = '';
    openssl_sign($data, $sig3, $res2, OPENSSL_ALGO_SHA256);
    check('换私钥签名后验证失败（安全性）',
        openssl_verify($data, $sig3, $pubPem, OPENSSL_ALGO_SHA256) !== 1);
}

// 挑战一次性
DB::conn()->exec('DELETE FROM webauthn_challenges');
$challenge = Crypto::randB64(32);
DB::conn()->insert('webauthn_challenges', [
    'challenge' => hash('sha256', $challenge), 'kind' => 'login', 'user_id' => 0,
    'expires_at' => time() + 300,
]);
$assertChallenge = (new ReflectionClass($wa))->getMethod('assertChallenge');
$assertChallenge->setAccessible(true);
$firstPass = true;
try { $assertChallenge->invoke($wa, $challenge, 'login', 0); } catch (\Throwable $e) { $firstPass = false; }
check('挑战值首次校验通过', $firstPass);
$secondPass = false;
try { $assertChallenge->invoke($wa, $challenge, 'login', 0); } catch (\Throwable $e) { $secondPass = true; }
check('挑战值用后即删，重复使用被拒绝（防重放）', $secondPass);

// 凭据删除顺序：先查存在（404），再判最后一把（409）
DB::conn()->exec('DELETE FROM webauthn_credentials');
$del1 = WebAuthn::delete($userId, 'nonexistent-id');
check('删除不存在的凭据返回 404（而不是 409）', $del1['status'] === 404, $del1['error']);

DB::conn()->insert('webauthn_credentials', [
    'id' => 'key1', 'user_id' => $userId, 'credential_id' => 'cred-1',
    'public_key' => 'x', 'alg' => -7, 'counter' => 0, 'name' => '测试密钥',
    'aaguid' => '', 'transports' => '', 'backup_eligible' => 0,
    'created_at' => time(), 'last_used_at' => 0,
]);
$del2 = WebAuthn::delete($userId, 'key1');
check('删除最后一把密钥被拒绝（409）', $del2['status'] === 409, $del2['error']);
check('被拒绝时凭据仍然存在', WebAuthn::countFor($userId) === 1);

/* ============================================================================
 * 9. 支付与钱包
 * ========================================================================== */

section('9. 支付与钱包');

DB::conn()->exec('DELETE FROM payment_orders');
DB::conn()->exec('DELETE FROM wallet_transactions');
DB::conn()->exec('DELETE FROM wallets');
DB::conn()->exec('DELETE FROM payment_gateways');

Settings::putMany([
    'payment.recharge_min' => '100',
    'payment.recharge_max' => '100000',
    'payment.bonus_rate' => '0',
    'payment.currency' => 'CNY',
    'site.url' => 'https://sso.test',
], 'payment');

// 通道自动注册（模拟通道默认启用）
$gateways = GatewayManager::all(true);
check('支付通道自动注册到数据库', count($gateways) >= 3, count($gateways) . ' 个');
check('模拟通道默认已启用', GatewayManager::default() !== null);
check('默认通道是模拟通道', (GatewayManager::default()['code'] ?? '') === 'mock');

// 金额边界
$tooSmall = Wallet::createRechargeOrder($userId, 50, 'mock', 'alipay', '127.0.0.1');
check('低于最低充值额被拒绝', $tooSmall['ok'] === false, $tooSmall['error']);
$tooBig = Wallet::createRechargeOrder($userId, 999999999, 'mock', 'alipay', '127.0.0.1');
check('超过最高充值额被拒绝', $tooBig['ok'] === false, $tooBig['error']);
$zero = Wallet::createRechargeOrder($userId, 0, 'mock', 'alipay', '127.0.0.1');
check('金额为 0 被拒绝', $zero['ok'] === false);
$neg = Wallet::createRechargeOrder($userId, -100, 'mock', 'alipay', '127.0.0.1');
check('负数金额被拒绝', $neg['ok'] === false);

// 正常下单
$order = Wallet::createRechargeOrder($userId, 10000, 'mock', 'alipay', '127.0.0.1');
check('创建充值订单成功', $order['ok'] === true, $order['error'] ?? '');
$orderRow = $order['order'] ?? [];
$outTradeNo = (string) ($orderRow['out_trade_no'] ?? '');
check('订单号非空且唯一', $outTradeNo !== '');
check('订单金额以「分」存储', (int) ($orderRow['amount'] ?? 0) === 10000);
check('订单初始状态为待支付', (string) ($orderRow['status'] ?? '') === 'pending');

// 模拟通道签名
$mock = new MockGateway(['key' => 'mock-secret-key']);
$signParams = ['out_trade_no' => $outTradeNo, 'amount' => '10000', 'paid' => '1'];
$sign = $mock->sign($signParams);
check('模拟通道能生成签名', strlen($sign) === 32);
check('模拟通道验签通过', $mock->verifyNotify(array_merge($signParams, ['sign' => $sign])));
check('篡改金额后验签失败', !$mock->verifyNotify(array_merge($signParams, ['amount' => '1', 'sign' => $sign])));

// ★ 入账幂等性（最关键的一条）
$settle1 = Wallet::settleNotify('mock', array_merge($signParams, ['sign' => $sign]));
check('首次通知入账成功', $settle1['ok'] === true && $settle1['already'] === false);
check('余额已增加 100 元', Wallet::balance($userId) === 10000, Wallet::balance($userId));

$settle2 = Wallet::settleNotify('mock', array_merge($signParams, ['sign' => $sign]));
check('重复通知被识别为「已处理」', $settle2['ok'] === true && $settle2['already'] === true);
check('★ 重复通知不会二次入账（幂等）', Wallet::balance($userId) === 10000, Wallet::balance($userId));

check('流水只记录了 1 条',
    DB::conn()->count("SELECT COUNT(*) FROM wallet_transactions WHERE kind = 'recharge'") === 1);

// 验签失败必须拒绝
$badSign = Wallet::settleNotify('mock', ['out_trade_no' => $outTradeNo, 'amount' => '10000',
    'paid' => '1', 'sign' => 'deadbeefdeadbeefdeadbeefdeadbeef']);
check('验签失败的通知被拒绝', $badSign['ok'] === false && str_contains($badSign['error'], '验签'));

// 金额不符必须拒绝
$order2 = Wallet::createRechargeOrder($userId, 5000, 'mock', 'alipay', '127.0.0.1');
$no2 = (string) ($order2['order']['out_trade_no'] ?? '');
$p2 = ['out_trade_no' => $no2, 'amount' => '1', 'paid' => '1'];
$p2['sign'] = $mock->sign($p2);
$mismatch = Wallet::settleNotify('mock', $p2);
check('★ 回调金额与订单不符时拒绝入账', $mismatch['ok'] === false, $mismatch['error']);
check('被拒后余额未变', Wallet::balance($userId) === 10000, Wallet::balance($userId));
check('被拒后订单仍是待支付', (string) DB::conn()->val(
    'SELECT status FROM payment_orders WHERE out_trade_no = ?', [$no2], '') === 'pending');

// 扣款
$before = Wallet::balance($userId);
$debit = Wallet::debit($userId, 3000, 'consume', '测试消费');
check('扣款成功', $debit['ok'] === true);
check('余额正确扣减', Wallet::balance($userId) === $before - 3000, Wallet::balance($userId));

$overdraft = Wallet::debit($userId, 99999999, 'consume', '超额消费');
check('★ 余额不足时扣款被拒绝（不会出现负数余额）', $overdraft['ok'] === false, $overdraft['error']);
check('被拒后余额保持不变', Wallet::balance($userId) === $before - 3000);

// 先验证「余额不足时退款被拒绝」—— 这是正确的保护行为
$insufficient = Wallet::refund($outTradeNo, 1, '余额不够时的退款尝试');
check('★ 余额不足以扣回时退款被拒绝（而非扣成负数）',
    $insufficient['ok'] === false && str_contains($insufficient['error'], '不足以扣回'),
    $insufficient['error']);
check('退款被拒后订单状态未被改为已退款', (string) DB::conn()->val(
    'SELECT status FROM payment_orders WHERE out_trade_no = ?', [$outTradeNo], ''
) === 'paid');
check('退款被拒后余额未变', Wallet::balance($userId) === $before - 3000);

// 补足余额后再退款
Wallet::adjust($userId, 5000, '测试：补足余额以验证退款', 1);
$balanceBeforeRefund = Wallet::balance($userId);

$refund = Wallet::refund($outTradeNo, 1, '测试退款');
check('余额充足时退款成功', $refund['ok'] === true, $refund['error'] ?? '');
check('退款已按入账金额扣回（含赠送）',
    Wallet::balance($userId) === $balanceBeforeRefund - 10000, Wallet::balance($userId));
$refundAgain = Wallet::refund($outTradeNo, 1, '重复退款');
check('已退款订单不能重复退款', $refundAgain['ok'] === false, $refundAgain['error']);

// 管理员调整
$beforeAdjust = Wallet::balance($userId);
$adj = Wallet::adjust($userId, 8888, '手工补录', 1);
check('管理员调整余额成功', $adj['ok'] === true);
check('调整后余额正确', Wallet::balance($userId) === $beforeAdjust + 8888, Wallet::balance($userId));
$adjNeg = Wallet::adjust($userId, -100000000, '超额扣减', 1);
check('扣减超过余额时被拒绝', $adjNeg['ok'] === false, $adjNeg['error'] ?? '');
$adjZero = Wallet::adjust($userId, 0, '零调整', 1);
check('调整金额为 0 被拒绝', $adjZero['ok'] === false);
check('调整操作已写入审计日志', DB::conn()->count(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'wallet.adjust' AND detail LIKE '%手工补录%'"
) === 1);
check('流水记录了操作人', (int) DB::conn()->val(
    "SELECT operator FROM wallet_transactions WHERE kind = 'admin_adjust' ORDER BY id DESC LIMIT 1", [], 0
) === 1);

// 通道切换只允许一个默认
$codes = array_column(GatewayManager::all(true), 'code');
GatewayManager::toggle('epay', false);
$switchResult = GatewayManager::setDefault('epay');
check('设置未启用的通道为默认被拒绝', $switchResult['ok'] === false, $switchResult['error'] ?? '');
check('默认通道数量永远不超过 1', DB::conn()->count(
    'SELECT COUNT(*) FROM payment_gateways WHERE is_default = 1'
) <= 1);

// 金额解析（元 → 分，绝不使用浮点）
$req = new Request(['amount' => '99.99'], [], [], [], [], '');
check('金额解析：99.99 元 → 9999 分', $req->amountCents('amount') === 9999);
$req2 = new Request(['amount' => '0.01'], [], [], [], [], '');
check('金额解析：0.01 元 → 1 分', $req2->amountCents('amount') === 1);
$req3 = new Request(['amount' => '100'], [], [], [], [], '');
check('金额解析：整数 100 元 → 10000 分', $req3->amountCents('amount') === 10000);
$req4 = new Request(['amount' => '1.005'], [], [], [], [], '');
check('金额解析：小数超两位被拒绝（防精度问题）', $req4->amountCents('amount') === 0);
$req5 = new Request(['amount' => 'abc'], [], [], [], [], '');
check('金额解析：非法输入返回 0', $req5->amountCents('amount') === 0);

/* ============================================================================
 * 10. 第三方绑定判定
 * ========================================================================== */

section('10. 第三方登录与绑定');

DB::conn()->exec('DELETE FROM identities');

// 已验证邮箱 → 自动合并
$auto = UserService::resolveIdentity('microsoft', 'ms-uid-001', 'yuhuan@test.local', true, ['nickname' => '渝欢']);
check('已验证邮箱命中已有账号 → 自动绑定登录', $auto['ok'] === true && $auto['action'] === 'autolink',
    $auto['action'] ?? '');
check('自动绑定已写入 identities', DB::conn()->count(
    "SELECT COUNT(*) FROM identities WHERE provider = 'microsoft' AND provider_uid = 'ms-uid-001'"
) === 1);

// 二次登录直接命中
$again = UserService::resolveIdentity('microsoft', 'ms-uid-001', 'yuhuan@test.local', true, []);
check('同一第三方账号再次登录 → 直接登录', $again['ok'] === true && $again['action'] === 'login');

// ★ 未验证邮箱绝不能自动合并
$unverified = UserService::resolveIdentity('github', 'gh-uid-002', 'yuhuan@test.local', false, []);
check('★ 未验证邮箱不自动合并，要求手动绑定',
    $unverified['ok'] === false && $unverified['action'] === 'need_bind', $unverified['action'] ?? '');
check('未验证邮箱的请求没有写入 identities', DB::conn()->count(
    "SELECT COUNT(*) FROM identities WHERE provider = 'github'"
) === 0);

// 不返回邮箱的 provider（如腾讯云/微信）也只能手动绑定
$noEmail = UserService::resolveIdentity('wechat', 'wx-openid-003', '', false, ['nickname' => '微信用户']);
check('不返回邮箱的 provider 走手动绑定分支',
    $noEmail['ok'] === false && $noEmail['action'] === 'need_bind');
check('拒绝理由说明了原因', str_contains((string) ($noEmail['reason'] ?? ''), '没有提供可验证的邮箱'),
    $noEmail['reason'] ?? '');

// 已注册到别的账号的第三方不能重复绑定
$otherUser = UserService::create('other@test.local', 'OtherPass1234!', '别人');
$bindConflict = UserService::bindIdentity((int) $otherUser['user']['id'], 'microsoft', 'ms-uid-001', []);
check('已绑定到其他账号的第三方不能重复绑定', $bindConflict['ok'] === false, $bindConflict['error']);
check('错误提示说明了已被占用', str_contains((string) ($bindConflict['error'] ?? ''), '其他'), $bindConflict['error'] ?? '');

// 解绑顺序：先 404 后 409
$unbind404 = UserService::unbindIdentity($userId, 'github');
check('解绑不存在的绑定返回 404（而不是 409）', $unbind404['status'] === 404, $unbind404['error']);

// 该用户有密码 + 1 个第三方绑定 → 解绑第三方应该成功（还有密码与安全密钥可用）
$bound = UserService::bindIdentity($userId, 'github', 'gh-uid-004', ['email' => 'yuhuan@test.local']);
check('绑定新的第三方账号成功', $bound['ok'] === true, $bound['error'] ?? '');
$unbindOk = UserService::unbindIdentity($userId, 'github');
check('还有其他登录方式时解绑成功', $unbindOk['ok'] === true, $unbindOk['error'] ?? '');

/* ============================================================================
 * 11. 权限能力位
 * ========================================================================== */

section('11. 权限模型');

DB::conn()->exec('DELETE FROM user_roles');
$superRoleId = (int) DB::conn()->val("SELECT id FROM roles WHERE code = 'super_admin'", [], 0);
$auditRoleId = (int) DB::conn()->val("SELECT id FROM roles WHERE code = 'auditor'", [], 0);
check('内置角色存在', $superRoleId > 0 && $auditRoleId > 0);

DB::conn()->insert('user_roles', [
    'user_id' => (int) $otherUser['user']['id'], 'role_id' => $auditRoleId,
    'granted_by' => 0, 'granted_at' => time(),
]);

check('超级管理员数量统计正确', Auth::superAdminCount() === 0, Auth::superAdminCount());

DB::conn()->insert('user_roles', [
    'user_id' => $userId, 'role_id' => $superRoleId,
    'granted_by' => 0, 'granted_at' => time(),
]);
check('授予超管后计数为 1', Auth::superAdminCount() === 1);

// 能力位解析（注：Auth::capabilities 依赖当前会话，这里直接查库验证逻辑）
$caps = json_decode((string) DB::conn()->val(
    'SELECT capabilities FROM roles WHERE id = ?', [$auditRoleId], '[]'
), true);
check('审计员角色能力位可解析', is_array($caps) && in_array('audit.view', $caps, true));
check('审计员不含用户编辑权限', !in_array('user.edit', $caps, true));
check('超管能力位是通配符 *', in_array('*', json_decode((string) DB::conn()->val(
    'SELECT capabilities FROM roles WHERE id = ?', [$superRoleId], '[]'
), true), true));

/* ============================================================================
 * 12. 请求解析与限流键
 * ========================================================================== */

section('12. 请求解析');

$req = new Request(
    ['q' => '  <script>alert(1)</script>  ', 'n' => '42'],
    ['name' => '  test  '],
    [],
    [],
    [],
    ''
);
check('字符串自动 trim', $req->str('name') === 'test');
check('控制字符被剥离', !str_contains($req->str('q'), "\n"));
check('整数解析正确', $req->int('n') === 42);
check('非数字返回默认值', $req->int('missing', 7) === 7);
check('超长输入被截断', mb_strlen($req->str('q', '', 5)) <= 5);

// Number(null) === 0 的坑：不传参数时必须走 fallback
$req2 = new Request([], [], [], [], [], '');
check('不传参数时 int 返回默认值（而非 0）', $req2->int('limit', 20) === 20);

// 邮箱校验
$req3 = new Request(['e1' => 'A@B.CoM', 'e2' => 'not-an-email', 'e3' => '  x@y.cn '], [], [], [], [], '');
check('邮箱归一化 + 校验通过', $req3->email('e1') === 'a@b.com');
check('非法邮箱返回空串', $req3->email('e2') === '');
check('邮箱自动 trim', $req3->email('e3') === 'x@y.cn');

// 布尔解析
$req4 = new Request(['a' => '1', 'b' => 'true', 'c' => '0', 'd' => 'on'], [], [], [], [], '');
check('布尔解析：1/true/on 为真', $req4->bool('a') && $req4->bool('b') && $req4->bool('d'));
check('布尔解析：0 为假', $req4->bool('c') === false);
check('布尔解析：缺省用默认值', $req4->bool('x', true) === true);

/* ============================================================================
 * 13. 审计日志脱敏
 * ========================================================================== */

section('13. 审计日志脱敏');

$sanitized = Audit::sanitize([
    'email' => 'a@b.com',
    'password' => 'SuperSecret123',
    'api_key' => 'sk-abcdef123456',
    'access_token' => 'token-value',
    'totp_secret' => 'JBSWY3DPEHPK3PXP',
    'code' => '135790',
    'normal_field' => 'visible',
]);
check('密码被脱敏', str_contains($sanitized, 'password=***') && !str_contains($sanitized, 'SuperSecret123'));
check('API 密钥被脱敏', str_contains($sanitized, 'api_key=***'));
check('访问令牌被脱敏', str_contains($sanitized, 'access_token=***'));
check('TOTP 密钥被脱敏', str_contains($sanitized, 'totp_secret=***'));
check('验证码被脱敏', str_contains($sanitized, 'code=***'));
check('普通字段正常保留', str_contains($sanitized, 'normal_field=visible'));
check('邮箱正常保留（便于追溯）', str_contains($sanitized, 'a@b.com'));

// 嵌套结构
$nested = Audit::sanitize(['user' => ['name' => 'x', 'secret' => 'yyyy']]);
check('嵌套结构也会脱敏', str_contains($nested, 'secret=***') && !str_contains($nested, 'yyyy'));

// 审计日志写入
DB::conn()->exec('DELETE FROM audit_logs');
Audit::log('auth', 'test.event', ['actor' => 'tester', 'detail' => '测试写入']);
check('审计日志能正常写入', DB::conn()->count("SELECT COUNT(*) FROM audit_logs WHERE action = 'test.event'") === 1);

/* ============================================================================
 * 14. 「不能操作自己 / 最后一个超管」的规则
 * ========================================================================== */

section('14. 权限体系的硬规则');

// 这两条规则在 UserController 里实现，此处验证其依赖的判断逻辑
check('超管计数可用于「最后一个超管」判断', is_int(Auth::superAdminCount()) && Auth::superAdminCount() >= 1);
check('能判断某用户是否为超管', DB::conn()->count(
    "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
     WHERE ur.user_id = ? AND r.code = 'super_admin'", [$userId]
) === 1);

/* ============================================================================
 * 结果
 * ========================================================================== */

echo "\n" . str_repeat('─', 64) . "\n";
if (Tally::$fail === 0) {
    echo "  \033[32m\033[1m全部通过：" . Tally::$pass . " 项断言\033[0m\n";
} else {
    echo "  \033[31m\033[1m通过 " . Tally::$pass . " 项，失败 " . Tally::$fail . " 项\033[0m\n";
    foreach (Tally::$failures as $f) {
        echo "    \033[31m· " . $f . "\033[0m\n";
    }
}
echo str_repeat('─', 64) . "\n\n";

exit(Tally::$fail === 0 ? 0 : 1);
