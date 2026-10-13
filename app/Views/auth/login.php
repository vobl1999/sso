<?php
/**
 * 登录页
 * @var array  $providers     启用的登录方式
 * @var bool   $passkeyEnabled
 * @var bool   $allowRegister
 * @var string $next
 */
$providers = $providers ?? ['email'];
$next = $next ?? '';
$hasThirdParty = count(array_diff($providers, ['email'])) > 0;
?>
<div class="auth-card">
  <h1>登录</h1>
  <p class="sub">使用邮箱或已绑定的账号继续</p>

  <!-- 登录方式切换 -->
  <div class="row" style="gap:6px;margin-bottom:18px">
    <button class="btn btn-sm btn-soft" type="button" data-mode-btn="password" style="flex:1">密码登录</button>
    <button class="btn btn-sm btn-ghost" type="button" data-mode-btn="code" style="flex:1">验证码登录</button>
  </div>

  <!-- ============ 密码登录 ============ -->
  <form data-ajax action="/login" method="post" data-pane="password" data-reload="off">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

    <div class="stack-s">
      <div class="field">
        <label class="label" for="email">邮箱地址</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username"
               placeholder="you@example.com" required autofocus>
      </div>
      <div class="field">
        <label class="label" for="password">密码</label>
        <input class="input" id="password" name="password" type="password"
               autocomplete="current-password" placeholder="••••••••" required>
      </div>
    </div>

    <div class="row mt-3" style="justify-content:space-between">
      <label class="switch" style="font-size:13px">
        <input type="checkbox" name="remember" value="1">
        <span class="muted">记住我</span>
      </label>
      <a href="/forgot" class="small">忘记密码？</a>
    </div>

    <button class="btn btn-primary btn-block mt-3" type="submit">登录</button>
  </form>

  <!-- ============ 验证码登录 ============ -->
  <form data-ajax action="/login/code" method="post" data-pane="code" hidden data-reload="off">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

    <div class="stack-s">
      <div class="field">
        <label class="label" for="login-email">邮箱地址</label>
        <div class="row" style="gap:8px">
          <input class="input" id="login-email" name="email" type="email"
                 autocomplete="username" placeholder="you@example.com" required>
          <button class="btn btn-ghost" type="button" data-action="/login/code/send"
                  data-context data-busy-label="发送中" style="flex:0 0 auto">发送验证码</button>
        </div>
        <span class="hint">验证码 10 分钟内有效。收不到的话别忘了看垃圾邮件文件夹。</span>
      </div>
      <div class="field">
        <label class="label">邮箱验证码</label>
        <div class="code-inputs" data-code-group data-autosubmit="off">
          <input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1">
          <input type="text" inputmode="numeric" maxlength="1">
          <input type="text" inputmode="numeric" maxlength="1">
          <input type="text" inputmode="numeric" maxlength="1">
          <input type="text" inputmode="numeric" maxlength="1">
          <input type="hidden" name="code" value="">
        </div>
      </div>
    </div>

    <button class="btn btn-primary btn-block mt-3" type="submit">登录</button>
  </form>

  <!-- ============ 安全密钥登录 ============ -->
  <?php if (!empty($passkeyEnabled)): ?>
    <button class="btn btn-ghost btn-block mt-3" type="button" id="passkey-login">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9M17 6l2 2M14 9l2 2"/></svg>
      用安全密钥 / 指纹登录
    </button>
  <?php endif; ?>

  <!-- ============ 第三方登录 ============ -->
  <?php if ($hasThirdParty): ?>
    <div class="divider">或使用以下账号</div>
    <div class="prov-grid">
      <?php if (in_array('microsoft', $providers, true)): ?>
        <a class="prov" href="/oauth/start/microsoft<?= $next !== '' ? '?next=' . urlencode($next) : '' ?>">
          <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true">
            <rect x="2" y="2" width="9" height="9" fill="#f25022"/>
            <rect x="13" y="2" width="9" height="9" fill="#7fba00"/>
            <rect x="2" y="13" width="9" height="9" fill="#00a4ef"/>
            <rect x="13" y="13" width="9" height="9" fill="#ffb900"/>
          </svg>
          <span>微软</span>
        </a>
      <?php endif; ?>
      <?php if (in_array('github', $providers, true)): ?>
        <a class="prov" href="/oauth/start/github<?= $next !== '' ? '?next=' . urlencode($next) : '' ?>">
          <svg viewBox="0 0 16 16" width="16" height="16" fill="#24292e" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
          <span>GitHub</span>
        </a>
      <?php endif; ?>
      <?php if (in_array('wechat', $providers, true)): ?>
        <a class="prov" href="/oauth/start/wechat"><span class="brand-dot" style="width:17px;height:17px;border-radius:6px;background:#07c160;color:#fff;display:grid;place-items:center;font-size:10px;font-weight:700">微</span><span>微信</span></a>
      <?php endif; ?>
      <?php if (in_array('qq', $providers, true)): ?>
        <a class="prov" href="/oauth/start/qq"><span class="brand-dot" style="width:17px;height:17px;border-radius:6px;background:#12b7f5;color:#fff;display:grid;place-items:center;font-size:10px;font-weight:700">Q</span><span>QQ</span></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="row mt-3" style="gap:7px;font-size:12.4px;color:var(--muted)">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ok);flex:0 0 14px"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
    登录状态仅保存在本次会话，离开后自动失效
  </div>
</div>

<div class="auth-foot">
  <?php if (!empty($allowRegister)): ?>
    还没有账号？<a href="/register">注册新账号</a>
  <?php else: ?>
    账号需由管理员开通
  <?php endif; ?>
</div>

<script>
(function () {
  // 登录方式切换
  var btns = document.querySelectorAll('[data-mode-btn]');
  var panes = document.querySelectorAll('[data-pane]');
  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.dataset.modeBtn;
      btns.forEach(function (b) {
        var on = b.dataset.modeBtn === mode;
        b.className = 'btn btn-sm ' + (on ? 'btn-soft' : 'btn-ghost');
      });
      panes.forEach(function (p) { p.hidden = p.dataset.pane !== mode; });
      var first = document.querySelector('[data-pane="' + mode + '"] input:not([type=hidden])');
      if (first) first.focus();
    });
  });

  // 安全密钥登录
  var pk = document.getElementById('passkey-login');
  if (pk) {
    pk.addEventListener('click', async function () {
      if (!window.PublicKeyCredential) {
        window.toast('当前浏览器不支持安全密钥', 'err');
        return;
      }
      window.busy(pk, true, '等待验证…');
      try {
        var begin = await window.api.post('/login/passkey/begin', {});
        var opt = begin.data;
        opt.challenge = b64uToBuf(opt.challenge);
        if (opt.allowCredentials) {
          opt.allowCredentials.forEach(function (c) { c.id = b64uToBuf(c.id); });
        }
        var cred = await navigator.credentials.get({ publicKey: opt });
        var payload = {
          id: cred.id,
          rawId: bufToB64u(cred.rawId),
          type: cred.type,
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          authenticatorData: bufToB64u(cred.response.authenticatorData),
          signature: bufToB64u(cred.response.signature),
          userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null,
        };
        var done = await window.api.post('/login/passkey/finish', payload);
        window.toast(done.message || '登录成功', 'ok', 1200);
        setTimeout(function () { location.href = done.data.redirect; }, 500);
      } catch (err) {
        window.busy(pk, false);
        window.toast(err.message || '安全密钥登录失败', 'err', 5000);
      }
    });
  }

  // base64url 与 ArrayBuffer 互转（WebAuthn 需要二进制）
  function b64uToBuf(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  window.__b64uToBuf = b64uToBuf;
  window.__bufToB64u = bufToB64u;
})();
</script>
