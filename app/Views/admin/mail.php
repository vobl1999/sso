<?php
/**
 * 后台 · 邮件（SMTP）设置
 * @var array $settings
 * @var array $presets
 * @var bool  $canManage
 * @var bool  $canTest
 */
$presetJson = json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$authMethod = $settings['mail.auth_method']['plain'] ?? 'auto';
$host = $settings['mail.host']['plain'] ?? '';
$isMicrosoft = str_contains(strtolower((string) $host), 'outlook.com')
    || str_contains(strtolower((string) $host), 'hotmail.com')
    || str_contains(strtolower((string) $host), 'office365.com');
$usagePct = $dailyLimit > 0 ? min(100, (int) round($todaySent / $dailyLimit * 100)) : 0;
?>

<?php if ($isMicrosoft && $authMethod !== 'xoauth2'): ?>
  <div class="alert alert-danger" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
    <div>
      <b>当前配置发不出去信。</b><br>
      你填的是微软邮箱（Outlook / Hotmail / Microsoft 365），但认证方式不是 OAuth2。
      微软已于 <b>2026 年 4 月</b>彻底关闭 SMTP 基础认证 —— 用账号密码会被直接拒绝
      （报错 <code>535 5.7.139 basic authentication is disabled</code>），
      Outlook 的应用专用密码也已随之下线。<br>
      <span class="mt-2" style="display:inline-block">
        <b>两个选择：</b>① 下方「认证方式」改为 <code>OAuth2</code> 并按提示完成授权；
        ② 换一个支持基础认证的发信服务（腾讯企业邮 / 阿里云邮件推送 / 网易企业邮 / Resend / Brevo / SendGrid）。
      </span>
    </div>
  </div>
<?php endif; ?>

<div class="kpis" style="margin-bottom:18px">
  <div class="kpi">
    <b><?= number_format((int) $todaySent) ?></b>
    <span>今日已发 / 上限 <?= number_format((int) $dailyLimit) ?>（<?= $usagePct ?>%）</span>
  </div>
  <div class="kpi">
    <b><?= $settings['mail.host']['has_value'] ? '已配置' : '未配置' ?></b>
    <span>发信服务器</span>
  </div>
  <div class="kpi">
    <b><?= number_format((int) ($settings['mail.code_ttl']['plain'] ?? 600) / 60) ?></b>
    <span>验证码有效分钟数</span>
  </div>
</div>

<form data-ajax action="/admin/mail/save" method="post" data-reload="off">
  <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

  <div class="card" style="margin-bottom:18px">
    <div class="card-head">
      <h3>发信服务器</h3>
      <span class="spacer"></span>
      <select class="input" data-preset-select='<?= e($presetJson) ?>' style="max-width:230px">
        <option value="">— 选择服务商自动填入 —</option>
        <?php foreach ($presets as $key => $p): ?>
          <option value="<?= e($key) ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="card-body">
      <div class="form-grid">
        <div class="field">
          <label class="label">SMTP 服务器地址 <span class="req">*</span></label>
          <input class="input" name="mail.host" value="<?= e($host) ?>" placeholder="smtp.exmail.qq.com">
        </div>
        <div class="field">
          <label class="label">端口 <span class="req">*</span></label>
          <input class="input" name="mail.port" type="number" value="<?= e($settings['mail.port']['plain'] ?? '465') ?>">
          <span class="hint">465 配 SSL 直连；587 配 STARTTLS（TLS）</span>
        </div>
        <div class="field">
          <label class="label">加密方式</label>
          <select class="input" name="mail.encryption">
            <?php foreach (['ssl' => 'SSL（推荐，465 端口）', 'tls' => 'STARTTLS（587 端口）', 'none' => '不加密（不推荐）'] as $v => $label): ?>
              <option value="<?= e($v) ?>" <?= ($settings['mail.encryption']['plain'] ?? 'ssl') === $v ? 'selected' : '' ?>>
                <?= e($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">认证方式</label>
          <select class="input" name="mail.auth_method">
            <?php foreach ([
                'auto'    => '自动（先试 PLAIN 再试 LOGIN）',
                'login'   => 'AUTH LOGIN',
                'plain'   => 'AUTH PLAIN',
                'xoauth2' => 'OAuth2（微软邮箱必须选这个）',
            ] as $v => $label): ?>
              <option value="<?= e($v) ?>" <?= $authMethod === $v ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">账号 <span class="req">*</span></label>
          <input class="input" name="mail.username" value="<?= e($settings['mail.username']['plain'] ?? '') ?>"
                 placeholder="noreply@example.com" autocomplete="off">
        </div>
        <div class="field">
          <label class="label">密码 / 授权码</label>
          <input class="input" name="mail.password" type="password"
                 value="<?= e($settings['mail.password']['display'] ?? '') ?>"
                 placeholder="留空表示不修改" autocomplete="new-password">
          <span class="hint">
            <b>QQ 邮箱、163、Gmail 这里要填「授权码」而不是登录密码</b> —— 这是最常见的配置失败原因。
            密码以 AES-256-GCM 加密存储，界面只回显占位符。
          </span>
        </div>
        <div class="field">
          <label class="label">发件人地址 <span class="req">*</span></label>
          <input class="input" name="mail.from_address" value="<?= e($settings['mail.from_address']['plain'] ?? '') ?>"
                 placeholder="noreply@example.com">
          <span class="hint">必须与登录账号同域，否则多数服务商会拒信</span>
        </div>
        <div class="field">
          <label class="label">发件人显示名</label>
          <input class="input" name="mail.from_name" value="<?= e($settings['mail.from_name']['plain'] ?? 'Vobl ID') ?>">
        </div>
        <div class="field">
          <label class="label">回复地址（可选）</label>
          <input class="input" name="mail.reply_to" value="<?= e($settings['mail.reply_to']['plain'] ?? '') ?>">
        </div>
        <div class="field">
          <label class="label">连接超时（秒）</label>
          <input class="input" name="mail.timeout" type="number" value="<?= e($settings['mail.timeout']['plain'] ?? '15') ?>">
        </div>
        <div class="field">
          <label class="label">全站每日发信上限</label>
          <input class="input" name="mail.daily_limit" type="number"
                 value="<?= e($settings['mail.daily_limit']['plain'] ?? '900') ?>">
          <span class="hint">保护发信额度。被刷爆 SMTP 配额等于直接损失钱</span>
        </div>
        <div class="field">
          <label class="label">验证码有效期（秒）</label>
          <input class="input" name="mail.code_ttl" type="number" value="<?= e($settings['mail.code_ttl']['plain'] ?? '600') ?>">
        </div>
      </div>
    </div>
    <?php if ($canManage): ?>
      <div class="card-foot">
        <div class="row" style="gap:9px">
          <button class="btn btn-primary" type="submit">保存配置</button>
          <span class="small muted">保存后立即生效，不需要重启或重新部署。</span>
        </div>
      </div>
    <?php endif; ?>
  </div>
</form>

<!-- 测试 -->
<?php if ($canTest): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-head"><h3>测试发信</h3></div>
  <div class="card-body">
    <p class="small muted" style="margin-bottom:14px">
      这是整个系统最容易配错的一环，务必点一次验证。先测连接（不发信），通了再发真实邮件。
    </p>
    <div class="row-wrap" style="gap:9px">
      <button class="btn btn-ghost" type="button" data-action="/admin/mail/test"
              data-busy-label="连接中…" data-reload="off" id="btn-test">测试连接</button>
      <input class="input" id="test-to" placeholder="收件邮箱（留空则发给自己的账号邮箱）" style="max-width:300px">
      <button class="btn btn-primary" type="button" data-action="/admin/mail/send-test"
              data-busy-label="发送中…" data-reload="off" id="btn-send-test">发送测试邮件</button>
    </div>

    <div id="test-result" hidden style="margin-top:16px">
      <div class="code">
        <div class="code-head">
          <span class="code-lang">smtp transcript</span>
          <button class="copy-btn" type="button" data-copy="#test-log" data-keep-label>复制</button>
        </div>
        <pre id="test-log" data-raw="" style="max-height:320px"></pre>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 常见问题 -->
<div class="card">
  <div class="card-head"><h3>配置常见问题</h3></div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="tbl">
        <tbody>
          <tr>
            <td style="width:36%"><b style="font-size:13.3px">报错 535 / 认证失败</b></td>
            <td class="small muted">
              密码要填「授权码」而不是登录密码。QQ 邮箱在「设置 → 账户 → POP3/IMAP/SMTP 服务」里生成；
              163 类似。另外确认服务商后台已开启 SMTP 服务。
            </td>
          </tr>
          <tr>
            <td><b style="font-size:13.3px">报错 5.7.139 / basic authentication is disabled</b></td>
            <td class="small muted">
              用的是微软邮箱。微软已于 2026 年 4 月关闭基础认证，必须把认证方式改成 OAuth2，
              或换用其它发信服务。
            </td>
          </tr>
          <tr>
            <td><b style="font-size:13.3px">连接超时 / 连不上</b></td>
            <td class="small muted">
              ① 国内很多主机默认封了 25 端口，请改用 465（SSL）或 587（TLS）；
              ② 确认服务器能访问外网；③ 云厂商安全组/防火墙可能没放行出站端口。
            </td>
          </tr>
          <tr>
            <td><b style="font-size:13.3px">连接通了但收不到信</b></td>
            <td class="small muted">
              先看垃圾邮件文件夹。然后检查发信域名是否配置了
              <code>SPF</code>、<code>DKIM</code>、<code>DMARC</code> 三条 DNS 记录 ——
              少了它们，投递成功率会非常低，用户会以为「注册坏了」。
            </td>
          </tr>
          <tr>
            <td><b style="font-size:13.3px">STARTTLS 握手失败</b></td>
            <td class="small muted">
              端口与加密方式不匹配。465 必须用 SSL，587 才用 STARTTLS。
              两者填反是最常见的低级错误。
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  function showLog(text) {
    var box = document.getElementById('test-result');
    var pre = document.getElementById('test-log');
    if (!box || !pre) return;
    box.hidden = false;
    pre.dataset.raw = text;
    pre.textContent = text;
  }

  // 测试按钮的返回里带了协议过程，需要特殊处理（不刷新页面）
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('#btn-test, #btn-send-test');
    if (!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();

    var isSend = btn.id === 'btn-send-test';
    var payload = isSend ? { to: (document.getElementById('test-to') || {}).value || '' } : {};

    window.busy(btn, true, isSend ? '发送中…' : '连接中…');
    window.api.post(btn.dataset.action, payload)
      .then(function (r) {
        window.busy(btn, false);
        window.toast(r.message || '成功', 'ok', 6000);
        showLog((r.data && r.data.transcript ? r.data.transcript.join('\n') : '(无协议日志)'));
      })
      .catch(function (err) {
        window.busy(btn, false);
        window.toast(err.message, 'err', 12000);
        showLog(err.message);
      });
  }, true);
})();
</script>
