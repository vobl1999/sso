<?php
declare(strict_types=1);

namespace Vobl\Controllers\Admin;

use Vobl\Controllers\BaseController;
use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;

/**
 * 后台 · 角色与权限
 * ============================================================================
 * 权限模型是「能力位」而不是「角色名」：
 *   判断权限时看的是 can('user.edit')，不是 hasRole('admin')。
 *   所以「加一个审计员角色」是新增一条数据，不是改代码。
 *
 * 能力位清单（CAPABILITIES）是唯一权威来源，界面上的复选框由它生成。
 * ============================================================================
 */
final class RoleController extends BaseController
{
    /** 全部可用能力位。分组只是为了界面好看，校验时用的是扁平列表 */
    public const CAPABILITIES = [
        '基础' => [
            'dashboard.view' => '查看控制台',
        ],
        '用户' => [
            'user.view'   => '查看用户',
            'user.edit'   => '编辑用户资料',
            'user.lock'   => '锁定 / 停用用户',
            'user.delete' => '删除用户',
        ],
        '权限' => [
            'role.view'   => '查看角色',
            'role.manage' => '新建 / 修改 / 删除角色',
        ],
        '应用' => [
            'client.view'   => '查看接入应用',
            'client.manage' => '管理接入应用',
        ],
        '邮件' => [
            'smtp.view'   => '查看邮件配置',
            'smtp.manage' => '修改邮件配置',
            'smtp.test'   => '发送测试邮件',
        ],
        '支付与充值' => [
            'payment.view'      => '查看支付配置',
            'payment.manage'    => '配置支付通道',
            'payment.order.view'=> '查看订单',
            'payment.refund'    => '订单退款',
            'wallet.adjust'     => '调整用户余额',
        ],
        '安全' => [
            'security.view'   => '查看安全设置',
            'security.manage' => '修改安全策略',
        ],
        '审计' => [
            'audit.view'   => '查看审计日志',
            'audit.export' => '导出审计日志',
        ],
        '系统' => [
            'settings.view'   => '查看系统设置',
            'settings.manage' => '修改系统设置',
        ],
    ];

    public static function allCapabilities(): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $group) {
            foreach ($group as $key => $label) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function index(Request $request): void
    {
        Auth::requireCapability('role.view');

        $roles = DB::conn()->all('SELECT * FROM roles ORDER BY is_system DESC, id');
        foreach ($roles as &$r) {
            $caps = json_decode((string) $r['capabilities'], true);
            $r['caps'] = is_array($caps) ? $caps : [];
            $r['is_all'] = in_array('*', $r['caps'], true);
            $r['user_count'] = DB::conn()->count('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [(int) $r['id']]);
        }
        unset($r);

        self::adminPage('admin/roles', [
            'title'        => '角色与权限',
            'roles'        => $roles,
            'capabilities' => self::CAPABILITIES,
            'capFlat'      => self::allCapabilities(),
            'canManage'    => Auth::can('role.manage'),
            'flash'        => self::takenFlash(),
        ]);
    }

    public static function save(Request $request): void
    {
        Auth::requireCapability('role.manage');

        $id   = $request->int('id');
        $code = strtolower(trim($request->str('code', '', 40)));
        $name = $request->str('name', '', 60);
        $caps = array_values(array_intersect($request->arr('capabilities'), self::allCapabilities()));
        $grantAll = $request->bool('grant_all');

        if ($name === '') {
            Response::error('请填写角色名称', 422);
        }

        if ($grantAll) {
            $caps = ['*'];
        }

        $existing = $id > 0 ? DB::conn()->one('SELECT * FROM roles WHERE id = ?', [$id]) : null;

        if ($existing !== null) {
            // 系统角色允许改名称与权限，但不允许改 code（代码里可能按 code 判断）
            if ((int) $existing['is_system'] === 1) {
                $code = (string) $existing['code'];
            }
            if ($code === '') {
                $code = (string) $existing['code'];
            }
            // 不允许把 super_admin 的权限改小，否则可能把自己锁死
            if ((string) $existing['code'] === 'super_admin' && !$grantAll) {
                Response::error('超级管理员必须保留全部权限', 422);
            }

            DB::conn()->update('roles', [
                'code'         => $code,
                'name'         => $name,
                'capabilities' => json_encode($caps, JSON_UNESCAPED_UNICODE),
                'updated_at'   => time(),
            ], 'id = ?', [$id]);

            Audit::log('admin', 'role.update', [
                'actor_id' => Auth::id(), 'target' => 'role:' . $id,
                'detail'   => '修改角色「' . $name . '」，能力位 ' . count($caps) . ' 项',
            ]);
            self::back('/admin/roles', '角色「' . $name . '」已更新');
        }

        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            Response::error('角色标识只能用英文小写字母、数字和下划线，且以字母开头', 422);
        }
        if (DB::conn()->one('SELECT id FROM roles WHERE code = ?', [$code]) !== null) {
            Response::error('角色标识「' . $code . '」已被占用', 422);
        }
        if ($caps === []) {
            Response::error('至少勾选一项权限，否则这个角色没有任何作用', 422);
        }

        $newId = DB::conn()->insert('roles', [
            'code'         => $code,
            'name'         => $name,
            'capabilities' => json_encode($caps, JSON_UNESCAPED_UNICODE),
            'is_system'    => 0,
            'created_at'   => time(),
            'updated_at'   => time(),
        ]);

        Audit::log('admin', 'role.create', [
            'actor_id' => Auth::id(), 'target' => 'role:' . $newId,
            'detail'   => '新建角色「' . $name . '」（' . $code . '）',
        ]);

        self::back('/admin/roles', '角色「' . $name . '」已创建');
    }

    public static function delete(Request $request): void
    {
        Auth::requireCapability('role.manage');

        $id = $request->int('id');
        $role = $id > 0 ? DB::conn()->one('SELECT * FROM roles WHERE id = ?', [$id]) : null;
        if ($role === null) {
            Response::error('角色不存在', 404);
        }
        if ((int) $role['is_system'] === 1) {
            Response::error('系统内置角色不能删除', 422);
        }

        $inUse = DB::conn()->count('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$id]);
        if ($inUse > 0) {
            Response::error('还有 ' . $inUse . ' 个用户使用该角色，请先取消他们的该角色', 422);
        }

        DB::conn()->delete('roles', 'id = ?', [$id]);

        Audit::log('admin', 'role.delete', [
            'actor_id' => Auth::id(), 'target' => 'role:' . $id,
            'detail'   => '删除角色「' . $role['name'] . '」',
        ]);

        self::back('/admin/roles', '角色「' . $role['name'] . '」已删除');
    }
}
