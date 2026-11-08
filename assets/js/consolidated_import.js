/*
 * Members & Others > Consolidated Import (modules/coop/consolidated_import.php).
 *
 * The first page sends the file in pieces (any size the server allows a request), then has it read into the database.
 * The page of a file shows its lines a page at a time, by kind (members, savings, loans, bill balances): a cell is
 * corrected by typing in it or choosing from its list and the member is checked again at once on the server; a line is
 * removed with its x (a member with its lines) and put back. Checking every line and saving run in short steps, with a
 * progress bar, so a file of thousands of rows never times out; saving goes on where it stopped.
 */
(function () {
  'use strict';
  const root = document.getElementById('ci');
  if (!root) return;
  const base = root.getAttribute('data-url');
  const csrf = root.getAttribute('data-csrf');
  const fmt = (v) => (window.AppFmt ? window.AppFmt.fmt(window.AppFmt.num(v)) : String(v));
  const num = (n) => Number(n || 0).toLocaleString('en-US');
  const esc = (s) => String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const say = (msg) => (window.AppDialog ? window.AppDialog.alert(msg, { title: 'Consolidated Import', tone: 'warning' }) : Promise.resolve(window.alert(msg)));
  const ask = (msg, ok) => (window.AppDialog ? window.AppDialog.confirm(msg, { title: 'Consolidated Import', ok: ok, tone: 'primary' }) : Promise.resolve(window.confirm(msg)));

  /** A request to the page: the answer as JSON, or an Error with the message of the server */
  function call(method, params, file) {
    let url = base;
    const opt = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method === 'GET') {
      url += '&' + new URLSearchParams(params).toString();
    } else {
      const fd = new FormData();
      fd.append('_csrf', csrf);
      Object.keys(params).forEach((k) => fd.append(k, params[k]));
      if (file) fd.append('data', file, 'piece');
      opt.body = fd;
    }
    return fetch(url, opt).then((r) => r.text().then((t) => {
      let j = null;
      try { j = JSON.parse(t); } catch (e) { j = null; }
      if (!j) {
        throw new Error(r.status === 403 ? 'Your session expired: refresh the page and log in again.'
          : r.status === 413 ? 'The server refused a piece of the file as too large (post_max_size).'
            : 'The server did not answer as expected (' + r.status + '). ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240));
      }
      if (!r.ok || j.error) throw new Error(j.error || 'The server refused the request (' + r.status + ').');
      return j;
    }));
  }

  // ================================================================== the first page: reading a file
  if (root.hasAttribute('data-ci-start')) {
    const form = root.querySelector('[data-ci-pick]');
    if (!form) return;
    const input = form.querySelector('input[type=file]');
    const btn = form.querySelector('[data-ci-read]');
    const box = form.querySelector('[data-ci-progress]');
    const bar = box.querySelector('.progress-bar');
    const text = form.querySelector('[data-ci-progress-text]');
    const max = parseInt(root.getAttribute('data-max'), 10) || 0;
    const mb = (n) => (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB';
    const show = (pct, msg, busy) => {
      box.hidden = false;
      bar.style.width = Math.max(2, Math.min(100, pct)) + '%';
      bar.classList.toggle('progress-bar-striped', !!busy);
      bar.classList.toggle('progress-bar-animated', !!busy);
      text.textContent = msg;
    };
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const f = input.files && input.files[0];
      if (!f) { input.focus(); return; }
      if (!/\.(csv|txt|tsv|xlsx)$/i.test(f.name)) {
        say(/\.xls$/i.test(f.name) ? 'Old Excel files (.xls) are not read: in Excel, File > Save As > Excel Workbook (.xlsx) or CSV.' : 'Choose a CSV file (.csv) or an Excel workbook (.xlsx).');
        return;
      }
      if (max && f.size > max) { say('The file is larger than ' + mb(max) + ': split it into smaller files.'); return; }
      btn.disabled = true;
      input.disabled = true;
      show(1, 'Starting');
      call('POST', { a: 'create', file_name: f.name, bytes: String(f.size) }).then((j) => {
        const size = j.chunk;
        const pieces = Math.max(1, Math.ceil(f.size / size));
        let i = 0;
        const next = () => {
          if (i >= pieces) return Promise.resolve();
          const piece = f.slice(i * size, Math.min(f.size, (i + 1) * size));
          return call('POST', { a: 'chunk', id: String(j.id), index: String(i) }, piece).then(() => {
            i++;
            show(5 + (i / pieces) * 70, 'Sending the file: ' + mb(Math.min(f.size, i * size)) + ' of ' + mb(f.size));
            return next();
          });
        };
        return next().then(() => {
          show(85, 'Reading the rows into the database and making the lines of the review. A large file takes a minute.', true);
          return call('POST', { a: 'parse', id: String(j.id) });
        });
      }).then((j) => {
        show(100, 'Read. Opening the review.');
        window.location.href = j.url;
      }).catch((err) => {
        box.hidden = true;
        btn.disabled = false;
        input.disabled = false;
        say(err.message);
      });
    });
    return;
  }

  // ================================================================== the page of a file
  let cfg;
  try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (e) { return; }
  const id = String(cfg.id);
  const kinds = cfg.kinds;
  let summary = cfg.summary;
  const st = { kind: 'member', page: 1, bad: false, q: '', status: cfg.status === 'saved' ? 'saved' : 'pending', pages: 1, total: 0, busy: false, undo: [] };
  const fromHash = (window.location.hash || '').replace('#', '');
  if (kinds[fromHash]) st.kind = fromHash;

  const table = root.querySelector('[data-ci-table]');
  const tiles = root.querySelectorAll('[data-ci-tile]');
  const views = root.querySelectorAll('[data-ci-view]');
  const find = root.querySelector('[data-ci-find]');
  const badOnly = root.querySelector('[data-ci-bad]');
  const statusPick = root.querySelector('[data-ci-status]');
  const msg = root.querySelector('[data-ci-msg]');
  const run = root.querySelector('[data-ci-run]');
  const runBar = run.querySelector('.progress-bar');
  const general = root.querySelector('[data-ci-general]');
  const checkBtn = root.querySelector('[data-ci-check]');
  const saveBtn = root.querySelector('[data-ci-save]');
  const saveLabel = root.querySelector('[data-ci-savelabel]');
  const undoWrap = root.querySelector('[data-ci-undowrap]');
  const undoText = root.querySelector('[data-ci-undotext]');
  statusPick.value = st.status;

  // ------------------------------------------------------------------ the counts above the lines
  function paintSummary() {
    const s = summary;
    const saved = cfg.status === 'saved';
    tiles.forEach((t) => {
      const k = t.getAttribute('data-ci-tile');
      const x = s.kinds[k];
      const n = saved ? x.saved : x.lines;
      t.querySelector('[data-n]').textContent = num(n);
      const bad = t.querySelector('[data-bad]');
      bad.textContent = !saved && x.bad ? num(x.bad) + ' with a problem' : (!saved && x.unchecked ? num(x.unchecked) + ' to check' : (n ? (saved ? 'Saved' : 'No problem') : 'None in the file'));
      bad.className = 'ci-tile-sub' + (!saved && x.bad ? ' is-bad' : (!saved && x.unchecked ? ' is-wait' : (n && !saved ? ' is-ok' : '')));
      t.querySelector('[data-total]').textContent = k !== 'member' && !saved && x.lines ? (k === 'loan' ? 'Principal ' : 'Balances ') + fmt(x.total) : '';
      t.classList.toggle('is-bad', !saved && x.bad > 0);
      t.classList.toggle('is-on', k === st.kind);
      t.setAttribute('aria-pressed', k === st.kind ? 'true' : 'false');
    });
    views.forEach((v) => {
      const k = v.getAttribute('data-ci-view');
      const x = s.kinds[k];
      v.querySelector('[data-count]').textContent = '(' + num(st.status === 'removed' ? x.removed : (st.status === 'saved' ? x.saved : x.lines)) + ')';
      v.classList.toggle('is-on', k === st.kind);
      v.classList.toggle('is-bad', !saved && x.bad > 0);
      v.setAttribute('aria-selected', k === st.kind ? 'true' : 'false');
    });
    general.hidden = !s.general.length;
    general.innerHTML = s.general.map((g) => '<div><i class="bi bi-exclamation-triangle"></i> ' + esc(g) + '</div>').join('');
    if (st.busy) return;
    if (saved) {
      msg.innerHTML = '<span class="text-success"><i class="bi bi-check2-circle"></i> Saved: ' + num(s.kinds.member.saved) + ' member(s) with their lines.</span>';
    } else if (s.unchecked) {
      msg.innerHTML = '<span class="ci-wait"><i class="bi bi-hourglass-split"></i> ' + num(s.unchecked) + ' line(s) not checked yet.</span>';
    } else if (s.bad || s.general.length) {
      msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + (s.bad ? num(s.bad) + ' line(s) have a problem (in red): correct them or remove them.' : 'See the problem above the lines.') + ' Nothing is saved until every line is good.</span>';
    } else if (s.pending) {
      msg.innerHTML = '<span class="text-success"><i class="bi bi-check2-circle"></i> Every line is good: ' + num(s.kinds.member.lines) + ' member(s), ' + num(s.kinds.savings.lines) + ' savings account(s), '
        + num(s.kinds.loan.lines) + ' loan(s), ' + num(s.kinds.bill.lines) + ' bill balance(s).</span>';
    } else {
      msg.innerHTML = '<span class="text-muted">No line left to save.</span>';
    }
    if (saveBtn) {
      saveBtn.disabled = !cfg.canSave || !(s.ready || cfg.status === 'saving');
      const left = s.kinds.member.lines;
      saveLabel.innerHTML = cfg.status === 'saving' ? 'Go on saving (' + num(left) + ' left)' : 'Save ' + num(left) + ' member' + (left === 1 ? '' : 's') + '<span class="ci-save-long"> to the database</span>';
    }
    if (checkBtn) checkBtn.disabled = !s.pending;
  }

  // ------------------------------------------------------------------ the lines
  const cols = () => cfg.columns[st.kind] || [];
  const readOnly = () => !cfg.editable || st.status !== 'pending';

  function input(c, v, bad, ro) {
    if (ro) {
      // a line that cannot be changed: its value as text (a list shows the name of the value)
      v = v === undefined || v === null ? '' : String(v);
      let shown = v;
      if (c.type === 'select' && c.options) {
        const hit = c.options.find(([ov]) => ov.toLowerCase() === v.toLowerCase());
        if (hit) shown = hit[1];
      }
      return '<span class="ci-val' + (c.type === 'money' || c.type === 'num' ? ' is-num' : '') + (v === '' ? ' is-blank' : '') + '" aria-label="' + esc(c.label) + '">' + (v === '' ? '-' : esc(shown)) + '</span>';
    }
    const a = ' data-k="' + esc(c.key) + '" aria-label="' + esc(c.label) + '"' + (bad ? ' aria-invalid="true"' : '') + (ro ? ' disabled' : '') + (c.hint ? ' title="' + esc(c.hint) + '"' : '');
    v = v === undefined || v === null ? '' : String(v);
    if (c.type === 'select' && c.options) {
      let found = v === '';
      let o = '<option value=""></option>';
      c.options.forEach(([ov, ol]) => {
        const sel = ov.toLowerCase() === v.toLowerCase();
        found = found || sel;
        o += '<option value="' + esc(ov) + '"' + (sel ? ' selected' : '') + '>' + esc(ol) + '</option>';
      });
      if (!found) o += '<option value="' + esc(v) + '" selected>' + esc(v) + '</option>';
      return '<select class="form-select form-select-sm' + (bad ? ' is-invalid' : '') + '" data-no-combo' + a + '>' + o + '</select>';
    }
    const cls = 'form-control form-control-sm' + (c.type === 'money' || c.type === 'num' ? ' text-end' : '') + (bad ? ' is-invalid' : '');
    return '<input type="text" class="' + cls + '" value="' + esc(v) + '"' + (c.type === 'money' || c.type === 'num' ? ' inputmode="decimal"' : '')
      + (c.type === 'date' ? ' placeholder="YYYY-MM-DD"' : '') + a + ' autocomplete="off" spellcheck="false">';
  }

  function lineHtml(l) {
    const all = cols();
    const grid = all.filter((c) => !c.more);
    const more = all.filter((c) => c.more);
    const bad = new Set(l.cells || []);
    const ro = readOnly() || l.status !== 'pending';
    const state = l.status === 'saved' ? 'is-saved' : (l.status === 'removed' ? 'is-removed' : (!l.checked ? 'is-edited' : (l.errors.length ? 'is-bad' : 'is-ok')));
    const openMore = more.some((c) => bad.has(c.key));
    const span = grid.length + 1 + (st.kind === 'member' ? 0 : 1);
    let h = '<tbody class="csvr-rec ' + state + '" data-id="' + l.id + '" data-member="' + l.member_line + '">';
    h += '<tr class="csvr-main"><td class="csvr-no"><span class="csvr-dot" aria-hidden="true"></span>' + l.line + '<span class="ci-row">Row ' + l.row + '</span></td>';
    if (st.kind !== 'member') h += '<td class="ci-member" data-label="Member">' + esc(l.member) + '</td>';
    // a member on file: its details are on file, only its number (and its water account, fixed billing) are read
    const own = (c) => l.on_file && c.key !== 'member_no' && !/^(water_|fsc_)/.test(c.key);
    grid.forEach((c) => {
      h += '<td class="csvr-c-' + esc(c.size || 'md') + (own(c) && !l.values[c.key] ? ' ci-onfile' : '') + '" data-label="' + esc(c.label) + '">'
        + (own(c) && !l.values[c.key] ? '<span class="ci-onfile-text">On file</span>' : input(c, l.values[c.key], bad.has(c.key), ro)) + '</td>';
    });
    h += '<td class="csvr-act">';
    if (more.length) h += '<button type="button" class="csvr-btn' + (openMore ? ' is-on' : '') + '" data-ci-more aria-expanded="' + (openMore ? 'true' : 'false') + '" title="The other columns of the line"><i class="bi bi-three-dots"></i></button>';
    if (cfg.editable && l.status === 'pending') {
      h += '<button type="button" class="csvr-btn is-danger" data-ci-remove title="' + (st.kind === 'member' ? 'Remove the member with its savings, loans and bill balances' : 'Remove this line from the import') + '" aria-label="Remove line ' + l.line + '"><i class="bi bi-x-lg"></i></button>';
    } else if (cfg.editable && l.status === 'removed') {
      h += '<button type="button" class="btn btn-sm btn-outline-primary" data-ci-restore><i class="bi bi-arrow-counterclockwise"></i> Put back</button>';
    }
    h += '</td></tr>';
    if (more.length) {
      h += '<tr class="csvr-more"' + (openMore ? '' : ' hidden') + '><td></td><td colspan="' + span + '"><div class="csvr-more-grid">';
      more.filter((c) => !(own(c) && !l.values[c.key])).forEach((c) => { h += '<label><span' + (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + (c.req ? ' *' : '') + '</span>' + input(c, l.values[c.key], bad.has(c.key), ro) + '</label>'; });
      h += '</div></td></tr>';
    }
    const parts = [];
    if (l.status === 'saved' && l.saved_ref) parts.push('<div class="ci-saved"><i class="bi bi-check2"></i> Saved: ' + esc(l.saved_ref) + '</div>');
    if (l.info) parts.push('<div class="csvr-info">' + l.info + '</div>');
    (l.errors || []).forEach((e) => parts.push('<div class="csvr-err"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' + esc(e) + '</div>'));
    (l.notes || []).forEach((e) => parts.push('<div class="csvr-note"><i class="bi bi-info-circle" aria-hidden="true"></i> ' + esc(e) + '</div>'));
    if (!l.checked && l.status === 'pending') parts.push('<div class="ci-wait"><i class="bi bi-hourglass-split"></i> Not checked yet.</div>');
    if (parts.length) h += '<tr class="csvr-msg"><td></td><td colspan="' + span + '"><div>' + parts.join('') + '</div></td></tr>';
    return h + '</tbody>';
  }

  function head() {
    let h = '<thead><tr><th class="csvr-no">Line</th>' + (st.kind !== 'member' ? '<th class="ci-member">Member</th>' : '');
    cols().filter((c) => !c.more).forEach((c) => { h += '<th class="csvr-c-' + esc(c.size || 'md') + '"' + (c.hint ? ' title="' + esc(c.hint) + '"' : '') + '>' + esc(c.label) + (c.req ? ' <span class="csvr-req" title="Required">*</span>' : '') + '</th>'; });
    return h + '<th class="csvr-act"><span class="visually-hidden">Actions</span></th></tr></thead>';
  }

  let loading = 0;
  function load() {
    const ticket = ++loading;
    table.setAttribute('aria-busy', 'true');
    return call('GET', { a: 'lines', id: id, kind: st.kind, page: String(st.page), bad: st.bad ? '1' : '0', q: st.q, status: st.status }).then((j) => {
      if (ticket !== loading) return;
      st.page = j.page;
      st.pages = j.pages;
      st.total = j.total;
      const empty = '<tbody class="csvr-none"><tr><td colspan="' + (cols().filter((c) => !c.more).length + 3) + '">'
        + (st.q || st.bad ? 'No line matches.' : (st.status === 'removed' ? 'No line removed.' : 'No ' + esc(kinds[st.kind].many) + ' in this file.')) + '</td></tr></tbody>';
      table.innerHTML = head() + (j.lines.length ? j.lines.map(lineHtml).join('') : empty);
      const from = j.total ? (j.page - 1) * cfg.pageLines + 1 : 0;
      root.querySelector('[data-ci-range]').textContent = j.total ? 'Lines ' + num(from) + ' to ' + num(from + j.lines.length - 1) + ' of ' + num(j.total) : '';
      root.querySelector('[data-ci-page]').textContent = 'Page ' + num(j.page) + ' of ' + num(j.pages);
      root.querySelector('[data-ci-prev]').disabled = j.page <= 1;
      root.querySelector('[data-ci-next]').disabled = j.page >= j.pages;
      table.removeAttribute('aria-busy');
      paintSummary();
    }).catch((e) => { table.removeAttribute('aria-busy'); say(e.message); });
  }

  /** Lines checked again on the server: those on the page are drawn again */
  function refresh(lines) {
    (lines || []).forEach((l) => {
      if (l.kind !== st.kind) return;
      const old = table.querySelector('tbody[data-id="' + l.id + '"]');
      if (!old) return;
      if (l.status !== st.status) { old.remove(); return; }
      const tmp = document.createElement('table');
      tmp.innerHTML = lineHtml(l);
      old.replaceWith(tmp.querySelector('tbody'));
    });
  }

  // ------------------------------------------------------------------ correcting and removing
  table.addEventListener('input', (e) => {
    const rec = e.target.closest('tbody.csvr-rec');
    if (rec && e.target.matches('[data-k]')) rec.classList.add('is-edited');
  });
  table.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('input[data-k]')) { e.preventDefault(); e.target.blur(); }
  });
  table.addEventListener('change', (e) => {
    const el = e.target;
    if (!el.matches('[data-k]')) return;
    const rec = el.closest('tbody.csvr-rec');
    rec.classList.add('is-edited', 'is-saving');
    call('POST', { a: 'edit', id: id, line: rec.getAttribute('data-id'), field: el.getAttribute('data-k'), value: el.value }).then((j) => {
      summary = j.summary;
      refresh(j.lines);
      paintSummary();
    }).catch((err) => { rec.classList.remove('is-saving'); say(err.message); });
  });
  table.addEventListener('click', (e) => {
    const more = e.target.closest('[data-ci-more]');
    if (more) {
      const row = more.closest('tbody').querySelector('.csvr-more');
      row.hidden = !row.hidden;
      more.classList.toggle('is-on', !row.hidden);
      more.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
      return;
    }
    const rm = e.target.closest('[data-ci-remove]');
    if (rm) {
      const rec = rm.closest('tbody.csvr-rec');
      rm.disabled = true;
      call('POST', { a: 'remove', id: id, line: rec.getAttribute('data-id') }).then((j) => {
        summary = j.summary;
        j.removed.forEach((x) => { const t = table.querySelector('tbody[data-id="' + x + '"]'); if (t) t.remove(); });
        refresh(j.lines);
        st.undo.push(j.removed);
        paintUndo();
        paintSummary();
      }).catch((err) => { rm.disabled = false; say(err.message); });
      return;
    }
    const back = e.target.closest('[data-ci-restore]');
    if (back) {
      back.disabled = true;
      call('POST', { a: 'restore', id: id, lines: back.closest('tbody').getAttribute('data-id') }).then((j) => {
        summary = j.summary;
        load();
      }).catch((err) => { back.disabled = false; say(err.message); });
    }
  });
  function paintUndo() {
    const n = st.undo.length;
    undoWrap.hidden = !n || st.status !== 'pending';
    if (n) {
      const last = st.undo[n - 1];
      undoText.textContent = last.length === 1 ? '1 line removed.' : last.length + ' lines removed.';
    }
  }
  root.querySelector('[data-ci-undo]').addEventListener('click', () => {
    const last = st.undo.pop();
    if (!last) return;
    call('POST', { a: 'restore', id: id, lines: last.join(',') }).then((j) => {
      summary = j.summary;
      paintUndo();
      load();
    }).catch((err) => { st.undo.push(last); say(err.message); });
  });

  // ------------------------------------------------------------------ choosing what to show
  const pick = (k) => {
    if (!kinds[k]) return;
    st.kind = k;
    st.page = 1;
    try { history.replaceState(null, '', '#' + k); } catch (e) { /* the address stays */ }
    load();
  };
  tiles.forEach((t) => t.addEventListener('click', () => { pick(t.getAttribute('data-ci-tile')); root.querySelector('[data-ci-review]').scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
  views.forEach((v) => v.addEventListener('click', () => pick(v.getAttribute('data-ci-view'))));
  window.addEventListener('hashchange', () => { const k = (window.location.hash || '').replace('#', ''); if (kinds[k] && k !== st.kind) pick(k); });
  let timer = null;
  find.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { st.q = find.value.trim(); st.page = 1; load(); }, 350); });
  badOnly.addEventListener('change', () => { st.bad = badOnly.checked; st.page = 1; load(); });
  statusPick.addEventListener('change', () => { st.status = statusPick.value; st.page = 1; paintUndo(); load(); });
  root.querySelector('[data-ci-prev]').addEventListener('click', () => { if (st.page > 1) { st.page--; load(); } });
  root.querySelector('[data-ci-next]').addEventListener('click', () => { if (st.page < st.pages) { st.page++; load(); } });

  // ------------------------------------------------------------------ checking and saving in steps
  function busy(on, text, pct) {
    st.busy = on;
    run.hidden = !on;
    if (on) {
      runBar.style.width = Math.max(3, Math.min(100, pct || 0)) + '%';
      msg.innerHTML = '<span class="ci-wait"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(text) + '</span>';
    }
    [checkBtn, saveBtn].forEach((b) => { if (b) b.disabled = on; });
    root.classList.toggle('is-running', on);
  }

  function checkAll() {
    if (st.busy) return Promise.resolve();
    busy(true, 'Checking the lines', 2);
    const step = () => call('POST', { a: 'check', id: id }).then((j) => {
      summary = j.summary;
      const pct = j.members ? (j.checked / j.members) * 100 : 100;
      busy(true, 'Checking the lines: ' + num(j.checked) + ' of ' + num(j.members) + ' member(s)', pct);
      return j.done ? null : step();
    });
    return step().then(() => { busy(false); return load(); }).catch((e) => { busy(false); paintSummary(); say(e.message); });
  }

  function saveAll() {
    if (st.busy) return;
    const k = summary.kinds;
    ask('Save the file to the database? ' + num(k.member.lines) + ' member(s) with ' + num(k.savings.lines) + ' savings account(s), ' + num(k.loan.lines) + ' loan(s) and '
      + num(k.bill.lines) + ' bill balance(s) are saved, a few members at a time, each line checked again first. Keep this page open until it ends: if it stops, saving goes on from where it stopped.', 'Save')
      .then((yes) => {
        if (!yes) return;
        busy(true, 'Saving', 2);
        const step = () => call('POST', { a: 'save', id: id }).then((j) => {
          summary = j.summary;
          const pct = j.members ? (j.saved / j.members) * 100 : 100;
          busy(true, 'Saving: ' + num(j.saved) + ' of ' + num(j.members) + ' member(s) saved', pct);
          return j.done ? true : step();
        });
        step().then(() => { window.location.reload(); }).catch((e) => {
          busy(false);
          cfg.status = 'saving';
          call('GET', { a: 'summary', id: id }).then((j) => { summary = j.summary; load(); }).catch(() => load());
          say(e.message);
        });
      });
  }
  if (checkBtn) checkBtn.addEventListener('click', () => {
    // every line again (the values of the page may have changed the checks of lines already checked)
    checkAll();
  });
  if (saveBtn) saveBtn.addEventListener('click', saveAll);

  // the column choices: the number of the account only for the columns that repeat
  root.querySelectorAll('[data-ci-mapfield]').forEach((sel) => {
    sel.addEventListener('change', () => {
      const opt = sel.options[sel.selectedIndex];
      const row = sel.closest('.ci-map-row');
      row.querySelector('[data-ci-mapslot]').hidden = !(opt && opt.getAttribute('data-rep') === '1');
      row.classList.toggle('is-off', sel.value === '');
    });
  });

  // the first tab: the kind with a problem, else the members
  if (!fromHash && summary.bad) {
    const first = Object.keys(kinds).find((k) => summary.kinds[k].bad > 0);
    if (first) st.kind = first;
  }
  paintSummary();
  load().then(() => {
    if (cfg.editable && summary.unchecked > 0) checkAll();
  });
})();
