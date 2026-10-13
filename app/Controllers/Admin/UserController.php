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
use Vobl\Services\Wallet;
use Vobl\Services\WebAuthn;

/**
 * 后台 · 用户管理
 * ============================================================================
 * 两条写死在服务端的硬规则（不依赖界面置灰）：
 *
 *   ① 管理员不能给自己加权限
 *      否则一个「运营」角色可以把自己提成超管，权限体系就形同虚设。
 *
 *   ② 不能删除/降级最后一个超级管理员
 *      否则系统会变成没人能管理，只能改数据库救。
 * ============================================================================
 */
final class UserController extends BaseController
{
    public static function index(Request $request): void
    {
        Auth::requireCapability('user.view');

        $keyword = $request->str('q', '', 100);
        $status  = $request->str('status', '', 20);
        $page    = self::pageNo($request);

        $where = [];
        $params = [];
        if ($keyword !== '') {
            $where[] = '(email LIKE ? OR display_name LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        if (in_array($status, ['active', 'locked', 'disabled'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sqlWhere = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $perPage = 20;
        $total = DB::conn()->count("SELECT COUNT(*) FROM users {$sqlWhere}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $users = DB::conn()->all(
            "SELECT id, email, email_verified, display_name, status, totp_enabled,
                    failed_count, locked_until, last_login_at, last_login_ip, created_at
             FROM users {$sqlWhere}
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // 附上角色与余额，列表里就能一次看全
        foreach ($users as &$u) {
            $u['roles'] = DB::conn()->all(
                'SELECT r.id, r.code, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
                [(int) $u['id']]
            );
            $u['balance'] = Wallet::balance((int) $u['id']);
            $u['passkeys'] = WebAuthn::countFor((int) $u['id']);
        }
        unset($u);

        self::adminPage('admin/users', [
            'title'   => '用户管理',
            'users'   => $users,
            'roles'   => DB::conn()->all('SELECT * FROM roles ORDER BY id'),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'keyword' => $keyword,
            'status'  => $status,
            'flash'   => self::takenFlash(),
        ]);
    }

    public static function detail(Request $request): void
    {
        Auth::requireCapability('user.view');
        $userId = $request->int('id');
        $user = $userId > 0 ? DB::conn()->one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user === null) {
            Response::error('用户不存在', 404);
        }

        Response::ok([
            'user'        => $user,
            'roles'       => DB::conn()->all(
                'SELECT r.id, r.code, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
                [$userId]
            ),
            'identities'  => \Vobl\Services\UserService::identitiesOf($userId),
            'passkeys'    => WebAuthn::listFor($userId),
            'wallet'      => Wallet::of($userId),
            'sessions'    => DB::conn()->all(
                'SELECT id, ip, device, last_seen FROM sessions
                 WHERE user_id = ? AND revoked_at = 0 AND expires_at > ?',
                [$userId, time()]
            ),
        ]);
    }

    // ================================================================ 状态

    public static function status(Request $request): void
    {
        Auth::requireCapability('user.lock');

        $userId = $request->int('id');
        $action = $request->str('action', '', 20);
        $user = $userId > 0 ? DB::conn()->one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user === null) {
            Response::error('用户不存在', 404);
        }

        // ★ 规则①：不能操作自己（避免管理员误锁自己导致全站失联）
        if ($userId === Auth::id() && in_array($action, ['lock', 'disable'], true)) {
            Response::error('不能锁定或停用自己的账号', 422);
        }

        // ★ 规则②：不能停用最后一个超级管理员
        if (in_array($action, ['lock', 'disable'], true) && self::isSuperAdmin($userId) && Auth::superAdminCount() <= 1) {
            Response::error('这是最后一个超级管理员，不能锁定或停用', 422);
        }

        $update = ['updated_at' => time()];
        $detail = '';
        switch ($action) {
            case 'lock':
                $update['status'] = 'locked';
                $update['locked_until'] = time() + 3600;
                $detail = '锁定账号 1 小时';
                break;
            case 'unlock':
                $update['status'] = 'active';
                $update['locked_until'] = 0;
                $update['failed_count'] = 0;
                $detail = '解锁账号并清零失败计数';
                break;
            case 'disable':
                $update['status'] = 'disabled';
                $detail = '停用账号';
                break;
            case 'enable':
                $update['status'] = 'active';
                $detail = '启用账号';
                break;
            default:
                Response::error('不支持的操作', 422);
        }

        DB::conn()->update('users', $update, 'id = ?', [$userId]);

        // 锁定/停用后把该用户所有会话踢掉，否则他手上已有的会话仍然有效
        if (in_array($action, ['lock', 'disable'], true)) {
            Auth::revokeAll($userId);
        }

        Audit::log('admin', 'user.status', [
            'actor_id' => Auth::id(),
            'target'   => 'user:' . $userId,
            'detail'   => $detail . '（' . $user['email'] . '）',
        ]);

        self::back('/admin/users', $detail . '成功');
    }

    // ================================================================ 角色

    public static function saveRoles(Request $request): void
    {
        Auth::requireCapability('role.manage');

        $userId = $request->int('id');
        $roleIds = array_map('intval', $request->arr('roles'));
        $user = $userId > 0 ? DB::conn()->one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user === null) {
            Response::error('用户不存在', 404);
        }

        // ★ 规则①：管理员不能给自己加权限
        if ($userId === Auth::id()) {
            Response::error('出于安全考虑，你不能修改自己的角色权限。请让其他管理员操作。', 422);
        }

        // 校验角色都存在
        $valid = [];
        if ($roleIds !== []) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $valid = array_map(
                'intval',
                array_column(
                    DB::conn()->all("SELECT id FROM roles WHERE id IN ({$placeholders})", $roleIds),
                    'id'
                )
            );
        }

        $wasSuper = self::isSuperAdmin($userId);
        $willBeSuper = false;
        if ($valid !== []) {
            $placeholders = implode(',', array_fill(0, count($valid), '?'));
            $willBeSuper = DB::conn()->count(
                "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ? AND r.code = 'super_admin' AND ur.role_id IN ({$placeholders})",
                array_merge([$userId], $valid)
            ) > 0;
        }

        // ★ 规则②：不能降级最后一个超级管理员
        if ($wasSuper && !$willBeSuper && Auth::superAdminCount() <= 1) {
            Response::error('这是最后一个超级管理员，不能取消其超级管理员角色', 422);
        }

        DB::conn()->tx(function (DB $db) use ($userId, $valid) {
            $db->delete('user_roles', 'user_id = ?', [$userId]);
            foreach ($valid as $rid) {
                $db->insert('user_roles', [
                    'user_id'    => $userId,
                    'role_id'    => $rid,
                    'granted_by' => Auth::id(),
                    'granted_at' => time(),
                ]);
            }
        });

        Audit::log('admin', 'user.roles', [
            'actor_id' => Auth::id(),
            'target'   => 'user:' . $userId,
            'detail'   => '调整角色，角色 ID：' . ($valid === [] ? '（无）' : implode(',', $valid)),
        ]);

        self::back('/admin/users', '角色已更新');
    }

    // ================================================================ 余额

    public static function adjustWallet(Request $request): void
    {
        Auth::requireCapability('wallet.adjust');

        $userId  = $request->int('id');
        $user = $userId > 0 ? DB::conn()->one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user === null) {
            Response::error('用户不存在', 404);
        }

        $operator = $request->str('operator', 'add', 10);   // add | sub | set
        $amount  = $request->amountCents('amount');
        $remark  = $request->str('remark', '', 200);

        if ($remark === '') {
            Response::error('请填写调整原因（会记入审计日志，便于日后对账）', 422);
        }

        if ($operator === 'set') {
            // 「设为指定金额」= 调整为「目标值 - 当前值」
            $current = Wallet::balance($userId);
            $delta = $amount - $current;
        } else {
            $delta = $operator === 'sub' ? -$amount : $amount;
        }

        if ($delta === 0) {
            Response::error('调整后余额没有变化', 422);
        }

        $result = Wallet::adjust($userId, $delta, $remark, Auth::id());
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        self::back('/admin/users', sprintf(
            '余额已调整，%s 的当前余额为 %s 元',
            (string) $user['email'],
            number_format($result['balance'] / 100, 2)
        ));
    }

    // ================================================================ 密码与会话

    public static function resetPassword(Request $request): void
    {
        Auth::requireCapability('user.edit');

        $userId = $request->int('id');
        $user = $userId > 0 ? DB::conn()->one('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user === null) {
            Response::error('用户不存在', 404);
        }

        $mode = $request->str('mode', 'manual', 20);

        if ($mode === 'random') {
            // 生成一个随机强密码，让管理员转交给用户，用户登录后自行修改
            $password = substr(Crypto::randHex(12), 0, 16);
        } else {
            $password = $request->raw('password', '', 200);
        }

        $result = \Vobl\Services\UserService::setPassword($userId, $password);
        if (!$result['ok']) {
            Response::error($result['error'], 422);
        }

        Audit::log('admin', 'user.password_reset', [
            'actor_id' => Auth::id(),
            'target'   => 'user:' . $userId,
            'detail'   => '管理员重置了 ' . $user['email'] . ' 的密码（方式：' . $mode . '）',
        ]);

        if ($mode === 'random') {
            Response::ok([
                'password' => $password,
                'revoked'  => $result['revoked'] ?? 0,
            ], '临时密码已生成，请转交给用户并提醒他登录后立即修改');
        }

        self::back('/admin/users', '密码已重置，该用户的全部会话已登出');
    }

    public static function revokeSessions(Request $request): void
    {
        Auth::requireCapability('user.edit');

        $userId = $request->int('id');
        if ($userId <= 0) {
            Response::error('参数不完整', 422);
        }
        if ($userId === Auth::id()) {
            Response::error('请使用「退出登录」来结束自己的会话', 422);
        }

        $count = Auth::revokeAll($userId);

        Audit::log('admin', 'user.revoke_sessions', [
            'actor_id' => Auth::id(),
            'target'   => 'user:' . $userId,
            'detail'   => '强制登出该用户全部会话，共 ' . $count . ' 个',
        ]);

        self::back('/admin/users', '已强制登出 ' . $count . ' 个会话');
    }

    // ================================================================ 工具

    private static function isSuperAdmin(int $userId): bool
    {
        return DB::conn()->count(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.code = 'super_admin'",
            [$userId]
        ) > 0;
    }
}
