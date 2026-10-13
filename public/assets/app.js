/* ==========================================================================
   Vobl ID · 前端交互
   零依赖。所有异步请求都带上 CSRF 令牌，且从 meta 标签读取（避免散落在各处）。
   ========================================================================== */

(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const IS_HTTPS = location.protocol === 'https:';

  /* ---------------------------------------------------------------- 图标 */

  const ICON = {
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>',
    copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
  };

  /* ---------------------------------------------------------------- Toast */

  function toast(message, type = 'info', ms = 3600) {
    let wrap = document.querySelector('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    const icon = type === 'ok' ? ICON.check
      : type === 'err' ? ICON.alert
      : type === 'warn' ? ICON.warn : ICON.info;
    el.innerHTML = icon + '<div></div>';
    el.lastChild.textContent = message;
    wrap.appendChild(el);
    setTimeout(() => {
      el.classList.add('out');
      setTimeout(() => el.remove(), 300);
    }, ms);
  }
  window.toast = toast;

  /* ---------------------------------------------------------------- 复制 */

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      // http 环境下 clipboard API 不可用，退回 execCommand
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
      ta.remove();
      return ok;
    }
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();

    let text = '';
    if (btn.dataset.copy === 'self') {
      text = btn.dataset.copyText || '';
    } else {
      const node = document.querySelector(btn.dataset.copy);
      text = node ? (node.dataset.raw || node.textContent || '') : '';
    }
    if (!text) return;

    const ok = await copyText(text.trim());
    if (ok) {
      const old = btn.innerHTML;
      btn.classList.add('done');
      btn.innerHTML = ICON.check + (btn.dataset.keepLabel ? '<span>已复制</span>' : '');
      setTimeout(() => { btn.innerHTML = old; btn.classList.remove('done'); }, 1600);
    } else {
      toast('复制失败，请手动选择文本', 'err');
    }
  });

  /* ---------------------------------------------------------------- 请求 */

  async function post(url, data, opts = {}) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': CSRF,
      },
      body: JSON.stringify(Object.assign({ _token: CSRF }, data)),
      credentials: 'same-origin',
      ...opts,
    });
    const ct = res.headers.get('content-type') || '';
    const body = ct.includes('json') ? await res.json().catch(() => null) : null;
    if (!res.ok) {
      const err = new Error((body && body.error) || ('请求失败（' + res.status + '）'));
      err.status = res.status;
      err.data = body;
      throw err;
    }
    return body;
  }
  window.api = { post, toast, csrf: CSRF };

  /* ---------------------------------------------------------------- 忙碌态 */

  function busy(btn, on, label) {
    if (!btn) return;
    if (on) {
      btn.dataset._html = btn.innerHTML;
      btn.classList.add('is-busy');
      btn.innerHTML = '<span class="spinner"></span>' + (label || '处理中…');
    } else if (btn.dataset._html) {
      btn.classList.remove('is-busy');
      btn.innerHTML = btn.dataset._html;
    }
  }
  window.busy = busy;

  /* ---------------------------------------------------------------- 表单异步提交 */

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('form[data-ajax]');
    if (!form) return;
    e.preventDefault();

    const btn = form.querySelector('[type="submit"]');
    const errBox = form.querySelector('[data-error]');
    if (errBox) { errBox.hidden = true; }

    busy(btn, true, form.dataset.busyLabel || '提交中…');

    const fd = new FormData(form);
    const payload = {};
    for (const [k, v] of fd.entries()) {
      if (k === '_token') continue;
      if (k in payload) {
        payload[k] = [].concat(payload[k], String(v));
      } else {
        payload[k] = String(v);
      }
    }

    try {
      const result = await post(form.action || location.pathname, payload);
      if (result && result.data && result.data.redirect) {
        toast(result.message || '操作成功', 'ok', 1200);
        setTimeout(() => { location.href = result.data.redirect; }, 500);
        return;
      }
      busy(btn, false);
      toast((result && result.message) || '操作成功', 'ok');
      if (form.dataset.reload !== 'off') {
        setTimeout(() => location.reload(), 700);
      }
    } catch (err) {
      busy(btn, false);
      if (errBox) {
        errBox.hidden = false;
        errBox.textContent = err.message;
      } else {
        toast(err.message, 'err', 5000);
      }
      if (err.status === 401 && err.data && err.data.code === 'unauthenticated') {
        setTimeout(() => { location.href = '/login'; }, 900);
      }
      if (err.status === 419) {
        setTimeout(() => location.reload(), 1500);
      }
    }
  });

  /* ---------------------------------------------------------------- 按钮异步操作 */

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    e.preventDefault();

    const url = btn.dataset.action;
    const confirmText = btn.dataset.confirm;
    if (confirmText && !window.confirm(confirmText)) return;

    let payload = {};
    if (btn.dataset.payload) {
      try { payload = JSON.parse(btn.dataset.payload); } catch (e2) { payload = {}; }
    }
    // 从最近的表单里带上隐藏字段（如 client_id）
    const holder = btn.closest('[data-context]');
    if (holder) {
      holder.querySelectorAll('input[type="hidden"][name]').forEach((inp) => {
        if (!(inp.name in payload)) payload[inp.name] = inp.value;
      });
    }

    busy(btn, true, btn.dataset.busyLabel);
    try {
      const result = await post(url, payload);
      busy(btn, false);
      toast((result && result.message) || '操作成功', 'ok');

      // 需要展示一次性内容（如新密钥）
      if (result && result.data && result.data.client_secret) {
        showSecret(result.data.client_secret);
      } else if (result && result.data && result.data.codes) {
        showRecoveryCodes(result.data.codes);
      } else if (result && result.data && result.data.password) {
        showTempPassword(result.data.password);
      } else if (result && result.data && result.data.redirect) {
        setTimeout(() => { location.href = result.data.redirect; }, 500);
        return;
      }
      if (btn.dataset.reload !== 'off') {
        setTimeout(() => location.reload(), 800);
      }
    } catch (err) {
      busy(btn, false);
      toast(err.message, 'err', 5000);
      if (err.status === 419) {
        setTimeout(() => location.reload(), 1500);
      }
    }
  });

  /* ---------------------------------------------------------------- 一次性内容弹窗 */

  function oneTimeModal(title, note, content, extraClass) {
    const mask = document.createElement('div');
    mask.className = 'modal-mask';
    mask.innerHTML =
      '<div class="modal">' +
        '<div class="modal-head"><h3 style="margin:0;font-size:16px"></h3>' +
        '<span class="badge b-warn" style="margin-left:8px">只显示这一次</span></div>' +
        '<div class="modal-body">' +
          '<p class="small muted" style="margin-bottom:14px"></p>' +
          '<div class="' + (extraClass || 'code') + '" data-content></div>' +
        '</div>' +
        '<div class="modal-foot">' +
          '<button class="btn btn-ghost" type="button" data-copy-all>' + ICON.copy + '<span>复制全部</span></button>' +
          '<button class="btn btn-primary" type="button" data-close>我已保存</button>' +
        '</div>' +
      '</div>';

    mask.querySelector('.modal-head h3').textContent = title;
    mask.querySelector('.modal-body p').textContent = note;

    const box = mask.querySelector('[data-content]');
    if (Array.isArray(content)) {
      box.style.padding = '16px';
      box.style.background = 'var(--surface-2)';
      box.style.borderRadius = 'var(--r)';
      box.style.fontFamily = 'var(--mono)';
      box.style.fontSize = '14px';
      box.style.lineHeight = '2';
      box.style.letterSpacing = '.04em';
      box.innerHTML = '';
      content.forEach((c) => {
        const d = document.createElement('div');
        d.textContent = c;
        box.appendChild(d);
      });
    } else {
      box.innerHTML = '<pre style="margin:0;padding:14px 16px;font-size:12.6px;color:#ece5df"></pre>';
      box.querySelector('pre').textContent = content;
    }

    document.body.appendChild(mask);

    const close = () => mask.remove();
    mask.addEventListener('click', (ev) => {
      if (ev.target === mask || ev.target.closest('[data-close]')) close();
    });
    mask.querySelector('[data-copy-all]').addEventListener('click', async (ev) => {
      const text = Array.isArray(content) ? content.join('\n') : content;
      if (await copyText(text)) {
        toast('已复制到剪贴板', 'ok');
        ev.currentTarget.innerHTML = ICON.check + '<span>已复制</span>';
      }
    });
    document.addEventListener('keydown', function onKey(ev) {
      if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
    });
  }

  function showSecret(secret) {
    oneTimeModal(
      '应用密钥',
      '服务端只保存了它的摘要，关掉这个窗口后就再也看不到明文了。请立刻复制到你的配置里。少了它，机密客户端将无法换取令牌。',
      secret
    );
  }
  window.showSecret = showSecret;

  function showRecoveryCodes(codes) {
    oneTimeModal(
      '账号恢复码',
      '打印或保存到密码管理器。当你忘记密码、又收不到邮件时，这是唯一能找回账号的东西。每个码只能用一次。',
      codes
    );
  }
  window.showRecoveryCodes = showRecoveryCodes;

  function showTempPassword(password) {
    oneTimeModal(
      '临时密码',
      '请通过安全渠道转交给用户，并提醒他登录后立即修改。该用户的其他所有会话已被登出。',
      password
    );
  }
  window.showTempPassword = showTempPassword;

  /* ---------------------------------------------------------------- 验证码输入框 */

  document.querySelectorAll('[data-code-group]').forEach((group) => {
    const inputs = Array.from(group.querySelectorAll('input'));
    if (!inputs.length) return;

    inputs.forEach((inp, i) => {
      inp.addEventListener('input', () => {
        inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
        if (inp.value && i < inputs.length - 1) inputs[i + 1].focus();
        // 全部填满自动提交
        if (inputs.every((x) => x.value !== '')) {
          const hidden = group.querySelector('input[type="hidden"]');
          if (hidden) hidden.value = inputs.map((x) => x.value).join('');
          const form = group.closest('form');
          if (form && group.dataset.autosubmit !== 'off') {
            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          }
        }
      });
      inp.addEventListener('keydown', (ev) => {
        if (ev.key === 'Backspace' && !inp.value && i > 0) inputs[i - 1].focus();
        if (ev.key === 'ArrowLeft' && i > 0) inputs[i - 1].focus();
        if (ev.key === 'ArrowRight' && i < inputs.length - 1) inputs[i + 1].focus();
      });
      // 粘贴整串自动分配
      inp.addEventListener('paste', (ev) => {
        const text = (ev.clipboardData || window.clipboardData).getData('text') || '';
        const digits = text.replace(/\D/g, '');
        if (digits.length < 2) return;
        ev.preventDefault();
        digits.split('').slice(0, inputs.length).forEach((d, k) => { inputs[k].value = d; });
        const hidden = group.querySelector('input[type="hidden"]');
        if (hidden) hidden.value = inputs.map((x) => x.value).join('');
        inputs[Math.min(digits.length, inputs.length) - 1].focus();
        if (inputs.every((x) => x.value !== '')) {
          const form = group.closest('form');
          if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
      });
    });

    // 初始化时同步一次隐藏域
    const hidden = group.querySelector('input[type="hidden"]');
    if (hidden) hidden.value = inputs.map((x) => x.value).join('');
  });

  // 有验证码组时，把焦点放到第一个空框
  const firstEmpty = Array.from(document.querySelectorAll('[data-code-group] input'))
    .find((i) => !i.value);
  if (firstEmpty) firstEmpty.focus();

  /* ---------------------------------------------------------------- 密码强度 */

  document.querySelectorAll('[data-password-meter]').forEach((meter) => {
    const input = document.querySelector(meter.dataset.passwordMeter);
    if (!input) return;
    const bars = Array.from(meter.querySelectorAll('i'));
    const label = meter.parentElement.querySelector('[data-strength-label]');

    const update = () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 8) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      if (v.length >= 14) score = Math.min(4, score + 1);

      const colors = ['#ddd5cd', '#d63a3a', '#94690a', '#0a7d57', '#0a7d57'];
      const texts = ['', '太弱', '一般', '良好', '很强'];
      bars.forEach((b, i) => { b.style.background = i < score ? colors[score] : '#ebe6e1'; });
      if (label) {
        label.textContent = v ? (texts[score] || '') : '';
        label.style.color = colors[score];
      }
    };
    input.addEventListener('input', update);
    update();
  });

  /* ---------------------------------------------------------------- 确认弹窗（替代 confirm） */

  window.askConfirm = function (message, onOk, opts = {}) {
    const mask = document.createElement('div');
    mask.className = 'modal-mask';
    mask.innerHTML =
      '<div class="modal" style="max-width:420px">' +
        '<div class="modal-body"><div class="row" style="gap:12px;align-items:flex-start">' +
          (ICON.warn).replace('<svg', '<svg style="width:20px;height:20px;color:var(--warn);flex:0 0 20px;margin-top:2px"') +
          '<div><h4 style="margin-bottom:6px"></h4><p class="small muted"></p></div>' +
        '</div></div>' +
        '<div class="modal-foot">' +
          '<button class="btn btn-ghost" type="button" data-no>取消</button>' +
          '<button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" type="button" data-yes></button>' +
        '</div>' +
      '</div>';
    mask.querySelector('h4').textContent = opts.title || '确认操作';
    mask.querySelector('p').textContent = message;
    mask.querySelector('[data-yes]').textContent = opts.okText || '确定';
    document.body.appendChild(mask);

    const close = () => mask.remove();
    mask.addEventListener('click', (e) => { if (e.target === mask) close(); });
    mask.querySelector('[data-no]').addEventListener('click', close);
    mask.querySelector('[data-yes]').addEventListener('click', () => { close(); onOk(); });
  };

  /* ---------------------------------------------------------------- 服务商预设联动 */

  document.querySelectorAll('[data-preset-select]').forEach((sel) => {
    sel.addEventListener('change', () => {
      const presets = JSON.parse(sel.dataset.presetSelect || '{}');
      const p = presets[sel.value];
      if (!p) return;
      const form = sel.closest('form');
      if (!form) return;
      const set = (name, value) => {
        const el = form.querySelector('[name="' + name + '"]');
        if (el && value !== undefined) el.value = value;
      };
      set('mail.host', p.host);
      set('mail.port', p.port);
      set('mail.encryption', p.encryption);
      toast('已填入「' + p.name + '」的推荐参数，记得补账号与密码', 'info');
    });
  });

  /* ---------------------------------------------------------------- 危险操作二次输入确认 */

  document.querySelectorAll('[data-type-confirm]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      const expect = btn.dataset.typeConfirm;
      const got = window.prompt('这是危险操作。请输入「' + expect + '」以确认：');
      if (got !== expect) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (got !== null) toast('输入不匹配，已取消', 'warn');
      }
    }, true);   // 捕获阶段，先于 data-action 的处理
  });

  /* ---------------------------------------------------------------- 自动消失的提示 */

  document.querySelectorAll('[data-flash]').forEach((el) => {
    setTimeout(() => {
      el.style.transition = 'opacity .4s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, 6000);
  });

})();
