<?php
/**
 * 授权确认页 —— 微软账号风格的独立决策页
 * ============================================================================
 * 这个页面存在的意义：用户有权知道「哪个应用、要哪些信息」。
 *
 * 设计上刻意做成「封闭决策页」：
 *   · 不带任何导航链接，用户只能做「接受」或「拒绝」这一个决定
 *   · 不显示广告、推荐、其他入口 —— 任何多余元素都会削弱「授权」这个动作的严肃感
 *   · 权限逐条列出并用人话解释，而不是只丢一串 scope 名字
 *
 * @var array  $client
 * @var array  $user
 * @var array  $scopeItems
 * @var string $scopeString
 * @var string $redirectUri
 * @var string $state
 * @var string $challenge
 * @var array  $otherAccounts
 * @var bool   $isFirstTime
 */
$accent = (string) ($client['accent'] ?? 'indigo');
$accentMap = [
    'indigo' => ['#6366f1', '#818cf8'], 'violet' => ['#8b5cf6', '#a78bfa'],
    'cyan'   => ['#06b6d4', '#22d3ee'], 'sky'    => ['#0ea5e9', '#38bdf8'],
    'emerald'=> ['#10b981', '#34d399'], 'amber'  => ['#f59e0b', '#fbbf24'],
    'rose'   => ['#f43f5e', '#fb7185'], 'slate'  => ['#64748b', '#94a3b8'],
];
[$c1, $c2] = $accentMap[$accent] ?? $accentMap['indigo'];

$iconSvg = static function (string $name): string {
    return match ($name) {
        'mail'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.6"/><path d="m3 7 9 6 9-6"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/></svg>',
        'key'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9M17 6l2 2"/></svg>',
        default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 21v-1a7 7 0 0 1 14 0v1"/></svg>',
    };
};
$initial = mb_strtoupper(mb_substr((string) ($user['display_name'] ?: $user['email']), 0, 1));
?>
<div class="consent-shell">
  <div class="consent-card">

    <div class="consent-app">
      <!-- 身份提供方 → 应用 -->
      <div class="consent-arrow">
        <span class="ico idp">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M12 11.6V20"/></svg>
        </span>
        <span class="chev">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
        <span class="ico app" style="background:linear-gradient(140deg,<?= e($c1) ?>,<?= e($c2) ?>);color:#fff;border:0">
          <?= e($client['icon'] ?: mb_strtoupper(mb_substr((string) $client['name'], 0, 1))) ?>
        </span>
      </div>

      <h1><?= e($client['name']) ?> 想要访问你的账号</h1>
      <p class="who">
        由 <b><?= e($site['name']) ?></b> 提供身份验证 ·
        <?= $isFirstTime ? '这是你第一次授权该应用' : '你之前已授权过该应用' ?>
      </p>
    </div>

    <!-- 当前账号 -->
    <div class="consent-account">
      <span class="ava"><?= e($initial) ?></span>
      <span class="info">
        <b><?= e($user['display_name'] ?: $user['email']) ?></b>
        <span><?= e($user['email']) ?></span>
      </span>
      <?php if ($otherAccounts !== []): ?>
        <a class="btn btn-sm btn-ghost" href="/login?prompt=login&next=<?= urlencode(
            '/authorize?' . http_build_query([
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => $scopeString,
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => $challenge !== '' ? 'S256' : '',
            ])
        ) ?>">切换账号</a>
      <?php endif; ?>
    </div>

    <!-- 权限清单 -->
    <div class="consent-scopes">
      <p class="ttl">接受后，<?= e($client['name']) ?> 将能够：</p>
      <?php foreach ($scopeItems as $s): ?>
        <div class="scope-item">
          <span class="ic"><?= $iconSvg((string) $s['icon']) ?></span>
          <span class="tx">
            <b><?= e($s['title']) ?></b>
            <span><?= e($s['desc']) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="consent-note">
      你可以随时在「账号中心 → 已授权应用」里撤销这项授权。
      撤销后，<?= e($client['name']) ?> 需要重新走一次单点登录。
    </p>

    <!-- 决定 -->
    <form method="post" action="/authorize/decision" class="consent-actions">
      <?= \Vobl\Core\Csrf::field() ?>
      <input type="hidden" name="client_id" value="<?= e($client['client_id']) ?>">
      <input type="hidden" name="redirect_uri" value="<?= e($redirectUri) ?>">
      <input type="hidden" name="state" value="<?= e($state) ?>">
      <input type="hidden" name="scope" value="<?= e($scopeString) ?>">
      <input type="hidden" name="code_challenge" value="<?= e($challenge) ?>">
      <input type="hidden" name="decision" value="deny" id="decision-field">
      <button class="btn btn-ghost" type="submit" onclick="document.getElementById('decision-field').value='deny'">拒绝</button>
      <button class="btn btn-primary" type="submit" onclick="document.getElementById('decision-field').value='approve'">接受</button>
    </form>

  </div>
</div>
