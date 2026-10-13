<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Request;
use Vobl\Core\Response;

/**
 * 后台 · 审计日志
 * ============================================================================
 * 只读。不提供任何删除或修改接口 —— 能篡改的日志等于没有日志。
 * 唯一的清理入口是按保留天数批量清理（Audit::prune），且会自己留下一条记录。
 * ============================================================================
 */
final class AuditController extends BaseController
{
    /** 分类的中文名与配色，界面上直接映射 */
    public const CATEGORY_LABELS = [
        'auth'     => '登录认证',
        'admin'    => '后台操作',
        'security' => '安全事件',
        'payment'  => '支付充值',
        'smtp'     => '邮件发送',
        'oauth'    => '单点登录',
    ];

    private static function buildFilter(Request $request): array
    {
        $filter = [];
        $category = $request->str('category', '', 20);
        if ($category !== '' && isset(self::CATEGORY_LABELS[$category])) {
            $filter['category'] = $category;
        }
        $result = $request->str('result', '', 20);
        if (in_array($result, ['ok', 'fail', 'denied'], true)) {
            $filter['result'] = $result;
        }
        $keyword = $request->str('q', '', 100);
        if ($keyword !== '') {
            $filter['keyword'] = $keyword;
        }
        $days = $request->int('days', 0);
        if ($days > 0) {
            $filter['from'] = time() - $days * 86400;
        }
        return $filter;
    }

    public static function index(Request $request): void
    {
        Auth::requireCapability('audit.view');

        $filter = self::buildFilter($request);
        $page = self::pageNo($request);
        $data = Audit::paginate($filter, $page, 40);

        self::adminPage('admin/audit', [
            'title'     => '审计日志',
            'events'    => $data['rows'],
            'total'     => $data['total'],
            'page'      => $data['page'],
            'pages'     => $data['pages'],
            'filter'    => $filter,
            'category'  => $request->str('category', '', 20),
            'result'    => $request->str('result', '', 20),
            'keyword'   => $request->str('q', '', 100),
            'days'      => $request->int('days', 0),
            'labels'    => self::CATEGORY_LABELS,
            'canExport' => Auth::can('audit.export'),
            'flash'     => self::takenFlash(),
        ]);
    }

    /**
     * 导出 CSV。
     * ★ CSV 注入防护：以 = + - @ 开头的单元格会被 Excel 当公式执行，
     *   必须在前面加一个单引号。这个漏洞常被忽略但危害很实际
     *   （管理员打开导出文件就会执行恶意公式）。
     */
    public static function export(Request $request): void
    {
        Auth::requireCapability('audit.export');

        $filter = self::buildFilter($request);
        $data = Audit::paginate($filter, 1, 200);

        Audit::log('admin', 'audit.export', [
            'actor_id' => Auth::id(),
            'detail'   => '导出审计日志 ' . count($data['rows']) . ' 条',
        ]);

        $rows = [];
        $rows[] = ['时间', '分类', '操作', '操作人', '目标', '结果', 'IP', '设备', '详情'];
        foreach ($data['rows'] as $e) {
            $rows[] = [
                date('Y-m-d H:i:s', (int) $e['ts']),
                self::CATEGORY_LABELS[(string) $e['category']] ?? (string) $e['category'],
                (string) $e['action'],
                (string) $e['actor'],
                (string) $e['target'],
                ['ok' => '成功', 'fail' => '失败', 'denied' => '被拒绝'][(string) $e['result']] ?? (string) $e['result'],
                (string) $e['ip'],
                (string) $e['ua'],
                (string) $e['detail'],
            ];
        }

        $filename = 'audit-' . date('Ymd-His') . '.csv';

        // 加 UTF-8 BOM，否则 Excel 打开中文会乱码
        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map([self::class, 'csvCell'], $row)) . "\r\n";
        }

        Response::text($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    private static function csvCell(string $value): string
    {
        // 防 CSV 公式注入
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            $value = "'" . $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
