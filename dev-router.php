<?php
/**
 * 本地开发路由脚本（PHP 内置服务器）
 * ============================================================================
 * ⚠️ 必须放在 **docroot 之外**。
 *    如果把路由脚本放进 public/ 里，PHP 内置服务器会把它当静态文件直接输出
 *    （浏览器里会看到源码），而不是执行它 —— 这是内置服务器的一个已知行为。
 *
 * 启动：
 *   php -S 127.0.0.1:8899 -t public dev-router.php
 * ============================================================================
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;

// 已存在的静态文件交给内置服务器处理（返回 false 即为「不接管」）
if ($path !== '/' && is_file($file)) {
    return false;
}

// 目录访问：优先 index.php
if ($path !== '/' && is_dir($file)) {
    $index = rtrim($file, '/') . '/index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

require __DIR__ . '/public/index.php';
return true;
