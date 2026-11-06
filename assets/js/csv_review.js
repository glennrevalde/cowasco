/*
 * The review of a CSV upload (app/CsvReview.php): every row of the file in a grid, corrected in place or removed,
 * then checked again or uploaded. The rows are sent back as one JSON field, so a file of thousands of rows is never
 * cut short by the server's limit on form fields.
 *
 * The checker: a row with a problem is shown in red with the cells its messages name outlined. When a cell is
 * corrected, the grid is checked again by itself a moment after the typing stops (the same checks as the upload,
 * every row, since a row can depend on the others: the same member twice, the balance left by the rows above), and
 * each row turns red or back to normal with its messages.
 */
(function () {
  'use strict';

  // the file step: the button says it is reading
  document.querySelectorAll('[data-csvr-read]').forEach((f) => {
    f.addEventListener('submit', () => {
      const b = f.querySelector('button.btn-primary');
      if (b) { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Reading the file&hellip;'; }
    });
  });

  const box = document.querySelector('[data-csvr]');
  const form = document.querySelector('[data-csvr-form]');
  if (!box || !form) return;

  const noun = [box.dataset.noun || 'row', box.dataset.nouns || 'rows'];
  const totalKey = box.dataset.total || '';
  const recs = () => Array.from(box.querySelectorAll('tbody[data-csvr-row]'));
  const removed = [];   // groups of rows taken out, each put back together
  const find = box.querySelector('[data-csvr-find]');
  const badOnly = box.querySelector('[data-csvr-badonly]');
  const removeBad = box.querySelector('[data-csvr-removebad]');
  const checking = box.querySelector('[data-csvr-checking]');
  const general = document.querySelector('[data-csvr-general]');
  const none = box.querySelector('tbody.csvr-none');
  const status = document.querySelector('[data-csvr-status]');

  const cents = (v) => {
    const s = String(v || '').replace(/[,\s₱]/g, '').replace(/^\((.*)\)$/, '-$1');
    if (!/^-?\d+(\.\d+)?$/.test(s)) return 0;
    const neg = s.startsWith('-');
    const [i, f = ''] = s.replace('-', '').split('.');
    return (neg ? -1 : 1) * ((parseInt(i, 10) || 0) * 100 + (parseInt((f + '00').slice(0, 2), 10) || 0));
  };
  const money = (c) => {
    const a = Math.abs(c);
    const s = Math.floor(a / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + String(a % 100).padStart(2, '0');
    return c < 0 ? '(' + s + ')' : s;
  };
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const set = (sel, v) => { const el = box.querySelector(sel); if (el) el.textContent = v; };
  const show = (sel, on) => { const el = box.querySelector(sel); if (el) el.hidden = !on; };

  const setStatus = () => {
    if (!status) return;
    const all = recs();
    const bad = all.filter((r) => r.classList.contains('is-bad')).length;
    const edited = all.filter((r) => r.classList.contains('is-edited')).length;
    const gen = general && !general.hidden;
    if (edited) {
      status.innerHTML = '<span class="text-primary"><i class="bi bi-pencil"></i> Rows were changed: they are being checked again.</span>';
    } else if (bad || gen) {
      status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + (bad === 1 ? 'The row in red has a problem: correct it (it is checked again as you type) or remove it.' : bad ? 'The ' + bad + ' rows in red have a problem: correct them (they are checked again as you type) or remove them.' : 'Correct the problem above the rows.') + '</span>';
    } else if (all.length) {
      status.innerHTML = '<span class="text-success"><i class="bi bi-check2-circle"></i> Every row is ready.</span>';
    } else {
      status.innerHTML = '<span class="text-muted">No row is left.</span>';
    }
  };

  const counts = () => {
    const all = recs();
    const n = all.length;
    set('[data-csvr-n]', n);
    set('[data-csvr-noun]', n === 1 ? noun[0] : noun[1]);
    const bad = all.filter((r) => r.classList.contains('is-bad')).length;
    set('[data-csvr-bad]', bad);
    show('[data-csvr-badwrap]', bad > 0);
    const edited = all.filter((r) => r.classList.contains('is-edited')).length;
    set('[data-csvr-edited]', edited);
    show('[data-csvr-editwrap]', edited > 0);
    set('[data-csvr-removed]', removed.reduce((t, g) => t + g.length, 0));
    show('[data-csvr-removedwrap]', removed.length > 0);
    if (totalKey) {
      let t = 0;
      all.forEach((r) => { const i = r.querySelector('[data-k="' + totalKey + '"]'); if (i) t += cents(i.value); });
      set('[data-csvr-total]', money(t));
    }
    const up = document.querySelector('[data-csvr-upn]');
    if (up) up.textContent = n;
    const upn = document.querySelector('[data-csvr-upnoun]');
    if (upn) upn.textContent = n === 1 ? noun[0] : noun[1];
    const upb = document.querySelector('[data-csvr-upload]');
    if (upb) upb.disabled = n === 0;
    if (badOnly) badOnly.disabled = bad === 0;
    if (removeBad) removeBad.hidden = bad === 0;
    setStatus();
  };

  const filter = () => {
    const q = (find ? find.value : '').trim().toLowerCase();
    const only = !!(badOnly && badOnly.checked);
    let shown = 0;
    recs().forEach((r) => {
      const text = Array.from(r.querySelectorAll('[data-k]')).map((i) => i.value).join(' ').toLowerCase() + ' ' + (r.querySelector('.csvr-msg') ? r.querySelector('.csvr-msg').textContent.toLowerCase() : '');
      const on = (q === '' || text.includes(q)) && (!only || r.classList.contains('is-bad'));
      r.hidden = !on;
      if (on) shown++;
    });
    if (none) none.hidden = shown > 0;
  };

  // the rows go back as one JSON field
  const rowsOf = () => recs().map((r) => {
    const o = { _line: parseInt(r.dataset.line, 10) || 0 };
    r.querySelectorAll('[data-k]').forEach((i) => { o[i.dataset.k] = i.value; });
    if (r.dataset.keep) { try { o._keep = JSON.parse(r.dataset.keep); } catch (err) { /* ignore */ } }
    return o;
  });
  const pack = () => { box.querySelector('[data-csvr-json]').value = JSON.stringify(rowsOf()); };

  // ------------------------------------------------------------------ the checker
  let timer = null;
  let ctrl = null;
  let seq = 0;

  const paint = (r, res) => {
    r.classList.remove('is-edited', 'is-checking');
    r.classList.toggle('is-bad', res.bad);
    r.classList.toggle('is-ok', !res.bad);
    const named = new Set(res.named || []);
    r.querySelectorAll('[data-k]').forEach((i) => {
      if (document.activeElement === i && i.classList.contains('is-dirty')) return;   // the cell still being typed in
      const on = named.has(i.dataset.k);
      i.classList.toggle('is-invalid', on);
      if (on) i.setAttribute('aria-invalid', 'true'); else i.removeAttribute('aria-invalid');
      i.classList.remove('is-dirty');
    });
    // the other columns open when a message names one of them
    const moreRow = r.querySelector('.csvr-more');
    if (moreRow && res.bad && moreRow.querySelector('.is-invalid') && moreRow.hidden) {
      moreRow.hidden = false;
      const b = r.querySelector('[data-csvr-more]');
      if (b) { b.classList.add('is-on'); b.setAttribute('aria-expanded', 'true'); }
    }
    let msg = r.querySelector('.csvr-msg');
    const html = (res.info ? '<div class="csvr-info">' + res.info + '</div>' : '')
      + res.errors.map((e) => '<div class="csvr-err"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' + esc(e) + '</div>').join('')
      + (res.notes || []).map((e) => '<div class="csvr-note"><i class="bi bi-info-circle" aria-hidden="true"></i> ' + esc(e) + '</div>').join('');
    if (!html) { if (msg) msg.remove(); return; }
    if (!msg) {
      msg = document.createElement('tr');
      msg.className = 'csvr-msg';
      const cols = r.querySelector('.csvr-main').children.length;
      msg.innerHTML = '<td></td><td colspan="' + (cols - 1) + '"></td>';
      r.appendChild(msg);
    }
    msg.lastElementChild.innerHTML = html;
  };

  // one check at a time: a change made while the server checks waits for that answer, then goes with the next check
  let inflight = false;
  let pending = false;
  const runCheck = () => {
    timer = null;
    if (inflight) { pending = true; return; }
    inflight = true;
    ctrl = typeof AbortController === 'function' ? new AbortController() : null;
    const mine = ++seq;
    // the cells typed in so far go with this check; a cell typed in after it is marked again and waits for the next
    box.querySelectorAll('.is-dirty').forEach((i) => i.classList.remove('is-dirty'));
    pack();
    const fd = new FormData(form);
    fd.set('step', 'check');
    fd.delete('_once');   // a check never uses up the upload's token
    if (checking) checking.hidden = false;
    fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, headers: { 'X-Csvr-Check': '1', Accept: 'application/json' }, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
      .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then((data) => {
        if (mine !== seq) return;
        const byLine = new Map((data.rows || []).map((x) => [String(x.line), x]));
        recs().forEach((r) => {
          if (r.querySelector('.is-dirty')) return;   // changed again while this check ran: the next check paints it
          const res = byLine.get(String(r.dataset.line));
          if (res) paint(r, res);
          else if (!byLine.size) r.classList.remove('is-edited', 'is-checking');   // only a problem of the page came back
        });
        if (general) {
          const g = data.general || [];
          general.hidden = g.length === 0;
          general.innerHTML = g.map(esc).join('<br>');
        }
        counts();
        filter();
      })
      .catch((err) => {
        if (err && err.name === 'AbortError') return;
        // the checker could not run (the session ended, the network): Check again does it by the page
        if (status) status.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> The rows could not be checked just now: press Check again.</span>';
      })
      .finally(() => {
        inflight = false;
        if (pending) { pending = false; runCheck(); return; }
        if (mine === seq && checking) checking.hidden = true;
      });
  };
  // a longer pause before checking a long file again (the server checks every row)
  const scheduleCheck = (delay) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(runCheck, delay === undefined ? Math.min(3000, 700 + recs().length) : delay);
  };

  // a cell changed: the row is checked again a moment after the typing stops
  box.addEventListener('input', (e) => {
    const r = e.target.closest('tbody[data-csvr-row]');
    if (!r || !e.target.matches('[data-k]')) return;
    e.target.classList.add('is-dirty');
    r.classList.add('is-edited');
    counts();
    scheduleCheck();
  });
  box.addEventListener('change', (e) => { if (e.target.matches('select[data-k]')) e.target.dispatchEvent(new Event('input', { bubbles: true })); });
  // a choice of the page (the code, the date, the account ...) changes every row
  form.addEventListener('change', (e) => {
    if (!e.target.closest('[data-csvr]') && e.target.name && e.target.name !== 'step') scheduleCheck(200);
  });

  const takeOut = (rows) => {
    const group = rows.map((r) => ({ el: r, next: r.nextElementSibling }));
    group.forEach((g) => g.el.remove());
    removed.push(group);
    counts();
    filter();
    // the rows left may now pass (the same member twice, the balance left by the rows above)
    scheduleCheck(150);
    return group;
  };

  box.addEventListener('click', (e) => {
    const rm = e.target.closest('[data-csvr-remove]');
    if (rm) {
      const r = rm.closest('tbody[data-csvr-row]');
      const next = r.nextElementSibling;
      takeOut([r]);
      const focus = next && next.matches('tbody[data-csvr-row]') ? next.querySelector('[data-csvr-remove]') : box.querySelector('[data-csvr-undo]');
      if (focus) focus.focus();
      return;
    }
    if (e.target.closest('[data-csvr-removebad]')) {
      // a row being corrected waits for its check
      const bad = recs().filter((r) => r.classList.contains('is-bad') && !r.classList.contains('is-edited'));
      if (bad.length) {
        takeOut(bad);
        if (badOnly) { badOnly.checked = false; filter(); }
        const u = box.querySelector('[data-csvr-undo]');
        if (u) u.focus();
      }
      return;
    }
    const more = e.target.closest('[data-csvr-more]');
    if (more) {
      const r = more.closest('tbody[data-csvr-row]');
      const row = r.querySelector('.csvr-more');
      const open = row.hidden;
      row.hidden = !open;
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
      more.classList.toggle('is-on', open);
      return;
    }
    if (e.target.closest('[data-csvr-undo]')) {
      const group = removed.pop();
      if (group) {
        group.slice().reverse().forEach((g) => {
          const anchor = g.next && g.next.parentNode ? g.next : none;
          anchor.parentNode.insertBefore(g.el, anchor);
        });
        counts();
        filter();
        scheduleCheck(150);
      }
    }
  });

  if (find) find.addEventListener('input', filter);
  if (badOnly) badOnly.addEventListener('change', filter);

  form.addEventListener('submit', (e) => {
    if (timer) { clearTimeout(timer); timer = null; }
    pending = false;
    if (ctrl) ctrl.abort();
    pack();
    const btn = e.submitter;
    setTimeout(() => {
      if (e.defaultPrevented) return;
      form.querySelectorAll('button[name="step"]').forEach((b) => { b.disabled = true; });
      if (btn) {
        // the disabled button no longer sends its value: keep the step
        let h = form.querySelector('input[type=hidden][name=step]');
        if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'step'; form.appendChild(h); }
        h.value = btn.value;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + (btn.value === 'post' ? 'Uploading' : 'Checking') + '&hellip;';
      }
    }, 0);
  });
  // Enter in a cell checks at once instead of uploading
  box.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('input[data-k]')) {
      e.preventDefault();
      scheduleCheck(0);
    }
  });
  // the width of the grid's box, for the messages kept in view when the grid scrolls sideways
  const scroller = box.querySelector('.csvr-scroll');
  const fit = () => { if (scroller) box.style.setProperty('--csvr-vw', scroller.clientWidth + 'px'); };
  fit();
  window.addEventListener('resize', fit);
  counts();
})();
