<?php
/**
 * 基础布局：所有页面共用。$content 由 View::render 注入。
 * @var string $content
 * @var array  $site
 */
use Vobl\Core\Response;

$flash = $_GET['flash'] ?? '';
$flashType = $_GET['flash_type'] ?? 'ok';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? $site['name']) ?></title>
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
