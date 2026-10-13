<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Payment\GatewayManager;
use Vobl\Services\Settings;
use Vobl\Services\Wallet;

/**
 * 后台 · 支付与充值管理
 * ============================================================================
 * 需求「支付 API 可在后台绑定与切换」的实现处：
 *
 *   · 通道列表来自 GatewayManager::all()，代码里有新通道就自动出现
 *   · 每个通道的配置表单由该通道的 configSchema() 生成 ——
 *     加新支付方式不用改这个控制器，也不用改模板
 *   · 密钥字段加密存储、回显打码，留空表示不修改
 *   · 「设为默认」用事务保证全站只有一个默认通道
 *
 * 三条与钱相关的硬约束：
 *   ① 退款必须退款金额 = 原订单金额，且用户余额得够扣回
 *   ② 手工调整余额必须填原因，且记录操作人
 *   ③ 订单金额一律以数据库为准，绝不接受外部传入
 * ============================================================================
 */
final class PaymentAdminController extends BaseController
{
    // ================================================================ 通道

    public static function index(Request $request): void
    {
        Auth::requireCapability('payment.view');

        $gateways = GatewayManager::all(true);
        $result = [];
        foreach ($gateways as $g) {
            $driver = GatewayManager::driverFor($g);
            $result[] = [
                'row'        => $g,
                'name'       => $driver !== null ? $driver->name() : (string) $g['name'],
                'configured' => $driver !== null && $driver->isConfigured(),
                'schema'     => GatewayManager::MAP[(string) $g['code']]::configSchema(),
                'config'     => GatewayManager::maskConfigForDisplay((string) $g['code'], $g['config_array']),
                'order_count'=> DB::conn()->count(
                    'SELECT COUNT(*) FROM payment_orders WHERE gateway_code = ?',
                    [(string) $g['code']]
                ),
            ];
        }

        $defaultGateway = GatewayManager::default();
        $defaultCode = (string) ($defaultGateway['code'] ?? '');

        self::adminPage('admin/payment', [
            'title'      => '支付与充值',
            'gateways'   => $result,
            'stats'      => Wallet::stats(),
            'settings'   => Settings::group('payment'),
            // 模拟通道仍是默认通道 → 说明还没接真实支付，界面上要给出明确提醒
            'isMockDefault' => $defaultCode === 'mock',
            'defaultCode'=> $defaultCode,
            'canManage'  => Auth::can('payment.manage'),
            'flash'      => self::takenFlash(),
        ]);
    }

    public static function saveGateway(Request $request): void
    {
        Auth::requireCapability('payment.manage');

        $code = $request->str('code', '', 32);
        if ($code === '' || !isset(GatewayManager::MAP[$code])) {
            Response::error('未知的支付通道', 422);
        }

        // 把该通道声明的字段都取出来交给管理器（管理器负责「留空即不修改」的逻辑）
        $input = [];
        foreach (GatewayManager::MAP[$code]::configSchema() as $field) {
            $input[$field['key']] = $request->raw($field['key'], '', 4000);
        }
        $input['name'] = $request->str('name', '', 60);
        $input['note'] = $request->str('note', '', 200);

        $result = GatewayManager::saveConfig($code, $input);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        self::back('/admin/payment', '通道配置已保存');
    }

    public static function toggle(Request $request): void
    {
        Auth::requireCapability('payment.manage');

        $code = $request->str('code', '', 32);
        $enable = $request->bool('enable');

        // 关掉模拟通道时给一次明确提醒（这是上线前必做的一步）
        if ($code === 'mock' && !$enable) {
            Audit::log('payment', 'gateway.disable_mock', [
                'actor_id' => Auth::id(),
                'detail'   => '停用模拟支付通道（上线前的正确操作）',
            ]);
        }

        $result = GatewayManager::toggle($code, $enable);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        self::back('/admin/payment', $enable ? '通道已启用' : '通道已停用');
    }

    public static function setDefault(Request $request): void
    {
        Auth::requireCapability('payment.manage');

        $code = $request->str('code', '', 32);
        $result = GatewayManager::setDefault($code);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        self::back('/admin/payment', '默认支付通道已切换');
    }

    // ================================================================ 订单

    public static function orders(Request $request): void
    {
        Auth::requireCapability('payment.order.view');

        $status  = $request->str('status', '', 20);
        $gateway = $request->str('gateway', '', 32);
        $keyword = $request->str('q', '', 100);
        $page    = self::pageNo($request);

        $where = [];
        $params = [];
        if (in_array($status, ['pending', 'paid', 'failed', 'closed', 'refunded'], true)) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($gateway !== '') {
            $where[] = 'o.gateway_code = ?';
            $params[] = $gateway;
        }
        if ($keyword !== '') {
            $where[] = '(o.out_trade_no LIKE ? OR o.trade_no LIKE ? OR u.email LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $sqlWhere = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = 25;
        $total = DB::conn()->count(
            "SELECT COUNT(*) FROM payment_orders o LEFT JOIN users u ON u.id = o.user_id {$sqlWhere}",
            $params
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $rows = DB::conn()->all(
            "SELECT o.*, u.email AS user_email, u.display_name AS user_name
             FROM payment_orders o LEFT JOIN users u ON u.id = o.user_id
             {$sqlWhere} ORDER BY o.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // 汇总信息，便于对账
        $sum = DB::conn()->one(
            "SELECT
                COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.paid_at >= ? THEN o.amount ELSE 0 END), 0) AS today_amount,
                COALESCE(SUM(CASE WHEN o.status = 'refunded' THEN o.credited ELSE 0 END), 0) AS refunded_amount
             FROM payment_orders o LEFT JOIN users u ON u.id = o.user_id {$sqlWhere}",
            array_merge([strtotime('today')], $params)
        ) ?? [];

        self::adminPage('admin/orders', [
            'title'    => '订单与对账',
            'orders'   => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'status'   => $status,
            'gateway'  => $gateway,
            'keyword'  => $keyword,
            'gateways' => GatewayManager::all(),
            'sum'      => $sum,
            'canRefund'=> Auth::can('payment.refund'),
            'flash'    => self::takenFlash(),
        ]);
    }

    public static function refund(Request $request): void
    {
        Auth::requireCapability('payment.refund');

        $outTradeNo = $request->str('out_trade_no', '', 40);
        $reason = $request->str('reason', '', 200);

        if ($outTradeNo === '') {
            Response::error('缺少订单号', 422);
        }
        if (trim($reason) === '') {
            Response::error('请填写退款原因（会记入审计日志）', 422);
        }

        $result = Wallet::refund($outTradeNo, Auth::id(), $reason);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        self::back('/admin/orders', '订单 ' . $outTradeNo . ' 已退款，余额已扣回');
    }

    // ================================================================ 钱包

    public static function wallets(Request $request): void
    {
        Auth::requireCapability('payment.order.view');

        $keyword = $request->str('q', '', 100);
        $page    = self::pageNo($request);

        $where = [];
        $params = [];
        if ($keyword !== '') {
            $where[] = '(u.email LIKE ? OR u.display_name LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $sqlWhere = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = 25;
        $total = DB::conn()->count(
            "SELECT COUNT(*) FROM users u LEFT JOIN wallets w ON w.user_id = u.id {$sqlWhere}",
            $params
        );
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $rows = DB::conn()->all(
            "SELECT u.id, u.email, u.display_name, u.status,
                    COALESCE(w.balance, 0) AS balance, w.updated_at AS wallet_updated,
                    (SELECT COALESCE(SUM(amount),0) FROM payment_orders o
                     WHERE o.user_id = u.id AND o.status = 'paid') AS total_recharged
             FROM users u LEFT JOIN wallets w ON w.user_id = u.id
             {$sqlWhere}
             ORDER BY COALESCE(w.balance, 0) DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        self::adminPage('admin/wallets', [
            'title'    => '用户余额',
            'wallets'  => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'keyword'  => $keyword,
            'sumBalance' => (int) DB::conn()->val('SELECT COALESCE(SUM(balance),0) FROM wallets', [], 0),
            'canAdjust'=> Auth::can('wallet.adjust'),
            'flash'    => self::takenFlash(),
        ]);
    }
}
