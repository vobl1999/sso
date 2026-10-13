<?php
/** 后台 · 支付通道管理（绑定与切换） */
$isMockDefault = !empty($isMockDefault);
?>
<?php if ($isMockDefault): ?>
  <div class="alert alert-warn" style="margin-bottom:18px">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
    <div><b>当前默认通道是「模拟支付」。</b>
      它不产生真实扣款 —— 点一下「确认支付」就当成付款成功。用于先跑通充值链路，
      <b>上线前务必绑定真实通道并停用模拟通道</b>，否则任何人都能免费充值。</div>
  </div>
<?php endif; ?>

<div class="kpis" style="margin-bottom:18px">
  <div class="kpi"><b>¥<?= e(number_format((int) $stats['today_amount'] / 100, 2)) ?></b><span>今日入账</span></div>
  <div class="kpi"><b>¥<?= e(number_format((int) $stats['total_amount'] / 100, 2)) ?></b><span>累计入账</span></div>
  <div class="kpi"><b><?= (int) $stats['pending_count'] ?></b><span>待支付订单</span></div>
  <div class="kpi"><b>¥<?= e(number_format((int) $stats['balance_total'] / 100, 2)) ?></b><span>用户余额合计</span></div>
</div>

<?php foreach ($gateways as $g): ?>
  <?php
  $row = $g['row'];
  $enabled = (int) $row['enabled'] === 1;
  $isDefault = (int) $row['is_default'] === 1;
  ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-head">
      <h3><?= e($g['name']) ?></h3>
      <?php if ($isDefault): ?>
        <span class="badge b-brand">默认通道</span>
      <?php endif; ?>
      <?php if ($enabled): ?>
        <span class="badge b-ok"><i class="dot"></i>已启用</span>
      <?php else: ?>
        <span class="badge b-gray">已停用</span>
      <?php endif; ?>
      <?php if (!$g['configured']): ?>
        <span class="badge b-warn">配置未完成</span>
      <?php endif; ?>
      <span class="spacer"></span>
      <span class="tiny muted"><?= (int) $g['order_count'] ?> 笔订单</span>
      <?php if (\Vobl\Core\Auth::can('payment.manage')): ?>
        <?php if ($enabled): ?>
          <button class="btn btn-sm btn-ghost" type="button" data-action="/admin/payment/toggle"
                  data-payload='<?= e(json_encode(['code' => $row['code'], 'enable' => 0])) ?>'
                  data-confirm="<?= $row['code'] === 'mock' ? '停用模拟通道后，充值将走其它已启用的通道。确定吗？' : '停用后用户将无法通过该通道充值。确定吗？' ?>"
                  data-busy-label="处理中…">停用</button>
        <?php else: ?>
          <button class="btn btn-sm btn-ok" type="button" data-action="/admin/payment/toggle"
                  data-payload='<?= e(json_encode(['code' => $row['code'], 'enable' => 1])) ?>'
                  data-busy-label="处理中…">启用</button>
        <?php endif; ?>
        <?php if ($enabled && !$isDefault): ?>
          <button class="btn btn-sm btn-soft" type="button" data-action="/admin/payment/default"
                  data-payload='<?= e(json_encode(['code' => $row['code']])) ?>'
                  data-confirm="切换默认通道后，用户新发起的充值会走这个通道。确定吗？"
                  data-busy-label="切换中…">设为默认</button>
        <?php endif; ?>
      <?php endif; ?>
      <button class="btn btn-sm btn-ghost" type="button" data-toggle="#gw-<?= e($row['code']) ?>">配置</button>
    </div>

    <?php if ((string) $row['note'] !== ''): ?>
      <div class="card-body" style="padding-bottom:0">
        <p class="small muted" style="margin:0"><?= e($row['note']) ?></p>
      </div>
    <?php endif; ?>

    <div id="gw-<?= e($row['code']) ?>" hidden class="card-body" style="border-top:1px solid var(--border)">
      <form data-ajax action="/admin/payment/save" method="post" data-reload="off">
        <input type="hidden" name="code" value="<?= e($row['code']) ?>">
        <div class="alert alert-danger" data-error hidden style="margin-bottom:12px"></div>

        <div class="form-grid">
          <div class="field">
            <label class="label">通道名称</label>
            <input class="input" name="name" value="<?= e($row['name']) ?>">
          </div>
          <div class="field">
            <label class="label">备注（仅管理员可见）</label>
            <input class="input" name="note" value="<?= e($row['note']) ?>" placeholder="例如：走的是哪家服务商、费率多少">
          </div>

          <?php foreach ($g['schema'] as $field): ?>
            <div class="field <?= $field['type'] === 'textarea' ? 'full' : '' ?>">
              <label class="label">
                <?= e($field['label']) ?>
                <?php if (!empty($field['required'])): ?><span class="req">*</span><?php endif; ?>
              </label>
              <?php if ($field['type'] === 'textarea'): ?>
                <textarea class="textarea" name="<?= e($field['key']) ?>"
                          rows="4" autocomplete="off"><?= e($g['config'][$field['key']] ?? '') ?></textarea>
              <?php else: ?>
                <input class="input" name="<?= e($field['key']) ?>"
                       type="<?= $field['type'] === 'password' ? 'password' : ($field['type'] === 'url' ? 'url' : 'text') ?>"
                       value="<?= e($g['config'][$field['key']] ?? '') ?>"
                       autocomplete="off"
                       placeholder="<?= !empty($field['secret']) ? '留空表示不修改' : '' ?>">
              <?php endif; ?>
              <?php if (!empty($field['hint'])): ?>
                <span class="hint"><?= e($field['hint']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (\Vobl\Core\Auth::can('payment.manage')): ?>
          <div class="row mt-3" style="gap:9px">
            <button class="btn btn-primary" type="submit">保存配置</button>
            <span class="small muted">密钥字段留空表示不修改（界面上显示的是占位符，不是真实值）。</span>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<!-- 支付策略 -->
<div class="card">
  <div class="card-head"><h3>充值策略</h3></div>
  <div class="card-body">
    <form data-ajax action="/admin/settings/save" method="post" data-reload="off" style="display:none"></form>
    <div class="table-wrap">
      <table class="tbl">
        <tbody>
        <?php foreach ($settings as $key => $meta): ?>
          <tr>
            <td style="width:38%">
              <b style="font-size:13.3px"><?= e($key) ?></b>
            </td>
            <td>
              <form data-ajax action="/admin/settings/save" method="post" data-reload="off" class="row" style="gap:8px">
                <input type="hidden" name="<?= e($key) ?>" value="">
                <input class="input" name="<?= e($key) ?>" value="<?= e($meta['plain']) ?>" style="max-width:220px">
                <button class="btn btn-sm btn-ghost" type="submit">保存</button>
              </form>
            </td>
            <td class="tiny muted">
              <?php
              echo match ($key) {
                  'payment.currency'     => '货币代码，一般保持 CNY',
                  'payment.recharge_min' => '单笔最低充值金额，单位：分（100 = 1 元）',
                  'payment.recharge_max' => '单笔最高充值金额，单位：分',
                  'payment.bonus_rate'   => '充值赠送比例，单位百分数（填 5 表示充 100 送 5）。填 0 表示不赠送',
                  'payment.allow_manual' => '是否允许用户提交线下转账凭证后由管理员手工入账',
                  default => '',
              };
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="alert alert-info mt-3" style="font-size:13px">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
      <div>
        <b>金额一律以「分」为整数存储</b>，绝不用浮点数 —— 浮点在钱上会产生
        <code>0.1 + 0.2 !== 0.3</code> 这类误差，对账时会非常痛苦。<br>
        另外：下单金额由服务端根据这里的策略与用户输入决定，支付回调里的金额只用来<b>比对</b>，
        绝不用来决定入账多少 —— 否则改一下回调参数就能 1 分钱充值 1 万元。
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-toggle]');
  if (!btn) return;
  var el = document.querySelector(btn.dataset.toggle);
  if (!el) return;
  el.hidden = !el.hidden;
});
</script>
