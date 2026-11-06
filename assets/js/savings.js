/* =====================================================================
   Savings & Deposits screens (Savings Account Center, Savings Transaction Center, Savings Account Transaction,
   Time Deposit Center): toolbar actions on the selected row, the right-click menu of a row, search as you type,
   and the savings transaction form (transaction codes, error correct, cheques, back entry, validation and
   passbook prompts after saving). No framework; works with the markup of the PHP pages.
   ===================================================================== */
(function () {
  'use strict';

  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  // ------------------------------------------------------------------ search as you type
  // <form data-live-search data-live-target="#rows"> : every change of its fields reloads the rows (the same page with
  // partial=1 answers only the rows); the address bar keeps the search so Back and Refresh show the same list.
  $$('form[data-live-search]').forEach((form) => {
    const target = $(form.dataset.liveTarget);
    if (!target) return;
    let seq = 0;
    const run = () => {
      const params = new URLSearchParams(new FormData(form));
      const url = (form.getAttribute('action') || window.location.pathname).split('?')[0] + '?' + params.toString();
      const mine = ++seq;
      form.classList.add('is-loading');
      fetch(url + '&partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then((r) => r.ok ? r.text() : Promise.reject(r.status))
        .then((html) => {
          if (mine !== seq) return;
          target.innerHTML = html;
          window.history.replaceState(null, '', url);
          $$('[data-print-link]', form).forEach((a) => { a.href = url + '&a=print'; });
          document.dispatchEvent(new CustomEvent('sv:rows', { detail: { target } }));
        })
        .catch(() => {})
        .finally(() => { if (mine === seq) form.classList.remove('is-loading'); });
    };
    const later = debounce(run, 220);
    form.addEventListener('input', (e) => { if (e.target.matches('input[type=search], input[type=text]')) later(); });
    form.addEventListener('change', (e) => { if (!e.target.matches('input[type=search], input[type=text]')) run(); });
    form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  });

  // ------------------------------------------------------------------ selectable rows and the toolbar
  // <table data-select-grid="#toolbar"> rows <tr data-row data-id=.. data-*>; toolbar items [data-need-row] act on
  // the selected row: data-href="...{id}..." opens a page, data-modal="#id" opens a dialog whose [data-row-field="x"]
  // inputs get the row's data-x, data-when="status:active,dormant" enables the item for those rows only.
  function rowData(tr) { return tr ? Object.assign({}, tr.dataset) : null; }

  function fill(template, data) {
    return template.replace(/\{(\w+)\}/g, (m, k) => (data && data[k] !== undefined ? encodeURIComponent(data[k]) : ''));
  }

  function allowed(item, data) {
    const when = item.dataset.when;
    if (!data) return !item.hasAttribute('data-need-row');
    if (!when) return true;
    return when.split(';').every((cond) => {
      const [k, vals] = cond.split(':');
      const neg = k.startsWith('!');
      const key = neg ? k.slice(1) : k;
      const hit = (vals || '').split(',').includes(String(data[key] || ''));
      return neg ? !hit : hit;
    });
  }

  // A dialog whose content is the selected row's (the Loan Accounts Center's ledgers): [data-row-load="url {id}"] is
  // fetched when the dialog opens and again when one of its [data-load-param] fields changes (a view, a date); its
  // [data-load-print] links print the same content (print=1).
  function loadUrl(m) {
    const box = $('[data-row-load]', m);
    if (!box || !box.dataset.loadUrl) return '';
    const q = new URLSearchParams();
    $$('[data-load-param]', m).forEach((f) => {
      if ((f.type === 'radio' || f.type === 'checkbox') && !f.checked) return;
      q.set(f.dataset.loadParam, f.value);
    });
    const qs = q.toString();
    return box.dataset.loadUrl + (qs ? '&' + qs : '');
  }
  function loadInto(m) {
    const box = $('[data-row-load]', m);
    const url = loadUrl(m);
    if (!box || !url) return;
    box.setAttribute('aria-busy', 'true');
    $$('[data-load-print]', m).forEach((a) => { a.href = url + '&print=1'; });
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
      .then((html) => { if (loadUrl(m) === url) box.innerHTML = html; })
      .catch(() => { box.innerHTML = '<div class="text-danger p-3">The form could not be loaded. Close it and try again.</div>'; })
      .finally(() => box.removeAttribute('aria-busy'));
  }
  document.addEventListener('change', (e) => {
    const f = e.target.closest('[data-load-param]');
    const m = f ? f.closest('.modal') : null;
    if (m) loadInto(m);
  });

  function act(item, data) {
    if (!allowed(item, data)) return;
    if (item.dataset.modal) {
      const m = $(item.dataset.modal);
      if (!m) return;
      $$('[data-row-field]', m).forEach((f) => {
        const v = data ? (data[f.dataset.rowField] || '') : '';
        if (f.matches('input, select, textarea')) f.value = v; else f.textContent = v;
      });
      $$('[data-row-href]', m).forEach((a) => { a.href = fill(a.dataset.rowHref, data); });
      $$('[data-row-load]', m).forEach((box) => {
        const url = fill(box.dataset.rowLoad, data);
        if (box.dataset.loadUrl !== url) box.innerHTML = '<div class="la-loading">Loading...</div>';
        box.dataset.loadUrl = url;
      });
      if ($('[data-row-load]', m)) loadInto(m);
      window.bootstrap.Modal.getOrCreateInstance(m).show();
      return;
    }
    if (item.dataset.href) {
      const url = fill(item.dataset.href, data);
      if (item.dataset.target === '_blank') window.open(url, '_blank', 'noopener'); else window.location.href = url;
    }
  }

  // The rows of a center are replaced by the search (innerHTML), so the grid is set up again on its new table; the
  // toolbar and the menu are set up once and act on the row selected in whichever table is there now.
  const bars = new WeakMap();      // toolbar element -> { selected }
  function state(bar) { if (!bars.has(bar)) bars.set(bar, { selected: null }); return bars.get(bar); }

  function setupGrid(table) {
    if (table.dataset.svReady === '1') return;
    table.dataset.svReady = '1';
    const bar = $(table.dataset.selectGrid);
    const menu = table.dataset.contextMenu ? $(table.dataset.contextMenu) : null;
    const st = bar ? state(bar) : { selected: null };
    const refresh = () => {
      const data = rowData(st.selected && st.selected.isConnected ? st.selected : null);
      if (bar) $$('[data-need-row]', bar).forEach((b) => { const ok = allowed(b, data); b.disabled = !ok; b.classList.toggle('disabled', !ok); });
    };
    const select = (tr) => {
      if (st.selected) { st.selected.classList.remove('is-selected'); st.selected.setAttribute('aria-selected', 'false'); }
      st.selected = tr || null;
      if (st.selected) { st.selected.classList.add('is-selected'); st.selected.setAttribute('aria-selected', 'true'); }
      if (menu) menu._svSelect = st;
      refresh();
    };
    const showMenu = (tr, x, y) => {
      if (!menu) return;
      select(tr);
      const data = rowData(tr);
      $$('[data-action]', menu).forEach((i) => { const ok = allowed(i, data); i.disabled = !ok; i.classList.toggle('disabled', !ok); i.setAttribute('aria-disabled', ok ? 'false' : 'true'); });
      const head = $('[data-menu-title]', menu);
      if (head) head.textContent = data.title || '';
      menu.hidden = false;
      const w = menu.offsetWidth, h = menu.offsetHeight;
      menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + 'px';
      menu.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
      const first = $('[data-action]:not(:disabled)', menu);
      if (first) first.focus({ preventScroll: true });
    };
    const def = () => (bar ? $('[data-grid-default]', bar) : null);
    table.addEventListener('click', (e) => {
      const tr = e.target.closest('tr[data-row]');
      if (!tr) return;
      const more = e.target.closest('[data-row-menu]');
      if (more) { e.preventDefault(); const r = more.getBoundingClientRect(); showMenu(tr, r.left, r.bottom + 4); return; }
      if (e.target.closest('a, button, input, select, label')) return;
      select(tr);
    });
    table.addEventListener('dblclick', (e) => {
      const tr = e.target.closest('tr[data-row]');
      if (!tr || e.target.closest('a, button, input, select')) return;
      if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (x) { /* nothing selected */ } }
      select(tr);
      if (def()) act(def(), rowData(tr));
    });
    table.addEventListener('keydown', (e) => {
      const tr = e.target.closest('tr[data-row]');
      if (!tr) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const rows = $$('tr[data-row]', table);
        const next = rows[rows.indexOf(tr) + (e.key === 'ArrowDown' ? 1 : -1)];
        if (next) { next.focus(); select(next); }
      } else if (e.key === 'Enter') {
        if (def()) act(def(), rowData(tr));
      } else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
        e.preventDefault(); const r = tr.getBoundingClientRect(); showMenu(tr, r.left + 40, r.bottom);
      } else if (e.key === ' ') { e.preventDefault(); select(tr); }
    });
    table.addEventListener('focusin', (e) => { const tr = e.target.closest('tr[data-row]'); if (tr && tr === e.target) select(tr); });
    if (menu) {
      table.addEventListener('contextmenu', (e) => {
        const tr = e.target.closest('tr[data-row]');
        if (!tr) return;
        e.preventDefault();
        showMenu(tr, e.clientX, e.clientY);
      });
      // a long press on a phone opens the same menu
      let timer = null;
      table.addEventListener('touchstart', (e) => {
        const tr = e.target.closest('tr[data-row]');
        if (!tr) return;
        const t = e.touches[0];
        timer = setTimeout(() => { timer = null; showMenu(tr, t.clientX, t.clientY); }, 550);
      }, { passive: true });
      ['touchend', 'touchmove', 'touchcancel'].forEach((ev) => table.addEventListener(ev, () => { if (timer) { clearTimeout(timer); timer = null; } }, { passive: true }));
    }
    select($('tr[data-row].is-selected', table));
  }

  // the toolbar and the menu: once
  $$('table[data-select-grid]').forEach((table) => {
    const bar = $(table.dataset.selectGrid);
    const menu = table.dataset.contextMenu ? $(table.dataset.contextMenu) : null;
    if (bar && !bar.dataset.svReady) {
      bar.dataset.svReady = '1';
      bar.addEventListener('click', (e) => {
        const item = e.target.closest('[data-need-row], [data-modal], [data-href]');
        if (!item || item.disabled) return;
        if (item.dataset.href || item.dataset.modal) {
          e.preventDefault();
          const sel = state(bar).selected;
          act(item, rowData(sel && sel.isConnected ? sel : null));
        }
      });
    }
    if (menu && !menu.dataset.svReady) {
      menu.dataset.svReady = '1';
      const hide = () => { menu.hidden = true; };
      menu.addEventListener('click', (e) => {
        const item = e.target.closest('[data-action]');
        if (!item || item.disabled) return;
        hide();
        const sel = bar ? state(bar).selected : null;
        act(item, rowData(sel));
      });
      menu.addEventListener('keydown', (e) => {
        const items = $$('[data-action]:not(:disabled)', menu);
        const i = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
        else if (e.key === 'Escape') { hide(); const sel = bar ? state(bar).selected : null; if (sel) sel.focus(); }
      });
      document.addEventListener('click', (e) => { if (!menu.contains(e.target) && !e.target.closest('[data-row-menu]')) hide(); });
      window.addEventListener('scroll', hide, { passive: true });
      window.addEventListener('resize', hide);
    }
    setupGrid(table);
  });
  // new rows from the search: nothing selected, the toolbar waits for a row
  document.addEventListener('sv:rows', (e) => {
    $$('table[data-select-grid]', e.detail.target).forEach((t) => {
      const bar = $(t.dataset.selectGrid);
      if (bar) state(bar).selected = null;
      setupGrid(t);
    });
  });

  // a dialog that comes back to the list after saving returns to the list as it is now (search and filter)
  document.addEventListener('submit', (e) => {
    const back = e.target.querySelector && e.target.querySelector('input[name="back"]');
    if (back) back.value = window.location.pathname + window.location.search;
  }, true);

  // ------------------------------------------------------------------ "Show transactions": Today / Custom
  $$('[data-show-range]').forEach((sel) => {
    const box = $(sel.dataset.showRange);
    const sync = () => { if (box) box.hidden = sel.value !== 'custom'; };
    sel.addEventListener('change', sync);
    sync();
  });

  // ------------------------------------------------------------------ the status bar clock
  $$('[data-clock]').forEach((el) => {
    const tick = () => { const d = new Date(); el.textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
    tick();
    setInterval(tick, 15000);
  });

  // ------------------------------------------------------------------ the Savings Account Transaction form
  const form = $('#svForm');
  if (form) {
    const codes = JSON.parse(form.dataset.codes || '{}');
    const typeSel = $('#svCode', form);
    const ecBox = $('#svEc', form);
    const ecPick = $('#svCorrects', form);
    const ecWrap = $('#svEcWrap', form);
    const glWrap = $('#svGlWrap', form);
    const chq = $('#svCheques', form);
    const back = $('#svBack', form);
    const date = $('#svDate', form);
    const amount = $('#svAmount', form);
    const today = form.dataset.today;
    // the total of the cheques; for a check deposit it is the amount of the deposit
    const chequeTotal = () => {
      if (!chq) return;
      let sum = 0;
      $$('input[data-chq-amount]', chq).forEach((i) => { const v = parseFloat(String(i.value).replace(/,/g, '')); if (!isNaN(v)) sum += v; });
      const txt = sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const out = $('[data-chq-total]', form); if (out) out.textContent = txt;
      if (amount && amount.dataset.fromCheques === '1') {
        amount.value = sum > 0 ? txt : '';
        amount.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };
    const blank = typeSel ? Array.from(typeSel.options).find((o) => !o.value) : null;
    const blankText = blank ? blank.textContent : '';
    const allOptions = typeSel ? Array.from(typeSel.options).filter((o) => o.value).map((o) => ({ value: o.value, text: o.textContent, ec: o.dataset.ec === '1' })) : [];

    const syncCodeList = () => {
      if (!typeSel) return;
      const ec = ecBox && ecBox.checked;
      const keep = typeSel.value;
      typeSel.innerHTML = '';
      if (blank) { const b = document.createElement('option'); b.value = ''; b.textContent = blankText; typeSel.appendChild(b); }
      allOptions.filter((o) => o.ec === ec).forEach((o) => {
        const op = document.createElement('option');
        op.value = o.value; op.textContent = o.text; if (o.ec) op.dataset.ec = '1';
        typeSel.appendChild(op);
      });
      typeSel.value = allOptions.some((o) => o.value === keep && o.ec === ec) ? keep : '';
      if (ecWrap) ecWrap.hidden = !ec;
      if (ecPick) ecPick.required = !!ec;
      syncCode();
    };
    const syncCode = () => {
      const c = typeSel ? codes[typeSel.value] : null;
      const isCheck = !!(c && c.check === 1);
      const isMemo = !!(c && c.memo === 1);
      if (chq) {
        chq.classList.toggle('is-off', !isCheck);
        $$('input', chq).forEach((i) => { i.disabled = !isCheck; });
        const add = $('[data-add-rows]', chq.parentElement); if (add) add.disabled = !isCheck;
      }
      // a check deposit: the amount is the total of the cheques, filled in as they are typed
      if (amount) {
        const wasCheck = amount.dataset.fromCheques === '1';
        amount.readOnly = isCheck;
        amount.dataset.fromCheques = isCheck ? '1' : '';
        amount.title = isCheck ? 'The total of the cheques below' : '';
        if (isCheck) chequeTotal(); else if (wasCheck) { amount.value = ''; }
      }
      if (glWrap) {
        glWrap.hidden = !isMemo;
        const s = $('select', glWrap); if (s) s.required = isMemo && !(ecBox && ecBox.checked);
      }
      const side = $('#svSide', form);
      if (side) side.textContent = c ? (c.ec ? (c.side === 'credit' ? 'Negates a deposit: the balance goes down' : 'Negates a withdrawal: the balance goes up') : (c.side === 'credit' ? 'Deposit: credited to the account' : 'Withdrawal: debited from the account')) + (c.opp ? ', ' + c.opp : '') : '';
      // the transaction to correct: only those of the code it corrects
      if (ecPick) {
        Array.from(ecPick.options).forEach((o) => { if (!o.value) return; o.hidden = !(c && c.related && o.dataset.code === c.related); });
        if (ecPick.selectedOptions[0] && ecPick.selectedOptions[0].hidden) ecPick.value = '';
      }
    };
    if (ecBox) ecBox.addEventListener('change', syncCodeList);
    if (typeSel) typeSel.addEventListener('change', syncCode);
    if (ecPick) ecPick.addEventListener('change', () => {
      const o = ecPick.selectedOptions[0];
      if (o && o.dataset.amount && amount) { amount.value = o.dataset.amount; amount.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    if (back && date) {
      const syncBack = () => {
        date.readOnly = !back.checked;
        if (!back.checked) date.value = today;
        date.closest('.sv-field') && date.closest('.sv-field').classList.toggle('is-locked', !back.checked);
      };
      back.addEventListener('change', syncBack);
      syncBack();
    }
    if (chq) chq.addEventListener('input', chequeTotal);
    if (chq) chq.addEventListener('click', (e) => { if (e.target.closest('[data-del]')) setTimeout(chequeTotal, 0); });
    // Clear: back to an empty transaction on the same account
    // Clear: every field is emptied (the account, the member and savings information, the transaction) for the
    // next transaction; the page opens again without an account
    const clr = $('[data-sv-clear]', form);
    if (clr) clr.addEventListener('click', () => {
      try { form.reset(); } catch (e) { /* nothing */ }
      window.location.href = clr.dataset.clearUrl || window.location.pathname;
    });
    // the two print choices are remembered on this computer
    ['svPrintPb', 'svPreview'].forEach((id) => {
      const cb = $('#' + id);
      if (!cb) return;
      try { const v = window.localStorage.getItem('sv.' + id); if (v !== null && !form.dataset.fromPost) cb.checked = v === '1'; } catch (e) { /* private mode */ }
      cb.addEventListener('change', () => { try { window.localStorage.setItem('sv.' + id, cb.checked ? '1' : '0'); } catch (e) { /* private mode */ } });
    });
    syncCodeList();
  }

  // ------------------------------------------------------------------ after saving: validation, then the passbook
  // <div data-after-save data-validate="#validationModal" data-passbook="#passbookModal">
  $$('[data-after-save]').forEach((box) => {
    const steps = [];
    if (box.dataset.validate) steps.push(box.dataset.validate);
    if (box.dataset.passbook) steps.push(box.dataset.passbook);
    box.dataset.left = String(steps.length);
    const next = () => {
      const sel = steps.shift();
      box.dataset.left = String(steps.length);
      const m = sel ? $(sel) : null;
      if (!m) return;
      m.addEventListener('hidden.bs.modal', next, { once: true });
      window.bootstrap.Modal.getOrCreateInstance(m).show();
    };
    window.addEventListener('load', next);
  });

  // a print button that opens the print window and then asks whether it came out right
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-print-then]');
    if (!btn) return;
    // the print window keeps this page as its opener: it says when it has printed, then closes itself
    window.open(btn.dataset.url, '_blank');
    const then = btn.dataset.printThen ? $(btn.dataset.printThen) : null;
    if (then) {
      then.hidden = false;
      then.classList.remove('is-ready');
      const b = $('[data-pb-yes]', then) || $('button, a', then);
      if (b) b.focus();
    }
  });
  // "No, print again later" outside a dialog (Passbook Printing): the question is put away
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-pb-later]');
    if (!btn || btn.closest('.modal')) return;
    const c = btn.closest('.pb-run-confirm');
    if (c) c.hidden = true;
  });

  // what a print window says once it has printed (it closes itself after)
  window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin || !e.data || typeof e.data !== 'object') return;
    if (e.data.coop === 'passbook-printed') {
      // the question "did every line print right?" is waiting: it is brought forward
      const c = $$('.pb-run-confirm').find((x) => !x.hidden);
      if (c) {
        c.classList.add('is-ready');
        const b = $('[data-pb-yes]', c);
        if (b) b.focus();
      }
      return;
    }
    if (e.data.coop === 'validation-printed') {
      // the validation is printed: the form is cleared for the next transaction, unless the passbook printing of the
      // transaction is still to come (its dialog is open or next): the form is cleared once the passbook is done
      const clear = $('[data-clear-after-print]');
      if (!clear) return;
      const after = $('[data-after-save]');
      if ($('.modal.show') || (after && Number(after.dataset.left || 0) > 0)) return;
      window.location.href = clear.dataset.clearAfterPrint;
    }
  });
})();
