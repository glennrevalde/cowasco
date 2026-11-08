/* =====================================================================
   Water Billing > Setup > Receipt Layout Setup (version 46): the drawing of a pre-printed receipt form.

   The table of each part (Header, Lines, Totals) holds its entries (form inputs L[part][n][...]); the drawing of the
   form is built from the tables and the measurements (setup[...]) with sample entries, and follows every change. An
   entry is dragged on the drawing (or moved with the arrow keys once chosen); the blue cross of a part moves the whole
   part (its Top / Left). The dashed frame is the printable area inside the margins; the second copy of a water bill
   is drawn lighter where it prints. All values are in tups (1,440 tups = 1 inch; 1 point = 20 tups). No framework.
   ===================================================================== */
(function () {
  'use strict';
  const form = document.getElementById('rlForm');
  if (!form) return;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const catalog = JSON.parse(form.dataset.catalog || '{}');
  const formats = JSON.parse(form.dataset.formats || '{}');
  const sample = JSON.parse(form.dataset.sample || '{}');
  const kind = form.dataset.kind || 'collection';
  const editable = form.dataset.editable === '1';
  const stage = $('[data-stage]', form);
  const zoom = $('#rlZoom', form);
  let active = 'header';
  let selected = null;
  let seq = Date.now() % 100000;
  let dirty = false;

  // ------------------------------------------------------------------ the measurements
  const num = (k, d) => { const f = form.elements['setup[' + k + ']']; const v = f ? parseFloat(f.value) : NaN; return isNaN(v) ? (d || 0) : v; };
  const m = () => ({
    pw: Math.max(1440, num('page_width', 12240)), ph: Math.max(1440, num('page_height', 15840)),
    mt: Math.max(0, num('margin_top')), mr: Math.max(0, num('margin_right')), mb: Math.max(0, num('margin_bottom')), ml: Math.max(0, num('margin_left')),
    header: [num('header_left'), num('header_top')], body: [num('body_left'), num('body_top')], footer: [num('footer_left'), num('footer_top')],
    interval: Math.max(50, num('body_interval', 139)), max: Math.max(1, Math.round(num('max_lines', 2))),
    copies: kind === 'collection' ? Math.max(1, Math.min(2, Math.round(num('copies', 1)))) : 1, cx: num('copy_left'), cy: num('copy_top'),
    fs: num('font_size_pt', 6), font: (form.elements['setup[font]'] || {}).value || 'mono_bold', cpi: parseInt((form.elements['setup[cpi]'] || {}).value || '0', 10) || 0,
  });
  const squeeze = (mm, fs) => (mm.cpi > 0 && fs > 0) ? Math.min(2, Math.max(0.3, ((mm.font === 'sans' ? 129.5 : 120) / fs) / mm.cpi)) : 1;

  // ------------------------------------------------------------------ the entries (from the tables)
  const rows = (sec) => $$('tbody[data-items="' + sec + '"] > tr[data-item]', form);
  const val = (tr, k) => { const f = $('[data-k="' + k + '"]', tr); return f ? f.value : ''; };
  const item = (tr) => ({ f: val(tr, 'f'), x: parseInt(val(tr, 'x'), 10) || 0, y: parseInt(val(tr, 'y'), 10) || 0, w: parseInt(val(tr, 'w'), 10) || 0, a: val(tr, 'a') || 'l',
    fmt: val(tr, 'fmt'), z: val(tr, 'z'), t: val(tr, 't'), fs: parseFloat(val(tr, 'fs')) || 0 });

  // ------------------------------------------------------------------ the values as printed (as WaterReceiptLayout::format())
  const pad = (n) => String(n).padStart(2, '0');
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fmtDate(v, f) {
    const p = String(v || '').slice(0, 10).split('-');
    if (p.length !== 3) return String(v || '');
    const y = +p[0], mo = +p[1], d = +p[2];
    switch (f) {
      case 'n/j/y': return mo + '/' + d + '/' + pad(y % 100);
      case 'm/d/y': return pad(mo) + '/' + pad(d) + '/' + pad(y % 100);
      case 'M j, Y': return MON[mo - 1] + ' ' + d + ', ' + y;
      case 'd-M-y': return pad(d) + '-' + MON[mo - 1] + '-' + pad(y % 100);
      default: return pad(mo) + '/' + pad(d) + '/' + y;
    }
  }
  function fmtMoney(v, f, z) {
    if (v === '' || v === null || v === undefined) return '';
    const n = parseFloat(String(v).replace(/[,\s₱]/g, '')) || 0;
    if (Math.abs(n) < 0.005) return z === '-' ? '-' : (z === '0' ? (f === 'whole' ? '0' : '0.00') : '');
    const a = Math.abs(n);
    const t = f === 'plain' ? a.toFixed(2) : (f === 'whole' ? Math.round(a).toLocaleString('en-US') : a.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    return (n < 0 ? '-' : '') + t;
  }
  function format(sec, it, vals) {
    const def = (catalog[sec] || {})[it.f];
    const k = def ? def.kind : 'text';
    if (it.f === 'text') {
      return String(it.t || '').replace(/\{(\w+)\}/g, (all, key) => {
        const d = (catalog[sec] || {})[key];
        if (!d || key === 'text') return all;
        return format(sec, { f: key, fmt: formats[d.kind] ? Object.keys(formats[d.kind])[0] : '', z: '' }, vals);
      });
    }
    const raw = vals[it.f] === undefined || vals[it.f] === null ? '' : String(vals[it.f]);
    if (k === 'date') return fmtDate(raw, it.fmt);
    if (k === 'money') return fmtMoney(raw, it.fmt, it.z);
    return raw;
  }
  function fitLines(lines, max) {
    if (lines.length <= max) return lines;
    const keep = lines.slice(0, max - 1);
    const rest = lines.slice(max - 1).reduce((t, l) => t + (parseFloat(l.amount) || 0), 0);
    keep.push({ description: kind === 'official' ? 'OTHER SERVICES' : 'OTHER CHARGES', amount: rest.toFixed(2) });
    return keep;
  }

  // ------------------------------------------------------------------ the drawing
  function scale(mm) {
    const availW = Math.max(240, stage.clientWidth - 40);
    const availH = Math.max(380, Math.round(window.innerHeight * 0.62));
    return Math.min(availW / mm.pw, availH / mm.ph) * (parseInt(zoom ? zoom.value : '100', 10) / 100);
  }
  function el(cls, css, text) {
    const d = document.createElement('div');
    d.className = cls;
    Object.assign(d.style, css);
    if (text !== undefined) d.textContent = text;
    return d;
  }
  function place(page, sec, it, x0, y0, vals, s, mm, tr, live, other) {
    const text = format(sec, it, vals);
    if (text === '' && !live) return;
    const d = el('rl-item' + (live ? ' is-live' : '') + (other ? ' is-other' : '') + (tr && tr === selected && live ? ' is-sel' : ''), {
      left: ((mm.ml + x0 + it.x) * s) + 'px', top: ((mm.mt + y0 + it.y) * s) + 'px',
      fontSize: Math.max(4, (it.fs || mm.fs) * 20 * s) + 'px',
    }, text === '' ? '[' + (((catalog[sec] || {})[it.f] || {}).label || it.f) + ']' : text);
    if (text === '') d.classList.add('is-empty');
    if (it.w > 0) { d.style.width = (it.w * s) + 'px'; d.style.textAlign = it.a === 'r' ? 'right' : (it.a === 'c' ? 'center' : 'left'); d.style.overflow = 'visible'; }
    const k = text === '' ? 1 : squeeze(mm, it.fs || mm.fs);
    if (k !== 1) { d.style.transform = 'scaleX(' + k.toFixed(4) + ')'; d.style.transformOrigin = (it.w > 0 && it.a === 'r' ? 'right' : (it.w > 0 && it.a === 'c' ? 'center' : 'left')) + ' top'; }
    if (tr && live) d._tr = tr;
    page.appendChild(d);
  }
  function render() {
    const mm = m();
    const s = scale(mm);
    stage.innerHTML = '';
    const page = el('rl-paper' + (mm.font === 'sans' ? ' is-sans' : '') + (mm.font === 'mono_bold' ? ' is-bold' : ''), { width: (mm.pw * s) + 'px', height: (mm.ph * s) + 'px' });
    stage.appendChild(page);
    page.appendChild(el('rl-margin', { left: (mm.ml * s) + 'px', top: (mm.mt * s) + 'px', width: Math.max(0, (mm.pw - mm.ml - mm.mr) * s) + 'px', height: Math.max(0, (mm.ph - mm.mt - mm.mb) * s) + 'px' }));
    const vals = sample.values || {};
    const lines = fitLines(sample.lines || [], mm.max);
    for (let c = 0; c < mm.copies; c++) {
      const dx = c * mm.cx, dy = c * mm.cy;
      const live = (sec) => c === 0 && sec === active && editable;
      const v = Object.assign({}, vals, { copy: c === 0 ? "Cooperative's Copy" : "Consumer's Copy" });
      if (c === 1) page.appendChild(el('rl-copy2', { left: ((mm.ml + dx) * s) + 'px', top: ((mm.mt + dy) * s) + 'px', width: Math.max(0, (mm.pw - mm.ml - mm.mr - dx) * s) + 'px', height: Math.max(0, (mm.ph - mm.mt - mm.mb - dy) * s) + 'px' }));
      rows('header').forEach((tr) => place(page, 'header', item(tr), dx + mm.header[0], dy + mm.header[1], v, s, mm, tr, live('header'), c > 0));
      for (let i = 0; i < mm.max; i++) {
        if (c === 0 && active === 'body') page.appendChild(el('rl-line', { top: ((mm.mt + dy + mm.body[1] + i * mm.interval) * s) + 'px' }));
        const line = lines[i];
        if (!line && !(c === 0 && i === 0 && active === 'body')) continue;
        rows('body').forEach((tr) => place(page, 'body', item(tr), dx + mm.body[0], dy + mm.body[1] + i * mm.interval, Object.assign({}, v, line || {}), s, mm, tr, live('body') && i === 0, c > 0));
      }
      rows('footer').forEach((tr) => place(page, 'footer', item(tr), dx + mm.footer[0], dy + mm.footer[1], v, s, mm, tr, live('footer'), c > 0));
    }
    if (editable) {
      const o = el('rl-origin', { left: ((mm.ml + mm[active][0]) * s) + 'px', top: ((mm.mt + mm[active][1]) * s) + 'px' });
      o.title = 'Drag to move the whole part (its Left and Top)';
      o.dataset.sec = active;
      page.appendChild(o);
    }
  }

  // ------------------------------------------------------------------ the table: kinds, formats, counts
  function syncRow(tr) {
    const sec = tr.closest('tbody').dataset.items;
    const f = val(tr, 'f');
    const k = ((catalog[sec] || {})[f] || {}).kind || 'text';
    tr.dataset.kind = k;
    const t = $('[data-k="t"]', tr);
    if (t) t.hidden = f !== 'text';
    const fmt = $('[data-k="fmt"]', tr);
    const opts = formats[k] || null;
    if (fmt) {
      if (opts && fmt.dataset.kind !== k) {
        const keep = fmt.value;
        fmt.innerHTML = Object.keys(opts).map((x) => '<option value="' + x + '">' + opts[x] + '</option>').join('');
        if (opts[keep]) fmt.value = keep;
      }
      if (!opts) fmt.innerHTML = '';
      fmt.hidden = !opts;
      fmt.dataset.kind = k;
    }
    const z = $('[data-k="z"]', tr);
    if (z) z.hidden = k !== 'money';
  }
  function counts() {
    $$('.rl-sec', form).forEach((b) => { const c = $('.rl-count', b); if (c) c.textContent = String(rows(b.dataset.sec).length); });
  }
  function select(tr) {
    selected = tr;
    $$('tr[data-item]', form).forEach((r) => r.classList.toggle('is-sel', r === tr));
    render();
  }

  // ------------------------------------------------------------------ dragging (an entry, or the origin of a part)
  let drag = null;
  const snap = (v) => Math.round(v / 10) * 10;
  stage.addEventListener('pointerdown', (e) => {
    if (!editable) return;
    const it = e.target.closest('.rl-item.is-live');
    const or = e.target.closest('.rl-origin');
    if (!it && !or) return;
    e.preventDefault();
    stage.focus({ preventScroll: true });   // the arrow keys then move the entry picked
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
      form.elements['setup[' + drag.sec + '_left]'].value = drag.x + dx;
      form.elements['setup[' + drag.sec + '_top]'].value = drag.y + dy;
    }
    dirty = true;
    window.requestAnimationFrame(render);
  });
  const endDrag = () => { drag = null; };
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
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

  // ------------------------------------------------------------------ the parts, the tables, the measurements
  $$('.rl-sec', form).forEach((b) => b.addEventListener('click', () => {
    active = b.dataset.sec;
    $$('.rl-sec', form).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
    $$('[data-sec-pane]', form).forEach((p) => { p.hidden = p.dataset.secPane !== active; });
    selected = null;
    render();
  }));
  form.addEventListener('input', (e) => {
    if (e.target.matches('[data-m], [data-k]')) { dirty = true; render(); }
  });
  form.addEventListener('change', (e) => {
    const tr = e.target.closest('tr[data-item]');
    if (tr && e.target.matches('[data-k="f"]')) syncRow(tr);
    if (e.target.matches('[data-m], [data-k]')) { dirty = true; render(); }
  });
  form.addEventListener('focusin', (e) => { const tr = e.target.closest('tr[data-item]'); if (tr && tr !== selected) select(tr); });
  form.addEventListener('click', (e) => {
    const del = e.target.closest('[data-del-item]');
    if (del) { const tr = del.closest('tr'); if (tr === selected) selected = null; tr.remove(); dirty = true; counts(); render(); return; }
    const add = e.target.closest('[data-add-item]');
    if (add) { addItem(add.dataset.addItem, null); return; }
    const reset = e.target.closest('[data-reset-sec]');
    if (reset) {
      const go = () => {
        const sec = reset.dataset.resetSec;
        $('tbody[data-items="' + sec + '"]', form).innerHTML = '';
        JSON.parse(reset.dataset.default || '[]').forEach((it) => addItem(sec, it));
        dirty = true;
      };
      if (window.AppDialog) window.AppDialog.confirm('Replace the entries of this part with the default ones?', { title: 'Receipt Layout Setup', ok: 'Replace' }).then((y) => { if (y) go(); });
      else if (window.confirm('Replace the entries of this part with the default ones?')) go();
    }
  });
  function addItem(sec, it) {
    const tpl = $('template[data-tpl="' + sec + '"]', form);
    const body = $('tbody[data-items="' + sec + '"]', form);
    const holder = document.createElement('tbody');
    holder.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + (seq++)).trim();
    const tr = holder.firstElementChild;
    body.appendChild(tr);
    const set = (k, v) => { const f = $('[data-k="' + k + '"]', tr); if (f && v !== undefined && v !== null) f.value = v; };
    if (it) {
      set('f', it.f); syncRow(tr);
      set('x', it.x); set('y', it.y); set('w', it.w); set('a', it.a); set('fmt', it.fmt); set('z', it.z); set('t', it.t); set('fs', it.fs > 0 ? it.fs : '');
    } else {
      const used = rows(sec).map((r) => val(r, 'f'));
      const free = Object.keys(catalog[sec] || {}).find((k) => k !== 'text' && !used.slice(0, -1).includes(k)) || 'text';
      set('f', free); syncRow(tr);
      const others = rows(sec).filter((r) => r !== tr).map(item);
      if (sec === 'body') set('x', others.length ? Math.max(...others.map((o) => o.x + Math.max(o.w, 600))) + 100 : 0);
      else set('y', others.length ? Math.max(...others.map((o) => o.y)) + 250 : 0);
      select(tr);
    }
    counts();
    render();
    return tr;
  }

  // the paper sizes
  const size = $('#rlSize', form);
  if (size) size.addEventListener('change', () => {
    if (!size.value) return;
    const [w, h] = size.value.split('x');
    form.elements['setup[page_width]'].value = w;
    form.elements['setup[page_height]'].value = h;
    dirty = true;
    render();
  });
  // a test page prints what is saved
  $$('[data-test]').forEach((a) => a.addEventListener('click', (e) => {
    if (!dirty) return;
    e.preventDefault();
    const go = () => window.open(a.href, '_blank', 'noopener');
    const text = 'The test page prints the saved layout. Print it without your changes? (Save first to print them.)';
    if (window.AppDialog) window.AppDialog.confirm(text, { title: 'Print a test page', ok: 'Print the saved layout' }).then((y) => { if (y) go(); });
    else if (window.confirm(text)) go();
  }));
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  if (zoom) zoom.addEventListener('input', render);
  let rt = null;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(render, 120); });

  ['header', 'body', 'footer'].forEach((sec) => rows(sec).forEach(syncRow));
  render();
})();
