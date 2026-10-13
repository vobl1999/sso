<?php
declare(strict_types=1);

/**
 * Vobl ID · 命令行工具
 * ============================================================================
 * 用法：php tools/cli.php <命令> [参数]
 *
 * 邮件相关：
 *   mail:apply              把 config/mail.local.php 的配置写入数据库
 *   mail:show               显示当前数据库里的邮件配置（密钥打码）
 *   mail:test               测试 SMTP 连接与认证（不发信）
 *   mail:send <邮箱>        发送一封真实测试邮件
 *   mail:diagnose           综合诊断：配置 + 连通性 + 认证 + 常见问题排查
 *   mail:oauth-help         显示微软 OAuth2 的获取步骤
 *
 * 其他：
 *   user:list               列出用户
 *   user:promote <邮箱>     把用户设为超级管理员（紧急恢复用）
 *   order:close-expired     关闭超时未支付的订单
 *   audit:prune [天数]      清理过期审计日志（默认 180 天）
 *   env:check               环境自检
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本文件只能通过命令行运行');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Vobl\Core\Audit;
use Vobl\Core\Config;
use Vobl\Core\DB;
use Vobl\Core\RateLimit;
use Vobl\Services\Mailer;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\Wallet;

/* ============================ 输出辅助 ============================ */

final class Out
{
    public static function title(string $text): void
    {
        echo "\n" . str_repeat('─', 64) . "\n";
        echo "  \033[1m{$text}\033[0m\n";
        echo str_repeat('─', 64) . "\n";
    }

    public static function ok(string $text): void
    {
        echo "  \033[32m✓\033[0m {$text}\n";
    }

    public static function fail(string $text): void
    {
        echo "  \033[31m✗\033[0m {$text}\n";
    }

    public static function warn(string $text): void
    {
        echo "  \033[33m!\033[0m {$text}\n";
    }

    public static function info(string $text): void
    {
        echo "  \033[36m·\033[0m {$text}\n";
    }

    public static function plain(string $text = ''): void
    {
        echo "  {$text}\n";
    }

    /** 多行文本：自动缩进，保留相对缩进 */
    public static function block(string $text, string $color = ''): void
    {
        $prefix = $color !== '' ? $color : '';
        $suffix = $color !== '' ? "\033[0m" : '';
        foreach (explode("\n", $text) as $line) {
            echo "    {$prefix}{$line}{$suffix}\n";
        }
    }
}

$cmd = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

/* ============================ 邮件命令 ============================ */

function loadLocalMail(): array
{
    $file = VOBL_ROOT . '/config/mail.local.php';
    if (!is_file($file)) {
        Out::fail('找不到 config/mail.local.php');
        Out::info('可以复制 config/mail.local.sample.php 作为模板，或直接跑 `php tools/cli.php mail:test` 测试数据库里的配置。');
        exit(1);
    }
    $data = require $file;
    if (!is_array($data)) {
        Out::fail('config/mail.local.php 必须返回一个数组');
        exit(1);
    }
    return $data;
}

switch ($cmd) {

    // ---------------------------------------------------------------- 写入配置
    case 'mail:apply':
        Out::title('写入邮件配置');
        $mail = loadLocalMail();

        $map = [
            'host'          => 'mail.host',
            'port'          => 'mail.port',
            'encryption'    => 'mail.encryption',
            'auth_method'   => 'mail.auth_method',
            'username'      => 'mail.username',
            'password'      => 'mail.password',
            'from_address'  => 'mail.from_address',
            'from_name'     => 'mail.from_name',
            'reply_to'      => 'mail.reply_to',
            'timeout'       => 'mail.timeout',
            'daily_limit'   => 'mail.daily_limit',
            'code_ttl'      => 'mail.code_ttl',
        ];

        $data = [];
        foreach ($map as $src => $key) {
            if (!array_key_exists($src, $mail)) {
                continue;
            }
            $data[$key] = (string) $mail[$src];
        }
        // OAuth2 相关参数（用于 xoauth2 认证）
        foreach (['oauth_client_id', 'oauth_client_secret', 'oauth_tenant', 'oauth_refresh_token'] as $k) {
            if (!empty($mail[$k])) {
                $data['mail.' . $k] = (string) $mail[$k];
            }
        }

        Settings::putMany($data, 'smtp');

        Out::ok('已写入 ' . count($data) . ' 项配置（敏感项已加密存储）');
        Out::plain();
        Out::info('服务器：' . ($mail['host'] ?? '') . ':' . ($mail['port'] ?? '') . ' / ' . ($mail['encryption'] ?? ''));
        Out::info('认证方式：' . ($mail['auth_method'] ?? 'auto'));
        Out::info('发件人：' . ($mail['from_address'] ?? ''));

        if (($mail['auth_method'] ?? '') !== 'xoauth2'
            && str_contains(strtolower((string) ($mail['host'] ?? '')), 'outlook.com')) {
            Out::plain();
            Out::warn('注意：Outlook / Hotmail 已不支持基础认证，必须改用 xoauth2。');
        }
        Out::plain();
        Out::info('下一步：php tools/cli.php mail:diagnose');

        Audit::log('smtp', 'config.apply', [
            'actor' => 'cli',
            'detail' => '通过命令行写入邮件配置：' . ($mail['host'] ?? ''),
        ]);
        break;

    // ---------------------------------------------------------------- 查看配置
    case 'mail:show':
        Out::title('当前邮件配置（数据库）');
        $keys = ['mail.host', 'mail.port', 'mail.encryption', 'mail.auth_method',
                 'mail.username', 'mail.password', 'mail.from_address', 'mail.from_name',
                 'mail.reply_to', 'mail.timeout', 'mail.daily_limit', 'mail.code_ttl',
                 'mail.oauth_client_id', 'mail.oauth_tenant', 'mail.oauth_refresh_token'];
        foreach ($keys as $k) {
            $v = Settings::get($k, null);
            if ($v === null || $v === '') {
                Out::plain(sprintf('%-24s %s', $k, "\033[33m(空)\033[0m"));
                continue;
            }
            if (Settings::isSecretKey($k)) {
                $v = '已配置（长度 ' . strlen((string) $v) . '，内容隐藏）';
            }
            Out::plain(sprintf('%-24s %s', $k, $v));
        }
        Out::plain();
        Out::info('今日已发信：' . (int) DB::conn()->val(
            'SELECT COALESCE(count,0) FROM email_quota WHERE bucket = ?',
            ['mail:day:' . date('Ymd')],
            0
        ) . ' 封');
        break;

    // ---------------------------------------------------------------- 测试连接
    case 'mail:test':
        Out::title('测试 SMTP 连接与认证');
        $mailer = new Mailer();
        $result = $mailer->testConnection();

        Out::plain('协议过程：');
        foreach (array_slice($result['log'], 0, 24) as $line) {
            $color = str_starts_with($line, '→') ? "\033[36m" : (str_starts_with($line, '←') ? "\033[90m" : '');
            Out::block($line, $color);
        }
        Out::plain();

        if ($result['ok']) {
            Out::ok('连接与认证全部通过，可以发信了');
            Out::info('下一步：php tools/cli.php mail:send 你的邮箱@example.com');
        } else {
            Out::fail('失败');
            Out::plain();
            Out::block($result['error'], "\033[31m");
        }
        exit($result['ok'] ? 0 : 1);

    // ---------------------------------------------------------------- 发测试信
    case 'mail:send':
        $to = $args[0] ?? '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Out::fail('用法：php tools/cli.php mail:send 收件人邮箱');
            exit(1);
        }
        Out::title('发送测试邮件 → ' . $to);
        $mailer = new Mailer();
        $result = $mailer->sendTest($to);

        if ($result['ok']) {
            Out::ok('已发送');
            Out::plain();
            Out::block('如果收件箱里没有，依次检查：');
            Out::block('  ① 垃圾邮件 / 促销邮件文件夹（第一次收信容易被判垃圾）');
            Out::block('  ② 发件域是否配了 SPF 与 DKIM 记录（没有的话投递率会很低）');
            Out::block('  ③ 服务商后台的发信配额是否用尽');
        } else {
            Out::fail('发送失败');
            Out::plain();
            Out::block($result['error'], "\033[31m");
        }
        Audit::log('smtp', 'cli.send_test', [
            'actor' => 'cli', 'target' => $to,
            'result' => $result['ok'] ? 'ok' : 'fail',
            'detail' => $result['ok'] ? '命令行发送测试邮件成功' : ('失败：' . $result['error']),
        ]);
        exit($result['ok'] ? 0 : 1);

    // ---------------------------------------------------------------- 综合诊断
    case 'mail:diagnose':
        Out::title('邮件功能综合诊断');

        $host = (string) Settings::get('mail.host', '');
        $port = (int) Settings::get('mail.port', 0);
        $enc  = (string) Settings::get('mail.encryption', '');
        $auth = (string) Settings::get('mail.auth_method', 'auto');
        $user = (string) Settings::get('mail.username', '');
        $from = (string) Settings::get('mail.from_address', '');
        $pass = (string) Settings::get('mail.password', '');

        Out::plain("\033[1m配置检查\033[0m");
        $configOk = true;
        foreach ([
            '服务器地址' => [$host !== '', $host ?: '(未配置)'],
            '端口'      => [$port > 0, (string) ($port ?: '(未配置)')],
            '加密方式'  => [in_array($enc, ['ssl', 'tls', 'none'], true), $enc ?: '(未配置)'],
            '认证方式'  => [in_array($auth, ['auto', 'login', 'plain', 'xoauth2'], true), $auth],
            '账号'      => [$user !== '', $user ?: '(未配置)'],
            '发件人地址'=> [filter_var($from, FILTER_VALIDATE_EMAIL) !== false, $from ?: '(未配置)'],
        ] as $label => [$ok, $value]) {
            $ok ? Out::ok(sprintf('%-12s %s', $label, $value))
                : Out::fail(sprintf('%-12s %s', $label, $value));
            $configOk = $configOk && $ok;
        }

        // 认证方式与端口/服务商是否匹配
        Out::plain();
        Out::plain("\033[1m兼容性检查\033[0m");
        $isMs = str_contains(strtolower($host), 'outlook.com')
             || str_contains(strtolower($host), 'hotmail.com')
             || str_contains(strtolower($host), 'office365.com');

        if ($isMs && $auth !== 'xoauth2') {
            Out::fail('这是微软邮箱，但认证方式不是 OAuth2');
            Out::plain();
            Out::block('微软已于 2026 年 4 月彻底关闭 SMTP 基础认证。用账号密码连接会被直接拒绝，报 535 5.7.139。', "\033[31m");
            Out::block('Outlook / Hotmail 的应用专用密码也已随基础认证一起下线。', "\033[31m");
            Out::plain();
            Out::block("\033[1m两个选择：\033[0m");
            Out::block('① 改用 OAuth2（同一邮箱继续用）：');
            Out::block('   把 config/mail.local.php 里的 auth_method 改成 xoauth2，');
            Out::block('   然后跑 php tools/cli.php mail:oauth-help 按步骤获取 refresh_token。');
            Out::block('② 换发信服务（推荐，最省事）：以下都支持基础认证');
            foreach (Mailer::PRESETS as $key => $p) {
                if ($key === 'custom' || $key === 'outlook') {
                    continue;
                }
                Out::block(sprintf('   %-14s %s:%d  %s', $p['name'], $p['host'], $p['port'], strtoupper($p['encryption'])));
            }
        } elseif ($isMs) {
            Out::ok('微软邮箱 + OAuth2，认证方式正确');
            if ((string) Settings::get('mail.oauth_refresh_token', '') === '') {
                Out::fail('但还没有 refresh_token，无法获取访问令牌');
                Out::info('跑 php tools/cli.php mail:oauth-help 看获取步骤');
            } else {
                Out::ok('refresh_token 已配置');
            }
        }

        if (!$isMs && $enc === 'tls' && $port !== 587) {
            Out::warn('STARTTLS 通常配 587 端口，你填的是 ' . $port . '，请确认');
        }
        if (!$isMs && $enc === 'ssl' && $port !== 465) {
            Out::warn('SSL 直连通常配 465 端口，你填的是 ' . $port . '，请确认');
        }
        if (!$isMs && in_array(strtolower($host), ['smtp.qq.com', 'smtp.qiye.163.com', 'smtp.163.com'], true)) {
            Out::warn('QQ / 163 邮箱的「密码」要填**授权码**（在邮箱设置里单独生成），不是登录密码');
        }

        // 发件人域名与账号域名是否一致
        if ($configOk) {
            $userDomain = substr(strrchr($user, '@') ?: '', 1);
            $fromDomain = substr(strrchr($from, '@') ?: '', 1);
            if ($userDomain !== '' && $fromDomain !== '' && $userDomain !== $fromDomain) {
                Out::warn('发件人域名(' . $fromDomain . ') 与账号域名(' . $userDomain . ') 不一致，'
                    . '大多数服务商不允许代发，会被拒信');
            }
        }

        // 实际连通性
        Out::plain();
        Out::plain("\033[1m连通性测试\033[0m");
        if (!$configOk) {
            Out::warn('配置不完整，跳过。先把上面标 ✗ 的项补齐。');
            break;
        }

        $mailer = new Mailer();
        $result = $mailer->testConnection();
        foreach (array_slice($result['log'], 0, 20) as $line) {
            $color = str_starts_with($line, '→') ? "\033[36m" : (str_starts_with($line, '←') ? "\033[90m" : '');
            Out::block($line, $color);
        }
        Out::plain();

        if ($result['ok']) {
            Out::ok('全部通过 —— 邮件功能可以正常使用');
        } else {
            Out::fail('连接或认证失败');
            Out::plain();
            Out::block($result['error'], "\033[31m");
            Out::plain();
            Out::block("\033[1m排查顺序：\033[0m");
            Out::block('① 服务器能否访问外网？很多国内主机默认封了 25 端口，建议用 465/587');
            Out::block('② 服务器地址与端口是否匹配加密方式（465=ssl，587=tls）');
            Out::block('③ 密码是否用的是授权码而不是登录密码');
            Out::block('④ 服务商后台是否开启了 SMTP 服务');
        }

        // 限额
        Out::plain();
        Out::plain("\033[1m发送限额\033[0m");
        $todayUsed = (int) DB::conn()->val(
            'SELECT COALESCE(count,0) FROM email_quota WHERE bucket = ?',
            ['mail:day:' . date('Ymd')],
            0
        );
        $limit = (int) Settings::get('mail.daily_limit', 900);
        Out::plain(sprintf('  今日已用 %d / %d 封', $todayUsed, $limit));
        if ($todayUsed >= $limit) {
            Out::warn('今日额度已用尽，用户将收不到验证码');
        }
        break;

    // ---------------------------------------------------------------- OAuth2 帮助
    case 'mail:oauth-help':
        Out::title('微软邮箱 OAuth2 配置步骤');
        Out::plain('因为微软关闭了 SMTP 基础认证，用 Outlook / Hotmail 发信必须走 OAuth2。');
        Out::plain('完整步骤如下（大约 10 分钟）：');
        Out::plain();
        Out::block("\033[1m第 1 步：注册一个 Azure 应用\033[0m");
        Out::block('打开 https://portal.azure.com → Microsoft Entra ID → 应用注册 → 新注册');
        Out::block('  · 名称：随便填，如 Vobl ID Mailer');
        Out::block('  · 受支持的账户类型：选「任何组织目录中的账户和个人 Microsoft 账户」');
        Out::block('  · 重定向 URI：选「公共客户端/本机」→ 填 http://localhost');
        Out::block('注册后记下「应用程序(客户端) ID」——这就是 client_id');
        Out::plain();
        Out::block("\033[1m第 2 步：添加 API 权限\033[0m");
        Out::block('API 权限 → 添加权限 → Microsoft Graph → 委托的权限');
        Out::block('  勾选 SMTP.Send（还要保留默认的 User.Read、offline_access）');
        Out::block('注意：offline_access 是拿到 refresh_token 的前提，没有它令牌 1 小时就失效。');
        Out::plain();
        Out::block("\033[1m第 3 步：允许公共客户端流\033[0m");
        Out::block('身份验证 → 页面最下方「允许公共客户端流」→ 设为「是」');
        Out::block('不勾这一项，用设备码流程拿令牌会报 unauthorized_client。');
        Out::plain();
        Out::block("\033[1m第 4 步：用设备码流程拿 refresh_token\033[0m");
        Out::block('在浏览器打开下面这个地址（把 CLIENT_ID 换成你的）：');
        Out::plain();
        Out::block('https://login.microsoftonline.com/consumers/oauth2/v2.0/devicecode?client_id=CLIENT_ID&scope=offline_access%20https%3A%2F%2Foutlook.office.com%2FSMTP.Send', "\033[33m");
        Out::plain();
        Out::block('它会返回一个 user_code，然后打开 https://microsoft.com/devicelogin 输入该码并完成登录授权。');
        Out::plain();
        Out::block('授权后，用下面这条命令换令牌（CLIENT_ID 与 DEVICE_CODE 都替换掉）：');
        Out::plain();
        Out::block('curl -X POST https://login.microsoftonline.com/consumers/oauth2/v2.0/token \\', "\033[33m");
        Out::block('  -d "client_id=CLIENT_ID" \\', "\033[33m");
        Out::block('  -d "grant_type=urn:ietf:params:oauth:grant-type:device_code" \\', "\033[33m");
        Out::block('  -d "device_code=DEVICE_CODE"', "\033[33m");
        Out::plain();
        Out::block('返回的 JSON 里有 refresh_token，把它填进 config/mail.local.php。');
        Out::plain();
        Out::block("\033[1m第 5 步：应用配置\033[0m");
        Out::block('在 config/mail.local.php 里填好：');
        Out::block('  auth_method        = "xoauth2"');
        Out::block('  oauth_client_id    = "你的 client_id"');
        Out::block('  oauth_tenant       = "consumers"   （个人账号）/ 租户ID（企业账号）');
        Out::block('  oauth_refresh_token= "上一步拿到的 refresh_token"');
        Out::block('然后跑：php tools/cli.php mail:apply && php tools/cli.php mail:diagnose');
        Out::plain();
        Out::warn('提醒：refresh_token 等同长期凭证，泄露即可发信。config/mail.local.php 已在 .gitignore 中。');
        break;

    /* ============================ 其他命令 ============================ */

    case 'user:list':
        Out::title('用户列表');
        $rows = DB::conn()->all(
            'SELECT id, email, display_name, status, email_verified, totp_enabled, last_login_at, created_at
             FROM users ORDER BY id DESC LIMIT 50'
        );
        if ($rows === []) {
            Out::plain('还没有任何用户');
            break;
        }
        Out::plain(sprintf('%-6s %-32s %-14s %-8s %-6s %s', 'ID', '邮箱', '名称', '状态', '2FA', '最近登录'));
        foreach ($rows as $r) {
            Out::plain(sprintf(
                '%-6d %-32s %-14s %-8s %-6s %s',
                (int) $r['id'],
                mb_substr((string) $r['email'], 0, 32),
                mb_substr((string) ($r['display_name'] ?: '—'), 0, 14),
                (string) $r['status'],
                (int) $r['totp_enabled'] === 1 ? '是' : '否',
                (int) $r['last_login_at'] > 0 ? date('Y-m-d H:i', (int) $r['last_login_at']) : '从未'
            ));
        }
        Out::plain();
        Out::info('共显示 ' . count($rows) . ' 条 / 总计 ' . (int) DB::conn()->count('SELECT COUNT(*) FROM users') . ' 个用户');
        break;

    case 'user:promote':
        $email = $args[0] ?? '';
        if ($email === '') {
            Out::fail('用法：php tools/cli.php user:promote 邮箱');
            exit(1);
        }
        Out::title('设为超级管理员');
        $user = UserService::findByEmail($email);
        if ($user === null) {
            Out::fail('找不到用户：' . $email);
            exit(1);
        }
        $roleId = (int) DB::conn()->val("SELECT id FROM roles WHERE code = 'super_admin' LIMIT 1", [], 0);
        if ($roleId === 0) {
            Out::fail('找不到 super_admin 角色，请先导入 sql/schema.mysql.sql');
            exit(1);
        }
        $exists = DB::conn()->one(
            'SELECT user_id FROM user_roles WHERE user_id = ? AND role_id = ?',
            [(int) $user['id'], $roleId]
        );
        if ($exists !== null) {
            Out::warn($email . ' 已经是超级管理员了');
            break;
        }
        DB::conn()->insert('user_roles', [
            'user_id' => (int) $user['id'], 'role_id' => $roleId,
            'granted_by' => 0, 'granted_at' => time(),
        ]);
        Out::ok($email . ' 已成为超级管理员');
        Audit::log('admin', 'user.promote', [
            'actor' => 'cli', 'actor_id' => 0,
            'target' => 'user:' . $user['id'],
            'detail' => '通过命令行授予超级管理员（紧急恢复用）',
        ]);
        Out::warn('这是应急手段，请在事后检查审计日志确认是本人操作。');
        break;

    case 'order:close-expired':
        Out::title('关闭超时订单');
        $n = Wallet::closeExpired();
        Out::ok('已关闭 ' . $n . ' 笔超时未支付的订单');
        break;

    case 'audit:prune':
        $days = (int) ($args[0] ?? 180);
        Out::title('清理审计日志');
        $n = Audit::prune($days);
        Out::ok('已清理 ' . $n . ' 条超过 ' . $days . ' 天的日志');
        RateLimit::prune();
        Out::ok('同时清理了过期的限流计数');
        Audit::log('admin', 'audit.prune', [
            'actor' => 'cli', 'detail' => '清理 ' . $n . ' 条超过 ' . $days . ' 天的审计日志',
        ]);
        break;

    case 'env:check':
        Out::title('环境自检');
        $checks = [
            ['PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), '需要 7.4+，推荐 8.1+'],
            ['PDO 扩展', extension_loaded('pdo') ? '已加载' : '未加载', extension_loaded('pdo'), '数据库访问必需'],
            ['OpenSSL 扩展', extension_loaded('openssl') ? '已加载' : '未加载', extension_loaded('openssl'), '加密配置与支付宝验签必需'],
            ['mbstring 扩展', extension_loaded('mbstring') ? '已加载' : '未加载', extension_loaded('mbstring'), '中文处理必需'],
            ['cURL 扩展', extension_loaded('curl') ? '已加载' : '未加载', extension_loaded('curl'), '微信支付需要（缺失会退回流式请求）'],
            ['数据库驱动', DB::conn()->driver(), true, ''],
            ['storage 可写', is_writable((string) Config::get('paths.storage')) ? '可写' : '不可写',
             is_writable((string) Config::get('paths.storage')), '日志目录需要可写'],
            ['调试模式', Config::isDebug() ? '已开启' : '已关闭', !Config::isDebug(), '生产环境应为「已关闭」'],
            ['安装入口', Config::get('install.allow_bootstrap_admin', false) ? '仍开启' : '已关闭',
             !Config::get('install.allow_bootstrap_admin', false), '建完管理员后应关闭'],
            ['app_secret', strlen((string) Config::get('keys.app_secret', '')) >= 32 ? '已配置' : '过短或未配置',
             strlen((string) Config::get('keys.app_secret', '')) >= 32, '会话签名密钥，至少 32 位'],
            ['config_key', strlen((string) Config::get('keys.config_key', '')) >= 32 ? '已配置' : '过短或未配置',
             strlen((string) Config::get('keys.config_key', '')) >= 32, '配置加密密钥，至少 32 位'],
        ];
        foreach ($checks as [$label, $value, $ok, $hint]) {
            $ok ? Out::ok(sprintf('%-16s %s', $label, $value))
                : Out::fail(sprintf('%-16s %s', $label, $value));
            if (!$ok && $hint !== '') {
                Out::plain('     └ ' . $hint);
            }
        }
        // 数据库表（驱动无关）
        Out::plain();
        try {
            $tables = DB::conn()->driver() === 'sqlite'
                ? DB::conn()->count("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                : DB::conn()->count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
            Out::plain('数据表数量：' . $tables);
            if ($tables < 15) {
                Out::warn('数据表看起来不完整，请导入 sql/schema.mysql.sql（或本地开发用 php tools/setup-sqlite.php）');
            } else {
                Out::ok('数据表已就绪');
            }
        } catch (\Throwable $e) {
            Out::fail('无法读取数据表：' . $e->getMessage());
            Out::info('请确认已导入 sql/schema.mysql.sql');
        }
        Out::plain();
        Out::plain('超级管理员数量：' . (int) DB::conn()->count(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'super_admin'"
        ));
        break;

    default:
        Out::title('Vobl ID · 命令行工具');
        Out::plain('用法：php tools/cli.php <命令> [参数]');
        Out::plain();
        Out::plain("\033[1m邮件\033[0m");
        Out::block('mail:apply              把 config/mail.local.php 的配置写入数据库');
        Out::block('mail:show               显示当前邮件配置（密钥隐藏）');
        Out::block('mail:test               测试 SMTP 连接与认证');
        Out::block('mail:send <邮箱>        发送一封真实测试邮件');
        Out::block('mail:diagnose           综合诊断（推荐）');
        Out::block('mail:oauth-help         微软邮箱 OAuth2 配置步骤');
        Out::plain();
        Out::plain("\033[1m运维\033[0m");
        Out::block('user:list               列出用户');
        Out::block('user:promote <邮箱>     授予超级管理员（紧急恢复）');
        Out::block('order:close-expired     关闭超时订单');
        Out::block('audit:prune [天数]      清理旧审计日志');
        Out::block('env:check               环境自检');
        Out::plain();
        break;
}
