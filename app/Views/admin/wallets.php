<?php
/** 后台 · 用户余额 */
$canAdjust = !empty($canAdjust);
?>
<div class="kpis" style="margin-bottom:18px">
  <div class="kpi"><b>¥<?= e(number_format((int) $sumBalance / 100, 2)) ?></b><span>全站余额合计</span></div>
  <div class="kpi"><b><?= number_format((int) $total) ?></b><span>拥有钱包的账号</span></div>
  <div class="kpi"><b>¥<?= e(number_format((int) $sumBalance / 100 / max(1, (int) $total), 2)) ?></b><span>人均余额</span></div>
</div>

<form class="card card-pad" method="get" style="margin-bottom:18px">
  <div class="row-wrap" style="gap:10px">
    <input class="input" name="q" value="<?= e($keyword) ?>" placeholder="搜索邮箱或名称" style="max-width:280px">
    <button class="btn btn-primary" type="submit">搜索</button>
    <?php if ($keyword !== ''): ?><a class="btn btn-ghost" href="/admin/wallets">清除</a><?php endif; ?>
    <span class="spacer"></span>
    <span class="tiny muted">余额按从高到低排序</span>
  </div>
</form>

<div class="card">
  <div class="table-wrap" style="border:0;border-radius:0">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:28%">用户</th>
          <th style="width:14%">当前余额</th>
          <th style="width:16%">累计充值</th>
          <th style="width:14%">最近变动</th>
          <th class="right">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($wallets === []): ?>
        <tr><td colspan="5" class="muted small" style="padding:24px">没有匹配的账号。</td></tr>
      <?php else: ?>
        <?php foreach ($wallets as $w): ?>
          <tr>
            <td>
              <b style="font-size:13.5px"><?= e($w['display_name'] ?: '（未设置名称）') ?></b>
              <div class="tiny muted mono"><?= e($w['email']) ?></div>
              <?php if ((string) $w['status'] !== 'active'): ?>
                <span class="badge b-danger" style="margin-top:3px">
                  <?= (string) $w['status'] === 'locked' ? '已锁定' : '已停用' ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-weight:600;font-size:15px">
              ¥<?= e(number_format(((int) $w['balance']) / 100, 2)) ?>
            </td>
            <td class="mono tiny muted">¥<?= e(number_format(((int) $w['total_recharged']) / 100, 2)) ?></td>
            <td class="tiny muted"><?= e(ago((int) $w['wallet_updated'])) ?></td>
            <td class="right">
              <?php if ($canAdjust): ?>
                <button class="btn btn-sm btn-soft" type="button"
                        data-adjust="<?= (int) $w['id'] ?>"
                        data-email="<?= e($w['email']) ?>"
                        data-balance="<?= (int) $w['balance'] ?>">调整余额</button>
              <?php else: ?>
                <span class="tiny muted">无权限</span>
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
        <?php $qs = static fn(int $p) => '?' . http_build_query(array_filter(['q' => $keyword, 'page' => $p])); ?>
        <?php if ($page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page - 1)) ?>">上一页</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm btn-ghost" href="<?= e($qs($page + 1)) ?>">下一页</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="alert alert-warn mt-3" style="font-size:13px">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
  <div>
    <b>手工调整余额必须填写原因。</b>原因是给未来的自己看的 —— 三个月后对账发现少了一千块，
    唯一能还原现场的就是这条记录（含操作人、时间、金额、原因）。<br>
    另外：所有余额变动都是<b>原子操作</b>（<code>balance = balance ± ?</code>，带余额条件的
    <code>WHERE</code>），并发下不会出现「读出来是 100，算完写回去 80」这种丢钱的情况。
  </div>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-adjust]');
  if (!btn) return;
  var id = btn.dataset.adjust;
  var email = btn.dataset.email || '';
  var balance = (parseInt(btn.dataset.balance, 10) || 0) / 100;

  var mask = document.createElement('div');
  mask.className = 'modal-mask';
  mask.innerHTML =
    '<div class="modal" style="max-width:480px">' +
      '<div class="modal-head"><h3 style="margin:0;font-size:16px">调整余额</h3>' +
        '<span class="badge b-gray mono">' + email.replace(/[<>&"]/g, '') + '</span></div>' +
      '<div class="modal-body">' +
        '<div class="row" style="gap:8px;margin-bottom:16px">' +
          '<span class="badge b-brand">当前余额 ¥' + balance.toFixed(2) + '</span>' +
        '</div>' +
        '<div class="form-grid">' +
          '<div class="field">' +
            '<label class="label">调整方式</label>' +
            '<select class="input" id="adj-op">' +
              '<option value="add">增加余额</option>' +
              '<option value="sub">扣减余额</option>' +
              '<option value="set">设为指定金额</option>' +
            '</select>' +
          '</div>' +
          '<div class="field">' +
            '<label class="label">金额（元）</label>' +
            '<input class="input" id="adj-amount" type="number" step="0.01" min="0.01" placeholder="100.00">' +
          '</div>' +
          '<div class="field full">' +
            '<label class="label">调整原因 <span class="req">*</span></label>' +
            '<input class="input" id="adj-remark" placeholder="例如：线下转账补录，工单号 20261013-001">' +
          '</div>' +
        '</div>' +
        '<p class="hint mt-2">这条记录会永久保留在审计日志里，请写清楚依据。</p>' +
      '</div>' +
      '<div class="modal-foot">' +
        '<button class="btn btn-ghost" type="button" data-close>取消</button>' +
        '<button class="btn btn-primary" type="button" data-do>执行调整</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(mask);

  function close() { mask.remove(); }
  mask.addEventListener('click', function (ev) {
    if (ev.target === mask || ev.target.closest('[data-close]')) close();
  });

  mask.querySelector('[data-do]').addEventListener('click', async function (ev) {
    var amount = mask.querySelector('#adj-amount').value;
    var remark = mask.querySelector('#adj-remark').value.trim();
    var op = mask.querySelector('#adj-op').value;
    if (!amount || parseFloat(amount) <= 0) { window.toast('请输入有效金额', 'err'); return; }
    if (!remark) { window.toast('请填写调整原因', 'err'); return; }

    window.busy(ev.currentTarget, true, '执行中…');
    try {
      await window.api.post('/admin/users/wallet', {
        id: id, operator: op, amount: amount, remark: remark,
      });
      window.toast('余额已调整', 'ok');
      setTimeout(function () { location.reload(); }, 700);
    } catch (err) {
      window.busy(ev.currentTarget, false);
      window.toast(err.message, 'err', 7000);
    }
  });
});
</script>
