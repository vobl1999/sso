<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Mailer;
use Vobl\Services\Settings;

/**
 * 后台 · 邮件（SMTP）设置
 * ============================================================================
 * 这是需求里明确要求的「可在后台配置 SMTP 服务器及发件参数」。
 *
 * 关键设计：
 *   · 所有参数存 settings 表，改完立即生效，不需要改配置文件、不需要重新部署
 *   · 密码字段加密存储（AES-256-GCM），界面回显只显示打码占位符
 *   · 提供「测试连接」与「发送测试邮件」两个按钮 ——
 *     这是整个系统里最容易配错的一环，必须让用户能自己验证
 *   · 内置常见服务商预设，选中自动带出 host/port/encryption，省得翻文档
 *
 * ★ 最容易踩的坑（界面上也要提示）：
 *   QQ 邮箱 / 163 / Gmail 需要填「授权码」而不是登录密码。
 *   很多用户配了半天不通，原因就是这里。
 * ============================================================================
 */
final class MailController extends BaseController
{
    public static function index(Request $request): void
    {
        Auth::requireCapability('smtp.view');

        $settings = Settings::group('smtp');

        // 最近发信记录，便于排查「用户说没收到邮件」
        $recentMail = Audit::paginate(['category' => 'smtp', 'keyword' => ''], 1, 15)['rows'];

        self::adminPage('admin/mail', [
            'title'    => '邮件设置',
            'settings' => $settings,
            'presets'  => Mailer::PRESETS,
            'canManage'=> Auth::can('smtp.manage'),
            'canTest'  => Auth::can('smtp.test'),
            'recent'   => $recentMail,
            'todaySent'=> DB::conn()->count(
                'SELECT COUNT(*) FROM email_quota WHERE bucket LIKE ?',
                ['mail:day:' . date('Ymd')]
            ),
            'dailyLimit' => Settings::int('mail.daily_limit', 900),
            'flash'    => self::takenFlash(),
        ]);
    }

    public static function save(Request $request): void
    {
        Auth::requireCapability('smtp.manage');

        $allowed = [
            'mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.password',
            'mail.from_address', 'mail.from_name', 'mail.reply_to', 'mail.timeout',
            'mail.daily_limit', 'mail.code_ttl',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if ($request->str($key, '__MISSING__', 500) === '__MISSING__') {
                continue;
            }
            $value = $request->raw($key, '', 500);
            $data[$key] = trim($value);
        }

        // 端口与数值做范围校验，避免填错导致连不上或敞开滥用
        if (isset($data['mail.port'])) {
            $port = (int) $data['mail.port'];
            if ($port < 1 || $port > 65535) {
                Response::error('端口号必须在 1 - 65535 之间', 422);
            }
            $data['mail.port'] = (string) $port;
        }
        if (isset($data['mail.encryption'])
            && !in_array($data['mail.encryption'], ['ssl', 'tls', 'none'], true)) {
            $data['mail.encryption'] = 'ssl';
        }
        if (isset($data['mail.timeout'])) {
            $data['mail.timeout'] = (string) max(5, min(60, (int) $data['mail.timeout']));
        }
        if (isset($data['mail.daily_limit'])) {
            $data['mail.daily_limit'] = (string) max(1, min(100000, (int) $data['mail.daily_limit']));
        }
        if (isset($data['mail.code_ttl'])) {
            $data['mail.code_ttl'] = (string) max(60, min(3600, (int) $data['mail.code_ttl']));
        }

        // 发件人地址必须是合法邮箱 —— 填错了所有邮件都会被拒
        if (!empty($data['mail.from_address'])
            && !filter_var($data['mail.from_address'], FILTER_VALIDATE_EMAIL)) {
            Response::error('发件人地址格式不正确', 422);
        }
        if (!empty($data['mail.reply_to'])
            && !filter_var($data['mail.reply_to'], FILTER_VALIDATE_EMAIL)) {
            Response::error('回复地址格式不正确', 422);
        }

        Settings::putMany($data, 'smtp');

        // 加密/端口这类关键项变化时提示用户去测试
        self::back('/admin/mail', '邮件配置已保存。建议点一下「测试连接」确认能通。');
    }

    public static function test(Request $request): void
    {
        Auth::requireCapability('smtp.test');

        $mailer = new Mailer();
        $result = $mailer->testConnection();

        Audit::log('smtp', 'test.connect', [
            'actor_id' => Auth::id(),
            'result'   => $result['ok'] ? 'ok' : 'fail',
            'detail'   => $result['ok'] ? 'SMTP 连接与认证测试成功' : ('失败：' . $result['error']),
        ]);

        if (!$result['ok']) {
            Response::error($result['error'], 502);
        }

        // 把协议过程返回给前端展示 —— 出问题时这是最直接的排查依据
        Response::ok([
            'transcript' => array_slice($result['log'], -12),
        ], '连接与认证都通过了，接下来可以发一封测试邮件');
    }

    public static function sendTest(Request $request): void
    {
        Auth::requireCapability('smtp.test');

        $to = $request->email('to');
        if ($to === '') {
            // 没填就用管理员自己的邮箱
            $user = Auth::user();
            $to = (string) $user['email'];
        }
        if ($to === '') {
            Response::error('请填写收件邮箱', 422);
        }

        $mailer = new Mailer();
        $mailer->resetLog();
        $result = $mailer->sendTest($to);

        Audit::log('smtp', 'test.send', [
            'actor_id' => Auth::id(),
            'target'   => $to,
            'result'   => $result['ok'] ? 'ok' : 'fail',
            'detail'   => $result['ok']
                ? '发送测试邮件到 ' . $to . ' 成功'
                : ('发送失败：' . $result['error']),
        ]);

        if (!$result['ok']) {
            Response::error('发送失败：' . $result['error'], 502);
        }

        Response::ok([
            'to'         => $to,
            'transcript' => array_slice($result['log'], -14),
        ], '测试邮件已发送到 ' . $to . '，请查收（没收到的话记得看垃圾箱）');
    }
}
