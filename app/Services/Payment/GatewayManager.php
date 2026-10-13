<?php
declare(strict_types=1);

namespace Vobl\Services\Payment;

use Vobl\Core\Audit;
use Vobl\Core\Crypto;
use Vobl\Core\DB;

/**
 * 支付通道管理器
 * ============================================================================
 * 后台「绑定与切换」的全部逻辑都在这里：
 *   · 通道配置存 payment_gateways 表（密钥字段加密）
 *   · 全站有且只有一个默认通道（is_default），切换就是改一行数据
 *   · 新增通道只需实现 GatewayInterface 并注册到 MAP，界面自动出现配置表单
 *
 * 为什么把「通道」做成数据而不是代码：
 *   用户在后台换支付服务商是运营动作，不该需要改代码重新部署。
 *   而且密钥属于敏感信息，不该进 Git 仓库。
 * ============================================================================
 */
final class GatewayManager
{
    /** 已实现的通道：code => 类名。加通道只改这一行 */
    public const MAP = [
        'epay'   => EpayGateway::class,
        'alipay' => AlipayGateway::class,
        'wechat' => WechatGateway::class,
        'mock'   => MockGateway::class,
    ];

    private static ?array $cache = null;

    // ================================================================ 读取

    /**
     * 全部通道（含未启用的），后台列表用。
     * 会自动补齐「代码里有、数据库里还没有」的新通道。
     */
    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }

        $rows = DB::conn()->all('SELECT * FROM payment_gateways ORDER BY sort_order, id');
        $existing = [];
        $out = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            if (!isset(self::MAP[$code])) {
                continue;   // 代码里已移除的通道，忽略
            }
            $row['config_array'] = self::decodeConfig((string) $row['config']);
            $row['driver'] = self::MAP[$code];
            $out[] = $row;
            $existing[$code] = true;
        }

        // 代码里有但库里没有 → 自动插入一条未启用的记录
        $sort = count($out);
        foreach (self::MAP as $code => $class) {
            if (isset($existing[$code])) {
                continue;
            }
            $driver = new $class([]);
            $sort++;
            DB::conn()->insert('payment_gateways', [
                'code'       => $code,
                'name'       => $driver->name(),
                'driver'     => $class,
                'config'     => json_encode(self::defaultConfig($class), JSON_UNESCAPED_UNICODE),
                'enabled'    => $code === 'mock' ? 1 : 0,   // 内置模拟通道默认开启，方便先跑通流程
                'is_default' => $code === 'mock' ? 1 : 0,
                'sort_order' => $sort,
                'note'       => '',
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $row = DB::conn()->one('SELECT * FROM payment_gateways WHERE code = ?', [$code]);
            if ($row !== null) {
                $row['config_array'] = self::decodeConfig((string) $row['config']);
                $row['driver'] = $class;
                $out[] = $row;
            }
        }

        self::$cache = $out;
        return $out;
    }

    /** 已启用的通道，前台支付方式选择用 */
    public static function enabled(): array
    {
        return array_values(array_filter(self::all(), static fn($g) => (int) $g['enabled'] === 1));
    }

    /** 当前默认通道 */
    public static function default(): ?array
    {
        $enabled = self::enabled();
        if ($enabled === []) {
            return null;
        }
        foreach ($enabled as $g) {
            if ((int) $g['is_default'] === 1) {
                return $g;
            }
        }
        return $enabled[0];   // 没有标记默认时用第一个，避免下单直接失败
    }

    public static function findByCode(string $code): ?array
    {
        foreach (self::all() as $g) {
            if ($g['code'] === $code) {
                return $g;
            }
        }
        return null;
    }

    /** 实例化通道驱动 */
    public static function driver(string $code): ?GatewayInterface
    {
        $row = self::findByCode($code);
        if ($row === null) {
            return null;
        }
        $class = self::MAP[$row['code']];
        return new $class($row['config_array']);
    }

    public static function driverFor(?array $row): ?GatewayInterface
    {
        if ($row === null) {
            return null;
        }
        $class = self::MAP[$row['code']] ?? null;
        if ($class === null) {
            return null;
        }
        return new $class($row['config_array'] ?? []);
    }

    // ================================================================ 写入

    /**
     * 保存通道配置。
     * ★ 密钥字段留空或为打码占位符时表示「不修改」，避免把已配置的密钥覆盖掉。
     */
    public static function saveConfig(string $code, array $input): array
    {
        $row = self::findByCode($code);
        if ($row === null) {
            return ['ok' => false, 'error' => '通道不存在'];
        }

        $class = self::MAP[$code];
        $schema = $class::configSchema();
        $config = $row['config_array'];

        foreach ($schema as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key]);
            if (!empty($field['secret']) && ($value === '' || $value === '••••••••')) {
                continue;   // 保持原值
            }
            $config[$key] = $value;
        }

        DB::conn()->update('payment_gateways', [
            'name'       => trim((string) ($input['name'] ?? $row['name'])) ?: $row['name'],
            'config'     => json_encode($config, JSON_UNESCAPED_UNICODE),
            'note'       => mb_substr(trim((string) ($input['note'] ?? $row['note'])), 0, 200),
            'updated_at' => time(),
        ], 'code = ?', [$code]);

        self::$cache = null;
        Audit::log('payment', 'gateway.config', [
            'target' => $code,
            'detail' => '更新支付通道配置：' . $row['name'],
        ]);
        return ['ok' => true, 'error' => ''];
    }

    /** 启用 / 停用 */
    public static function toggle(string $code, bool $enabled): array
    {
        $row = self::findByCode($code);
        if ($row === null) {
            return ['ok' => false, 'error' => '通道不存在'];
        }
        if ($enabled) {
            $driver = self::driverFor($row);
            if ($driver !== null && !$driver->isConfigured()) {
                return ['ok' => false, 'error' => '该通道的必填参数还没配置完，无法启用'];
            }
        }
        DB::conn()->update('payment_gateways', [
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => time(),
        ], 'code = ?', [$code]);

        // 停用的是当前默认通道 → 自动把默认让给另一个已启用的通道
        if (!$enabled && (int) $row['is_default'] === 1) {
            self::setDefault((string) ($row['code']) === 'mock' ? '' : ((self::enabledCodes()[0] ?? '')), true);
        }

        self::$cache = null;
        Audit::log('payment', $enabled ? 'gateway.enable' : 'gateway.disable', [
            'target' => $code,
            'detail' => ($enabled ? '启用' : '停用') . '支付通道：' . $row['name'],
        ]);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * 切换默认通道。
     * 用事务保证「全站只有一个默认」——先全部清零再置一，中间不会出现空档。
     */
    public static function setDefault(string $code): array
    {
        $row = self::findByCode($code);
        if ($row === null) {
            return ['ok' => false, 'error' => '通道不存在'];
        }
        if ((int) $row['enabled'] !== 1) {
            return ['ok' => false, 'error' => '请先启用该通道，再设为默认'];
        }

        DB::conn()->tx(static function (DB $db) use ($code) {
            $db->query('UPDATE payment_gateways SET is_default = 0');
            $db->query('UPDATE payment_gateways SET is_default = 1, updated_at = ? WHERE code = ?', [time(), $code]);
        });

        self::$cache = null;
        Audit::log('payment', 'gateway.default', [
            'target' => $code,
            'detail' => '切换默认支付通道为：' . $row['name'],
        ]);
        return ['ok' => true, 'error' => ''];
    }

    private static function enabledCodes(): array
    {
        return array_map(static fn($g) => (string) $g['code'], self::enabled());
    }

    // ================================================================ 工具

    /** 生成订单号：时间 + 随机，避免可预测（不能只用自增 ID） */
    public static function newOrderNo(): string
    {
        return date('YmdHis') . random_int(100000, 999999);
    }

    /** 配置里的密钥字段解密后使用；其余字段直接返回 */
    public static function decodeConfig(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    private static function defaultConfig(string $class): array
    {
        $out = [];
        foreach ($class::configSchema() as $field) {
            $out[$field['key']] = (string) ($field['default'] ?? '');
        }
        return $out;
    }

    /** 按配置声明给字段打码，用于界面回显 */
    public static function maskConfigForDisplay(string $code, array $config): array
    {
        $class = self::MAP[$code] ?? null;
        if ($class === null) {
            return $config;
        }
        $out = $config;
        foreach ($class::configSchema() as $field) {
            if (!empty($field['secret'])) {
                $key = $field['key'];
                $out[$key] = !empty($config[$key]) ? '••••••••' : '';
            }
        }
        return $out;
    }
}
