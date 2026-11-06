/*
 * Interest on Share Capital / Patronage Refund: the batch under review (modules/coop/dist_batch.php).
 *  - find a member, show only those who owe the cooperative, tick members to remove them together;
 *  - the totals of the members shown while the list is searched;
 *  - the basis of a member: pointed at (mouse), focused (keyboard) or tapped, a window shows how it is worked out
 *    (the share capital balance day by day and its average, or the income / purchases / deliveries document by
 *    document), fetched once from the page (a=basis&line=...) and kept. On a phone it opens as a sheet from the bottom.
 */
(function () {
  'use strict';

  const box = document.querySelector('[data-dist-review]');
  if (!box) return;

  // ------------------------------------------------------------------ amounts (to the centavo, no floating point)
  const cents = (v) => {
    const s = String(v || '0').trim();
    const neg = s.startsWith('-');
    const [i, f = ''] = s.replace('-', '').split('.');
    return (neg ? -1 : 1) * ((parseInt(i, 10) || 0) * 100 + (parseInt((f + '00').slice(0, 2), 10) || 0));
  };
  const money = (c) => {
    const a = Math.abs(c);
    const s = Math.floor(a / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + String(a % 100).padStart(2, '0');
    return c < 0 ? '(' + s + ')' : s;
  };

  // ------------------------------------------------------------------ the review: find, owes only, tick and remove
  const rows = Array.from(box.querySelectorAll('tbody tr[data-text]'));
  const find = box.querySelector('[data-dist-find]');
  const oblOnly = box.querySelector('[data-dist-obl]');
  const none = box.querySelector('tr.dist-none');
  const bulk = document.querySelector('[data-dist-bulk]');
  const all = box.querySelector('[data-dist-all]');
  const shownRow = box.querySelector('[data-dist-shown]');
  const onList = rows.filter((r) => r.dataset.ex === '0').length;
  const lineBoxes = () => rows.filter((r) => !r.hidden).map((r) => r.querySelector('[data-dist-line]')).filter(Boolean);

  const sync = () => {
    const ticked = rows.map((r) => r.querySelector('[data-dist-line]')).filter((c) => c && c.checked);
    if (bulk) {
      bulk.hidden = ticked.length === 0;
      bulk.querySelector('[data-dist-count]').textContent = ticked.length + ' ticked';
    }
    if (all) {
      const vis = lineBoxes();
      all.checked = vis.length > 0 && vis.every((c) => c.checked);
      all.indeterminate = !all.checked && vis.some((c) => c.checked);
    }
  };

  // the totals of the members shown (on the list, not removed), while a search or "owes only" narrows the list
  const shownTotals = (active) => {
    if (!shownRow) return;
    shownRow.hidden = !active;
    if (!active) return;
    const t = [0, 0, 0, 0];
    let n = 0;
    rows.forEach((r) => {
      if (r.hidden || r.dataset.ex !== '0') return;
      n++;
      (r.dataset.sum || '').split('|').forEach((v, i) => { t[i] += cents(v); });
    });
    shownRow.querySelector('[data-k="n"]').textContent = n + ' of the ' + onList + ' member(s) on the list';
    ['b', 'c', 'a', 't'].forEach((k, i) => { shownRow.querySelector('[data-k="' + k + '"]').textContent = money(t[i]); });
  };

  const filter = () => {
    const q = (find ? find.value : '').trim().toLowerCase();
    const only = !!(oblOnly && oblOnly.checked);
    let shown = 0;
    rows.forEach((r) => {
      const on = (q === '' || r.dataset.text.includes(q)) && (!only || r.dataset.obl === '1');
      r.hidden = !on;
      if (!on) {
        // its open Remove / Adjust form goes with it
        let n = r.nextElementSibling;
        while (n && n.classList.contains('dist-form-row')) { n.classList.remove('show'); n = n.nextElementSibling; }
      }
      if (on) shown++;
    });
    if (none) none.hidden = shown > 0 || rows.length === 0;
    shownTotals(q !== '' || only);
    sync();
  };
  if (find) find.addEventListener('input', filter);
  if (oblOnly) oblOnly.addEventListener('change', filter);
  if (all) all.addEventListener('change', () => { lineBoxes().forEach((c) => { c.checked = all.checked; }); sync(); });
  box.addEventListener('change', (e) => { if (e.target.matches('[data-dist-line]')) sync(); });
  sync();

  // ------------------------------------------------------------------ the basis: how it is worked out
  const title = box.getAttribute('data-basis-title') || 'Basis';
  const pop = document.createElement('div');
  pop.className = 'dist-pop';
  pop.id = 'distPop';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-labelledby', 'distPopT');
  pop.hidden = true;
  pop.innerHTML = '<div class="dist-pop-grip" aria-hidden="true"></div>'
    + '<div class="dist-pop-head"><div class="dist-pop-title" id="distPopT"></div>'
    + '<button type="button" class="btn-close" aria-label="Close"></button></div>'
    + '<div class="dist-pop-body" tabindex="-1"></div>';
  const shade = document.createElement('div');
  shade.className = 'dist-pop-shade';
  shade.hidden = true;
  document.body.appendChild(shade);
  document.body.appendChild(pop);
  const popTitle = pop.querySelector('.dist-pop-title');
  const popBody = pop.querySelector('.dist-pop-body');
  const closeBtn = pop.querySelector('.btn-close');

  const cache = new Map();
  const hoverable = window.matchMedia('(hover: hover) and (pointer: fine)');
  const narrow = window.matchMedia('(max-width: 575.98px)');
  let current = null;      // the basis button the window belongs to
  let pinned = false;      // opened by a click / tap / Enter: stays until closed
  let openTimer = 0;
  let closeTimer = 0;
  let seq = 0;
  let quiet = false;       // focus given back to the amount on closing: it does not open the window again

  const isSheet = () => narrow.matches;

  const place = () => {
    if (!current || pop.hidden) return;
    if (isSheet()) {
      pop.classList.add('is-sheet');
      pop.style.left = pop.style.top = pop.style.width = pop.style.maxHeight = '';
      return;
    }
    pop.classList.remove('is-sheet');
    const r = current.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    const w = Math.min(500, vw - 24);
    pop.style.width = w + 'px';
    // right edges together (the amounts are right-aligned), kept inside the window
    let left = Math.round(r.right - w);
    left = Math.max(12, Math.min(left, vw - w - 12));
    // the window stays under the top bar of the program
    const bar = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--bar-h'), 10) || 56;
    const below = vh - r.bottom - 14;
    const above = r.top - bar - 14;
    pop.style.maxHeight = '';
    const natural = pop.offsetHeight;
    const up = natural > below && above > below;
    const room = Math.max(160, (up ? above : below) - 8);
    pop.style.maxHeight = Math.min(room, Math.round(vh * 0.78)) + 'px';
    const h = pop.offsetHeight;
    const top = up ? r.top - h - 10 : r.bottom + 10;
    pop.style.left = left + 'px';
    pop.style.top = Math.max(bar + 8, Math.round(top)) + 'px';
    pop.classList.toggle('is-up', up);
    // the little arrow points at the amount
    const caret = Math.max(18, Math.min(w - 18, r.left + r.width / 2 - left));
    pop.style.setProperty('--caret-x', caret + 'px');
  };

  const show = (html) => {
    popBody.innerHTML = html;
    place();
  };

  const load = (btn) => {
    const url = btn.getAttribute('data-dist-basis');
    if (cache.has(url)) { show(cache.get(url)); return; }
    const mine = ++seq;
    show('<div class="dist-pop-wait"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Working it out&hellip;</div>');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then((r) => r.text().then((t) => ({ ok: r.ok || r.status === 404, t })))
      .then(({ ok, t }) => {
        if (!ok || t.indexOf('data-basis-ok') === -1) throw new Error('bad');
        cache.set(url, t);
        if (mine === seq && current === btn) show(t);
      })
      .catch(() => {
        if (mine === seq && current === btn) {
          show('<div class="db-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> The details could not be loaded. The session may have ended: reload the page and try again.</div>');
        }
      });
  };

  const open = (btn, pin, intoWindow) => {
    clearTimeout(closeTimer);
    clearTimeout(openTimer);
    if (current && current !== btn) current.setAttribute('aria-expanded', 'false');
    const same = current === btn && !pop.hidden;
    current = btn;
    pinned = pin || (same && pinned);
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-controls', 'distPop');
    popTitle.textContent = title;
    pop.hidden = false;
    shade.hidden = !isSheet();
    document.body.classList.toggle('dist-pop-open', isSheet());
    if (!same) load(btn); else place();
    // opened from the keyboard (or as a sheet): the focus goes into the window, so Tab reaches its links and X
    if (pin && (intoWindow || isSheet())) popBody.focus({ preventScroll: true });
  };

  const close = (refocus) => {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    if (pop.hidden) return;
    pop.hidden = true;
    shade.hidden = true;
    document.body.classList.remove('dist-pop-open');
    const btn = current;
    if (btn) btn.setAttribute('aria-expanded', 'false');
    current = null;
    pinned = false;
    seq++;
    if (refocus && btn) {
      quiet = true;
      btn.focus({ preventScroll: true });
      quiet = false;
    }
  };

  const laterClose = () => {
    if (pinned) return;
    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => { if (!pinned) close(false); }, 240);
  };

  // pointing with a mouse: open after a moment, close when the pointer leaves the amount and the window
  box.addEventListener('pointerover', (e) => {
    const btn = e.target.closest('[data-dist-basis]');
    // pointing opens the small window only; the sheet of a narrow screen opens on a click
    if (!btn || e.pointerType !== 'mouse' || !hoverable.matches || isSheet()) return;
    clearTimeout(closeTimer);
    if (current === btn && !pop.hidden) return;
    if (pinned && current) return;      // a window opened by a click stays until closed
    clearTimeout(openTimer);
    openTimer = setTimeout(() => open(btn, false), current ? 60 : 180);
  });
  box.addEventListener('pointerout', (e) => {
    const btn = e.target.closest('[data-dist-basis]');
    if (!btn || e.pointerType !== 'mouse') return;
    if (e.relatedTarget && btn.contains(e.relatedTarget)) return;
    clearTimeout(openTimer);
    if (current === btn) laterClose();
  });
  pop.addEventListener('pointerenter', () => { clearTimeout(closeTimer); });
  pop.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') laterClose(); });

  // a click, a tap or Enter: open and keep it open; again: close
  box.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-dist-basis]');
    if (!btn) return;
    e.preventDefault();
    if (current === btn && !pop.hidden && pinned) { close(false); return; }
    open(btn, true, e.detail === 0);
  });

  // the keyboard: tabbing onto an amount shows its window; leaving it closes it (unless opened with Enter)
  box.addEventListener('focusin', (e) => {
    const btn = e.target.closest('[data-dist-basis]');
    if (!btn || quiet || isSheet() || !btn.matches(':focus-visible')) return;
    if (current !== btn || pop.hidden) open(btn, false);
  });
  box.addEventListener('focusout', (e) => {
    const btn = e.target.closest('[data-dist-basis]');
    if (!btn || current !== btn || pinned) return;
    if (e.relatedTarget && pop.contains(e.relatedTarget)) return;
    close(false);
  });

  closeBtn.addEventListener('click', () => close(true));
  shade.addEventListener('click', () => close(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !pop.hidden) { e.stopPropagation(); close(true); }
  });
  document.addEventListener('pointerdown', (e) => {
    if (pop.hidden || pop.contains(e.target) || e.target.closest('[data-dist-basis]')) return;
    close(false);
  });
  const follow = () => { if (!pop.hidden) window.requestAnimationFrame(place); };
  window.addEventListener('scroll', follow, { passive: true });
  window.addEventListener('resize', follow);
  if (narrow.addEventListener) narrow.addEventListener('change', () => close(false));
})();
