<?php
/** 注册页：三步走（填邮箱 → 验码 → 设密码），每一步只让用户做一件事 */
$minPassword = $minPassword ?? 10;
?>
<div class="auth-card">
  <h1>创建账号</h1>
  <p class="sub">用邮箱注册，之后也可以绑定微软或 GitHub 账号</p>

  <!-- 步骤指示 -->
  <div class="row" style="gap:6px;margin-bottom:20px">
    <span class="badge b-brand" data-step-badge="1">1 填写邮箱</span>
    <span class="badge b-gray" data-step-badge="2">2 验证邮箱</span>
    <span class="badge b-gray" data-step-badge="3">3 设置密码</span>
  </div>

  <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

  <!-- ============ 第一步 ============ -->
  <div data-step="1">
    <div class="field">
      <label class="label" for="reg-email">邮箱地址 <span class="req">*</span></label>
      <input class="input" id="reg-email" type="email" placeholder="you@example.com" autocomplete="email" autofocus>
      <span class="hint">这个邮箱就是你的登录账号，请填写常用邮箱。</span>
    </div>
    <div class="field mt-3">
      <label class="label" for="reg-name">显示名称 <span class="muted" style="font-weight:400">（可留空）</span></label>
      <input class="input" id="reg-name" type="text" placeholder="别人看到的名字" maxlength="60">
    </div>
    <button class="btn btn-primary btn-block mt-4" type="button" id="btn-send">发送验证码</button>
    <p class="hint mt-2">验证码 10 分钟内有效，同一邮箱 60 秒内只能重发一次。</p>
  </div>

  <!-- ============ 第二步 ============ -->
  <div data-step="2" hidden>
    <p class="small muted" style="margin-bottom:14px">
      验证码已发送至 <b style="color:var(--ink)" id="echo-email"></b>
    </p>
    <div class="field">
      <label class="label">邮箱验证码</label>
      <div class="code-inputs" data-code-group data-autosubmit="off">
        <input type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
        <input type="text" inputmode="numeric" maxlength="1">
        <input type="text" inputmode="numeric" maxlength="1">
        <input type="text" inputmode="numeric" maxlength="1">
        <input type="text" inputmode="numeric" maxlength="1">
        <input type="text" inputmode="numeric" maxlength="1">
        <input type="hidden" id="reg-code" value="">
      </div>
      <span class="hint">支持直接粘贴整串验证码，会自动填入。</span>
    </div>
    <button class="btn btn-primary btn-block mt-4" type="button" id="btn-verify">验证并继续</button>
    <p class="hint mt-2">
      没收到？<a href="#" id="btn-resend">重新发送</a> ·
      顺手检查一下垃圾邮件文件夹，第一次收我们的邮件容易被误判。
    </p>
    <button class="btn btn-link mt-2" type="button" id="btn-back-1">← 换个邮箱</button>
  </div>

  <!-- ============ 第三步 ============ -->
  <div data-step="3" hidden>
    <div class="field">
      <label class="label" for="reg-pass">设置密码 <span class="req">*</span></label>
      <input class="input" id="reg-pass" type="password" autocomplete="new-password"
             placeholder="至少 <?= (int) $minPassword ?> 位" data-password-input>
      <div class="row" style="gap:4px;margin-top:9px" data-password-meter="#reg-pass">
        <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
        <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
        <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
        <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
      </div>
      <span class="hint"><b data-strength-label style="font-weight:700"></b>
        至少 <?= (int) $minPassword ?> 位，建议混合大小写、数字和符号。不要用「password」这类常见词。</span>
    </div>
    <div class="field mt-3">
      <label class="label" for="reg-pass2">再输一次 <span class="req">*</span></label>
      <input class="input" id="reg-pass2" type="password" autocomplete="new-password" placeholder="确认密码">
    </div>
    <button class="btn btn-primary btn-block mt-4" type="button" id="btn-complete">完成注册</button>
    <p class="hint mt-2">注册即表示同意本站的服务条款与隐私政策。</p>
  </div>
</div>

<div class="auth-foot">
  已经有账号了？<a href="/login">直接登录</a>
</div>

<script>
(function () {
  var state = { email: '', code: '' };
  var err = document.querySelector('[data-error]');

  function showErr(msg) {
    err.hidden = false;
    err.textContent = msg;
    err.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  function clearErr() { err.hidden = true; }

  function step(n) {
    document.querySelectorAll('[data-step]').forEach(function (el) {
      el.hidden = el.dataset.step !== String(n);
    });
    document.querySelectorAll('[data-step-badge]').forEach(function (b) {
      var i = parseInt(b.dataset.stepBadge, 10);
      b.className = 'badge ' + (i === n ? 'b-brand' : i < n ? 'b-ok' : 'b-gray');
    });
    var first = document.querySelector('[data-step="' + n + '"] input:not([type=hidden])');
    if (first) first.focus();
  }

  var emailInput = document.getElementById('reg-email');

  document.getElementById('btn-send').addEventListener('click', async function (e) {
    clearErr();
    var email = emailInput.value.trim().toLowerCase();
    if (!email || email.indexOf('@') < 0) { showErr('请输入有效的邮箱地址'); return; }
    var btn = e.currentTarget;
    window.busy(btn, true, '发送中…');
    try {
      await window.api.post('/register/send', { email: email });
      state.email = email;
      document.getElementById('echo-email').textContent = email;
      window.busy(btn, false);
      window.toast('验证码已发送，请查收邮件', 'ok');
      step(2);
    } catch (e2) {
      window.busy(btn, false);
      showErr(e2.message);
    }
  });

  document.getElementById('btn-resend').addEventListener('click', async function (e) {
    e.preventDefault();
    clearErr();
    try {
      await window.api.post('/register/send', { email: state.email });
      window.toast('验证码已重新发送', 'ok');
    } catch (e2) { showErr(e2.message); }
  });

  document.getElementById('btn-back-1').addEventListener('click', function () { step(1); });

  document.getElementById('btn-verify').addEventListener('click', async function (e) {
    clearErr();
    state.code = document.getElementById('reg-code').value;
    if (state.code.length !== 6) { showErr('请输入 6 位验证码'); return; }
    var btn = e.currentTarget;
    window.busy(btn, true, '验证中…');
    try {
      await window.api.post('/register/verify', { email: state.email, code: state.code });
      window.busy(btn, false);
      window.toast('邮箱验证通过', 'ok');
      step(3);
    } catch (e2) {
      window.busy(btn, false);
      showErr(e2.message);
    }
  });

  document.getElementById('btn-complete').addEventListener('click', async function (e) {
    clearErr();
    var p1 = document.getElementById('reg-pass').value;
    var p2 = document.getElementById('reg-pass2').value;
    if (p1.length < <?= (int) $minPassword ?>) { showErr('密码至少需要 <?= (int) $minPassword ?> 位'); return; }
    if (p1 !== p2) { showErr('两次输入的密码不一致'); return; }
    var btn = e.currentTarget;
    window.busy(btn, true, '创建中…');
    try {
      var r = await window.api.post('/register/complete', {
        email: state.email,
        code: state.code,
        password: p1,
        display_name: document.getElementById('reg-name').value.trim(),
      });
      window.toast(r.message || '注册成功', 'ok', 1200);
      setTimeout(function () { location.href = (r.data && r.data.redirect) || '/account'; }, 600);
    } catch (e2) {
      window.busy(btn, false);
      showErr(e2.message);
    }
  });

  emailInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('btn-send').click();
  });
})();
</script>
