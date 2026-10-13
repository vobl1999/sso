<?php
/**
 * 认证页布局：登录 / 注册 / 找回密码 / 账号中心
 * @var string $content
 * @var array  $site
 */
$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'ok';
$withSidebar = !empty($withSidebar);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? '账号') ?> · <?= e($site['name']) ?></title>
<meta name="csrf-token" content="<?= e(\Vobl\Core\Csrf::token()) ?>">
<meta name="theme-color" content="#faf8f6">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>

<?php if ($withSidebar): ?>
<div class="admin-shell">
  <aside class="admin-side">
    <a class="admin-brand" href="/developer">
      <span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M12 11.6V20"/></svg></span>
      <span><?= e($site['name']) ?></span>
    </a>
    <span class="admin-group">我的</span>
    <a class="nav <?= ($nav ?? '') === 'developer' ? 'on' : '' ?>" href="/developer">开放平台</a>
    <a class="nav <?= ($nav ?? '') === 'wallet' ? 'on' : '' ?>" href="/wallet">充值与余额</a>
    <a class="nav <?= ($nav ?? '') === 'account' ? 'on' : '' ?>" href="/account">账号与安全</a>
    <span class="admin-group">其他</span>
    <a class="nav" href="/docs" target="_blank">接入文档</a>
    <?php if (!empty($currentUser) && \Vobl\Core\Auth::isAdmin()): ?>
      <a class="nav" href="/admin">进入管理后台</a>
    <?php endif; ?>
    <span style="flex:1"></span>
    <a class="nav" href="/logout">退出登录</a>
  </aside>
  <main class="admin-main">
    <header class="admin-top">
      <h1><?= e($pageTitle ?? '') ?></h1>
      <span class="spacer"></span>
      <?php if (!empty($wallet)): ?>
        <span class="badge b-brand">余额 ¥<?= e(number_format(((int) $wallet['balance']) / 100, 2)) ?></span>
      <?php endif; ?>
      <?php if (!empty($currentUser)): ?>
        <a class="admin-user" href="/account">
          <span class="ava"><?= e(mb_strtoupper(mb_substr((string) ($currentUser['display_name'] ?: $currentUser['email']), 0, 1))) ?></span>
          <span><?= e($currentUser['display_name'] ?: $currentUser['email']) ?></span>
        </a>
      <?php endif; ?>
    </header>
    <div class="admin-content"><?= $content ?></div>
  </main>
</div>
<?php else: ?>
<div class="auth-shell">
  <a class="auth-logo" href="/">
    <span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M12 11.6V20"/></svg></span>
    <b><?= e($site['name']) ?></b>
  </a>
  <?= $content ?>
  <footer class="foot">
    <?php if ($site['url'] !== ''): ?><a href="<?= e($site['url']) ?>"><?= e($site['url']) ?></a><?php endif; ?>
    <?php if ($site['icp'] !== ''): ?> · <?= e($site['icp']) ?><?php endif; ?>
  </footer>
</div>
<?php endif; ?>

<script src="/assets/app.js"></script>
<?php if ($flash !== ''): ?>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    window.toast(<?= json_encode($flash, JSON_UNESCAPED_UNICODE) ?>,
      <?= json_encode(in_array($flashType, ['ok','err','warn','info'], true) ? $flashType : 'ok') ?>);
  });
</script>
<?php endif; ?>
</body>
</html>
