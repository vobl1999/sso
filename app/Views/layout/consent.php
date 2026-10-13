<?php
/**
 * 授权确认页专用布局：独立、不带任何导航 —— 这是一个「决策页」，
 * 用户应该只看到「谁在要什么权限」和两个按钮，不该被别的链接分心。
 * 视觉上参考微软账号的授权页。
 *
 * @var string $content
 * @var array  $site
 */
$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'ok';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>授权确认 · <?= e($site['name']) ?></title>
<meta name="csrf-token" content="<?= e(\Vobl\Core\Csrf::token()) ?>">
<meta name="theme-color" content="#faf8f6">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?= $content ?>
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
