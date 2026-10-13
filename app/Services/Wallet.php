<?php
declare(strict_types=1);

namespace Vobl\Services;

use Vobl\Core\Audit;
use Vobl\Core\DB;
use Vobl\Services\Payment\GatewayManager;

/**
 * 钱包与资金
 * ============================================================================
 * 资金相关的代码是全项目最不能出错的部分。四条铁律：
 *
 *   1. 金额一律用「分」的整数。绝不用浮点 —— 0.1 + 0.2 !== 0.3 在钱上是灾难。
 *
 *   2. 所有余额变动必须在事务里，且用原子 SQL（balance = balance + ?），
 *      不做「先读出来、再算、再写回去」—— 那样并发下必然出错。
 *
 *   3. 入账必须幂等。支付通道的回调会重复到达（对方重试、用户刷新页面都会）。
 *      做法是「条件更新」：只有 status 还是 pending 才允许改成 paid，
 *      靠 UPDATE 的返回行数判断是否抢到了这次入账。抢不到的说明别人已经处理了。
 *
 *   4. 金额以服务端订单为准。回调里传来的金额只用来「比对」，绝不用来「决定」。
 *      否则攻击者改一下回调里的金额就能 1 分钱充值 10000 元。
 * ============================================================================
 */
final class Wallet
{
    // ================================================================ 账户

    public static function of(int $userId): array
    {
        $row = DB::conn()->one('SELECT * FROM wallets WHERE user_id = ? LIMIT 1', [$userId]);
        if ($row === null) {
            DB::conn()->insert('wallets', [
                'user_id'    => $userId,
                'balance'    => 0,
                'frozen'     => 0,
                'currency'   => (string) Settings::get('payment.currency', 'CNY'),
                'updated_at' => time(),
            ]);
            $row = DB::conn()->one('SELECT * FROM wallets WHERE user_id = ? LIMIT 1', [$userId]);
        }
        return $row ?? ['user_id' => $userId, 'balance' => 0, 'frozen' => 0, 'currency' => 'CNY'];
    }

    public static function balance(int $userId): int
    {
        return (int) self::of($userId)['balance'];
    }

    // ================================================================ 下单

    /**
     * 创建充值订单。
     * @return array{ok:bool, error:string, order:array}
     */
    public static function createRechargeOrder(
        int $userId,
        int $amountCents,
        string $gatewayCode,
        string $payType = 'alipay',
        string $clientIp = ''
    ): array {
        $min = Settings::int('payment.recharge_min', 100);
        $max = Settings::int('payment.recharge_max', 1000000);

        if ($amountCents <= 0) {
            return ['ok' => false, 'error' => '请输入有效的充值金额', 'order' => []];
        }
        if ($amountCents < $min) {
            return ['ok' => false, 'error' => '单笔最低充值 ' . number_format($min / 100, 2) . ' 元', 'order' => []];
        }
        if ($amountCents > $max) {
            return ['ok' => false, 'error' => '单笔最高充值 ' . number_format($max / 100, 2) . ' 元', 'order' => []];
        }

        $gateway = $gatewayCode !== '' ? GatewayManager::findByCode($gatewayCode) : GatewayManager::default();
        if ($gateway === null || (int) $gateway['enabled'] !== 1) {
            return ['ok' => false, 'error' => '支付通道不可用，请联系管理员', 'order' => []];
        }

        // 赠送金额：按配置比例计算，上限不超过本金（防止配错导致送破产）
        $bonusRate = (int) Settings::get('payment.bonus_rate', 0);
        $bonus = $bonusRate > 0 ? (int) floor($amountCents * $bonusRate / 100) : 0;
        $bonus = min($bonus, $amountCents);

        $outTradeNo = GatewayManager::newOrderNo();
        $subject = '账户充值 ' . number_format($amountCents / 100, 2) . ' 元'
            . ($bonus > 0 ? '（赠 ' . number_format($bonus / 100, 2) . '）' : '');

        DB::conn()->insert('payment_orders', [
            'out_trade_no' => $outTradeNo,
            'trade_no'     => '',
            'user_id'      => $userId,
            'gateway_code' => (string) $gateway['code'],
            'amount'       => $amountCents,
            'credited'     => $amountCents + $bonus,
            'subject'      => $subject,
            'kind'         => 'recharge',
            'status'       => 'pending',
            'notify_payload' => '',
            'notify_count' => 0,
            'client_ip'    => $clientIp,
            'created_at'   => time(),
            'paid_at'      => 0,
            'expires_at'   => time() + 3600,   // 1 小时未支付自动关闭
        ]);

        $order = DB::conn()->one('SELECT * FROM payment_orders WHERE out_trade_no = ?', [$outTradeNo]) ?? [];

        Audit::log('payment', 'order.create', [
            'actor_id' => $userId,
            'target'   => $outTradeNo,
            'detail'   => '创建充值订单 ' . number_format($amountCents / 100, 2) . ' 元，通道：' . $gateway['name'],
        ]);

        return ['ok' => true, 'error' => '', 'order' => $order, 'gateway' => $gateway];
    }

    // ================================================================ 入账

    /**
     * 处理支付成功回调并入账。
     *
     * ★ 这是全系统最需要小心的函数。幂等的实现方式见下方注释。
     *
     * @return array{ok:bool, error:string, already:bool, order:array}
     */
    public static function settleNotify(string $gatewayCode, array $params): array
    {
        $gateway = GatewayManager::driver($gatewayCode);
        if ($gateway === null) {
            return ['ok' => false, 'error' => '未知的支付通道：' . $gatewayCode, 'already' => false, 'order' => []];
        }

        // ① 验签。不过就直接丢弃，绝不进入业务逻辑
        if (!$gateway->verifyNotify($params)) {
            Audit::log('payment', 'notify.badsign', [
                'actor_id' => 0,
                'target'   => $gatewayCode,
                'result'   => 'denied',
                'detail'   => '支付回调验签失败，已丢弃',
                'data'     => $params,
            ]);
            return ['ok' => false, 'error' => '验签失败', 'already' => false, 'order' => []];
        }

        $info = $gateway->parseNotify($params);
        $outTradeNo = $info['out_trade_no'];
        if ($outTradeNo === '') {
            return ['ok' => false, 'error' => '回调缺少订单号', 'already' => false, 'order' => []];
        }

        $order = DB::conn()->one('SELECT * FROM payment_orders WHERE out_trade_no = ? LIMIT 1', [$outTradeNo]);
        if ($order === null) {
            return ['ok' => false, 'error' => '订单不存在', 'already' => false, 'order' => []];
        }

        // 回调可能来自另一个通道（配置切换后的历史订单），以订单上记录的为准
        if ((string) $order['gateway_code'] !== $gatewayCode) {
            Audit::log('payment', 'notify.gateway_mismatch', [
                'target' => $outTradeNo, 'result' => 'denied',
                'detail' => '回调通道 ' . $gatewayCode . ' 与订单通道 ' . $order['gateway_code'] . ' 不一致',
            ]);
            return ['ok' => false, 'error' => '通道不匹配', 'already' => false, 'order' => $order];
        }

        // 记录通知次数，便于排查「对方一直在重试」的情况
        DB::conn()->query(
            'UPDATE payment_orders SET notify_count = notify_count + 1, notify_payload = ? WHERE id = ?',
            [mb_substr(json_encode($params, JSON_UNESCAPED_UNICODE), 0, 60000), $order['id']]
        );

        if (!$info['paid']) {
            DB::conn()->update('payment_orders',
                ['status' => 'failed'],
                'id = ? AND status = ?', [$order['id'], 'pending']);
            return ['ok' => true, 'error' => '', 'already' => false, 'order' => $order];
        }

        // ② 金额比对。以订单金额为准，回调金额只用来核对
        if ($info['amount'] > 0 && $info['amount'] !== (int) $order['amount']) {
            Audit::log('payment', 'notify.amount_mismatch', [
                'actor_id' => (int) $order['user_id'], 'target' => $outTradeNo, 'result' => 'denied',
                'detail' => sprintf('金额不符：订单 %d 分，回调 %d 分', (int) $order['amount'], $info['amount']),
            ]);
            return ['ok' => false, 'error' => '金额不一致，已拒绝入账', 'already' => false, 'order' => $order];
        }

        // ③ 幂等入账
        return DB::conn()->tx(function (DB $db) use ($order, $info, $outTradeNo) {
            // ★ 条件更新：只有还是 pending 才改得动。
            //   靠返回行数判断谁抢到了这次入账 —— 并发或重试时只有第一次能成功。
            $affected = $db->query(
                'UPDATE payment_orders SET status = ?, trade_no = ?, paid_at = ?, notify_payload = ?
                 WHERE id = ? AND status = ?',
                ['paid', $info['trade_no'], time(),
                 mb_substr(json_encode($info['raw'], JSON_UNESCAPED_UNICODE), 0, 60000),
                 $order['id'], 'pending']
            )->rowCount();

            if ($affected === 0) {
                // 已经被处理过了 —— 这是正常的重复通知，返回成功让对方停止重试
                return ['ok' => true, 'error' => '', 'already' => true, 'order' => $order];
            }

            $userId  = (int) $order['user_id'];
            $credit  = (int) $order['credited'];   // 含赠送

            // 余额用原子 SQL 递增，不做读改写
            $db->increment('wallets', 'balance', $credit, 'user_id = ?', [$userId]);
            // 首次充值时钱包行可能还不存在，补一条
            $exists = $db->one('SELECT user_id FROM wallets WHERE user_id = ? LIMIT 1', [$userId]);
            if ($exists === null) {
                $db->insert('wallets', [
                    'user_id' => $userId, 'balance' => $credit, 'frozen' => 0,
                    'currency' => (string) Settings::get('payment.currency', 'CNY'),
                    'updated_at' => time(),
                ]);
            }

            $balanceAfter = (int) ($db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0));

            $db->insert('wallet_transactions', [
                'user_id'       => $userId,
                'direction'     => 'in',
                'amount'        => $credit,
                'balance_after' => $balanceAfter,
                'kind'          => 'recharge',
                'ref_id'        => $outTradeNo,
                'remark'        => '充值入账' . ($credit > (int) $order['amount']
                                    ? '（含赠送 ' . number_format(($credit - (int) $order['amount']) / 100, 2) . ' 元）'
                                    : ''),
                'operator'      => 0,
                'client_ip'     => (string) $order['client_ip'],
                'created_at'    => time(),
            ]);

            Audit::log('payment', 'order.paid', [
                'actor_id' => $userId,
                'target'   => $outTradeNo,
                'detail'   => sprintf('充值成功，入账 %s 元，余额 %s 元',
                    number_format($credit / 100, 2), number_format($balanceAfter / 100, 2)),
            ]);

            return ['ok' => true, 'error' => '', 'already' => false, 'order' => $order];
        });
    }

    // ================================================================ 扣款

    /**
     * 扣款（消费）。
     * 余额不足直接拒绝，不允许出现负数余额。
     */
    public static function debit(int $userId, int $amountCents, string $kind, string $remark, string $refId = ''): array
    {
        if ($amountCents <= 0) {
            return ['ok' => false, 'error' => '扣款金额必须大于 0'];
        }

        return DB::conn()->tx(function (DB $db) use ($userId, $amountCents, $kind, $remark, $refId) {
            $balance = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);
            if ($balance < $amountCents) {
                return ['ok' => false, 'error' => '余额不足，当前余额 ' . number_format($balance / 100, 2) . ' 元'];
            }

            // 带余额条件的原子扣减 —— 并发下不会扣成负数
            $affected = $db->query(
                'UPDATE wallets SET balance = balance - ?, updated_at = ? WHERE user_id = ? AND balance >= ?',
                [$amountCents, time(), $userId, $amountCents]
            )->rowCount();
            if ($affected === 0) {
                return ['ok' => false, 'error' => '扣款失败，请稍后重试（余额可能刚刚发生变化）'];
            }

            $after = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);
            $db->insert('wallet_transactions', [
                'user_id' => $userId, 'direction' => 'out', 'amount' => $amountCents,
                'balance_after' => $after, 'kind' => $kind, 'ref_id' => $refId,
                'remark' => $remark, 'operator' => 0, 'client_ip' => '', 'created_at' => time(),
            ]);
            return ['ok' => true, 'error' => '', 'balance' => $after];
        });
    }

    // ================================================================ 管理操作

    /**
     * 管理员手工调整余额。必须记录操作人，否则对账时说不清钱去哪了。
     */
    public static function adjust(int $userId, int $deltaCents, string $remark, int $operatorId): array
    {
        if ($deltaCents === 0) {
            return ['ok' => false, 'error' => '调整金额不能为 0'];
        }

        return DB::conn()->tx(function (DB $db) use ($userId, $deltaCents, $remark, $operatorId) {
            if ($deltaCents < 0) {
                $need = abs($deltaCents);
                $balance = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);
                if ($balance < $need) {
                    return ['ok' => false, 'error' => '用户余额不足以扣减 ' . number_format($need / 100, 2) . ' 元'];
                }
                $db->query(
                    'UPDATE wallets SET balance = balance - ?, updated_at = ? WHERE user_id = ? AND balance >= ?',
                    [$need, time(), $userId, $need]
                );
            } else {
                $exists = $db->one('SELECT user_id FROM wallets WHERE user_id = ? LIMIT 1', [$userId]);
                if ($exists === null) {
                    $db->insert('wallets', [
                        'user_id' => $userId, 'balance' => 0, 'frozen' => 0,
                        'currency' => (string) Settings::get('payment.currency', 'CNY'),
                        'updated_at' => time(),
                    ]);
                }
                $db->increment('wallets', 'balance', $deltaCents, 'user_id = ?', [$userId]);
            }

            $after = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);
            $db->insert('wallet_transactions', [
                'user_id' => $userId,
                'direction' => $deltaCents > 0 ? 'in' : 'out',
                'amount' => abs($deltaCents),
                'balance_after' => $after,
                'kind' => 'admin_adjust',
                'ref_id' => '',
                'remark' => $remark !== '' ? $remark : '管理员调整',
                'operator' => $operatorId,
                'client_ip' => '',
                'created_at' => time(),
            ]);

            Audit::log('payment', 'wallet.adjust', [
                'actor_id' => $operatorId,
                'target'   => 'user:' . $userId,
                'detail'   => sprintf('调整余额 %s%s 元，调整后 %s 元。备注：%s',
                    $deltaCents > 0 ? '+' : '-',
                    number_format(abs($deltaCents) / 100, 2),
                    number_format($after / 100, 2),
                    $remark),
            ]);

            return ['ok' => true, 'error' => '', 'balance' => $after];
        });
    }

    /**
     * 退款。把已支付订单标记为 refunded 并从余额扣回。
     * 余额不够扣时拒绝 —— 用户可能已经把钱花掉了。
     */
    public static function refund(string $outTradeNo, int $operatorId, string $reason = ''): array
    {
        $order = DB::conn()->one('SELECT * FROM payment_orders WHERE out_trade_no = ? LIMIT 1', [$outTradeNo]);
        if ($order === null) {
            return ['ok' => false, 'error' => '订单不存在'];
        }
        if ((string) $order['status'] !== 'paid') {
            return ['ok' => false, 'error' => '只有已支付的订单才能退款（当前状态：' . $order['status'] . '）'];
        }

        return DB::conn()->tx(function (DB $db) use ($order, $operatorId, $reason, $outTradeNo) {
            $userId = (int) $order['user_id'];
            $credit = (int) $order['credited'];

            $affected = $db->query(
                'UPDATE payment_orders SET status = ? WHERE id = ? AND status = ?',
                ['refunded', $order['id'], 'paid']
            )->rowCount();
            if ($affected === 0) {
                return ['ok' => false, 'error' => '订单状态已变化，退款中止'];
            }

            $balance = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);
            if ($balance < $credit) {
                // 回滚：把状态改回去，避免「标了退款但钱没扣」
                $db->query('UPDATE payment_orders SET status = ? WHERE id = ?', ['paid', $order['id']]);
                return ['ok' => false, 'error' => sprintf(
                    '用户余额（%s 元）不足以扣回本笔充值（%s 元），可能已消费。请先与用户确认。',
                    number_format($balance / 100, 2), number_format($credit / 100, 2)
                )];
            }

            $db->query(
                'UPDATE wallets SET balance = balance - ?, updated_at = ? WHERE user_id = ? AND balance >= ?',
                [$credit, time(), $userId, $credit]
            );
            $after = (int) $db->val('SELECT balance FROM wallets WHERE user_id = ?', [$userId], 0);

            $db->insert('wallet_transactions', [
                'user_id' => $userId, 'direction' => 'out', 'amount' => $credit,
                'balance_after' => $after, 'kind' => 'refund', 'ref_id' => $outTradeNo,
                'remark' => '充值退款' . ($reason !== '' ? '：' . $reason : ''),
                'operator' => $operatorId, 'client_ip' => '', 'created_at' => time(),
            ]);

            Audit::log('payment', 'order.refund', [
                'actor_id' => $operatorId, 'target' => $outTradeNo,
                'detail' => sprintf('退款 %s 元。原因：%s', number_format($credit / 100, 2), $reason ?: '未填写'),
            ]);

            return ['ok' => true, 'error' => '', 'balance' => $after];
        });
    }

    // ================================================================ 查询

    public static function transactions(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(100, max(5, $perPage));
        $page = max(1, $page);
        $total = DB::conn()->count('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?', [$userId]);
        $offset = ($page - 1) * $perPage;
        $rows = DB::conn()->all(
            "SELECT * FROM wallet_transactions WHERE user_id = ?
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$userId]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $perPage)];
    }

    public static function ordersOf(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(100, max(5, $perPage));
        $page = max(1, $page);
        $total = DB::conn()->count('SELECT COUNT(*) FROM payment_orders WHERE user_id = ?', [$userId]);
        $offset = ($page - 1) * $perPage;
        $rows = DB::conn()->all(
            "SELECT * FROM payment_orders WHERE user_id = ?
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$userId]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $perPage)];
    }

    /** 关闭超时未支付的订单 */
    public static function closeExpired(): int
    {
        return DB::conn()->query(
            'UPDATE payment_orders SET status = ? WHERE status = ? AND expires_at < ?',
            ['closed', 'pending', time()]
        )->rowCount();
    }

    /** 经营数据，后台看板用 */
    public static function stats(): array
    {
        $todayStart = strtotime('today');
        return [
            'today_amount' => (int) DB::conn()->val(
                'SELECT COALESCE(SUM(amount),0) FROM payment_orders WHERE status = ? AND paid_at >= ?',
                ['paid', $todayStart], 0
            ),
            'today_count' => DB::conn()->count(
                'SELECT COUNT(*) FROM payment_orders WHERE status = ? AND paid_at >= ?',
                ['paid', $todayStart]
            ),
            'total_amount' => (int) DB::conn()->val(
                'SELECT COALESCE(SUM(amount),0) FROM payment_orders WHERE status = ?', ['paid'], 0
            ),
            'pending_count' => DB::conn()->count(
                'SELECT COUNT(*) FROM payment_orders WHERE status = ? AND expires_at > ?',
                ['pending', time()]
            ),
            'balance_total' => (int) DB::conn()->val(
                'SELECT COALESCE(SUM(balance),0) FROM wallets', [], 0
            ),
        ];
    }
}
