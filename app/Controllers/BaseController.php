<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Auth;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Core\View;
use Vobl\Services\Settings;

/**
 * 控制器基类。所有 Action 都是静态方法 —— 路由直接用 [Class::class, 'method'] 调用，
 * 不需要容器，也不需要为每个请求 new 一个对象。
 */
abstract class BaseController
{
    /**
     * 只允许跳回站内，防止开放重定向。
     * ★ 这个校验必须做：/login?next=https://evil.com 是很常见的钓鱼手法。
     */
    protected static function safeNext(string $next, string $fallback = '/developer'): string
    {
        $next = trim($next);
        if ($next === '') {
            return $fallback;
        }
        // 只接受以单个 / 开头的相对路径
        if ($next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n") || str_contains($next, "\r")) {
            return $fallback;
        }
        // 过滤掉控制字符与反斜杠（部分浏览器会把 \ 当路径分隔符）
        if (preg_match('#[\x00-\x1F\x7F\\\\]#', $next) === 1) {
            return $fallback;
        }
        return $next;
    }

    /** 渲染页面（带布局） */
    protected static function page(string $template, array $data = [], string $layout = 'layout/auth'): void
    {
        $data['site'] = [
            'name' => (string) Settings::get('site.name', 'Vobl ID'),
            'url'  => (string) Settings::get('site.url', ''),
            'icp'  => (string) Settings::get('site.icp', ''),
        ];
        $data['currentUser'] = Auth::user();
        Response::html(View::render($template, $data, $layout));
    }

    protected static function adminPage(string $template, array $data = []): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            Response::redirect('/developer');
        }
        // ★ 变量名刻意叫 userCaps 而不是 capabilities。
        //   之前叫 capabilities 时踩了坑：它会把页面自己传进来的
        //   'capabilities'（例如角色页需要的「能力位目录」）覆盖掉，
        //   结果模板里对同一个名字的两种期望打架 —— 角色页拿到的是
        //   当前用户的扁平权限列表，再 foreach 就会得到「对字符串做 foreach」的致命错误。
        $data['userCaps'] = Auth::capabilities();
        self::page($template, $data, 'layout/admin');
    }

    /** 表单提交后的「回跳 + 提示」模式：不用 JS 也能给出明确反馈 */
    protected static function back(string $url, string $message, string $type = 'ok'): void
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . 'flash=' . urlencode($message) . '&flash_type=' . urlencode($type);
        Response::redirect($url);
    }

    protected static function takenFlash(): ?array
    {
        $msg = (string) ($_GET['flash'] ?? '');
        if ($msg === '') {
            return null;
        }
        return [
            'message' => $msg,
            'type'    => in_array(($_GET['flash_type'] ?? ''), ['ok', 'err', 'warn', 'info'], true)
                            ? (string) $_GET['flash_type'] : 'ok',
        ];
    }

    /** 分页参数 */
    protected static function pageNo(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    /** 统一的「参数校验失败」响应 */
    protected static function invalid(Request $request, string $message): void
    {
        if ($request->wantsJson()) {
            Response::error($message, 422, 'invalid_input');
        }
        self::back($_SERVER['HTTP_REFERER'] ?? '/', $message, 'err');
    }
}
