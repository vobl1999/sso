<?php
/**
 * 账号中心 · 安全设置
 * ============================================================================
 * 目标：让用户一眼看清「我的账号现在有多安全」，并能自助处理全部安全项。
 *
 * 页面结构刻意按「从最重要到最次要」排：
 *   邮箱（身份本身）→ 密码 → 两步验证 → 安全密钥 → 登录方式 → 会话 → 恢复码
 *
 * @var array $user
 * @var array $sessions
 * @var array $identities
 * @var array $passkeys
 * @var array $grants
 * @var array $recovery
 * @var array $wallet
 * @var array $providers
 */
use Vobl\Services\UserService;
use Vobl\Services\Settings;

$providers = $providers ?? ['email'];
$webauthnEnabled = !empty($webauthnEnabled);
$minPassword = $minPassword ?? 10;

$boundProviders = array_column($identities, 'provider');
$initial = mb_strtoupper(mb_substr((string) ($user['display_name'] ?: $user['email']), 0, 1));

// 账号安全评分：让用户对「自己有多安全」有个直观感受
$score = 0;
$scoreTotal = 4;
if ((int) $user['email_verified'] === 1) $score++;          // 邮箱已验证
if ($user['has_password'] ?? false) $score++;        // 有密码
if ($passkeys !== []) $score++;                              // 有安全密钥
if ($recovery['left'] > 0) $score++;                         // 有恢复码
$scorePct = (int) round($score / $scoreTotal * 100);
$scoreLabel = $score >= 4 ? '很安全' : ($score >= 3 ? '良好' : ($score >= 2 ? '一般' : '需要加强'));
$scoreColor = $score >= 4 ? 'var(--ok)' : ($score >= 3 ? 'var(--ok)' : ($score >= 2 ? 'var(--warn)' : 'var(--danger)'));
?>

<!-- 账号概览 -->
<div class="card card-pad" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:18px">
    <span style="width:56px;height:56px;border-radius:50%;background:var(--grad);color:var(--brand-ink);display:grid;place-items:center;font-size:23px;font-weight:700;flex:0 0 auto">
      <?= e($initial) ?>
    </span>
    <div style="min-width:0;flex:1">
      <div class="row-wrap" style="gap:9px">
        <b style="font-size:18px"><?= e($user['display_name'] ?: $user['email']) ?></b>
        <?php if ((int) $user['email_verified'] === 1): ?>
          <span class="badge b-ok"><i class="dot"></i>邮箱已验证</span>
        <?php else: ?>
          <span class="badge b-warn"><i class="dot"></i>邮箱待验证</span>
        <?php endif; ?>
        <?php if ((int) $user['totp_enabled'] === 1): ?>
          <span class="badge b-ok">两步验证已开启</span>
        <?php endif; ?>
      </div>
      <div class="row-wrap small muted" style="gap:8px;margin-top:5px">
        <span class="mono"><?= e($user['email']) ?></span>
        <span>·</span>
        <span>注册于 <?= e(dt((int) $user['created_at'], 'Y-m-d')) ?></span>
        <span>·</span>
        <span>余额 ¥<?= e(number_format(((int) ($wallet['balance'] ?? 0)) / 100, 2)) ?></span>
      </div>
    </div>
    <div style="text-align:right;flex:0 0 auto">
      <div style="font-size:22px;font-weight:700;color:<?= $scoreColor ?>;letter-spacing:-.03em"><?= $scorePct ?>%</div>
      <div class="tiny muted">安全评分 · <?= e($scoreLabel) ?></div>
    </div>
  </div>
</div>

<div class="stack">

  <!-- ============ 邮箱 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>邮箱地址</h3>
      <?php if ((int) $user['email_verified'] === 1): ?>
        <span class="badge b-ok"><i class="dot"></i>已验证</span>
      <?php else: ?>
        <span class="badge b-warn"><i class="dot"></i>待验证</span>
      <?php endif; ?>
      <span class="spacer"></span>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="#email-form">更换邮箱</button>
    </div>
    <div class="card-body">
      <p class="small" style="margin-bottom:6px"><b class="mono" style="font-size:14px"><?= e($user['email']) ?></b></p>
      <p class="small muted" style="margin:0">
        这是你的登录凭证，也是找回密码的唯一途径，请确保它长期可用。
        换邮箱需要先验证新地址 —— 新地址验证通过前不会改动任何数据，避免打错一个字就把自己锁在门外。
      </p>

      <div id="email-form" hidden style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>
        <div class="form-grid">
          <div class="field">
            <label class="label">新邮箱地址</label>
            <div class="row" style="gap:8px">
              <input class="input" id="new-email" type="email" placeholder="new@example.com">
              <button class="btn btn-ghost" type="button" id="btn-email-send" style="flex:0 0 auto">发送验证码</button>
            </div>
          </div>
          <div class="field">
            <label class="label">新邮箱收到的验证码</label>
            <div class="row" style="gap:8px">
              <input class="input mono" id="new-email-code" maxlength="6" placeholder="6 位数字">
              <button class="btn btn-primary" type="button" id="btn-email-confirm" style="flex:0 0 auto">确认更换</button>
            </div>
          </div>
        </div>
        <p class="hint mt-2">更换成功后，我们会向你<b>原来的邮箱</b>发一封通知邮件 —— 如果你没做这个操作，这封邮件就是你发现账号被入侵的唯一机会。</p>
      </div>
    </div>
  </div>

  <!-- ============ 密码 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>登录密码</h3>
      <?php if ($user['has_password'] ?? false): ?>
        <span class="badge b-ok"><i class="dot"></i>已设置</span>
      <?php else: ?>
        <span class="badge b-danger"><i class="dot"></i>未设置</span>
      <?php endif; ?>
      <span class="spacer"></span>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="#pass-form">修改密码</button>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin:0">
        密码要求至少 <?= (int) $minPassword ?> 位。
        <b>修改密码后，其他所有设备会自动登出</b> —— 这是怀疑密码泄露时最有效的自救手段。
      </p>

      <form id="pass-form" hidden data-ajax action="/account/password" method="post" data-reload="off"
            style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>
        <div class="form-grid">
          <div class="field full">
            <label class="label">当前密码</label>
            <input class="input" name="current_password" type="password" autocomplete="current-password" required>
          </div>
          <div class="field">
            <label class="label">新密码</label>
            <input class="input" name="new_password" type="password" autocomplete="new-password"
                   data-password-input placeholder="至少 <?= (int) $minPassword ?> 位">
            <div class="row" style="gap:4px;margin-top:9px" data-password-meter="[name=new_password]">
              <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
              <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
              <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
              <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
            </div>
          </div>
          <div class="field">
            <label class="label">确认新密码</label>
            <input class="input" name="confirm_password" type="password" autocomplete="new-password">
          </div>
        </div>
        <button class="btn btn-primary mt-3" type="submit">保存新密码</button>
      </form>
    </div>
  </div>

  <!-- ============ 两步验证 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>两步验证</h3>
      <?php if ((int) $user['totp_enabled'] === 1): ?>
        <span class="badge b-ok"><i class="dot"></i>已开启</span>
      <?php else: ?>
        <span class="badge b-gray">未开启</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin:0">
        开启后，登录时除了密码还需要输入手机上的一次性验证码。
        即使密码泄露，别人也进不来 —— 这是对账号保护效果最明显的一项。
      </p>
      <?php if ((int) $user['totp_enabled'] !== 1): ?>
        <div class="alert alert-info mt-3" style="font-size:13px">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
          <div>需要先安装一个验证器应用（如 Google Authenticator、Microsoft Authenticator、1Password 等）。</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ 安全密钥 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>安全密钥与通行密钥</h3>
      <span class="badge <?= $passkeys !== [] ? 'b-ok' : 'b-gray' ?>"><?= count($passkeys) ?> 把</span>
      <span class="spacer"></span>
      <?php if ($webauthnEnabled): ?>
        <button class="btn btn-sm btn-soft" type="button" data-action="/account/passkeys/begin"
                id="btn-add-passkey" data-busy-label="准备中…" data-reload="off">添加</button>
      <?php else: ?>
        <span class="tiny muted">管理员已关闭此功能</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin-bottom:14px">
        用指纹、面容或实体安全密钥（如 YubiKey）直接登录，<b>比密码更抗钓鱼</b>：
        密钥与本站域名绑定，假网站拿不到它。
      </p>

      <div class="table-wrap">
        <table class="tbl">
          <tbody>
          <?php if ($passkeys === []): ?>
            <tr><td class="muted small" style="padding:18px">
              还没有添加任何密钥。添加后登录页会自动出现「用安全密钥登录」按钮。
            </td></tr>
          <?php else: ?>
            <?php foreach ($passkeys as $pk): ?>
              <tr>
                <td>
                  <b style="font-size:13.5px"><?= e($pk['name'] ?: '安全密钥') ?></b>
                  <div class="tiny muted" style="margin-top:2px">
                    添加于 <?= e(dt((int) $pk['created_at'], 'Y-m-d')) ?>
                    · <?= (int) $pk['backup_eligible'] === 1 ? '可同步的通行密钥' : '单设备硬件密钥' ?>
                    <?php if ((int) $pk['last_used_at'] > 0): ?>
                      · 最近使用 <?= e(ago((int) $pk['last_used_at'])) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="right">
                  <button class="btn btn-sm btn-danger" type="button" data-action="/account/passkeys/delete"
                          data-payload='<?= e(json_encode(['id' => $pk['id']])) ?>'
                          data-confirm="确定移除这把安全密钥？移除后就不能再用它登录了。"
                          data-busy-label="移除中…">移除</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (count($passkeys) === 1): ?>
        <div class="alert alert-warn mt-3" style="font-size:13px">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
          <div>这是你唯一的一把密钥。<b>系统不允许移除最后一把</b> —— 否则设备丢失就彻底进不去了。建议再添加一把备用。</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ 绑定的登录方式 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>绑定的登录方式</h3>
      <span class="badge b-gray">
        <?= count($identities) + 1 ?> / <?= count($providers) + 1 ?> 已绑定
      </span>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin-bottom:14px">绑定越多，丢一个也不怕。任意一种都能登进同一个账号。</p>

      <div class="table-wrap">
        <table class="tbl">
          <tbody>
            <tr>
              <td>
                <b style="font-size:13.5px">邮箱与密码</b>
                <div class="tiny muted" style="margin-top:2px"><?= e($user['email']) ?> · 账号主体</div>
              </td>
              <td class="right"><span class="badge b-ok"><i class="dot"></i>已绑定</span></td>
            </tr>
            <?php
            $providerNames = [
                'microsoft' => '微软账号', 'github' => 'GitHub',
                'wechat' => '微信', 'qq' => 'QQ', 'google' => 'Google',
            ];
            $available = array_diff(array_keys($providerNames), $boundProviders, ['email']);
            ?>
            <?php foreach ($identities as $idn): ?>
              <tr>
                <td>
                  <b style="font-size:13.5px"><?= e($providerNames[$idn['provider']] ?? $idn['provider']) ?></b>
                  <div class="tiny muted" style="margin-top:2px">
                    <?php if ((string) $idn['email'] !== ''): ?>
                      邮箱 <?= e($idn['email']) ?>
                      <?= (int) $idn['email_verified'] === 1 ? '（已验证）' : '（未验证）' ?>
                    <?php else: ?>
                      该平台不返回邮箱
                    <?php endif; ?>
                    · 绑定于 <?= e(dt((int) $idn['linked_at'], 'Y-m-d')) ?>
                  </div>
                </td>
                <td class="right">
                  <button class="btn btn-sm btn-ghost" type="button" data-action="/account/identities/unbind"
                          data-payload='<?= e(json_encode(['provider' => $idn['provider']])) ?>'
                          data-confirm="解绑后就不能再用它登录了，确定吗？"
                          data-busy-label="解绑中…">解绑</button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php foreach ($available as $p): ?>
              <?php if (in_array($p, $providers, true)): ?>
                <tr>
                  <td>
                    <b style="font-size:13.5px;color:var(--muted)"><?= e($providerNames[$p]) ?></b>
                    <div class="tiny muted" style="margin-top:2px">
                      <?= $p === 'wechat' || $p === 'qq'
                          ? '该平台不返回邮箱，绑定后仅作为辅助登录方式'
                          : '可返回已验证邮箱' ?>
                    </div>
                  </td>
                  <td class="right">
                    <a class="btn btn-sm btn-soft" href="/oauth/start/<?= e($p) ?>?bind=1">绑定</a>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="alert alert-warn mt-3" style="font-size:13px">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
        <div>
          <b>不允许解绑最后一种登录方式。</b>如果你只剩一种，系统会拒绝解绑 —— 否则你会把自己锁在门外。<br>
          另外，绑定与解绑都会向你<b>所有已验证邮箱</b>发送通知邮件。
        </div>
      </div>
    </div>
  </div>

  <!-- ============ 登录会话 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>登录会话</h3>
      <span class="badge b-brand"><?= count($sessions) ?> 台设备在线</span>
      <span class="spacer"></span>
      <button class="btn btn-sm btn-danger" type="button" data-action="/account/sessions/revoke-all"
              data-confirm="确定登出其他所有设备？当前设备会保留。" data-busy-label="处理中…">登出其他设备</button>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="tbl">
          <tbody>
          <?php foreach ($sessions as $s): ?>
            <tr>
              <td>
                <b style="font-size:13.5px"><?= e($s['device'] ?: '未知设备') ?></b>
                <?php if (!empty($s['is_current'])): ?>
                  <span class="badge b-ok" style="margin-left:5px"><i class="dot"></i>当前设备</span>
                <?php endif; ?>
                <div class="tiny muted mono" style="margin-top:2px">
                  <?= e($s['ip']) ?> · 最近活跃 <?= e(ago((int) $s['last_seen'])) ?>
                  · 登录于 <?= e(dt((int) $s['created_at'], 'm-d H:i')) ?>
                </div>
              </td>
              <td class="right">
                <?php if (empty($s['is_current'])): ?>
                  <button class="btn btn-sm btn-ghost" type="button" data-action="/account/sessions/revoke"
                          data-payload='<?= e(json_encode(['session_id' => $s['id']])) ?>'
                          data-busy-label="吊销中…">吊销</button>
                <?php else: ?>
                  <span class="tiny muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint mt-2">发现不认识的设备，直接吊销即可。吊销后那台设备需要重新登录。</p>
    </div>
  </div>

  <!-- ============ 恢复码 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>账号恢复码</h3>
      <?php if ($recovery['left'] > 0): ?>
        <span class="badge <?= $recovery['left'] > 3 ? 'b-ok' : 'b-warn' ?>">
          剩余 <?= (int) $recovery['left'] ?> / <?= (int) $recovery['total'] ?> 个
        </span>
      <?php else: ?>
        <span class="badge b-danger"><i class="dot"></i>尚未生成</span>
      <?php endif; ?>
      <span class="spacer"></span>
      <button class="btn btn-sm btn-soft" type="button" data-action="/account/recovery/generate"
              data-confirm="重新生成会作废之前所有的恢复码，确定吗？"
              data-busy-label="生成中…" data-reload="off">重新生成</button>
    </div>
    <div class="card-body">
      <p class="small muted" style="margin:0">
        邮箱是你的唯一身份，所以它也是最大的单点风险。
        如果哪天邮箱收不到信了（停用、离职、被回收），恢复码是你<b>唯一</b>还能拿回账号的东西。
      </p>
      <p class="small muted mt-2" style="margin:0">
        请打印出来或存进密码管理器。每个码只能用一次，重新生成会作废旧的全部。
      </p>
    </div>
  </div>

  <!-- ============ 已授权应用 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>已授权应用</h3>
      <span class="badge b-gray"><?= count($grants) ?> 个</span>
    </div>
    <div class="card-body">
      <?php if ($grants === []): ?>
        <p class="small muted" style="margin:0">还没有任何应用获得你的授权。</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <tbody>
            <?php foreach ($grants as $g): ?>
              <tr>
                <td>
                  <b style="font-size:13.5px"><?= e($g['name'] ?: $g['client_id']) ?></b>
                  <div class="tiny muted" style="margin-top:2px">
                    授权范围 <span class="mono"><?= e($g['scopes']) ?></span>
                    · <?= e(dt((int) $g['granted_at'], 'Y-m-d')) ?>
                  </div>
                </td>
                <td class="right"><span class="badge b-ok"><i class="dot"></i>已授权</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint mt-2">撤销授权后，该应用需要重新走一次单点登录。</p>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
(function () {
  // 折叠区块
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-toggle]');
    if (!btn) return;
    var el = document.querySelector(btn.dataset.toggle);
    if (!el) return;
    el.hidden = !el.hidden;
    if (!el.hidden) {
      var f = el.querySelector('input');
      if (f) f.focus();
    }
  });

  function err(msg) {
    window.toast(msg, 'err', 5000);
  }

  // 更换邮箱
  var sendBtn = document.getElementById('btn-email-send');
  if (sendBtn) {
    sendBtn.addEventListener('click', async function () {
      var email = document.getElementById('new-email').value.trim().toLowerCase();
      if (!email || email.indexOf('@') < 0) { err('请输入有效的新邮箱地址'); return; }
      window.busy(sendBtn, true, '发送中…');
      try {
        await window.api.post('/account/email/send', { new_email: email });
        window.busy(sendBtn, false);
        window.toast('验证码已发送到新邮箱，请查收', 'ok');
      } catch (e) { window.busy(sendBtn, false); err(e.message); }
    });
  }

  var confirmBtn = document.getElementById('btn-email-confirm');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', async function () {
      var email = document.getElementById('new-email').value.trim().toLowerCase();
      var code = document.getElementById('new-email-code').value.trim();
      if (!email || code.length !== 6) { err('请填写新邮箱和 6 位验证码'); return; }
      window.busy(confirmBtn, true, '确认中…');
      try {
        var r = await window.api.post('/account/email/confirm', { new_email: email, code: code });
        window.toast('登录邮箱已更换', 'ok', 1200);
        setTimeout(function () { location.reload(); }, 700);
      } catch (e) { window.busy(confirmBtn, false); err(e.message); }
    });
  }

  // 添加安全密钥
  var addPk = document.getElementById('btn-add-passkey');
  if (addPk) {
    addPk.addEventListener('click', async function () {
      if (!window.PublicKeyCredential) { err('当前浏览器不支持安全密钥'); return; }
      window.busy(addPk, true, '准备中…');
      try {
        var begin = await window.api.post('/account/passkeys/begin', {});
        var opt = begin.data;
        opt.challenge = window.__b64uToBuf(opt.challenge);
        opt.user.id = window.__b64uToBuf(opt.user.id);
        if (opt.excludeCredentials) {
          opt.excludeCredentials.forEach(function (c) { c.id = window.__b64uToBuf(c.id); });
        }
        var cred = await navigator.credentials.create({ publicKey: opt });
        var payload = {
          id: cred.id,
          rawId: window.__bufToB64u(cred.rawId),
          type: cred.type,
          clientDataJSON: window.__bufToB64u(cred.response.clientDataJSON),
          attestationObject: window.__bufToB64u(cred.response.attestationObject),
          transports: cred.response.getTransports ? cred.response.getTransports() : [],
        };
        var done = await window.api.post('/account/passkeys/finish', payload);
        window.toast(done.message || '安全密钥已添加', 'ok', 1500);
        setTimeout(function () { location.reload(); }, 900);
      } catch (e) {
        window.busy(addPk, false);
        // 把浏览器的英文错误翻成人话
        var msg = e.message || '';
        if (e.name === 'NotAllowedError') msg = '操作被取消或超时了';
        else if (e.name === 'InvalidStateError') msg = '这把安全密钥已经注册过了';
        else if (e.name === 'SecurityError') msg = '需要 HTTPS 环境，或域名不匹配';
        err(msg || '添加失败');
      }
    });
  }
})();
</script>
