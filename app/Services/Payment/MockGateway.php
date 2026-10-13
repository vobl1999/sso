<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

use Vobl\Core\Crypto;

/**
 * 模拟支付通道（内置，用于在绑定真实通道之前跑通全流程）
 * ============================================================================
 * 为什么需要它：
 *   真实支付通道的申请与审核周期可能好几天。在此之前，如果没有模拟通道，
 *   充值功能就完全没法测试 —— 订单、回调、入账、退款这条链路一行都验不了。
 *
 * 它的行为与真实通道**完全一致**：
 *   · 同样生成订单、同样跳转到「支付页」、同样 POST 异步通知、同样要验签
 *   · 唯一区别是「支付页」是本地的，点一下按钮就当成付款成功
 *
 * 所以一旦换成真实通道，除了配置，代码路径一行都不用改。
 *
 * ⚠️ 上线前务必在后台停用它 —— 否则等于给所有人开了免费充值。
 * ============================================================================
 */
final class MockGateway implements GatewayInterface
{
    private array $cfg;

    public function __construct(array $config = [])
    {
        $this->cfg = array_merge(['key' => 'mock-secret-key'], $config);
    }

    public function code(): string { return 'mock'; }
    public function name(): string { return '模拟支付（仅测试用）'; }

    public static function configSchema(): array
    {
        return [
            ['key' => 'key', 'label' => '模拟密钥', 'type' => 'password', 'required' => false,
             'hint' => '仅用于本地流程测试。上线前请停用本通道，否则任何人都能免费充值。',
             'secret' => true, 'default' => 'mock-secret-key'],
        ];
    }

    public function isConfigured(): bool
    {
        return true;   // 永远可用，因为它不发外部请求
    }

    public function createPayment(array $order, array $ctx): array
    {
        // 把回调参数预先算好，塞进模拟支付页 —— 这样它的通知报文
        // 与真实通道的结构完全一致，Wallet 那边的代码不用为它做任何特判
        $params = [
            'out_trade_no' => (string) $order['out_trade_no'],
            'trade_no'     => 'MOCK' . date('YmdHis') . random_int(1000, 9999),
            'amount'       => (string) $order['amount'],
            'paid'         => '1',
        ];
        $params['sign'] = $this->sign($params);

        $notifyUrl  = (string) $ctx['notify_url'];
        $returnUrl  = (string) $ctx['return_url'];

        return [
            'ok'    => true,
            'error' => '',
            'mode'  => 'html',
            'url'   => '',
            'html'  => $this->renderPage($order, $params, $notifyUrl, $returnUrl),
            'form'  => $params,
        ];
    }

    public function verifyNotify(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        return Crypto::equals($this->sign($params), $sign);
    }

    public function parseNotify(array $params): array
    {
        return [
            'out_trade_no' => (string) ($params['out_trade_no'] ?? ''),
            'trade_no'     => (string) ($params['trade_no'] ?? ''),
            'amount'       => (int) ($params['amount'] ?? 0),
            'paid'         => (string) ($params['paid'] ?? '') === '1',
            'raw'          => $params,
        ];
    }

    public function notifyAck(): string
    {
        return 'success';
    }

    public function sign(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === '') {
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

    /** 本地的「收银台」页面 */
    private function renderPage(array $order, array $params, string $notifyUrl, string $returnUrl): string
    {
        $fields = '';
        foreach ($params as $k => $v) {
            $fields .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
        }

        $amount = number_format(((int) $order['amount']) / 100, 2);

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>模拟收银台 · 仅测试用</title><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#faf8f6;'
            . 'font:15px/1.7 -apple-system,Segoe UI,PingFang SC,sans-serif;color:#1f1710}'
            . '.card{max-width:400px;width:calc(100% - 32px);background:#fff;border:1px solid #e8eaee;'
            . 'border-radius:18px;padding:30px 26px;text-align:center}'
            . '.warn{background:#fdf1e5;color:#b34e08;border-radius:11px;padding:11px 14px;'
            . 'font-size:13px;margin-bottom:22px;text-align:left;line-height:1.6}'
            . '.amt{font-size:40px;font-weight:700;letter-spacing:-.03em;margin:8px 0 4px}'
            . '.sub{color:#94837a;font-size:13px;margin-bottom:24px}'
            . 'button{width:100%;height:48px;border:0;border-radius:13px;font:600 15px/1 inherit;'
            . 'cursor:pointer;margin-bottom:10px}'
            . '.pay{background:#ff8a24;color:#401a00}'
            . '.cancel{background:#f4f4f5;color:#6b7280}'
            . '</style></head><body>'
            . '<div class="card">'
            . '<div class="warn"><b>这是模拟收银台</b><br>不会产生任何真实扣款，点「确认支付」即视为付款成功，'
            . '用于测试充值链路是否正常。</div>'
            . '<div class="amt">¥' . e($amount) . '</div>'
            . '<div class="sub">' . e((string) ($order['subject'] ?: '账户充值'))
            . ' · 订单号 ' . e((string) $order['out_trade_no']) . '</div>'
            . '<form method="post" action="' . e($notifyUrl) . '">' . $fields
            . '<button class="pay" type="submit">确认支付</button></form>'
            . '<form method="get" action="' . e($returnUrl) . '">'
            . '<input type="hidden" name="out_trade_no" value="' . e((string) $order['out_trade_no']) . '">'
            . '<button class="cancel" type="submit">取消并返回</button></form>'
            . '</div></body></html>';
    }
}
