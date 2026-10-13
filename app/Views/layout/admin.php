<?php
/**
 * 后台布局
 * @var string $content
 * @var array  $site
 * @var array  $userCaps   当前用户的权限（由 AdminPage 注入）
 */
$caps = $userCaps ?? [];
$has = static function (string $cap) use ($caps): bool {
    return in_array('*', $caps, true) || in_array($cap, $caps, true);
};
$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'ok';
$current = $nav ?? '';
$user = $currentUser ?? null;
$initial = $user ? mb_strtoupper(mb_substr((string) ($user['display_name'] ?: $user['email']), 0, 1)) : '?';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? '管理后台') ?> · <?= e($site['name']) ?></title>
<meta name="csrf-token" content="<?= e(\Vobl\Core\Csrf::token()) ?>">
<meta name="theme-color" content="#faf8f6">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>

<div class="admin-shell">
  <aside class="admin-side">
    <a class="admin-brand" href="/admin">
      <span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M12 11.6V20"/></svg></span>
      <span><?= e($site['name']) ?></span>
    </a>

    <?php if ($has('dashboard.view')): ?>
      <span class="admin-group">总览</span>
      <a class="nav <?= $current === 'dashboard' ? 'on' : '' ?>" href="/admin">控制台</a>
    <?php endif; ?>

    <?php if ($has('user.view') || $has('role.view')): ?>
      <span class="admin-group">账号</span>
      <?php if ($has('user.view')): ?>
        <a class="nav <?= $current === 'users' ? 'on' : '' ?>" href="/admin/users">用户管理</a>
      <?php endif; ?>
      <?php if ($has('role.view')): ?>
        <a class="nav <?= $current === 'roles' ? 'on' : '' ?>" href="/admin/roles">角色与权限</a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($has('client.view')): ?>
      <span class="admin-group">接入</span>
      <a class="nav <?= $current === 'clients' ? 'on' : '' ?>" href="/admin/clients">接入应用</a>
    <?php endif; ?>

    <?php if ($has('smtp.view') || $has('payment.view') || $has('payment.order.view')): ?>
      <span class="admin-group">运营</span>
      <?php if ($has('smtp.view')): ?>
        <a class="nav <?= $current === 'mail' ? 'on' : '' ?>" href="/admin/mail">邮件设置</a>
      <?php endif; ?>
      <?php if ($has('payment.view')): ?>
        <a class="nav <?= $current === 'payment' ? 'on' : '' ?>" href="/admin/payment">支付通道</a>
      <?php endif; ?>
      <?php if ($has('payment.order.view')): ?>
        <a class="nav <?= $current === 'orders' ? 'on' : '' ?>" href="/admin/orders">订单与对账</a>
        <a class="nav <?= $current === 'wallets' ? 'on' : '' ?>" href="/admin/wallets">用户余额</a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($has('audit.view') || $has('settings.view')): ?>
      <span class="admin-group">系统</span>
      <?php if ($has('audit.view')): ?>
        <a class="nav <?= $current === 'audit' ? 'on' : '' ?>" href="/admin/audit">审计日志</a>
      <?php endif; ?>
      <?php if ($has('settings.view')): ?>
        <a class="nav <?= $current === 'settings' ? 'on' : '' ?>" href="/admin/settings">系统设置</a>
      <?php endif; ?>
    <?php endif; ?>

    <span style="flex:1"></span>
    <a class="nav" href="/developer">返回前台</a>
    <a class="nav" href="/logout">退出登录</a>
  </aside>

  <main class="admin-main">
    <header class="admin-top">
      <h1><?= e($pageTitle ?? '管理后台') ?></h1>
      <span class="spacer"></span>
      <?php if (!empty($notices)): ?>
        <?php foreach ($notices as $n): ?>
          <span class="badge b-warn"><?= e($n) ?></span>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($user): ?>
        <a class="admin-user" href="/account">
          <span class="ava"><?= e($initial) ?></span>
          <span><?= e($user['display_name'] ?: $user['email']) ?></span>
        </a>
      <?php endif; ?>
    </header>

    <div class="admin-content">
      <?php if (isset($capacityWarning) && $capacityWarning !== ''): ?>
        <div class="alert alert-warn" style="margin-bottom:18px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
          <div><?= $capacityWarning ?></div>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </div>
  </main>
</div>

<script src="/assets/app.js"></script>
<?php if ($flash !== ''): ?>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    window.toast(<?= json_encode($flash, JSON_UNESCAPED_UNICODE) ?>,
      <?= json_encode(in_array($flashType, ['ok','err','warn','info'], true) ? $flashType : 'ok') ?>, 7000);
  });
</script>
<?php endif; ?>
</body>
</html>
