<?php
/** 后台 · 用户管理 */
$canEdit = in_array('*', $userCaps ?? [], true) || in_array('user.edit', $userCaps ?? [], true);
$canLock = in_array('*', $userCaps ?? [], true) || in_array('user.lock', $userCaps ?? [], true);
$canRole = in_array('*', $userCaps ?? [], true) || in_array('role.manage', $userCaps ?? [], true);
$canWallet = in_array('*', $userCaps ?? [], true) || in_array('wallet.adjust', $userCaps ?? [], true);
$statusMap = [
    'active'   => ['正常', 'b-ok'],
    'locked'   => ['已锁定', 'b-warn'],
    'disabled' => ['已停用', 'b-danger'],
];
?>

<!-- 筛选 -->
<form class="card card-pad" method="get" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:10px">
    <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="搜索邮箱或名称" style="max-width:260px">
    <select class="input" name="status" style="max-width:150px">
      <option value="">全部状态</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>正常</option>
      <option value="locked" <?= $status === 'locked' ? 'selected' : '' ?>>已锁定</option>
      <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>
    </select>
    <button class="btn btn-primary" type="submit">筛选</button>
    <?php if ($keyword !== '' || $status !== ''): ?>
      <a class="btn btn-ghost" href="/admin/users">清除</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="badge b-gray">共 <?= number_format((int) $total) ?> 个用户</span>
  </div>
</form>

<div class="card">
  <div class="table-wrap" style="border:0;border-radius:0">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:26%">用户</th>
          <th style="width:16%">角色</th>
          <th style="width:11%">余额</th>
          <th style="width:14%">安全</th>
          <th style="width:13%">最近登录</th>
          <th class="right">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($users === []): ?>
        <tr><td colspan="6" class="muted small" style="padding:24px">没有匹配的用户。</td></tr>
      <?php else: ?>
        <?php foreach ($users as $u): ?>
          <?php [$sLabel, $sCls] = $statusMap[(string) $u['status']] ?? [(string) $u['status'], 'b-gray']; ?>
          <tr>
            <td>
              <b style="font-size:13.5px"><?= e($u['display_name'] ?: '（未设置名称）') ?></b>
              <div class="tiny muted mono"><?= e($u['email']) ?></div>
              <div class="row-wrap" style="gap:5px;margin-top:4px">
                <span class="badge <?= e($sCls) ?>"><?= e($sLabel) ?></span>
                <?php if ((int) $u['email_verified'] === 0): ?>
                  <span class="badge b-warn">邮箱未验证</span>
                <?php endif; ?>
                <?php if ((int) $u['id'] === \Vobl\Core\Auth::id()): ?>
                  <span class="badge b-brand">你自己</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ($u['roles'] === []): ?>
                <span class="tiny muted">普通用户</span>
              <?php else: ?>
                <?php foreach ($u['roles'] as $r): ?>
                  <span class="badge <?= (string) $r['code'] === 'super_admin' ? 'b-brand' : 'b-gray' ?>" style="margin-bottom:3px">
                    <?= e($r['name']) ?>
                  </span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-weight:600">¥<?= e(number_format((int) $u['balance'] / 100, 2)) ?></td>
            <td>
              <div class="row-wrap" style="gap:5px">
                <?php if ((int) $u['totp_enabled'] === 1): ?><span class="badge b-ok">2FA</span><?php endif; ?>
                <?php if ((int) $u['passkeys'] > 0): ?><span class="badge b-ok">密钥 ×<?= (int) $u['passkeys'] ?></span><?php endif; ?>
                <?php if ((int) $u['totp_enabled'] === 0 && (int) $u['passkeys'] === 0): ?>
                  <span class="badge b-gray">仅密码</span>
                <?php endif; ?>
              </div>
              <?php if ((int) $u['failed_count'] > 0): ?>
                <div class="tiny" style="color:var(--warn);margin-top:3px">失败 <?= (int) $u['failed_count'] ?> 次</div>
              <?php endif; ?>
            </td>
            <td class="tiny muted">
              <?= e(ago((int) $u['last_login_at'])) ?>
              <?php if ((string) $u['last_login_ip'] !== ''): ?>
                <div class="mono" style="font-size:11px"><?= e($u['last_login_ip']) ?></div>
              <?php endif; ?>
            </td>
            <td class="right">
              <?php if ($canLock): ?>
                <?php if ((string) $u['status'] === 'active'): ?>
                  <button class="btn btn-sm btn-ghost" type="button" data-action="/admin/users/status"
                          data-payload='<?= e(json_encode(['id' => $u['id'], 'action' => 'lock'])) ?>'
                          data-confirm="锁定后该用户 1 小时内无法登录，且现有会话会被全部登出。确定吗？"
                          data-busy-label="处理中…">锁定</button>
                  <button class="btn btn-sm btn-danger" type="button" data-action="/admin/users/status"
                          data-payload='<?= e(json_encode(['id' => $u['id'], 'action' => 'disable'])) ?>'
                          data-confirm="停用后该用户将完全无法登录。确定吗？"
                          data-busy-label="处理中…">停用</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-ok" type="button" data-action="/admin/users/status"
                          data-payload='<?= e(json_encode(['id' => $u['id'], 'action' => 'unlock'])) ?>'
                          data-busy-label="处理中…">解锁</button>
                <?php endif; ?>
              <?php endif; ?>
              <button class="btn btn-sm btn-soft" type="button" data-manage="<?= (int) $u['id'] ?>">管理</button>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card-foot">
      <div class="row" style="gap:6px">
        <span>第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
        <span class="spacer"></span>
        <?php if ($page > 1): ?>
          <a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query(array_filter(['q'=>$keyword,'status'=>$status,'page'=>$page-1]))) ?>">上一页</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query(array_filter(['q'=>$keyword,'status'=>$status,'page'=>$page+1]))) ?>">下一页</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- 用户管理弹窗 -->
<div id="user-modal" hidden class="modal-mask" style="display:none"></div>

<script>
(function () {
  var users = <?= json_encode(array_map(static function ($u) {
      return [
          'id' => (int) $u['id'],
          'email' => (string) $u['email'],
          'name' => (string) $u['display_name'],
          'status' => (string) $u['status'],
          'roleIds' => array_map(static fn($r) => (int) $r['id'], $u['roles']),
          'balance' => (int) $u['balance'],
      ];
  }, $users), JSON_UNESCAPED_UNICODE) ?>;

  var roles = <?= json_encode(array_map(static fn($r) => [
      'id' => (int) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code'],
      'system' => (int) $r['is_system'] === 1,
  ], $roles), JSON_UNESCAPED_UNICODE) ?>;

  var canRole = <?= $canRole ? 'true' : 'false' ?>;
  var canWallet = <?= $canWallet ? 'true' : 'false' ?>;
  var canEdit = <?= $canEdit ? 'true' : 'false' ?>;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-manage]');
    if (!btn) return;
    var id = parseInt(btn.dataset.manage, 10);
    var u = users.find(function (x) { return x.id === id; });
    if (!u) return;

    var roleBoxes = roles.map(function (r) {
      var checked = u.roleIds.indexOf(r.id) >= 0 ? ' checked' : '';
      var disabled = canRole ? '' : ' disabled';
      return '<label class="choice"><input type="checkbox" name="roles[]" value="' + r.id + '"' + checked + disabled + '> ' +
        esc(r.name) + '</label>';
    }).join('');

    var mask = document.createElement('div');
    mask.className = 'modal-mask';
    mask.innerHTML =
      '<div class="modal">' +
        '<div class="modal-head"><h3 style="margin:0;font-size:16px">' + esc(u.name || u.email) + '</h3>' +
          '<span class="badge b-gray mono">' + esc(u.email) + '</span></div>' +
        '<div class="modal-body">' +

          '<h4 style="margin-bottom:10px;font-size:14px">角色权限</h4>' +
          (canRole
            ? '<div class="choice-row">' + roleBoxes + '</div>' +
              '<div class="row mt-3"><button class="btn btn-sm btn-primary" type="button" data-save-roles>保存角色</button></div>' +
              '<p class="hint mt-2">管理员不能修改自己的角色 —— 这条规则写死在服务端，避免权限体系被自己绕过。</p>'
            : '<p class="small muted">你没有「管理角色」的权限。</p>') +

          '<hr>' +

          '<h4 style="margin-bottom:10px;font-size:14px">账户余额</h4>' +
          (canWallet
            ? '<div class="row-wrap" style="gap:8px">' +
                '<span class="badge b-brand">当前 ¥' + (u.balance / 100).toFixed(2) + '</span>' +
                '<select class="input" id="wd-op" style="max-width:110px">' +
                  '<option value="add">增加</option><option value="sub">扣减</option><option value="set">设为</option>' +
                '</select>' +
                '<input class="input" id="wd-amount" type="number" step="0.01" min="0.01" placeholder="金额（元）" style="max-width:140px">' +
                '<input class="input" id="wd-remark" placeholder="调整原因（必填，会写进审计日志）" style="max-width:280px">' +
                '<button class="btn btn-sm btn-primary" type="button" data-save-wallet>执行</button>' +
              '</div>' +
              '<p class="hint mt-2">余额调整必须填写原因 —— 这是日后对账的唯一依据。</p>'
            : '<p class="small muted">你没有「调整余额」的权限。</p>') +

          '<hr>' +

          '<h4 style="margin-bottom:10px;font-size:14px">安全操作</h4>' +
          '<div class="row-wrap" style="gap:8px">' +
            (canEdit
              ? '<button class="btn btn-sm btn-ghost" type="button" data-reset-pw>生成临时密码</button>' +
                '<button class="btn btn-sm btn-ghost" type="button" data-revoke>强制登出全部会话</button>'
              : '<span class="small muted">你没有「编辑用户」的权限。</span>') +
          '</div>' +

        '</div>' +
        '<div class="modal-foot"><button class="btn btn-ghost" type="button" data-close>关闭</button></div>' +
      '</div>';

    document.body.appendChild(mask);

    function close() { mask.remove(); }
    mask.addEventListener('click', function (ev) {
      if (ev.target === mask || ev.target.closest('[data-close]')) close();
    });

    // 保存角色
    var sr = mask.querySelector('[data-save-roles]');
    if (sr) sr.addEventListener('click', async function () {
      var ids = Array.prototype.slice.call(mask.querySelectorAll('input[name="roles[]"]:checked'))
        .map(function (i) { return parseInt(i.value, 10); });
      window.busy(sr, true, '保存中…');
      try {
        await window.api.post('/admin/users/roles', { id: id, roles: ids });
        window.toast('角色已更新', 'ok');
        setTimeout(function () { location.reload(); }, 600);
      } catch (err) { window.busy(sr, false); window.toast(err.message, 'err', 6000); }
    });

    // 余额调整
    var sw = mask.querySelector('[data-save-wallet]');
    if (sw) sw.addEventListener('click', async function () {
      var amount = mask.querySelector('#wd-amount').value;
      var remark = mask.querySelector('#wd-remark').value.trim();
      if (!amount || parseFloat(amount) <= 0) { window.toast('请输入有效金额', 'err'); return; }
      if (!remark) { window.toast('请填写调整原因', 'err'); return; }
      window.busy(sw, true, '执行中…');
      try {
        await window.api.post('/admin/users/wallet', {
          id: id, operator: mask.querySelector('#wd-op').value, amount: amount, remark: remark,
        });
        window.toast('余额已调整', 'ok');
        setTimeout(function () { location.reload(); }, 700);
      } catch (err) { window.busy(sw, false); window.toast(err.message, 'err', 6000); }
    });

    // 重置密码
    var rp = mask.querySelector('[data-reset-pw]');
    if (rp) rp.addEventListener('click', function () {
      window.askConfirm(
        '将为该用户生成一个随机临时密码，并通过弹窗展示给你（只显示一次）。该用户的所有会话会被登出。',
        async function () {
          window.busy(rp, true, '生成中…');
          try {
            var r = await window.api.post('/admin/users/password', { id: id, mode: 'random' });
            window.busy(rp, false);
            window.showTempPassword(r.data.password);
          } catch (err) { window.busy(rp, false); window.toast(err.message, 'err', 6000); }
        },
        { title: '重置用户密码', okText: '生成临时密码' }
      );
    });

    // 强制登出
    var rv = mask.querySelector('[data-revoke]');
    if (rv) rv.addEventListener('click', function () {
      window.askConfirm('该用户的所有设备都会被登出，需要重新登录。', async function () {
        window.busy(rv, true, '处理中…');
        try {
          await window.api.post('/admin/users/revoke', { id: id });
          window.toast('已强制登出', 'ok');
          setTimeout(function () { location.reload(); }, 600);
        } catch (err) { window.busy(rv, false); window.toast(err.message, 'err'); }
      }, { title: '强制登出', okText: '确定登出', danger: false });
    });
  });
})();
</script>
