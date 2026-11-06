/* =====================================================================
   Loans > Loan Release: the Loan Release Form. The release type (Cash or Cheque) chooses the list of the
   Cash/bank Account and the Cheque No.; the Loan Charges grid adds, deletes and changes the deductions; every
   change asks the server for the release computation (api.coop?a=release_plan): the amount of each deduction,
   the total charges, the net proceeds and the due date. No framework.
   ===================================================================== */
(function () {
  'use strict';

  const form = document.getElementById('lrForm');
  if (!form || form.dataset.released === '1') return;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const fmt = (v) => window.AppFmt.fmt(window.AppFmt.num(v));
  const grid = $('#lrGrid tbody');
  const tpl = $('#lrRowTpl');
  let rowSeq = $$('tr[data-ch]', grid).length;

  // ------------------------------------------------------------------ release type: Cash or Cheque
  const acct = $('#lrAcct');
  const chequeNo = $('#lrChequeNo');
  const note = $('#lrTypeNote');
  const lists = { cash: JSON.parse(acct.dataset.cash || '[]'), cheque: JSON.parse(acct.dataset.bank || '[]') };
  const remember = { cash: acct.value, cheque: '' };
  function releaseType() { const r = $('input[name="release_mode"]:checked', form); return r ? r.value : 'cash'; }
  function setType(type, initial) {
    if (!initial) remember[type === 'cash' ? 'cheque' : 'cash'] = acct.value;
    const keep = initial ? acct.value : remember[type];
    acct.innerHTML = '<option value=""></option>';
    lists[type].forEach(([id, label]) => {
      const o = document.createElement('option');
      o.value = String(id);
      o.textContent = label;
      if (String(id) === String(keep)) o.selected = true;
      acct.appendChild(o);
    });
    const cheque = type === 'cheque';
    chequeNo.disabled = !cheque;
    chequeNo.required = cheque;
    if (!cheque) chequeNo.value = '';
    if (note) note.textContent = cheque ? 'Posted as a check of Banking > Write Checks, numbered with the cheque no.' : 'Posted as a Cash Voucher of Banking > Cash Voucher.';
    if (cheque && !initial) chequeNo.focus();
  }
  $$('input[name="release_mode"]', form).forEach((r) => r.addEventListener('change', () => setType(releaseType(), false)));
  setType(releaseType(), true);

  // ------------------------------------------------------------------ the deductions
  function renumber() {
    // the names keep the order of the rows (ch[0], ch[1] ...) so the server reads them as the user sees them
    $$('tr[data-ch]', grid).forEach((tr, i) => {
      $$('[name^="ch["]', tr).forEach((f) => { f.name = f.name.replace(/^ch\[[^\]]*\]/, 'ch[' + i + ']'); });
    });
  }
  function addRow() {
    const html = tpl.innerHTML.replace(/__i__/g, String(rowSeq++));
    const body = document.createElement('tbody');
    body.innerHTML = html.trim();
    const tr = body.firstElementChild;
    grid.appendChild(tr);
    renumber();
    const pick = $('[data-ch-pick]', tr);
    if (pick) pick.focus();
    return tr;
  }
  const add = $('#lrAdd');
  if (add) add.addEventListener('click', () => addRow());
  grid.addEventListener('click', (e) => {
    const del = e.target.closest('[data-ch-del]');
    if (!del) return;
    del.closest('tr').remove();
    renumber();
    plan();
  });
  grid.addEventListener('change', (e) => {
    const tr = e.target.closest('tr[data-ch]');
    if (!tr) return;
    if (e.target.matches('[data-ch-pick]')) {
      // another charge: its own rate and amount
      $('[data-ch-rate]', tr).value = '';
      $('[data-ch-amount]', tr).value = '';
      tr.dataset.auto = '1';
      plan();
    }
  });
  grid.addEventListener('input', (e) => {
    const tr = e.target.closest('tr[data-ch]');
    if (!tr) return;
    if (e.target.matches('[data-ch-rate]')) {
      // a rate: the amount follows it
      $('[data-ch-amount]', tr).value = '';
      tr.dataset.auto = '1';
      later();
    } else if (e.target.matches('[data-ch-amount]')) {
      tr.dataset.auto = '0';
      later();
    }
  });

  // ------------------------------------------------------------------ the computation
  let seq = 0;
  function params() {
    const q = new URLSearchParams();
    const val = (sel) => { const f = $(sel, form); return f ? f.value : ''; };
    const am = $('input[name="amort_type"]:checked', form);
    q.set('term', val('[name="term"]'));
    q.set('term_unit', val('[name="term_unit"]'));
    q.set('mode', val('[name="mode_of_payment"]'));
    q.set('rate', val('[name="rate"]'));
    q.set('amort', am ? am.value : '');
    q.set('release', val('[name="release_date"]'));
    q.set('start', val('[name="start_date"]'));
    q.set('prev_loan_id', val('[name="previous_loan_id"]'));
    q.set('charges', JSON.stringify($$('tr[data-ch]', grid).map((tr) => ({
      charge_id: $('[data-ch-pick]', tr) ? $('[data-ch-pick]', tr).value : '',
      rate: $('[data-ch-rate]', tr).disabled ? '' : $('[data-ch-rate]', tr).value,
      // an amount worked out by the server is worked out again (the term or the rate may have changed); a typed one stays
      amount: tr.dataset.auto === '1' ? '' : $('[data-ch-amount]', tr).value.replace(/,/g, ''),
    }))));
    return q;
  }
  function apply(d) {
    const rows = $$('tr[data-ch]', grid);
    (d.rows || []).forEach((r, i) => {
      const tr = rows[i];
      if (!tr) return;
      const rate = $('[data-ch-rate]', tr), amt = $('[data-ch-amount]', tr);
      $('[data-ch-acct]', tr).textContent = r.account || '';
      $('[data-ch-basis]', tr).textContent = r.error ? r.error : (r.basis || '');
      tr.classList.toggle('is-bad', !!r.error);
      rate.disabled = !r.is_rate;
      rate.placeholder = r.is_rate ? '' : 'amount';
      if (!r.is_rate) rate.value = '';
      else if (rate.value === '' && r.rate !== null && r.rate !== undefined) rate.value = Number(r.rate).toFixed(2);
      if (r.amount !== null && r.amount !== undefined && (tr.dataset.auto === '1' || amt.value === '')) amt.value = Number(r.amount).toFixed(2);
    });
    const set = (sel, v) => { const n = $(sel); if (n && v !== undefined && v !== null) n.textContent = fmt(v); };
    set('#lrNet', d.net_proceeds);
    set('#lrTotal', d.total_charges);
    set('#lrPayoff', d.payoff);
    set('#lrPrincipal', d.principal);
    const net = $('#lrNetText');
    if (net) net.value = d.net_proceeds || '';
    $('#lrNet').classList.toggle('is-bad', window.AppFmt.num(d.net_proceeds) < 0);
    const due = $('#lrDue');
    if (due && d.due_date) due.value = d.due_date;
    const err = $('#lrError');
    if (err) { err.hidden = !d.error; err.textContent = d.error || ''; }
    const rel = $('#lrRelease');
    if (rel) rel.disabled = !!d.error;
  }
  function plan() {
    const mine = ++seq;
    form.classList.add('is-loading');
    fetch(form.dataset.api + '&' + params().toString(), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } })
      .then((r) => r.json())
      .then((d) => { if (mine === seq) apply(d); })
      .catch(() => {})
      .finally(() => { if (mine === seq) form.classList.remove('is-loading'); });
  }
  let timer = null;
  function later() { clearTimeout(timer); timer = setTimeout(plan, 350); }
  form.addEventListener('change', (e) => { if (e.target.matches('[data-plan]')) plan(); });
  form.addEventListener('input', (e) => { if (e.target.matches('input[data-plan]')) later(); });

  // ------------------------------------------------------------------ the start of payment follows the release date and the mode until it is changed by hand
  const start = $('#lrStart');
  let touched = false;
  start.addEventListener('input', () => { touched = true; });
  const months = { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 };
  const days = { daily: 1, weekly: 7, semi_monthly: 15 };
  function nextDue() {
    if (touched) return;
    const d = new Date($('#lrDate').value + 'T00:00:00');
    if (isNaN(d)) return;
    const mo = $('#lrMode').value;
    if (mo in months) { const day = d.getDate(); d.setDate(1); d.setMonth(d.getMonth() + months[mo]); const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate(); d.setDate(Math.min(day, last)); }
    else if (mo in days) d.setDate(d.getDate() + days[mo]);
    else return;
    start.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  $('#lrDate').addEventListener('change', nextDue);
  $('#lrMode').addEventListener('change', nextDue);
  // the amounts the server worked out are its own until the user types one
  $$('tr[data-ch]', grid).forEach((tr) => { tr.dataset.auto = '1'; });
})();
