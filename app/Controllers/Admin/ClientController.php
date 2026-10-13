<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;

/**
 * 后台 · 接入应用管理
 * ============================================================================
 * 与用户侧的「开放平台」是同一批数据，区别是这里能看全部应用、
 * 能停用应用、能重置任意应用的密钥。
 *
 * 为什么停用比删除更重要：
 *   删除会连带清掉授权记录与订单关联，属于不可逆操作；
 *   停用只是让 /authorize 拒绝该应用，随时可恢复。
 *   所以界面上默认推荐停用。
 * ============================================================================
 */
final class ClientController extends BaseController
{
    public static function index(Request $request): void
    {
        Auth::requireCapability('client.view');

        $keyword = $request->str('q', '', 100);
        $where = [];
        $params = [];
        if ($keyword !== '') {
            $where[] = '(c.name LIKE ? OR c.client_id LIKE ? OR u.email LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        $sqlWhere = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $clients = DB::conn()->all(
            "SELECT c.*, u.email AS owner_email
             FROM oauth_clients c LEFT JOIN users u ON u.id = c.created_by
             {$sqlWhere} ORDER BY c.created_at DESC",
            $params
        );
        foreach ($clients as &$c) {
            $c['redirect_uris_arr'] = json_decode((string) $c['redirect_uris'], true) ?: [];
            $c['scopes_arr'] = json_decode((string) $c['scopes'], true) ?: [];
            $c['grant_count'] = DB::conn()->count(
                'SELECT COUNT(*) FROM oauth_grants WHERE client_id = ? AND revoked_at = 0',
                [(string) $c['client_id']]
            );
            $c['has_secret'] = (string) $c['client_secret'] !== '';
        }
        unset($c);

        self::adminPage('admin/clients', [
            'title'     => '接入应用',
            'clients'   => $clients,
            'keyword'   => $keyword,
            'canManage' => Auth::can('client.manage'),
            'flash'     => self::takenFlash(),
        ]);
    }

    public static function save(Request $request): void
    {
        Auth::requireCapability('client.manage');

        $clientId = $request->str('client_id', '', 64);
        $row = DB::conn()->one('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            Response::error('应用不存在', 404);
        }

        $name = $request->str('name', '', 60);
        if ($name === '') {
            Response::error('应用名称不能为空', 422);
        }

        $rawUris = $request->str('redirect_uris', '', 2000);
        $uris = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawUris) ?: [])));
        if ($uris === []) {
            Response::error('至少保留一个回调地址', 422);
        }
        foreach ($uris as $uri) {
            if (str_starts_with($uri, '/')) {
                continue;
            }
            $p = parse_url($uri);
            if ($p === false || !isset($p['scheme'], $p['host'])
                || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
                Response::error('回调地址格式不对：' . $uri, 422);
            }
        }

        $scopes = array_values(array_intersect($request->arr('scopes'), ['profile', 'email', 'orgs']));
        if ($scopes === []) {
            $scopes = ['profile'];
        }

        $status = $request->str('status', 'active', 20);
        if (!in_array($status, ['active', 'disabled'], true)) {
            $status = 'active';
        }

        DB::conn()->update('oauth_clients', [
            'name'          => $name,
            'description'   => $request->str('description', '', 160),
            'redirect_uris' => json_encode($uris, JSON_UNESCAPED_UNICODE),
            'scopes'        => json_encode($scopes, JSON_UNESCAPED_UNICODE),
            'homepage'      => $request->str('homepage', '', 255),
            'icon'          => $request->str('icon', '', 4),
            'accent'        => $request->str('accent', 'indigo', 16),
            'visible'       => $request->bool('visible', true) ? 1 : 0,
            'status'        => $status,
        ], 'client_id = ?', [$clientId]);

        Audit::log('admin', 'client.update', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '修改应用「' . $name . '」' . ($status === 'disabled' ? '（已停用）' : ''),
        ]);

        self::back('/admin/clients', '应用「' . $name . '」已更新');
    }

    public static function delete(Request $request): void
    {
        Auth::requireCapability('client.manage');

        $clientId = $request->str('client_id', '', 64);
        $row = DB::conn()->one('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            Response::error('应用不存在', 404);
        }

        $grants = DB::conn()->count('SELECT COUNT(*) FROM oauth_grants WHERE client_id = ?', [$clientId]);
        $force = $request->bool('force');

        // 有用户授权过的应用，删除前必须显式确认（避免误删导致用户被反复弹授权页）
        if ($grants > 0 && !$force) {
            Response::error(
                '该应用已被 ' . $grants . ' 个用户授权。如果只是暂时不想让它接入，建议改为「停用」；'
                . '确实要删除请再次确认。',
                409
            );
        }

        DB::conn()->tx(function (DB $db) use ($clientId) {
            $db->delete('oauth_grants', 'client_id = ?', [$clientId]);
            $db->delete('oauth_codes', 'client_id = ?', [$clientId]);
            $db->delete('oauth_clients', 'client_id = ?', [$clientId]);
        });

        Audit::log('admin', 'client.delete', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '删除应用「' . $row['name'] . '」，同时清理 ' . $grants . ' 条授权记录',
        ]);

        self::back('/admin/clients', '应用已删除');
    }

    public static function resetSecret(Request $request): void
    {
        Auth::requireCapability('client.manage');

        $clientId = $request->str('client_id', '', 64);
        $row = DB::conn()->one('SELECT * FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            Response::error('应用不存在', 404);
        }

        $secret = 'vsk_' . substr(Crypto::randHex(32), 0, 40);
        DB::conn()->update('oauth_clients', [
            'client_secret' => Crypto::hashPassword($secret),
            'type'          => 'confidential',
        ], 'client_id = ?', [$clientId]);

        Audit::log('admin', 'client.secret', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '管理员重置了应用「' . $row['name'] . '」的密钥',
        ]);

        Response::ok(['client_secret' => $secret], '新密钥已生成，旧密钥立即失效');
    }
}
