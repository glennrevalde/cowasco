/* =====================================================================
   Incremental searching on the Water Billing pages (version 46).

   The list follows every letter typed, with no button to press:

   - <input data-live-search> in a GET filter form: the page asks the server again a moment after the typing stops
     (and at once when a filter list of the form changes) and puts the new lists in place of the old ones: every
     element of the page with data-live-results and an id takes the content of the element with the same id in the
     answer. The address bar follows (a reload or a bookmark shows the same list). The Apply / Filter button still
     works, and works without script.
   - <input data-inc-table="#tbodySelector">: the rows of a table already on the page narrow as one types (every
     word typed must be found in the row, in any order). An element named by data-inc-count="#selector" shows
     "N of M".
   - <input data-inc-list="#dropdownSelector" data-inc-item=".itemSelector">: the results a page shows under a search
     box can be gone through with the arrow keys; Enter takes the highlighted one (the first one when none is).

   The searchable drop-down lists (member pickers and the other long lists) narrow the same way: see
   navbar_components.php (window.DsIncremental).
   ===================================================================== */
(function () {
  'use strict';
  if (window.IncSearch) return;

  const fold = (t) => String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const words = (q) => fold(q).split(/[\s,;]+/).filter(Boolean);
  const matches = (text, ws) => { const t = fold(text); return ws.every((w) => t.indexOf(w) >= 0); };

  // ------------------------------------------------------------------ live search of a GET filter form
  const LIVE_DELAY = 320;
  function bindLive(input) {
    const form = input.form;
    if (!form || form.dataset.incLive === '1') return;
    form.dataset.incLive = '1';
    let timer = null;
    let ctrl = null;
    let last = '';

    const regions = () => Array.from(document.querySelectorAll('[data-live-results][id]'));
    const urlOf = () => {
      const params = new URLSearchParams(new FormData(form));
      params.delete('page');
      Array.from(params.keys()).forEach((k) => { if (params.get(k) === '') params.delete(k); });
      const action = form.getAttribute('action') || window.location.pathname;
      const base = new URL(action, window.location.href);
      base.search = params.toString();
      return base.toString();
    };
    const run = () => {
      const url = urlOf();
      if (url === last) return;
      last = url;
      if (ctrl) ctrl.abort();
      ctrl = typeof AbortController === 'function' ? new AbortController() : null;
      regions().forEach((r) => r.setAttribute('aria-busy', 'true'));
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, signal: ctrl ? ctrl.signal : undefined })
        .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then((html) => {
          const doc = new DOMParser().parseFromString(html, 'text/html');
          regions().forEach((r) => {
            const fresh = doc.getElementById(r.id);
            if (fresh) r.innerHTML = fresh.innerHTML;
            r.removeAttribute('aria-busy');
          });
          try { window.history.replaceState(null, '', url); } catch (e) { /* the address stays */ }
          document.dispatchEvent(new CustomEvent('inc:live-updated', { detail: { url } }));
          // the page measures its new lists again (scroll buttons, sticky headers)
          window.dispatchEvent(new Event('resize'));
        })
        .catch((err) => {
          if (err && err.name === 'AbortError') return;
          regions().forEach((r) => r.removeAttribute('aria-busy'));
          last = '';
        });
    };
    const soon = () => { clearTimeout(timer); timer = setTimeout(run, LIVE_DELAY); };
    form.querySelectorAll('[data-live-search]').forEach((box) => {
      box.setAttribute('autocomplete', 'off');
      box.addEventListener('input', soon);
      box.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); last = ''; run(); } });
    });
    // the other filters of the form take effect when changed
    form.querySelectorAll('select, input[type="date"], input[type="checkbox"], input[type="radio"]').forEach((f) => {
      if (f.hasAttribute('data-no-live')) return;
      f.addEventListener('change', () => { clearTimeout(timer); run(); });
    });
    last = urlOf();
  }

  // ------------------------------------------------------------------ narrowing the rows of a table on the page
  function bindTable(input) {
    if (input.dataset.incBound === '1') return;
    input.dataset.incBound = '1';
    input.setAttribute('autocomplete', 'off');
    const filter = () => {
      const body = document.querySelector(input.dataset.incTable);
      if (!body) return;
      const ws = words(input.value);
      const rows = Array.from(body.querySelectorAll(':scope > tr')).filter((tr) => !tr.hasAttribute('data-inc-empty'));
      let shown = 0;
      rows.forEach((tr) => {
        const ok = !ws.length || matches(tr.textContent + ' ' + (tr.dataset.search || ''), ws);
        tr.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      let empty = body.querySelector(':scope > tr[data-inc-empty]');
      if (!shown && rows.length && ws.length) {
        if (!empty) {
          empty = document.createElement('tr');
          empty.setAttribute('data-inc-empty', '');
          const td = document.createElement('td');
          td.colSpan = 99;
          td.style.cssText = 'padding:22px;text-align:center;color:#6b7280;font-size:.82rem';
          empty.appendChild(td);
          body.appendChild(empty);
        }
        empty.firstChild.textContent = 'Nothing matches "' + input.value.trim() + '".';
        empty.style.display = '';
      } else if (empty) {
        empty.style.display = 'none';
      }
      const count = input.dataset.incCount ? document.querySelector(input.dataset.incCount) : null;
      if (count) count.textContent = ws.length ? shown + ' of ' + rows.length : rows.length + ' shown';
    };
    input.addEventListener('input', filter);
    input.addEventListener('search', filter);
    input._incFilter = filter;
    if (input.value) filter();
  }

  // ------------------------------------------------------------------ arrow keys through search results
  function bindList(input) {
    if (input.dataset.incListBound === '1') return;
    input.dataset.incListBound = '1';
    const itemSel = input.dataset.incItem || '.search-result';
    const items = () => {
      const box = document.querySelector(input.dataset.incList);
      return box ? Array.from(box.querySelectorAll(itemSel)).filter((el) => el.offsetParent !== null) : [];
    };
    const paint = (list, i) => {
      list.forEach((el, n) => { el.classList.toggle('inc-active', n === i); el.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
      if (list[i]) list[i].scrollIntoView({ block: 'nearest' });
    };
    // the results shown belong to an earlier query until the page draws the answer to the letters typed now: Enter
    // waits for it (the pages drop a late answer to an older query), so it never takes an account from old results
    let stale = false;
    const listBox = document.querySelector(input.dataset.incList);
    if (listBox && window.MutationObserver) {
      new MutationObserver(() => { stale = false; }).observe(listBox, { childList: true, subtree: true });
    }
    input.addEventListener('keydown', (e) => {
      if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) return;
      const list = items();
      if (!list.length) {
        if (e.key === 'Enter') e.preventDefault();
        return;
      }
      if (stale) {
        e.preventDefault();
        return;
      }
      let i = list.findIndex((el) => el.classList.contains('inc-active'));
      if (e.key === 'Enter') {
        e.preventDefault();
        (list[i >= 0 ? i : 0]).click();
        return;
      }
      e.preventDefault();
      i = e.key === 'ArrowDown' ? Math.min(list.length - 1, i + 1) : Math.max(0, i - 1);
      paint(list, i);
    });
    input.addEventListener('input', () => {
      stale = true;
      items().forEach((el) => el.classList.remove('inc-active'));
    });
  }

  function init(root) {
    const r = root || document;
    r.querySelectorAll('input[data-live-search]').forEach(bindLive);
    r.querySelectorAll('input[data-inc-table]').forEach(bindTable);
    r.querySelectorAll('input[data-inc-list]').forEach(bindList);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init()); else init();

  const style = document.createElement('style');
  style.textContent = '[data-live-results][aria-busy="true"]{opacity:.55;transition:opacity .15s}'
    + '.inc-active{background:#eef2ff !important;box-shadow:inset 0 0 0 1.5px rgba(26,26,46,.22)}';
  (document.head || document.documentElement).appendChild(style);

  window.IncSearch = { fold, words, matches, init };
})();
