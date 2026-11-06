<!-- DS_SEARCHABLE_SELECTS_START -->
<style>
@import url('../common/fonts.css');

/*
 * The searchable select component of the water pages.
 * - Normal large selects become clean searchable dropdowns.
 * - Member/consumer selects become rich card-like search results.
 * - Original <select> remains in the form for normal PHP POST handling.
 */
.ds-select-enhanced {
    position: relative;
    width: 100%;
    font-family: inherit;
}

.ds-native-select-hidden {
    position: absolute !important;
    left: 0;
    top: 0;
    width: 1px !important;
    height: 1px !important;
    opacity: 0.01 !important;
    pointer-events: none !important;
    z-index: -1 !important;
}

.ds-select-control {
    width: 100%;
    min-height: 42px;
    border: 1.5px solid var(--border, #e5e7eb);
    border-radius: var(--radius-sm, 8px);
    background: var(--white, #ffffff);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 12px;
    cursor: pointer;
    color: var(--text-primary, #111827);
    transition: border-color .2s, box-shadow .2s, background .2s;
}

.ds-select-control:hover {
    border-color: var(--border-focus, #1a1a2e);
}

.ds-select-enhanced.open .ds-select-control {
    border-color: var(--border-focus, #1a1a2e);
    box-shadow: 0 0 0 3px rgba(26,26,46,.10);
}

.ds-select-control.disabled {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #9ca3af;
}

.ds-select-value {
    flex: 1;
    min-width: 0;
    font-size: .875rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* a long choice is cut with an ellipsis inside the field (it no longer pushes the arrow out of a narrow form) */
.ds-select-value-wrap {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
}

.ds-select-value-wrap > .ds-select-value {
    display: block;
}

.ds-select-value.placeholder {
    color: var(--text-muted, #9ca3af);
}

.ds-select-arrow {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--text-muted, #9ca3af);
    transition: transform .2s;
}

.ds-select-enhanced.open .ds-select-arrow {
    transform: rotate(180deg);
}

.ds-select-dropdown {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    background: var(--white, #ffffff);
    border: 1.5px solid var(--border-focus, #1a1a2e);
    border-radius: 12px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .20);
    z-index: 99999;
    display: none;
    overflow: hidden;
}

.ds-select-enhanced.open .ds-select-dropdown {
    display: block;
}

.ds-select-search {
    width: 100%;
    border: 0;
    border-bottom: 1px solid var(--border, #e5e7eb);
    outline: none;
    padding: 11px 13px;
    font-family: inherit;
    font-size: .875rem;
    color: var(--text-primary, #111827);
    background: #ffffff;
}

.ds-select-search::placeholder {
    color: var(--text-muted, #9ca3af);
}

.ds-select-results {
    max-height: 280px;
    overflow-y: auto;
    padding: 6px;
}

.ds-select-option {
    padding: 10px 11px;
    border-radius: 9px;
    cursor: pointer;
    font-size: .86rem;
    color: var(--text-primary, #111827);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background .15s;
}

.ds-select-option:hover,
.ds-select-option.active {
    background: var(--off-white, #f8f9fb);
}

.ds-select-option.selected {
    background: #eef2ff;
    color: #1e1b4b;
    font-weight: 700;
}

.ds-select-option.disabled {
    opacity: .45;
    cursor: not-allowed;
}

.ds-select-option-check {
    margin-left: auto;
    color: #059669;
    font-weight: 900;
}

.ds-select-empty {
    color: var(--text-muted, #9ca3af);
    text-align: center;
    padding: 18px 12px;
    font-size: .85rem;
}

/* Rich member result style */
.ds-member-option {
    align-items: center;
    padding: 10px 11px;
}

.ds-member-avatar {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    background: var(--accent, #1a1a2e);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .65rem;
    font-weight: 800;
    flex-shrink: 0;
    letter-spacing: .02em;
}

.ds-member-main {
    min-width: 0;
    flex: 1;
}

.ds-member-name {
    display: block;
    font-weight: 800;
    font-size: .83rem;
    color: var(--text-primary, #111827);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.ds-member-meta {
    display: block;
    margin-top: 2px;
    font-size: .70rem;
    color: var(--text-secondary, #6b7280);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.ds-member-badge {
    display: inline-flex;
    margin-top: 3px;
    padding: 2px 7px;
    border-radius: 4px;
    font-size: .60rem;
    font-weight: 800;
    letter-spacing: .04em;
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
}

.ds-select-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.ds-select-tag {
    display: inline-flex;
    align-items: center;
    max-width: 180px;
    padding: 3px 7px;
    border-radius: 4px;
    background: #f3f4f6;
    color: #374151;
    font-size: .72rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Incremental search (version 46): the list narrows with every letter typed, the letters found are marked, the
   first match is highlighted and taken by Enter; the arrow keys move the highlight. */
.ds-select-option.active {
    background: #eef2ff;
    box-shadow: inset 0 0 0 1.5px rgba(26, 26, 46, .22);
}
.ds-select-option mark {
    background: #fde68a;
    color: inherit;
    padding: 0;
    border-radius: 2px;
}
.ds-select-foot {
    padding: 7px 12px 8px;
    border-top: 1px solid var(--border, #e8eaed);
    font-size: .72rem;
    color: var(--text-muted, #9ca3af);
    background: #fcfcfd;
}
.ds-select-foot:empty { display: none; }

/*
 * If a select is inside a modal or card, make sure dropdown can sit above nearby elements.
 */
.modal-box .ds-select-dropdown,
.card .ds-select-dropdown {
    z-index: 100000;
}
</style>

<?php
/*
 * Shared UI consistency layer. Loaded last on purpose: it sits after every
 * page's own <style> block, so its equal-specificity rules win by document
 * order and each page keeps its own colours and layout.
 *
 * The stylesheet lives beside this file in common/ rather than in its own
 * assets/ tree. Anything that loads this navbar has already copied common/,
 * so a partial update can never leave the pages pointing at a 404.
 *
 * Every entry point that includes this navbar (views/*.php, process/generate_soa.php,
 * and common/accountingReportPage.php, itself included from views/) is served
 * one directory below the application root, so '../common/' is correct.
 */
?>
<link rel="stylesheet" href="../common/ui-consistency.css">
<?php
/*
 * Business-page-only layer (audit F01/F08/F10 + responsiveness):
 *  - business-responsive.css     scoped responsive overrides; never loaded by
 *                                the protected login/register/password screens
 *  - business-request.js         CSRF token injection for process/ POSTs and the
 *                                JSON-aware fetch wrapper (window.businessFetchJson)
 *  - business-ui-consistency.js  idempotent replacement for ui-consistency.js on
 *                                business pages (the shared file stays untouched
 *                                for the authentication screens)
 */
?>
<link rel="stylesheet" href="../common/business-responsive.css">
<script>
window.BusinessPageConfig = <?= json_encode([
    'csrfToken' => csrfToken(),
    'loginUrl' => 'login.php',
    'currentPage' => $current_page,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="../common/business-request.js"></script>
<script src="../common/business-ui-consistency.js" defer></script>
<script src="../common/incremental-search.js" defer></script>

<script>
(function () {
    const MIN_OPTIONS = 8;
    const NON_SEARCH_SMALL_NAMES = [
        'status', 'type', 'frequency', 'gender', 'civil_status', 'payment_type',
        'action', 'role', 'filter', 'category', 'adjustment_type'
    ];

    function normalize(text) {
        return String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    /* ---- incremental search (version 46) ----------------------------------------------------------------
       Every word typed must be found (in any order); a word found at the start of a word of the option counts
       first, and the options whose text starts with what was typed come before all others ("agu" lists Aguilar
       first, "bond ja" finds "Bond, James", "31" finds account 31). Accents and commas do not matter. */
    function fold(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function typedWords(query) {
        return fold(query).split(/[\s,;]+/).filter(Boolean);
    }
    function wordStarts(t) {
        const out = [];
        for (let i = 0; i < t.length; i++) {
            const c = t[i];
            const alnum = /[a-z0-9\u00df-\u024f]/.test(c);
            if (alnum && (i === 0 || !/[a-z0-9\u00df-\u024f]/.test(t[i - 1]))) out.push(i);
        }
        return out;
    }
    /** -1: not a match; 0: starts with what was typed; 1: every word at a word start; 2: every word somewhere */
    function matchRank(text, words) {
        if (!words.length) return 0;
        const t = fold(text);
        if (!words.every(w => t.indexOf(w) >= 0)) return -1;
        const plain = t.replace(/[^a-z0-9\u00df-\u024f]+/g, ' ').trim();
        if (plain.indexOf(words.join(' ')) === 0 || t.indexOf(words[0]) === 0) return 0;
        const starts = wordStarts(t);
        return words.every(w => starts.some(p => t.startsWith(w, p))) ? 1 : 2;
    }
    /** The text with the words found marked (escaped) */
    function markWords(text, words) {
        const raw = String(text || '');
        if (!words.length) return escapeHtml(raw);
        const t = fold(raw);
        const starts = wordStarts(t);
        const hits = [];
        words.forEach(w => {
            let at = starts.find(p => t.startsWith(w, p));
            if (at === undefined) at = t.indexOf(w);
            if (at >= 0) hits.push([at, at + w.length]);
        });
        hits.sort((a, b) => a[0] - b[0]);
        let out = '', i = 0;
        hits.forEach(([a, b]) => {
            if (a < i) return;
            out += escapeHtml(raw.slice(i, a)) + '<mark>' + escapeHtml(raw.slice(a, b)) + '</mark>';
            i = b;
        });
        return out + escapeHtml(raw.slice(i));
    }
    window.DsIncremental = { fold, typedWords, matchRank, markWords };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getLabelText(select) {
        const id = select.id;
        if (id) {
            const label = document.querySelector('label[for="' + CSS.escape(id) + '"]');
            if (label) return label.textContent || '';
        }

        const group = select.closest('.form-group, .field, .form-field, td, div');
        if (group) {
            const label = group.querySelector('label');
            if (label) return label.textContent || '';
        }

        return '';
    }

    function isThirdPartyInternalSelect(select) {
        if (!select) return true;

        /*
         * Do not convert Flatpickr's internal month <select>.
         * The global searchable-select script runs on dynamically-added selects too,
         * and enhancing Flatpickr's month dropdown breaks the calendar header UI.
         */
        return Boolean(
            select.classList.contains('flatpickr-monthDropdown-months') ||
            select.closest('.flatpickr-calendar') ||
            select.closest('.flatpickr-months') ||
            select.closest('.flatpickr-current-month')
        );
    }

    function shouldEnhance(select) {
        if (!select || select.dataset.dsEnhanced === '1') return false;
        if (isThirdPartyInternalSelect(select)) return false;
        if (select.dataset.noSearch === 'true') return false;
        if (select.classList.contains('ds-native-select-hidden')) return false;

        const realOptions = Array.from(select.options).filter(opt => opt.value !== '' && !opt.disabled);
        const nameIdLabel = normalize((select.name || '') + ' ' + (select.id || '') + ' ' + getLabelText(select));

        if (select.dataset.searchable === 'true') return true;
        if (isMemberSelect(select)) return true;
        if (nameIdLabel.includes('account') || nameIdLabel.includes('chart') || nameIdLabel.includes('bill code') || nameIdLabel.includes('supplier')) return true;
        if (realOptions.length >= MIN_OPTIONS) return true;

        const isSmallUtility = NON_SEARCH_SMALL_NAMES.some(token => nameIdLabel === token || nameIdLabel.endsWith(' ' + token));
        if (isSmallUtility) return false;

        return false;
    }

    function isMemberSelect(select) {
        const haystack = normalize((select.name || '') + ' ' + (select.id || '') + ' ' + getLabelText(select));
        return (
            haystack.includes('member') ||
            haystack.includes('consumer') ||
            haystack.includes('customer')
        );
    }

    function initialsFromName(name) {
        const cleaned = String(name || '').replace(/^(mr\.|mrs\.|ms\.|miss|dr\.)\s+/i, '').trim();
        const parts = cleaned.split(/[\s,]+/).filter(Boolean);
        if (!parts.length) return 'NA';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function parseMemberOption(option) {
        let text = (option.textContent || '').replace(/\s+/g, ' ').trim();
        let value = option.value || '';
        let name = text;
        let meta = '';
        let badge = '';

        /*
         * Common formats:
         * 100001 — Dela Cruz, Juan
         * 100001, Dela Cruz, Juan
         * Dela Cruz, Juan
         */
        const dashMatch = text.match(/^([^—-]+)\s*[—-]\s*(.+)$/);
        if (dashMatch && /^\d+/.test(dashMatch[1].trim())) {
            meta = 'Account ' + dashMatch[1].trim();
            name = dashMatch[2].trim();
        } else {
            const commaMatch = text.match(/^(\d{3,})\s*,\s*(.+)$/);
            if (commaMatch) {
                meta = 'Account ' + commaMatch[1].trim();
                name = commaMatch[2].trim();
            } else if (/^\d+$/.test(value)) {
                meta = 'Account ' + value;
            }
        }

        if (option.dataset.address) meta += (meta ? ', ' : '') + option.dataset.address;
        if (option.dataset.status) badge = option.dataset.status;

        return { name, meta, badge };
    }

    /* The closed control has one line to work with, so an option may carry a
       data-short-label written for that space. The dropdown list and its search
       always use the option's own full text, so nothing is ever hidden from the
       person choosing. */
    function closedLabel(opt) {
        const short = (opt.dataset.shortLabel || '').trim();
        return short !== '' ? short : opt.textContent.trim();
    }

    function selectedText(select, rich) {
        const selected = Array.from(select.selectedOptions || []).filter(opt => opt.value !== '');
        if (select.multiple) {
            if (!selected.length) return '<span class="ds-select-value placeholder">Select option(s)...</span>';

            const tags = selected.slice(0, 3).map(opt => {
                const text = rich ? parseMemberOption(opt).name : closedLabel(opt);
                return '<span class="ds-select-tag">' + escapeHtml(text) + '</span>';
            }).join('');

            const more = selected.length > 3
                ? '<span class="ds-select-tag">+' + (selected.length - 3) + ' more</span>'
                : '';

            return '<span class="ds-select-tags">' + tags + more + '</span>';
        }

        const opt = select.options[select.selectedIndex];
        if (!opt || opt.value === '') {
            const placeholder = opt ? opt.textContent.trim() : 'Select...';
            return '<span class="ds-select-value placeholder">' + escapeHtml(placeholder || 'Select...') + '</span>';
        }

        const text = rich ? parseMemberOption(opt).name : closedLabel(opt);
        return '<span class="ds-select-value">' + escapeHtml(text) + '</span>';
    }

    function enhanceSelect(select) {
        if (!shouldEnhance(select)) return;

        select.dataset.dsEnhanced = '1';

        const richMember = isMemberSelect(select);
        const wrapper = document.createElement('div');
        wrapper.className = 'ds-select-enhanced' + (richMember ? ' ds-member-select' : '');
        wrapper.dataset.multiple = select.multiple ? '1' : '0';

        const control = document.createElement('div');
        control.className = 'ds-select-control' + (select.disabled ? ' disabled' : '');
        control.tabIndex = select.disabled ? -1 : 0;

        const value = document.createElement('div');
        value.className = 'ds-select-value-wrap';

        const arrow = document.createElement('div');
        arrow.className = 'ds-select-arrow';
        arrow.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>';

        control.appendChild(value);
        control.appendChild(arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'ds-select-dropdown';

        const search = document.createElement('input');
        search.className = 'ds-select-search';
        search.type = 'text';
        search.autocomplete = 'off';
        search.placeholder = richMember ? 'Search member name, account no., address...' : 'Search...';

        const results = document.createElement('div');
        results.className = 'ds-select-results';

        dropdown.appendChild(search);
        dropdown.appendChild(results);

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        wrapper.appendChild(control);
        wrapper.appendChild(dropdown);

        select.classList.add('ds-native-select-hidden');

        function closeAllExcept(current) {
            document.querySelectorAll('.ds-select-enhanced.open').forEach(el => {
                if (el !== current) el.classList.remove('open');
            });
        }

        function syncLabel() {
            value.innerHTML = selectedText(select, richMember);
            control.classList.toggle('disabled', select.disabled);
            control.tabIndex = select.disabled ? -1 : 0;
        }

        function optionHtml(option, words, index, active) {
            const selected = option.selected;
            const disabled = option.disabled;
            const selectedCheck = selected ? '<span class="ds-select-option-check">✓</span>' : '';
            const cls = (selected ? ' selected' : '') + (disabled ? ' disabled' : '') + (index === active ? ' active' : '');

            if (richMember) {
                const member = parseMemberOption(option);
                const initials = initialsFromName(member.name);
                const meta = member.meta ? '<span class="ds-member-meta">' + markWords(member.meta, words) + '</span>' : '';
                const badge = member.badge ? '<span class="ds-member-badge">' + escapeHtml(member.badge) + '</span>' : '';
                return `
                    <div class="ds-select-option ds-member-option${cls}" data-value="${escapeHtml(option.value)}" data-i="${index}" role="option" aria-selected="${index === active ? 'true' : 'false'}">
                        <span class="ds-member-avatar">${escapeHtml(initials)}</span>
                        <span class="ds-member-main">
                            <span class="ds-member-name">${markWords(member.name, words)}</span>
                            ${meta}
                            ${badge}
                        </span>
                        ${selectedCheck}
                    </div>
                `;
            }

            return `
                <div class="ds-select-option${cls}" data-value="${escapeHtml(option.value)}" data-i="${index}" role="option" aria-selected="${index === active ? 'true' : 'false'}">
                    <span>${markWords(option.textContent.trim(), words)}</span>
                    ${selectedCheck}
                </div>
            `;
        }

        /* the options shown for what is typed, best matches first; the highlighted one is taken by Enter */
        const MAX_SHOWN = 300;
        let shown = [];
        let active = -1;
        let typed = false;
        const foot = document.createElement('div');
        foot.className = 'ds-select-foot';
        foot.setAttribute('aria-live', 'polite');
        dropdown.appendChild(foot);
        results.setAttribute('role', 'listbox');
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');

        function render() {
            const words = typedWords(search.value);
            const ranked = [[], [], []];
            let total = 0;
            Array.from(select.options).forEach(opt => {
                if (opt.value === '') {
                    if (!words.length) ranked[0].push(opt);
                    return;
                }
                total++;
                const text = [opt.textContent, opt.value, opt.dataset.code, opt.dataset.description, opt.dataset.address, opt.dataset.status].filter(Boolean).join(' ');
                const r = matchRank(text, words);
                if (r >= 0) ranked[r].push(opt);
            });
            const all = ranked[0].concat(ranked[1], ranked[2]);
            shown = all.slice(0, MAX_SHOWN);

            if (!shown.length) {
                active = -1;
                results.innerHTML = '<div class="ds-select-empty">No results found for "' + escapeHtml(search.value.trim()) + '".</div>';
                foot.textContent = '';
                return;
            }
            const firstEnabled = shown.findIndex(o => !o.disabled && (o.value !== '' || !words.length));
            const current = words.length ? -1 : shown.findIndex(o => o.selected && o.value !== '');
            active = current >= 0 ? current : firstEnabled;
            results.innerHTML = shown.map((o, i) => optionHtml(o, words, i, active)).join('');
            const matches = all.filter(o => o.value !== '').length;
            foot.textContent = words.length
                ? (matches === 1 ? '1 match' : matches + ' matches') + (all.length > MAX_SHOWN ? ', the first ' + MAX_SHOWN + ' shown: type more letters to narrow the list' : '') + '. Enter takes the highlighted one.'
                : (total > MAX_SHOWN ? 'Type to narrow the ' + total + ' entries.' : '');
            scrollToActive();
        }

        function paintActive() {
            results.querySelectorAll('.ds-select-option').forEach(el => {
                const on = Number(el.dataset.i) === active;
                el.classList.toggle('active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        function scrollToActive() {
            const el = results.querySelector('.ds-select-option.active');
            if (!el) return;
            const top = el.offsetTop, bottom = top + el.offsetHeight;
            if (top < results.scrollTop) results.scrollTop = top;
            else if (bottom > results.scrollTop + results.clientHeight) results.scrollTop = bottom - results.clientHeight;
        }

        function move(step) {
            if (!shown.length) return;
            let i = active;
            for (let n = 0; n < shown.length; n++) {
                i = Math.max(0, Math.min(shown.length - 1, (i < 0 ? 0 : i) + step));
                if (!shown[i].disabled) break;
                if ((step > 0 && i === shown.length - 1) || (step < 0 && i === 0)) break;
            }
            if (shown[i] && !shown[i].disabled) {
                active = i;
                paintActive();
                scrollToActive();
            }
        }

        function open(seed) {
            if (select.disabled) return;
            closeAllExcept(wrapper);
            wrapper.classList.add('open');
            search.value = seed || '';
            typed = !!seed;
            render();
            try { search.focus({ preventScroll: true }); } catch (e) { search.focus(); }
            setTimeout(() => {
                search.focus();
                const end = search.value.length;
                try { search.setSelectionRange(end, end); } catch (e) { /* not a text box */ }
            }, 0);
        }

        function close() {
            wrapper.classList.remove('open');
            typed = false;
        }

        function choose(value) {
            const option = Array.from(select.options).find(opt => opt.value === value);
            if (!option || option.disabled) return;

            if (select.multiple) {
                option.selected = !option.selected;
            } else {
                select.value = value;
                close();
            }

            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncLabel();
            if (select.multiple) render();
        }

        control.addEventListener('click', () => open(''));
        control.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
                event.preventDefault();
                open('');
                return;
            }
            // typing on the closed box starts the search with that letter (incremental search); a letter typed
            // before the search box has the focus is added to it, so a quick typist loses none
            if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                if (wrapper.classList.contains('open')) {
                    search.value += event.key;
                    typed = true;
                    render();
                    search.focus();
                } else {
                    open(event.key);
                }
            }
        });

        search.addEventListener('input', function () {
            typed = true;
            render();
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                control.focus();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                move(event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'PageDown' || event.key === 'PageUp') {
                event.preventDefault();
                move(event.key === 'PageDown' ? 8 : -8);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const pick = shown[active];
                if (pick && !pick.disabled) choose(pick.value);
                if (!select.multiple) control.focus();
            } else if (event.key === 'Tab' && typed && !select.multiple) {
                // leaving the box after typing takes the match only when the letters leave exactly one; with several
                // matches the box closes unchanged (Enter or a click takes one of them)
                const usable = shown.filter(o => !o.disabled && o.value !== '');
                if (usable.length === 1 && usable[0].value !== select.value) choose(usable[0].value);
                else close();
            }
        });

        results.addEventListener('mousemove', function (event) {
            const optionEl = event.target.closest('.ds-select-option');
            if (!optionEl || optionEl.classList.contains('disabled')) return;
            const i = Number(optionEl.dataset.i);
            if (i !== active) {
                active = i;
                paintActive();
            }
        });

        results.addEventListener('click', function (event) {
            const optionEl = event.target.closest('.ds-select-option');
            if (!optionEl || optionEl.classList.contains('disabled')) return;
            choose(optionEl.dataset.value);
        });

        select.addEventListener('change', syncLabel);

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) close();
        });

        /*
         * Watch disabled changes and option lists rebuilt by the page's own script.
         */
        const observer = new MutationObserver(syncLabel);
        observer.observe(select, { attributes: true, attributeFilter: ['disabled'], childList: true });

        /* A page that fills a form by script (an Edit window showing the saved accounts) sets the value
           without a change event; the hooks below call this so the box shows what is selected. */
        select._dsSync = syncLabel;

        syncLabel();
    }

    /* Setting select.value, select.selectedIndex or option.selected by script refreshes the searchable box
       that stands in for the select (once per task, after the page's script has finished). */
    (function hookProgrammaticSelection() {
        if (window.__dsSelectHooked) return;
        window.__dsSelectHooked = true;
        const pending = new Set();
        function schedule(select) {
            if (!select || typeof select._dsSync !== 'function') return;
            pending.add(select);
            if (pending.size === 1) {
                Promise.resolve().then(function () {
                    const list = Array.from(pending);
                    pending.clear();
                    list.forEach(function (s) { try { s._dsSync(); } catch (e) { /* the box keeps its text */ } });
                });
            }
        }
        function hook(proto, prop, selectOf) {
            const desc = Object.getOwnPropertyDescriptor(proto, prop);
            if (!desc || typeof desc.set !== 'function') return;
            Object.defineProperty(proto, prop, {
                configurable: true,
                enumerable: desc.enumerable,
                get: desc.get,
                set: function (v) { desc.set.call(this, v); schedule(selectOf(this)); }
            });
        }
        hook(HTMLSelectElement.prototype, 'value', function (s) { return s; });
        hook(HTMLSelectElement.prototype, 'selectedIndex', function (s) { return s; });
        hook(HTMLOptionElement.prototype, 'selected', function (o) { return o.closest ? o.closest('select') : null; });
        document.addEventListener('reset', function (event) {
            const form = event.target;
            setTimeout(function () {
                if (form && form.querySelectorAll) form.querySelectorAll('select').forEach(schedule);
            }, 0);
        }, true);
    })();

    function enhanceAll(root) {
        (root || document)
            .querySelectorAll('select:not(.flatpickr-monthDropdown-months)')
            .forEach(enhanceSelect);
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhanceAll(document);

        /*
         * Some pages create select elements dynamically. Enhance them too.
         */
        const pageRoot = document.body;
        if (!pageRoot) return;

        const observer = new MutationObserver(function (mutations) {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach(node => {
                    if (!(node instanceof HTMLElement)) return;
                    if (node.closest && node.closest('.flatpickr-calendar')) return;
                    if (node.matches && node.matches('select')) enhanceSelect(node);
                    enhanceAll(node);
                });
            }
        });

        observer.observe(pageRoot, { childList: true, subtree: true });
    });
})();
</script>
<!-- DS_SEARCHABLE_SELECTS_END -->
<?php
/* FORCE CONFIGURATION guided setup (business pages only). */
$__setupTutorialInclude = __DIR__ . '/setupTutorial.php';
if (is_file($__setupTutorialInclude)) { require_once $__setupTutorialInclude; }
unset($__setupTutorialInclude);
?>
