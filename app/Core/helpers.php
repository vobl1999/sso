<?php
declare(strict_types=1);

/**
 * 全局辅助函数
 * ============================================================================
 * 这些函数刻意定义在**全局命名空间**，而不是 Vobl\Core 里。
 *
 * 原因：视图模板文件（app/Views/**.php）不声明命名空间，位于全局作用域。
 * 如果 e() 定义在 Vobl\Core 下，模板里写 e($x) 会被解析成 \e($x) 而报
 * 「函数未定义」。定义在全局命名空间后：
 *   · 模板里写 e($x)          → 命中 \e
 *   · 类里写 e($x)（Vobl\Core）→ PHP 会先找 Vobl\Core\e，找不到就回退到 \e
 * 两种情况都能正常工作。
 * ============================================================================
 */

if (!function_exists('e')) {
    /**
     * HTML 转义。模板里输出任何用户数据都必须用它。
     * ENT_QUOTES 同时处理单双引号，ENT_SUBSTITUTE 让非法 UTF-8 变成替代字符而不是返回空串。
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('raw')) {
    /**
     * 原样输出 HTML。名字已经说明风险 ——
     * 只用于确实可信的片段（如已由服务端生成的 SVG 图标），绝不用来输出用户输入。
     */
    function raw(string $html): string
    {
        return $html;
    }
}

if (!function_exists('money')) {
    /** 分 → 元，带千分位 */
    function money(int $cents, bool $withSymbol = true): string
    {
        return ($withSymbol ? '¥' : '') . number_format($cents / 100, 2, '.', ',');
    }
}

if (!function_exists('dt')) {
    /** 时间戳格式化，0 显示为占位符 */
    function dt(int $ts, string $format = 'Y-m-d H:i'): string
    {
        return $ts > 0 ? date($format, $ts) : '—';
    }
}

if (!function_exists('ago')) {
    /** 相对时间 */
    function ago(int $ts): string
    {
        if ($ts <= 0) {
            return '—';
        }
        $d = time() - $ts;
        if ($d < 60) {
            return '刚刚';
        }
        if ($d < 3600) {
            return floor($d / 60) . ' 分钟前';
        }
        if ($d < 86400) {
            return floor($d / 3600) . ' 小时前';
        }
        if ($d < 86400 * 30) {
            return floor($d / 86400) . ' 天前';
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('mask_secret')) {
    /** 敏感值打码，用于日志与界面回显 */
    function mask_secret(string $value, int $keep = 4): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= $keep) {
            return str_repeat('•', $len);
        }
        return substr($value, 0, $keep) . str_repeat('•', min(16, $len - $keep));
    }
}
