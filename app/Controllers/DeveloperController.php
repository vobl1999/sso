<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Settings;
use Vobl\Services\UserService;
use Vobl\Services\Wallet;

/**
 * 开放平台 · 应用管理
 * ============================================================================
 * 用户可以在这里自助创建应用、拿到 client_id（与一次性密钥），
 * 把 Vobl ID 接进自己的站点。也就是「成为接入方」的入口。
 *
 * 权限模型：
 *   普通用户 → 只能看到并管理自己创建的应用
 *   有 client.view 能力位 → 能看到全部应用（后台用）
 *
 * ★ 密钥只存 password_hash，只在创建 / 重置时显示一次明文。
 *   这不是「用户体验差」，而是「数据库泄露时不至于全线失守」的必要代价。
 * ============================================================================
 */
final class DeveloperController extends BaseController
{
    private const ACCENTS = ['indigo', 'violet', 'cyan', 'sky', 'emerald', 'amber', 'rose', 'slate'];
    private const SCOPE_ALL = ['profile', 'email', 'orgs'];

    public static function index(Request $request): void
    {
        Auth::requireLogin();
        $userId = Auth::id();

        $userIdStr = (string) $userId;
        $clients = DB::conn()->all(
            'SELECT * FROM oauth_clients WHERE created_by = ? ORDER BY created_at DESC',
            [$userId]
        );
        foreach ($clients as &$c) {
            $c['redirect_uris'] = json_decode((string) $c['redirect_uris'], true) ?: [];
            $c['scopes'] = json_decode((string) $c['scopes'], true) ?: ['profile'];
        }
        unset($c);

        // 每个应用的授权人数，让用户知道自己的应用有多少人在用
        foreach ($clients as &$c) {
            $c['grant_count'] = DB::conn()->count(
                'SELECT COUNT(*) FROM oauth_grants WHERE client_id = ? AND revoked_at = 0',
                [(string) $c['client_id']]
            );
        }
        unset($c);

        $grants = DB::conn()->all(
            'SELECT g.client_id, g.scopes, g.granted_at, c.name, c.icon, c.accent
             FROM oauth_grants g
             LEFT JOIN oauth_clients c ON c.client_id = g.client_id
             WHERE g.user_id = ? AND g.revoked_at = 0
             ORDER BY g.granted_at DESC',
            [$userId]
        );

        self::page('developer/index', [
            'user'        => Auth::user(),
            'clients'     => $clients,
            'grants'      => $grants,
            'wallet'      => Wallet::of($userId),
            'recentTx'    => Wallet::transactions($userId, 1, 5)['rows'],
            'accents'     => self::ACCENTS,
            'scopeAll'    => self::SCOPE_ALL,
            'siteOrigin'  => (string) Settings::get('site.url', $request->origin()),
            'flash'       => self::takenFlash(),
        ]);
    }

    // ================================================================ 创建应用

    public static function create(Request $request): void
    {
        Auth::requireLogin();

        $validated = self::validate($request);
        if (!$validated['ok']) {
            Response::error($validated['error'], 422);
        }
        $data = $validated['data'];

        $clientId = self::newClientId($data['name']);
        $secret   = $data['type'] === 'confidential' ? self::newSecret() : '';

        DB::conn()->insert('oauth_clients', [
            'client_id'     => $clientId,
            'client_secret' => $secret !== '' ? Crypto::hashPassword($secret) : '',
            'name'          => $data['name'],
            'description'   => $data['description'],
            'redirect_uris' => json_encode($data['redirect_uris'], JSON_UNESCAPED_UNICODE),
            'scopes'        => json_encode($data['scopes'], JSON_UNESCAPED_UNICODE),
            'homepage'      => $data['homepage'],
            'icon'          => $data['icon'],
            'accent'        => $data['accent'],
            'type'          => $data['type'],
            'visible'       => $data['visible'] ? 1 : 0,
            'status'        => 'active',
            'created_by'    => Auth::id(),
            'created_at'    => time(),
        ]);

        Audit::log('oauth', 'client.create', [
            'actor_id' => Auth::id(),
            'target'   => $clientId,
            'detail'   => '创建应用「' . $data['name'] . '」',
        ]);

        Response::ok([
            'client_id'     => $clientId,
            'client_secret' => $secret,     // 只在这一个响应里出现
            'redirect'      => '/developer?flash=' . urlencode('应用「' . $data['name'] . '」创建成功'),
        ], $secret !== '' ? '应用已创建，请立即保存密钥（只显示这一次）' : '应用已创建');
    }

    // ================================================================ 修改应用

    public static function update(Request $request): void
    {
        Auth::requireLogin();

        $clientId = $request->str('client_id', '', 64);
        $row = self::ownedClient($clientId);
        if ($row === null) {
            Response::error('应用不存在或你没有权限修改', 404);
        }

        $validated = self::validate($request);
        if (!$validated['ok']) {
            Response::error($validated['error'], 422);
        }
        $data = $validated['data'];

        DB::conn()->update('oauth_clients', [
            'name'          => $data['name'],
            'description'   => $data['description'],
            'redirect_uris' => json_encode($data['redirect_uris'], JSON_UNESCAPED_UNICODE),
            'scopes'        => json_encode($data['scopes'], JSON_UNESCAPED_UNICODE),
            'homepage'      => $data['homepage'],
            'icon'          => $data['icon'],
            'accent'        => $data['accent'],
            'visible'       => $data['visible'] ? 1 : 0,
            // 类型切换时密钥处理：改成公开客户端就清掉密钥（它不再需要）
            'client_secret' => $data['type'] === 'confidential'
                ? (string) $row['client_secret'] : '',
            'type'          => $data['type'],
        ], 'client_id = ?', [$clientId]);

        Audit::log('oauth', 'client.update', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '修改应用「' . $data['name'] . '」',
        ]);

        self::back('/developer', '应用已更新');
    }

    public static function delete(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('删除应用', '/developer');

        $clientId = $request->str('client_id', '', 64);
        $row = self::ownedClient($clientId);
        if ($row === null) {
            Response::error('应用不存在或你没有权限删除', 404);
        }

        // 一起清掉授权记录与未使用的授权码，避免留下悬空数据
        DB::conn()->tx(function (DB $db) use ($clientId) {
            $db->delete('oauth_grants', 'client_id = ?', [$clientId]);
            $db->delete('oauth_codes', 'client_id = ?', [$clientId]);
            $db->delete('oauth_clients', 'client_id = ?', [$clientId]);
        });

        Audit::log('oauth', 'client.delete', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '删除应用「' . $row['name'] . '」',
        ]);

        self::back('/developer', '应用「' . $row['name'] . '」已删除');
    }

    public static function resetSecret(Request $request): void
    {
        Auth::requireLogin();
        Auth::requireConfirm('重置应用密钥', '/developer');

        $clientId = $request->str('client_id', '', 64);
        $row = self::ownedClient($clientId);
        if ($row === null) {
            Response::error('应用不存在或你没有权限操作', 404);
        }

        $secret = self::newSecret();
        DB::conn()->update('oauth_clients', [
            'client_secret' => Crypto::hashPassword($secret),
            'type'          => 'confidential',
        ], 'client_id = ?', [$clientId]);

        Audit::log('oauth', 'client.secret', [
            'actor_id' => Auth::id(), 'target' => $clientId,
            'detail'   => '重置应用「' . $row['name'] . '」的密钥',
        ]);

        Response::ok([
            'client_secret' => $secret,
        ], '新密钥已生成，旧密钥立即失效，请立即保存');
    }

    // ================================================================ 工具

    /** 只能操作自己创建的应用。返回 null 表示无权限 */
    private static function ownedClient(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }
        return DB::conn()->one(
            'SELECT * FROM oauth_clients WHERE client_id = ? AND created_by = ? LIMIT 1',
            [$clientId, Auth::id()]
        );
    }

    private static function validate(Request $request): array
    {
        $name = $request->str('name', '', 60);
        if ($name === '') {
            return ['ok' => false, 'error' => '请填写应用名称'];
        }

        // 回调地址：支持换行或逗号分隔
        $rawUris = $request->str('redirect_uris', '', 2000);
        $uris = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawUris) ?: [])));
        if ($uris === []) {
            return ['ok' => false, 'error' => '至少填写一个回调地址'];
        }
        if (count($uris) > 8) {
            return ['ok' => false, 'error' => '回调地址最多 8 个'];
        }
        foreach ($uris as $uri) {
            if (str_starts_with($uri, '/')) {
                continue;   // 相对地址用于同站应用
            }
            $parts = parse_url($uri);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                return ['ok' => false, 'error' => '回调地址格式不对：' . $uri];
            }
            if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return ['ok' => false, 'error' => '回调地址只支持 http / https：' . $uri];
            }
            if (strlen($uri) > 500) {
                return ['ok' => false, 'error' => '回调地址过长：' . mb_substr($uri, 0, 40) . '…'];
            }
        }

        $homepage = $request->str('homepage', '', 255);
        if ($homepage !== '') {
            $hp = parse_url($homepage);
            if ($hp === false || !isset($hp['scheme'], $hp['host'])) {
                return ['ok' => false, 'error' => '主页地址格式不对'];
            }
        }

        $scopes = array_values(array_intersect($request->arr('scopes'), self::SCOPE_ALL));
        if ($scopes === []) {
            $scopes = ['profile'];
        }

        $accent = $request->str('accent', 'indigo', 16);
        if (!in_array($accent, self::ACCENTS, true)) {
            $accent = 'indigo';
        }

        $icon = $request->str('icon', '', 4);
        if ($icon === '') {
            $icon = mb_strtoupper(mb_substr($name, 0, 1));
        }

        return ['ok' => true, 'error' => '', 'data' => [
            'name'          => $name,
            'description'   => $request->str('description', '', 160),
            'redirect_uris' => $uris,
            'scopes'        => $scopes,
            'homepage'      => $homepage,
            'icon'          => $icon,
            'accent'        => $accent,
            'type'          => $request->str('type', 'confidential', 20) === 'public' ? 'public' : 'confidential',
            'visible'       => $request->bool('visible', true),
        ]];
    }

    private static function newClientId(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name, 'UTF-8')) ?? '';
        $slug = trim((string) $slug, '-');
        $slug = mb_substr($slug !== '' ? $slug : 'app', 0, 20);
        return $slug . '-' . substr(Crypto::randHex(4), 0, 8);
    }

    private static function newSecret(): string
    {
        return 'vsk_' . substr(Crypto::randHex(32), 0, 40);
    }
}
