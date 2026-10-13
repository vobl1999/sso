<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

use Vobl\Core\Crypto;

/**
 * 支付宝 · 电脑网站支付（alipay.trade.page.pay）
 * ============================================================================
 * 使用 RSA2（SHA256withRSA）签名，需要：
 *   应用私钥（自己生成，用来签名请求）
 *   支付宝公钥（从开放平台下载，用来验证回调）
 *
 * 与易支付的关键差异：
 *   易支付用「商户密钥 + MD5」做对称签名，密钥双方共享；
 *   支付宝用「非对称密钥」——我们用自己的私钥签名、用支付宝的公钥验签，
 *   私钥永远不外传。安全性更高，但配置步骤也更多。
 * ============================================================================
 */
final class AlipayGateway implements GatewayInterface
{
    private array $cfg;
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function __construct(array $config = [])
    {
        $this->cfg = array_merge([
            'app_id'          => '',
            'private_key'     => '',
            'alipay_public_key' => '',
            'gateway'         => self::GATEWAY,
            'product_code'    => 'FAST_INSTANT_TRADE_PAY',
        ], $config);
    }

    public function code(): string { return 'alipay'; }
    public function name(): string { return '支付宝 · 电脑网站支付'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'app_id', 'label' => 'APPID', 'type' => 'text', 'required' => true,
             'hint' => '开放平台应用详情页里的 APPID', 'secret' => false],
            ['key' => 'private_key', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true,
             'hint' => 'PEM 格式，含 BEGIN/END 行。用工具生成后只粘贴私钥，公钥上传到开放平台', 'secret' => true],
            ['key' => 'alipay_public_key', 'label' => '支付宝公钥', 'type' => 'textarea', 'required' => true,
             'hint' => '★ 注意是「支付宝公钥」不是「应用公钥」。在开放平台的接口加签方式页复制', 'secret' => true],
            ['key' => 'gateway', 'label' => '网关地址', 'type' => 'url', 'required' => false,
             'hint' => '默认生产环境。沙箱调试时改为 https://openapi.alipaydev.com/gateway.do',
             'secret' => false, 'default' => self::GATEWAY],
            ['key' => 'product_code', 'label' => '产品码', 'type' => 'text', 'required' => false,
             'hint' => '网页支付固定 FAST_INSTANT_TRADE_PAY', 'secret' => false,
             'default' => 'FAST_INSTANT_TRADE_PAY'],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->cfg['app_id'] !== ''
            && $this->cfg['private_key'] !== ''
            && $this->cfg['alipay_public_key'] !== '';
    }

    // ---------------------------------------------------------------- 发起支付

    public function createPayment(array $order, array $ctx): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => '支付宝通道尚未配置完整', 'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        $biz = [
            'out_trade_no' => (string) $order['out_trade_no'],
            // 支付宝金额单位是「元」，两位小数
            'total_amount' => number_format(((int) $order['amount']) / 100, 2, '.', ''),
            'subject'      => mb_substr((string) ($order['subject'] ?: '账户充值'), 0, 120),
            'product_code' => (string) $this->cfg['product_code'],
        ];

        $params = [
            'app_id'      => (string) $this->cfg['app_id'],
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => (string) $ctx['notify_url'],
            'return_url'  => (string) $ctx['return_url'],
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
        ];

        $params['sign'] = $this->sign($params);
        if ($params['sign'] === '') {
            return ['ok' => false, 'error' => '签名失败：应用私钥格式不正确（需要 PEM 格式）',
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        // 电脑网站支付允许 GET 跳转，参数直接拼在 URL 上
        return [
            'ok'    => true,
            'error' => '',
            'mode'  => 'redirect',
            'url'   => (string) $this->cfg['gateway'] . '?' . http_build_query($params),
            'html'  => '',
            'form'  => $params,
        ];
    }

    // ---------------------------------------------------------------- 回调

    public function verifyNotify(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        // ★ 验签用的内容必须排除 sign 与 sign_type，其余按参数名升序拼 k=v。
        //   注意：值要用「原始值」，不要 urlencode —— 支付宝的规则是
        //   「待签名串不编码，签名结果再编码」
        $filtered = [];
        foreach ($params as $k => $v) {
            if (in_array($k, ['sign', 'sign_type'], true)) {
                continue;
            }
            if (is_array($v) || $v === '') {
                continue;
            }
            $filtered[(string) $k] = (string) $v;
        }
        ksort($filtered, SORT_STRING);

        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $content = implode('&', $pairs);

        $publicKey = $this->normalizeKey((string) $this->cfg['alipay_public_key'], 'PUBLIC KEY');
        $ok = @openssl_verify($content, base64_decode($sign, true) ?: '', $publicKey, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    public function parseNotify(array $params): array
    {
        // 交易状态：TRADE_SUCCESS / TRADE_FINISHED 都算支付成功
        $status = (string) ($params['trade_status'] ?? '');
        $paid = in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);

        $cents = 0;
        $amount = (string) ($params['total_amount'] ?? '0');
        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1) {
            $parts = explode('.', $amount, 2);
            $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');
        }

        return [
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'trade_no'     => (string) ($params['trade_no'] ?? ''),
            'amount'       => $cents,
            'paid'         => $paid,
            'raw'          => $params,
        ];
    }

    public function notifyAck(): string
    {
        return 'success';
    }

    // ---------------------------------------------------------------- 签名

    /** 用应用私钥签名（RSA2 = SHA256withRSA） */
    private function sign(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if (in_array($k, ['sign', 'sign_type'], true)) {
                continue;
            }
            if (is_array($v) || $v === '') {
                continue;
            }
            $filtered[(string) $k] = (string) $v;
        }
        ksort($filtered, SORT_STRING);

        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $content = implode('&', $pairs);

        $privateKey = $this->normalizeKey((string) $this->cfg['private_key'], 'PRIVATE KEY');
        $signature = '';
        $ok = @openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($ok !== true) {
            return '';
        }
        return base64_encode($signature);
    }

    /**
     * 把用户粘贴的密钥规整成 PEM。
     * 用户从开放平台复制的通常是一整串没有换行的 base64，
     * 直接丢给 openssl 会报错，这里自动补齐头尾并每 64 字符换行。
     */
    private function normalizeKey(string $key, string $label): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);
        $wrapped = chunk_split((string) $body, 64, "\n");
        return "-----BEGIN {$label}-----\n" . $wrapped . "-----END {$label}-----\n";
    }
}
