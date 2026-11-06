/* ==========================================================================
   Water Billing — long <option> label truncation
   --------------------------------------------------------------------------
   ui-consistency.css already stops the CLOSED control from running its text
   under the arrow. This handles the other half: the OPEN dropdown list, which
   the browser renders at full option width with no ellipsis of its own, so a
   label like

       A — RESIDENTIAL ASSOCIATE / INSTITUTIONAL / 1/2"

   spills past the control it belongs to.

   Any option whose label does not fit is shortened to

       A — RESIDENTIAL ASSOCIATE / INSTITU…

   PRESENTATION ONLY. option.value is never read, never written, and never
   depended on, so every form posts exactly what it posted before. The full
   label is kept in data-full-label and mirrored into title=, so hovering the
   option or the control still shows the complete text, and the label is
   restored in full before each re-measure.
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

    function restore(option) {
        if (option.dataset.fullLabel !== undefined && option.dataset.fullLabel !== '') {
            option.textContent = option.dataset.fullLabel;
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
                options[i].removeAttribute('title');
            }
            select.removeAttribute('title');
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
                option.textContent = shortened;
                option.title = full;
            } else {
                option.textContent = full;
                option.removeAttribute('title');
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
            select.title = full;
        } else {
            select.removeAttribute('title');
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

        // Selects injected later (modals, AJAX-rendered rows) get the same treatment.
        if (window.MutationObserver) {
            var pending = false;
            new MutationObserver(function (mutations) {
                if (pending) return;
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
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
