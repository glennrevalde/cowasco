/* The loan finder (Loans::pickerBox): a search box whose value is a loan no. A click in it lists the borrowers with
   outstanding loans (name, loan product, loan no., balance); each letter typed narrows the list by the borrower's name,
   the loan no. or the loan product (api.coop?a=loans). Choosing one puts its loan no. in the box and, with
   data-submit, sends the form (the Loan Statement of Account opens on it). */
(function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // the part typed, marked in what is shown (every word, at the start of a word or anywhere in a loan no.)
  const mark = (text, words, anywhere) => {
    let out = esc(text);
    words.forEach((w) => {
      if (!w) return;
      const re = new RegExp((anywhere ? '' : '(^|[\\s,(])') + '(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'i');
      out = out.replace(re, anywhere ? '<mark>$1</mark>' : '$1<mark>$2</mark>');
    });
    return out;
  };

  document.querySelectorAll('[data-loan-pick]').forEach((box) => {
    const input = box.querySelector('input[type="search"]');
    const list = box.querySelector('.loan-pick-list');
    let items = [];
    let total = 0;
    let hi = -1;
    let lastQ = null;
    let seq = 0;
    let timer = null;

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
    }
    function render() {
      const words = input.value.trim().split(/[\s,]+/).filter(Boolean);
      if (!items.length) {
        list.innerHTML = '<div class="lp-none">' + (words.length ? 'No borrower with an outstanding loan matches "' + esc(input.value.trim()) + '".' : 'No loan has a balance.') + '</div>';
      } else {
        list.innerHTML = '<div class="lp-head">' + (words.length ? total + ' loan' + (total === 1 ? '' : 's') + ' found' : 'Borrowers with outstanding loans (' + total + ')')
          + (total > items.length ? ', the first ' + items.length + ' shown: type more to narrow' : '') + '</div>'
          + items.map((l, i) => '<div class="lp-opt' + (i === hi ? ' is-hi' : '') + '" role="option" id="' + input.id + '_o' + i + '" aria-selected="' + (i === hi) + '" data-i="' + i + '">'
            + '<div class="lp-main"><div class="lp-name">' + mark(l.name, words, false) + '</div>'
            + '<div class="lp-sub"><span class="lp-no">' + mark(l.loan_no, words, true) + '</span><span>' + mark(l.product, words, false) + '</span>'
            + (l.status !== 'Current' ? '<span class="' + (l.status === 'Past due' ? 'text-danger' : 'text-muted') + '">' + esc(l.status) + '</span>' : '') + '</div></div>'
            + '<div class="lp-bal"><div>' + esc(l.balance) + '</div><div class="lp-bal-k">balance</div></div></div>').join('');
      }
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (hi >= 0) input.setAttribute('aria-activedescendant', input.id + '_o' + hi); else input.removeAttribute('aria-activedescendant');
      const cur = list.querySelector('.lp-opt.is-hi');
      if (cur) cur.scrollIntoView({ block: 'nearest' });
    }
    function search(force) {
      const q = input.value.trim();
      if (!force && q === lastQ) { if (list.hidden) render(); return; }
      lastQ = q;
      const mine = ++seq;
      fetch(box.dataset.api + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } })
        .then((r) => r.json())
        .then((d) => {
          if (mine !== seq) return;   // a later search already answered
          items = d.loans || [];
          total = d.total || items.length;
          hi = items.length ? 0 : -1;
          render();
        })
        .catch(() => {});
    }
    function later() {
      clearTimeout(timer);
      timer = setTimeout(() => search(false), 150);
    }
    function pick(l) {
      input.value = l.loan_no;
      lastQ = null;
      close();
      box.dispatchEvent(new CustomEvent('loan:picked', { bubbles: true, detail: l }));
      const form = box.closest('form');
      if (box.hasAttribute('data-submit') && form) {
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
      }
    }

    input.addEventListener('input', later);
    input.addEventListener('focus', () => search(true));
    input.addEventListener('click', () => { if (list.hidden) search(true); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { search(true); return; } hi = Math.min(items.length - 1, hi + 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); render(); }
      else if (e.key === 'Enter') {
        // a loan no. typed exactly opens it; otherwise the highlighted loan of the list
        const typed = input.value.trim();
        const exact = items.find((l) => l.loan_no === typed);
        if (exact) { e.preventDefault(); pick(exact); }
        else if (!list.hidden && items[hi]) { e.preventDefault(); pick(items[hi]); }
      }
      else if (e.key === 'Escape') { close(); }
    });
    list.addEventListener('mousedown', (e) => {
      const o = e.target.closest('.lp-opt');
      if (o) { e.preventDefault(); pick(items[+o.dataset.i]); }
    });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) close(); });
  });
})();
