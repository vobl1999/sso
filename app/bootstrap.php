<?php
declare(strict_types=1);

/**
 * Vobl ID · 应用引导
 * ============================================================================
 * 所有请求都从这里进入（public/index.php 只负责 include 本文件）。
 *
 * 安全前提（三条，缺一不可）：
 *   1. Web 根目录指向 public/，其余目录（app/ config/ storage/ sql/）不可直接访问
 *   2. config/config.php 里必须设置真实的 app_secret 与 config_key
 *   3. app.debug 生产环境必须为 false —— 否则报错页会暴露路径与 SQL
 * ============================================================================
 */

use Vobl\Core\Audit;
use Vobl\Core\Config;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;

define('VOBL_START', microtime(true));
define('VOBL_ROOT', dirname(__DIR__));

// ------------------------------------------------------------------ 错误处理
// 开发环境直接显示，生产环境只记日志、对外统一给友好页面（不泄露任何内部信息）
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ------------------------------------------------------------------ 自动加载
// 零依赖的 PSR-4 风格加载器：Vobl\Core\DB → app/Core/DB.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vobl\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = VOBL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 全局辅助函数（e / money / dt / ago …）
// ★ 必须显式加载：这些函数定义在全局命名空间，不参与类的自动加载。
//   模板文件也是全局作用域，所以它们能被模板直接调用。
require_once VOBL_ROOT . '/app/Core/helpers.php';

// ------------------------------------------------------------------ 载入配置
$configFile = VOBL_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>尚未安装</title>'
        . '<div style="font:15px/1.8 -apple-system,Segoe UI,PingFang SC,sans-serif;max-width:600px;'
        . 'margin:80px auto;padding:30px;border:1px solid #e5e7eb;border-radius:14px;color:#1f1710">'
        . '<h2 style="margin:0 0 14px">还差一步就能启动</h2>'
        . '<p style="color:#6b7280;margin:0 0 16px">没有找到配置文件。请执行：</p>'
        . '<pre style="background:#f6f7f9;padding:14px 16px;border-radius:10px;overflow:auto;'
        . 'font-size:13.5px">cp config/config.sample.php config/config.php</pre>'
        . '<p style="color:#6b7280;margin:16px 0 0">然后填好数据库连接与两个密钥，再刷新本页。</p>'
        . '</div>';
    exit;
}

$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    exit('config/config.php 必须返回一个数组');
}
Config::load($config);

// 时区
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));

// ------------------------------------------------------------------ 密钥自检
// 密钥还是占位符说明没配置。这种情况必须立刻停下 ——
// 用一个公开的默认密钥跑起来的会话系统等于没有认证。
$appSecret = (string) Config::get('keys.app_secret', '');
$configKey = (string) Config::get('keys.config_key', '');
if (Config::isDebug()) {
    ini_set('display_errors', '1');
}
if (str_contains($appSecret, 'CHANGE_ME') || strlen($appSecret) < 32) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>密钥未配置</title>'
        . '<div style="font:15px/1.8 -apple-system,Segoe UI,PingFang SC,sans-serif;max-width:620px;'
        . 'margin:80px auto;padding:30px;border:1px solid #f0c6c6;border-radius:14px;color:#1f1710">'
        . '<h2 style="margin:0 0 14px">应用密钥还没有配置</h2>'
        . '<p style="color:#6b7280;margin:0 0 14px">打开 <code>config/config.php</code>，把 '
        . '<code>keys.app_secret</code> 和 <code>keys.config_key</code> 换成随机字符串。</p>'
        . '<p style="color:#6b7280;margin:0 0 10px">生成方式（命令行执行一次）：</p>'
        . '<pre style="background:#f6f7f9;padding:14px 16px;border-radius:10px;overflow:auto;'
        . 'font-size:13.5px">php -r "echo bin2hex(random_bytes(32));"</pre>'
        . '<p style="color:#b34e08;font-size:13.5px;margin:16px 0 0">这两个值一旦泄露，'
        . '任何人都能伪造登录会话，请务必保密、不要提交到 Git 仓库。</p>'
        . '</div>';
    exit;
}

// ------------------------------------------------------------------ 数据库
try {
    DB::init((array) Config::require('db'));
} catch (\Throwable $e) {
    http_response_code(500);
    $message = Config::isDebug() ? $e->getMessage() : '数据库连接失败';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>数据库连接失败</title>'
        . '<div style="font:15px/1.8 -apple-system,Segoe UI,PingFang SC,sans-serif;max-width:620px;'
        . 'margin:80px auto;padding:30px;border:1px solid #f0c6c6;border-radius:14px">'
        . '<h2 style="margin:0 0 14px">连不上数据库</h2>'
        . '<p style="color:#6b7280">' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<p style="color:#6b7280;font-size:13.5px;margin-top:14px">请检查 config/config.php 里的 '
        . 'db 配置：主机、端口、库名、用户名、密码。宝塔面板里创建的数据库名通常带下划线前缀。</p>'
        . '</div>';
    exit;
}

// ------------------------------------------------------------------ 全局异常兜底
set_exception_handler(static function (\Throwable $e): void {
    error_log('[vobl] 未捕获异常：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    try {
        Audit::log('security', 'exception', [
            'result' => 'fail',
            'detail' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    } catch (\Throwable $ignore) {
        // 审计本身失败时不再抛
    }

    if (Config::isDebug()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<pre style="font:13px/1.7 ui-monospace,monospace;padding:20px;background:#1a1a1a;color:#e8e8e8">';
        echo htmlspecialchars((string) $e, ENT_QUOTES);
        echo '</pre>';
        return;
    }

    if (Request::capture()->wantsJson()) {
        Response::error('服务器开小差了，请稍后重试', 500, 'internal_error');
    }
    Response::html(
        '<!doctype html><meta charset="utf-8"><title>出错了</title>'
        . '<div style="font:15px/1.8 -apple-system,Segoe UI,PingFang SC,sans-serif;max-width:520px;'
        . 'margin:80px auto;padding:30px;border:1px solid #e5e7eb;border-radius:14px;text-align:center">'
        . '<h2 style="margin:0 0 10px">服务器开小差了</h2>'
        . '<p style="color:#6b7280;margin:0 0 20px">问题已经记录到日志，管理员会尽快处理。</p>'
        . '<a href="/" style="color:#ee7318;font-weight:600">返回首页</a></div>',
        500
    );
});

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[vobl] 致命错误：' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);
    }
});

// ------------------------------------------------------------------ 模板共享变量
\Vobl\Core\View::share('config', $config);
