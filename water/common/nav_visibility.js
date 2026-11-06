(function enforceVisiblePagePermissions(config) {
    if (!config || config.superAdmin) return;

    const protectedFiles = new Set((config.protectedFiles || []).map(String));
    const allowedFiles = new Set((config.allowedFiles || []).map(String));

    function localPhpFile(rawValue) {
        if (!rawValue || typeof rawValue !== 'string') return '';
        const match = rawValue.match(/(?:location(?:\.href)?\s*=|window\.open\s*\()?\s*['"]([^'"]+\.php(?:\?[^'"]*)?)/i);
        const candidate = match ? match[1] : rawValue;
        try {
            const url = new URL(candidate, window.location.href);
            if (url.origin !== window.location.origin) return '';
            const parts = url.pathname.split('/').filter(Boolean);
            return parts.length ? parts[parts.length - 1] : '';
        } catch (error) {
            return '';
        }
    }

    function forbiddenTarget(element) {
        let raw = '';
        if (element.matches('a[href]')) raw = element.getAttribute('href') || '';
        else if (element.hasAttribute('data-href')) raw = element.getAttribute('data-href') || '';
        else if (element.hasAttribute('onclick')) raw = element.getAttribute('onclick') || '';
        else if (element.matches('form[action]')) raw = element.getAttribute('action') || '';

        const file = localPhpFile(raw);
        return file !== '' && protectedFiles.has(file) && !allowedFiles.has(file);
    }

    function hideForbidden(element) {
        if (!(element instanceof Element) || !forbiddenTarget(element)) return;

        // Whole-page action cards should disappear, not remain as a visible
        // card with a missing button. Ordinary row/detail links are hidden
        // individually so the surrounding record remains readable.
        let visibilityTarget = element;
        if (!element.matches('.action-card, .quick-action-card')) {
            const explicitContainer = element.closest('[data-access-page-container]');
            const settingsCard = element.matches('a.btn, button.btn')
                ? element.closest('.grid > .card')
                : null;
            visibilityTarget = explicitContainer || settingsCard || element;
        }

        visibilityTarget.hidden = true;
        visibilityTarget.setAttribute('aria-hidden', 'true');
        visibilityTarget.setAttribute('data-access-hidden', '1');
        if (element.matches('a[href]')) element.removeAttribute('href');
        if (element.matches('button, [tabindex]')) element.setAttribute('tabindex', '-1');
    }

    function inspect(root) {
        if (!(root instanceof Element || root instanceof Document)) return;
        if (root instanceof Element && root.matches('a[href], [data-href], [onclick], form[action]')) {
            hideForbidden(root);
        }
        root.querySelectorAll('a[href], [data-href], [onclick], form[action]').forEach(hideForbidden);
    }

    inspect(document);
    const observer = new MutationObserver(records => {
        for (const record of records) {
            record.addedNodes.forEach(node => {
                if (node instanceof Element) inspect(node);
            });
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', () => inspect(document), { once: true });
})(window.WSH_ACCESS || {});

