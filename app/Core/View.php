<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 模板渲染。纯 PHP 模板 + 布局。
 *
 * 默认转义：模板里输出用户数据一律用 e()，不要直接 echo。
 * 需要输出富文本时才用 raw()，并且必须已经过白名单过滤。
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], string $layout = null): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, array_merge($data, ['content' => $content]));
    }

    /** 渲染并直接输出 */
    public static function display(string $template, array $data = [], string $layout = null): void
    {
        Response::html(self::render($template, $data, $layout));
    }

    private static function capture(string $template, array $data): string
    {
        $file = self::resolve($template);
        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    private static function resolve(string $template): string
    {
        $base = dirname(__DIR__) . '/Views/';
        $file = $base . str_replace('..', '', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("模板不存在：{$template}");
        }
        return $file;
    }
}

/*
 * 视图辅助函数（e / raw / money / dt / ago）已移到 app/Core/helpers.php，
 * 并定义在全局命名空间 —— 这样模板文件（全局作用域）才能直接调用。
 * 详见该文件头部的说明。
 */
