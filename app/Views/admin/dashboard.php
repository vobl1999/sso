<?php
/** 后台控制台 */
$maxTrend = 1;
foreach ($trend as $t) { $maxTrend = max($maxTrend, (int) $t['users'], (int) $t['logins']); }
?>
<?php if (!empty($security['smtp']) === false): ?>
  <div class="alert alert-warn" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
    <div><b>邮件还没配置。</b>注册验证、找回密码都需要发信，请先去
      <a href="/admin/mail">邮件设置</a> 配好 SMTP 并发送一封测试邮件。</div>
  </div>
<?php endif; ?>

<?php if (($defaultGateway['code'] ?? '') === 'mock'): ?>
  <div class="alert alert-warn" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
    <div><b>当前默认支付通道是「模拟支付」。</b>它不产生真实扣款，任何人都能免费充值。
      上线前请去 <a href="/admin/payment">支付通道</a> 绑定真实通道并停用模拟通道。</div>
  </div>
<?php endif; ?>

<?php if (!$security['require_2fa_admin'] && $security['passkey_total'] === 0): ?>
  <div class="alert alert-info" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
    <div>还没有任何管理员启用安全密钥，也没有强制两步验证。
      管理员权限最大，建议在 <a href="/admin/settings">系统设置</a> 里开启「管理员强制两步验证」。</div>
  </div>
<?php endif; ?>

<!-- 用户概况 -->
<h3 style="margin-bottom:11px;font-size:15px">用户</h3>
<div class="kpis" style="margin-bottom:20px">
  <div class="kpi"><b><?= number_format((int) $userStats['total']) ?></b><span>用户总数</span></div>
  <div class="kpi"><b><?= number_format((int) $userStats['active']) ?></b><span>状态正常</span></div>
  <div class="kpi"><b><?= number_format((int) $userStats['new_today']) ?></b><span>今日新增</span></div>
  <div class="kpi"><b><?= number_format((int) $userStats['with_passkey']) ?></b><span>已启用安全密钥</span></div>
  <div class="kpi"><b><?= number_format((int) $userStats['last_24h_login']) ?></b><span>24 小时内登录</span></div>
</div>

<!-- 支付概况 -->
<h3 style="margin-bottom:11px;font-size:15px">收入</h3>
<div class="kpis" style="margin-bottom:20px">
  <div class="kpi"><b>¥<?= e(number_format((int) $payment['today_amount'] / 100, 2)) ?></b><span>今日入账</span></div>
  <div class="kpi"><b><?= (int) $payment['today_count'] ?></b><span>今日订单</span></div>
  <div class="kpi"><b>¥<?= e(number_format((int) $payment['total_amount'] / 100, 2)) ?></b><span>累计入账</span></div>
  <div class="kpi"><b>¥<?= e(number_format((int) $payment['balance_total'] / 100, 2)) ?></b><span>用户余额合计</span></div>
</div>

<div class="stack">

  <!-- 趋势 -->
  <div class="card">
    <div class="card-head"><h3>最近 7 天</h3>
      <span class="spacer"></span>
      <span class="badge b-brand">新增用户</span>
      <span class="badge b-gray">登录次数</span>
    </div>
    <div class="card-body">
      <div style="display:flex;align-items:flex-end;gap:14px;height:130px">
        <?php foreach ($trend as $t): ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%">
            <div style="flex:1;width:100%;display:flex;align-items:flex-end;gap:3px;justify-content:center">
              <div title="新增 <?= (int) $t['users'] ?>"
                   style="width:42%;max-width:22px;border-radius:5px 5px 0 0;background:var(--grad);
                          height:<?= max(3, (int) round((int) $t['users'] / $maxTrend * 100)) ?>%"></div>
              <div title="登录 <?= (int) $t['logins'] ?>"
                   style="width:42%;max-width:22px;border-radius:5px 5px 0 0;background:var(--deco);
                          height:<?= max(3, (int) round((int) $t['logins'] / $maxTrend * 100)) ?>%"></div>
            </div>
            <span class="tiny muted"><?= e($t['date']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- 安全状况 -->
  <div class="card">
    <div class="card-head"><h3>安全状况</h3></div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="tbl">
          <tbody>
            <tr>
              <td>近 24 小时登录失败</td>
              <td class="right">
                <span class="badge <?= (int) $security['fail_last_24h'] > 20 ? 'b-warn' : 'b-ok' ?>">
                  <?= (int) $security['fail_last_24h'] ?> 次
                </span>
              </td>
              <td class="tiny muted" style="width:44%">
                次数突然升高可能意味着有人在爆破。超过 20 次建议查看<a href="/admin/audit?category=auth">登录审计</a>。
              </td>
            </tr>
            <tr>
              <td>近 24 小时被拒绝的请求</td>
              <td class="right"><span class="badge <?= (int) $security['denied_last_24h'] > 10 ? 'b-warn' : 'b-gray' ?>"><?= (int) $security['denied_last_24h'] ?> 次</span></td>
              <td class="tiny muted">包含越权访问、CSRF 校验失败、签名不通过等。</td>
            </tr>
            <tr>
              <td>邮件发送</td>
              <td class="right"><span class="badge <?= $security['smtp'] ? 'b-ok' : 'b-danger' ?>"><?= $security['smtp'] ? '已配置' : '未配置' ?></span></td>
              <td class="tiny muted">未配置时注册验证与找回密码不可用。</td>
            </tr>
            <tr>
              <td>安全密钥登录</td>
              <td class="right"><span class="badge <?= $security['webauthn'] ? 'b-ok' : 'b-gray' ?>"><?= $security['webauthn'] ? '已启用' : '已关闭' ?></span></td>
              <td class="tiny muted">共 <?= (int) $security['passkey_total'] ?> 把密钥已注册。</td>
            </tr>
            <tr>
              <td>管理员强制两步验证</td>
              <td class="right"><span class="badge <?= $security['require_2fa_admin'] ? 'b-ok' : 'b-warn' ?>"><?= $security['require_2fa_admin'] ? '已开启' : '未开启' ?></span></td>
              <td class="tiny muted">建议开启。<a href="/admin/settings">去设置</a></td>
            </tr>
            <tr>
              <td>接入应用</td>
              <td class="right"><span class="badge b-gray"><?= (int) $clientCount ?> 个</span></td>
              <td class="tiny muted"><a href="/admin/clients">查看全部</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 最近动态 -->
  <div class="card">
    <div class="card-head"><h3>最近动态</h3><span class="spacer"></span>
      <a class="btn btn-sm btn-ghost" href="/admin/audit">查看全部日志</a>
    </div>
    <div class="table-wrap" style="border:0;border-radius:0">
      <table class="tbl">
        <thead><tr><th style="width:16%">时间</th><th style="width:14%">分类</th><th style="width:22%">操作</th><th>详情</th></tr></thead>
        <tbody>
        <?php if ($recent === []): ?>
          <tr><td colspan="4" class="muted small" style="padding:20px">还没有任何记录。</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $e): ?>
            <tr>
              <td class="tiny muted nowrap"><?= e(ago((int) $e['ts'])) ?></td>
              <td>
                <?php
                $catLabels = ['auth'=>'登录','admin'=>'后台','security'=>'安全','payment'=>'支付','smtp'=>'邮件','oauth'=>'SSO'];
                $catCls = ['auth'=>'b-ok','admin'=>'b-gray','security'=>'b-danger','payment'=>'b-brand','smtp'=>'b-gray','oauth'=>'b-brand'];
                ?>
                <span class="badge <?= e($catCls[(string) $e['category']] ?? 'b-gray') ?>">
                  <?= e($catLabels[(string) $e['category']] ?? $e['category']) ?>
                </span>
              </td>
              <td class="tiny mono"><?= e($e['action']) ?></td>
              <td class="small muted">
                <?= e($e['detail']) ?>
                <?php if ((string) $e['result'] !== 'ok'): ?>
                  <span class="badge b-danger" style="margin-left:5px"><?= (string) $e['result'] === 'denied' ? '已拒绝' : '失败' ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
