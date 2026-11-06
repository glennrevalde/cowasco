/*
 * Primary buttons of the water pages in the system's primary colour.
 *
 * The former water-billing program drew its primary actions near-black, each page with its own class names. This
 * marks every button whose face is near-black with "wsh-primary" (theme.css paints it the system blue), also for
 * buttons added or restyled later (dialogs, tables filled by script, toggles that become active). The decision is
 * taken on the button's own final colour, never mid-transition, and is revised whenever its class or style changes.
 */
(function () {
    'use strict';
    var SEL = 'button, a.btn, a[class*="btn"], a[class*="button"], input[type="submit"], input[type="button"], [role="button"]';
    var SHELL = '.wsh-bar, .wsh-sidebar, .wsh-toasts';

    function isButton(el) {
        return el && el.nodeType === 1 && el.matches(SEL) && !el.closest(SHELL);
    }

    function nearBlack(el) {
        var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/.exec(getComputedStyle(el).backgroundColor || '');
        if (!m) return false;
        var a = m[4] === undefined ? 1 : parseFloat(m[4]);
        var r = +m[1], g = +m[2], b = +m[3];
        return a >= 0.9 && Math.max(r, g, b) <= 64 && Math.abs(r - g) <= 30 && Math.abs(g - b) <= 40;
    }

    function judge(el) {
        var keep = el.style.transition;
        el.style.transition = 'none';
        el.classList.remove('wsh-primary');
        if (nearBlack(el)) el.classList.add('wsh-primary');
        void el.offsetWidth;
        el.style.transition = keep;
    }

    var busy = false, observer;

    function run(list) {
        if (busy) return;
        busy = true;
        try {
            list.forEach(judge);
        } finally {
            if (observer) observer.takeRecords();
            busy = false;
        }
    }

    function start() {
        run(Array.prototype.filter.call(document.querySelectorAll(SEL), isButton));
        if (!window.MutationObserver) return;
        observer = new MutationObserver(function (records) {
            if (busy) return;
            var seen = [];
            records.forEach(function (rec) {
                if (rec.type === 'attributes') {
                    if (isButton(rec.target) && seen.indexOf(rec.target) < 0) seen.push(rec.target);
                    else if (rec.attributeName === 'class' && rec.target.nodeType === 1 && rec.target.querySelectorAll) {
                        // a container changed state (a tab bar, a selected row): its buttons may have changed colour
                        rec.target.querySelectorAll(SEL).forEach(function (b) { if (isButton(b) && seen.indexOf(b) < 0) seen.push(b); });
                    }
                    return;
                }
                rec.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    if (isButton(n) && seen.indexOf(n) < 0) seen.push(n);
                    if (n.querySelectorAll) n.querySelectorAll(SEL).forEach(function (b) { if (isButton(b) && seen.indexOf(b) < 0) seen.push(b); });
                });
            });
            if (seen.length) run(seen);
        });
        observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'disabled'] });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
