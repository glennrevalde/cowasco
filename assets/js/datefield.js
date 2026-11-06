/* =====================================================================
   Dates typed as numbers.

   Every date box (<input type="date">) can be typed into with the number keys, on a computer and on a phone:
     07182025  ->  07/18/2025          7/18/2025, 7-18-25, 2025-07-18 are read too
     071825    ->  07/18/2025          (two-digit years: up to ten years ahead are this century, the rest the last)
   and the calendar button next to it still opens the calendar.

   The original date box stays in the form (out of sight) and keeps the value in the form the server reads
   (yyyy-mm-dd), so nothing that sends or reads the form changes: its "input" and "change" events still fire, and
   a value set by the page's own script (box.value = ..., box.valueAsDate = ...) shows in the typed box.
   A box can keep the browser's own date field with data-native-date.
   ===================================================================== */
(function () {
  'use strict';
  if (window.DateField) return;

  const CSS = ''
    + '.datefield{position:relative;display:block;min-width:0}'
    + '.datefield.is-inline{display:inline-block;vertical-align:middle}'
    + '.datefield>.datefield-text{width:100%;padding-right:2.3rem}'
    + '.datefield>.datefield-native{position:absolute!important;right:0;top:0;width:2.2rem!important;height:100%!important;min-width:0!important;'
    + 'opacity:0!important;pointer-events:none!important;border:0!important;padding:0!important;margin:0!important}'
    + '.datefield-btn{position:absolute;right:1px;top:1px;bottom:1px;width:2.1rem;display:grid;place-items:center;border:0;background:transparent;'
    + 'color:#5b6573;border-radius:0 7px 7px 0;cursor:pointer;font-size:.92rem;padding:0}'
    + '.datefield-btn:hover{color:#0b5ed7;background:rgba(13,110,253,.07)}'
    + '.datefield-btn:focus-visible{outline:2px solid rgba(13,110,253,.55);outline-offset:-2px}'
    + '.datefield-btn[disabled]{opacity:.45;cursor:default}'
    + '.datefield-text.is-bad{border-color:#dc3545!important;box-shadow:0 0 0 .18rem rgba(220,53,69,.15)!important}'
    + '@media print{.datefield-btn{display:none}.datefield>.datefield-text{padding-right:0}}';

  function addStyle() {
    if (document.getElementById('datefield-style')) return;
    const s = document.createElement('style');
    s.id = 'datefield-style';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (y, m, d) => y + '-' + pad(m) + '-' + pad(d);
  const show = (v) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? m[2] + '/' + m[3] + '/' + m[1] : ''; };
  const valid = (y, m, d) => { if (y < 1000 || y > 9999 || m < 1 || m > 12 || d < 1) return false; const dt = new Date(y, m - 1, d); return dt.getMonth() === m - 1 && dt.getDate() === d; };
  function fullYear(yy) {
    const now = new Date().getFullYear();
    const y = 2000 + yy;
    return y > now + 10 ? y - 100 : y;
  }

  /** the typed text -> yyyy-mm-dd, '' when empty, null when it is not a date */
  function parse(text) {
    const t = String(text || '').trim();
    if (t === '') return '';
    let m;
    if ((m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(t))) return valid(+m[1], +m[2], +m[3]) ? iso(+m[1], +m[2], +m[3]) : null;
    if ((m = /^(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{2}|\d{4})$/.exec(t))) {
      const y = m[3].length === 2 ? fullYear(+m[3]) : +m[3];
      return valid(y, +m[1], +m[2]) ? iso(y, +m[1], +m[2]) : null;
    }
    if ((m = /^(\d{2})(\d{2})(\d{4})$/.exec(t))) return valid(+m[3], +m[1], +m[2]) ? iso(+m[3], +m[1], +m[2]) : null;
    if ((m = /^(\d{2})(\d{2})(\d{2})$/.exec(t))) { const y = fullYear(+m[3]); return valid(y, +m[1], +m[2]) ? iso(y, +m[1], +m[2]) : null; }
    return null;
  }

  const proto = HTMLInputElement.prototype;
  const valueDesc = Object.getOwnPropertyDescriptor(proto, 'value');
  const asDateDesc = Object.getOwnPropertyDescriptor(proto, 'valueAsDate');

  function enhance(orig) {
    if (orig.dataset.datefield === '1' || orig.hasAttribute('data-native-date') || orig.closest('template, [data-native-date]')) return;
    if (orig.type !== 'date') return;
    orig.dataset.datefield = '1';
    addStyle();

    const cs = window.getComputedStyle(orig);
    const wrap = document.createElement('span');
    wrap.className = 'datefield' + (cs.display.indexOf('inline') === 0 ? ' is-inline' : '');
    // the place the box had in its row goes to the wrapper
    ['width', 'maxWidth', 'minWidth', 'flex', 'flexGrow', 'flexShrink', 'flexBasis', 'gridColumn', 'gridRow', 'alignSelf', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight'].forEach((k) => {
      if (orig.style[k]) { wrap.style[k] = orig.style[k]; }
    });
    if (orig.parentElement) {
      const pd = window.getComputedStyle(orig.parentElement).display;
      if (pd.indexOf('flex') >= 0) { wrap.style.flexGrow = cs.flexGrow; wrap.style.flexShrink = cs.flexShrink; wrap.style.flexBasis = cs.flexBasis; }
      if (pd.indexOf('grid') >= 0 && cs.gridColumnStart !== 'auto') wrap.style.gridColumn = cs.gridColumnStart + ' / ' + cs.gridColumnEnd;
    }
    if (!orig.style.width && cs.display.indexOf('inline') === 0) wrap.style.width = cs.width;

    const text = document.createElement('input');
    text.type = 'text';
    text.className = orig.className + ' datefield-text';
    text.inputMode = 'numeric';
    text.autocomplete = 'off';
    text.placeholder = 'mm/dd/yyyy';
    text.maxLength = 10;
    text.title = 'Type the date as numbers, e.g. 07182025, or choose it from the calendar';
    if (orig.getAttribute('aria-label')) text.setAttribute('aria-label', orig.getAttribute('aria-label'));
    if (orig.id) {
      text.id = orig.id + '__typed';
      document.querySelectorAll('label[for="' + orig.id + '"]').forEach((l) => { l.htmlFor = text.id; });
    }
    ['height', 'fontSize', 'textAlign', 'padding', 'paddingLeft', 'paddingTop', 'paddingBottom'].forEach((k) => { if (orig.style[k]) text.style[k] = orig.style[k]; });

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'datefield-btn';
    btn.tabIndex = -1;
    btn.setAttribute('aria-label', 'Open the calendar');
    btn.innerHTML = '<i class="bi bi-calendar3"></i>';

    orig.parentNode.insertBefore(wrap, orig);
    wrap.appendChild(text);
    wrap.appendChild(orig);
    wrap.appendChild(btn);
    orig.classList.add('datefield-native');
    orig.tabIndex = -1;
    orig.setAttribute('aria-hidden', 'true');

    // required / disabled / read-only live on the box that is seen
    const mirror = () => {
      text.disabled = orig.disabled;
      text.readOnly = orig.readOnly;
      btn.disabled = orig.disabled || orig.readOnly;
      if (orig.hasAttribute('required')) { orig.removeAttribute('required'); text.required = true; orig.dataset.datefieldRequired = '1'; }
    };
    mirror();
    new MutationObserver(mirror).observe(orig, { attributes: true, attributeFilter: ['required', 'disabled', 'readonly'] });
    // the page's script may switch "required" off again with box.required = false
    Object.defineProperty(orig, 'required', {
      configurable: true,
      get() { return text.required; },
      set(v) { text.required = !!v; },
    });

    const syncFromOrig = () => {
      const v = valueDesc.get.call(orig);
      if (parse(text.value) !== v) text.value = show(v);
      check();
    };
    function check() {
      const typed = text.value.trim();
      const p = parse(typed);
      let msg = '';
      if (typed !== '' && p === null) msg = 'Enter the date as MM/DD/YYYY, e.g. 07/18/2025.';
      else if (p && orig.min && p < orig.min) msg = 'The date cannot be before ' + show(orig.min) + '.';
      else if (p && orig.max && p > orig.max) msg = 'The date cannot be after ' + show(orig.max) + '.';
      text.setCustomValidity(msg);
      text.classList.toggle('is-bad', msg !== '');
    }
    // a value set by the page's script shows in the typed box
    Object.defineProperty(orig, 'value', {
      configurable: true,
      get() { return valueDesc.get.call(this); },
      set(v) { valueDesc.set.call(this, v); syncFromOrig(); },
    });
    if (asDateDesc) {
      Object.defineProperty(orig, 'valueAsDate', {
        configurable: true,
        get() { return asDateDesc.get.call(this); },
        set(v) { asDateDesc.set.call(this, v); syncFromOrig(); },
      });
    }

    const commit = (fire) => {
      const p = parse(text.value);
      check();
      if (p === null) return;
      if (valueDesc.get.call(orig) !== p) {
        valueDesc.set.call(orig, p);
        orig.dispatchEvent(new Event('input', { bubbles: true }));
        if (fire) orig.dispatchEvent(new Event('change', { bubbles: true }));
        else orig.dataset.datefieldPending = '1';
      }
    };

    text.addEventListener('input', () => {
      // digits only: the slashes are put in while typing (07 -> 07/, 0718 -> 07/18/)
      const raw = text.value;
      let m;
      if (/^\d+$/.test(raw) && raw.length >= 3 && raw.length <= 8) {
        text.value = raw.length <= 4 ? raw.slice(0, 2) + '/' + raw.slice(2) : raw.slice(0, 2) + '/' + raw.slice(2, 4) + '/' + raw.slice(4);
      } else if ((m = /^(\d{2})\/(\d{2})(\d{1,4})$/.exec(raw))) {
        text.value = m[1] + '/' + m[2] + '/' + m[3];
      }
      text.classList.remove('is-bad');
      text.setCustomValidity('');
      if (text.value.trim() === '') {
        if (valueDesc.get.call(orig) !== '') { valueDesc.set.call(orig, ''); orig.dispatchEvent(new Event('input', { bubbles: true })); orig.dataset.datefieldPending = '1'; }
        return;
      }
      // a complete date takes effect at once; anything shorter waits for the box to be left
      if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(text.value) || /^\d{4}-\d{2}-\d{2}$/.test(text.value)) commit(false);
    });
    const finish = () => {
      if (text.value.trim() !== '') {
        const p = parse(text.value);
        if (p) text.value = show(p);
      }
      commit(false);
      if (orig.dataset.datefieldPending === '1') { delete orig.dataset.datefieldPending; orig.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    text.addEventListener('change', finish);
    text.addEventListener('blur', finish);
    text.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') finish();
      else if ((e.key === 'ArrowDown' && e.altKey) || e.key === 'F4') { e.preventDefault(); openCalendar(); }
    });

    // the calendar: the browser's own picker of the original box
    function openCalendar() {
      if (orig.disabled || orig.readOnly) return;
      try {
        if (typeof orig.showPicker === 'function') { orig.showPicker(); return; }
      } catch (err) { /* fall through */ }
      orig.style.pointerEvents = 'auto';
      orig.focus();
      orig.click();
      setTimeout(() => { orig.style.pointerEvents = ''; }, 500);
    }
    btn.addEventListener('click', openCalendar);
    orig.addEventListener('input', () => { text.value = show(valueDesc.get.call(orig)) || text.value; check(); });
    orig.addEventListener('change', () => { text.value = show(valueDesc.get.call(orig)); check(); });
    orig.form && orig.form.addEventListener('reset', () => setTimeout(syncFromOrig, 0));

    text.value = show(valueDesc.get.call(orig));
    check();
  }

  function init(root) {
    (root || document).querySelectorAll('input[type="date"]').forEach(enhance);
  }

  window.DateField = { init, parse };
  const start = () => {
    init(document);
    // date boxes added later (a new grid row, a form opened in a window) are typed into the same way
    new MutationObserver((list) => {
      list.forEach((m) => m.addedNodes.forEach((n) => {
        if (n.nodeType !== 1) return;
        if (n.matches && n.matches('input[type="date"]')) enhance(n);
        else if (n.querySelectorAll) init(n);
      }));
    }).observe(document.documentElement, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
