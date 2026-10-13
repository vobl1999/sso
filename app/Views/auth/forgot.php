<?php
/** 忘记密码：验证码重置 + 恢复码兜底 */
$minPassword = $minPassword ?? 10;
?>
<div class="auth-card">
  <h1>找回密码</h1>
  <p class="sub">用注册邮箱接收验证码，重设登录密码</p>

  <div class="row" style="gap:6px;margin-bottom:18px">
    <button class="btn btn-sm btn-soft" type="button" data-mode-btn="email" style="flex:1">邮箱验证码</button>
    <button class="btn btn-sm btn-ghost" type="button" data-mode-btn="recovery" style="flex:1">恢复码</button>
  </div>

  <!-- ============ 邮箱验证码重置 ============ -->
  <form data-ajax action="/forgot/reset" method="post" data-pane="email" data-reload="off">
    <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

    <div class="stack-s">
      <div class="field">
        <label class="label" for="fp-email">注册邮箱</label>
        <div class="row" style="gap:8px">
          <input class="input" id="fp-email" name="email" type="email" placeholder="you@example.com" required autofocus>
          <button class="btn btn-ghost" type="button" data-action="/forgot/send" data-context data-busy-label="发送中" style="flex:0 0 auto">发送验证码</button>
        </div>
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
      <div class="field">
        <label class="label" for="fp-pass">新密码</label>
        <input class="input" id="fp-pass" name="password" type="password" autocomplete="new-password"
               placeholder="至少 <?= (int) $minPassword ?> 位" data-password-input>
        <div class="row" style="gap:4px;margin-top:9px" data-password-meter="#fp-pass">
          <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
          <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
          <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
          <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
        </div>
        <span class="hint"><b data-strength-label style="font-weight:700"></b></span>
      </div>
    </div>

    <div class="alert alert-warn mt-3" style="font-size:13px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
      <div>重置成功后，你在<b>所有设备</b>上的登录状态都会失效，需要重新登录。这是防止密码泄露后他人继续使用会话的必要措施。</div>
    </div>

    <button class="btn btn-primary btn-block mt-3" type="submit">重置密码</button>
  </form>

  <!-- ============ 恢复码重置 ============ -->
  <form data-ajax action="/forgot/recovery" method="post" data-pane="recovery" hidden data-reload="off">
    <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

    <div class="alert alert-info" style="margin-bottom:16px;font-size:13px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
      <div>收不到邮件（邮箱已停用、或已经无法访问）时用这个。恢复码是你注册后在账号中心生成的那组一次性代码。</div>
    </div>

    <div class="stack-s">
      <div class="field">
        <label class="label" for="rc-email">账号邮箱</label>
        <input class="input" id="rc-email" name="email" type="email" placeholder="you@example.com" required>
      </div>
      <div class="field">
        <label class="label" for="rc-code">恢复码</label>
        <input class="input mono" id="rc-code" name="recovery_code" placeholder="XXXXX-XXXXX-XXXXX" autocomplete="off">
        <span class="hint">每个恢复码只能使用一次。</span>
      </div>
      <div class="field">
        <label class="label" for="rc-pass">新密码</label>
        <input class="input" id="rc-pass" name="password" type="password" autocomplete="new-password"
               placeholder="至少 <?= (int) $minPassword ?> 位">
      </div>
    </div>

    <button class="btn btn-primary btn-block mt-3" type="submit">用恢复码重置</button>
  </form>
</div>

<div class="auth-foot">
  <a href="/login">← 返回登录</a>
</div>

<script>
(function () {
  var btns = document.querySelectorAll('[data-mode-btn]');
  var panes = document.querySelectorAll('[data-pane]');
  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.dataset.modeBtn;
      btns.forEach(function (b) {
        b.className = 'btn btn-sm ' + (b.dataset.modeBtn === mode ? 'btn-soft' : 'btn-ghost');
      });
      panes.forEach(function (p) { p.hidden = p.dataset.pane !== mode; });
    });
  });
})();
</script>
