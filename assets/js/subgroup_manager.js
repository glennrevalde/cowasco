/* =====================================================================
   Address sub-groups: add, rename, sort, switch off and delete them where they are chosen.

   A button with data-subgroup-manage beside an Address sub-group box opens the list in a window; an element with
   data-subgroup-manager is the list itself, on a page of its own (Water Billing > Address Sub-groups). Both talk to
   water/process/addressSubgroups.php (data-endpoint), with the session's token (data-csrf). After every change the
   Address sub-group boxes of the page take the new list at once, keeping what each one shows (a renamed entry shows
   its new name). The boxes are select[name="address_subgroup"], select[name="bulk_address_subgroup"] and any select
   with data-subgroup-select; the options with an empty value, "none" or "__none__" stay as they are.
   A box wrapped in data-subgroup-inline (the member form, version 57) also adds a new sub-group and renames the chosen
   one in place, without the window.
   No framework; the same on the cooperative pages and the water pages.
   ===================================================================== */
(function () {
  'use strict';
  if (window.SubgroupManager) return;

  const CSS = ''
    + '.sgm-back{position:fixed;inset:0;z-index:1990;background:rgba(15,23,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:6vh 14px 14px;overflow:auto}'
    + '.sgm{background:#fff;color:#111827;width:min(760px,100%);border-radius:12px;box-shadow:0 24px 60px rgba(15,23,42,.28);font-size:14px;line-height:1.45;overflow:hidden}'
    + '.sgm-inline{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);font-size:14px;line-height:1.45;overflow:hidden}'
    + '.sgm-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #e5e7eb}'
    + '.sgm-head h2{margin:0;font-size:17px;font-weight:700;flex:1;letter-spacing:-.01em}'
    + '.sgm-x{border:0;background:transparent;font-size:22px;line-height:1;color:#6b7280;cursor:pointer;padding:4px 8px;border-radius:6px}'
    + '.sgm-x:hover{background:#f3f4f6;color:#111827}'
    + '.sgm-body{padding:16px 20px 20px}'
    + '.sgm-help{margin:0 0 14px;color:#4b5563;font-size:13px}'
    + '.sgm-add{display:grid;grid-template-columns:minmax(0,1fr) 96px auto;gap:10px;align-items:end;padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;margin-bottom:14px}'
    + '.sgm label.sgm-l{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}'
    + '.sgm-in{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font:inherit;background:#fff;color:#111827}'
    + '.sgm-in:focus{outline:2px solid rgba(37,99,235,.35);outline-offset:0;border-color:#2563eb}'
    + '.sgm-btn{border:1px solid #d1d5db;background:#fff;color:#111827;border-radius:7px;padding:8px 13px;font:inherit;font-weight:600;cursor:pointer;white-space:nowrap}'
    + '.sgm-btn:hover{background:#f3f4f6}'
    + '.sgm-btn.is-primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}'
    + '.sgm-btn.is-primary:hover{background:#1e40af}'
    + '.sgm-btn.is-danger{color:#b91c1c;border-color:#fecaca}'
    + '.sgm-btn.is-danger:hover{background:#fef2f2}'
    + '.sgm-btn[disabled]{opacity:.5;cursor:not-allowed}'
    + '.sgm-bar{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}'
    + '.sgm-bar .sgm-in{max-width:280px}'
    + '.sgm-count{margin-left:auto;color:#6b7280;font-size:12.5px}'
    + '.sgm-msg{display:none;margin-bottom:12px;padding:10px 12px;border-radius:8px;font-size:13px}'
    + '.sgm-msg.is-ok{display:block;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}'
    + '.sgm-msg.is-bad{display:block;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}'
    + '.sgm-table{width:100%;min-width:0;max-width:100%;border-collapse:collapse;table-layout:auto}'
    + '.sgm-table th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:700;padding:8px 10px;border-bottom:1px solid #e5e7eb;background:#f9fafb}'
    + '.sgm-table td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}'
    + '.sgm-table tr.is-off td.sgm-name{color:#9ca3af}'
    + '.sgm-num{text-align:right;font-variant-numeric:tabular-nums}'
    + '.sgm-acts{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}'
    + '.sgm-state{font-size:12.5px;font-weight:600}'
    + '.sgm-state.is-on{color:#047857}.sgm-state.is-off{color:#9ca3af}'
    + '.sgm-used{color:#4b5563;font-size:12.5px}'
    + '.sgm-empty{padding:20px;text-align:center;color:#6b7280}'
    + '.sgm-wrap{max-height:52vh;overflow:auto;border:1px solid #e5e7eb;border-radius:10px}'
    + '.sgm-inline .sgm-wrap{max-height:none}'
    + '.sgm-check{display:inline-flex;align-items:center;gap:6px;font-size:13px}'
    + '.sgm-manage{display:inline-flex;align-items:center;gap:5px;margin-top:6px;border:0;background:transparent;color:#1d4ed8;font-size:12.5px;font-weight:600;padding:2px 0;cursor:pointer}'
    + '.sgm-manage:hover{text-decoration:underline}'
    + '.sgi-pick>select{flex:1 1 auto;width:1%;min-width:0}.sgi-btn{flex:0 0 auto}.sgi-edit>input{min-width:0}'
    + '.sgi-msg{margin-top:4px;font-size:12.5px;line-height:1.35}.sgi-msg.is-ok{color:#047857}.sgi-msg.is-bad{color:#b91c1c}'
    + '@media (max-width:640px){.sgm-add{grid-template-columns:1fr 1fr}.sgm-add .sgm-addname{grid-column:1/-1}.sgm-table thead{display:none}'
    + '.sgm-table,.sgm-table tbody,.sgm-table tr,.sgm-table td{display:block;width:100%}.sgm-table tr{border-bottom:1px solid #e5e7eb;padding:8px 0}'
    + '.sgm-table td{border:0;padding:3px 12px;text-align:left}.sgm-table td[data-label]{display:flex;align-items:center;justify-content:space-between;gap:12px}'
    + '.sgm-table td[data-label]::before{content:attr(data-label);flex:0 0 auto;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}'
    + '.sgm-table td.sgm-name{display:block;padding-bottom:4px}.sgm-table td.sgm-name::before{display:none}.sgm-table td.sgm-name strong{font-size:14px}'
    + '.sgm-table td[data-label] input.sgm-in{max-width:62%}.sgm-table td:last-child{padding-top:8px}'
    + '.sgm-num{text-align:left}.sgm-acts{justify-content:flex-start}.sgm-head,.sgm-body{padding-left:14px;padding-right:14px}}';

  function addStyle() {
    if (document.getElementById('sgm-style')) return;
    const s = document.createElement('style');
    s.id = 'sgm-style';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));
  const KEEP = ['', 'none', '__none__'];

  function token(el) {
    const own = el && el.dataset && el.dataset.csrf;
    if (own) return own;
    const f = document.querySelector('input[name="_csrf"], input[name="csrf_token"]');
    if (f && f.value) return f.value;
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') || '' : '';
  }
  function ask(text, ok) {
    if (window.AppDialog && typeof window.AppDialog.confirm === 'function') {
      return window.AppDialog.confirm(text, { title: 'Address sub-groups', ok: ok || 'Yes, continue', tone: 'danger' });
    }
    return Promise.resolve(window.confirm(text));
  }

  /** The Address sub-group boxes of the page take the active names, keeping each one's choice ($renamed: old => new) */
  function refreshSelects(rows, renamed) {
    const names = rows.filter((r) => r.is_active).map((r) => r.name);
    const boxes = document.querySelectorAll('select[name="address_subgroup"], select[name="bulk_address_subgroup"], select[data-subgroup-select]');
    boxes.forEach((sel) => {
      let current = sel.value;
      if (renamed && Object.prototype.hasOwnProperty.call(renamed, current)) current = renamed[current];
      const keep = Array.from(sel.options).filter((o) => KEEP.includes(o.value) || o.hasAttribute('data-keep'));
      Array.from(sel.options).forEach((o) => { if (!keep.includes(o)) o.remove(); });
      names.forEach((n) => { const o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
      if (current !== '' && !KEEP.includes(current) && !names.includes(current)) {
        // a name the record has that is no longer offered stays shown as it is (as the forms show it)
        const o = document.createElement('option'); o.value = current; o.textContent = current + ' (not on the list)'; sel.appendChild(o);
      }
      sel.value = current;
      if (typeof sel._dsSync === 'function') { try { sel._dsSync(); } catch (e) { /* the box keeps its text */ } }
      // the searchable box of the cooperative pages (assets/js/app.js) shows the renamed entry too
      if (typeof sel.comboSync === 'function') { try { sel.comboSync(); } catch (e) { /* the box keeps its text */ } }
    });
  }

  function Manager(host, opts) {
    this.host = host;
    this.opts = opts;
    this.rows = [];
    this.can = { view: true, create: false, edit: false, delete: false };
    this.editing = 0;
  }
  Manager.prototype.html = function () {
    return '<div class="sgm-body">'
      + '<p class="sgm-help">Address sub-groups split an area into smaller parts (a purok, a sitio, a zone). The member form and the water-service accounts choose from this one list. A new name is carried to every member and account that has the old one; a sub-group in use cannot be deleted, but it can be switched off so it is no longer offered.</p>'
      + '<div class="sgm-msg" role="status" aria-live="polite" data-msg></div>'
      + '<form class="sgm-add" data-add hidden><div class="sgm-addname"><label class="sgm-l" for="sgmNew' + this.opts.uid + '">New sub-group</label><input class="sgm-in" id="sgmNew' + this.opts.uid + '" maxlength="60" placeholder="e.g. Purok 3, Sitio Caimito" autocomplete="off" required></div>'
      + '<div><label class="sgm-l" for="sgmOrd' + this.opts.uid + '">Order</label><input class="sgm-in sgm-num" id="sgmOrd' + this.opts.uid + '" type="number" step="1" value="0"></div>'
      + '<button class="sgm-btn is-primary" type="submit">Add</button></form>'
      + '<div class="sgm-bar"><label class="sgm-l" for="sgmFind' + this.opts.uid + '" style="margin:0">Find</label><input class="sgm-in" id="sgmFind' + this.opts.uid + '" type="search" placeholder="Type to narrow the list" autocomplete="off" data-find><span class="sgm-count" data-count></span></div>'
      + '<div class="sgm-wrap"><table class="sgm-table"><thead><tr><th>Name</th><th class="sgm-num">Order</th><th>Offered</th><th>Used by</th><th></th></tr></thead><tbody data-rows><tr><td colspan="5" class="sgm-empty">Loading the list ...</td></tr></tbody></table></div>'
      + '</div>';
  };
  Manager.prototype.say = function (text, ok) {
    const m = this.host.querySelector('[data-msg]');
    m.className = 'sgm-msg ' + (ok ? 'is-ok' : 'is-bad');
    m.textContent = text;
  };
  Manager.prototype.used = function (r) {
    const parts = [];
    if (r.members) parts.push(r.members + ' member' + (r.members === 1 ? '' : 's'));
    if (r.accounts) parts.push(r.accounts + ' account' + (r.accounts === 1 ? '' : 's'));
    return parts.length ? parts.join(', ') : 'Not used';
  };
  Manager.prototype.render = function () {
    const body = this.host.querySelector('[data-rows]');
    const find = (this.host.querySelector('[data-find]').value || '').trim().toLowerCase();
    const words = find.split(/\s+/).filter(Boolean);
    const shown = this.rows.filter((r) => !words.length || words.every((w) => r.name.toLowerCase().split(/[\s,.\-\/]+/).some((p) => p.startsWith(w)) || r.name.toLowerCase().includes(w)));
    this.host.querySelector('[data-count]').textContent = (words.length ? shown.length + ' of ' : '') + this.rows.length + ' sub-group' + (this.rows.length === 1 ? '' : 's');
    this.host.querySelector('[data-add]').hidden = !this.can.create;
    if (!shown.length) {
      body.innerHTML = '<tr><td colspan="5" class="sgm-empty">' + (this.rows.length ? 'No sub-group matches what was typed.' : 'No address sub-group yet.' + (this.can.create ? ' Add the first one above.' : '')) + '</td></tr>';
      return;
    }
    body.innerHTML = shown.map((r) => {
      if (this.editing === r.id) {
        return '<tr data-id="' + r.id + '"><td data-label="Name"><input class="sgm-in" data-e="name" maxlength="60" value="' + esc(r.name) + '" aria-label="Name"></td>'
          + '<td class="sgm-num" data-label="Order"><input class="sgm-in sgm-num" data-e="sort" type="number" step="1" value="' + esc(r.sort_order) + '" aria-label="Order" style="max-width:90px"></td>'
          + '<td data-label="Offered"><label class="sgm-check"><input type="checkbox" data-e="active"' + (r.is_active ? ' checked' : '') + '> Offered</label></td>'
          + '<td data-label="Used by" class="sgm-used">' + esc(this.used(r)) + '</td>'
          + '<td><div class="sgm-acts"><button type="button" class="sgm-btn is-primary" data-act="save">Save</button><button type="button" class="sgm-btn" data-act="cancel">Cancel</button></div></td></tr>';
      }
      return '<tr data-id="' + r.id + '"' + (r.is_active ? '' : ' class="is-off"') + '><td class="sgm-name" data-label="Name"><strong>' + esc(r.name) + '</strong></td>'
        + '<td class="sgm-num" data-label="Order">' + esc(r.sort_order) + '</td>'
        + '<td data-label="Offered"><span class="sgm-state ' + (r.is_active ? 'is-on">Yes' : 'is-off">Switched off') + '</span></td>'
        + '<td data-label="Used by" class="sgm-used">' + esc(this.used(r)) + '</td>'
        + '<td><div class="sgm-acts">' + (this.can.edit ? '<button type="button" class="sgm-btn" data-act="edit">Edit</button>' : '')
        + (this.can.delete ? '<button type="button" class="sgm-btn is-danger" data-act="delete"' + (r.members || r.accounts ? ' title="In use: switch it off instead, or move its members and accounts first"' : '') + '>Delete</button>' : '') + '</div></td></tr>';
    }).join('');
  };
  Manager.prototype.call = function (payload) {
    const opts = { method: payload ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (payload) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(Object.assign({ csrf_token: token(this.opts.trigger || this.host), page_key: this.opts.pageKey || '' }, payload));
    }
    return fetch(this.opts.endpoint + (payload ? '' : '?action=list'), opts).then((r) => r.text().then((t) => {
      let d = null;
      try { d = JSON.parse(t); } catch (e) { throw new Error('The address sub-groups could not be loaded. Sign in again if your session ended.'); }
      if (d && Array.isArray(d.rows)) this.rows = d.rows;
      if (d && d.can) this.can = d.can;
      if (!d || !d.success) throw new Error((d && d.error) || 'The address sub-groups could not be saved.');
      return d;
    }));
  };
  Manager.prototype.load = function () {
    return this.call(null).then(() => { this.render(); }).catch((e) => { this.say(e.message, false); this.render(); });
  };
  Manager.prototype.bind = function () {
    const host = this.host;
    host.querySelector('[data-find]').addEventListener('input', () => this.render());
    host.querySelector('[data-add]').addEventListener('submit', (e) => {
      e.preventDefault();
      const name = host.querySelector('#sgmNew' + this.opts.uid);
      const ord = host.querySelector('#sgmOrd' + this.opts.uid);
      this.call({ action: 'save', name: name.value, sort_order: parseInt(ord.value || '0', 10) || 0, is_active: true }).then((d) => {
        this.say(d.message, true); name.value = ''; this.render(); refreshSelects(this.rows, null); name.focus();
      }).catch((err) => { this.say(err.message, false); this.render(); });
    });
    host.addEventListener('click', (e) => {
      const b = e.target.closest('[data-act]');
      if (!b || !host.contains(b)) return;
      const tr = b.closest('tr[data-id]');
      const id = tr ? parseInt(tr.dataset.id, 10) : 0;
      const row = this.rows.find((r) => r.id === id);
      if (!row) return;
      if (b.dataset.act === 'edit') { this.editing = id; this.render(); const f = host.querySelector('tr[data-id="' + id + '"] [data-e="name"]'); if (f) { f.focus(); f.select(); } return; }
      if (b.dataset.act === 'cancel') { this.editing = 0; this.render(); return; }
      if (b.dataset.act === 'save') {
        const name = tr.querySelector('[data-e="name"]').value;
        const sort = parseInt(tr.querySelector('[data-e="sort"]').value || '0', 10) || 0;
        const active = tr.querySelector('[data-e="active"]').checked;
        const oldName = row.name;
        this.call({ action: 'save', id, name, sort_order: sort, is_active: active }).then((d) => {
          this.editing = 0; this.say(d.message, true); this.render();
          const renamed = {};
          const now = this.rows.find((r) => r.id === id);
          if (now && now.name !== oldName) renamed[oldName] = now.name;
          refreshSelects(this.rows, renamed);
        }).catch((err) => { this.say(err.message, false); this.render(); });
        return;
      }
      if (b.dataset.act === 'delete') {
        ask('Delete the address sub-group "' + row.name + '"?', 'Delete').then((yes) => {
          if (!yes) return;
          this.call({ action: 'delete', id }).then((d) => { this.say(d.message, true); this.render(); refreshSelects(this.rows, null); })
            .catch((err) => { this.say(err.message, false); this.render(); });
        });
      }
    });
    host.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.closest('tr[data-id] [data-e]')) {
        e.preventDefault();
        const save = e.target.closest('tr').querySelector('[data-act="save"]');
        if (save) save.click();
      }
      if (e.key === 'Escape' && this.editing) { e.stopPropagation(); this.editing = 0; this.render(); }
    });
  };

  let uid = 0;
  function endpointOf(el) {
    return (el && el.dataset.endpoint) || window.SUBGROUP_ENDPOINT || '';
  }

  /** The list in a window, opened by a Manage button */
  function open(trigger) {
    addStyle();
    const back = document.createElement('div');
    back.className = 'sgm-back';
    const box = document.createElement('div');
    box.className = 'sgm';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'sgmTitle' + (++uid));
    const mgr = new Manager(box, { uid, endpoint: endpointOf(trigger), pageKey: trigger.dataset.pageKey || '', trigger });
    box.innerHTML = '<div class="sgm-head"><h2 id="sgmTitle' + uid + '">Address sub-groups</h2><button type="button" class="sgm-x" aria-label="Close" data-close>&times;</button></div>' + mgr.html();
    back.appendChild(box);
    document.body.appendChild(back);
    const before = document.activeElement;
    const close = () => { back.remove(); document.removeEventListener('keydown', onKey, true); if (before && before.focus) { try { before.focus({ preventScroll: true }); } catch (e) { /* ignore */ } } };
    const onKey = (e) => { if (e.key === 'Escape' && !mgr.editing) { e.preventDefault(); close(); } };
    document.addEventListener('keydown', onKey, true);
    back.addEventListener('mousedown', (e) => { if (e.target === back) close(); });
    box.querySelector('[data-close]').addEventListener('click', close);
    mgr.bind();
    mgr.load().then(() => { const f = box.querySelector('#sgmNew' + uid); (f && !box.querySelector('[data-add]').hidden ? f : box.querySelector('[data-find]')).focus(); });
    return mgr;
  }

  /** The list on a page of its own */
  function mount(el) {
    if (el.dataset.sgmMounted) return null;
    el.dataset.sgmMounted = '1';
    addStyle();
    el.classList.add('sgm-inline');
    const mgr = new Manager(el, { uid: ++uid, endpoint: endpointOf(el), pageKey: el.dataset.pageKey || '' });
    el.innerHTML = mgr.html();
    mgr.bind();
    mgr.load();
    return mgr;
  }

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-subgroup-manage]');
    if (!t) return;
    e.preventDefault();
    open(t);
  });

  /**
   * The box itself (version 57): [data-subgroup-inline] holds the Address sub-group select with a Rename button
   * (data-sgi="edit": the chosen sub-group renamed in place, carried to every member and water-service account that
   * has it) and an Add button (data-sgi="new": a new sub-group typed in place, added to the list and chosen); Save or
   * Enter keeps it, Cancel or Escape puts the select back. The other boxes of the page take the list at once.
   */
  function inline(wrap) {
    if (wrap.dataset.sgiReady) return;
    wrap.dataset.sgiReady = '1';
    const pick = wrap.querySelector('.sgi-pick');
    const edit = wrap.querySelector('.sgi-edit');
    const sel = pick ? pick.querySelector('select') : null;
    const input = wrap.querySelector('[data-sgi-name]');
    const save = wrap.querySelector('[data-sgi="save"]');
    const msg = wrap.querySelector('.sgi-msg');
    if (!sel || !edit || !input || !save) return;
    const mgr = new Manager(wrap, { uid: ++uid, endpoint: endpointOf(wrap), pageKey: wrap.dataset.pageKey || '', trigger: wrap });
    let mode = '';
    let row = null;
    const say = (text, ok) => {
      msg.hidden = !text;
      msg.className = 'sgi-msg ' + (ok ? 'is-ok' : 'is-bad');
      msg.textContent = text || '';
    };
    const close = () => { mode = ''; row = null; edit.hidden = true; pick.hidden = false; save.disabled = false; };
    const begin = (m) => {
      say('', true);
      const current = sel.value;
      const go = () => {
        mode = m;
        input.value = m === 'edit' ? row.name : '';
        input.placeholder = m === 'edit' ? '' : 'New sub-group, e.g. Purok 3';
        save.textContent = m === 'edit' ? 'Rename' : 'Add';
        pick.hidden = true;
        edit.hidden = false;
        input.focus();
        if (m === 'edit') input.select();
      };
      if (m === 'new') { row = null; go(); return; }
      if (current === '' || KEEP.includes(current)) { say('Choose the sub-group to rename first.', false); return; }
      mgr.call(null).then(() => {
        row = mgr.rows.find((r) => r.name === current) || null;
        if (!row) { say('"' + current + '" is not on the list: add it with +.', false); return; }
        go();
      }).catch((err) => say(err.message, false));
    };
    const keep = () => {
      const name = input.value.trim();
      if (name === '') { say('Type the name of the sub-group.', false); input.focus(); return; }
      save.disabled = true;
      const payload = mode === 'edit'
        ? { action: 'save', id: row.id, name, sort_order: row.sort_order, is_active: !!row.is_active }
        : { action: 'save', name, sort_order: 0, is_active: true };
      const oldName = row ? row.name : null;
      const wasEdit = mode === 'edit';
      (wasEdit ? Promise.resolve() : mgr.call(null)).then(() => {
        if (!wasEdit) {
          // a new one goes to the end of the list
          payload.sort_order = mgr.rows.reduce((m, r) => Math.max(m, parseInt(r.sort_order, 10) || 0), 0) + 10;
        }
        return mgr.call(payload);
      }).then((d) => {
        const renamed = {};
        const now = wasEdit ? mgr.rows.find((r) => r.id === row.id) : mgr.rows.find((r) => r.name.toLowerCase() === name.toLowerCase());
        if (wasEdit && now && now.name !== oldName) renamed[oldName] = now.name;
        refreshSelects(mgr.rows, renamed);
        if (!wasEdit && now) { sel.value = now.name; sel.dispatchEvent(new Event('change', { bubbles: true })); }
        close();
        say(d.message || (wasEdit ? 'Renamed.' : 'Added.'), true);
        sel.focus();
      }).catch((err) => { save.disabled = false; say(err.message, false); input.focus(); });
    };
    wrap.addEventListener('click', (e) => {
      const b = e.target.closest('[data-sgi]');
      if (!b || !wrap.contains(b)) return;
      e.preventDefault();
      if (b.dataset.sgi === 'edit' || b.dataset.sgi === 'new') begin(b.dataset.sgi);
      else if (b.dataset.sgi === 'save') keep();
      else if (b.dataset.sgi === 'cancel') { close(); say('', true); sel.focus(); }
    });
    input.addEventListener('keydown', (e) => {
      // Enter keeps the name (the member form is not sent); Escape leaves it
      if (e.key === 'Enter') { e.preventDefault(); keep(); }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); say('', true); sel.focus(); }
    });
    sel.addEventListener('change', () => say('', true));
  }
  function start() {
    addStyle();
    document.querySelectorAll('[data-subgroup-manager]').forEach(mount);
    document.querySelectorAll('[data-subgroup-inline]').forEach(inline);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  window.SubgroupManager = { open, mount, inline, refreshSelects };
})();
