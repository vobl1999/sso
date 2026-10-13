<?php
/**
 * 开放平台 · 应用管理
 * @var array $clients
 * @var array $grants
 * @var array $wallet
 * @var array $recentTx
 * @var array $accents
 */
$siteOrigin = $siteOrigin ?? '';
$initial = mb_strtoupper(mb_substr((string) ($user['display_name'] ?: $user['email']), 0, 1));
$accentClass = static function (string $a): string {
    return in_array($a, ['indigo','violet','cyan','sky','emerald','amber','rose','slate'], true) ? $a : 'indigo';
};
?>

<!-- 概览 -->
<div class="kpis" style="margin-bottom:18px">
  <div class="kpi"><b><?= count($clients) ?></b><span>我的应用</span></div>
  <div class="kpi"><b><?= count($grants) ?></b><span>我授权的应用</span></div>
  <div class="kpi"><b>¥<?= e(number_format(((int) ($wallet['balance'] ?? 0)) / 100, 2)) ?></b><span>账户余额</span></div>
  <div class="kpi"><b><?= array_sum(array_column($clients, 'grant_count')) ?></b><span>累计授权人次</span></div>
</div>

<div class="stack">

  <!-- ============ 我的应用 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>我的应用</h3>
      <span class="spacer"></span>
      <button class="btn btn-sm btn-primary" type="button" data-toggle="#new-client">新建应用</button>
    </div>

    <!-- 新建表单 -->
    <div id="new-client" hidden style="padding:20px;border-bottom:1px solid var(--border);background:var(--surface-2)">
      <form data-ajax action="/developer/clients" method="post" data-reload="off">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>
        <div class="form-grid">
          <div class="field">
            <label class="label">应用名称 <span class="req">*</span></label>
            <input class="input" name="name" placeholder="例如：云主机控制台" required maxlength="60">
          </div>
          <div class="field">
            <label class="label">主页地址</label>
            <input class="input" name="homepage" placeholder="https://idc.example.com">
          </div>
          <div class="field full">
            <label class="label">一句话说明</label>
            <input class="input" name="description" placeholder="客户登录后直接进入面板" maxlength="160">
          </div>
          <div class="field full">
            <label class="label">回调地址 <span class="req">*</span></label>
            <textarea class="textarea" name="redirect_uris" rows="2"
              placeholder="https://idc.example.com/auth/callback&#10;每行一个，也可以用逗号分隔"></textarea>
            <span class="hint">
              必须与你的应用实际使用的地址完全一致，多一个斜杠都会被拒绝（这是防授权码劫持的必要检查）。<br>
              以 <code>/</code> 开头的地址表示只在本站内跳转，适合同站应用。
            </span>
          </div>
          <div class="field">
            <label class="label">授权范围</label>
            <div class="choice-row">
              <label class="choice"><input type="checkbox" name="scopes[]" value="profile" checked> profile 昵称头像</label>
              <label class="choice"><input type="checkbox" name="scopes[]" value="email"> email 邮箱</label>
              <label class="choice"><input type="checkbox" name="scopes[]" value="orgs"> orgs 组织</label>
            </div>
          </div>
          <div class="field">
            <label class="label">客户端类型</label>
            <div class="choice-row">
              <label class="choice"><input type="radio" name="type" value="confidential" checked> 机密（有服务端）</label>
              <label class="choice"><input type="radio" name="type" value="public"> 公开（纯前端 PKCE）</label>
            </div>
          </div>
          <div class="field full">
            <label class="switch"><input type="checkbox" name="visible" value="1" checked> 在门户首页展示，允许一键登录</label>
          </div>
        </div>
        <div class="row mt-3" style="gap:9px">
          <button class="btn btn-primary" type="submit">创建应用</button>
          <button class="btn btn-ghost" type="button" data-toggle="#new-client">取消</button>
        </div>
        <p class="hint mt-2">创建后会显示一次 client_secret，请立即保存 —— 服务端只存摘要，之后无法再查看。</p>
      </form>
    </div>

    <div class="card-body">
      <?php if ($clients === []): ?>
        <div class="empty" style="padding:32px 0">
          <div class="empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg>
          </div>
          <h3>还没有应用</h3>
          <p>点「新建应用」，填名称和回调地址即可拿到 client_id。</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>应用</th><th>client_id</th><th>类型</th><th>授权人数</th><th class="right">操作</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
              <tr>
                <td>
                  <div class="row" style="gap:10px">
                    <span style="width:34px;height:34px;border-radius:11px;flex:0 0 auto;display:grid;place-items:center;
                      font-weight:700;font-size:14px;color:#fff;background:linear-gradient(140deg,#ff8a24,#ffab4d)">
                      <?= e($c['icon'] ?: mb_strtoupper(mb_substr((string) $c['name'], 0, 1))) ?>
                    </span>
                    <div style="min-width:0">
                      <b style="font-size:13.6px"><?= e($c['name']) ?></b>
                      <?php if ((string) $c['status'] !== 'active'): ?>
                        <span class="badge b-danger" style="margin-left:5px">已停用</span>
                      <?php endif; ?>
                      <div class="tiny muted"><?= e($c['description'] ?: '—') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="inline-copy">
                    <span><?= e($c['client_id']) ?></span>
                    <button type="button" data-copy="self" data-copy-text="<?= e($c['client_id']) ?>">复制</button>
                  </span>
                </td>
                <td>
                  <span class="badge <?= (string) $c['type'] === 'public' ? 'b-brand' : 'b-gray' ?>">
                    <?= (string) $c['type'] === 'public' ? '公开 + PKCE' : '机密' ?>
                  </span>
                </td>
                <td><?= (int) $c['grant_count'] ?> 人</td>
                <td class="right">
                  <button class="btn btn-sm btn-ghost" type="button" data-action="/developer/clients/secret"
                          data-payload='<?= e(json_encode(['client_id' => $c['client_id']])) ?>'
                          data-confirm="重置密钥后，旧密钥立即失效，需要把新密钥填回你的应用。确定吗？"
                          data-busy-label="生成中…" data-reload="off">重置密钥</button>
                  <button class="btn btn-sm btn-danger" type="button" data-action="/developer/clients/delete"
                          data-payload='<?= e(json_encode(['client_id' => $c['client_id']])) ?>'
                          data-confirm="确定删除「<?= e($c['name']) ?>」？用这个 client_id 登录的站点会立刻失效。"
                          data-busy-label="删除中…">删除</button>
                </td>
              </tr>
              <tr>
                <td colspan="5" style="background:var(--surface-2);padding:10px 14px">
                  <div class="tiny muted" style="margin-bottom:6px">回调地址（<?= count($c['redirect_uris']) ?> 个）：</div>
                  <div class="row-wrap" style="gap:6px">
                    <?php foreach ($c['redirect_uris'] as $u): ?>
                      <span class="inline-copy" style="font-size:11.8px">
                        <span><?= e($u) ?></span>
                        <button type="button" data-copy="self" data-copy-text="<?= e($u) ?>">复制</button>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <div class="row-wrap" style="gap:6px;margin-top:8px">
                    <span class="tiny muted">授权范围：</span>
                    <?php foreach ($c['scopes'] as $s): ?>
                      <span class="badge b-gray"><?= e($s) ?></span>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ 接入指引 ============ -->
  <div class="card">
    <div class="card-head"><h3>三步接入</h3><span class="spacer"></span>
      <a class="btn btn-sm btn-ghost" href="/docs" target="_blank">完整文档</a>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin-bottom:14px">
        下面用的是你第一个应用的 <code>client_id</code>。把这三段换成你自己的即可。
      </p>
      <?php $firstClient = $clients[0]['client_id'] ?? '你的client_id'; ?>
      <div class="stack-s">
        <div>
          <div class="row" style="gap:7px;margin-bottom:7px">
            <span class="badge b-brand">1</span><b style="font-size:13.6px">跳转到授权端点</b>
          </div>
          <div class="code">
            <div class="code-head"><span class="code-lang">http</span>
              <button class="copy-btn" type="button" data-copy="#c-1" data-keep-label>复制</button>
            </div>
            <pre id="c-1" data-raw="<?= e($siteOrigin) ?>/authorize?client_id=<?= e($firstClient) ?>&amp;redirect_uri=你的回调地址&amp;response_type=code&amp;scope=profile%20email&amp;state=随机串"><?= e($siteOrigin) ?>/authorize
  ?client_id=<?= e($firstClient) ?>
  &amp;redirect_uri=你的回调地址
  &amp;response_type=code
  &amp;scope=profile email
  &amp;state=随机串</pre>
          </div>
        </div>

        <div>
          <div class="row" style="gap:7px;margin-bottom:7px">
            <span class="badge b-brand">2</span><b style="font-size:13.6px">用授权码在服务端换令牌</b>
          </div>
          <div class="code">
            <div class="code-head"><span class="code-lang">bash</span>
              <button class="copy-btn" type="button" data-copy="#c-2" data-keep-label>复制</button>
            </div>
            <pre id="c-2" data-raw="curl -X POST <?= e($siteOrigin) ?>/oauth/token -H &quot;Content-Type: application/json&quot; -d '{&quot;grant_type&quot;:&quot;authorization_code&quot;,&quot;client_id&quot;:&quot;<?= e($firstClient) ?>&quot;,&quot;client_secret&quot;:&quot;你的密钥&quot;,&quot;code&quot;:&quot;回调拿到的授权码&quot;}'">curl -X POST <?= e($siteOrigin) ?>/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "authorization_code",
    "client_id": "<?= e($firstClient) ?>",
    "client_secret": "你的密钥",
    "code": "回调拿到的授权码"
  }'</pre>
          </div>
        </div>

        <div>
          <div class="row" style="gap:7px;margin-bottom:7px">
            <span class="badge b-brand">3</span><b style="font-size:13.6px">用令牌取用户身份</b>
          </div>
          <div class="code">
            <div class="code-head"><span class="code-lang">bash</span>
              <button class="copy-btn" type="button" data-copy="#c-3" data-keep-label>复制</button>
            </div>
            <pre id="c-3" data-raw="curl <?= e($siteOrigin) ?>/oauth/userinfo -H &quot;Authorization: Bearer 上一步拿到的access_token&quot;">curl <?= e($siteOrigin) ?>/oauth/userinfo \
  -H "Authorization: Bearer 上一步拿到的 access_token"</pre>
          </div>
        </div>
      </div>

      <div class="alert alert-warn mt-3" style="font-size:13px">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
        <div><b>client_secret 只能出现在服务端。</b>绝对不要写进前端 JS，也不要提交到公开仓库。
          纯前端应用请创建「公开客户端」，用 PKCE 而不是密钥。</div>
      </div>
    </div>
  </div>

  <!-- ============ 我授权的应用 ============ -->
  <?php if ($grants !== []): ?>
  <div class="card">
    <div class="card-head"><h3>我授权的应用</h3><span class="badge b-gray"><?= count($grants) ?> 个</span></div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="tbl">
          <tbody>
          <?php foreach ($grants as $g): ?>
            <tr>
              <td>
                <b style="font-size:13.5px"><?= e($g['name'] ?: $g['client_id']) ?></b>
                <div class="tiny muted mono" style="margin-top:2px"><?= e($g['scopes']) ?> · <?= e(dt((int) $g['granted_at'], 'Y-m-d')) ?></div>
              </td>
              <td class="right"><a class="btn btn-sm btn-ghost" href="/account">去管理</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-toggle]');
  if (!btn) return;
  var el = document.querySelector(btn.dataset.toggle);
  if (!el) return;
  el.hidden = !el.hidden;
  if (!el.hidden) { var f = el.querySelector('input,textarea'); if (f) f.focus(); }
});
</script>
