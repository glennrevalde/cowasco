/* ==========================================================================
   Water Billing — FORCE CONFIGURATION guided setup (business pages only)
   --------------------------------------------------------------------------
   Server-rendered dialog/banner + this enhancement layer:
     - focus trap and keyboard operation inside the required dialog
       (Escape, backdrop click, refresh or back never dismiss it: the server
       renders it again until the configuration is really saved);
     - spotlight of the relevant control on the setup page;
     - dynamic dialog for configuration_required answers from business
       endpoints (window.SetupTutorial.showRequirement);
     - presentation progress (which screen was seen) sent to the server; this
       never counts as completion.
   ========================================================================== */
(function () {
    'use strict';
    var config = window.SetupTutorialConfig || {};
    var overlay = document.getElementById('setupTutorialOverlay');
    var banner = document.getElementById('setupTutorialBanner');

    function focusables(root) {
        return Array.prototype.slice.call(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'));
    }

    function trapFocus(dialogRoot) {
        function onKey(event) {
            if (event.key === 'Escape') {
                // A required step cannot be dismissed. Keep focus inside instead.
                event.preventDefault();
                event.stopPropagation();
                var first = focusables(dialogRoot)[0];
                if (first) first.focus();
                return;
            }
            if (event.key !== 'Tab') return;
            var items = focusables(dialogRoot);
            if (!items.length) { event.preventDefault(); return; }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || !dialogRoot.contains(document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !dialogRoot.contains(document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        }
        document.addEventListener('keydown', onKey, true);
        document.addEventListener('focusin', function (event) {
            if (!dialogRoot.contains(event.target)) {
                var first = focusables(dialogRoot)[0];
                if (first) first.focus();
            }
        });
        // Background must not be reachable by assistive technology.
        Array.prototype.forEach.call(document.body.children, function (child) {
            if (child !== dialogRoot && !child.contains(dialogRoot) && !dialogRoot.contains(child) && child.tagName !== 'SCRIPT' && child.tagName !== 'LINK' && child.tagName !== 'STYLE') {
                child.setAttribute('aria-hidden', 'true');
                child.setAttribute('data-setup-tutorial-inert', '1');
                if ('inert' in child) child.inert = true;
            }
        });
        var first = focusables(dialogRoot)[0];
        window.setTimeout(function () { (first || dialogRoot).focus(); }, 30);
    }

    function recordProgress(key, state) {
        if (!config.progressUrl || !key) return;
        try {
            var body = JSON.stringify({ requirement_key: key, state: state });
            window.fetch(config.progressUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
        } catch (e) { /* best effort */ }
    }

    function spotlight(selector) {
        if (!selector) return false;
        var target = null;
        try {
            var candidates = selector.split(',');
            for (var i = 0; i < candidates.length && !target; i++) {
                target = document.querySelector(candidates[i].trim());
            }
        } catch (e) { target = null; }
        if (!target) target = document.querySelector('.main-content form');
        if (!target) return false;
        document.querySelectorAll('.setup-tutorial-spotlight').forEach(function (el) { el.classList.remove('setup-tutorial-spotlight'); });
        target.classList.add('setup-tutorial-spotlight');
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var field = target.matches('input, select, textarea, button') ? target : target.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (field) window.setTimeout(function () { try { field.focus({ preventScroll: true }); } catch (e) { field.focus(); } }, 250);
        window.setTimeout(function () { target.classList.remove('setup-tutorial-spotlight'); }, 6000);
        return true;
    }

    function buildDialog(payload) {
        var existing = document.getElementById('setupTutorialOverlay');
        if (existing) return existing;
        var total = Number(payload.total || config.totalSteps || 0);
        var step = Number(payload.step || (payload.position || 0));
        var canConfigure = !!payload.can_configure;
        var destination = canConfigure && payload.destination ? String(payload.destination) : '';
        var wrap = document.createElement('div');
        wrap.className = 'setup-tutorial-overlay is-blocking';
        wrap.id = 'setupTutorialOverlay';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-labelledby', 'setupTutorialTitle');
        wrap.setAttribute('tabindex', '-1');
        var dialog = document.createElement('div');
        dialog.className = 'setup-tutorial-dialog';
        var progress = document.createElement('div');
        progress.className = 'setup-tutorial-progress';
        progress.textContent = total > 0 ? 'Step ' + step + ' of ' + total : 'Configuration required';
        var title = document.createElement('h2');
        title.className = 'setup-tutorial-title';
        title.id = 'setupTutorialTitle';
        title.textContent = (canConfigure ? 'Configure this first: ' : 'An administrator must complete: ') + String(payload.title || payload.requirement_key || 'required configuration');
        var body = document.createElement('div');
        body.className = 'setup-tutorial-body';
        var why = document.createElement('p');
        why.textContent = String(payload.message || 'This action is blocked until the required configuration is saved.');
        body.appendChild(why);
        if (payload.blocked_task) {
            var blocked = document.createElement('p');
            blocked.className = 'setup-tutorial-blocked';
            blocked.textContent = 'Blocked until then: ' + String(payload.blocked_task) + '. Nothing was saved by the request you just made.';
            body.appendChild(blocked);
        }
        var actions = document.createElement('div');
        actions.className = 'setup-tutorial-actions';
        if (destination) {
            var go = document.createElement('a');
            go.className = 'setup-tutorial-btn is-primary';
            go.href = destination + '#setup-step-' + String(payload.requirement_key || '');
            go.textContent = 'Configure now';
            actions.appendChild(go);
        }
        var help = document.createElement('a');
        help.className = 'setup-tutorial-btn';
        help.href = 'help_center.php';
        help.textContent = 'Help Center';
        actions.appendChild(help);
        var reload = document.createElement('button');
        reload.type = 'button';
        reload.className = 'setup-tutorial-btn';
        reload.textContent = 'Re-check configuration';
        reload.addEventListener('click', function () { window.location.reload(); });
        actions.appendChild(reload);
        dialog.appendChild(progress);
        dialog.appendChild(title);
        dialog.appendChild(body);
        dialog.appendChild(actions);
        wrap.appendChild(dialog);
        document.body.appendChild(wrap);
        trapFocus(dialog);
        recordProgress(String(payload.requirement_key || ''), 'step_seen');
        return wrap;
    }

    function start() {
        if (overlay) {
            var dialog = overlay.querySelector('.setup-tutorial-dialog');
            trapFocus(dialog || overlay);
            overlay.addEventListener('click', function (event) {
                // Backdrop clicks never dismiss a required step.
                if (event.target === overlay) {
                    event.preventDefault();
                    var first = focusables(dialog || overlay)[0];
                    if (first) first.focus();
                }
            });
            if (config.current && config.current.key) recordProgress(config.current.key, 'step_seen');
            var configure = document.getElementById('setupTutorialConfigure');
            if (configure && config.current) {
                configure.addEventListener('click', function () { recordProgress(config.current.key, 'step_opened'); });
            }
        }
        if (banner && config.current) {
            recordProgress(config.current.key, 'step_seen');
            var button = document.getElementById('setupTutorialSpotlight');
            if (button) button.addEventListener('click', function () { spotlight(config.current.focus); });
            if (config.onDestination) {
                var hash = window.location.hash || '';
                if (hash.indexOf('#setup-step-') === 0 || true) {
                    window.setTimeout(function () { spotlight(config.current.focus); }, 350);
                }
            }
        }
    }

    window.SetupTutorial = {
        config: config,
        showRequirement: function (payload) { return buildDialog(payload || {}); },
        spotlight: spotlight
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
