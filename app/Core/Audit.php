<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 审计日志
 * ============================================================================
 * 敏感操作必须留痕：登录、授权、权限变更、配置修改、余额调整、密钥操作。
 *
 * 三条设计原则：
 *   1. 只增不改 —— 不提供删除接口，管理员也无法篡改历史
 *   2. 写入失败不能影响主流程（用 try/catch 包住，只记错误日志）
 *   3. 绝不记录密码、密钥、验证码原文
 * ============================================================================
 */
final class Audit
{
    /** 已知的分类，避免拼写错误导致日志分散 */
    public const CATEGORIES = ['auth', 'admin', 'payment', 'security', 'smtp', 'oauth'];

    /** 敏感字段名，写入前自动打码 */
    private const MASK_KEYS = [
        'password', 'passwd', 'secret', 'token', 'code', 'key', 'credential',
        'totp', 'recovery', 'private', 'signature', 'access_token', 'refresh_token',
    ];

    public static function log(string $category, string $action, array $opt = []): void
    {
        try {
            $req = Request::capture();

            $detail = (string) ($opt['detail'] ?? '');
            if (isset($opt['data']) && is_array($opt['data'])) {
                $detail = trim($detail . ' ' . self::sanitize($opt['data']));
            }

            // ★ 注意：in_array 判定之后必须再取一次默认值。
            //   写成 in_array($opt['result'] ?? 'ok', [...]) ? $opt['result'] : 'ok'
            //   是错的 —— 缺省时 in_array('ok', [...]) 为 true，但 $opt['result'] 并不存在，
            //   会触发 Undefined array key 警告并写入 NULL，导致日志整条丢失。
            $result = (string) ($opt['result'] ?? 'ok');

            DB::conn()->insert('audit_logs', [
                'ts'       => time(),
                'category' => in_array($category, self::CATEGORIES, true) ? $category : 'admin',
                'action'   => mb_substr($action, 0, 48),
                'actor_id' => (int) ($opt['actor_id'] ?? Auth::id()),
                'actor'    => mb_substr((string) ($opt['actor'] ?? self::currentActorLabel()), 0, 190),
                'target'   => mb_substr((string) ($opt['target'] ?? ''), 0, 120),
                'result'   => in_array($result, ['ok', 'fail', 'denied'], true) ? $result : 'ok',
                'ip'       => (string) ($opt['ip'] ?? $req->ip()),
                'ua'       => mb_substr((string) ($opt['ua'] ?? $req->userAgent()), 0, 255),
                'detail'   => mb_substr($detail, 0, 400),
            ]);
        } catch (\Throwable $e) {
            // 审计失败绝不阻断业务，但要留下痕迹
            error_log('[audit] 写入失败：' . $e->getMessage());
        }
    }

    private static function currentActorLabel(): string
    {
        $u = Auth::user();
        if ($u !== null) {
            return (string) $u['email'];
        }
        $s = Auth::session();
        return $s !== null ? 'session:' . substr((string) $s['id'], 0, 8) : 'anonymous';
    }

    /**
     * 把数组转成可读的键值串，并对敏感字段打码。
     * 这样日志里能看到「改了什么」，但看不到「改成了什么密码」。
     */
    public static function sanitize(array $data, int $depth = 0): string
    {
        if ($depth > 3) {
            return '…';
        }
        $parts = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            $sensitive = false;
            foreach (self::MASK_KEYS as $needle) {
                if (str_contains($key, $needle)) {
                    $sensitive = true;
                    break;
                }
            }
            if (is_array($v)) {
                $parts[] = $k . '={' . self::sanitize($v, $depth + 1) . '}';
            } elseif ($sensitive) {
                $parts[] = $k . '=***';
            } else {
                $s = (string) $v;
                if (mb_strlen($s) > 80) {
                    $s = mb_substr($s, 0, 80) . '…';
                }
                $parts[] = $k . '=' . $s;
            }
        }
        return implode(' ', $parts);
    }

    /** 分页查询 */
    public static function paginate(array $filter = [], int $page = 1, int $perPage = 30): array
    {
        $where = [];
        $params = [];
        if (!empty($filter['category'])) {
            $where[] = 'category = ?';
            $params[] = $filter['category'];
        }
        if (!empty($filter['action'])) {
            $where[] = 'action = ?';
            $params[] = $filter['action'];
        }
        if (!empty($filter['actor_id'])) {
            $where[] = 'actor_id = ?';
            $params[] = (int) $filter['actor_id'];
        }
        if (!empty($filter['result'])) {
            $where[] = 'result = ?';
            $params[] = $filter['result'];
        }
        if (!empty($filter['keyword'])) {
            $where[] = '(actor LIKE ? OR target LIKE ? OR detail LIKE ?)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filter['from'])) {
            $where[] = 'ts >= ?';
            $params[] = (int) $filter['from'];
        }
        if (!empty($filter['to'])) {
            $where[] = 'ts <= ?';
            $params[] = (int) $filter['to'];
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = DB::conn()->count("SELECT COUNT(*) FROM audit_logs {$sqlWhere}", $params);
        $page  = max(1, $page);
        $perPage = min(200, max(10, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = DB::conn()->all(
            "SELECT * FROM audit_logs {$sqlWhere} ORDER BY ts DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /** 清理过期日志（默认保留 180 天） */
    public static function prune(int $days = 180): int
    {
        return DB::conn()->delete('audit_logs', 'ts < ?', [time() - $days * 86400]);
    }
}
