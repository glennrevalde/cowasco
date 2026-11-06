/* =====================================================================
   The program's own message and confirmation window.

   Every question a page asks ("Post this deposit of 1,500.00?", "Delete this bill code?") opens this window
   instead of the browser's "localhost says" box: same look on the accounting, cooperative and water pages,
   real values in the text, and it works the same on a phone.

     AppDialog.confirm(text, {title, ok, cancel, tone: 'primary' | 'danger' | 'warning', html}) -> Promise<boolean>
     AppDialog.alert(text, {title, ok, tone})                                                    -> Promise<void>
     AppDialog.copy(text, {title})   shows a value to copy (where the clipboard is not available)

   In the markup:
     <button data-confirm="Delete this role?">         asks first, then does what the button does
     <a href="..." data-confirm="Open the print run?"> asks first, then follows the link
     <form data-confirm="Close the period?">           asks when the form is sent (forms marked data-once are
                                                       asked by coop.js, which also fills {amount} and the like)
     data-confirm-title, data-confirm-ok, data-confirm-tone change the title, the button and its colour.

   window.alert() is answered by this window too, so no page shows the browser's box.
   ===================================================================== */
(function () {
  'use strict';
  if (window.AppDialog) return;

  const CSS = ''
    + '.appdlg-back{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;'
    + 'background:rgba(17,24,39,.46);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);opacity:0;transition:opacity .16s ease}'
    + '.appdlg-back.is-open{opacity:1}'
    + '.appdlg{width:min(460px,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1f2933;border-radius:14px;'
    + 'border:1px solid #dfe3e9;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 24px 48px -18px rgba(15,23,42,.45),0 4px 10px rgba(15,23,42,.08);'
    + 'transform:translateY(8px) scale(.98);transition:transform .18s cubic-bezier(.16,1,.3,1);'
    + 'font-family:"Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;font-size:.92rem}'
    + '.appdlg-back.is-open .appdlg{transform:none}'
    + '.appdlg-head{display:flex;align-items:center;gap:.7rem;padding:1rem 1.15rem .35rem}'
    + '.appdlg-ico{flex:0 0 36px;width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:1.05rem;'
    + 'background:linear-gradient(180deg,#eef4ff,#dfeaff);color:#0b5ed7;box-shadow:0 1px 0 #fff inset,0 1px 2px rgba(13,110,253,.18)}'
    + '.appdlg.is-danger .appdlg-ico{background:linear-gradient(180deg,#fff1f1,#fde2e2);color:#b42318;box-shadow:0 1px 0 #fff inset,0 1px 2px rgba(180,35,24,.18)}'
    + '.appdlg.is-warning .appdlg-ico{background:linear-gradient(180deg,#fff8e6,#fdecc4);color:#9a6700;box-shadow:0 1px 0 #fff inset,0 1px 2px rgba(154,103,0,.18)}'
    + '.appdlg-title{margin:0;font-size:1.02rem;font-weight:650;line-height:1.3}'
    + '.appdlg-body{padding:.45rem 1.15rem 1rem 1.15rem;line-height:1.5;color:#374151;white-space:pre-line;word-wrap:break-word}'
    + '.appdlg-body.is-html{white-space:normal}'
    + '.appdlg-body p:last-child{margin-bottom:0}'
    + '.appdlg-copy{width:100%;margin-top:.6rem;padding:.5rem .65rem;border:1px solid #dfe3e9;border-radius:8px;background:#f8f9fb;'
    + 'font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.95rem;box-shadow:inset 0 1px 2px rgba(15,23,42,.07)}'
    + '.appdlg-foot{display:flex;justify-content:flex-end;gap:.5rem;padding:.8rem 1.15rem 1rem;border-top:1px solid #ebeef2;background:#fbfcfd;border-radius:0 0 14px 14px}'
    + '.appdlg-btn{appearance:none;border:1px solid transparent;border-radius:8px;padding:.5rem 1rem;font:inherit;font-weight:600;cursor:pointer;min-width:96px;'
    + 'transition:background-color .12s,box-shadow .12s,transform .05s}'
    + '.appdlg-btn:active{transform:translateY(1px)}'
    + '.appdlg-btn:focus-visible{outline:2px solid rgba(13,110,253,.55);outline-offset:2px}'
    + '.appdlg-cancel{background:#fff;color:#374151;border-color:#cfd5dd;box-shadow:0 1px 0 #fff inset,0 1px 2px rgba(15,23,42,.06)}'
    + '.appdlg-cancel:hover{background:#f3f5f8}'
    + '.appdlg-ok{background:linear-gradient(180deg,#2f82ff,#0b5ed7);color:#fff;border-color:#0b5ed7;'
    + 'box-shadow:0 1px 0 rgba(255,255,255,.3) inset,0 2px 4px rgba(13,110,253,.3)}'
    + '.appdlg-ok:hover{background:linear-gradient(180deg,#1f76f7,#0a53c1)}'
    + '.appdlg.is-danger .appdlg-ok{background:linear-gradient(180deg,#e5484d,#c4262c);border-color:#b42318;box-shadow:0 1px 0 rgba(255,255,255,.25) inset,0 2px 4px rgba(180,35,24,.3)}'
    + '.appdlg.is-warning .appdlg-ok{background:linear-gradient(180deg,#f5b400,#d99a00);border-color:#c08a00;color:#1f2933}'
    + '@media (max-width:520px){.appdlg-back{align-items:flex-end;padding:10px}.appdlg{width:100%;border-radius:16px}'
    + '.appdlg-foot{border-radius:0 0 16px 16px}.appdlg-btn{flex:1 1 0;padding:.65rem 1rem}}'
    + '@media print{.appdlg-back{display:none!important}}';

  function addStyle() {
    if (document.getElementById('appdlg-style')) return;
    const s = document.createElement('style');
    s.id = 'appdlg-style';
    s.textContent = CSS;
    (document.head || document.documentElement).appendChild(s);
  }

  const ICONS = { primary: 'bi-question-circle', danger: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle', copy: 'bi-clipboard' };
  const esc = (t) => String(t == null ? '' : t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // questions asked while one is showing wait their turn
  const queue = [];
  let busy = false;

  function open(o) {
    return new Promise((resolve) => {
      queue.push({ o, resolve });
      next();
    });
  }

  function next() {
    if (busy || !queue.length) return;
    if (!document.body) { document.addEventListener('DOMContentLoaded', next, { once: true }); return; }
    busy = true;
    const { o, resolve } = queue.shift();
    addStyle();
    const tone = o.tone || (o.kind === 'alert' ? 'info' : 'primary');
    const back = document.createElement('div');
    back.className = 'appdlg-back';
    const box = document.createElement('div');
    box.className = 'appdlg' + (tone === 'danger' ? ' is-danger' : tone === 'warning' ? ' is-warning' : '');
    box.setAttribute('role', o.kind === 'alert' ? 'alertdialog' : 'dialog');
    box.setAttribute('aria-modal', 'true');
    const titleId = 'appdlg-t' + Date.now();
    box.setAttribute('aria-labelledby', titleId);
    const body = o.html ? String(o.text) : esc(o.text);
    box.innerHTML = '<div class="appdlg-head"><span class="appdlg-ico"><i class="bi ' + (ICONS[o.kind === 'copy' ? 'copy' : tone] || ICONS.primary) + '"></i></span>'
      + '<h2 class="appdlg-title" id="' + titleId + '">' + esc(o.title) + '</h2></div>'
      + '<div class="appdlg-body' + (o.html ? ' is-html' : '') + '">' + body
      + (o.kind === 'copy' ? '<input class="appdlg-copy" readonly value="' + esc(o.value) + '">' : '') + '</div>'
      + '<div class="appdlg-foot">'
      + (o.kind === 'confirm' ? '<button type="button" class="appdlg-btn appdlg-cancel" data-act="no">' + esc(o.cancel || 'Cancel') + '</button>' : '')
      + '<button type="button" class="appdlg-btn appdlg-ok" data-act="yes">' + esc(o.ok || (o.kind === 'confirm' ? 'Yes, continue' : 'OK')) + '</button></div>';
    back.appendChild(box);
    document.body.appendChild(back);
    const before = document.activeElement;
    const yes = box.querySelector('[data-act="yes"]');
    const no = box.querySelector('[data-act="no"]');
    const done = (answer) => {
      document.removeEventListener('keydown', onKey, true);
      back.classList.remove('is-open');
      setTimeout(() => { back.remove(); busy = false; next(); }, 140);
      if (before && typeof before.focus === 'function' && document.contains(before)) { try { before.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
      resolve(answer);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(o.kind === 'confirm' ? false : undefined); }
      else if (e.key === 'Tab') {
        // keep the focus inside the window
        const f = Array.prototype.slice.call(box.querySelectorAll('button, input'));
        const i = f.indexOf(document.activeElement);
        e.preventDefault();
        f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
      }
    };
    document.addEventListener('keydown', onKey, true);
    yes.addEventListener('click', () => done(o.kind === 'confirm' ? true : undefined));
    if (no) no.addEventListener('click', () => done(false));
    back.addEventListener('mousedown', (e) => { if (e.target === back && o.kind !== 'confirm') done(undefined); });
    requestAnimationFrame(() => {
      back.classList.add('is-open');
      // a question that could do damage starts on "Cancel"; anything else on the main button
      (tone === 'danger' && no ? no : yes).focus();
      const copy = box.querySelector('.appdlg-copy');
      if (copy) { copy.focus(); copy.select(); }
    });
  }

  const AppDialog = {
    confirm(text, opt) {
      opt = opt || {};
      return open({ kind: 'confirm', text, title: opt.title || 'Please confirm', ok: opt.ok, cancel: opt.cancel, tone: opt.tone, html: !!opt.html });
    },
    alert(text, opt) {
      opt = opt || {};
      return open({ kind: 'alert', text, title: opt.title || 'Please note', ok: opt.ok, tone: opt.tone || 'info', html: !!opt.html });
    },
    copy(value, opt) {
      opt = opt || {};
      return open({ kind: 'copy', text: opt.text || 'Copy this value:', value, title: opt.title || 'Copy', ok: 'Done' });
    },
  };
  window.AppDialog = AppDialog;

  // the browser's alert box is not used anywhere: the same message opens here
  window.alert = function (msg) { AppDialog.alert(msg); };

  // words that make a question a "danger" one when the page does not say
  const DANGER = /\b(delete|remove|discard|void|reverse|write off|deactivate|wipe)\b/i;
  const toneOf = (el, text) => el.getAttribute('data-confirm-tone') || (DANGER.test(text) ? 'danger' : 'primary');
  const okOf = (el, text) => {
    if (el.getAttribute('data-confirm-ok')) return el.getAttribute('data-confirm-ok');
    const m = text.match(DANGER);
    if (!m) return 'Yes, continue';
    const verb = m[1].toLowerCase() === 'wipe' ? 'delete' : m[1].toLowerCase();
    return verb.charAt(0).toUpperCase() + verb.slice(1);
  };

  // ---------------------------------------------------------------- buttons and links that ask first
  document.addEventListener('click', (e) => {
    const el = e.target.closest && e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM' || el.closest('.appdlg')) return;
    if (el.dataset.confirmPassed === '1') { delete el.dataset.confirmPassed; return; }
    if (el.disabled) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const text = el.getAttribute('data-confirm');
    AppDialog.confirm(text, { title: el.getAttribute('data-confirm-title') || 'Please confirm', ok: okOf(el, text), tone: toneOf(el, text) }).then((yes) => {
      if (!yes) return;
      el.dataset.confirmPassed = '1';
      const form = el.form || (el.closest && el.closest('form'));
      const isSubmit = (el.tagName === 'BUTTON' && (el.type || 'submit') === 'submit') || (el.tagName === 'INPUT' && el.type === 'submit');
      if (isSubmit && form) {
        // the form is sent the way the button would have sent it (its name and value travel with it)
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(el); }
        else {
          if (el.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = el.name; h.value = el.value; form.appendChild(h); }
          form.submit();
        }
        delete el.dataset.confirmPassed;
      } else if (el.tagName === 'A' && el.href) {
        delete el.dataset.confirmPassed;
        if (el.target && el.target !== '_self') window.open(el.href, el.target); else window.location.href = el.href;
      } else {
        el.click();
      }
    });
  }, true);

  // ---------------------------------------------------------------- forms that ask when they are sent
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form.hasAttribute('data-once')) return;
    if (form.dataset.confirmPassed === '1') { delete form.dataset.confirmPassed; return; }
    const btn = e.submitter;
    if (btn && btn.hasAttribute('data-no-confirm')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const text = form.getAttribute('data-confirm');
    AppDialog.confirm(text, { title: form.getAttribute('data-confirm-title') || 'Please confirm', ok: okOf(form, text), tone: toneOf(form, text) }).then((yes) => {
      if (!yes) return;
      form.dataset.confirmPassed = '1';
      if (typeof form.requestSubmit === 'function') { btn && btn.form === form ? form.requestSubmit(btn) : form.requestSubmit(); }
      else {
        if (btn && btn.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
        form.submit();
      }
    });
  }, true);
})();
