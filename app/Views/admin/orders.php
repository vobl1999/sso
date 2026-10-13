<?php
/**
 * 后台 · 订单与对账
 * @var array $orders
 * @var array $sum
 */
$sum = $sum ?? [];
$canRefund = !empty($canRefund);
$statusMap = [
    'pending'  => ['待支付', 'b-warn'],
    'paid'     => ['已支付', 'b-ok'],
    'failed'   => ['支付失败', 'b-danger'],
    'closed'   => ['已关闭', 'b-gray'],
    'refunded' => ['已退款', 'b-brand'],
];
$orderStats = [
    ['累计入账', '¥' . number_format(((int) ($sum['paid_amount'] ?? 0)) / 100, 2), '仅统计已支付订单'],
    ['今日入账', '¥' . number_format(((int) ($sum['today_amount'] ?? 0)) / 100, 2), '按支付时间归集'],
    ['累计退款', '¥' . number_format(((int) ($sum['refunded_amount'] ?? 0)) / 100, 2), '含赠送部分一起扣回'],
    ['净收入', '¥' . number_format((((int) ($sum['paid_amount'] ?? 0)) - ((int) ($sum['refunded_amount'] ?? 0))) / 100, 2), '入账 − 退款'],
];
?>

<div class="kpis" style="margin-bottom:18px">
  <?php foreach ($orderStats as [$label, $value, $hint]): ?>
    <div class="kpi"><b><?= e($value) ?></b><span><?= e($label) ?></span>
      <div class="tiny muted" style="margin-top:3px"><?= e($hint) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<form class="card card-pad" method="get" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:10px">
    <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="订单号 / 支付平台单号 / 用户邮箱" style="max-width:290px">
    <select class="input" name="status" style="max-width:140px">
      <option value="">全部状态</option>
      <?php foreach ($statusMap as $k => [$l, $c]): ?>
        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="gateway" style="max-width:180px">
      <option value="">全部通道</option>
      <?php foreach ($gateways as $gw): ?>
        <option value="<?= e($gw['code']) ?>" <?= $gateway === $gw['code'] ? 'selected' : '' ?>><?= e($gw['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">筛选</button>
    <?php if ($keyword !== '' || $status !== '' || $gateway !== ''): ?>
      <a class="btn btn-ghost" href="/admin/orders">清除</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="badge b-gray">共 <?= number_format((int) $total) ?> 笔</span>
  </div>
</form>

<div class="card">
  <div class="table-wrap" style="border:0;border-radius:0">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:19%">订单号</th>
          <th style="width:17%">用户</th>
          <th style="width:10%">金额</th>
          <th style="width:9%">入账</th>
          <th style="width:10%">通道</th>
          <th style="width:10%">状态</th>
          <th style="width:13%">时间</th>
          <th class="right">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($orders === []): ?>
        <tr><td colspan="8" class="muted small" style="padding:24px">没有匹配的订单。</td></tr>
      <?php else: ?>
        <?php foreach ($orders as $o): ?>
          <?php [$sLabel, $sCls] = $statusMap[(string) $o['status']] ?? [(string) $o['status'], 'b-gray']; ?>
          <tr>
            <td>
              <span class="inline-copy" style="font-size:11.6px">
                <span><?= e($o['out_trade_no']) ?></span>
                <button type="button" data-copy="self" data-copy-text="<?= e($o['out_trade_no']) ?>">复制</button>
              </span>
              <?php if ((string) $o['trade_no'] !== ''): ?>
                <div class="tiny muted mono" style="margin-top:3px" title="支付平台订单号"><?= e($o['trade_no']) ?></div>
              <?php endif; ?>
            </td>
            <td class="tiny">
              <?= e($o['user_name'] ?: '—') ?>
              <div class="mono muted"><?= e($o['user_email'] ?: '') ?></div>
            </td>
            <td style="font-weight:600">¥<?= e(number_format(((int) $o['amount']) / 100, 2)) ?></td>
            <td class="mono tiny">
              ¥<?= e(number_format(((int) $o['credited']) / 100, 2)) ?>
              <?php if ((int) $o['credited'] > (int) $o['amount']): ?>
                <div style="color:var(--ok);font-size:11px">
                  +<?= e(number_format((((int) $o['credited']) - ((int) $o['amount'])) / 100, 2)) ?> 赠送
                </div>
              <?php endif; ?>
            </td>
            <td class="tiny muted"><?= e($o['gateway_code']) ?></td>
            <td>
              <span class="badge <?= e($sCls) ?>"><?= e($sLabel) ?></span>
              <?php if ((int) $o['notify_count'] > 1): ?>
                <div class="tiny muted" title="支付平台重复通知次数">通知 ×<?= (int) $o['notify_count'] ?></div>
              <?php endif; ?>
            </td>
            <td class="tiny muted">
              <?= e(dt((int) $o['created_at'], 'm-d H:i')) ?>
              <?php if ((int) $o['paid_at'] > 0): ?>
                <div style="color:var(--ok)">支付 <?= e(dt((int) $o['paid_at'], 'm-d H:i')) ?></div>
              <?php endif; ?>
              <?php if ((string) $o['status'] === 'pending'): ?>
                <div style="color:var(--warn)"><?= e(dt((int) $o['expires_at'], 'm-d H:i')) ?> 关闭</div>
              <?php endif; ?>
            </td>
            <td class="right">
              <?php if ($canRefund && (string) $o['status'] === 'paid'): ?>
                <button class="btn btn-sm btn-danger" type="button" data-refund="<?= e($o['out_trade_no']) ?>">退款</button>
              <?php elseif ((string) $o['status'] === 'refunded'): ?>
                <span class="tiny muted">已退款</span>
              <?php else: ?>
                <span class="tiny muted">—</span>
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
            'q' => $keyword, 'status' => $status, 'gateway' => $gateway, 'page' => $p,
        ]));
        ?>
        <?php if ($page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page - 1)) ?>">上一页</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page + 1)) ?>">下一页</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="alert alert-warn mt-3" style="font-size:13px">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
  <div>
    <b>退款的两个约束（都写在服务端）：</b><br>
    ① 退款金额等于该笔订单的实际入账金额（含赠送），不接受手工指定 —— 否则可能退多；<br>
    ② 用户当前余额必须足够扣回。如果他已经把这笔钱花掉了，系统会拒绝退款并提示先与用户确认，
    而不是把余额扣成负数。
  </div>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-refund]');
  if (!btn) return;
  var no = btn.dataset.refund;

  var mask = document.createElement('div');
  mask.className = 'modal-mask';
  mask.innerHTML =
    '<div class="modal" style="max-width:460px">' +
      '<div class="modal-head"><h3 style="margin:0;font-size:16px">订单退款</h3></div>' +
      '<div class="modal-body">' +
        '<p class="small muted" style="margin-bottom:14px">' +
          '订单 <b class="mono">' + no.replace(/[<>&"]/g, '') + '</b> 将从用户余额中扣回该笔入账。' +
          '退款原因会写进审计日志，是日后对账的依据。' +
        '</p>' +
        '<div class="field">' +
          '<label class="label">退款原因 <span class="req">*</span></label>' +
          '<input class="input" id="rf-reason" placeholder="例如：用户误充，协商后退款" autofocus>' +
        '</div>' +
      '</div>' +
      '<div class="modal-foot">' +
        '<button class="btn btn-ghost" type="button" data-close>取消</button>' +
        '<button class="btn btn-danger" type="button" data-do>确认退款</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(mask);

  function close() { mask.remove(); }
  mask.addEventListener('click', function (ev) {
    if (ev.target === mask || ev.target.closest('[data-close]')) close();
  });

  mask.querySelector('[data-do]').addEventListener('click', async function (ev) {
    var reason = mask.querySelector('#rf-reason').value.trim();
    if (!reason) { window.toast('请填写退款原因', 'err'); return; }
    window.busy(ev.currentTarget, true, '退款中…');
    try {
      await window.api.post('/admin/orders/refund', { out_trade_no: no, reason: reason });
      window.toast('退款成功，余额已扣回', 'ok');
      setTimeout(function () { location.reload(); }, 800);
    } catch (err) {
      window.busy(ev.currentTarget, false);
      window.toast(err.message, 'err', 8000);
    }
  });
});
</script>
