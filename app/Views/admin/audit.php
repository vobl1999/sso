<?php
/** 后台 · 审计日志 */
$catCls = ['auth'=>'b-ok','admin'=>'b-gray','security'=>'b-danger','payment'=>'b-brand','smtp'=>'b-gray','oauth'=>'b-brand'];
$resMap = ['ok'=>['成功','b-ok'],'fail'=>['失败','b-danger'],'denied'=>['已拒绝','b-warn']];
?>
<form class="card card-pad" method="get" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:10px">
    <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="搜索操作人 / 目标 / 详情" style="max-width:260px">
    <select class="input" name="category" style="max-width:150px">
      <option value="">全部分类</option>
      <?php foreach ($labels as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="result" style="max-width:140px">
      <option value="">全部结果</option>
      <option value="ok" <?= $result === 'ok' ? 'selected' : '' ?>>成功</option>
      <option value="fail" <?= $result === 'fail' ? 'selected' : '' ?>>失败</option>
      <option value="denied" <?= $result === 'denied' ? 'selected' : '' ?>>已拒绝</option>
    </select>
    <select class="input" name="days" style="max-width:140px">
      <option value="">全部时间</option>
      <?php foreach ([1=>'今天', 7=>'近 7 天', 30=>'近 30 天', 90=>'近 90 天'] as $d => $l): ?>
        <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">筛选</button>
    <?php if ($keyword !== '' || $category !== '' || $result !== '' || $days > 0): ?>
      <a class="btn btn-ghost" href="/admin/audit">清除</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="badge b-gray">共 <?= number_format((int) $total) ?> 条</span>
    <?php if ($canExport): ?>
      <a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query(array_filter([
          'q' => $keyword, 'category' => $category, 'result' => $result,
          'days' => $days ?: null, 'export' => 1,
      ]))) ?>" onclick="this.href='/admin/audit/export?'+this.href.split('?')[1]">导出 CSV</a>
    <?php endif; ?>
  </div>
</form>

<div class="card">
  <div class="table-wrap" style="border:0;border-radius:0">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:12%">时间</th>
          <th style="width:9%">分类</th>
          <th style="width:15%">操作</th>
          <th style="width:17%">操作人</th>
          <th style="width:12%">IP</th>
          <th style="width:8%">结果</th>
          <th>详情</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($events === []): ?>
        <tr><td colspan="7" class="muted small" style="padding:24px">没有匹配的日志。</td></tr>
      <?php else: ?>
        <?php foreach ($events as $v): ?>
          <?php [$rLabel, $rCls] = $resMap[(string) $v['result']] ?? [(string) $v['result'], 'b-gray']; ?>
          <tr>
            <td class="tiny muted nowrap" title="<?= e(dt((int) $v['ts'], 'Y-m-d H:i:s')) ?>">
              <?= e(ago((int) $v['ts'])) ?>
            </td>
            <td>
              <span class="badge <?= e($catCls[(string) $v['category']] ?? 'b-gray') ?>">
                <?= e($labels[(string) $v['category']] ?? $v['category']) ?>
              </span>
            </td>
            <td class="tiny mono"><?= e($v['action']) ?></td>
            <td class="tiny">
              <?= e($v['actor'] ?: '—') ?>
              <?php if ((int) $v['actor_id'] > 0): ?>
                <a class="muted" href="/admin/audit?q=<?= urlencode((string) $v['actor']) ?>" title="查看该操作人的全部日志">↗</a>
              <?php endif; ?>
            </td>
            <td class="tiny mono muted"><?= e($v['ip'] ?: '—') ?></td>
            <td><span class="badge <?= e($rCls) ?>"><?= e($rLabel) ?></span></td>
            <td class="small muted">
              <?= e($v['detail']) ?>
              <?php if ((string) $v['target'] !== ''): ?>
                <span class="badge b-gray mono" style="margin-left:4px"><?= e($v['target']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="card-foot">
      <div class="row" style="gap:6px">
        <span>第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
        <span class="spacer"></span>
        <?php
        $qs = static fn(int $p) => '?' . http_build_query(array_filter([
            'q' => $keyword, 'category' => $category, 'result' => $result,
            'days' => $days ?: null, 'page' => $p,
        ]));
        ?>
        <?php if ($page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page - 1)) ?>">上一页</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page + 1)) ?>">下一页</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="alert alert-info mt-3" style="font-size:13px">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
  <div>
    日志<b>只增不改</b>，系统不提供任何删除或修改接口 —— 能篡改的日志等于没有日志。
    唯一的清理入口是按保留天数批量清理，且清理动作本身也会留下一条记录。<br>
    敏感值（密码、密钥、验证码、令牌）在写入前会自动打码，日志里只会看到 <code>***</code>。
  </div>
</div>
