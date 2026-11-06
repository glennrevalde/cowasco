/* ==========================================================================
   Water Billing — long <option> label truncation (BUSINESS-PAGE build)
   --------------------------------------------------------------------------
   This is the business-page replacement for common/ui-consistency.js. It is
   loaded through common/navbar.php only, so the operator login, registration,
   password and account-setup screens keep the original shared script exactly
   as before.

   Behavioural difference (audit F10): the original script assigned
   option.textContent unconditionally and observed every subtree child
   addition, so its own writes re-triggered the observer and re-scheduled
   applyToAll() indefinitely. This build
     - compares before writing (no DOM mutation when the label is unchanged),
     - ignores mutation records that its own option/select writes produce,
     - re-runs only when a mutation actually adds a <select>,
   so the page becomes idle once options finish loading. Full accessible
   labels stay in data-full-label / title, and searchable selects owned by
   navbar.php are never touched.
   ========================================================================== */
(function () {
    'use strict';

    var ELLIPSIS = '…';
    var MIN_VISIBLE_CHARS = 8;   // never shorten to something unreadable
    var SAFETY_PADDING = 6;      // px of breathing room before the arrow
    var measureCanvas = null;

    function textWidth(text, font) {
        if (!measureCanvas) measureCanvas = document.createElement('canvas');
        var ctx = measureCanvas.getContext('2d');
        if (!ctx) return 0;
        ctx.font = font;
        return ctx.measureText(text).width;
    }

    function controlFont(select) {
        var cs = window.getComputedStyle(select);
        // Canvas wants the CSS font shorthand; line-height is irrelevant here.
        return (cs.fontStyle || 'normal') + ' ' + (cs.fontWeight || '400') + ' ' +
               (cs.fontSize || '14px') + ' ' + (cs.fontFamily || 'sans-serif');
    }

    /* Width available to the label once the control's own padding and the
       dropdown arrow gutter are taken out. */
    function availableWidth(select) {
        var cs = window.getComputedStyle(select);
        var padLeft = parseFloat(cs.paddingLeft) || 0;
        var padRight = parseFloat(cs.paddingRight) || 0;
        var inner = select.clientWidth - padLeft - padRight - SAFETY_PADDING;
        return inner > 0 ? inner : 0;
    }

    function shortenToFit(text, limit, font) {
        if (textWidth(text, font) <= limit) return text;

        var low = MIN_VISIBLE_CHARS;
        var high = text.length;
        var best = MIN_VISIBLE_CHARS;

        while (low <= high) {
            var mid = (low + high) >> 1;
            var candidate = text.slice(0, mid).replace(/[\s\-–—/,.]+$/, '') + ELLIPSIS;
            if (textWidth(candidate, font) <= limit) {
                best = mid;
                low = mid + 1;
            } else {
                high = mid - 1;
            }
        }

        return text.slice(0, best).replace(/[\s\-–—/,.]+$/, '') + ELLIPSIS;
    }

    /* The searchable component in navbar.php builds its own list from
       option.textContent and filters on it, so a truncated label there would
       break searching. Leave every select it owns completely alone. */
    function isEnhanced(select) {
        return select.classList.contains('ds-native-select-hidden') ||
               select.closest('.ds-select-enhanced') !== null;
    }

    function setText(node, text) {
        if (node.textContent !== text) node.textContent = text;
    }

    function setTitle(node, title) {
        if (title === null || title === undefined || title === '') {
            if (node.hasAttribute('title')) node.removeAttribute('title');
        } else if (node.getAttribute('title') !== title) {
            node.setAttribute('title', title);
        }
    }

    function restore(option) {
        if (option.dataset.fullLabel !== undefined && option.dataset.fullLabel !== '') {
            setText(option, option.dataset.fullLabel);
        }
    }

    function applyTo(select) {
        if (!select || select.multiple || select.size > 1) return;

        var options = select.options;
        var i;

        /* The searchable component may enhance a select after our first pass
           has already shortened its labels, and it builds and filters its list
           from option.textContent. Hand every label back in full and never
           touch this select again, so a race can't leave it searching on
           truncated text. */
        if (isEnhanced(select)) {
            for (i = 0; i < options.length; i++) {
                restore(options[i]);
                setTitle(options[i], '');
            }
            setTitle(select, '');
            return;
        }

        for (i = 0; i < options.length; i++) restore(options[i]);

        var limit = availableWidth(select);
        if (limit <= 0) return;

        var font = controlFont(select);

        for (i = 0; i < options.length; i++) {
            var option = options[i];
            var full = option.textContent.replace(/\s+/g, ' ').trim();
            if (full === '') continue;

            if (option.dataset.fullLabel === undefined || option.dataset.fullLabel === '') {
                option.dataset.fullLabel = full;
            }

            var shortened = shortenToFit(full, limit, font);
            if (shortened !== full) {
                setText(option, shortened);
                setTitle(option, full);
            } else {
                setText(option, full);
                setTitle(option, '');
            }
        }

        reflectSelectedTitle(select);
    }

    /* Hovering the control itself should reveal the untruncated selection. */
    function reflectSelectedTitle(select) {
        var option = select.options[select.selectedIndex];
        if (!option) return;
        var full = option.dataset.fullLabel || option.textContent;
        if (full && full !== option.textContent) {
            setTitle(select, full);
        } else {
            setTitle(select, '');
        }
    }

    function applyToAll(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var selects = scope.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) applyTo(selects[i]);
    }

    var resizeTimer = null;
    function scheduleReapply() {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () { applyToAll(document); }, 140);
    }

    function start() {
        // One pass now, one after the searchable-select script has had a turn,
        // so selects it takes over are correctly skipped.
        applyToAll(document);
        window.setTimeout(function () { applyToAll(document); }, 220);

        window.addEventListener('resize', scheduleReapply);

        document.addEventListener('change', function (event) {
            if (event.target && event.target.tagName === 'SELECT') {
                reflectSelectedTitle(event.target);
            }
        });

        // Selects injected later (modals, AJAX-rendered rows) get the same
        // treatment. Our own writes touch only <option>/<select> nodes, and a
        // pass only matters when a mutation introduces a select or options, so
        // everything else is ignored — the observer can no longer re-trigger
        // itself.
        if (window.MutationObserver) {
            var pending = false;
            function introducesSelect(record) {
                var target = record.target;
                if (target && target.nodeType === 1) {
                    var tag = target.tagName;
                    if (tag === 'OPTION') return false;                 // our own textContent writes
                    if (tag === 'SELECT' || tag === 'OPTGROUP') return true; // options added/replaced by the page
                }
                var added = record.addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var node = added[j];
                    if (node.nodeType !== 1) continue;
                    if (node.tagName === 'SELECT') return true;
                    if (node.querySelector && node.querySelector('select')) return true;
                }
                return false;
            }
            new MutationObserver(function (mutations) {
                if (pending) return;
                for (var i = 0; i < mutations.length; i++) {
                    if (introducesSelect(mutations[i])) {
                        pending = true;
                        window.setTimeout(function () {
                            pending = false;
                            applyToAll(document);
                        }, 90);
                        return;
                    }
                }
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();

/* ==========================================================================
   Wide-table scroll hosts (business pages only).
   A table that no page-level wrapper already scrolls gets its own labelled,
   keyboard-focusable scroll region, so the page body never pans sideways and
   no column or total is hidden. Paper/print tables are excluded.
   ========================================================================== */
(function () {
    'use strict';
    var HOSTS = '.table-wrap, .table-container, .table-scroll, .table-responsive, .data-table-wrap, ' +
        '.tablewrap, .batch-table-wrap, .card-table-wrap, div.table, .ui-table-scroll, .business-table-scroll, ' +
        '.hc-table-wrap, .no-scroll-wrap';

    function wrap(table) {
        if (!(table instanceof HTMLTableElement)) return;
        if (table.closest(HOSTS)) return;
        if (!table.closest('.main-content')) return;
        var cls = String(table.className || '');
        if (/soa-|print-table|ledger-table|receipt|paper|no-scroll/i.test(cls)) return;
        if (table.closest('[data-print-area], .print-area, .print-only, .paper')) return;
        var host = document.createElement('div');
        host.className = 'business-table-scroll';
        host.setAttribute('tabindex', '0');
        host.setAttribute('role', 'region');
        var caption = table.querySelector('caption');
        var heading = caption ? caption.textContent.trim() : '';
        host.setAttribute('aria-label', heading ? heading + ' (scrollable table)' : 'Scrollable table');
        table.parentNode.insertBefore(host, table);
        host.appendChild(table);
    }

    function wrapAll(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var tables = scope.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) wrap(tables[i]);
        if (root instanceof HTMLTableElement) wrap(root);
    }

    function start() {
        wrapAll(document);
        if (window.MutationObserver) {
            var pending = false;
            new MutationObserver(function (mutations) {
                if (pending) return;
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var node = added[j];
                        if (node.nodeType !== 1) continue;
                        if (node.tagName === 'TABLE' || (node.querySelector && node.querySelector('table'))) {
                            pending = true;
                            window.setTimeout(function () { pending = false; wrapAll(document); }, 60);
                            return;
                        }
                    }
                }
            }).observe(document.body || document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
