<?php
/** 后台 · 接入应用管理 */
$canManage = !empty($canManage);
?>
<form class="card card-pad" method="get" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:10px">
    <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="搜索应用名 / client_id / 创建者" style="max-width:300px">
    <button class="btn btn-primary" type="submit">搜索</button>
    <?php if ($keyword !== ''): ?><a class="btn btn-ghost" href="/admin/clients">清除</a><?php endif; ?>
    <span class="spacer"></span>
    <span class="badge b-gray">共 <?= count($clients) ?> 个应用</span>
  </div>
</form>

<?php if ($clients === []): ?>
  <div class="card empty">
    <div class="empty-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg>
    </div>
    <h3>还没有接入应用</h3>
    <p>用户可以自己在「开放平台」创建应用，你也可以在这里替他们管理。</p>
  </div>
<?php else: ?>
  <?php foreach ($clients as $c): ?>
    <div class="card" style="margin-bottom:14px">
      <div class="card-head">
        <span style="width:34px;height:34px;border-radius:11px;flex:0 0 auto;display:grid;place-items:center;
          font-weight:700;font-size:14px;color:#fff;background:linear-gradient(140deg,#ff8a24,#ffab4d)">
          <?= e($c['icon'] ?: mb_strtoupper(mb_substr((string) $c['name'], 0, 1))) ?>
        </span>
        <h3><?= e($c['name']) ?></h3>
        <?php if ((string) $c['status'] === 'active'): ?>
          <span class="badge b-ok"><i class="dot"></i>正常</span>
        <?php else: ?>
          <span class="badge b-danger">已停用</span>
        <?php endif; ?>
        <span class="badge <?= (string) $c['type'] === 'public' ? 'b-brand' : 'b-gray' ?>">
          <?= (string) $c['type'] === 'public' ? '公开 + PKCE' : '机密' ?>
        </span>
        <?php if ((string) $c['client_secret'] === ''): ?>
          <span class="badge b-warn">无密钥</span>
        <?php endif; ?>
        <span class="spacer"></span>
        <span class="tiny muted"><?= (int) $c['grant_count'] ?> 人授权</span>
        <?php if ($canManage): ?>
          <button class="btn btn-sm btn-ghost" type="button" data-action="/admin/clients/secret"
                  data-payload='<?= e(json_encode(['client_id' => $c['client_id']])) ?>'
                  data-confirm="重置后旧密钥立即失效，该应用的接入方需要更换配置。确定吗？"
                  data-busy-label="生成中…" data-reload="off">重置密钥</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-ghost" type="button" data-toggle="#cl-<?= e($c['client_id']) ?>">编辑</button>
      </div>

      <div class="card-body">
        <div class="row-wrap" style="gap:7px;margin-bottom:9px">
          <span class="tiny muted">client_id</span>
          <span class="inline-copy">
            <span><?= e($c['client_id']) ?></span>
            <button type="button" data-copy="self" data-copy-text="<?= e($c['client_id']) ?>">复制</button>
          </span>
        </div>
        <div class="row-wrap" style="gap:6px;margin-bottom:8px">
          <span class="tiny muted">回调地址：</span>
          <?php foreach ($c['redirect_uris_arr'] as $u): ?>
            <span class="inline-copy" style="font-size:11.8px">
              <span><?= e($u) ?></span>
              <button type="button" data-copy="self" data-copy-text="<?= e($u) ?>">复制</button>
            </span>
          <?php endforeach; ?>
        </div>
        <div class="row-wrap" style="gap:6px">
          <span class="tiny muted">授权范围：</span>
          <?php foreach ($c['scopes_arr'] as $s): ?>
            <span class="badge b-gray"><?= e($s) ?></span>
          <?php endforeach; ?>
          <?php if ((string) $c['owner_email'] !== ''): ?>
            <span class="spacer"></span>
            <span class="tiny muted">创建者 <?= e($c['owner_email']) ?> · <?= e(dt((int) $c['created_at'], 'Y-m-d')) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canManage): ?>
        <div id="cl-<?= e($c['client_id']) ?>" hidden class="card-body" style="border-top:1px solid var(--border)">
          <form data-ajax action="/admin/clients/save" method="post" data-reload="off">
            <input type="hidden" name="client_id" value="<?= e($c['client_id']) ?>">
            <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>

            <div class="form-grid">
              <div class="field">
                <label class="label">应用名称</label>
                <input class="input" name="name" value="<?= e($c['name']) ?>" required>
              </div>
              <div class="field">
                <label class="label">主页地址</label>
                <input class="input" name="homepage" value="<?= e($c['homepage']) ?>">
              </div>
              <div class="field full">
                <label class="label">说明</label>
                <input class="input" name="description" value="<?= e($c['description']) ?>">
              </div>
              <div class="field full">
                <label class="label">回调地址</label>
                <textarea class="textarea" name="redirect_uris" rows="3"><?= e(implode("\n", $c['redirect_uris_arr'])) ?></textarea>
                <span class="hint">每行一个，必须与实际使用的地址完全一致。相对地址（以 / 开头）表示同站跳转。</span>
              </div>
              <div class="field">
                <label class="label">授权范围</label>
                <div class="choice-row">
                  <?php foreach (['profile' => 'profile 昵称头像', 'email' => 'email 邮箱', 'orgs' => 'orgs 组织'] as $k => $label): ?>
                    <label class="choice">
                      <input type="checkbox" name="scopes[]" value="<?= e($k) ?>"
                             <?= in_array($k, $c['scopes_arr'], true) ? 'checked' : '' ?>>
                      <span><?= e($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="field">
                <label class="label">状态</label>
                <select class="input" name="status">
                  <option value="active" <?= (string) $c['status'] === 'active' ? 'selected' : '' ?>>正常</option>
                  <option value="disabled" <?= (string) $c['status'] === 'disabled' ? 'selected' : '' ?>>停用</option>
                </select>
                <span class="hint">停用比删除更稳妥：随时可恢复，且不会清掉用户的授权记录。</span>
              </div>
              <div class="field full">
                <label class="switch">
                  <input type="checkbox" name="visible" value="1" <?= (int) $c['visible'] === 1 ? 'checked' : '' ?>>
                  <span>在门户首页展示</span>
                </label>
              </div>
            </div>

            <div class="row mt-3" style="gap:9px">
              <button class="btn btn-primary" type="submit">保存</button>
            </div>
          </form>

          <hr>
          <div class="row-wrap" style="gap:9px">
            <button class="btn btn-sm btn-danger" type="button" data-action="/admin/clients/delete"
                    data-payload='<?= e(json_encode(['client_id' => $c['client_id']])) ?>'
                    data-confirm="删除会清掉该应用的全部授权记录，属于不可逆操作。确定要删除「<?= e($c['name']) ?>」吗？"
                    data-busy-label="删除中…">删除应用</button>
            <span class="tiny muted">
              如果只是暂时不想让它接入，建议改为「停用」——
              删除会连带清掉 <?= (int) $c['grant_count'] ?> 条用户授权记录，用户会重新被弹授权页。
            </span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-toggle]');
  if (!btn) return;
  var el = document.querySelector(btn.dataset.toggle);
  if (!el) return;
  el.hidden = !el.hidden;
});
</script>
