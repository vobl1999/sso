<?php
/**
 * 安装引导
 * @var array $checks
 * @var bool  $allOk
 * @var bool  $allowed
 * @var bool  $hasSuperAdmin
 */
?>
<div class="wrap wrap-mid" style="padding-top:40px;padding-bottom:40px">
  <div class="row" style="gap:11px;margin-bottom:24px">
    <span style="width:40px;height:40px;border-radius:13px;background:var(--grad);color:var(--brand-ink);display:grid;place-items:center;flex:0 0 auto">
      <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M12 11.6V20"/></svg>
    </span>
    <div>
      <h1 style="font-size:23px">安装 <?= e($site['name']) ?></h1>
      <p class="small muted" style="margin:0">创建第一个管理员账号，开始使用</p>
    </div>
  </div>

  <!-- 环境自检 -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-head">
      <h3>环境自检</h3>
      <span class="spacer"></span>
      <?php if ($allOk): ?>
        <span class="badge b-ok"><i class="dot"></i>全部通过</span>
      <?php else: ?>
        <span class="badge b-danger"><i class="dot"></i>有项目未通过</span>
      <?php endif; ?>
    </div>
    <div class="table-wrap" style="border:0;border-radius:0">
      <table class="tbl">
        <tbody>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td style="width:44%">
              <b style="font-size:13.3px"><?= e($c['label']) ?></b>
              <?php if (!empty($c['hint'])): ?>
                <div class="tiny muted"><?= e($c['hint']) ?></div>
              <?php endif; ?>
            </td>
            <td><span class="mono tiny"><?= e($c['value']) ?></span></td>
            <td class="right">
              <?php if ($c['ok']): ?>
                <span class="badge b-ok">通过</span>
              <?php else: ?>
                <span class="badge b-danger">需处理</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($hasSuperAdmin): ?>
    <!-- 已经装好了 -->
    <div class="alert alert-ok" style="margin-bottom:18px">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      <div>
        <b>系统已经安装完成。</b><br>
        管理员账号已存在，安装入口已自动关闭（这是防止他人创建管理员的必要措施）。
      </div>
    </div>
    <a class="btn btn-primary" href="/login">前往登录</a>

  <?php elseif (!$allowed): ?>
    <div class="alert alert-warn" style="margin-bottom:18px">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
      <div>
        <b>安装入口已被配置关闭。</b><br>
        打开 <code>config/config.php</code>，把 <code>install.allow_bootstrap_admin</code> 改为 <code>true</code>，
        创建完管理员后再改回 <code>false</code>。
      </div>
    </div>

  <?php else: ?>
    <!-- 创建管理员 -->
    <div class="card">
      <div class="card-head"><h3>创建超级管理员</h3></div>
      <div class="card-body">
        <?php if (!$allOk): ?>
          <div class="alert alert-danger" style="margin-bottom:16px">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            <div>上面还有未通过的项目，建议先处理完再继续。数据库表不存在时创建会失败。</div>
          </div>
        <?php endif; ?>

        <form data-ajax action="/install" method="post" data-reload="off">
          <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

          <div class="stack-s">
            <div class="field">
              <label class="label">管理员邮箱 <span class="req">*</span></label>
              <input class="input" name="email" type="email" placeholder="admin@example.com" required autofocus>
              <span class="hint">这个邮箱就是登录账号，也是唯一能接收密码重置的地址，请填长期可用的。</span>
            </div>
            <div class="field">
              <label class="label">显示名称</label>
              <input class="input" name="display_name" placeholder="管理员" maxlength="60">
            </div>
            <div class="field">
              <label class="label">密码 <span class="req">*</span></label>
              <input class="input" name="password" type="password" autocomplete="new-password"
                     placeholder="至少 <?= (int) $minPassword ?> 位" data-password-input required>
              <div class="row" style="gap:4px;margin-top:9px" data-password-meter="[name=password]">
                <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
                <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
                <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
                <i style="flex:1;height:4px;border-radius:3px;background:#ebe6e1"></i>
              </div>
              <span class="hint"><b data-strength-label style="font-weight:700"></b>管理员权限最大，密码请务必足够强。</span>
            </div>
          </div>

          <button class="btn btn-primary btn-lg btn-block mt-4" type="submit">创建并进入后台</button>
        </form>
      </div>
      <div class="card-foot">
        创建成功后请立刻把 <code>config/config.php</code> 里的
        <code>install.allow_bootstrap_admin</code> 改成 <code>false</code>，
        否则等于给所有人留了一个创建管理员的入口。
      </div>
    </div>

    <div class="alert alert-info mt-3" style="font-size:13px">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
      <div>
        <b>建议的下一个步骤：</b>进入后台 → <b>邮件设置</b> → 配置 SMTP 并发送一封测试邮件。
        发信不通的话，注册验证、找回密码这些功能都用不了。
      </div>
    </div>
  <?php endif; ?>
</div>
