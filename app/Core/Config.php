<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 配置读取（不可变，启动时一次性载入）
 */
final class Config
{
    private static array $data = [];

    public static function load(array $data): void
    {
        self::$data = $data;
    }

    /** 点号路径读取，如 Config::get('db.host') */
    public static function get(string $path, $default = null)
    {
        $node = self::$data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    public static function require(string $path)
    {
        $v = self::get($path);
        if ($v === null || $v === '' || $v === []) {
            throw new \RuntimeException("缺少必需配置项：{$path}（请检查 config/config.php）");
        }
        return $v;
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }
}
