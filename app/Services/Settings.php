<?php
declare(strict_types=1);

namespace Vobl\Services;

use Vobl\Core\Audit;
use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;

/**
 * 运行时配置
 * ============================================================================
 * 与 config/config.php 的分工：
 *
 *   config.php  → 部署期机密（数据库、app_secret），改它要动服务器
 *   settings 表 → 运行期配置（SMTP、支付通道、登录策略），后台点点就能改
 *
 * 这样「改一个 SMTP 服务器」不需要重新部署 —— 这正是需求要的
 * 「所有功能均可在后台进行操作与管理」的基础。
 *
 * 敏感项（SMTP 密码、支付密钥）在库里是 AES-256-GCM 密文，
 * 密钥来自 config.php 的 config_key。列表回显时自动打码。
 * ============================================================================
 */
final class Settings
{
    private static ?array $cache = null;
    private static array $dirty = [];

    /** 敏感项清单。这些键的值会被加密存储，回显时打码 */
    public const SECRET_KEYS = [
        'mail.password',
        'payment.*.key',          // 通配：所有支付通道的密钥
        'payment.*.secret',
        'payment.*.private_key',
    ];

    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $rows = DB::conn()->all('SELECT `key`, `value`, `is_secret` FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        $value = $all[$key];

        // 敏感项是密文，取值时透明解密
        if (self::isSecretKey($key) && $value !== '' && str_starts_with($value, 'v1:')) {
            try {
                return Crypto::decrypt($value, (string) Config::require('keys.config_key'));
            } catch (\Throwable $e) {
                error_log('[settings] 解密失败 ' . $key . '：' . $e->getMessage());
                return $default;
            }
        }
        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, null);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 按组读取，后台表单用 */
    public static function group(string $group): array
    {
        $rows = DB::conn()->all(
            'SELECT `key`, `value`, `is_secret` FROM settings WHERE group_name = ? ORDER BY `key`',
            [$group]
        );
        $out = [];
        foreach ($rows as $row) {
            $k = (string) $row['key'];
            $isSecret = (int) $row['is_secret'] === 1;
            $raw = (string) $row['value'];
            $out[$k] = [
                'key'       => $k,
                'is_secret' => $isSecret,
                // 打码回显：只告诉管理员「已配置」，不把密钥送进 HTML
                'display'   => $isSecret && $raw !== '' ? '••••••••' : $raw,
                'has_value' => $raw !== '',
                'plain'     => $isSecret ? '' : $raw,
            ];
        }
        return $out;
    }

    /**
     * 批量保存。$data 是 key => value。
     *
     * ★ 关键约定：值等于打码占位符 '••••••••' 时表示「用户没改」，
     *   跳过不写。否则每次保存表单都会把密钥覆盖成一串圆点。
     */
    public static function putMany(array $data, string $group = 'general'): int
    {
        $db = DB::conn();
        $written = 0;
        $changed = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (!preg_match('/^[a-z0-9_.]{1,64}$/i', $key)) {
                continue;
            }
            $value = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

            if ($value === '••••••••') {
                continue;   // 未修改的敏感项
            }

            $isSecret = self::isSecretKey($key);
            if ($isSecret && $value !== '') {
                $value = Crypto::encrypt($value, (string) Config::require('keys.config_key'));
            }

            $exists = $db->one('SELECT `key` FROM settings WHERE `key` = ? LIMIT 1', [$key]);
            if ($exists === null) {
                $db->insert('settings', [
                    'key'        => $key,
                    'value'      => $value,
                    'group_name' => $group,
                    'is_secret'  => $isSecret ? 1 : 0,
                    'updated_at' => time(),
                ]);
            } else {
                $db->update('settings', ['value' => $value, 'updated_at' => time()], '`key` = ?', [$key]);
            }
            // 审计里记录键名与是否敏感，不记录值
            $changed[] = $key . ($isSecret ? '(密)' : '');
            $written++;
        }

        if ($written > 0) {
            self::$cache = null;    // 失效缓存
            Audit::log('admin', 'settings.update', [
                'detail' => '更新配置 ' . $written . ' 项',
                'data'   => ['keys' => implode(',', $changed)],
            ]);
        }
        return $written;
    }

    public static function put(string $key, $value, string $group = 'general'): void
    {
        self::putMany([$key => $value], $group);
    }

    /**
     * 判断是否敏感项。支持通配：payment.*.key 匹配 payment.epay.key
     */
    public static function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_KEYS as $pattern) {
            if ($pattern === $key) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '#^' . str_replace('\*', '[^.]+', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $key) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
