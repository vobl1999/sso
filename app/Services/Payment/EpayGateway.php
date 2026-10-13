<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

use Vobl\Core\Crypto;

/**
 * 易支付 / 彩虹易支付 通用协议
 * ============================================================================
 * 国内最常见的一类「聚合支付」接口，一个账号就能收支付宝、微信、QQ 钱包。
 * 很多第三方支付服务商（以及自建的支付面板）都兼容这套协议。
 *
 * 协议要点：
 *   签名 = MD5(按 key 升序拼接的 k=v&k=v… + 商户密钥)
 *   拼接时必须：① 排除 sign / sign_type ② 排除空值 ③ 按参数名 ASCII 升序
 *   回调成功要返回纯文本 success，否则对方会不断重试
 * ============================================================================
 */
final class EpayGateway implements GatewayInterface
{
    private array $cfg;

    public function __construct(array $config = [])
    {
        $this->cfg = array_merge([
            'api_url'    => '',
            'pid'        => '',
            'key'        => '',
            'pay_type_alipay' => 'alipay',
            'pay_type_wechat' => 'wxpay',
        ], $config);
    }

    public function code(): string { return 'epay'; }
    public function name(): string { return '易支付（聚合通道）'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'api_url', 'label' => '接口地址', 'type' => 'url', 'required' => true,
             'hint' => '服务商提供的提交地址，形如 https://pay.example.com/submit.php', 'secret' => false],
            ['key' => 'pid', 'label' => '商户 ID（PID）', 'type' => 'text', 'required' => true,
             'hint' => '在支付服务商后台获取', 'secret' => false],
            ['key' => 'key', 'label' => '商户密钥', 'type' => 'password', 'required' => true,
             'hint' => '用于签名校验，泄露后他人可伪造支付通知，务必保密', 'secret' => true],
            ['key' => 'pay_type_alipay', 'label' => '支付宝通道代码', 'type' => 'text', 'required' => false,
             'hint' => '默认 alipay，部分服务商为 alipay_scan', 'secret' => false, 'default' => 'alipay'],
            ['key' => 'pay_type_wechat', 'label' => '微信通道代码', 'type' => 'text', 'required' => false,
             'hint' => '默认 wxpay，部分服务商为 wechat', 'secret' => false, 'default' => 'wxpay'],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->cfg['api_url'] !== '' && $this->cfg['pid'] !== '' && $this->cfg['key'] !== '';
    }

    // ---------------------------------------------------------------- 发起支付

    public function createPayment(array $order, array $ctx): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => '易支付通道尚未配置完整', 'mode' => '', 'url' => '', 'html' => '', 'form' => []];
        }

        $payType = ($ctx['pay_type'] ?? '') === 'wechat'
            ? $this->cfg['pay_type_wechat']
            : $this->cfg['pay_type_alipay'];

        $params = [
            'pid'          => $this->cfg['pid'],
            'type'         => $payType,
            'out_trade_no' => (string) $order['out_trade_no'],
            'notify_url'   => (string) $ctx['notify_url'],
            'return_url'   => (string) $ctx['return_url'],
            'name'         => (string) ($order['subject'] ?: '账户充值'),
            // 易支付的金额单位是「元」，且要求两位小数
            'money'        => number_format(((int) $order['amount']) / 100, 2, '.', ''),
        ];
        $params['sign']      = $this->sign($params);
        $params['sign_type'] = 'MD5';

        return [
            'ok'    => true,
            'error' => '',
            'mode'  => 'html',
            'url'   => '',
            'html'  => $this->buildAutoForm((string) $this->cfg['api_url'], $params),
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
        $computed = $this->sign($params);
        // 定长比较，防时序侧信道
        return Crypto::equals($computed, $sign);
    }

    public function parseNotify(array $params): array
    {
        $paid = (string) ($params['trade_status'] ?? '') === 'TRADE_SUCCESS';
        $money = (string) ($params['money'] ?? '0');
        // 元 → 分。用字符串运算避免浮点误差
        $cents = 0;
        if (preg_match('/^\d+(\.\d{1,2})?$/', $money) === 1) {
            $parts = explode('.', $money, 2);
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

    /**
     * 按协议计算签名。
     * 顺序很关键：过滤空值 → 按参数名升序 → 拼 k=v → 末尾接密钥 → MD5
     */
    public function sign(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type') {
                continue;
            }
            if ($v === '' || $v === null || is_array($v)) {
                continue;
            }
            $filtered[(string) $k] = (string) $v;
        }
        ksort($filtered, SORT_STRING);

        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return md5(implode('&', $pairs) . (string) $this->cfg['key']);
    }

    /** 生成自动提交的表单（易支付要求 POST 到 submit_url） */
    private function buildAutoForm(string $action, array $params): string
    {
        $inputs = '';
        foreach ($params as $k => $v) {
            $inputs .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><title>正在跳转到支付页面…</title>'
            . '<style>body{font:15px/1.7 system-ui,sans-serif;display:grid;place-items:center;'
            . 'min-height:100vh;margin:0;background:#faf8f6;color:#4b4137}'
            . '.box{text-align:center}.sp{width:26px;height:26px;border:3px solid #ee7318;'
            . 'border-right-color:transparent;border-radius:50%;margin:0 auto 14px;'
            . 'animation:s .7s linear infinite}@keyframes s{to{transform:rotate(360deg)}}</style></head>'
            . '<body onload="document.forms[0].submit()">'
            . '<div class="box"><div class="sp"></div>正在跳转到支付页面，请稍候…</div>'
            . '<form method="post" action="' . e($action) . '">' . $inputs . '</form>'
            . '</body></html>';
    }
}
