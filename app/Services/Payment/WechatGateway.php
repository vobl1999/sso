<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

use Vobl\Core\Crypto;

/**
 * 微信支付 · Native 扫码支付（APIv2）
 * ============================================================================
 * 用「统一下单 + 二维码」的流程：我们拿到 code_url，前端生成二维码，
 * 用户微信扫码付款，微信再异步通知我们。
 *
 * ★ 版本说明（很重要，别踩坑）：
 *   本实现走的是 APIv2（MD5 / HMAC-SHA256 对称签名）。
 *   微信官方主推 APIv3（RSA 证书 + AES-GCM 解密回调），但 v2 目前仍在服务，
 *   而且很多第三方支付面板只兼容 v2。
 *
 *   如果你要用 APIv3，需要注意它的回调是**加密**的：
 *   需要用 APIv3 密钥对 resource 做 AES-256-GCM 解密才能拿到订单信息，
 *   本类不覆盖这个流程。届时建议增加一个 WechatV3Gateway 实现，
 *   接口仍然复用 GatewayInterface，后台配置表单会自动切换。
 * ============================================================================
 */
final class WechatGateway implements GatewayInterface
{
    private array $cfg;
    private const UNIFIED_ORDER = 'https://api.mch.weixin.qq.com/pay/unifiedorder';

    public function __construct(array $config = [])
    {
        $this->cfg = array_merge([
            'app_id'  => '',
            'mch_id'  => '',
            'api_key' => '',
            'sign_type' => 'MD5',
        ], $config);
    }

    public function code(): string { return 'wechat'; }
    public function name(): string { return '微信支付 · Native 扫码（APIv2）'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'app_id', 'label' => 'AppID', 'type' => 'text', 'required' => true,
             'hint' => '公众号或开放平台应用的 AppID', 'secret' => false],
            ['key' => 'mch_id', 'label' => '商户号 MchID', 'type' => 'text', 'required' => true,
             'hint' => '微信支付商户平台的商户号', 'secret' => false],
            ['key' => 'api_key', 'label' => 'API 密钥（v2）', 'type' => 'password', 'required' => true,
             'hint' => '商户平台 → 账户中心 → API安全 → APIv2 密钥（32 位）。泄露后可伪造支付通知',
             'secret' => true],
            ['key' => 'sign_type', 'label' => '签名类型', 'type' => 'text', 'required' => false,
             'hint' => 'MD5 或 HMAC-SHA256', 'secret' => false, 'default' => 'MD5'],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->cfg['app_id'] !== '' && $this->cfg['mch_id'] !== '' && $this->cfg['api_key'] !== '';
    }

    // ---------------------------------------------------------------- 发起支付

    public function createPayment(array $order, array $ctx): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => '微信支付通道尚未配置完整',
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        $params = [
            'appid'            => (string) $this->cfg['app_id'],
            'mch_id'           => (string) $this->cfg['mch_id'],
            'nonce_str'        => Crypto::randHex(16),
            'body'             => mb_substr((string) ($order['subject'] ?: '账户充值'), 0, 60),
            'out_trade_no'     => (string) $order['out_trade_no'],
            // ★ 微信的金额单位是「分」，与我们的内部单位一致，不需要换算
            'total_fee'        => (int) $order['amount'],
            'spbill_create_ip' => (string) ($ctx['client_ip'] ?? '127.0.0.1'),
            'notify_url'       => (string) $ctx['notify_url'],
            'trade_type'       => 'NATIVE',
        ];
        $params['sign'] = $this->sign($params);

        $xml = $this->toXml($params);
        $response = $this->post($xml);

        if ($response === '') {
            return ['ok' => false, 'error' => '调用微信统一下单接口失败：无法连接或超时',
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        $result = $this->fromXml($response);
        if (($result['return_code'] ?? '') !== 'SUCCESS') {
            return ['ok' => false,
                    'error' => '微信返回失败：' . (string) ($result['return_msg'] ?? '未知错误'),
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }
        if (($result['result_code'] ?? '') !== 'SUCCESS') {
            return ['ok' => false,
                    'error' => '下单被拒绝：' . (string) ($result['err_code_des'] ?? $result['err_code'] ?? '未知'),
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        // 校验返回值签名（防止被中间人篡改 code_url）
        if (!$this->verifySign($result)) {
            return ['ok' => false, 'error' => '微信返回数据验签失败，已中止（可能存在中间人攻击）',
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        $codeUrl = (string) ($result['code_url'] ?? '');
        if ($codeUrl === '') {
            return ['ok' => false, 'error' => '微信未返回二维码链接',
                    'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        return [
            'ok'    => true,
            'error' => '',
            'mode'  => 'qrcode',
            'url'   => $codeUrl,     // 前端据此生成二维码
            'html'  => '',
            'form'  => [],
        ];
    }

    // ---------------------------------------------------------------- 回调

    public function verifyNotify(array $params): bool
    {
        return $this->verifySign($params);
    }

    public function parseNotify(array $params): array
    {
        $paid = ($params['return_code'] ?? '') === 'SUCCESS'
             && ($params['result_code'] ?? '') === 'SUCCESS';

        return [
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'trade_no'     => (string) ($params['transaction_id'] ?? ''),
            // 单位已经是分
            'amount'       => (int) ($params['total_fee'] ?? 0),
            'paid'         => $paid,
            'raw'          => $params,
        ];
    }

    /** 微信要求返回这段 XML，返回别的它会一直重试 */
    public function notifyAck(): string
    {
        return '<xml><return_code><![CDATA[SUCCESS]]></return_code>'
            . '<return_msg><![CDATA[OK]]></return_msg></xml>';
    }

    // ---------------------------------------------------------------- 签名

    /**
     * 签名：按参数名升序拼 k=v&k=v，末尾接 API 密钥，再 MD5 或 HMAC-SHA256。
     * 排除 sign 与空值 —— 这一步漏了是最常见的「签名错误」原因。
     */
    public function sign(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === '' || $v === null || is_array($v)) {
                continue;
            }
            $filtered[(string) $k] = (string) $v;
        }
        ksort($filtered, SORT_STRING);

        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $content = implode('&', $pairs) . '&key=' . (string) $this->cfg['api_key'];

        return strtoupper((string) $this->cfg['sign_type']) === 'HMAC-SHA256'
            ? strtoupper(hash_hmac('sha256', $content, (string) $this->cfg['api_key']))
            : strtoupper(md5($content));
    }

    private function verifySign(array $params): bool
    {
        $sign = strtoupper((string) ($params['sign'] ?? ''));
        if ($sign === '') {
            return false;
        }
        // 微信签名是大写十六进制，统一大写后做定长比较
        return Crypto::equals(strtoupper($this->sign($params)), $sign);
    }

    // ---------------------------------------------------------------- XML

    private function toXml(array $params): string
    {
        $xml = '<xml>';
        foreach ($params as $k => $v) {
            $xml .= '<' . $k . '>' . $this->cdata((string) $v) . '</' . $k . '>';
        }
        return $xml . '</xml>';
    }

    private function cdata(string $value): string
    {
        // 微信要求全部用 CDATA 包裹
        return '<![CDATA[' . str_replace(']]>', ']]&gt;', $value) . ']]>';
    }

    private function fromXml(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }
        // LIBXML_NONET 禁止访问网络实体；PHP 8 起外部实体默认就是关闭的，
        // 不要再调用已废弃的 libxml_disable_entity_loader()，否则会出弃用警告
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    private function post(string $xml): string
    {
        if (!function_exists('curl_init')) {
            // 退回流式请求，兼容极少数没装 curl 的环境
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: text/xml\r\n",
                    'content' => $xml,
                    'timeout' => 15,
                ],
            ]);
            $res = @file_get_contents(self::UNIFIED_ORDER, false, $ctx);
            return $res === false ? '' : $res;
        }

        $ch = curl_init(self::UNIFIED_ORDER);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml'],
        ]);
        $res = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            error_log('[wechat] 统一下单请求失败：' . $error);
            return '';
        }
        return (string) $res;
    }
}
