/*
 * The review of an import of Water Billing (water/common/importReviewHelpers.php): the file is read on the server,
 * every row is shown with its problems (a row with a problem in red), a cell is corrected by typing in it (the rows
 * are checked again as you type), a row is removed with its x (and put back with Undo), and the rows are posted only
 * when none has a problem: every row or none.
 *
 * window.IMPORT_REVIEW = {endpoint, csrf, token, cols: [{key, label, type, size, req}], noun: [one, many],
 *   total: column key added up, totalLabel, postLabel, confirm, defaults: form selector, canPost}
 */
(function () {
    'use strict';
    const cfg = window.IMPORT_REVIEW;
    if (!cfg) return;
    const $ = (sel, root) => (root || document).querySelector(sel);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const money = v => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const state = {rows: [], removed: [], file: '', checking: 0, timer: null, edited: false, general: [], postError: '', seq: 0};
    // the program's own question window (assets/js/dialog.js), the browser's only where it is missing
    const ask = (text, opts) => window.AppDialog ? window.AppDialog.confirm(text, opts || {}) : Promise.resolve(window.confirm(text));

    const els = {
        start: $('#irStart'), review: $('#irReview'), readForm: $('#irReadForm'), readBtn: $('#irReadBtn'), readMsg: $('#irReadMsg'),
        table: $('#irTable'), find: $('#irFind'), badOnly: $('#irBadOnly'), removeBad: $('#irRemoveBad'),
        n: $('#irN'), bad: $('#irBad'), badWrap: $('#irBadWrap'), total: $('#irTotal'), removedWrap: $('#irRemovedWrap'), removedN: $('#irRemovedN'),
        undo: $('#irUndo'), status: $('#irStatus'), post: $('#irPost'), postN: $('#irPostN'), recheck: $('#irRecheck'), restart: $('#irRestart'),
        general: $('#irGeneral'), fileName: $('#irFileName'), checking: $('#irChecking'), none: $('#irNone'),
    };

    function defaults() {
        const out = {};
        const form = cfg.defaults ? $(cfg.defaults) : null;
        if (form) new FormData(form).forEach((v, k) => { out[k] = String(v); });
        return out;
    }

    async function send(body, isForm) {
        const headers = {'X-CSRF-Token': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'};
        if (!isForm) headers['Content-Type'] = 'application/json';
        const r = await fetch(cfg.endpoint, {method: 'POST', headers, body: isForm ? body : JSON.stringify(body), credentials: 'same-origin'});
        let data = null;
        try { data = await r.json(); } catch (e) { data = null; }
        if (!data) throw new Error('The server did not answer (' + r.status + '). Reload the page and try again.');
        if (data.success === false) throw new Error(data.error || data.message || 'The request was refused.');
        return data;
    }

    // ------------------------------------------------------------------ 1. the file
    if (els.readForm) {
        els.readForm.addEventListener('submit', async ev => {
            ev.preventDefault();
            const fd = new FormData(els.readForm);
            fd.set('action', 'read');
            Object.entries(defaults()).forEach(([k, v]) => { if (!fd.has(k)) fd.set(k, v); });
            els.readBtn.disabled = true;
            els.readMsg.hidden = true;
            try {
                const data = await send(fd, true);
                state.file = data.file || '';
                state.rows = data.rows.map(r => ({...r, edited: false}));
                state.removed = [];
                state.general = data.general || [];
                state.postError = '';
                openReview();
            } catch (e) {
                els.readMsg.textContent = e.message;
                els.readMsg.hidden = false;
            } finally {
                els.readBtn.disabled = false;
            }
        });
    }

    function openReview() {
        els.start.hidden = true;
        els.review.hidden = false;
        els.fileName.textContent = state.file || 'The file';
        render();
        els.review.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    // ------------------------------------------------------------------ 2. the review
    function cellInput(row, c) {
        const v = row.values[c.key] ?? '';
        const bad = row.cells.includes(c.key);
        const num = c.type === 'money' || c.type === 'num';
        return `<input type="text" class="ir-in${num ? ' is-num' : ''}${bad ? ' is-bad' : ''}" data-k="${esc(c.key)}" value="${esc(v)}"`
            + ` aria-label="${esc(c.label)}"${bad ? ' aria-invalid="true"' : ''}${num ? ' inputmode="decimal"' : ''} placeholder="${esc(placeholder(c))}" autocomplete="off" spellcheck="false">`;
    }

    // a blank cell shows the value of the form it takes
    function placeholder(c) {
        const d = (state.defaults || {})[c.key];
        if (d !== undefined && String(d).trim() !== '') {
            const sel = cfg.defaults ? $(cfg.defaults + ' select[name="' + c.key + '"]') : null;
            const text = sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent.replace(/\s*\(.*$/, '') : String(d);
            return text;
        }
        return c.type === 'date' ? 'YYYY-MM-DD' : '';
    }
    function placeholders() {
        state.defaults = defaults();
        els.table.querySelectorAll('.ir-in').forEach(inp => {
            const c = cfg.cols.find(x => x.key === inp.dataset.k);
            if (c) inp.placeholder = placeholder(c);
        });
    }

    function messages(row) {
        const parts = [];
        if (row.info) parts.push(`<div class="ir-info">${row.info}</div>`);
        row.errors.forEach(e => parts.push(`<div class="ir-err"><span aria-hidden="true">!</span>${esc(e)}</div>`));
        row.notes.forEach(e => parts.push(`<div class="ir-note">${esc(e)}</div>`));
        return parts.join('');
    }

    function rowHtml(row) {
        const state_ = row.errors.length ? 'is-bad' : (row.edited ? 'is-edit' : 'is-ok');
        const cells = cfg.cols.map(c => `<td class="ir-c ir-${esc(c.size || 'md')}" data-label="${esc(c.label)}">${cellInput(row, c)}</td>`).join('');
        return `<tbody class="ir-rec ${state_}" data-line="${row.line}">
            <tr class="ir-main"><td class="ir-no"><span class="ir-dot" aria-hidden="true"></span>${row.line}</td>${cells}
            <td class="ir-act"><button type="button" class="ir-x" data-remove title="Remove this row" aria-label="Remove line ${row.line}">&times;</button></td></tr>
            <tr class="ir-msg"${messages(row) ? '' : ' hidden'}><td></td><td colspan="${cfg.cols.length + 1}">${messages(row)}</td></tr></tbody>`;
    }

    function render() {
        state.defaults = defaults();
        els.table.querySelector('thead').innerHTML = '<tr><th class="ir-no">Line</th>'
            + cfg.cols.map(c => `<th class="ir-${esc(c.size || 'md')}">${esc(c.label)}${c.req ? ' <span class="ir-req" title="Required">*</span>' : ''}</th>`).join('')
            + '<th class="ir-act"><span class="sr-only">Remove</span></th></tr>';
        els.table.querySelectorAll('tbody.ir-rec').forEach(t => t.remove());
        els.table.insertAdjacentHTML('beforeend', state.rows.map(rowHtml).join(''));
        applyFilter();
        summary();
    }

    // the result of a check on the rows already shown: classes and messages only (the cell being typed in keeps its focus)
    function refresh(row) {
        const tb = els.table.querySelector(`tbody.ir-rec[data-line="${row.line}"]`);
        if (!tb) return;
        tb.classList.remove('is-bad', 'is-ok', 'is-edit');
        tb.classList.add(row.errors.length ? 'is-bad' : (row.edited ? 'is-edit' : 'is-ok'));
        tb.querySelectorAll('.ir-in').forEach(inp => {
            const bad = row.cells.includes(inp.dataset.k);
            inp.classList.toggle('is-bad', bad);
            bad ? inp.setAttribute('aria-invalid', 'true') : inp.removeAttribute('aria-invalid');
            if (document.activeElement !== inp && inp.value !== (row.values[inp.dataset.k] ?? '')) inp.value = row.values[inp.dataset.k] ?? '';
        });
        const msg = tb.querySelector('.ir-msg');
        const html = messages(row);
        msg.hidden = !html;
        msg.lastElementChild.innerHTML = html;
    }

    function summary() {
        const n = state.rows.length;
        const bad = state.rows.filter(r => r.errors.length).length;
        els.n.textContent = n;
        els.n.nextElementSibling.textContent = n === 1 ? cfg.noun[0] : cfg.noun[1];
        els.bad.textContent = bad;
        els.badWrap.hidden = bad === 0;
        els.removeBad.hidden = bad === 0;
        els.badOnly.disabled = bad === 0;
        if (bad === 0) els.badOnly.checked = false;
        if (els.total && cfg.total) {
            const t = state.rows.reduce((s, r) => s + (Number(r.figure ?? String(r.values[cfg.total] || '').replace(/[^0-9.\-]/g, '')) || 0), 0);
            els.total.textContent = '₱' + money(t);
        }
        els.removedWrap.hidden = state.removed.length === 0;
        els.removedN.textContent = state.removed.length;
        els.postN.textContent = n + ' ' + (n === 1 ? cfg.noun[0] : cfg.noun[1]);
        const pending = state.checking > 0 || state.edited;
        els.checking.hidden = !pending;
        const general = state.postError ? [state.postError, ...state.general] : state.general;
        els.general.hidden = !general.length;
        els.general.innerHTML = general.map(esc).join('<br>');
        const ready = n > 0 && bad === 0 && !pending && !general.length;
        if (els.post) els.post.disabled = !ready || !cfg.canPost;
        let msg;
        if (!n) msg = '<span class="ir-bad-text">Every line was removed: start over with another file.</span>';
        else if (pending) msg = '<span class="ir-wait-text">Checking the lines...</span>';
        else if (state.postError) msg = '<span class="ir-bad-text">Nothing was posted: see the message above the lines. Correct it, then post again (Check again clears the message).</span>';
        else if (general.length) msg = '<span class="ir-bad-text">Correct the problem above the lines.</span>';
        else if (bad === 1) msg = '<span class="ir-bad-text">The line in red has a problem: correct it (it is checked again as you type) or remove it.</span>';
        else if (bad > 1) msg = `<span class="ir-bad-text">The ${bad} lines in red have a problem: correct them (they are checked again as you type) or remove them.</span>`;
        else msg = `<span class="ir-ok-text">Every line is ready.${cfg.canPost ? '' : ' Posting needs the Create right on this page.'}</span>`;
        els.status.innerHTML = msg;
    }

    function applyFilter() {
        const q = (els.find.value || '').trim().toLowerCase();
        const badOnly = els.badOnly.checked;
        let shown = 0;
        els.table.querySelectorAll('tbody.ir-rec').forEach(tb => {
            const row = state.rows.find(r => String(r.line) === tb.dataset.line);
            const text = row ? (Object.values(row.values).join(' ') + ' ' + row.info.replace(/<[^>]+>/g, ' ')).toLowerCase() : '';
            const ok = (!q || text.includes(q)) && (!badOnly || (row && row.errors.length));
            tb.hidden = !ok;
            if (ok) shown++;
        });
        els.none.hidden = shown > 0 || state.rows.length === 0;
    }

    function current() {
        return state.rows.map(r => ({_line: r.line, ...r.values}));
    }

    async function check() {
        clearTimeout(state.timer);
        const seq = ++state.seq;
        state.checking++;
        state.edited = false;
        summary();
        try {
            const data = await send({action: 'check', rows: current(), defaults: defaults()});
            // an answer to an older check (a later one was sent, or a cell changed since) is not applied
            if (seq !== state.seq || state.edited) return;
            const byLine = new Map(data.rows.map(r => [r.line, r]));
            state.general = data.general || [];
            state.rows.forEach(r => {
                const c = byLine.get(r.line);
                if (!c) return;
                r.errors = c.errors; r.notes = c.notes; r.info = c.info; r.cells = c.cells; r.figure = c.figure;
                r.edited = false;
                refresh(r);
            });
        } catch (e) {
            if (seq === state.seq) state.general = [e.message];
        } finally {
            state.checking--;
            summary();
        }
    }

    function schedule() {
        state.edited = true;
        state.postError = '';
        clearTimeout(state.timer);
        state.timer = setTimeout(check, 650);
        summary();
    }

    if (els.table) {
        els.table.addEventListener('input', ev => {
            const inp = ev.target.closest('.ir-in');
            if (!inp) return;
            const tb = inp.closest('tbody.ir-rec');
            const row = state.rows.find(r => String(r.line) === tb.dataset.line);
            if (!row) return;
            row.values[inp.dataset.k] = inp.value;
            row.edited = true;
            tb.classList.remove('is-ok');
            tb.classList.add(row.errors.length ? 'is-bad' : 'is-edit');
            schedule();
        });
        els.table.addEventListener('click', ev => {
            const x = ev.target.closest('[data-remove]');
            if (!x) return;
            const tb = x.closest('tbody.ir-rec');
            const i = state.rows.findIndex(r => String(r.line) === tb.dataset.line);
            if (i < 0) return;
            state.removed.push(state.rows[i]);
            state.rows.splice(i, 1);
            tb.remove();
            applyFilter();
            schedule();
        });
    }
    if (els.undo) els.undo.addEventListener('click', () => {
        const row = state.removed.pop();
        if (!row) return;
        state.rows.push(row);
        state.rows.sort((a, b) => a.line - b.line);
        render();
        schedule();
    });
    if (els.removeBad) els.removeBad.addEventListener('click', () => {
        const bad = state.rows.filter(r => r.errors.length);
        if (!bad.length) return;
        state.removed.push(...bad);
        state.rows = state.rows.filter(r => !r.errors.length);
        render();
        schedule();
    });
    if (els.find) els.find.addEventListener('input', applyFilter);
    if (els.badOnly) els.badOnly.addEventListener('change', applyFilter);
    if (els.recheck) els.recheck.addEventListener('click', () => { state.postError = ''; check(); });
    if (cfg.defaults && $(cfg.defaults)) {
        $(cfg.defaults).addEventListener('input', () => { if (!els.review.hidden) { placeholders(); schedule(); } });
        $(cfg.defaults).addEventListener('change', () => { if (!els.review.hidden) { placeholders(); schedule(); } });
    }
    if (els.restart) els.restart.addEventListener('click', async () => {
        if (state.rows.length && !await ask('Start over with another file? The corrections made here are not kept.', {title: 'Start over', ok: 'Start over', tone: 'warning'})) return;
        state.seq++;
        state.rows = []; state.removed = []; state.postError = ''; state.general = [];
        els.review.hidden = true;
        els.start.hidden = false;
        if (els.readForm) els.readForm.reset();
    });

    // ------------------------------------------------------------------ 3. post
    if (els.post) els.post.addEventListener('click', async () => {
        if (els.post.disabled) return;
        const n = state.rows.length;
        const what = n + ' ' + (n === 1 ? cfg.noun[0] : cfg.noun[1]);
        if (!await ask((cfg.confirm || 'Post the rows?').replace('{n}', what), {title: cfg.confirmTitle || 'Post', ok: cfg.confirmOk || 'Post'})) return;
        if (els.post.disabled || state.edited || state.checking) return;
        els.post.disabled = true;
        state.seq++;
        state.checking++;
        summary();
        let error = '';
        try {
            const data = await send({action: 'post', rows: current(), defaults: defaults(), operation_token: cfg.token, file: state.file});
            if (data.redirect) { window.location.href = data.redirect; return; }
        } catch (e) {
            error = e.message;
        }
        state.checking--;
        if (error) {
            // the message stays above the lines until something is changed or checked again; the lines are checked
            // again so what refused them shows on them
            await check();
            state.postError = error;
        }
        summary();
    });
})();
