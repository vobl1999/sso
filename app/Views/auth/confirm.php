<?php
/** 二次验证页：敏感操作前的身份再确认 */
$action = $action ?? '继续';
$hasPasskey = $hasPasskey ?? false;
?>
<div class="auth-card">
  <div class="row" style="gap:11px;margin-bottom:16px">
    <span style="width:38px;height:38px;border-radius:12px;background:var(--info-soft);color:var(--accent-text);display:grid;place-items:center;flex:0 0 auto">
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
    </span>
    <div>
      <h1 style="font-size:19px">请确认是你本人</h1>
      <p class="small muted" style="margin:0">即将执行：<?= e($action) ?></p>
    </div>
  </div>

  <p class="small muted" style="margin-bottom:18px">
    这是一个安全敏感操作。为了确认操作者确实是账号本人（而不是拿着你电脑的其他人），需要你重新验证一次身份。
  </p>

  <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

  <!-- 密码验证 -->
  <form data-ajax action="/confirm" method="post" data-reload="off" id="confirm-form">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <input type="hidden" name="mode" value="password">
    <div class="field">
      <label class="label" for="cf-pass">当前登录密码</label>
      <input class="input" id="cf-pass" name="password" type="password"
             autocomplete="current-password" placeholder="输入密码以继续" required autofocus>
    </div>
    <button class="btn btn-primary btn-block mt-3" type="submit">确认</button>
  </form>

  <?php if ($hasPasskey && !empty($passkeyEnabled)): ?>
    <div class="divider">或</div>
    <button class="btn btn-ghost btn-block" type="button" id="confirm-passkey">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9M17 6l2 2"/></svg>
      使用安全密钥 / 指纹验证
    </button>
  <?php endif; ?>

  <p class="hint mt-3">
    验证通过后 15 分钟内不再重复询问。这不会影响你其他设备上的登录状态。
  </p>
</div>

<div class="auth-foot"><a href="<?= e($next ?: '/account') ?>">取消并返回</a></div>

<script>
(function () {
  var btn = document.getElementById('confirm-passkey');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    window.busy(btn, true, '等待验证…');
    try {
      var begin = await window.api.post('/login/passkey/begin', {});
      var opt = begin.data;
      opt.challenge = window.__b64uToBuf(opt.challenge);
      if (opt.allowCredentials) opt.allowCredentials.forEach(function (c) { c.id = window.__b64uToBuf(c.id); });
      var cred = await navigator.credentials.get({ publicKey: opt });
      var payload = {
        mode: 'passkey',
        next: <?= json_encode($next) ?>,
        id: cred.id,
        rawId: window.__bufToB64u(cred.rawId),
        type: cred.type,
        clientDataJSON: window.__bufToB64u(cred.response.clientDataJSON),
        authenticatorData: window.__bufToB64u(cred.response.authenticatorData),
        signature: window.__bufToB64u(cred.response.signature),
        userHandle: cred.response.userHandle ? window.__bufToB64u(cred.response.userHandle) : null,
      };
      var r = await window.api.post('/confirm', payload);
      window.toast('验证通过', 'ok', 1000);
      setTimeout(function () { location.href = (r.data && r.data.redirect) || <?= json_encode($next) ?>; }, 400);
    } catch (err) {
      window.busy(btn, false);
      window.toast(err.message || '验证失败', 'err', 5000);
    }
  });
})();
</script>
