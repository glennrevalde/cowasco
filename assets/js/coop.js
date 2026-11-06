/* Cooperative screens: member picker, one-time submit, money fields, row grids, the official receipt and
   disbursement builders, the amortization preview and the cash count. No build step. Amounts are added up in
   whole centavos here; the server works every figure out again with exact decimals and is what is saved. */
(function () {
  'use strict';

  // ------------------------------------------------------------------ money helpers (centavos, no float drift)
  function cents(v) {
    let s = String(v === undefined || v === null ? '' : v).replace(/[,\s₱]/g, '');
    if (s === '' || s === '-' || s === '.') return 0;
    let neg = false;
    if (s[0] === '(' && s[s.length - 1] === ')') { neg = true; s = s.slice(1, -1); }
    if (s[0] === '-') { neg = true; s = s.slice(1); }
    if (!/^\d*(\.\d*)?$/.test(s)) return 0;
    const parts = s.split('.');
    const whole = parseInt(parts[0] || '0', 10);
    const frac = (parts[1] || '') + '000';
    let c = whole * 100 + parseInt(frac.slice(0, 2), 10);
    if (parseInt(frac[2], 10) >= 5) c += 1;
    return neg ? -c : c;
  }
  function money(c) {
    const neg = c < 0;
    const a = Math.abs(c);
    const s = Math.floor(a / 100).toLocaleString('en-US') + '.' + String(a % 100).padStart(2, '0');
    return neg ? '(' + s + ')' : s;
  }
  function plain(c) {
    const neg = c < 0;
    const a = Math.abs(c);
    return (neg ? '-' : '') + Math.floor(a / 100) + '.' + String(a % 100).padStart(2, '0');
  }
  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }
  function debounce(fn, ms) {
    let t = null;
    return function () { const args = arguments; clearTimeout(t); t = setTimeout(() => fn.apply(null, args), ms); };
  }
  function getJSON(url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json());
  }
  window.CoopMoney = { cents, money, plain };

  // ------------------------------------------------------------------ money inputs
  function bindMoney(root) {
    (root || document).querySelectorAll('input[data-money]').forEach((inp) => {
      if (inp.dataset.moneyReady) return;
      inp.dataset.moneyReady = '1';
      inp.addEventListener('focus', () => { if (inp.value !== '') inp.value = inp.value.replace(/,/g, ''); });
      inp.addEventListener('blur', () => {
        if (inp.value.trim() === '') return;
        inp.value = money(cents(inp.value));
        inp.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  }
  bindMoney(document);
  // sent without the thousands separators (the server reads both, this keeps the posted values plain)
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('input[data-money]').forEach((inp) => { if (inp.value !== '') inp.value = inp.value.replace(/,/g, '').replace(/^\((.*)\)$/, '-$1'); });
  }, true);

  // ------------------------------------------------------------------ confirm before posting, then send once
  let confirmModal = null;
  function confirmBox(title, body, okText, onOk) {
    if (!window.bootstrap || !bootstrap.Modal) { AppDialog.confirm(body, { title, ok: okText || 'Post', html: true }).then((yes) => { if (yes) onOk(); }); return; }
    if (!confirmModal) {
      const el = document.createElement('div');
      el.className = 'modal fade coop-confirm';
      el.tabIndex = -1;
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><span class="class-confirm-ico"><i class="bi bi-shield-check"></i></span><h5 class="modal-title"></h5>'
        + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        + '<div class="modal-body"></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Go back</button>'
        + '<button type="button" class="btn btn-primary" data-ok></button></div></div></div>';
      document.body.appendChild(el);
      confirmModal = { el, modal: new bootstrap.Modal(el), ok: null };
      el.querySelector('[data-ok]').addEventListener('click', () => { const f = confirmModal.ok; confirmModal.ok = null; confirmModal.modal.hide(); if (f) f(); });
    }
    confirmModal.el.querySelector('.modal-title').textContent = title;
    confirmModal.el.querySelector('.modal-body').innerHTML = body;
    confirmModal.el.querySelector('[data-ok]').textContent = okText || 'Post';
    confirmModal.ok = onOk;
    confirmModal.modal.show();
  }
  window.CoopConfirm = confirmBox;

  document.querySelectorAll('form[data-once]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (e.defaultPrevented) return;
      if (form.dataset.sending === '1') { e.preventDefault(); return; }
      const ask = form.dataset.confirm;
      const btn = e.submitter;
      if (ask && form.dataset.confirmed !== '1' && !(btn && btn.hasAttribute('data-no-confirm'))) {
        e.preventDefault();
        // {amount}, {total}, {kind} ... are the values on the form: the text of a chosen option, amounts with their
        // separators; a value not filled in yet reads as such rather than leaving the braces in the question
        const valueOf = (k) => {
          const n = form.querySelector('[data-confirm-value="' + k + '"]') || form.querySelector('[name="' + k + '"]');
          if (!n) return '';
          let v;
          if (n.tagName === 'SELECT') { const o = n.options[n.selectedIndex]; v = o && o.value !== '' ? o.text.trim() : ''; if (k === 'kind') v = v.toLowerCase(); }
          else v = String(n.value !== undefined && n.value !== '' ? n.value : (n.textContent || '')).trim();
          if (/^(amount|total|principal|net|r_amount|cash|excess)$/.test(k) || n.hasAttribute('data-money')) {
            const c = cents(v);
            v = c ? money(c) : '';
          }
          return v === '' ? '(not entered)' : v;
        };
        const text = ask.replace(/\{([a-z_]+)\}/g, (m, k) => esc(valueOf(k)));
        // the class the entry is posted under is shown with the question
        // (the box may sit outside the form, joined to it by its form attribute)
        const cls = form.querySelector('select[name="class_id"]') || (form.elements && form.elements.namedItem('class_id'));
        const clsText = cls && cls.value ? cls.options[cls.selectedIndex].text.trim() : '';
        const body = '<p class="mb-0">' + text + '</p>' + (clsText ? '<p class="mb-0 mt-2 small text-muted">Class: <strong class="text-body">' + esc(clsText) + '</strong></p>' : '');
        confirmBox(form.dataset.confirmTitle || 'Please confirm', body, form.dataset.confirmOk || 'Yes, continue', () => {
          form.dataset.confirmed = '1';
          if (btn && btn.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
          if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
        });
        return;
      }
      form.dataset.sending = '1';
      // the buttons are switched off a moment later, so the pressed button still travels with the form
      setTimeout(() => {
        form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach((b) => {
          b.disabled = true;
          if (b === btn || (!btn && b.classList.contains('btn-primary'))) b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        });
      }, 0);
      // a network failure would leave the form dead: it comes back after 20 seconds
      setTimeout(() => { form.dataset.sending = ''; form.dataset.confirmed = ''; form.querySelectorAll('button').forEach((b) => { b.disabled = false; }); }, 20000);
    });
  });

  // ------------------------------------------------------------------ member picker
  document.querySelectorAll('[data-member-pick]').forEach((box) => {
    const input = box.querySelector('input[type="search"]');
    const hidden = box.querySelector('input[type="hidden"]');
    const list = box.querySelector('.member-pick-list');
    let items = [];
    let hi = -1;
    let lastQ = null;
    function render() {
      if (!items.length) { list.innerHTML = '<div class="mp-none">No member matches "' + esc(input.value) + '"</div>'; list.hidden = false; return; }
      list.innerHTML = items.map((m, i) => '<div class="mp-opt' + (i === hi ? ' is-hi' : '') + '" data-i="' + i + '"><div><strong>' + esc(m.name) + '</strong>'
        + (m.hold ? ' <span class="text-danger small">on hold</span>' : '') + '<div class="small text-muted">' + esc(m.no) + (m.type ? ', ' + esc(m.type) : '') + (m.city ? ', ' + esc(m.city) : '') + '</div></div>'
        + '<span class="small ' + (m.status === 'Active' ? 'text-success' : 'text-muted') + '">' + esc(m.status) + '</span></div>').join('');
      list.hidden = false;
    }
    const search = debounce(() => {
      const q = input.value.trim();
      if (q === lastQ) return;
      lastQ = q;
      if (q.length < 1) { list.hidden = true; return; }
      let url = box.dataset.api + '&q=' + encodeURIComponent(q);
      if (box.dataset.status) url += '&status=' + encodeURIComponent(box.dataset.status);
      getJSON(url).then((d) => { items = d.members || []; hi = items.length ? 0 : -1; render(); }).catch(() => {});
    }, 180);
    function pick(m) {
      hidden.value = m.id;
      input.value = m.name + ' (' + m.no + ')';
      list.hidden = true;
      box.dispatchEvent(new CustomEvent('member:picked', { bubbles: true, detail: m }));
      // the address may carry the placeholder as typed or URL-encoded ({id} or %7Bid%7D)
      if (box.dataset.href) { window.location.href = box.dataset.href.replace(/\{id\}|%7Bid%7D/gi, encodeURIComponent(m.id)); return; }
      if (box.hasAttribute('data-submit') && box.closest('form')) box.closest('form').submit();
    }
    input.addEventListener('input', () => { if (hidden.value) { hidden.value = ''; box.dispatchEvent(new CustomEvent('member:cleared', { bubbles: true })); } search(); });
    input.addEventListener('focus', () => { if (!hidden.value && input.value.trim()) { lastQ = null; search(); } });
    input.addEventListener('keydown', (e) => {
      if (list.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(items.length - 1, hi + 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); render(); }
      else if (e.key === 'Enter') { if (items[hi]) { e.preventDefault(); pick(items[hi]); } }
      else if (e.key === 'Escape') { list.hidden = true; }
    });
    list.addEventListener('mousedown', (e) => { const o = e.target.closest('.mp-opt'); if (o) { e.preventDefault(); pick(items[+o.dataset.i]); } });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) list.hidden = true; });
    if (hidden.value) setTimeout(() => box.dispatchEvent(new CustomEvent('member:picked', { bubbles: true, detail: { id: +hidden.value, preset: true } })), 0);
  });

  // ------------------------------------------------------------------ account picker (Savings Deposit / Withdrawal)
  // The box takes an account no. or a passbook scan as before (Enter opens it), and finds accounts by the member's
  // name as you type: each word typed narrows the list. Choosing one opens it (data-href with {no}).
  document.querySelectorAll('[data-account-pick]').forEach((box) => {
    const input = box.querySelector('input[type="search"]');
    const list = box.querySelector('.member-pick-list');
    let items = [];
    let hi = -1;
    let lastQ = null;
    let seq = 0;
    function render() {
      if (!items.length) { list.innerHTML = '<div class="mp-none">No account matches "' + esc(input.value) + '". Press Enter to open it by number.</div>'; list.hidden = false; return; }
      list.innerHTML = items.map((a, i) => '<div class="mp-opt' + (i === hi ? ' is-hi' : '') + '" data-i="' + i + '" role="option"><div><strong>' + esc(a.name) + '</strong>'
        + '<div class="small text-muted">' + esc(a.no) + ', ' + esc(a.product) + ', member ' + esc(a.member_no) + (a.status !== 'Active' ? ', ' + esc(a.status) : '') + '</div></div>'
        + '<div class="text-end small"><div class="fw-semibold">' + esc(a.available) + '</div><div class="text-muted">available</div></div></div>').join('');
      list.hidden = false;
    }
    const search = debounce(() => {
      const q = input.value.trim();
      if (q === lastQ) return;
      lastQ = q;
      if (q.length < 2) { list.hidden = true; items = []; return; }
      const mine = ++seq;
      getJSON(box.dataset.api + '&q=' + encodeURIComponent(q)).then((d) => {
        if (mine !== seq) return;   // a later search already answered
        items = d.accounts || []; hi = items.length ? 0 : -1; render();
      }).catch(() => {});
    }, 160);
    function open(a) {
      list.hidden = true;
      input.value = a.no;
      window.location.href = box.dataset.href.replace('{no}', encodeURIComponent(a.no));
    }
    input.addEventListener('input', search);
    input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && !/^\d+$/.test(input.value.trim())) { lastQ = null; search(); } });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { lastQ = null; search(); return; } hi = Math.min(items.length - 1, hi + 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); render(); }
      else if (e.key === 'Enter') {
        // a typed or scanned number that is exactly an account opens it; otherwise the highlighted match
        const exact = items.find((a) => a.no === input.value.trim());
        if (exact) { e.preventDefault(); open(exact); }
        else if (!list.hidden && items[hi] && !/^\d+$/.test(input.value.trim())) { e.preventDefault(); open(items[hi]); }
      }
      else if (e.key === 'Escape') { list.hidden = true; }
    });
    list.addEventListener('mousedown', (e) => { const o = e.target.closest('.mp-opt'); if (o) { e.preventDefault(); open(items[+o.dataset.i]); } });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) list.hidden = true; });
  });

  // ------------------------------------------------------------------ row grids: <table data-rows="templateId">, buttons [data-add-rows="tableId"]
  document.querySelectorAll('table[data-rows]').forEach((table) => {
    const tpl = document.getElementById(table.dataset.rows);
    const tbody = table.tBodies[0];
    let n = tbody.rows.length + 100;
    function add() {
      const tmp = document.createElement('tbody');
      tmp.innerHTML = tpl.innerHTML.replace(/__i__/g, 'r' + (n++)).trim();
      const tr = tmp.firstElementChild;
      tbody.appendChild(tr);
      bindMoney(tr);
      if (window.AppCombo) window.AppCombo.init(tr);
      tr.dispatchEvent(new CustomEvent('rows:added', { bubbles: true }));
      return tr;
    }
    table.addEventListener('click', (e) => {
      const del = e.target.closest('[data-del]');
      if (!del) return;
      const tr = del.closest('tr');
      tr.remove();
      table.dispatchEvent(new CustomEvent('rows:changed', { bubbles: true }));
      if (!tbody.rows.length && table.dataset.keepOne !== undefined) add();
    });
    document.querySelectorAll('[data-add-rows="' + table.id + '"]').forEach((b) => b.addEventListener('click', () => { const tr = add(); const f = tr.querySelector('input:not([type=hidden]), select'); if (f) f.focus(); }));
    const min = parseInt(table.dataset.minRows || '0', 10);
    while (tbody.rows.length < min) add();
    table.addRow = add;
  });

  // ------------------------------------------------------------------ totals: [data-total-of="selector"] shows the sum of the fields
  function refreshTotals() {
    document.querySelectorAll('[data-total-of]').forEach((out) => {
      let c = 0;
      document.querySelectorAll(out.dataset.totalOf).forEach((i) => { if (!i.disabled) c += cents(i.value); });
      if (out.tagName === 'INPUT') out.value = money(c); else out.textContent = money(c);
      out.dataset.cents = c;
    });
    document.querySelectorAll('[data-change-of]').forEach((out) => {
      const tot = document.querySelector(out.dataset.changeOf);
      const rec = document.querySelector(out.dataset.received);
      if (!tot || !rec) return;
      const diff = cents(rec.value) - +(tot.dataset.cents || 0);
      out.textContent = rec.value.trim() === '' ? '' : money(diff);
      out.classList.toggle('text-danger', diff < 0);
    });
  }
  document.addEventListener('input', debounce(refreshTotals, 60));
  document.addEventListener('change', refreshTotals);
  document.addEventListener('rows:changed', refreshTotals);
  window.CoopTotals = refreshTotals;
  refreshTotals();

  // ------------------------------------------------------------------ account number look-up: <input data-acct-lookup="#out">
  document.querySelectorAll('input[data-acct-lookup]').forEach((inp) => {
    const out = document.querySelector(inp.dataset.acctLookup);
    const run = () => {
      const v = inp.value.trim();
      if (!out) return;
      if (!v) { out.textContent = ''; return; }
      getJSON('index.php?p=api.coop&a=account&no=' + encodeURIComponent(v)).then((d) => {
        if (d.error || !d.account) { out.innerHTML = '<span class="text-danger">' + esc(d.error || 'Not found') + '</span>'; return; }
        const a = d.account;
        out.textContent = a.member + ' (' + a.member_no + '), ' + a.product + ', available ' + money(cents(a.available)) + (a.status !== 'active' ? ', ' + a.status : '');
      }).catch(() => {});
    };
    inp.addEventListener('change', run);
    if (inp.value) run();
  });

  // ------------------------------------------------------------------ cash count by denomination
  document.querySelectorAll('[data-denoms]').forEach((box) => {
    const out = box.querySelector('[data-denoms-total]');
    const diffOut = box.querySelector('[data-denoms-diff]');
    const expected = cents(box.dataset.expected || '0');
    const calc = () => {
      let c = 0;
      box.querySelectorAll('input[data-value]').forEach((i) => {
        const n = parseInt(i.value || '0', 10) || 0;
        const line = n * cents(i.dataset.value);
        c += line;
        const lo = i.closest('tr') && i.closest('tr').querySelector('[data-line]');
        if (lo) lo.textContent = n ? money(line) : '';
      });
      const typed = box.querySelector('input[name="denoms[_total]"]');
      if (typed && typed.value.trim() !== '') c = cents(typed.value);
      if (out) out.textContent = money(c);
      if (diffOut) {
        const d = c - expected;
        diffOut.textContent = d === 0 ? 'Balanced' : (d > 0 ? 'Over ' : 'Short ') + money(Math.abs(d));
        diffOut.className = d === 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold';
      }
    };
    box.addEventListener('input', calc);
    calc();
  });

  // ------------------------------------------------------------------ amortization preview
  document.querySelectorAll('[data-schedule]').forEach((box) => {
    const form = box.closest('form') || document;
    const fields = JSON.parse(box.dataset.fields || '{}');      // param -> field name
    const val = (name) => { const el = form.querySelector('[name="' + name + '"]'); return el ? el.value.replace(/,/g, '') : ''; };
    const run = debounce(() => {
      let url = box.dataset.api;
      Object.keys(fields).forEach((k) => { url += '&' + k + '=' + encodeURIComponent(val(fields[k])); });
      if (box.dataset.extra) url += '&' + box.dataset.extra;
      getJSON(url).then((d) => {
        if (d.error && !d.rows) { box.innerHTML = '<div class="text-muted small">' + esc(d.error) + '</div>'; return; }
        if (!d.rows || !d.rows.length) { box.innerHTML = '<div class="text-muted small">' + esc(d.error || 'Enter the amount and the term.') + '</div>'; return; }
        const first = d.rows[0];
        let html = '<div class="sched-sum"><div><span>Rate</span><strong>' + esc(String(d.rate).replace(/(\.\d\d\d*?)0+$/, '$1')) + ' %</strong></div><div><span>Amortization</span><strong>' + esc(money(cents(first.total))) + '</strong><small>' + esc(d.mode) + ', ' + d.installments + ' payments</small></div>'
          + '<div><span>Total interest</span><strong>' + esc(money(cents(d.total_interest))) + '</strong></div>'
          + (d.charges && d.charges.length ? '<div><span>Charges</span><strong>' + esc(money(cents(d.total_charges))) + '</strong><small>' + d.charges.map((c) => esc(c.name) + ' ' + esc(money(cents(c.amount)))).join(', ') + '</small></div>' : '')
          + (d.payoff && +d.payoff > 0 ? '<div><span>Previous loan payoff</span><strong>' + esc(money(cents(d.payoff))) + '</strong></div>' : '')
          + '<div><span>' + (d.charge_method === 'deducted' || (d.payoff && +d.payoff > 0) ? 'Net proceeds' : 'Proceeds') + '</span><strong>' + esc(money(cents(d.net_proceeds))) + '</strong></div>'
          + '<div><span>Maturity</span><strong>' + esc(d.due_date) + '</strong></div></div>';
        html += '<div class="sched-wrap"><table class="table table-sm sched-table"><thead><tr><th>#</th><th>Due</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amortization</th><th class="num">Balance</th></tr></thead><tbody>'
          + d.rows.map((r) => '<tr><td>' + r.n + '</td><td>' + esc(r.date) + '</td><td class="num">' + money(cents(r.principal)) + '</td><td class="num">' + money(cents(r.interest)) + '</td><td class="num">' + money(cents(r.total)) + '</td><td class="num">' + money(cents(r.balance)) + '</td></tr>').join('')
          + '</tbody></table></div>';
        box.innerHTML = html;
        box.dispatchEvent(new CustomEvent('schedule:loaded', { bubbles: true, detail: d }));
      }).catch(() => {});
    }, 300);
    Object.values(fields).forEach((name) => {
      const el = form.querySelector('[name="' + name + '"]');
      if (el) { el.addEventListener('change', run); el.addEventListener('input', run); }
    });
    run();
  });

  // ------------------------------------------------------------------ official receipt / disbursement builder
  // <div id="memberItems" data-items-api="..." data-mode="or|cv" data-can-override="1"> filled when a member is picked
  document.querySelectorAll('[data-items-api]').forEach((wrap) => {
    const form = wrap.closest('form');
    const mode = wrap.dataset.mode || 'or';
    const canOverride = wrap.dataset.canOverride === '1';
    const dateField = form ? form.querySelector('[name="txn_date"]') : null;
    const cardOut = document.querySelector(wrap.dataset.cardTarget || '#memberCard');
    const keep = JSON.parse(wrap.dataset.posted || '{}');      // what was typed before a failed save
    let memberId = null;
    function load(id) {
      memberId = id;
      if (!id) { wrap.innerHTML = mode === 'card' ? '' : '<div class="text-muted small p-3">Choose the member first.</div>'; if (cardOut) cardOut.innerHTML = ''; return; }
      wrap.innerHTML = mode === 'card' ? '' : '<div class="text-muted small p-3"><span class="spinner-border spinner-border-sm"></span> Loading the member\'s accounts...</div>';
      getJSON(wrap.dataset.itemsApi + '&id=' + id + (dateField && dateField.value ? '&date=' + dateField.value : '')).then((d) => {
        if (d.error) { wrap.innerHTML = '<div class="alert alert-danger">' + esc(d.error) + '</div>'; return; }
        if (cardOut) cardOut.innerHTML = d.card || '';
        if (mode === 'card') {
          // only the member: a hold or an alert to see before receiving money
          wrap.innerHTML = (d.member.hold ? '<div class="alert alert-danger py-2">On hold: ' + esc(d.member.hold_reason) + '</div>' : '')
            + (d.member.alert ? '<div class="alert alert-warning py-2">' + esc(d.member.alert) + '</div>' : '');
          refreshTotals();
          return;
        }
        wrap.innerHTML = mode === 'or' ? orRows(d) : cvRows(d);
        bindMoney(wrap);
        wrap.querySelectorAll('[data-payoff]').forEach((cb) => cb.addEventListener('change', () => {
          const amt = wrap.querySelector('input[name="items[' + cb.dataset.payoff + '][amount]"]');
          if (amt) { amt.value = cb.checked ? money(cents(cb.dataset.amount)) : ''; amt.readOnly = cb.checked; }
          refreshTotals();
        }));
        wrap.querySelectorAll('[data-fill]').forEach((b) => b.addEventListener('click', () => {
          const amt = wrap.querySelector('input[name="items[' + b.dataset.fill + '][amount]"]');
          if (amt) { amt.value = money(cents(b.dataset.amount)); refreshTotals(); amt.focus(); }
        }));
        wrap.querySelectorAll('[data-ovr-toggle]').forEach((b) => b.addEventListener('click', () => {
          const box = wrap.querySelector('[data-ovr="' + b.dataset.ovrToggle + '"]');
          if (box) { box.hidden = !box.hidden; box.querySelectorAll('input').forEach((i) => { i.disabled = box.hidden; }); }
        }));
        refreshTotals();
      }).catch(() => { wrap.innerHTML = '<div class="alert alert-danger">The member\'s accounts could not be loaded.</div>'; });
    }
    function k(key, fallback) { return keep[key] !== undefined ? keep[key] : (fallback || ''); }
    function orRows(d) {
      let i = 0;
      let html = '';
      if (d.member.hold) html += '<div class="alert alert-danger py-2">On hold: ' + esc(d.member.hold_reason) + '</div>';
      if (d.member.alert) html += '<div class="alert alert-warning py-2">' + esc(d.member.alert) + '</div>';
      html += '<table class="table table-sm or-items mb-0"><thead><tr><th>Account / loan</th><th class="num">Balance / due</th><th class="num" style="width:170px">Amount</th></tr></thead><tbody>';
      d.accounts.forEach((a) => {
        if (a.type === 'time_deposit') return;
        const n = i++;
        html += '<tr><td><input type="hidden" name="items[' + n + '][kind]" value="savings"><input type="hidden" name="items[' + n + '][account_id]" value="' + a.id + '">'
          + '<strong>' + esc(a.product) + '</strong><div class="small text-muted">' + esc(a.no) + (a.status !== 'active' ? ', ' + esc(a.status) : '') + '</div></td>'
          + '<td class="num">' + money(cents(a.balance)) + '</td>'
          + '<td><input class="form-control form-control-sm text-end" name="items[' + n + '][amount]" value="' + esc(k('items.' + n + '.amount')) + '" inputmode="decimal" data-money data-or-amount autocomplete="off"></td></tr>';
      });
      d.loans.forEach((l) => {
        const n = i++;
        html += '<tr class="or-loan"><td><input type="hidden" name="items[' + n + '][kind]" value="loan"><input type="hidden" name="items[' + n + '][loan_id]" value="' + l.id + '">'
          + '<strong>' + esc(l.product) + '</strong> <span class="small ' + (l.status === 'past_due' ? 'text-danger' : 'text-muted') + '">' + (l.status === 'past_due' ? 'past due' : '') + '</span>'
          + '<div class="small text-muted">' + esc(l.loan_no) + ', amortization ' + money(cents(l.amortization)) + (l.next_due ? ', next due ' + esc(l.next_due) : '') + '</div>'
          + '<div class="or-dues small">' + (l.count_due ? l.count_due + ' due: ' : 'Nothing due yet. ') + 'penalty ' + money(cents(l.penalty_due)) + ', interest ' + money(cents(l.interest_due)) + ', principal ' + money(cents(l.principal_due))
          + ' <button type="button" class="btn btn-link btn-sm p-0" data-fill="' + n + '" data-amount="' + l.total_due + '">use amount due</button>'
          + '<label class="ms-2"><input type="checkbox" class="form-check-input" name="items[' + n + '][payoff]" value="1" data-payoff="' + n + '" data-amount="' + l.payoff + '"> pay off (' + money(cents(l.payoff)) + ')</label>'
          + (canOverride ? ' <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-ovr-toggle="' + n + '">split by hand</button>' : '') + '</div>'
          + (canOverride ? '<div class="or-ovr row g-1 mt-1" data-ovr="' + n + '" hidden>'
            + ['penalty', 'interest', 'principal'].map((p) => '<div class="col-4"><input class="form-control form-control-sm text-end" name="items[' + n + '][override][' + p + ']" placeholder="' + p + '" inputmode="decimal" data-money disabled></div>').join('') + '</div>' : '')
          + '</td><td class="num">' + money(cents(l.total_due)) + '<div class="small text-muted">balance ' + money(cents(l.principal_balance)) + '</div></td>'
          + '<td><input class="form-control form-control-sm text-end" name="items[' + n + '][amount]" value="' + esc(k('items.' + n + '.amount')) + '" inputmode="decimal" data-money data-or-amount autocomplete="off"></td></tr>';
      });
      html += '</tbody></table>';
      if (!d.accounts.length && !d.loans.length) html += '<div class="text-muted small p-3">The member has no open account or loan yet. Open one on the Savings Account Center, or use the other items below.</div>';
      wrap.dataset.next = i;
      return html;
    }
    function cvRows(d) {
      let i = 0;
      let html = '<table class="table table-sm or-items mb-0"><thead><tr><th>Account</th><th class="num">Available</th><th class="num" style="width:170px">Withdraw</th></tr></thead><tbody>';
      d.accounts.forEach((a) => {
        if (a.type === 'time_deposit' || !a.withdrawable) return;
        const n = i++;
        html += '<tr><td><input type="hidden" name="items[' + n + '][kind]" value="savings"><input type="hidden" name="items[' + n + '][account_id]" value="' + a.id + '">'
          + '<strong>' + esc(a.product) + '</strong><div class="small text-muted">' + esc(a.no) + (a.status !== 'active' ? ', ' + esc(a.status) : '') + ', balance ' + money(cents(a.balance)) + '</div></td>'
          + '<td class="num">' + money(cents(a.available)) + '</td>'
          + '<td><input class="form-control form-control-sm text-end" name="items[' + n + '][amount]" value="' + esc(k('items.' + n + '.amount')) + '" inputmode="decimal" data-money data-or-amount autocomplete="off"></td></tr>';
      });
      html += '</tbody></table>';
      if (!i) html += '<div class="text-muted small p-3">No account of this member allows withdrawals.</div>';
      wrap.dataset.next = i;
      return html;
    }
    document.addEventListener('member:picked', (e) => { if (!e.detail || !e.detail.id) return; load(e.detail.id); });
    document.addEventListener('member:cleared', () => load(null));
    if (dateField) dateField.addEventListener('change', () => { if (memberId) load(memberId); });
    if (!memberId) load(null);
  });
})();
