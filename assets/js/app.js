/* G-SMaRT Integrated Cooperative System - front-end helpers (no build step) */
(function () {
  'use strict';

  // number helpers come from fmt.js (loaded in <head> so inline page scripts can use them)
  const { num, r2, fmt } = window.AppFmt;

  // ------------------------------------------------------------------ line grids
  function initGrid(table) {
    const items = JSON.parse(table.dataset.items || '{}');
    table._gridItems = items;   // v37: an item added with "Add new item" is put in here (QuickAdd)
    const taxes = JSON.parse(table.dataset.taxes || '{}');
    const unitsByItem = JSON.parse(table.dataset.units || '{}'); // item id -> [{id, code, factor, base, sd, pd}]
    const mode = table.dataset.mode || 'sales'; // sales | purchase | expense | journal
    const tbody = table.querySelector('tbody');
    const tpl = document.getElementById(table.dataset.template);
    const form = table.closest('form');
    let counter = tbody.querySelectorAll('tr').length + 1000;

    const field = (tr, name) => tr.querySelector('[data-f="' + name + '"]');

    function priceFor(itemId, cb) {
      const it = items[itemId];
      if (!it) return cb(0);
      if (mode !== 'sales') return cb(it.cost);
      const cust = form && form.querySelector('[name="customer_id"]');
      // the price level of the customer, or the member / nonmember level of the line of business, on the document date
      const unit = form && form.querySelector('[name="business_unit_id"]');
      const date = form && form.querySelector('[name="txn_date"]');
      if (table.dataset.priceUrl && ((cust && cust.value) || table.dataset.walkinLevel === '1')) {
        fetch(table.dataset.priceUrl + '&item=' + itemId + '&customer=' + (cust ? cust.value : '') + (unit && unit.value ? '&unit=' + unit.value : '') + (date && date.value ? '&date=' + date.value : ''))
          .then((r) => r.json()).then((d) => cb(num(d.price))).catch(() => cb(it.price));
      } else {
        cb(it.price);
      }
    }

    const r4 = (v) => Math.round((v + Number.EPSILON) * 10000) / 10000;

    /** base units in one of the unit the row is entered in */
    function rowFactor(tr) {
      const u = field(tr, 'uom_id');
      if (!u || !u.value) return 1;
      const o = u.options[u.selectedIndex];
      return num(o && o.dataset.factor) || 1;
    }

    /** fills the U/M select for the row's item and returns the unit picked by default */
    function fillUnits(tr, keep) {
      const sel = field(tr, 'uom_id');
      if (!sel) return null;
      const itemSel = field(tr, 'item_id');
      const list = (itemSel && unitsByItem[itemSel.value]) || [];
      sel.innerHTML = '<option value=""></option>';
      let kept = null, preferred = null, base = null;
      list.forEach((u) => {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = u.code;
        o.dataset.factor = u.factor;
        sel.appendChild(o);
        if (String(u.id) === String(keep)) kept = u;
        if (!preferred && (mode === 'purchase' ? u.pd : u.sd)) preferred = u;
        if (!base && u.base) base = u;
      });
      const picked = kept || preferred || base;
      if (picked) sel.value = String(picked.id);
      return picked;
    }

    function recalcRow(tr, source) {
      const itemSel = field(tr, 'item_id');
      const it = itemSel ? items[itemSel.value] : null;
      const qtyF = field(tr, 'qty'), rateF = field(tr, 'rate'), amtF = field(tr, 'amount'), discF = field(tr, 'discount_val') || field(tr, 'discount_pct');
      const kindF = field(tr, 'discount_kind');
      const taxSel = field(tr, 'tax_code_id'), taxF = field(tr, 'tax_amount');
      let amount = amtF ? num(amtF.value) : 0;
      if (it && it.type === 'subtotal') {
        let sum = 0, prev = tr.previousElementSibling;
        while (prev) {
          const ps = field(prev, 'item_id');
          const pit = ps ? items[ps.value] : null;
          if (pit && pit.type === 'subtotal') break;
          sum += num(field(prev, 'amount') ? field(prev, 'amount').value : 0);
          prev = prev.previousElementSibling;
        }
        if (amtF) amtF.value = '0.00';
        const lbl = tr.querySelector('[data-subtotal]');
        if (lbl) lbl.textContent = fmt(sum);
        if (taxF) taxF.value = '0.00';
        return;
      }
      if (it && it.type === 'discount') {
        const prev = tr.previousElementSibling;
        const prevAmt = prev && field(prev, 'amount') ? num(field(prev, 'amount').value) : 0;
        amount = it.pct > 0 ? -r2(prevAmt * it.pct / 100) : -Math.abs(num(rateF ? rateF.value : it.price));
        if (amtF) amtF.value = amount.toFixed(2);
      } else if (it && it.type === 'payment') {
        amount = -Math.abs(num(rateF ? rateF.value : 0));
        if (amtF) amtF.value = amount.toFixed(2);
      } else if (qtyF && rateF && source !== 'amount') {
        const q = num(qtyF.value), rt = num(rateF.value), d = discF ? num(discF.value) : 0;
        const kind = kindF ? kindF.value : 'pct';
        if (qtyF.value !== '' || rateF.value !== '') {
          const gross = r2(q * rt);
          amount = kind === 'amt' ? r2(gross - Math.min(Math.abs(d), Math.abs(gross)) * (gross < 0 ? -1 : 1)) : r2(gross * (1 - d / 100));
          if (amtF) amtF.value = amount.toFixed(2);
        }
      } else if (source === 'amount' && qtyF && rateF && num(qtyF.value) !== 0 && !(discF && num(discF.value))) {
        rateF.value = (amount / num(qtyF.value)).toFixed(4).replace(/\.?0+$/, '');
      }
      if (taxSel && taxF) {
        const rate = num(taxes[taxSel.value] || 0);
        taxF.value = r2(amount * rate / 100).toFixed(2);
      }
    }

    function recalcTotals() {
      let sub = 0, tax = 0, dr = 0, cr = 0;
      tbody.querySelectorAll('tr').forEach((tr) => {
        const a = field(tr, 'amount'), t = field(tr, 'tax_amount');
        const d = field(tr, 'debit'), c = field(tr, 'credit');
        if (a) sub += num(a.value);
        if (t) tax += num(t.value);
        if (d) dr += num(d.value);
        if (c) cr += num(c.value);
      });
      const group = table.dataset.totalGroup || 'main';
      let grandSub = 0, grandTax = 0;
      document.querySelectorAll('table.line-grid[data-total-group="' + group + '"]').forEach((tb) => {
        tb.querySelectorAll('tbody tr').forEach((tr) => {
          const a = tr.querySelector('[data-f="amount"]'), t = tr.querySelector('[data-f="tax_amount"]');
          if (a) grandSub += num(a.value);
          if (t) grandTax += num(t.value);
        });
      });
      const set = (k, v) => document.querySelectorAll('[data-total="' + group + '-' + k + '"]').forEach((el) => { el.textContent = fmt(v); });
      set('subtotal', grandSub);
      set('tax', grandTax);
      set('total', grandSub + grandTax);
      set('debit', dr);
      set('credit', cr);
      set('diff', dr - cr);
      set('grid-' + (table.id || ''), sub);
      document.dispatchEvent(new CustomEvent('grid:totals', { detail: { group, subtotal: grandSub, tax: grandTax, total: grandSub + grandTax } }));
    }

    function onItemChange(tr) {
      const sel = field(tr, 'item_id');
      const it = items[sel.value];
      if (!it) { fillUnits(tr, ''); recalcRow(tr); recalcTotals(); return; }
      const desc = field(tr, 'description');
      if (desc) desc.value = it.desc || it.name;
      const unit = field(tr, 'unit');
      if (unit) unit.value = it.unit || '';
      const taxSel = field(tr, 'tax_code_id');
      if (taxSel && it.tax) taxSel.value = String(it.tax);
      const qtyF = field(tr, 'qty');
      if (qtyF && (qtyF.value === '' || num(qtyF.value) === 0) && ['subtotal', 'discount', 'payment'].indexOf(it.type) < 0) qtyF.value = '1';
      const picked = fillUnits(tr, '');
      priceFor(sel.value, (price) => {
        const rateF = field(tr, 'rate');
        // the price is per base unit; the line is entered in the chosen unit
        const perEntered = picked ? r4(num(price) * num(picked.factor)) : price;
        if (rateF) rateF.value = (it.type === 'discount' && it.pct > 0) ? '' : perEntered;
        recalcRow(tr);
        recalcTotals();
      });
    }

    /** switching the unit re-prices the line for the new unit */
    function onUnitChange(tr, oldFactor) {
      const rateF = field(tr, 'rate');
      const f = rowFactor(tr);
      if (rateF && rateF.value !== '' && oldFactor > 0 && f > 0 && f !== oldFactor) {
        rateF.value = String(r4(num(rateF.value) / oldFactor * f));
      }
      recalcRow(tr);
      recalcTotals();
    }

    function bindRow(tr) {
      let unitFactor = rowFactor(tr);
      tr.addEventListener('change', (e) => {
        const f = e.target.dataset.f;
        if (f === 'item_id') { onItemChange(tr); unitFactor = rowFactor(tr); return; }
        if (f === 'uom_id') { const old = unitFactor; unitFactor = rowFactor(tr); return onUnitChange(tr, old); }
        recalcRow(tr, f);
        recalcTotals();
      });
      tr.addEventListener('input', (e) => {
        const f = e.target.dataset.f;
        if (['qty', 'rate', 'discount_pct', 'discount_val', 'debit', 'credit'].indexOf(f) >= 0) { recalcRow(tr, f); recalcTotals(); }
      });
      const rm = tr.querySelector('[data-remove-row]');
      if (rm) rm.addEventListener('click', () => { tr.remove(); recalcTotals(); });
      // a new line straight above this one, for an item forgotten in the middle of a long list
      const ins = tr.querySelector('[data-insert-row]');
      if (ins) ins.addEventListener('click', () => { const nr = addRow(tr); if (nr) markNew([nr]); });
    }

    // ---- the default site for items: copied onto the lines, so nobody has to pick it on every line.
    // A line whose site was changed by hand keeps its own; changing the default moves the others along.
    const headSite = form ? form.querySelector('select[name="site_id"]') : null;
    const rowSite = (tr) => tr.querySelector('select[name$="[site_id]"]');
    let lastSite = headSite ? headSite.value : '';
    const setSite = (sel, v) => { if (sel.value !== v && Array.prototype.some.call(sel.options, (o) => o.value === v)) { sel.value = v; if (sel.comboSync) sel.comboSync(); } };
    function defaultSite(tr) {
      const sel = rowSite(tr);
      if (headSite && sel && sel.value === '' && headSite.value !== '') setSite(sel, headSite.value);
    }
    if (headSite) {
      headSite.addEventListener('change', () => {
        tbody.querySelectorAll('tr').forEach((tr) => { const sel = rowSite(tr); if (sel && (sel.value === '' || sel.value === lastSite)) setSite(sel, headSite.value); });
        lastSite = headSite.value;
      });
    }

    // new lines are shown, lit up for a moment; the cursor is not put in them, because the item box would drop its
    // list over the buttons below and a second click on "Add line" would pick an item instead
    function markNew(rows) {
      rows.forEach((r) => { r.classList.remove('row-new'); void r.offsetWidth; r.classList.add('row-new'); setTimeout(() => r.classList.remove('row-new'), 1300); });
      if (rows[0]) { try { rows[0].scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ } }
    }

    /** a new line at the end, or straight above the line given */
    function addRow(before) {
      if (!tpl) return null;
      const html = tpl.innerHTML.replace(/__i__/g, 'n' + (counter++));
      const tmp = document.createElement('tbody');
      tmp.innerHTML = html.trim();
      const tr = tmp.firstElementChild;
      if (before && before.parentNode === tbody) tbody.insertBefore(tr, before); else tbody.appendChild(tr);
      bindRow(tr);
      defaultSite(tr);
      if (window.AppCombo) window.AppCombo.init(tr);
      tr.dispatchEvent(new CustomEvent('grid:row-added', { bubbles: true }));
      if (before) { tbody.querySelectorAll('tr').forEach((r) => recalcRow(r)); recalcTotals(); }
      return tr;
    }

    tbody.querySelectorAll('tr').forEach(bindRow);
    tbody.querySelectorAll('tr').forEach(defaultSite);
    // "Add line", "Add 5 lines" and "Add 10 lines"
    document.querySelectorAll('[data-add-row="' + table.id + '"]').forEach((b) => b.addEventListener('click', () => {
      const n = Math.max(1, Math.min(50, num(b.dataset.addCount) || 1));
      const added = [];
      for (let i = 0; i < n; i++) { const tr = addRow(); if (tr) added.push(tr); }
      markNew(added);
    }));
    if (!tbody.querySelector('tr')) { for (let i = 0; i < (num(table.dataset.minRows) || 3); i++) addRow(); }
    table.addRow = addRow;
    table.recalc = () => { tbody.querySelectorAll('tr').forEach((tr) => recalcRow(tr)); recalcTotals(); };
    // The tax of a line is worked out again when the form is saved, so the figure shown in the totals
    // has to be the same one. A line that carries a tax code has its tax put in step here, which keeps a
    // form filled from another document (a sales order from an estimate, an invoice from a sales order,
    // a bill from a purchase order) showing the tax and the total the source document showed.
    // Only the tax is touched: the amount of a saved line is left exactly as it was entered.
    tbody.querySelectorAll('tr').forEach((tr) => {
      const taxSel = field(tr, 'tax_code_id'), taxF = field(tr, 'tax_amount'), amtF = field(tr, 'amount');
      if (!taxSel || !taxF || !amtF) return;
      const rate = num(taxes[taxSel.value] || 0);
      const want = r2(num(amtF.value) * rate / 100);
      if (Math.abs(want - num(taxF.value)) > 0.004) taxF.value = want.toFixed(2);
    });
    recalcTotals();
  }

  document.querySelectorAll('table.line-grid').forEach(initGrid);

  // ------------------------------------------------------------------ journal grids: class of the entry and class of the lines
  // The Class chosen for the whole entry is shown on every line that has an account, and a line gets it as soon as
  // its account is chosen. A line whose class was changed by hand keeps its own. With no class on the entry yet,
  // the first class picked on a line becomes the class of the entry.
  document.querySelectorAll('table.line-grid[data-mode="journal"]').forEach((table) => {
    const form = table.closest('form');
    const head = form && form.querySelector('select[name="class_id"]');
    if (!head) return;
    let last = head.value;
    const lineClass = (tr) => tr.querySelector('select[name$="[class_id]"]');
    const hasAccount = (tr) => { const a = tr.querySelector('select[name$="[account_id]"]'); return !!a && a.value !== ''; };
    const spread = () => {
      Array.prototype.forEach.call(table.tBodies[0].rows, (tr) => {
        const ls = lineClass(tr);
        if (ls && hasAccount(tr) && (ls.value === '' || ls.value === last)) ls.value = head.value;
      });
      last = head.value;
    };
    head.addEventListener('change', spread);
    table.addEventListener('change', (e) => {
      const el = e.target;
      const tr = el.closest && el.closest('tr');
      if (!tr || !el.name) return;
      const ls = lineClass(tr);
      if (!ls) return;
      if (/\[account_id\]$/.test(el.name)) {
        if (el.value !== '' && ls.value === '') ls.value = head.value;
        if (el.value === '' && ls.value === head.value) ls.value = '';
      } else if (el === ls && head.value === '' && ls.value !== '') {
        head.value = ls.value;
        head.classList.remove('class-missing');
        spread();
      }
    });
  });

  // ------------------------------------------------------------------ "Add new" from a drop-down (v37)
  // A box marked data-add (customer, vendor, name, account, item) ends its list with "Add new ..." for the users who
  // may create that kind of record (the page says which in <body data-quick-add>). Choosing it opens the list's own
  // form in a window over the transaction; once saved there the new record is put in the box, and in every other box
  // of the same kind on the page, without leaving the transaction. See app/QuickAdd.php.
  window.AppQuickAdd = (function () {
    let cfg = null;
    try { cfg = JSON.parse(document.body.dataset.quickAdd || 'null'); } catch (e) { cfg = null; }
    const NOUN = { customer: 'customer', vendor: 'supplier', account: 'account', item: 'item' };
    const TITLE = { customer: 'New customer or job', vendor: 'New supplier', account: 'New account', item: 'New item' };
    let modalEl = null, modal = null, frame = null, origin = null, originKind = null, added = false, ready = false;

    function entries(sel) {
      if (!cfg || !sel || !sel.dataset.add || sel.disabled) return [];
      const form = sel.form || sel.closest('form');
      // a form that saves; or, on a transaction page, a form that chooses the vendor or customer to work with
      if (form && (form.getAttribute('method') || 'get').toLowerCase() !== 'post' && cfg.ptype !== 'transaction') return [];
      const kinds = sel.dataset.add === 'name' ? ['customer', 'vendor'] : [sel.dataset.add];
      return kinds.filter((k) => cfg.kinds[k]).map((k) => ({ kind: k, noun: NOUN[k] }));
    }

    function build() {
      modalEl = document.createElement('div');
      modalEl.className = 'modal fade qa-modal';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-labelledby', 'qaTitle');
      modalEl.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-md-down"><div class="modal-content">'
        + '<div class="modal-header"><span class="qa-ico"><i class="bi bi-plus-lg"></i></span><h5 class="modal-title" id="qaTitle"></h5>'
        + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        + '<div class="modal-body p-0"><div class="qa-types"></div><div class="qa-frame-wrap"><div class="qa-loading"><span class="spinner-border spinner-border-sm"></span> Opening the form</div><iframe class="qa-frame" title="New record"></iframe></div></div>'
        + '</div></div>';
      document.body.appendChild(modalEl);
      frame = modalEl.querySelector('iframe');
      frame.addEventListener('load', () => { modalEl.classList.remove('is-loading'); });
      modal = new bootstrap.Modal(modalEl);
      modalEl.addEventListener('shown.bs.modal', () => { ready = true; });
      modalEl.addEventListener('hidden.bs.modal', () => {
        frame.src = 'about:blank';
        // closed without adding: back to the box it was opened from
        const back = !added && origin && origin.parentNode && origin.parentNode.querySelector('.combo-input');
        if (back) back.focus();
      });
    }

    function load(url) {
      modalEl.classList.remove('is-picking');
      modalEl.classList.add('is-loading');
      frame.src = url;
    }

    function open(sel, kind, typed) {
      if (!cfg || !cfg.kinds[kind] || !window.bootstrap) return;
      if (!modalEl) build();
      origin = sel;
      originKind = kind;
      added = false;
      const q = '&from=' + encodeURIComponent(cfg.from || '') + (typed ? '&prefill=' + encodeURIComponent(typed) : '');
      ready = false;
      modalEl.querySelector('.modal-title').textContent = TITLE[kind];
      const types = modalEl.querySelector('.qa-types');
      types.innerHTML = '';
      if (kind === 'item') {
        // first the type of item, as on the Item List's "New item" menu
        const allowed = (sel.dataset.addTypes || '').split(',').filter(Boolean);
        const list = Object.keys(cfg.types).filter((t) => !allowed.length || allowed.indexOf(t) >= 0);
        types.innerHTML = '<p class="qa-types-q">What kind of item?</p><div class="qa-type-list">' + list.map((t) => '<button type="button" class="qa-type" data-type="' + t + '"><i class="bi bi-'
          + ({ service: 'tools', inventory: 'box-seam', non_inventory: 'bag', assembly: 'diagram-3', other_charge: 'receipt', subtotal: 'calculator', group: 'collection', discount: 'percent', payment: 'cash-coin' }[t] || 'box')
          + '"></i><span>' + cfg.types[t] + '</span></button>').join('') + '</div>';
        types.querySelectorAll('.qa-type').forEach((b) => b.addEventListener('click', () => {
          modalEl.querySelector('.modal-title').textContent = 'New ' + cfg.types[b.dataset.type].toLowerCase();
          load(cfg.kinds.item + '&type=' + encodeURIComponent(b.dataset.type) + q);
        }));
        modalEl.classList.add('is-picking');
        modalEl.classList.remove('is-loading');
        frame.src = 'about:blank';
      } else {
        const t = kind === 'account' && sel.dataset.addTypes ? '&types=' + encodeURIComponent(sel.dataset.addTypes) : '';
        load(cfg.kinds[kind] + t + q);
      }
      modal.show();
    }

    // the text of the new choice, written the way the box writes its others
    function nameText(sel, name, word) {
      const colon = Array.prototype.some.call(sel.options, (o) => /^(Customer|Supplier|Employee|Other): /.test(o.text));
      return colon ? word + ': ' + name : name + ' (' + word + ')';
    }
    function accountText(sel, r) {
      const opts = Array.prototype.filter.call(sel.options, (o) => o.value !== '');
      if (opts.some((o) => /^\S+ - /.test(o.text))) return r.code + ' - ' + r.name;
      if (opts.some((o) => /\([^()]*\)$/.test(o.text))) return r.name + ' (' + r.code + ')';
      return r.code + ' - ' + r.name;
    }
    function addOption(sel, value, text) {
      if (Array.prototype.some.call(sel.options, (o) => o.value === String(value))) return;
      const o = document.createElement('option');
      o.value = String(value);
      o.textContent = text;
      const key = text.toLocaleLowerCase();
      const before = Array.prototype.find.call(sel.options, (x) => x.value !== '' && x.text.toLocaleLowerCase() > key);
      sel.insertBefore(o, before || null);
    }
    function boxes() {
      const out = Array.prototype.slice.call(document.querySelectorAll('select[data-add]'));
      document.querySelectorAll('template').forEach((t) => t.content.querySelectorAll('select[data-add]').forEach((s) => out.push(s)));
      return out;
    }

    function apply(r) {
      added = true;
      const typesOf = (s) => (s.dataset.addTypes || '').split(',').filter(Boolean);
      boxes().forEach((s) => {
        const k = s.dataset.add;
        if (r.kind === 'customer' || r.kind === 'vendor') {
          if (k === r.kind) addOption(s, r.id, r.label);
          else if (k === 'name') addOption(s, (r.kind === 'customer' ? 'c' : 'v') + r.id, nameText(s, r.label, r.kind === 'customer' ? 'Customer' : 'Supplier'));
        } else if (r.kind === 'account' && k === 'account') {
          const ts = typesOf(s);
          if (!ts.length || ts.indexOf(r.type) >= 0 || s === origin) addOption(s, r.id, accountText(s, r));
        } else if (r.kind === 'item' && k === 'item') {
          const ts = typesOf(s);
          if (!ts.length || ts.indexOf(r.type) >= 0 || s === origin) addOption(s, r.id, r.label);
        }
      });
      if (r.kind === 'item' && r.grid) {
        document.querySelectorAll('table.line-grid').forEach((t) => { if (t._gridItems) t._gridItems[r.id] = r.grid[t.dataset.mode === 'purchase' ? 'purchase' : 'sales']; });
      }
      if (origin) {
        const v = origin.dataset.add === 'name' ? (r.kind === 'customer' ? 'c' : 'v') + r.id : String(r.id);
        if (Array.prototype.some.call(origin.options, (o) => o.value === v)) {
          origin.value = v;
          if (origin.comboSync) origin.comboSync();
          origin.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
      toast((r.kind === 'vendor' ? 'Supplier' : r.kind.charAt(0).toUpperCase() + r.kind.slice(1)) + ' added: ' + r.label);
    }

    function toast(text) {
      const t = document.createElement('div');
      t.className = 'qa-toast';
      t.setAttribute('role', 'status');
      t.innerHTML = '<i class="bi bi-check-circle-fill"></i><span></span>';
      t.querySelector('span').textContent = text;
      document.body.appendChild(t);
      requestAnimationFrame(() => t.classList.add('is-on'));
      setTimeout(() => { t.classList.remove('is-on'); setTimeout(() => t.remove(), 400); }, 3200);
    }

    window.addEventListener('message', (e) => {
      if (e.origin !== window.location.origin || !frame || e.source !== frame.contentWindow) return;
      const d = e.data && e.data.gsQuickAdd;
      if (!d) return;
      // a window still opening (a very quick save) is closed as soon as it has opened
      const close = () => { if (ready) modal.hide(); else modalEl.addEventListener('shown.bs.modal', () => modal.hide(), { once: true }); };
      if (d.close) { close(); return; }
      if (d.kind && d.id) { try { apply(d); } finally { close(); } }
    });

    // inside the window: Cancel (and Escape) close it
    if (document.body.classList.contains('is-popup') && window.parent !== window) {
      const close = () => window.parent.postMessage({ gsQuickAdd: { close: true } }, window.location.origin);
      document.querySelectorAll('[data-popup-close]').forEach((b) => b.addEventListener('click', close));
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !document.querySelector('.combo-list[style*="block"]')) close(); });
    }

    return { entries, open, apply };
  })();

  // ------------------------------------------------------------------ searchable drop-downs
  // <select data-combo> becomes a box you type in: the list narrows with every letter (incremental search) and
  // matches any part of the text, so an account is found by its description and a name by any word of it.
  // The <select> stays in the form (hidden) and keeps posting the value; "change" still fires on it.
  const CLASS_REQUIRED_MSG = 'Choose the class: it is required, and the journal entry carries it.';
  const Combo = (function () {
    let open = null;   // the combo whose list is showing
    const list = document.createElement('div');
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.style.display = 'none';
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(list));
    if (document.body) document.body.appendChild(list);

    const norm = (t) => String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function labelOf(sel) {
      const o = sel.options[sel.selectedIndex];
      return o && o.value !== '' ? o.text : '';
    }

    // The list is put in the part of the window the on-screen keyboard is NOT covering. On a phone held
    // on its side the keyboard takes most of the screen; visualViewport says where the visible band is,
    // so the list is placed and sized to fit inside it instead of ending up behind the keys.
    function place(c) {
      const vv = window.visualViewport;
      const seenTop = vv ? vv.offsetTop : 0;
      const seenH = vv ? vv.height : window.innerHeight;
      const seenW = vv ? vv.width : window.innerWidth;
      const seenLeft = vv ? vv.offsetLeft : 0;
      const seenBottom = seenTop + seenH;
      const r = c.input.getBoundingClientRect();
      // the box itself can be behind the keyboard; the list is hung off the edge of the visible band then
      const from = Math.min(Math.max(r.top, seenTop), seenBottom);
      const to = Math.min(Math.max(r.bottom, seenTop), seenBottom);
      const below = seenBottom - to - 12;
      const above = from - seenTop - 12;
      const under = below >= 150 || below >= above;
      const want = Math.min(Math.max(r.width, 260), seenW - 16);
      list.style.maxHeight = Math.max(90, Math.min(300, under ? below : above)) + 'px';
      list.style.minWidth = want + 'px';
      list.style.left = Math.max(seenLeft + 8, Math.min(r.left, seenLeft + seenW - want - 8)) + 'px';
      if (under) { list.style.top = (to + 2) + 'px'; list.style.bottom = 'auto'; }
      else { list.style.top = 'auto'; list.style.bottom = (window.innerHeight - from + 2) + 'px'; }
    }

    function render(c) {
      const q = norm(c.input.value.trim());
      const words = c.typed && q ? q.split(/\s+/) : [];
      const starts = [], rest = [];
      Array.prototype.forEach.call(c.sel.options, (o) => {
        if (o.value === '' || o.disabled) return;
        const t = norm(o.text);
        if (!words.every((w) => t.indexOf(w) >= 0)) return;
        (words.length && t.indexOf(words[0]) === 0 ? starts : rest).push(o);
      });
      c.items = starts.concat(rest).slice(0, 200);
      const mark = (text) => {
        if (!words.length) return esc(text);
        const t = norm(text); let out = '', i = 0;
        const hits = [];
        words.forEach((w) => { const at = t.indexOf(w); if (at >= 0) hits.push([at, at + w.length]); });
        hits.sort((a, b) => a[0] - b[0]);
        hits.forEach(([a, b]) => { if (a < i) return; out += esc(text.slice(i, a)) + '<mark>' + esc(text.slice(a, b)) + '</mark>'; i = b; });
        return out + esc(text.slice(i));
      };
      // the part in brackets at the end (account code, kind of name) is shown lighter
      // (one level of brackets inside is kept: "Santos, Maria (Aflatoun (youth) Member)")
      const split = (text) => { const m = /^(.*\S)\s+\(((?:[^()]|\([^()]*\))*)\)$/.exec(text); return m ? [m[1], m[2]] : [text, '']; };
      let html = c.blank && !words.length && c.sel.value !== '' ? '<div class="combo-opt combo-clear" data-i="-1">' + esc(c.blankText || 'Clear this box') + '</div>' : '';
      c.items.forEach((o, i) => {
        const [main, tail] = split(o.text);
        html += '<div class="combo-opt' + (o.value === c.sel.value ? ' is-current' : '') + '" role="option" data-i="' + i + '"><span>' + mark(main) + '</span>'
          + (tail ? '<span class="combo-tail">' + mark(tail) + '</span>' : '') + '</div>';
      });
      if (!c.items.length) html += '<div class="combo-none">Nothing matches "' + esc(c.input.value.trim()) + '"</div>';
      // v37: "Add new customer / supplier / account / item" at the foot of the list (QuickAdd)
      c.adds = window.AppQuickAdd ? window.AppQuickAdd.entries(c.sel) : [];
      if (c.adds.length) {
        const typed = c.typed ? c.input.value.trim() : '';
        html += '<div class="combo-adds">' + c.adds.map((a, j) => '<div class="combo-opt combo-add" role="option" data-i="' + (c.items.length + j) + '"><i class="bi bi-plus-circle"></i><span>'
          + (typed ? 'Add "' + esc(typed) + '" as a new ' + esc(a.noun) : 'Add new ' + esc(a.noun)) + '</span></div>').join('') + '</div>';
      }
      list.innerHTML = html;
      c.hi = c.items.length ? 0 : (c.adds.length && c.typed && c.input.value.trim() ? 0 : -1);
      if (!words.length) { const cur = c.items.findIndex((o) => o.value === c.sel.value); if (cur >= 0) c.hi = cur; }
      paintHi(c, true);
    }

    function paintHi(c, scroll) {
      list.querySelectorAll('.combo-opt').forEach((el) => el.classList.toggle('is-hi', +el.dataset.i === c.hi));
      const el = list.querySelector('.combo-opt.is-hi');
      if (el && scroll) { const top = el.offsetTop, bot = top + el.offsetHeight; if (top < list.scrollTop) list.scrollTop = top; else if (bot > list.scrollTop + list.clientHeight) list.scrollTop = bot - list.clientHeight; }
    }

    function show(c) {
      if (c.sel.disabled) return;
      open = c;
      list.style.display = 'block';
      place(c);
      render(c);
      // taking the focus makes the browser scroll the box into view, which moves it: measure again once
      // that has happened, so the list is not left where the box used to be
      requestAnimationFrame(() => { if (open === c) place(c); });
    }

    function hide(revert) {
      if (!open) return;
      const c = open;
      open = null;
      list.style.display = 'none';
      c.typed = false;
      if (revert) c.input.value = labelOf(c.sel);
    }

    function choose(c, i) {
      // an "Add new" line: the form of that list opens over the page, with what was typed as the name
      if (i >= c.items.length && c.adds && c.adds[i - c.items.length]) {
        const add = c.adds[i - c.items.length];
        const typed = c.typed ? c.input.value.trim() : '';
        hide(true);
        window.AppQuickAdd.open(c.sel, add.kind, typed);
        return;
      }
      const val = i < 0 ? '' : (c.items[i] ? c.items[i].value : null);
      if (val === null) return;
      const changed = c.sel.value !== val;
      c.sel.value = val;
      hide(true);
      if (changed) c.sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    list.addEventListener('mousedown', (e) => {
      const el = e.target.closest('.combo-opt');
      e.preventDefault();   // keep the focus in the box
      if (el && open) choose(open, +el.dataset.i);
    });
    window.addEventListener('scroll', (e) => { if (open && e.target !== list && !list.contains(e.target)) place(open); }, true);
    window.addEventListener('resize', () => { if (open) place(open); });
    // the keyboard coming up or going down, and the phone being turned
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', () => { if (open) place(open); });
      window.visualViewport.addEventListener('scroll', () => { if (open) place(open); });
    }
    window.addEventListener('orientationchange', () => setTimeout(() => { if (open) place(open); }, 250));

    function enhance(sel) {
      if (sel.dataset.comboReady === '1' || sel.closest('template')) return;
      sel.dataset.comboReady = '1';
      const first = sel.options[0];
      const c = { sel, items: [], hi: -1, typed: false, blank: !!(first && first.value === ''), blankText: first && first.value === '' ? first.text.trim() : '' };
      const wrap = document.createElement('div');
      wrap.className = 'combo';
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control form-control-sm combo-input';
      input.autocomplete = 'off';
      input.spellcheck = false;
      input.placeholder = sel.dataset.comboPlaceholder || 'Type to search';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      if (sel.title) input.title = sel.title;
      if (sel.id) { const lab = document.querySelector('label[for="' + sel.id + '"]'); if (lab) { input.id = sel.id + '_combo'; lab.htmlFor = input.id; } }
      c.input = input;
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(input);
      wrap.appendChild(sel);
      sel.classList.add('combo-src');
      sel.tabIndex = -1;
      if (sel.required) { sel.required = false; input.required = true; }
      input.disabled = sel.disabled;
      input.value = labelOf(sel);
      // a class that is required says so in words, not with the browser's general "fill out this field"
      if (sel.hasAttribute('data-class-required')) {
        const why = () => input.setCustomValidity(sel.value ? '' : CLASS_REQUIRED_MSG);
        why();
        sel.addEventListener('change', why);
        input.addEventListener('blur', () => setTimeout(why, 0));
      }

      input.addEventListener('focus', () => { input.select(); show(c); });
      input.addEventListener('click', () => { if (open !== c) { input.select(); show(c); } });
      input.addEventListener('input', () => { c.typed = true; if (open !== c) show(c); else render(c); });
      input.addEventListener('blur', () => {
        if (open !== c) return;
        // leaving with text typed: take the highlighted match; leaving it empty clears the choice
        if (c.typed && input.value.trim() === '' && c.blank) choose(c, -1);
        else if (c.typed && c.hi >= 0 && c.hi < c.items.length) choose(c, c.hi);
        else hide(true);
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (open !== c) { show(c); return; }
          const n = c.items.length; if (!n && !(c.adds && c.adds.length)) return;
          c.hi = e.key === 'ArrowDown' ? Math.min(n + (c.adds ? c.adds.length : 0) - 1, c.hi + 1) : Math.max(c.blank ? -1 : 0, c.hi - 1);
          paintHi(c, true);
        } else if (e.key === 'Enter') {
          if (open === c) { e.preventDefault(); if (c.typed && input.value.trim() === '' && c.blank) choose(c, -1); else if (c.hi >= -1) choose(c, c.hi); }
        } else if (e.key === 'Escape') {
          if (open === c) { e.preventDefault(); e.stopPropagation(); hide(true); }
        }
      });
      // value changed by other code (clear button, defaults): show it
      sel.addEventListener('change', () => { if (open !== c) input.value = labelOf(sel); });
      sel.comboSync = () => {
        input.value = labelOf(sel); input.disabled = sel.disabled;
        if (sel.hasAttribute('data-class-required')) input.setCustomValidity(sel.value ? '' : CLASS_REQUIRED_MSG);
      };
    }

    // Every long drop-down searches as you type: customers, vendors, employees and other names, items,
    // fixed assets, accounts ... Short lists (a status, a method, a yes/no) stay plain drop-downs.
    // A select can be kept plain with data-no-combo, or forced on with data-combo.
    const AUTO_MIN = 4;
    function auto(sel) {
      if (sel.multiple || sel.hasAttribute('data-no-combo') || sel.closest('[data-no-combo]')) return false;
      if (sel.hasAttribute('data-combo')) return true;
      // a box with "Add new" lines is always a searchable box, however short its list (QuickAdd)
      if (sel.dataset.add && window.AppQuickAdd && window.AppQuickAdd.entries(sel).length) return true;
      let n = 0;
      for (let i = 0; i < sel.options.length; i++) { if (sel.options[i].value !== '') n++; if (n > AUTO_MIN) return true; }
      return false;
    }
    function init(root) {
      (root || document).querySelectorAll('select').forEach((sel) => { if (auto(sel)) enhance(sel); });
    }
    return { init, sync: (root) => (root || document).querySelectorAll('select[data-combo]').forEach((s) => s.comboSync && s.comboSync()) };
  })();
  window.AppCombo = Combo;
  Combo.init(document);
  // the class boxes short enough to stay plain drop-downs give the same message
  document.querySelectorAll('select[data-class-required]:not(.combo-src)').forEach((sel) => {
    const why = () => sel.setCustomValidity(sel.value ? '' : CLASS_REQUIRED_MSG);
    why();
    sel.addEventListener('change', why);
  });
  document.addEventListener('reset', (e) => setTimeout(() => Combo.sync(e.target), 0));

  // ------------------------------------------------------------------ wide tables on a small screen
  // A table with more columns than the screen has room for used to push the whole page sideways, so on a
  // phone the menu bar and the buttons slid out of reach. Every table that is not already inside a box of
  // its own gets one here: the columns are swiped inside the box and the page itself stays where it is.
  // Left alone: tables whose heading is pinned to the window (a box of their own would stop that), the
  // totals block of a transaction, and anything already in a scrolling box.
  document.querySelectorAll('table.table').forEach((t) => {
    if (t.closest('.table-responsive') || t.classList.contains('totals-box') || t.querySelector('thead.sticky-top')) return;
    const par = t.parentNode;
    if (!par || par.nodeType !== 1) return;
    const box = document.createElement('div');
    box.className = 'table-responsive';
    par.insertBefore(box, t);
    box.appendChild(t);
  });

  // a soft shadow down the right-hand edge while there are still columns out of sight
  document.querySelectorAll('.table-responsive').forEach((box) => {
    const upd = () => box.classList.toggle('has-more', box.scrollWidth - box.clientWidth - box.scrollLeft > 2);
    box.addEventListener('scroll', upd, { passive: true });
    window.addEventListener('resize', upd);
    upd();
  });

  // ------------------------------------------------------------------ misc
  // [data-confirm] on a button, a link or a form asks first: assets/js/dialog.js (the program's own window, never the
  // browser's box). A form marked data-once is asked by coop.js, which also fills in {amount} and the like.

  // ------------------------------------------------------------------ saving without a class
  // Any transaction form with a Class box asks for confirmation when it is saved with no class chosen.
  // Skipped for: forms or buttons marked data-no-class-check, forms whose data-class-check-when amounts are all empty, buttons that do not save (preview, load lines),
  // a Class box that is hidden or has no classes to choose from.
  (function () {
    const NOT_SAVING = ['preview', 'load', 'save_units', 'suspend'];
    let modalEl = null, modal = null, onContinue = null, focusBack = null;

    function build() {
      modalEl = document.createElement('div');
      modalEl.className = 'modal fade class-confirm';
      modalEl.id = 'classConfirmModal';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-labelledby', 'classConfirmTitle');
      modalEl.innerHTML =
        '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><span class="class-confirm-ico"><i class="bi bi-tag"></i></span>'
        + '<h5 class="modal-title" id="classConfirmTitle">No class selected</h5>'
        + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        + '<div class="modal-body"><p class="mb-2">This transaction has no class. It will be saved as <strong>Unclassified</strong> and will not appear under any class on the class reports.</p>'
        + '<p class="mb-0 text-muted small">Do you want to continue saving?</p></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-act="back"><i class="bi bi-arrow-left"></i> Choose a class</button>'
        + '<button type="button" class="btn btn-primary" data-act="go"><i class="bi bi-check2"></i> Continue saving</button></div>'
        + '</div></div>';
      document.body.appendChild(modalEl);
      modal = new bootstrap.Modal(modalEl);
      modalEl.querySelector('[data-act="go"]').addEventListener('click', () => { const f = onContinue; onContinue = null; focusBack = null; modal.hide(); if (f) f(); });
      modalEl.querySelector('[data-act="back"]').addEventListener('click', () => modal.hide());
      modalEl.addEventListener('shown.bs.modal', () => modalEl.querySelector('[data-act="back"]').focus());
      modalEl.addEventListener('hidden.bs.modal', () => {
        onContinue = null;
        // after Bootstrap has handed the focus back to the Save button, move it to the Class box
        const sel = focusBack;
        focusBack = null;
        if (sel) { sel.classList.add('class-missing'); setTimeout(() => { try { sel.focus(); } catch (e) { /* ignore */ } }, 30); }
      });
    }

    document.addEventListener('change', (e) => { if (e.target.matches && e.target.matches('select.class-missing')) e.target.classList.remove('class-missing'); });

    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
      if (form.dataset.classConfirmed === '1' || form.hasAttribute('data-no-class-check')) return;
      const btn = e.submitter || null;
      if (btn && (btn.hasAttribute('data-no-class-check') || btn.hasAttribute('formnovalidate'))) return;
      if (btn && NOT_SAVING.indexOf(btn.value) >= 0) return;
      if (btn && NOT_SAVING.indexOf(btn.dataset.do || '') >= 0) return;
      const sel = form.querySelector('select[name="class_id"]');
      if (!sel || sel.disabled || sel.value !== '' || sel.offsetParent === null) return;
      if (!Array.prototype.some.call(sel.options, (o) => o.value !== '')) return;   // no classes set up yet
      // journal entries: nothing to ask when every line that is filled in carries its own class
      // (a cash voucher still asks, because the class at the top is the one its cash line gets)
      const lineSels = Array.prototype.filter.call(form.querySelectorAll('select[name$="[class_id]"]'), (ls) => {
        const tr = ls.closest('tr');
        const acc = tr && tr.querySelector('select[name$="[account_id]"]');
        return acc && acc.value !== '';
      });
      if (lineSels.length && !form.querySelector('select[name="account_id"]') && lineSels.every((ls) => ls.value !== '')) return;
      // forms where the class only matters when certain amounts are entered (bank reconciliation: service charge, interest)
      const when = form.getAttribute('data-class-check-when');
      if (when && !Array.prototype.some.call(form.querySelectorAll(when), (i) => num(i.value) > 0)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      const go = () => {
        form.dataset.classConfirmed = '1';
        if (typeof form.requestSubmit === 'function') { btn && btn.form === form ? form.requestSubmit(btn) : form.requestSubmit(); }
        else { form.submit(); }
        setTimeout(() => { delete form.dataset.classConfirmed; }, 0);   // ask again if the page stays open (validation stopped the save)
      };
      if (!window.bootstrap || !bootstrap.Modal) {
        AppDialog.confirm('This transaction has no class. It will be saved as Unclassified and will not appear under any class on the class reports.',
          { title: 'No class selected', ok: 'Continue saving', cancel: 'Choose a class', tone: 'warning' }).then((yes) => { if (yes) go(); else sel.focus(); });
        return;
      }
      if (!modalEl) build();
      onContinue = go;
      focusBack = sel;
      modal.show();
    }, true);
  })();

  // ------------------------------------------------------------------ Memorize / Load memorized (modules/txn/_memorize.php)
  // "Memorize" in the box sends the form with memorize=1: the server checks it as for saving and keeps it on the
  // Memorized Transactions list instead of recording it. Nothing else is asked (class, stock) because nothing is saved.
  document.addEventListener('click', (e) => {
    const go = e.target.closest('[data-memorize-go]');
    if (!go) return;
    const box = go.closest('.modal');
    const form = go.closest('form') || (box && box.closest('form'));
    if (!form) return;
    const name = form.querySelector('[data-mem-name]');
    if (name && name.value.trim() === '') { name.classList.add('is-invalid'); name.focus(); return; }
    form.elements.memorize.value = '1';
    form.dataset.classConfirmed = '1';
    if (form.elements.confirm_negative) form.elements.confirm_negative.value = '1';
    const nv = form.noValidate;
    form.noValidate = true;
    if (window.bootstrap && box) bootstrap.Modal.getOrCreateInstance(box).hide();
    if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    setTimeout(() => { form.noValidate = nv; form.elements.memorize.value = '0'; delete form.dataset.classConfirmed; if (form.elements.confirm_negative) form.elements.confirm_negative.value = '0'; }, 0);
  });
  document.addEventListener('input', (e) => { if (e.target.matches && e.target.matches('[data-mem-name]')) e.target.classList.remove('is-invalid'); });
  // loading a memorized transaction replaces what is on the form: ask first when something was typed
  let formTouched = false;
  document.addEventListener('input', (e) => { if (e.target.closest && e.target.closest('main form[method="post"]') && !e.target.closest('.modal')) formTouched = true; });
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-mem-load]');
    if (!a || !formTouched) return;
    e.preventDefault();
    AppDialog.confirm('Replace what is on the form with the memorized transaction?', { title: 'Load memorized transaction', ok: 'Replace' })
      .then((yes) => { if (yes) window.location.href = a.href; });
  });

  // ------------------------------------------------------------------ stock below zero: ask before saving
  // A form carrying confirm_negative (sales invoice, sales receipt, POS, inventory adjustment and transfer) is
  // not saved when it would take an item below zero at a site: the page comes back with the question
  // "Saving the transaction will lead the inventory to a negative balance. Do you want to continue?".
  // Yes sends the form again, with the button that was pressed the first time; No leaves the form as it is
  // to be changed or cancelled. window.AppNegStock.ask() is also used by the POS register before it sends.
  (function () {
    // the button pressed is remembered, so Yes can press the same one again
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement) || !form.elements.confirm_submitter) return;
      const b = e.submitter;
      form.elements.confirm_submitter.value = b && b.name ? b.name + '=' + (b.value || '') : '';
    }, true);

    let modalEl = null, modal = null, onYes = null, onNo = null;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    function build() {
      modalEl = document.createElement('div');
      modalEl.className = 'modal fade class-confirm neg-stock-confirm';
      modalEl.id = 'negStockModal';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-labelledby', 'negStockTitle');
      modalEl.setAttribute('data-bs-backdrop', 'static');
      modalEl.innerHTML =
        '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><span class="class-confirm-ico neg-ico"><i class="bi bi-exclamation-triangle"></i></span>'
        + '<h5 class="modal-title" id="negStockTitle">Stock will go below zero</h5></div>'
        + '<div class="modal-body"><p class="mb-2">Saving the transaction will lead the inventory to a negative balance.</p>'
        + '<div class="neg-stock-list"></div>'
        + '<p class="mb-0 fw-semibold">Do you want to continue?</p></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-act="no"><i class="bi bi-x-lg"></i> No</button>'
        + '<button type="button" class="btn btn-warning" data-act="yes"><i class="bi bi-check2"></i> Yes</button></div>'
        + '</div></div>';
      document.body.appendChild(modalEl);
      modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });
      modalEl.querySelector('[data-act="yes"]').addEventListener('click', () => { const f = onYes; onYes = null; onNo = null; modal.hide(); if (f) f(); });
      modalEl.querySelector('[data-act="no"]').addEventListener('click', () => modal.hide());
      modalEl.addEventListener('shown.bs.modal', () => modalEl.querySelector('[data-act="no"]').focus());
      modalEl.addEventListener('hidden.bs.modal', () => { const f = onNo; onYes = null; onNo = null; if (f) f(); });
    }
    /** rows: [{code, name?, site?, before, after}] */
    function ask(rows, yes, no) {
      const text = 'Saving the transaction will lead the inventory to a negative balance.\n\n'
        + rows.map((r) => r.code + (r.site ? ' at ' + r.site : '') + ': ' + r.before + ' on hand, would be left with ' + r.after).join('\n')
        + '\n\nDo you want to continue?';
      if (!window.bootstrap || !bootstrap.Modal) { AppDialog.confirm(text, { title: 'Stock will go below zero', ok: 'Yes', cancel: 'No', tone: 'warning' }).then((y) => { if (y) yes(); else if (no) no(); }); return; }
      if (!modalEl) build();
      modalEl.querySelector('.neg-stock-list').innerHTML = '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Item</th>'
        + (rows.some((r) => r.site) ? '<th>Site</th>' : '') + '<th class="num">On hand</th><th class="num">After saving</th></tr></thead><tbody>'
        + rows.map((r) => '<tr><td>' + esc(r.code) + (r.name ? '<div class="small text-muted">' + esc(r.name) + '</div>' : '') + '</td>'
          + (rows.some((x) => x.site) ? '<td>' + esc(r.site || '') + '</td>' : '')
          + '<td class="num">' + esc(r.before) + '</td><td class="num neg">' + esc(r.after) + '</td></tr>').join('')
        + '</tbody></table></div>';
      onYes = yes; onNo = no || null;
      modal.show();
    }
    /** send the form again with the answer Yes */
    function resend(form, btn) {
      form.elements.confirm_negative.value = '1';
      form.dataset.classConfirmed = '1';
      if (typeof form.requestSubmit === 'function') { btn && btn.form === form ? form.requestSubmit(btn) : form.requestSubmit(); }
      else {
        if (btn && btn.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
        form.submit();
      }
      setTimeout(() => { delete form.dataset.classConfirmed; }, 0);
    }
    window.AppNegStock = { ask, resend };

    // the page came back with the question
    const alertEl = document.querySelector('[data-negative-stock]');
    const field = document.querySelector('form input[name="confirm_negative"]');
    if (!alertEl || !field) return;
    const form = field.form;
    let rows = [];
    try { rows = JSON.parse(alertEl.dataset.negativeStock || '[]'); } catch (e) { rows = []; }
    const pressed = (form.elements.confirm_submitter ? form.elements.confirm_submitter.value : '').split('=');
    const btn = pressed[0] ? Array.prototype.find.call(form.querySelectorAll('button[name], input[type="submit"][name]'),
      (b) => b.name === pressed[0] && String(b.value || '') === pressed.slice(1).join('=')) || null : null;
    const show = () => ask(rows, () => resend(form, btn));
    if (document.readyState === 'complete') setTimeout(show, 50); else window.addEventListener('load', () => setTimeout(show, 50));
  })();

  // ------------------------------------------------------------------ attachments (v40, app/Attachments.php)
  // The Attachments box of a form keeps every file chosen (more can be added with the button or by dropping them on
  // it), lists each with its size and a Remove, and turns away a file the server would not take before anything is
  // sent. On the page of a saved document the same box attaches the files straight away with its Attach button.
  (function () {
    const BLOCK = /\.(exe|msi|msp|bat|cmd|com|scr|pif|cpl|dll|sys|vbs|vbe|jse|wsf|wsh|ps1|psm1|hta|lnk|reg|msc|jar|apk|app|deb|rpm|sh|php|phtml|phar)$/i;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b >= 1024 ? Math.round(b / 1024) + ' KB' : b + ' bytes'));
    const icon = (n) => {
      const e = (String(n).split('.').pop() || '').toLowerCase();
      if (e === 'pdf') return 'file-earmark-pdf';
      if (/^(jpe?g|png|gif|webp|bmp|tiff?|heic|svg)$/.test(e)) return 'file-earmark-image';
      if (/^(docx?|rtf|odt)$/.test(e)) return 'file-earmark-word';
      if (/^(xlsx?|xlsm|csv|ods)$/.test(e)) return 'file-earmark-excel';
      if (/^(pptx?|odp)$/.test(e)) return 'file-earmark-ppt';
      if (/^(zip|rar|7z|gz|tar)$/.test(e)) return 'file-earmark-zip';
      if (/^(html?|css|js|json|xml)$/.test(e)) return 'file-earmark-code';
      if (/^(txt|eml|msg)$/.test(e)) return 'file-earmark-text';
      return 'file-earmark';
    };
    document.querySelectorAll('[data-att]').forEach((box) => {
      const input = box.querySelector('[data-att-input]');
      if (!input) return;
      const list = box.querySelector('[data-att-list]');
      const warn = box.querySelector('[data-att-warn]');
      const drop = box.querySelector('.att-drop');
      const send = box.querySelector('[data-att-send]');
      const countLabel = box.querySelector('[data-att-count-label]');
      const maxFile = +box.dataset.attMax || 0;
      const maxPost = +box.dataset.attPost || 0;
      const maxCount = +box.dataset.attCount || 20;
      let dt = null;
      try { dt = new DataTransfer(); } catch (e) { dt = null; }
      const say = (msgs) => { if (!warn) return; warn.hidden = !msgs.length; warn.innerHTML = msgs.map((m) => '<i class="bi bi-exclamation-triangle"></i> ' + esc(m)).join('<br>'); };
      const queued = () => (dt ? Array.from(dt.files) : Array.from(input.files || []));
      function counts() {
        if (!countLabel || !list) return;
        const n = list.querySelectorAll('.att-item:not(.is-removing)').length;
        countLabel.textContent = n ? n + (n === 1 ? ' file' : ' files') : 'None yet';
      }
      function render() {
        if (!list) return;
        list.querySelectorAll('.is-queued').forEach((li) => li.remove());
        queued().forEach((f, i) => {
          const li = document.createElement('li');
          li.className = 'att-item is-new is-queued';
          li.innerHTML = '<span class="att-ico"><i class="bi bi-' + icon(f.name) + '"></i></span>'
            + '<span class="att-text"><span class="att-name">' + esc(f.name) + '</span><span class="att-meta">' + size(f.size)
            + (send ? ', ready to attach' : ', attached when you save') + '</span></span>'
            + (dt ? '<span class="att-actions"><button type="button" class="btn btn-sm btn-light att-btn" data-i="' + i + '" title="Take it off"><i class="bi bi-x-lg"></i><span> Remove</span></button></span>' : '');
          list.appendChild(li);
        });
        if (send) {
          const n = queued().length;
          send.hidden = !n;
          const lab = send.querySelector('[data-att-n]');
          if (lab) lab.textContent = n === 1 ? '1 file' : n + ' files';
        }
        counts();
      }
      function add(files) {
        const msgs = [];
        let total = queued().reduce((s, f) => s + f.size, 0);
        Array.from(files).forEach((f) => {
          if (BLOCK.test(f.name)) { msgs.push('"' + f.name + '" is a program file; program files cannot be attached.'); return; }
          if (!f.size) { msgs.push('"' + f.name + '" is empty.'); return; }
          if (maxFile && f.size > maxFile) { msgs.push('"' + f.name + '" (' + size(f.size) + ') is larger than ' + size(maxFile) + ', the most the server takes for one file.'); return; }
          if (!dt) return;
          if (Array.from(dt.files).some((x) => x.name === f.name && x.size === f.size)) return;
          if (dt.files.length >= maxCount) { msgs.push('Up to ' + maxCount + ' files can go in one save. Save, then add "' + f.name + '" and the rest.'); return; }
          if (maxPost && total + f.size > maxPost * 0.95) { msgs.push('"' + f.name + '" would take the files of this save over ' + size(maxPost) + '. Save first, then add it.'); return; }
          dt.items.add(f);
          total += f.size;
        });
        if (dt) input.files = dt.files;
        say(msgs);
        render();
      }
      input.addEventListener('change', () => {
        const picked = Array.from(input.files || []);
        if (!dt) {   // an older browser: the box holds what was picked last
          const bad = picked.filter((f) => BLOCK.test(f.name) || (maxFile && f.size > maxFile));
          if (bad.length) { input.value = ''; add(bad); return; }
          say([]); render(); return;
        }
        input.files = dt.files;   // the files chosen before stay; the new ones are added to them
        add(picked);
      });
      if (drop) {
        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
        ['dragleave', 'dragend'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
        drop.addEventListener('drop', (e) => {
          e.preventDefault();
          drop.classList.remove('is-over');
          if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            if (dt) add(e.dataTransfer.files); else { input.files = e.dataTransfer.files; render(); }
          }
        });
      }
      if (list) {
        list.addEventListener('click', (e) => {
          const q = e.target.closest('button[data-i]');
          if (q && dt) { dt.items.remove(+q.dataset.i); input.files = dt.files; render(); return; }
          const k = e.target.closest('[data-att-drop]');
          if (k) { k.closest('.att-item').remove(); counts(); }
        });
        list.addEventListener('change', (e) => {
          const cb = e.target.closest('[data-att-remove]');
          if (!cb) return;
          const li = cb.closest('.att-item');
          li.classList.toggle('is-removing', cb.checked);
          const lab = cb.parentElement.querySelector('span');
          if (lab) lab.textContent = cb.checked ? ' Undo' : ' Remove';
          counts();
        });
        list.querySelectorAll('[data-att-remove]:checked').forEach((cb) => { const lab = cb.parentElement.querySelector('span'); if (lab) lab.textContent = ' Undo'; });
      }
      if (send && box.tagName === 'FORM') {
        box.addEventListener('submit', () => {
          const b = send.querySelector('button');
          if (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Attaching…'; }
        });
      }
    });
  })();

  // ------------------------------------------------------------------ long forms travel in one field
  // PHP reads only the first max_input_vars fields of a form (1000 as installed) and silently drops the rest:
  // a bill of 122 lines has more than 1,400 fields and would be saved without its last 40 lines. A long form
  // is therefore sent as one field, _packed, holding every [name, value] pair; the server unfolds it again
  // (unpack_posted_form in app/helpers.php). This runs last, after every other submit handler has had its say.
  const PACK_ABOVE = 300;
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    // a form that sends files (multipart) is packed too: its files are left out of the pairs and go as they are
    const named = Array.prototype.filter.call(form.elements, (el) => el.name && !el.disabled);
    if (named.length <= PACK_ABOVE) return;
    let fd;
    try { fd = new FormData(form, e.submitter || undefined); } catch (err) { fd = new FormData(form); if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value); }
    const pairs = [];
    let csrf = '';
    fd.forEach((v, k) => { if (k === '_csrf') { csrf = v; } else if (typeof v === 'string') { pairs.push([k, v]); } });
    // everything else is left out of this submission; the boxes are given back at once, so a form that opens in
    // another tab, or a page the browser keeps, still works
    const off = named.filter((el) => el.name !== '_csrf' && el.type !== 'file');
    off.forEach((el) => { el.disabled = true; });
    if (e.submitter && e.submitter.name) { e.submitter.dataset.packName = e.submitter.name; e.submitter.removeAttribute('name'); }
    let box = form.querySelector('input[name="_packed"]');
    if (!box) { box = document.createElement('input'); box.type = 'hidden'; box.name = '_packed'; form.appendChild(box); }
    box.value = JSON.stringify(pairs);
    if (!form.querySelector('input[name="_csrf"]') && csrf) { const c = document.createElement('input'); c.type = 'hidden'; c.name = '_csrf'; c.value = csrf; form.appendChild(c); }
    setTimeout(() => {
      off.forEach((el) => { el.disabled = false; });
      if (e.submitter && e.submitter.dataset.packName) { e.submitter.name = e.submitter.dataset.packName; delete e.submitter.dataset.packName; }
      box.remove();
    }, 0);
  });

  document.querySelectorAll('[data-check-all]').forEach((master) => {
    master.addEventListener('change', () => {
      document.querySelectorAll(master.dataset.checkAll).forEach((cb) => { cb.checked = master.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); });
    });
  });

  // auto-submit selects
  document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // A drop-down list of check boxes (multi_check_field): the button shows what is ticked; a filter form is sent
  // when the list is closed after a change, or by its Show button.
  document.querySelectorAll('[data-multi-check]').forEach((box) => {
    const btn = box.querySelector('.multi-check-btn');
    const text = box.querySelector('.multi-check-text');
    const boxes = () => Array.from(box.querySelectorAll('.multi-check-list input[type=checkbox]'));
    const state = () => boxes().filter((c) => c.checked).map((c) => c.value).join(',');
    const label = () => {
      const names = boxes().filter((c) => c.checked).map((c) => c.closest('label').textContent.trim());
      text.textContent = names.length === 0 ? box.dataset.all
        : names.length <= 2 ? names.join(', ') : names[0] + ', ' + names[1] + ' and ' + (names.length - 2) + ' more ' + box.dataset.noun;
      btn.classList.toggle('has-picks', names.length > 0);
      if (names.length) btn.classList.remove('is-invalid');
    };
    let before = state();
    box.addEventListener('change', (e) => { if (e.target.matches('.multi-check-list input')) label(); });
    const all = box.querySelector('[data-mc-all]');
    const none = box.querySelector('[data-mc-none]');
    const fire = () => box.dispatchEvent(new Event('change', { bubbles: true }));
    if (all) all.addEventListener('click', () => { boxes().forEach((c) => { if (!c.closest('.multi-check-item').hidden) c.checked = true; }); label(); fire(); });
    if (none) none.addEventListener('click', () => { boxes().forEach((c) => { c.checked = false; }); label(); fire(); });
    const find = box.querySelector('[data-mc-find]');
    if (find) {
      find.addEventListener('input', () => {
        const words = find.value.toLowerCase().split(/\s+/).filter(Boolean);
        box.querySelectorAll('.multi-check-item').forEach((it) => {
          const t = it.textContent.toLowerCase();
          it.hidden = !words.every((w) => t.indexOf(w) >= 0);
        });
        // a group heading shows while one of its choices does
        box.querySelectorAll('[data-mc-group]').forEach((g) => {
          let n = g.nextElementSibling; let any = false;
          while (n && !n.hasAttribute('data-mc-group')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
          g.hidden = !any;
        });
      });
      find.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
    btn.addEventListener('shown.bs.dropdown', () => { before = state(); if (find) find.focus(); });
    if (box.hasAttribute('data-multi-submit')) {
      btn.addEventListener('hidden.bs.dropdown', () => { if (state() !== before && box.closest('form')) box.closest('form').submit(); });
    }
    label();
  });

  // Supervisor approval box: by the supervisor's password, or by a one-time approval code
  document.querySelectorAll('[data-approval]').forEach((box) => {
    const show = (way) => {
      box.querySelectorAll('.approval-tab').forEach((b) => b.classList.toggle('is-on', b.dataset.way === way));
      box.querySelectorAll('[data-way-panel]').forEach((p) => { p.hidden = p.dataset.wayPanel !== way; p.querySelectorAll('input').forEach((i) => { if (p.hidden) i.value = ''; }); });
    };
    box.querySelectorAll('.approval-tab').forEach((b) => b.addEventListener('click', () => show(b.dataset.way)));
    const code = box.querySelector('.approval-code');
    if (code) code.addEventListener('input', () => {
      const v = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
      code.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4) : v;
    });
  });

  // Incremental search: as the user types in an input with data-live-filter="<selector of the rows>", the rows
  // whose text does not contain every typed word are hidden at once, with no reload. Enter still sends the form
  // (a barcode scan, or a search the server should run). The matching count is shown in [data-live-count].
  document.querySelectorAll('input[data-live-filter]').forEach((input) => {
    const count = document.querySelector(input.dataset.liveCount || '#__none');
    const empty = document.querySelector(input.dataset.liveEmpty || '#__none');
    const rows = [...document.querySelectorAll(input.dataset.liveFilter)].filter((r) => r !== empty);
    if (!rows.length) return;
    const texts = rows.map((r) => (r.dataset.filterText || r.textContent).toLowerCase().replace(/\s+/g, ' '));
    const apply = () => {
      const words = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
      let shown = 0;
      // a class, not the hidden attribute: Bootstrap's d-flex (display: flex !important) would win over hidden
      rows.forEach((r, i) => { const hit = words.every((w) => texts[i].includes(w)); r.classList.toggle('live-hide', !hit); if (hit) shown++; });
      if (count) count.textContent = words.length ? shown + ' of ' + rows.length : rows.length + '';
      if (empty) empty.classList.toggle('live-hide', shown > 0);
      const first = rows.find((r) => !r.classList.contains('live-hide'));
      rows.forEach((r) => r.classList.remove('live-first'));
      if (first && words.length) first.classList.add('live-first');
    };
    if (empty) empty.classList.add('live-hide');
    input.addEventListener('input', apply);
    // Enter opens the first match instead of reloading the page, unless nothing matched (then the server searches)
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const first = rows.find((r) => !r.classList.contains('live-hide'));
      const link = first && input.value.trim() ? (first.matches('a[href]') ? first : first.querySelector('a[href]')) : null;
      if (link && !input.hasAttribute('data-live-enter-submits')) { e.preventDefault(); window.location.href = link.href; }
    });
    if (input.value) apply();
  });

  // Sum of inputs matching a selector into a target
  document.querySelectorAll('[data-sum-into]').forEach((container) => {
    const target = document.querySelector(container.dataset.sumInto);
    const sel = container.dataset.sumSelector || 'input[data-sum]';
    const update = () => {
      let s = 0;
      container.querySelectorAll(sel).forEach((i) => { s += num(i.value); });
      if (target) target.textContent = fmt(s);
      document.dispatchEvent(new CustomEvent('sum:update', { detail: { id: container.id, sum: s } }));
    };
    container.addEventListener('input', update);
    container.addEventListener('change', update);
    update();
  });

  // Checkbox that fills an amount input with its data-amount (payments, deposits, bills)
  document.querySelectorAll('input[type=checkbox][data-fill]').forEach((cb) => {
    cb.addEventListener('change', () => {
      const target = document.querySelector(cb.dataset.fill);
      if (!target) return;
      target.value = cb.checked ? num(cb.dataset.amount).toFixed(2) : '';
      target.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // toggle sections by select value: data-toggle-by="#selectId" data-show-when="a,b"
  document.querySelectorAll('[data-toggle-by]').forEach((el) => {
    const src = document.querySelector(el.dataset.toggleBy);
    if (!src) return;
    const upd = () => {
      const vals = el.dataset.showWhen.split(',');
      const v = src.type === 'checkbox' ? (src.checked ? '1' : '0') : src.value;
      el.style.display = vals.indexOf(v) >= 0 ? '' : 'none';
    };
    src.addEventListener('change', upd);
    upd();
  });

  // the usual site of a line of business: picking the unit on a form moves the form's inventory site to it
  document.querySelectorAll('select[name="business_unit_id"][data-unit-sites]').forEach((unit) => {
    let map = {};
    try { map = JSON.parse(unit.dataset.unitSites || '{}'); } catch (e) { return; }
    unit.addEventListener('change', () => {
      const form = unit.closest('form');
      const box = form && form.querySelector('select[name="site_id"]');
      const site = map[unit.value] ? String(map[unit.value]) : '';
      if (!box || site === '' || box.value === site || !Array.prototype.some.call(box.options, (o) => o.value === site)) return;
      box.value = site;
      if (box.comboSync) box.comboSync();
      box.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  // Tree toggles for page access
  document.querySelectorAll('[data-module-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const mod = btn.dataset.moduleToggle;
      const val = btn.dataset.value;
      document.querySelectorAll('select[data-module="' + mod + '"]').forEach((s) => { s.value = val; s.dispatchEvent(new Event('change', { bubbles: true })); });
      document.querySelectorAll('input[type=checkbox][data-module="' + mod + '"][data-kind="' + (btn.dataset.kind || 'allowed') + '"]').forEach((c) => { c.checked = val === '1'; });
    });
  });
})();

// v36 Expenses tab: the Customer / Supplier box. Only a cost for a customer can be billed, so Billable is
// cleared and switched off while a supplier is chosen on the line.
(function () {
  document.addEventListener('change', (e) => {
    const sel = e.target;
    if (!sel.matches || !sel.matches('select[data-f="exp_name"]')) return;
    const tr = sel.closest('tr');
    const bill = tr && tr.querySelector('input[data-f="exp_billable"]');
    if (!bill) return;
    const supplier = sel.value.charAt(0) === 'v';
    if (supplier) bill.checked = false;
    bill.disabled = supplier;
    bill.title = supplier ? 'Only a cost for a customer can be billed' : '';
  });
})();

// v38 Cash Count Sheet (Shifts, Cash Drops & Readings): clicking the "Cash counted" box (or its button) opens the
// sheet of bills and coins; each line's amount and the total cash follow the quantities typed, and "Use this count"
// puts the total in the box. See app/CashCount.php.
(function () {
  const sheets = document.querySelectorAll('.cc-modal');
  if (!sheets.length || !window.bootstrap) return;
  const money = (v) => v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  sheets.forEach((m) => {
    const target = document.querySelector(m.dataset.ccTarget);
    const used = m.querySelector('[data-cc-used]');
    const modal = new bootstrap.Modal(m);
    const total = () => {
      let t = 0;
      m.querySelectorAll('input[data-cc-den]').forEach((i) => {
        const q = parseInt(String(i.value).replace(/[^0-9]/g, ''), 10) || 0;
        const amt = Math.round(q * parseFloat(i.dataset.ccDen) * 100) / 100;
        i.closest('tr').querySelector('[data-cc-amt]').textContent = money(amt);
        i.closest('tr').classList.toggle('has-qty', q > 0);
        t += amt;
      });
      t = Math.round(t * 100) / 100;
      m.querySelector('[data-cc-total]').textContent = money(t);
      return t;
    };
    m.addEventListener('input', (e) => { if (e.target.matches('input[data-cc-den]')) { e.target.value = e.target.value.replace(/[^0-9]/g, ''); total(); } });
    m.addEventListener('keydown', (e) => {
      // Enter moves down the sheet instead of sending the form
      if (e.key === 'Enter' && e.target.matches('input[data-cc-den]')) {
        e.preventDefault();
        const all = Array.from(m.querySelectorAll('input[data-cc-den]'));
        const next = all[all.indexOf(e.target) + 1];
        if (next) next.focus(); else m.querySelector('[data-cc-use]').focus();
      }
    });
    m.querySelector('[data-cc-clear]').addEventListener('click', () => { m.querySelectorAll('input[data-cc-den]').forEach((i) => { i.value = ''; }); total(); });
    m.querySelector('[data-cc-use]').addEventListener('click', () => {
      const t = total();
      if (target) {
        target.value = t.toFixed(2);
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (used) used.value = '1';
      modal.hide();
    });
    m.addEventListener('shown.bs.modal', () => { const first = m.querySelector('input[data-cc-den]'); if (first) first.focus(); });
    const sel = '#' + m.id;
    document.querySelectorAll('[data-cash-count="' + sel + '"]').forEach((el) => {
      const open = (e) => { if (e) e.preventDefault(); total(); modal.show(); };
      el.addEventListener('click', open);
      if (el.tagName === 'INPUT' && el.readOnly) el.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') open(e); });
    });
    total();
  });
})();
