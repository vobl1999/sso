<?php
/**
 * 后台 · 角色与权限
 * @var array $roles
 * @var array $capabilities  分组的能力位
 * @var array $capFlat       扁平清单（用于校验回显）
 * @var bool  $canManage
 */
$capFlat = $capFlat ?? [];
?>
<div class="alert alert-info" style="margin-bottom:18px">
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
  <div>
    <b>权限用的是「能力位」而不是角色名。</b>
    代码里判断的是 <code>can('user.edit')</code>，不是 <code>hasRole('admin')</code>。
    所以「加一个审计员」是新增一条数据，而不是改代码、重新部署。<br>
    <span class="mt-2" style="display:inline-block">
      两条写在服务端、不依赖界面置灰的硬规则：<b>① 管理员不能给自己授权</b>；
      <b>② 不能取消最后一个超级管理员</b>。少了任何一条，权限体系都能被绕过。
    </span>
  </div>
</div>

<?php if ($canManage): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head">
    <h3>新建角色</h3>
    <span class="spacer"></span>
    <button class="btn btn-sm btn-primary" type="button" data-toggle="#role-new">展开</button>
  </div>
  <div id="role-new" hidden class="card-body" style="border-top:1px solid var(--border)">
    <form data-ajax action="/admin/roles/save" method="post" data-reload="off">
      <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>
      <div class="form-grid">
        <div class="field">
          <label class="label">角色名称 <span class="req">*</span></label>
          <input class="input" name="name" placeholder="例如：内容审核员" required maxlength="60">
        </div>
        <div class="field">
          <label class="label">角色标识 <span class="req">*</span></label>
          <input class="input mono" name="code" placeholder="例如：content_reviewer" required maxlength="40">
          <span class="hint">英文小写字母、数字、下划线，以字母开头。程序内部用的稳定标识，建好后不建议改。</span>
        </div>
      </div>

      <div class="mt-3">
        <label class="label" style="margin-bottom:9px">权限</label>
        <?php foreach ($capabilities as $group => $caps): ?>
          <div style="margin-bottom:12px">
            <div class="tiny muted" style="font-weight:700;margin-bottom:6px"><?= e($group) ?></div>
            <div class="choice-row">
              <?php foreach ($caps as $key => $label): ?>
                <label class="choice">
                  <input type="checkbox" name="capabilities[]" value="<?= e($key) ?>">
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <label class="switch mt-2">
          <input type="checkbox" name="grant_all" value="1">
          <span><b>授予全部权限</b>（等同于超级管理员，请谨慎勾选）</span>
        </label>
      </div>

      <div class="row mt-4" style="gap:9px">
        <button class="btn btn-primary" type="submit">创建角色</button>
        <button class="btn btn-ghost" type="button" data-toggle="#role-new">取消</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php foreach ($roles as $r): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-head">
      <h3><?= e($r['name']) ?></h3>
      <span class="badge b-gray mono"><?= e($r['code']) ?></span>
      <?php if ((int) $r['is_system'] === 1): ?>
        <span class="badge b-brand">系统内置</span>
      <?php endif; ?>
      <?php if ($r['is_all']): ?>
        <span class="badge b-warn">全部权限</span>
      <?php endif; ?>
      <span class="spacer"></span>
      <span class="tiny muted"><?= (int) $r['user_count'] ?> 个用户</span>
      <?php if ($canManage && (int) $r['is_system'] !== 1): ?>
        <button class="btn btn-sm btn-danger" type="button" data-action="/admin/roles/delete"
                data-payload='<?= e(json_encode(['id' => $r['id']])) ?>'
                data-confirm="确定删除角色「<?= e($r['name']) ?>」？如果还有用户使用它，系统会拒绝删除。"
                data-busy-label="删除中…">删除</button>
      <?php endif; ?>
      <?php if ($canManage): ?>
        <button class="btn btn-sm btn-ghost" type="button" data-toggle="#role-<?= (int) $r['id'] ?>">编辑</button>
      <?php endif; ?>
    </div>

    <!-- 权限概览 -->
    <?php if (!$r['is_all']): ?>
      <div class="card-body">
        <div class="choice-row">
          <?php if ($r['caps'] === []): ?>
            <span class="small muted">没有任何权限</span>
          <?php else: ?>
            <?php foreach ($capFlat as $cap): ?>
              <?php if (in_array($cap, $r['caps'], true)): ?>
                <span class="badge b-ok"><?= e($cap) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 编辑表单 -->
    <?php if ($canManage): ?>
      <div id="role-<?= (int) $r['id'] ?>" hidden class="card-body" style="border-top:1px solid var(--border)">
        <form data-ajax action="/admin/roles/save" method="post" data-reload="off">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>

          <div class="form-grid">
            <div class="field">
              <label class="label">角色名称</label>
              <input class="input" name="name" value="<?= e($r['name']) ?>" required>
            </div>
            <div class="field">
              <label class="label">角色标识</label>
              <input class="input mono" name="code" value="<?= e($r['code']) ?>"
                     <?= (int) $r['is_system'] === 1 ? 'readonly' : '' ?>>
              <?php if ((int) $r['is_system'] === 1): ?>
                <span class="hint">系统内置角色的标识不可修改。</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-3">
            <?php foreach ($capabilities as $group => $caps): ?>
              <div style="margin-bottom:12px">
                <div class="tiny muted" style="font-weight:700;margin-bottom:6px"><?= e($group) ?></div>
                <div class="choice-row">
                  <?php foreach ($caps as $key => $label): ?>
                    <label class="choice">
                      <input type="checkbox" name="capabilities[]" value="<?= e($key) ?>"
                             <?= in_array($key, $r['caps'], true) ? 'checked' : '' ?>
                             <?= (string) $r['code'] === 'super_admin' ? 'disabled' : '' ?>>
                      <span><?= e($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if ((string) $r['code'] === 'super_admin'): ?>
              <input type="hidden" name="grant_all" value="1">
              <div class="alert alert-warn" style="font-size:13px">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
                <div>超级管理员必须保留全部权限 —— 这条限制写在服务端。取消它等于给自己挖坑。</div>
              </div>
            <?php else: ?>
              <label class="switch mt-2">
                <input type="checkbox" name="grant_all" value="1">
                <span><b>授予全部权限</b>（慎用）</span>
              </label>
            <?php endif; ?>
          </div>

          <div class="row mt-3" style="gap:9px">
            <button class="btn btn-primary" type="submit">保存</button>
            <button class="btn btn-ghost" type="button" data-toggle="#role-<?= (int) $r['id'] ?>">收起</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-toggle]');
  if (!btn) return;
  var el = document.querySelector(btn.dataset.toggle);
  if (!el) return;
  el.hidden = !el.hidden;
});
</script>
