/* =====================================================================
   Cooperative > Passbook Types: the design of a passbook (cover, header, body, footer).
   The table of each part holds the fields (form inputs L[part][n][...]); the drawing of the page is built from the
   table and the margins, with sample data, and follows every change. A field is dragged on the drawing (or moved
   with the arrow keys); the blue cross of a part moves the whole part (its Top / Left). All values are in tups
   (1,440 tups = 1 inch; 1 point = 20 tups). No framework.
   ===================================================================== */
(function () {
  'use strict';

  // the list of passbook types: a click on a row opens it
  document.querySelectorAll('.pbt-list tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = tr.dataset.href;
  }));
  const form = document.getElementById('pbtForm');
  if (!form) return;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const catalog = JSON.parse(form.dataset.catalog || '{}');
  const formats = JSON.parse(form.dataset.formats || '{}');
  const sample = JSON.parse(form.dataset.sample || '{}');
  const stage = $('[data-stage]', form);
  const zoom = $('#pbdZoom', form);
  const editable = !form.querySelector('.pbt-actions button.btn-primary') ? false : true;
  let active = 'body';
  let selected = null;           // the row (tr) of the field selected
  let seq = Date.now() % 100000; // the index of a field added (its form names)
  let dirty = false;

  // ------------------------------------------------------------------ the numbers of the form
  const num = (name, dflt) => { const f = form.elements[name]; const v = f ? parseFloat(f.value) : NaN; return isNaN(v) ? (dflt || 0) : v; };
  const m = () => ({
    pw: Math.max(1440, num('page_width', 12240)), ph: Math.max(1440, num('page_height', 8640)),
    cover: [num('cover_left'), num('cover_top')], header: [num('header_left'), num('header_top')], footer: [num('footer_left'), num('footer_top')],
    body: [num('body_left'), num('body_top')], interval: Math.max(50, num('body_interval', 275)),
    skipStart: num('start_line_skip'), skipN: num('line_skip'), max: Math.max(1, num('max_lines', 26)), fs: num('font_size_pt', 9),
    font: (form.elements['opt[font]'] || {}).value || 'mono', cpi: parseInt((form.elements['opt[cpi]'] || {}).value || '0', 10) || 0,
  });
  // condensed print: the characters narrowed to the characters per inch (as Passbook::squeeze())
  const squeeze = (mm, fs) => (mm.cpi > 0 && fs > 0) ? Math.min(2, Math.max(0.3, ((mm.font === 'sans' ? 129.5 : 120) / fs) / mm.cpi)) : 1;
  const skipped = (mm) => { const s = {}; if (mm.skipStart > 0 && mm.skipN > 0) for (let i = mm.skipStart; i < mm.skipStart + mm.skipN; i++) s[i] = true; return s; };
  const pageLines = (mm) => { const s = skipped(mm); const out = []; for (let l = 1; l <= mm.max; l++) if (!s[l]) out.push(l); return out; };

  // ------------------------------------------------------------------ the fields of a part (from its table)
  const rows = (sec) => $$('tbody[data-items="' + sec + '"] > tr[data-item]', form);
  const val = (tr, k) => { const f = $('[data-k="' + k + '"]', tr); return f ? f.value : ''; };
  const item = (tr) => ({ f: val(tr, 'f'), x: parseInt(val(tr, 'x'), 10) || 0, y: parseInt(val(tr, 'y'), 10) || 0, w: parseInt(val(tr, 'w'), 10) || 0, a: val(tr, 'a') || 'l',
    fmt: val(tr, 'fmt'), z: val(tr, 'z'), t: val(tr, 't'), fs: parseFloat(val(tr, 'fs')) || 0 });

  // ------------------------------------------------------------------ the values as printed (as Passbook::format())
  const pad = (n) => String(n).padStart(2, '0');
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fmtDate(v, f) {
    const p = String(v || '').slice(0, 10).split('-');
    if (p.length !== 3) return '';
    const y = +p[0], mo = +p[1], d = +p[2];
    switch (f) {
      case 'm/d/y': return pad(mo) + '/' + pad(d) + '/' + pad(y % 100);
      case 'm/d/Y': return pad(mo) + '/' + pad(d) + '/' + y;
      case 'M j, Y': return MON[mo - 1] + ' ' + d + ', ' + y;
      case 'd/m/Y': return pad(d) + '/' + pad(mo) + '/' + y;
      default: return mo + '/' + d + '/' + pad(y % 100);
    }
  }
  function fmtMoney(v, f, z) {
    const n = parseFloat(v || '0') || 0;
    if (Math.abs(n) < 0.005) return z === '-' ? '-' : (z === '0' ? (f === 'whole' ? '0' : '0.00') : '');
    const a = Math.abs(n);
    let t;
    if (f === 'plain') t = a.toFixed(2);
    else if (f === 'whole') t = Math.round(a).toLocaleString('en-US');
    else t = a.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (n < 0 ? '-' : '') + t;
  }
  function format(sec, it, vals) {
    const def = (catalog[sec] || {})[it.f];
    const kind = def ? def.kind : 'text';
    if (it.f === 'text') {
      return String(it.t || '').replace(/\{(\w+)\}/g, (all, k) => {
        const d = (catalog[sec] || {})[k];
        if (!d || k === 'text') return all;
        return format(sec, { f: k, fmt: (formats[d.kind] ? Object.keys(formats[d.kind])[0] : ''), z: '' }, vals);
      });
    }
    const raw = vals[it.f] === undefined || vals[it.f] === null ? '' : String(vals[it.f]);
    switch (kind) {
      case 'date': return fmtDate(raw, it.fmt);
      case 'money': return fmtMoney(raw, it.fmt, it.z);
      case 'term': {
        const [n, unit] = raw.split('|');
        if (!n) return '';
        const nn = String(parseFloat(n));
        if (it.fmt === 'months') return nn + ' ' + (unit === 'days' ? 'days' : 'months');
        if (it.fmt === 'num') return nn;
        return nn + (unit === 'days' ? 'days' : 'mos');
      }
      case 'rate': return raw === '' ? '' : (it.fmt === 'pct0' ? String(parseFloat(raw)) : (parseFloat(raw) || 0).toFixed(2)) + '%';
      default: return raw;
    }
  }

  // ------------------------------------------------------------------ the drawing of the page
  function scale(mm) {
    // 100 %: the whole page in the width of the drawing and at most about two thirds of the window high
    const availW = Math.max(240, stage.parentElement.clientWidth - 40);
    const availH = Math.max(420, Math.round(window.innerHeight * 0.68));
    return Math.min(availW / mm.pw, availH / mm.ph) * (parseInt(zoom ? zoom.value : '100', 10) / 100);
  }
  function el(cls, css, text) {
    const d = document.createElement('div');
    d.className = cls;
    Object.assign(d.style, css);
    if (text !== undefined) d.textContent = text;
    return d;
  }
  function placeItem(page, sec, it, origin, dy, vals, s, mm, tr, interactive, ghost) {
    const text = format(sec, it, vals);
    // an empty field is shown by its name where it can be grabbed (in the body, on the first line only)
    if (text === '' && !(interactive && ghost !== false)) return null;
    const d = el('pbd-item' + (interactive ? ' is-live' : '') + (tr && tr === selected ? ' is-sel' : ''), {
      left: Math.max(0, (origin[0] + it.x) * s) + 'px', top: Math.max(0, (origin[1] + it.y + dy) * s) + 'px',
      fontSize: Math.max(5, (it.fs || mm.fs) * 20 * s) + 'px', lineHeight: '1.15',
    }, text === '' ? (interactive ? '[' + ((catalog[sec] || {})[it.f] || {}).label + ']' : '') : text);
    if (text === '' && interactive) d.classList.add('is-empty');
    if (it.w > 0 && it.a !== 'l') { d.style.width = (it.w * s) + 'px'; d.style.textAlign = it.a === 'r' ? 'right' : 'center'; }
    if (text === '' && it.w > 0) { d.style.width = (it.w * s) + 'px'; d.style.overflow = 'hidden'; d.style.textOverflow = 'ellipsis'; }
    const k = text === '' ? 1 : squeeze(mm, it.fs || mm.fs);
    if (k !== 1) { d.style.transform = 'scaleX(' + k.toFixed(4) + ')'; d.style.transformOrigin = (it.w > 0 && it.a === 'r' ? 'right' : (it.w > 0 && it.a === 'c' ? 'center' : 'left')) + ' top'; }
    if (tr) d._tr = tr;
    page.appendChild(d);
    return d;
  }
  function originMark(page, sec, origin, s) {
    const o = el('pbd-origin', { left: (origin[0] * s) + 'px', top: (origin[1] * s) + 'px' });
    o.title = 'Drag to move the ' + sec + ' (its Left and Top)';
    o.dataset.sec = sec;
    page.appendChild(o);
  }
  function render() {
    const mm = m();
    const s = scale(mm);
    stage.innerHTML = '';
    stage.classList.toggle('is-sans', mm.font === 'sans');
    const wrap = el('pbd-paper-wrap', {});
    const gutter = el('pbd-gutter', { height: (mm.ph * s) + 'px' });
    const page = el('pbd-paper', { width: (mm.pw * s) + 'px', height: (mm.ph * s) + 'px' });
    wrap.appendChild(gutter);
    wrap.appendChild(page);
    stage.appendChild(wrap);
    const vals = sample.values || {};
    if (active === 'cover') {
      page.classList.add('is-cover');
      rows('cover').forEach((tr) => placeItem(page, 'cover', item(tr), mm.cover, 0, vals, s, mm, tr, true));
      originMark(page, 'cover', mm.cover, s);
      return;
    }
    // the lines of the body: a guide on every line, the fold shaded, the sample entries on the printable lines
    const skip = skipped(mm);
    for (let l = 1; l <= mm.max; l++) {
      const top = (mm.body[1] + (l - 1) * mm.interval) * s;
      const g = el('pbd-guide' + (skip[l] ? ' is-skip' : ''), { top: top + 'px', height: Math.max(2, mm.interval * s) + 'px' });
      if (skip[l]) g.textContent = 'line ' + l + ' skipped';
      page.appendChild(g);
      gutter.appendChild(el('pbd-gnum' + (skip[l] ? ' is-skip' : ''), { top: top + 'px', fontSize: Math.max(7, Math.min(11, mm.interval * s * 0.8)) + 'px' }, String(l)));
    }
    const lines = pageLines(mm);
    const live = (sec) => sec === active;
    rows('header').forEach((tr) => placeItem(page, 'header', item(tr), mm.header, 0, vals, s, mm, tr, live('header')));
    const sl = sample.lines || [];
    lines.forEach((l, i) => {
      const v = Object.assign({}, sl[i] || {}, { line: String(l) });
      rows('body').forEach((tr) => placeItem(page, 'body', item(tr), mm.body, (l - 1) * mm.interval, v, s, mm, tr, live('body'), i === 0));
    });
    const last = sl[Math.max(0, lines.length - 1)] || {};
    rows('footer').forEach((tr) => placeItem(page, 'footer', item(tr), mm.footer, 0, Object.assign({}, vals, { balance: last.balance || vals.balance }), s, mm, tr, live('footer')));
    originMark(page, active, mm[active], s);
    if (mm.body[1] + (mm.max - 1) * mm.interval > mm.ph) {
      page.appendChild(el('pbd-warn', {}, 'Max Lines go below the page: line ' + mm.max + ' would print at ' + (mm.body[1] + (mm.max - 1) * mm.interval) + ' tups, the page is ' + mm.ph + ' high.'));
    }
  }

  // ------------------------------------------------------------------ the rule of the lines, in words
  function rule() {
    const box = $('[data-rule]', form);
    if (!box) return;
    const mm = m();
    const s = Object.keys(skipped(mm)).map(Number);
    const parts = [];
    if (s.length) {
      if (s[0] > 1) parts.push('lines 1 to ' + (s[0] - 1));
      if (s[s.length - 1] < mm.max) parts.push((s[s.length - 1] + 1) + ' to ' + mm.max);
    } else parts.push('lines 1 to ' + mm.max);
    const skipWords = s.length === 1 ? 'line ' + s[0] + ' is' : (s.length === 2 ? 'lines ' + s[0] + ' and ' + s[1] + ' are' : 'lines ' + s[0] + ' to ' + s[s.length - 1] + ' are');
    box.textContent = 'Printing starts on line 1. A page prints ' + parts.join(' and ') + (s.length ? '; ' + skipWords + ' skipped (after line ' + (s[0] - 1) + ' the next entry prints on line ' + (s[s.length - 1] + 1) + ')' : '')
      + '. After line ' + mm.max + ', the next entry goes to line 1 of the next page, after the header.';
  }

  // ------------------------------------------------------------------ the table: kinds, formats, counts
  function syncRow(tr) {
    const sec = tr.closest('tbody').dataset.items;
    const f = val(tr, 'f');
    const kind = ((catalog[sec] || {})[f] || {}).kind || 'text';
    tr.dataset.kind = kind;
    const t = $('[data-k="t"]', tr);
    if (t) t.hidden = f !== 'text';
    const fmt = $('[data-k="fmt"]', tr);
    const opts = formats[kind] || null;
    if (fmt) {
      const keep = fmt.value;
      if (opts) {
        if (fmt.dataset.kind !== kind) {
          fmt.innerHTML = Object.keys(opts).map((k) => '<option value="' + k + '">' + opts[k] + '</option>').join('');
          if (opts[keep]) fmt.value = keep;
        }
        fmt.hidden = false;
      } else {
        fmt.innerHTML = '';
        fmt.hidden = true;
      }
      fmt.dataset.kind = kind;
    }
    const z = $('[data-k="z"]', tr);
    if (z) { z.hidden = kind !== 'money'; z.classList.toggle('mt-1', !!opts); }
  }
  function counts() {
    $$('.pbd-tab', form).forEach((b) => { const c = $('.pbd-count', b); if (c) c.textContent = String(rows(b.dataset.sec).length); });
  }
  function select(tr) {
    selected = tr;
    $$('tr[data-item]', form).forEach((r) => r.classList.toggle('is-sel', r === tr));
    render();
  }

  // ------------------------------------------------------------------ dragging (a field, or the origin of a part)
  let drag = null;
  const snap = (v) => Math.round(v / 10) * 10;
  stage.addEventListener('pointerdown', (e) => {
    if (!editable) return;
    const it = e.target.closest('.pbd-item.is-live');
    const or = e.target.closest('.pbd-origin');
    if (!it && !or) return;
    e.preventDefault();
    const s = scale(m());
    if (it) {
      select(it._tr);
      drag = { tr: it._tr, x0: e.clientX, y0: e.clientY, x: parseInt(val(it._tr, 'x'), 10) || 0, y: parseInt(val(it._tr, 'y'), 10) || 0, s };
    } else {
      const sec = or.dataset.sec;
      drag = { sec, x0: e.clientX, y0: e.clientY, x: num(sec + '_left'), y: num(sec + '_top'), s };
    }
    stage.setPointerCapture(e.pointerId);
  });
  stage.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const dx = snap((e.clientX - drag.x0) / drag.s), dy = snap((e.clientY - drag.y0) / drag.s);
    if (drag.tr) {
      $('[data-k="x"]', drag.tr).value = drag.x + dx;
      $('[data-k="y"]', drag.tr).value = drag.y + dy;
    } else {
      form.elements[drag.sec + '_left'].value = Math.max(0, drag.x + dx);
      form.elements[drag.sec + '_top'].value = Math.max(0, drag.y + dy);
    }
    dirty = true;
    window.requestAnimationFrame(render);
  });
  const endDrag = () => { if (drag) { drag = null; rule(); } };
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
  stage.tabIndex = 0;
  stage.addEventListener('keydown', (e) => {
    if (!selected || !editable || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
    e.preventDefault();
    const step = e.shiftKey ? 100 : 10;
    const x = $('[data-k="x"]', selected), y = $('[data-k="y"]', selected);
    if (e.key === 'ArrowLeft') x.value = (parseInt(x.value, 10) || 0) - step;
    if (e.key === 'ArrowRight') x.value = (parseInt(x.value, 10) || 0) + step;
    if (e.key === 'ArrowUp') y.value = (parseInt(y.value, 10) || 0) - step;
    if (e.key === 'ArrowDown') y.value = (parseInt(y.value, 10) || 0) + step;
    dirty = true;
    render();
  });

  // ------------------------------------------------------------------ the tabs, the table, the margins
  $$('.pbd-tab', form).forEach((b) => b.addEventListener('click', () => {
    active = b.dataset.sec;
    $$('.pbd-tab', form).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
    $$('[data-sec-pane]', form).forEach((p) => { p.hidden = p.dataset.secPane !== active; });
    selected = null;
    render();
  }));
  form.addEventListener('input', (e) => {
    dirty = true;
    if (e.target.matches('[data-m], [data-k], [data-k] *')) { rule(); render(); }
  });
  form.addEventListener('change', (e) => {
    const tr = e.target.closest('tr[data-item]');
    if (tr && e.target.matches('[data-k="f"]')) { syncRow(tr); }
    if (e.target.matches('[data-m], [data-k]')) { rule(); render(); }
  });
  form.addEventListener('focusin', (e) => { const tr = e.target.closest('tr[data-item]'); if (tr && tr !== selected) select(tr); });
  form.addEventListener('click', (e) => {
    const del = e.target.closest('[data-del-item]');
    if (del) { const tr = del.closest('tr'); if (tr === selected) selected = null; tr.remove(); dirty = true; counts(); render(); return; }
    const add = e.target.closest('[data-add-item]');
    if (add) { addItem(add.dataset.addItem, null); return; }
    const reset = e.target.closest('[data-reset-sec]');
    if (reset) {
      if (!window.confirm('Replace the fields of this part with the default design?')) return;
      const sec = reset.dataset.resetSec;
      $('tbody[data-items="' + sec + '"]', form).innerHTML = '';
      JSON.parse(reset.dataset.default || '[]').forEach((it) => addItem(sec, it));
      dirty = true;
    }
  });
  function addItem(sec, it) {
    const tpl = $('template[data-tpl="' + sec + '"]', form);
    const body = $('tbody[data-items="' + sec + '"]', form);
    const holder = document.createElement('tbody');
    holder.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + (seq++)).trim();
    const tr = holder.firstElementChild;
    body.appendChild(tr);
    const used = rows(sec).map((r) => val(r, 'f'));
    const set = (k, v) => { const f = $('[data-k="' + k + '"]', tr); if (f && v !== undefined && v !== null) f.value = v; };
    if (it) {
      set('f', it.f); syncRow(tr);
      set('x', it.x); set('y', it.y); set('w', it.w); set('a', it.a); set('fmt', it.fmt); set('z', it.z); set('t', it.t); set('fs', it.fs > 0 ? it.fs : '');
    } else {
      // the first field not placed yet, below the others (the body: to the right of the last column)
      const free = Object.keys(catalog[sec] || {}).find((k) => k !== 'text' && !used.slice(0, -1).includes(k)) || 'text';
      set('f', free); syncRow(tr);
      const others = rows(sec).filter((r) => r !== tr).map(item);
      if (sec === 'body') set('x', others.length ? Math.max(...others.map((o) => o.x + Math.max(o.w, 600))) + 100 : 0);
      else set('y', others.length ? Math.max(...others.map((o) => o.y)) + 300 : 0);
      select(tr);
    }
    counts();
    render();
    return tr;
  }

  // the passbook size: a choice of sizes fills the width and height
  const size = $('#pbtSize', form);
  if (size) size.addEventListener('change', () => {
    if (!size.value) return;
    const [w, h] = size.value.split('x');
    form.elements.page_width.value = w;
    form.elements.page_height.value = h;
    dirty = true; render();
  });
  // the kind of a type not used yet: the page opens again with the default design of the other kind
  const kind = $('[data-kind-switch]', form);
  if (kind) kind.addEventListener('change', () => {
    if (window.confirm('Open the design of a ' + (kind.value === 'loan' ? 'loan' : 'savings') + ' passbook? Changes not saved are lost.')) {
      window.location.href = kind.dataset.kindSwitch + '&ledger=' + encodeURIComponent(kind.value);
    } else kind.value = form.dataset.ledger;
  });
  // a test page prints what is saved
  $$('[data-test]', form).forEach((a) => a.addEventListener('click', (e) => {
    if (dirty && !window.confirm('The test page prints the saved passbook type. Print it without your changes? (Save first to print them.)')) e.preventDefault();
  }));
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  if (zoom) zoom.addEventListener('input', render);
  let rt = null;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(render, 120); });

  rows('cover').concat(rows('header'), rows('body'), rows('footer')).forEach(syncRow);
  if (!editable) $$('input, select, textarea, button[data-del-item]', $('.pbd', form)).forEach((x) => { if (x.id !== 'pbdZoom') x.disabled = true; });
  rule();
  render();
})();
