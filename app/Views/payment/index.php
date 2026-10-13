<?php
/**
 * 充值 / 钱包
 * @var array $gateways 可用支付通道
 * @var array $orders
 * @var array $transactions
 * @var array $presets   快捷金额（分）
 */
$balance = (int) ($wallet['balance'] ?? 0);
$gateways = $gateways ?? [];
$presets = $presets ?? [1000, 3000, 5000, 10000];
$defaultGateway = '';
foreach ($gateways as $g) {
    if (!empty($g['is_default'])) { $defaultGateway = (string) $g['code']; break; }
}
if ($defaultGateway === '' && $gateways !== []) {
    $defaultGateway = (string) $gateways[0]['code'];
}
$statusLabels = [
    'pending'  => ['待支付', 'b-warn'],
    'paid'     => ['已支付', 'b-ok'],
    'failed'   => ['支付失败', 'b-danger'],
    'closed'   => ['已关闭', 'b-gray'],
    'refunded' => ['已退款', 'b-gray'],
];
?>

<!-- 余额 -->
<div class="card card-pad" style="margin-bottom:18px;background-image:radial-gradient(120% 130% at 100% 0%, rgba(255,138,36,.10), transparent 62%)">
  <div class="row-wrap" style="gap:20px">
    <div style="flex:1;min-width:180px">
      <div class="tiny muted" style="margin-bottom:4px">账户余额</div>
      <div style="font-size:34px;font-weight:700;letter-spacing:-.035em;line-height:1.15">
        <span style="font-size:20px;font-weight:600;color:var(--muted)">¥</span><?= e(number_format($balance / 100, 2)) ?>
      </div>
      <?php if ($bonusRate > 0): ?>
        <div class="small" style="color:var(--ok);margin-top:4px;font-weight:600">
          当前充值赠送 <?= (int) $bonusRate ?>%
        </div>
      <?php endif; ?>
    </div>
    <div style="flex:0 0 auto">
      <button class="btn btn-primary btn-lg" type="button" data-toggle="#recharge-form">立即充值</button>
    </div>
  </div>
</div>

<?php if ($gateways === []): ?>
  <div class="alert alert-warn" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
    <div><b>暂时无法充值。</b>管理员还没有配置可用的支付通道。请联系管理员在后台「支付通道」里绑定并启用一个通道。</div>
  </div>
<?php endif; ?>

<!-- 充值表单 -->
<div id="recharge-form" hidden class="card" style="margin-bottom:18px">
  <div class="card-head"><h3>充值</h3></div>
  <div class="card-body">
    <form id="recharge">
      <div class="alert alert-danger" data-error hidden style="margin-bottom:14px"></div>

      <div class="field">
        <label class="label">选择金额</label>
        <div class="choice-row" id="amount-presets">
          <?php foreach ($presets as $p): ?>
            <label class="choice">
              <input type="radio" name="amount_preset" value="<?= (int) $p / 100 ?>">
              <span>¥<?= e(number_format($p / 100, 2)) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="choice">
            <input type="radio" name="amount_preset" value="custom" checked>
            <span>自定义</span>
          </label>
        </div>
      </div>

      <div class="field mt-3" style="max-width:260px">
        <label class="label">充值金额（元）</label>
        <input class="input" id="amount-input" type="number" step="0.01" min="<?= e(number_format($minAmount / 100, 2)) ?>"
               max="<?= e(number_format($maxAmount / 100, 2)) ?>" placeholder="10.00" value="10.00">
        <span class="hint">
          单笔 <?= e(number_format($minAmount / 100, 2)) ?> ~ <?= e(number_format($maxAmount / 100, 2)) ?> 元
        </span>
      </div>

      <div class="field mt-3">
        <label class="label">支付方式</label>
        <div class="choice-row" id="pay-types">
          <?php foreach ($gateways as $g): ?>
            <label class="choice">
              <input type="radio" name="gateway" value="<?= e($g['code']) ?>"
                     data-pay-types='<?= e(json_encode($g['pay_types'] ?: ['only' => $g['name']])) ?>'
                     <?= $g['code'] === $defaultGateway ? 'checked' : '' ?>>
              <span><?= e($g['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="choice-row" id="sub-types" style="margin-top:8px"></div>
      </div>

      <div class="row mt-4" style="gap:9px">
        <button class="btn btn-primary" type="submit" id="btn-pay">去支付</button>
        <button class="btn btn-ghost" type="button" data-toggle="#recharge-form">取消</button>
      </div>

      <p class="hint mt-3">
        支付完成后余额会<b>自动到账</b>，通常几秒内完成。
        如果页面回来了但余额还没变，刷新一下即可 —— 入账由支付平台的异步通知驱动，与页面跳转无关。
      </p>
    </form>
  </div>
</div>

<div class="stack">

  <!-- 流水 -->
  <div class="card">
    <div class="card-head"><h3>资金流水</h3><span class="badge b-gray"><?= (int) $transactions['total'] ?> 条</span></div>
    <div class="card-body">
      <?php if ($transactions['rows'] === []): ?>
        <p class="small muted" style="margin:0">还没有任何资金变动。</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>时间</th><th>类型</th><th>金额</th><th>余额</th><th>备注</th></tr></thead>
            <tbody>
            <?php foreach ($transactions['rows'] as $t): ?>
              <tr>
                <td class="tiny muted nowrap"><?= e(dt((int) $t['created_at'], 'm-d H:i')) ?></td>
                <td>
                  <span class="badge <?= (string) $t['direction'] === 'in' ? 'b-ok' : 'b-gray' ?>">
                    <?= (string) $t['direction'] === 'in' ? '入账' : '支出' ?>
                  </span>
                </td>
                <td style="font-weight:600;color:<?= (string) $t['direction'] === 'in' ? 'var(--ok)' : 'var(--muted)' ?>">
                  <?= (string) $t['direction'] === 'in' ? '+' : '−' ?>¥<?= e(number_format(((int) $t['amount']) / 100, 2)) ?>
                </td>
                <td class="mono tiny">¥<?= e(number_format(((int) $t['balance_after']) / 100, 2)) ?></td>
                <td class="small muted"><?= e($t['remark'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 订单 -->
  <div class="card">
    <div class="card-head"><h3>充值订单</h3><span class="badge b-gray"><?= (int) $orders['total'] ?> 笔</span></div>
    <div class="card-body">
      <?php if ($orders['rows'] === []): ?>
        <p class="small muted" style="margin:0">还没有充值记录。</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>订单号</th><th>金额</th><th>通道</th><th>状态</th><th>创建时间</th></tr></thead>
            <tbody>
            <?php foreach ($orders['rows'] as $o): ?>
              <?php [$label, $cls] = $statusLabels[(string) $o['status']] ?? [(string) $o['status'], 'b-gray']; ?>
              <tr>
                <td class="mono tiny"><?= e($o['out_trade_no']) ?></td>
                <td style="font-weight:600">¥<?= e(number_format(((int) $o['amount']) / 100, 2)) ?></td>
                <td class="tiny muted"><?= e($o['gateway_code']) ?></td>
                <td><span class="badge <?= e($cls) ?>"><?= e($label) ?></span></td>
                <td class="tiny muted nowrap"><?= e(dt((int) $o['created_at'], 'm-d H:i')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
(function () {
  // 快捷金额联动
  var amountInput = document.getElementById('amount-input');
  document.querySelectorAll('[data-toggle="#recharge-form"]').forEach(function () {
    // 由 app.js 统一处理显隐
  });
  document.getElementById('amount-presets').addEventListener('change', function (e) {
    if (e.target.name !== 'amount_preset') return;
    if (e.target.value !== 'custom') amountInput.value = e.target.value;
    else amountInput.focus();
  });

  // 支付通道 → 二级支付方式
  var subTypes = document.getElementById('sub-types');
  function renderSubTypes() {
    var checked = document.querySelector('input[name="gateway"]:checked');
    subTypes.innerHTML = '';
    if (!checked) return;
    var types = {};
    try { types = JSON.parse(checked.dataset.payTypes || '{}'); } catch (e) { types = {}; }
    var keys = Object.keys(types);
    if (keys.length <= 1) return;   // 只有一种就不用再问用户
    keys.forEach(function (k, i) {
      var label = document.createElement('label');
      label.className = 'choice';
      label.innerHTML = '<input type="radio" name="pay_type" value="' + k + '"' + (i === 0 ? ' checked' : '') +
        '><span>' + types[k] + '</span>';
      subTypes.appendChild(label);
    });
  }
  document.getElementById('pay-types').addEventListener('change', renderSubTypes);
  renderSubTypes();

  // 提交
  document.getElementById('recharge').addEventListener('submit', async function (e) {
    e.preventDefault();
    var errBox = this.querySelector('[data-error]');
    errBox.hidden = true;

    var btn = document.getElementById('btn-pay');
    var gateway = document.querySelector('input[name="gateway"]:checked');
    if (!gateway) {
      errBox.hidden = false;
      errBox.textContent = '请选择支付方式';
      return;
    }
    var payType = document.querySelector('input[name="pay_type"]:checked');

    window.busy(btn, true, '正在创建订单…');
    try {
      var r = await window.api.post('/wallet/order', {
        amount: amountInput.value,
        gateway: gateway.value,
        pay_type: payType ? payType.value : '',
      });
      var d = r.data;
      // 三种交付方式：跳转 / 输出表单 / 二维码
      if (d.mode === 'redirect' && d.url) {
        location.href = d.url;
      } else if (d.mode === 'html' && d.html) {
        document.open(); document.write(d.html); document.close();
      } else if (d.mode === 'qrcode' && d.url) {
        // 用外部二维码服务渲染，避免引入额外依赖
        var mask = document.createElement('div');
        mask.className = 'modal-mask';
        mask.innerHTML = '<div class="modal" style="max-width:340px;text-align:center">' +
          '<div class="modal-body">' +
          '<h3 style="margin-bottom:6px">扫码支付</h3>' +
          '<p class="small muted" style="margin-bottom:16px">请用手机扫描下方二维码完成支付</p>' +
          '<img alt="支付二维码" style="width:220px;height:220px;border:1px solid var(--border);border-radius:12px" ' +
          'src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(d.url) + '">' +
          '<p class="hint" style="margin-top:12px">支付完成后本页会自动跳转</p>' +
          '</div><div class="modal-foot" style="justify-content:center">' +
          '<button class="btn btn-ghost" type="button" data-close>关闭</button></div></div>';
        document.body.appendChild(mask);
        mask.addEventListener('click', function (ev) {
          if (ev.target === mask || ev.target.closest('[data-close]')) mask.remove();
        });
        window.busy(btn, false);
      } else {
        window.busy(btn, false);
        window.toast('订单已创建，但未获得支付地址', 'warn');
      }
    } catch (err) {
      window.busy(btn, false);
      errBox.hidden = false;
      errBox.textContent = err.message;
    }
  });
})();
</script>
