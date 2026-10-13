<?php
declare(strict_types=1);

namespace Vobl\Services;

use Vobl\Core\Config;

/**
 * SMTP 发信客户端（纯 socket 实现，零依赖）
 * ============================================================================
 * 为什么不用 PHPMailer：
 *   宝塔环境里很多站点没装 composer，且用户明确要求「可上传 GitHub 即用」。
 *   自己实现 200 行即可覆盖 465/587/25 三种端口 + SSL/STARTTLS + 两种认证，
 *   省掉一个必须执行的依赖安装步骤 —— 这一步恰恰最容易卡住非技术用户。
 *
 * 支持：
 *   · 465 SSL 直连 / 587 STARTTLS / 25 明文（不推荐）
 *   · AUTH LOGIN 与 AUTH PLAIN，自动协商
 *   · HTML + 纯文本 双版本正文（multipart/alternative）
 *   · UTF-8 主题与发件人名的 MIME 编码
 *   · 中文域名与邮箱地址不丢字符
 * ============================================================================
 */
final class Mailer
{
    private array $cfg;
    private $socket = null;
    private array $log = [];
    private int $timeout;

    /** 常见服务商的默认参数，后台只需选服务商就能带出（省得用户去翻文档） */
    public const PRESETS = [
        'tencent'  => ['name' => '腾讯企业邮',   'host' => 'smtp.exmail.qq.com',  'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'qq'       => ['name' => 'QQ 邮箱',      'host' => 'smtp.qq.com',         'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'aliyun'   => ['name' => '阿里云邮件推送', 'host' => 'smtpdm.aliyun.com',   'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'netease'  => ['name' => '网易企业邮',   'host' => 'smtp.qiye.163.com',   'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'resend'   => ['name' => 'Resend',       'host' => 'smtp.resend.com',     'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'brevo'    => ['name' => 'Brevo',        'host' => 'smtp-relay.brevo.com','port' => 587, 'encryption' => 'tls',    'auth' => 'login'],
        'sendgrid' => ['name' => 'SendGrid',     'host' => 'smtp.sendgrid.net',   'port' => 465, 'encryption' => 'ssl',    'auth' => 'login'],
        'gmail'    => ['name' => 'Gmail',        'host' => 'smtp.gmail.com',      'port' => 587, 'encryption' => 'tls',    'auth' => 'login'],
        // ★ Outlook / Hotmail 必须用 OAuth2：微软已于 2026 年 4 月彻底关闭 SMTP 基础认证
        'outlook'  => ['name' => 'Outlook / Hotmail（需 OAuth2）', 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'tls', 'auth' => 'xoauth2'],
        'custom'   => ['name' => '自定义',        'host' => '',                   'port' => 465, 'encryption' => 'ssl',    'auth' => 'auto'],
    ];

    public function __construct(array $config = null)
    {
        $this->cfg = $config ?? [
            'host'       => (string) Settings::get('mail.host', ''),
            'port'       => Settings::int('mail.port', 465),
            'encryption' => (string) Settings::get('mail.encryption', 'ssl'),
            'auth_method'=> (string) Settings::get('mail.auth_method', 'auto'),
            'username'   => (string) Settings::get('mail.username', ''),
            'password'   => (string) Settings::get('mail.password', ''),
            'from_address' => (string) Settings::get('mail.from_address', ''),
            'from_name'  => (string) Settings::get('mail.from_name', 'Vobl ID'),
            'reply_to'   => (string) Settings::get('mail.reply_to', ''),
        ];
        $this->timeout = max(5, min(60, (int) Settings::get('mail.timeout', 15)));
    }

    // ================================================================ 对外接口

    /**
     * 发送邮件。
     * @return array{ok:bool, error:string, log:array}
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        try {
            $this->validate();
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->deliver($to, $subject, $htmlBody, $textBody);
            $this->quit();
            return ['ok' => true, 'error' => '', 'log' => $this->log];
        } catch (\Throwable $e) {
            $this->log[] = 'ERROR: ' . $e->getMessage();
            $this->close();
            return ['ok' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        }
    }

    /** 连通性测试：只连不发，后台「测试连接」按钮用 */
    public function testConnection(): array
    {
        try {
            $this->validate();
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->quit();
            return ['ok' => true, 'error' => '', 'log' => $this->log];
        } catch (\Throwable $e) {
            $this->log[] = 'ERROR: ' . $e->getMessage();
            $this->close();
            return ['ok' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        }
    }

    /** 发一封真实测试邮件 */
    public function sendTest(string $to): array
    {
        $siteName = (string) Settings::get('site.name', 'Vobl ID');
        $html = $this->template('SMTP 配置成功', [
            '这是一封测试邮件，说明你的发信配置已经可以正常工作。',
            '如果你收到的是这封邮件，说明验证码、找回密码等功能都能正常发信了。',
        ], ['发信服务器' => $this->cfg['host'] . ':' . $this->cfg['port']]);
        return $this->send($to, $siteName . ' · SMTP 配置测试', $html);
    }

    public function transcript(): array
    {
        return $this->log;
    }

    // ================================================================ 前置校验

    private function validate(): void
    {
        foreach (['host' => '服务器地址', 'username' => '账号', 'password' => '密码'] as $k => $label) {
            if (trim((string) ($this->cfg[$k] ?? '')) === '') {
                throw new \RuntimeException("SMTP {$label}未配置，请到后台「邮件设置」填写");
            }
        }
        if (empty($this->cfg['from_address'])) {
            throw new \RuntimeException('发件人地址未配置，请到后台「邮件设置」填写');
        }
        if (!filter_var($this->cfg['from_address'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('发件人地址格式不正确：' . $this->cfg['from_address']);
        }
        if (!filter_var($this->cfg['host'], FILTER_VALIDATE_IP)
            && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', (string) $this->cfg['host'])) {
            throw new \RuntimeException('SMTP 服务器地址格式不正确');
        }
    }

    // ================================================================ 连接

    private function connect(): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $enc  = strtolower((string) ($this->cfg['encryption'] ?? 'ssl'));

        // SSL 直连（465）与 STARTTLS（587）的区别就在这里：
        // 前者一建立连接就是加密的，后者先明文再升级
        $transport = $enc === 'ssl' ? 'ssl://' : '';
        $target = $transport . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                '无法连接到 %s（%s）。请检查：① 服务器地址与端口是否正确 ② 服务器能否访问外网（很多国内主机默认封了 25 端口，建议用 465）③ 防火墙是否放行。原始错误：%s',
                $target, $enc, $errstr ?: ('errno ' . $errno)
            ));
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->expect(220, '连接建立');
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    // ================================================================ 协议

    private function handshake(): void
    {
        $host = (string) Config::get('app.url', 'localhost');
        $host = preg_replace('#^https?://#', '', $host) ?: 'localhost';
        $host = explode('/', $host)[0];

        $this->command('EHLO ' . $host, 250, 'EHLO');

        $enc = strtolower((string) ($this->cfg['encryption'] ?? 'ssl'));
        if ($enc === 'tls') {
            $this->command('STARTTLS', 220, 'STARTTLS');
            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)
            );
            if ($ok !== true) {
                throw new \RuntimeException('STARTTLS 加密握手失败。很多服务商在 587 端口才支持 STARTTLS，请确认端口与加密方式是否匹配。');
            }
            // 升级加密后必须重新 EHLO
            $this->command('EHLO ' . $host, 250, 'EHLO (加密后)');
        }
    }

    private function authenticate(): void
    {
        $user = (string) $this->cfg['username'];
        $pass = (string) $this->cfg['password'];
        $method = strtolower((string) ($this->cfg['auth_method'] ?? 'auto'));

        // ---------------------------------------------------------------- XOAUTH2
        // 微软已于 2026 年 4 月彻底关闭 SMTP 基础认证，Outlook / Hotmail
        // 只能用 OAuth2。这里走 AUTH XOAUTH2，凭据格式固定为
        //   base64("user=" . $user . "\x01auth=Bearer " . $token . "\x01\x01")
        if ($method === 'xoauth2') {
            $token = trim((string) ($this->cfg['oauth_token'] ?? ''));
            if ($token === '') {
                throw new \RuntimeException(
                    'OAuth2 访问令牌为空。请先在后台「邮件设置」里完成微软账号授权（点「连接微软账号」），'
                    . '系统会保存刷新令牌并自动续期。'
                );
            }
            $sasl = base64_encode("user={$user}\x01auth=Bearer {$token}\x01\x01");
            $resp = $this->command('AUTH XOAUTH2 ' . $sasl, null, 'AUTH XOAUTH2', true);

            if ($resp['code'] !== 235) {
                // XOAUTH2 失败时服务器会回一段 base64 的 JSON 说明，解出来更有用
                $detail = '';
                $decoded = base64_decode($resp['text'], true);
                if ($decoded !== false && $decoded !== '') {
                    $json = json_decode($decoded, true);
                    $detail = is_array($json) ? (string) ($json['error_description'] ?? $json['error'] ?? '') : '';
                }
                throw new \RuntimeException(sprintf(
                    'OAuth2 认证失败（%d %s）。%s常见原因：令牌已过期、该账号未授权 SMTP.Send 权限、'
                    . '或应用注册里没有勾选「允许公共客户端流」。',
                    $resp['code'],
                    $resp['text'],
                    $detail !== '' ? '服务器说明：' . $detail . '。' : ''
                ));
            }
            return;
        }

        // ---------------------------------------------------------------- AUTH PLAIN
        // 一次把用户名密码送过去，最省往返。auto 模式优先试它
        if ($method === 'auto' || $method === 'plain') {
            $plain = base64_encode("\0" . $user . "\0" . $pass);
            $resp = $this->command('AUTH PLAIN ' . $plain, null, 'AUTH PLAIN', true);
            if ($resp['code'] === 235) {
                return;
            }
            if ($method === 'plain') {
                $this->throwAuthError($resp, 'AUTH PLAIN');
            }
            // auto 模式下 AUTH PLAIN 不被支持是正常的，继续试 AUTH LOGIN
        }

        // ---------------------------------------------------------------- AUTH LOGIN
        $resp2 = $this->command('AUTH LOGIN', 334, 'AUTH LOGIN');
        $this->command(base64_encode($user), 334, '用户名');
        $resp3 = $this->command(base64_encode($pass), null, '密码', true);

        if ($resp3['code'] !== 235) {
            $this->throwAuthError($resp3, 'AUTH LOGIN');
        }
    }

    /**
     * 认证失败时给出可操作的诊断，而不是把 SMTP 的原始错误直接丢给用户。
     * 这一步很值得做：邮件认证失败的原因高度集中，把常见原因列清楚能省掉大量排查。
     */
    private function throwAuthError(array $resp, string $stage): void
    {
        $raw = $resp['code'] . ' ' . $resp['text'];
        $host = strtolower((string) $this->cfg['host']);
        $isMicrosoft = str_contains($host, 'outlook.com')
            || str_contains($host, 'hotmail.com')
            || str_contains($host, 'office365.com')
            || str_contains($host, 'microsoft.com');

        // 微软关闭基础认证后的典型报错
        if (str_contains($resp['text'], '5.7.139')
            || stripos($resp['text'], 'basic authentication is disabled') !== false
            || stripos($resp['text'], 'SmtpClientAuthentication') !== false) {
            throw new \RuntimeException(
                '【需要 OAuth2】' . $raw . "\n"
                . "微软已于 2026 年 4 月彻底关闭 SMTP 基础认证，用「账号 + 密码（含应用专用密码）」"
                . "连接 Outlook / Hotmail / Microsoft 365 会被直接拒绝。\n"
                . "解决办法二选一：\n"
                . "  ① 换一个支持基础认证的发信服务（推荐，配置最简单）：腾讯企业邮、阿里云邮件推送、"
                . "网易企业邮、Resend、Brevo、SendGrid 都可以；\n"
                . "  ② 继续用微软账号：在后台把认证方式改为 OAuth2 并完成微软账号授权。"
            );
        }

        if ($resp['code'] === 535) {
            $hint = $isMicrosoft
                ? '微软账号请确认已改用 OAuth2 认证方式。'
                : '请确认：① 密码填的是「授权码」而不是登录密码（QQ 邮箱、163、Gmail 都需要授权码）；'
                  . '② 账号是否已在服务商后台开启 SMTP 服务。';
            throw new \RuntimeException("认证失败（{$raw}，阶段：{$stage}）。{$hint}");
        }

        throw new \RuntimeException("认证失败（{$raw}，阶段：{$stage}）。请检查账号与密码是否正确。");
    }

    private function deliver(string $to, string $subject, string $html, string $text): void
    {
        $from = (string) $this->cfg['from_address'];

        $this->command('MAIL FROM:<' . $from . '>', 250, 'MAIL FROM');
        $this->command('RCPT TO:<' . $to . '>', null, 'RCPT TO', true);

        $this->command('DATA', 354, 'DATA');

        $data = $this->buildMessage($to, $subject, $html, $text);
        $this->write($data . "\r\n.");

        $resp = $this->readResponse();
        $this->log[] = '← ' . $resp['code'] . ' ' . $resp['text'];
        if ($resp['code'] !== 250) {
            throw new \RuntimeException('邮件内容被服务器拒绝：' . $resp['text']);
        }
    }

    private function quit(): void
    {
        try {
            $this->command('QUIT', 221, 'QUIT');
        } catch (\Throwable $e) {
            // 退出失败无关紧要
        }
        $this->close();
    }

    // ================================================================ 报文构造

    private function buildMessage(string $to, string $subject, string $html, string $text): string
    {
        if ($text === '') {
            $text = $this->htmlToText($html);
        }

        $fromAddr = (string) $this->cfg['from_address'];
        $fromName = (string) ($this->cfg['from_name'] ?? '');
        $replyTo  = (string) ($this->cfg['reply_to'] ?? '');

        $boundary = '=_vobl_' . bin2hex(random_bytes(12));

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->encodeAddress($fromAddr, $fromName);
        $headers[] = 'To: <' . $to . '>';
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->mailDomain($fromAddr) . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Vobl-ID/1.0';
        // 重要：告诉收件方我们只发事务性邮件，降低被判垃圾的概率
        $headers[] = 'Auto-Submitted: auto-generated';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = [];
        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/plain; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = chunk_split(base64_encode($text), 76, "\r\n");

        $body[] = '--' . $boundary;
        $body[] = 'Content-Type: text/html; charset=UTF-8';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = '';
        $body[] = chunk_split(base64_encode($html), 76, "\r\n");

        $body[] = '--' . $boundary . '--';

        $message = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);

        // ★ 点填充：正文中以 . 开头的行必须变成 .. 否则会被当成 DATA 结束符
        $message = preg_replace('/^\./m', '..', $message);

        // 统一 CRLF（SMTP 协议要求）
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);

        return $message;
    }

    /** 主题 / 发件人名用 RFC 2047 编码，否则中文会变乱码 */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeAddress(string $address, string $name): string
    {
        if ($name === '') {
            return '<' . $address . '>';
        }
        return $this->encodeHeader($name) . ' <' . $address . '>';
    }

    private function mailDomain(string $address): string
    {
        $parts = explode('@', $address);
        return $parts[1] ?? 'localhost';
    }

    /** 粗糙但够用的 HTML → 纯文本，用于 multipart 的纯文本分支 */
    private function htmlToText(string $html): string
    {
        $t = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $t = preg_replace('#<br\s*/?>#i', "\n", $t) ?? $t;
        $t = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    // ================================================================ 底层 IO

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP 连接已断开');
        }
        $full = $data . "\r\n";
        $written = @fwrite($this->socket, $full);
        if ($written === false || $written < strlen($full)) {
            throw new \RuntimeException('向 SMTP 服务器写入数据失败（连接可能已中断）');
        }
        $this->log[] = '→ ' . (strlen($data) > 120 ? substr($data, 0, 120) . '…' : $data);
    }

    private function readResponse(): array
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP 连接已断开');
        }
        $lines = [];
        $code = 0;
        $text = '';
        $started = time();

        while (true) {
            if (time() - $started > $this->timeout) {
                throw new \RuntimeException('等待 SMTP 服务器响应超时（' . $this->timeout . ' 秒）');
            }
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('读取 SMTP 响应超时');
                }
                throw new \RuntimeException('SMTP 服务器关闭了连接');
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m) === 1) {
                $code = (int) $m[1];
                $text = $m[3];
                // 连字符表示还有后续行
                if ($m[2] === ' ') {
                    break;
                }
            }
        }
        return ['code' => $code, 'text' => $text, 'lines' => $lines];
    }

    /**
     * 发送命令并校验响应码。
     * $expect 为 null 时表示「只要不是 4xx/5xx 就算通过」（多行响应场景）。
     */
    private function command(string $cmd, ?int $expect, string $label, bool $acceptAnyOk = false): array
    {
        $this->write($cmd);
        $resp = $this->readResponse();
        $this->log[] = '← ' . $resp['code'] . ' ' . $resp['text'];

        if ($expect !== null && $resp['code'] !== $expect) {
            throw new \RuntimeException(sprintf('%s 失败：%d %s', $label, $resp['code'], $resp['text']));
        }
        if ($expect === null && $acceptAnyOk && $resp['code'] >= 400) {
            // 交给调用方处理（AUTH PLAIN 不被支持是正常的）
            return $resp;
        }
        if ($expect === null && $resp['code'] >= 500) {
            throw new \RuntimeException(sprintf('%s 失败：%d %s', $label, $resp['code'], $resp['text']));
        }
        return $resp;
    }

    /** 已按协议要求调用 expect() */
    private function expect(int $code, string $label): void
    {
        $resp = $this->readResponse();
        $this->log[] = '← ' . $resp['code'] . ' ' . $resp['text'];
        if ($resp['code'] !== $code) {
            throw new \RuntimeException(sprintf('%s 失败：%d %s', $label, $resp['code'], $resp['text']));
        }
    }

    /** 清空协议日志（后台展示时只要最后一次的） */
    public function resetLog(): void
    {
        $this->log = [];
    }

    // ================================================================ 邮件模板

    /**
     * 统一邮件外壳。逻辑与 UI 里的「验证邮件」设计稿保持一致：
     * 纯文本 + 一个按钮，不塞营销内容 —— 越简单越不容易进垃圾箱。
     */
    public function template(string $title, array $paragraphs, array $info = [], ?array $cta = null): string
    {
        $siteName = (string) Settings::get('site.name', 'Vobl ID');
        $siteUrl  = (string) Settings::get('site.url', '');

        ob_start();
        ?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f7f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="max-width:520px;background:#ffffff;border:1px solid #e8eaee;border-radius:14px;overflow:hidden;">
    <tr><td style="padding:28px 32px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="width:34px;height:34px;background:#ee7318;border-radius:10px;text-align:center;
                   vertical-align:middle;font:700 17px/34px -apple-system,Segoe UI,sans-serif;color:#401a00;">V</td>
        <td style="padding-left:10px;font:600 15px/34px -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710;">
          <?= e($siteName) ?></td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:20px 32px 0;">
      <h1 style="margin:0 0 14px;font:600 19px/1.4 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710;">
        <?= e($title) ?></h1>
      <?php foreach ($paragraphs as $p): ?>
        <p style="margin:0 0 12px;font:400 14.5px/1.75 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#4b4137;">
          <?= e($p) ?></p>
      <?php endforeach; ?>
    </td></tr>

    <?php if ($cta !== null): ?>
    <tr><td style="padding:8px 32px 0;" align="left">
      <a href="<?= e($cta['url']) ?>"
         style="display:inline-block;padding:13px 26px;background:#ee7318;color:#401a00;text-decoration:none;
                border-radius:11px;font:600 14.5px/1 -apple-system,Segoe UI,PingFang SC,sans-serif;">
        <?= e($cta['label']) ?></a>
      <p style="margin:12px 0 0;font:400 12.5px/1.7 -apple-system,Segoe UI,sans-serif;color:#94837a;">
        按钮点不开？把下面的地址复制到浏览器：<br>
        <span style="word-break:break-all;color:#b34e08;"><?= e($cta['url']) ?></span></p>
    </td></tr>
    <?php endif; ?>

    <?php if ($info !== []): ?>
    <tr><td style="padding:20px 32px 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="background:#faf7f4;border-radius:11px;">
        <?php foreach ($info as $k => $v): ?>
        <tr>
          <td style="padding:9px 14px;font:400 13px/1.6 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#94837a;white-space:nowrap;">
            <?= e($k) ?></td>
          <td style="padding:9px 14px;font:400 13px/1.6 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710;">
            <?= e($v) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </td></tr>
    <?php endif; ?>

    <tr><td style="padding:24px 32px 28px;">
      <hr style="border:0;border-top:1px solid #e8eaee;margin:0 0 14px;">
      <p style="margin:0;font:400 12px/1.7 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#94837a;">
        <?= e($siteName) ?> · 统一身份中心<br>
        <?php if ($siteUrl !== ''): ?><?= e($siteUrl) ?><br><?php endif; ?>
        本邮件由系统自动发出，请勿直接回复。
      </p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /** 验证码邮件（最常用） */
    public function codeTemplate(string $code, string $purpose, int $ttlMinutes): string
    {
        $colors = [
            'verify_email'   => ['验证你的邮箱', '欢迎注册'],
            'reset_password' => ['重置登录密码', '你正在重置密码'],
            'login_code'     => ['登录验证码', '你正在免密登录'],
            'change_email'   => ['确认新邮箱', '你正在更换邮箱'],
            'bind_confirm'   => ['绑定确认', '你正在绑定新的登录方式'],
        ];
        [$title, $prefix] = $colors[$purpose] ?? ['邮箱验证码', '你正在执行敏感操作'];

        $siteName = (string) Settings::get('site.name', 'Vobl ID');
        $digits = preg_replace('/(.)/', '$1 ', $code);
        $digits = trim((string) $digits);

        ob_start();
        ?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f6f7f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="max-width:520px;background:#ffffff;border:1px solid #e8eaee;border-radius:14px;">
    <tr><td style="padding:28px 32px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="width:34px;height:34px;background:#ee7318;border-radius:10px;text-align:center;
                   vertical-align:middle;font:700 17px/34px -apple-system,Segoe UI,sans-serif;color:#401a00;">V</td>
        <td style="padding-left:10px;font:600 15px/34px -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710;">
          <?= e($siteName) ?></td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:22px 32px 0;">
      <h1 style="margin:0 0 10px;font:600 20px/1.4 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710;">
        <?= e($title) ?></h1>
      <p style="margin:0;font:400 14.5px/1.75 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#4b4137;">
        <?= e($prefix) ?>，请在页面上输入下面的验证码完成确认：</p>
    </td></tr>

    <tr><td style="padding:22px 32px;" align="center">
      <div style="background:#fdf1e5;border-radius:12px;padding:20px 16px;">
        <span style="font:700 34px/1 ui-monospace,SFMono-Regular,Consolas,monospace;
                     letter-spacing:6px;color:#b34e08;"><?= e($code) ?></span>
      </div>
      <p style="margin:14px 0 0;font:400 12.5px/1.7 -apple-system,Segoe UI,sans-serif;color:#94837a;">
        验证码 <?= (int) $ttlMinutes ?> 分钟内有效，仅可使用一次。<br>
        如果这不是你本人的操作，忽略这封邮件即可，你的账号不会有任何变化。</p>
    </td></tr>

    <tr><td style="padding:0 32px 28px;">
      <hr style="border:0;border-top:1px solid #e8eaee;margin:0 0 14px;">
      <p style="margin:0;font:400 12px/1.7 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#94837a;">
        <?= e($siteName) ?> · 统一身份中心<br>本邮件由系统自动发出，请勿直接回复。</p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
