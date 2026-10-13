<?php
/**
 * 后台 · 系统设置（站点信息 / 安全策略 / 登录方式 / 环境自检）
 * @var array $providers
 * @var array $enabledProviders
 */
$providers = $providers ?? [];
$enabledProviders = $enabledProviders ?? ['email'];
$canManage = !empty($canManage);
$env = $env ?? [];
$stats = $stats ?? [];
?>

<div class="stack">

  <!-- ============ 站点信息 ============ -->
  <div class="card">
    <div class="card-head"><h3>站点信息</h3></div>
    <div class="card-body">
      <form data-ajax action="/admin/settings/save" method="post" data-reload="off">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>
        <div class="form-grid">
          <div class="field">
            <label class="label">站点名称</label>
            <input class="input" name="site.name" value="<?= e($general['site.name']['plain'] ?? '') ?>">
          </div>
          <div class="field">
            <label class="label">备案号（可选）</label>
            <input class="input" name="site.icp" value="<?= e($general['site.icp']['plain'] ?? '') ?>">
          </div>
          <div class="field full">
            <label class="label">站点地址 <span class="req">*</span></label>
            <input class="input" name="site.url" value="<?= e($general['site.url']['plain'] ?? '') ?>"
                   placeholder="https://sso.example.com">
            <span class="hint">
              必须与用户实际访问的地址一致。它会影响三处：
              ① 邮件里的链接；② 支付回调地址；③ 第三方登录的回调地址拼接。
              换域名后一定要回来改这里，否则支付通知会收不到。
            </span>
          </div>
        </div>
        <?php if ($canManage): ?>
          <button class="btn btn-primary mt-3" type="submit">保存</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ============ 登录方式 ============ -->
  <div class="card">
    <div class="card-head">
      <h3>登录方式</h3>
      <span class="spacer"></span>
      <span class="badge b-gray"><?= count($enabledProviders) ?> 种已启用</span>
    </div>
    <div class="card-body">
      <form data-ajax action="/admin/settings/providers" method="post" data-reload="off">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>

        <div class="choice-row">
          <?php foreach ($providers as $key => $p): ?>
            <label class="choice">
              <input type="checkbox" name="providers[]" value="<?= e($key) ?>"
                     <?= in_array($key, $enabledProviders, true) ? 'checked' : '' ?>
                     <?= $key === 'email' ? 'disabled checked' : '' ?>>
              <span><?= e($p['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if (in_array('email', $enabledProviders, true)): ?>
          <input type="hidden" name="providers[]" value="email">
        <?php endif; ?>

        <div class="table-wrap mt-3">
          <table class="tbl">
            <tbody>
            <?php foreach ($providers as $key => $p): ?>
              <tr>
                <td style="width:22%"><b style="font-size:13.3px"><?= e($p['name']) ?></b></td>
                <td class="small muted"><?= e($p['desc']) ?></td>
                <td class="tiny muted mono" style="width:30%">
                  <?php if ($key !== 'email'): ?>
                    /oauth/callback/<?= e($key) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="alert alert-info mt-3" style="font-size:13px">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
          <div>
            <b>邮箱不能关闭</b> —— 它是身份主体，关掉就没法注册和登录了，服务端会强制保留。<br>
            启用第三方登录前，记得去对应平台登记回调地址（表格最后一列）。
            这个开关也是故障应急手段：某个平台出问题时，取消勾选重新保存即可摘掉它，不用回滚代码。
            <b>未绑定的第三方只能作为辅助登录方式</b>，不能替代邮箱身份。
          </div>
        </div>

        <?php if ($canManage): ?>
          <button class="btn btn-primary mt-3" type="submit">保存登录方式</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ============ 安全策略 ============ -->
  <div class="card">
    <div class="card-head"><h3>安全策略</h3></div>
    <div class="card-body">
      <form data-ajax action="/admin/settings/security" method="post" data-reload="off">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>

        <div class="form-grid">
          <div class="field">
            <label class="label">密码最小长度</label>
            <input class="input" name="security.password_min" type="number" min="6" max="64"
                   value="<?= e($security['security.password_min']['plain'] ?? '10') ?>">
            <span class="hint">建议 10 位以上。太短会被暴力破解，太长用户会写在便签上</span>
          </div>
          <div class="field">
            <label class="label">会话有效期（秒）</label>
            <input class="input" name="security.session_ttl" type="number" min="300"
                   value="<?= e($security['security.session_ttl']['plain'] ?? '604800') ?>">
            <span class="hint">604800 = 7 天。默认下发的是会话 Cookie，关掉浏览器即失效</span>
          </div>
        </div>

        <div class="stack-s mt-3">
          <label class="switch">
            <input type="checkbox" name="security.allow_register" value="1"
                   <?= ($security['security.allow_register']['plain'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span><b>开放自助注册</b><br>
              <span class="tiny muted">关闭后只能由管理员代开账号。没有邮件服务时建议关闭。</span></span>
          </label>
          <label class="switch">
            <input type="checkbox" name="security.webauthn_enabled" value="1"
                   <?= ($security['security.webauthn_enabled']['plain'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span><b>启用安全密钥 / 通行密钥</b><br>
              <span class="tiny muted">允许用户用指纹、面容、YubiKey 登录。需要 HTTPS 环境。</span></span>
          </label>
          <label class="switch">
            <input type="checkbox" name="security.require_2fa_admin" value="1"
                   <?= ($security['security.require_2fa_admin']['plain'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span><b>管理员强制两步验证</b><br>
              <span class="tiny muted">管理员权限最大，建议开启。开启后管理员登录需要额外验证一次身份。</span></span>
          </label>
          <label class="switch">
            <input type="checkbox" name="security.cookie_remember" value="1"
                   <?= ($security['security.cookie_remember']['plain'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span><b>允许「记住我」</b><br>
              <span class="tiny muted">关闭时下发会话 Cookie（关浏览器即失效），公用电脑上更安全。
                开启后勾选记住我可以保持 7 天。</span></span>
          </label>
        </div>

        <div class="alert alert-warn mt-3" style="font-size:13px">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
          <div>
            这里的策略存在数据库里，保存后立即生效，<b>不需要改配置文件、不需要重新部署</b>。
            这是刻意的设计：改一个安全策略应该是运营动作，而不是「找运维改文件再重启」。
            <br>真正需要放在配置文件里的只有那些「改了就进不去」的东西：数据库连接、会话签名密钥、配置加密密钥。
          </div>
        </div>

        <?php if ($canManage): ?>
          <button class="btn btn-primary mt-3" type="submit">保存安全策略</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ============ 环境与数据 ============ -->
  <div class="card">
    <div class="card-head"><h3>运行环境</h3></div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="tbl">
          <tbody>
            <?php
            $envRows = [
                ['PHP 版本', $env['php'] ?? PHP_VERSION, version_compare($env['php'] ?? PHP_VERSION, '7.4.0', '>=')],
                ['数据库', $env['mysql'] ?? '未知', true],
                ['OpenSSL 扩展', $env['openssl'] ?? '未知', ($env['openssl'] ?? '') === '已加载'],
                ['cURL 扩展', $env['curl'] ?? '未知', ($env['curl'] ?? '') === '已加载'],
                ['storage 目录', $env['storage'] ?? '未知', ($env['storage'] ?? '') === '可写'],
                ['调试模式', $env['debug'] ?? '未知', ($env['debug'] ?? '') === '关闭'],
                ['安装入口', $env['install'] ?? '未知', str_starts_with((string) ($env['install'] ?? ''), '已关闭')],
            ];
            foreach ($envRows as [$label, $value, $ok]):
            ?>
              <tr>
                <td style="width:30%"><b style="font-size:13.3px"><?= e($label) ?></b></td>
                <td class="mono tiny"><?= e($value) ?></td>
                <td class="right">
                  <?php if ($ok): ?>
                    <span class="badge b-ok">正常</span>
                  <?php else: ?>
                    <span class="badge b-warn">需注意</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="kpis mt-3">
        <?php foreach ([
            '用户' => $stats['users'] ?? 0, '活跃会话' => $stats['sessions'] ?? 0,
            '接入应用' => $stats['clients'] ?? 0, '安全密钥' => $stats['passkeys'] ?? 0,
            '审计日志' => $stats['audit'] ?? 0, '支付订单' => $stats['orders'] ?? 0,
        ] as $label => $value): ?>
          <div class="kpi"><b><?= number_format((int) $value) ?></b><span><?= e($label) ?></span></div>
        <?php endforeach; ?>
      </div>

      <div class="alert alert-info mt-3" style="font-size:13px">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        <div>
          <b>日常维护命令</b>（在项目根目录执行）：<br>
          <code>php tools/cli.php env:check</code> 环境自检 ·
          <code>php tools/cli.php audit:prune 180</code> 清理半年以上的日志 ·
          <code>php tools/cli.php order:close-expired</code> 关闭超时订单 ·
          <code>php tools/cli.php user:promote 邮箱</code> 紧急恢复管理员权限<br>
          建议把前两条加进宝塔的计划任务，每天跑一次。
        </div>
      </div>
    </div>
  </div>

</div>
