/* =====================================================================
   Loan Collection Form: the payment mode and the cheques, the open loans, the amortization due details and the
   payment applied (with Override Payment and the payoff), Back Entry, Clear, the schedule and subsidiary dialogs,
   the passbook and error correct dialogs, and the date range of the Loan Collections list. The server works every
   figure out again when the payment is saved; nothing here is posted as it is shown.
   ===================================================================== */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const form = document.getElementById('lcForm');
  if (!form || !window.CoopMoney) return;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const el = (id) => document.getElementById(id);
  const M = window.CoopMoney;
  const cents = M.cents;
  const fmt = (c) => (c < 0 ? '-' : '') + M.money(Math.abs(c));
  const today = form.dataset.today;
  const modal = (m) => window.bootstrap.Modal.getOrCreateInstance(m);

  const mode = el('lcMode');
  const amount = el('lcAmount');
  const total = el('lcTotal');
  const date = el('lcDate');
  const back = el('lcBack');
  const loanId = el('lcLoanId');
  const payoff = el('lcPayoff');
  const ovBtn = el('lcOverride');
  const useOv = el('lcUseOv');
  const paid = { penalty: el('nPaid'), interest: el('iPaid'), principal: el('pPaid') };
  const chqBox = el('lcChequeBox');
  const chq = el('lcCheques');
  const loans = el('lcLoans');
  const status = el('lcStatus');
  let fig = null;

  const say = (msg, kind) => {
    if (!status) return;
    status.textContent = msg;
    status.className = kind ? 'is-' + kind : '';
  };

  // ------------------------------------------------------------------ the cheques, the amount, the total
  const chequeTotal = () => {
    let c = 0;
    if (chq) $$('input[data-chq-amount]', chq).forEach((i) => { c += cents(i.value); });
    const out = $('[data-chq-total]', form);
    if (out) out.textContent = fmt(c);
    return c;
  };
  const sumPaid = () => cents(paid.penalty.value) + cents(paid.interest.value) + cents(paid.principal.value);
  const overriding = () => useOv.value === '1';

  // The amount is typed for a cash or bank payment; it is the total of the cheques for a check, the payoff for a full
  // payment, and the total of Amount Paid while the payment is overridden.
  const syncAmountLock = () => {
    let lock = null;
    if (mode.value === 'check') { lock = chequeTotal(); amount.title = 'The total of the cheques'; }
    else if (payoff.checked && fig) { lock = cents(fig.payoff); amount.title = 'The payoff of the loan'; }
    else if (overriding()) { lock = sumPaid(); amount.title = 'The total of Amount Paid'; }
    else amount.title = 'The payment amount';
    amount.readOnly = lock !== null;
    amount.classList.toggle('sv-ro', lock !== null);
    if (lock !== null) amount.value = lock ? M.money(lock) : '';
  };

  const updateTotal = () => {
    const sum = sumPaid();
    total.value = sum ? fmt(sum) : '';
    el('lcTotalConfirm').value = total.value;
    if (!loanId.value) {
      say(!loans ? 'Choose the member in Receive from.' : ($('tr[data-loan]', loans) ? 'Select the loan to be paid in Open Loans.' : 'The member has no open loan.'));
      return;
    }
    const al = fig && fig.allocation;
    const typed = cents(amount.value);
    if (!overriding() && al && cents(al.excess) > 0) {
      say('The payment is ' + fmt(cents(al.excess)) + ' more than the loan can take (payoff ' + fmt(cents(fig.payoff)) + '). Lower the amount or tick Full payment.', 'bad');
    } else if (mode.value === 'check' && sum && chequeTotal() !== sum) {
      say('The cheques (' + fmt(chequeTotal()) + ') must add up to the payment applied (' + fmt(sum) + ').', 'warn');
    } else if (overriding() && typed && typed !== sum) {
      say('Amount Paid (' + fmt(sum) + ') must add up to the Cash Amount (' + fmt(typed) + ').', 'warn');
    } else if (sum) {
      say('Payment applied: penalty ' + fmt(cents(paid.penalty.value)) + ', interest ' + fmt(cents(paid.interest.value)) + ', principal ' + fmt(cents(paid.principal.value)) + (overriding() ? ' (overridden)' : '') + '.', 'ok');
    } else {
      say(mode.value === 'check' ? 'Enter the cheques in Cheque Payment Details.' : 'Enter the Cash Amount (the payment).');
    }
  };

  // ------------------------------------------------------------------ the figures of the selected loan
  const put = (id, v) => { const n = el(id); if (n) n.value = v; };
  const money = (v) => fmt(cents(v));
  const clearFigures = () => {
    ['pBal', 'iBal', 'nBal', 'pDue', 'iDue', 'nDue', 'pCnt', 'iCnt', 'nCnt', 'lcLastPay'].forEach((id) => put(id, ''));
    if (!overriding()) Object.values(paid).forEach((i) => { i.value = ''; });
    const cap = el('lcLoanCaption'); if (cap) cap.textContent = '';
    fig = null;
    updateTotal();
  };
  const show = (d) => {
    const det = d.details;
    put('pBal', money(det.principal.balance)); put('iBal', money(det.interest.balance)); put('nBal', money(det.penalty.balance));
    put('pDue', money(det.principal.due)); put('iDue', money(det.interest.due)); put('nDue', money(det.penalty.due));
    put('pCnt', det.principal.count); put('iCnt', det.interest.count); put('nCnt', det.penalty.count);
    put('lcLastPay', d.last_payment || '');
    const po = el('lcPayoffAmt'); if (po) po.textContent = money(d.payoff);
    const cap = el('lcLoanCaption'); if (cap) cap.textContent = d.loan.name + ' ' + d.loan.no + (d.next_due ? ', next due ' + d.next_due : '');
    if (payoff.checked || mode.value === 'check') syncAmountLock();
    if (!overriding()) {
      const al = d.allocation;
      paid.penalty.value = al ? money(al.penalty) : '';
      paid.interest.value = al ? money(al.interest) : '';
      paid.principal.value = al ? money(al.principal) : '';
    }
    updateTotal();
  };
  let seq = 0;
  let timer = null;
  const refresh = (now) => {
    clearTimeout(timer);
    const run = () => {
      if (!loanId.value) { clearFigures(); return; }
      const mine = ++seq;
      const a = overriding() ? '' : String(amount.value).replace(/,/g, '');
      const url = form.dataset.figures + '&loan=' + encodeURIComponent(loanId.value) + '&date=' + encodeURIComponent(date.value || today)
        + '&amount=' + encodeURIComponent(a) + (payoff.checked ? '&payoff=1' : '');
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => {
          if (mine !== seq) return;
          if (d.error) { say(d.error, 'bad'); return; }
          fig = d;
          show(d);
        })
        .catch(() => { if (mine === seq) say('The figures of the loan could not be loaded. Check the connection and try again.', 'bad'); });
    };
    if (now) run(); else timer = setTimeout(run, 220);
  };

  // ------------------------------------------------------------------ payment mode
  const syncMode = () => {
    const v = mode.value;
    $$('[data-mode-show]', form).forEach((n) => { n.hidden = n.dataset.modeShow !== v; });
    const lab = el('lcCashLabel');
    if (lab) lab.textContent = v === 'bank' ? 'Bank Account' : (v === 'savings' ? 'From Savings' : 'Cash Account');
    const isCheck = v === 'check';
    if (chqBox) { chqBox.disabled = !isCheck; chqBox.classList.toggle('is-off', !isCheck); }
    const wasLocked = amount.readOnly;
    syncAmountLock();
    if (wasLocked && !amount.readOnly && !payoff.checked && !overriding()) amount.value = '';
    refresh();
  };
  mode.addEventListener('change', syncMode);
  if (chq) {
    const onCheques = () => { chequeTotal(); if (mode.value === 'check') { syncAmountLock(); refresh(); } };
    chq.addEventListener('input', onCheques);
    chq.addEventListener('change', onCheques);
    chq.addEventListener('rows:changed', onCheques);
  }
  amount.addEventListener('input', () => { if (!amount.readOnly) refresh(); });
  date.addEventListener('change', () => refresh(true));

  // ------------------------------------------------------------------ Back Entry: an earlier date only when ticked
  const syncBack = () => {
    date.readOnly = !back.checked;
    const f = date.closest('.sv-field'); if (f) f.classList.toggle('is-locked', !back.checked);
    if (!back.checked && date.value !== today) { date.value = today; refresh(true); }
  };
  back.addEventListener('change', () => { syncBack(); if (back.checked) date.focus(); });

  // ------------------------------------------------------------------ Override Payment and the payoff
  const setOverride = (on, quiet) => {
    useOv.value = on ? '1' : '';
    ovBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    ovBtn.classList.toggle('active', on);
    Object.values(paid).forEach((i) => { i.readOnly = !on; i.classList.toggle('is-edit', on); i.tabIndex = on ? 0 : -1; });
    if (on && payoff.checked) payoff.checked = false;
    syncAmountLock();
    updateTotal();
    if (quiet) return;
    if (on) paid.penalty.focus(); else refresh(true);
  };
  if (ovBtn) ovBtn.addEventListener('click', () => setOverride(!overriding()));
  Object.values(paid).forEach((i) => {
    i.addEventListener('input', () => { if (overriding()) { syncAmountLock(); updateTotal(); } });
    i.addEventListener('blur', () => { if (overriding() && i.value.trim() !== '') i.value = M.money(cents(i.value)); });
  });
  payoff.addEventListener('change', () => {
    if (payoff.checked && overriding()) setOverride(false, true);
    const wasLocked = amount.readOnly;
    syncAmountLock();
    if (wasLocked && !amount.readOnly) amount.value = '';
    refresh(true);
  });

  // ------------------------------------------------------------------ Open Loans: the loan to be paid
  const ecSel = el('lcEcId');
  const selectLoan = (tr) => {
    if (loans) $$('tr[data-loan]', loans).forEach((r) => { const on = r === tr; r.classList.toggle('is-selected', on); r.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    loanId.value = tr ? tr.dataset.loan : '';
    el('lcLoanConfirm').value = tr ? tr.dataset.name + ' ' + tr.dataset.no : '';
    const save = el('lcSave');
    if (save && save.dataset.canSave === '1') save.disabled = !tr;
    refresh(true);
  };
  if (loans) {
    loans.addEventListener('click', (e) => {
      if (e.target.closest('button, a')) return;
      const tr = e.target.closest('tr[data-loan]');
      if (tr) selectLoan(tr);
    });
    loans.addEventListener('keydown', (e) => {
      const tr = e.target.closest('tr[data-loan]');
      if (!tr || e.target !== tr) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectLoan(tr); }
      else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const rows = $$('tr[data-loan]', loans);
        const next = rows[rows.indexOf(tr) + (e.key === 'ArrowDown' ? 1 : -1)];
        if (next) next.focus();
      }
    });
  }

  // View Amortization / View Subsidiary
  const viewModal = el('lcViewModal');
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-lc-view]');
    if (!b || !viewModal) return;
    const kind = b.dataset.lcView;
    el('lcViewTitle').textContent = kind === 'schedule' ? 'Amortization Schedule' : 'Subsidiary: the member\'s loan ledger';
    const body = el('lcViewBody');
    body.innerHTML = '<div class="text-muted small py-3">Loading ...</div>';
    modal(viewModal).show();
    fetch(form.dataset.view + '&a=' + kind + '&loan=' + encodeURIComponent(b.dataset.loanId) + '&date=' + encodeURIComponent(date.value || today), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then((r) => r.text())
      .then((html) => { body.innerHTML = html; })
      .catch(() => { body.innerHTML = '<p class="text-danger mb-0">It could not be loaded. Check the connection and try again.</p>'; });
  });

  // ------------------------------------------------------------------ Receive from: the member's open loans
  // Choosing the member opens the form for that member; what was already typed in OR Entry is kept.
  const pick = el('lcPick');
  if (pick) pick.addEventListener('member:picked', (e) => {
    if (!e.detail || e.detail.preset) return;
    const q = new URLSearchParams();
    q.set('member', e.detail.id);
    ['receipt_no', 'mode', 'bank_account_id', 'memo'].forEach((k) => { const n = form.elements.namedItem(k); if (n && n.value) q.set(k, n.value); });
    if (back.checked) { q.set('back_entry', '1'); q.set('txn_date', date.value); }
    window.location.href = form.dataset.clearUrl + '&' + q.toString();
  });

  // ------------------------------------------------------------------ Clear, Save
  el('lcClear').addEventListener('click', () => {
    try { form.reset(); } catch (x) { /* nothing */ }
    window.location.href = form.dataset.clearUrl;
  });
  document.addEventListener('submit', (e) => {
    if (e.target !== form) return;
    let msg = '';
    if (!form.elements.namedItem('member').value) msg = 'Choose the member in Receive from.';
    else if (!loanId.value) msg = 'Select the loan to be paid in Open Loans.';
    else if (!payoff.checked && !cents(amount.value)) msg = mode.value === 'check' ? 'Enter the cheques in Cheque Payment Details.' : 'Enter the Cash Amount (the payment).';
    if (msg) { e.preventDefault(); e.stopPropagation(); say(msg, 'bad'); status.scrollIntoView({ block: 'nearest' }); }
  }, true);

  // the two print choices are remembered on this computer
  ['lcPrintPb', 'lcPreview'].forEach((id) => {
    const cb = el(id);
    if (!cb) return;
    try { const v = window.localStorage.getItem('lc.' + id); if (v !== null && !form.dataset.fromPost) cb.checked = v === '1'; } catch (x) { /* private mode */ }
    cb.addEventListener('change', () => { try { window.localStorage.setItem('lc.' + id, cb.checked ? '1' : '0'); } catch (x) { /* private mode */ } });
  });

  // ------------------------------------------------------------------ the dialogs
  const pbModal = el('lcPbModal');
  if (pbModal) pbModal.addEventListener('show.bs.modal', () => {
    const want = loanId.value || pbModal.dataset.defaultLoan || '';
    let any = false;
    $$('[data-pb-loan]', pbModal).forEach((b) => { const on = b.dataset.pbLoan === String(want); b.hidden = !on; any = any || on; });
    const none = $('[data-pb-none]', pbModal); if (none) none.hidden = any;
  });
  const ecModal = el('lcEcModal');
  if (ecModal && ecSel) ecModal.addEventListener('show.bs.modal', () => {
    if (ecSel.value || !loanId.value) return;
    const o = Array.from(ecSel.options).find((x) => x.dataset.loan === loanId.value);
    if (o) ecSel.value = o.value;
  });
  const printModal = el('lcPrintModal');
  if (printModal) {
    printModal.addEventListener('show.bs.modal', () => {
      const tr = $('#lcRows tr[data-row].is-selected');
      $$('[data-lc-print="or"], [data-lc-print="val"]', printModal).forEach((a) => {
        a.classList.toggle('disabled', !tr);
        a.setAttribute('aria-disabled', tr ? 'false' : 'true');
        a.href = tr ? (a.dataset.lcPrint === 'or' ? tr.dataset.orUrl : tr.dataset.valUrl) : '#';
        $('[data-lc-ref]', a).textContent = tr ? tr.dataset.ref : '';
        $('[data-lc-hint]', a).hidden = !!tr;
      });
      const list = $('[data-lc-print="list"]', printModal);
      const f = el('lcFilter');
      if (list && f) {
        const p = new URLSearchParams(new FormData(f));
        ['p', 'member', 'loan'].forEach((k) => p.delete(k));
        list.href = list.dataset.base + '&' + p.toString();
      }
    });
    printModal.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-lc-print]');
      if (!a) return;
      if (a.classList.contains('disabled')) { e.preventDefault(); return; }
      setTimeout(() => modal(printModal).hide(), 50);
    });
  }
  // a dialog whose request was refused (a supervisor's approval, a missing reason) opens again
  const again = $('[data-lc-reopen]');
  if (again) {
    const m = $(again.dataset.lcReopen);
    if (m) {
      if (m.id === 'lcDelModal') {
        const id = (m.querySelector('[name="loan_txn_id"]') || {}).value;
        const tr = id ? $('#lcRows tr[data-row][data-id="' + id + '"]') : null;
        if (tr) $$('[data-row-field]', m).forEach((f) => { if (!f.matches('input')) f.textContent = tr.dataset[f.dataset.rowField] || ''; });
      }
      window.addEventListener('load', () => modal(m).show());
    }
  }

  // ------------------------------------------------------------------ the date range of the Loan Collections list
  const range = el('lcRange');
  const from = el('lcFrom');
  const to = el('lcTo');
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  if (range && from && to) {
    range.addEventListener('change', () => {
      const t = new Date(today + 'T00:00:00');
      if (range.value === 'today') { from.value = today; to.value = today; }
      else if (range.value === 'week') { const s = new Date(range.dataset.weekStart + 'T00:00:00'); const e = new Date(s); e.setDate(s.getDate() + 6); from.value = iso(s); to.value = iso(e); }
      else if (range.value === 'month') { from.value = today.slice(0, 8) + '01'; to.value = iso(new Date(t.getFullYear(), t.getMonth() + 1, 0)); }
      else from.focus();
    });
    [from, to].forEach((i) => i.addEventListener('change', () => { range.value = 'custom'; }));
  }

  // ------------------------------------------------------------------ start
  const save = el('lcSave');
  if (save) save.dataset.canSave = save.disabled ? '0' : '1';
  syncBack();
  if (overriding()) setOverride(true, true);
  syncMode();
  chequeTotal();
  const pre = loans ? $('tr[data-loan].is-selected', loans) : null;
  if (pre) selectLoan(pre); else updateTotal();
});
