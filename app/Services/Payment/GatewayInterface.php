<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

/**
 * 支付通道接口
 * ============================================================================
 * 所有支付实现都必须遵守这份契约，后台才能做到「绑定即用、切换不改代码」。
 *
 * ★ 三条不可妥协的安全底线（每个实现都必须遵守）：
 *
 *   1. 金额以服务端订单为准，绝不信任回调里传来的金额。
 *      回调只用来「确认付了」，不用来「决定付了多少」。
 *   2. 回调必须验签。验签失败的请求一律当作攻击丢弃。
 *   3. 回调可能重复到达（通道方会重试，用户也可能刷新），
 *      入账必须幂等 —— 同一订单号只允许入账一次。
 * ============================================================================
 */
interface GatewayInterface
{
    /** 通道标识，与数据库 payment_gateways.code 对应 */
    public function code(): string;

    /** 展示名称 */
    public function name(): string;

    /**
     * 发起支付。
     *
     * @param array $order 服务端订单（含 out_trade_no / amount(分) / subject）
     * @param array $ctx   上下文：notify_url / return_url / client_ip / pay_type
     * @return array{ok:bool, error:string, mode:string, url:string, html:string, form:array}
     *         mode = redirect  → 直接跳转 $url
     *         mode = html      → 输出 $html（自带表单，用于需要 POST 的通道）
     *         mode = qrcode    → 页面内展示二维码，$url 为二维码内容
     */
    public function createPayment(array $order, array $ctx): array;

    /**
     * 校验异步通知的签名。
     * @param array $params 通道方 POST/GET 过来的原始参数
     */
    public function verifyNotify(array $params): bool;

    /**
     * 从通知里解析出关键信息。必须在 verifyNotify 通过之后才调用。
     * @return array{out_trade_no:string, trade_no:string, amount:int, paid:bool, raw:array}
     */
    public function parseNotify(array $params): array;

    /**
     * 通道方期望我们返回什么字符串才算「接收成功」。
     * 返回错了对方会一直重试，导致重复通知。
     */
    public function notifyAck(): string;

    /**
     * 配置字段声明。后台据此自动生成表单，加新通道不用改界面代码。
     * @return array<int, array{key:string, label:string, type:string, required:bool, hint:string, secret:bool}>
     */
    public static function configSchema(): array;

    /** 该通道是否已配置完整、可以启用 */
    public function isConfigured(): bool;
}
