<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Payment\GatewayManager;
use Vobl\Services\Settings;
use Vobl\Services\Wallet;

/**
 * 充值 / 支付
 * ============================================================================
 * 三条链路：
 *   ① 创建订单 → 交给支付通道 → 用户付款 → 通道异步通知 → 入账
 *   ② 用户从支付页返回（同步）→ 只做展示，绝不在这一步入账
 *   ③ 后台手动调整余额（管理员）
 *
 * ★ 最重要的两条规矩：
 *   · 同步返回（return）**绝不能**用来入账。用户可以伪造 GET 参数直接访问它。
 *     入账只认异步通知（notify），那个有签名校验。
 *   · 订单金额由服务端决定，回调金额只用于比对。见 Wallet::settleNotify()。
 * ============================================================================
 */
final class PaymentController extends BaseController
{
    // ================================================================ 充值页

    public static function index(Request $request): void
    {
        Auth::requireLogin();
        $userId = Auth::id();

        $gateways = GatewayManager::enabled();
        // 过滤掉未配置完整的通道，避免用户选了一个点了必然报错的
        $usable = [];
        foreach ($gateways as $g) {
            $driver = GatewayManager::driverFor($g);
            if ($driver !== null && $driver->isConfigured()) {
                $usable[] = [
                    'code'        => (string) $g['code'],
                    'name'        => (string) $g['name'],
                    'is_default'  => (int) $g['is_default'] === 1,
                    'pay_types'   => self::payTypesOf((string) $g['code']),
                ];
            }
        }

        $orders = Wallet::ordersOf($userId, self::pageNo($request), 15);
        $txPage = Wallet::transactions($userId, self::pageNo($request), 15);

        self::page('payment/index', [
            'user'        => Auth::user(),
            'wallet'      => Wallet::of($userId),
            'gateways'    => $usable,
            'orders'      => $orders,
            'transactions'=> $txPage,
            'minAmount'   => Settings::int('payment.recharge_min', 100),
            'maxAmount'   => Settings::int('payment.recharge_max', 1000000),
            'bonusRate'   => (int) Settings::get('payment.bonus_rate', 0),
            'currency'    => (string) Settings::get('payment.currency', 'CNY'),
            'flash'       => self::takenFlash(),
            'presets'     => [1000, 3000, 5000, 10000, 30000, 50000],   // 快捷金额（分）
        ]);
    }

    /** 各通道支持的支付方式（用于二级选择） */
    private static function payTypesOf(string $code): array
    {
        return match ($code) {
            'epay'   => ['alipay' => '支付宝', 'wechat' => '微信'],
            'alipay' => ['alipay' => '支付宝'],
            'wechat' => ['wechat' => '微信'],
            default  => [],
        };
    }

    // ================================================================ 创建订单

    public static function createOrder(Request $request): void
    {
        Auth::requireLogin();
        $userId = Auth::id();

        $amountCents = $request->amountCents('amount');
        $gatewayCode = $request->str('gateway', '', 32);
        $payType     = $request->str('pay_type', 'alipay', 20);

        $result = Wallet::createRechargeOrder(
            $userId,
            $amountCents,
            $gatewayCode,
            $payType,
            $request->ip()
        );

        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        $order   = $result['order'];
        $gateway = $result['gateway'];

        $driver = GatewayManager::driverFor($gateway);
        if ($driver === null) {
            Response::error('支付通道不可用', 500);
        }

        $origin = rtrim((string) Settings::get('site.url', $request->origin()), '/');

        $pay = $driver->createPayment($order, [
            // ★ 通知地址必须用配置的正式域名，不能用当前请求的 Host ——
            //   否则从内网 IP 访问时会生成一个通道方根本访问不到的回调地址
            'notify_url' => $origin . '/pay/notify/' . $gateway['code'],
            'return_url' => $origin . '/pay/return?out_trade_no=' . urlencode((string) $order['out_trade_no']),
            'client_ip'  => $request->ip(),
            'pay_type'   => $payType,
        ]);

        if (!$pay['ok']) {
            Audit::log('payment', 'order.payfail', [
                'actor_id' => $userId, 'target' => (string) $order['out_trade_no'],
                'result'   => 'fail', 'detail' => $pay['error'],
            ]);
            Response::error($pay['error'], 502);
        }

        Response::ok([
            'out_trade_no' => (string) $order['out_trade_no'],
            'mode'         => $pay['mode'],
            'url'          => $pay['url'],
            'html'         => $pay['html'],
            'amount'       => (int) $order['amount'],
        ], '订单已创建，正在跳转支付');
    }

    // ================================================================ 同步返回

    /**
     * 用户付款后从支付页跳回来。
     * ★ 这里只做展示，绝不入账 —— 入账只认异步通知。
     *   原因是这个地址是 GET，任何人都能直接访问并伪造参数。
     */
    public static function payReturn(Request $request): void
    {
        Auth::requireLogin();

        $outTradeNo = $request->str('out_trade_no', '', 40);
        $order = \Vobl\Core\DB::conn()->one(
            'SELECT * FROM payment_orders WHERE out_trade_no = ? AND user_id = ? LIMIT 1',
            [$outTradeNo, Auth::id()]
        );

        if ($order === null) {
            Response::redirect('/wallet?flash=' . urlencode('没有找到对应的订单') . '&flash_type=err');
        }

        // 订单状态由异步通知驱动，这里读到的可能是「还没到账」
        if ((string) $order['status'] === 'paid') {
            Response::redirect('/wallet?flash=' . urlencode('充值成功，余额已到账'));
        }

        Response::redirect('/wallet?flash=' . urlencode(
            '支付结果确认中。如果已完成付款，到账通常需要几秒到一分钟，请稍后刷新本页。'
        ) . '&flash_type=warn');
    }

    // ================================================================ 异步通知

    /**
     * 支付通道的异步通知。
     *
     * 这个端点不鉴权、不校验 CSRF，因为它是「服务器对服务器」的调用 ——
     * 它的真实性靠通道的签名校验（在 Wallet::settleNotify 里完成）。
     *
     * 必须返回通道期望的字符串（多数是 "success"），
     * 返回别的会被判定为「接收失败」从而不断重试。
     */
    public static function notify(Request $request, array $params = []): void
    {
        $gatewayCode = (string) ($params['gateway'] ?? '');

        // 合并 GET 与 POST 参数（有些通道用 GET 通知）
        $raw = array_merge($_GET, $_POST);
        // 也兼容 JSON 通知体
        $json = $request->json();
        if ($json !== []) {
            $raw = array_merge($raw, $json);
        }
        unset($raw['gateway']);

        $driver = GatewayManager::driver($gatewayCode);
        $ack = $driver !== null ? $driver->notifyAck() : 'success';

        try {
            $result = Wallet::settleNotify($gatewayCode, $raw);

            if ($result['ok']) {
                // 重复通知也返回成功，让对方停止重试
                Response::text($ack);
            }

            // 失败时返回 fail，让通道重试（可能是我们临时出错）
            Audit::log('payment', 'notify.fail', [
                'target' => (string) ($raw['out_trade_no'] ?? $gatewayCode),
                'result' => 'fail',
                'detail' => (string) $result['error'],
            ]);
            Response::text('fail');
        } catch (\Throwable $e) {
            error_log('[payment] 处理通知异常：' . $e->getMessage());
            // 异常也返回 fail，让通道重试
            Response::text('fail');
        }
    }
}
