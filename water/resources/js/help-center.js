(function () {
    'use strict';

    const searchInput = document.querySelector('[data-help-search]');
    const clearButton = document.querySelector('[data-help-clear]');
    const articles = Array.from(document.querySelectorAll('[data-help-article]'));
    const filters = Array.from(document.querySelectorAll('[data-help-filter]'));
    const countNode = document.querySelector('[data-help-count]');
    const emptyNode = document.querySelector('[data-help-empty]');
    let activeCategory = 'all';

    function normalize(value) {
        return String(value || '').toLowerCase().trim().replace(/\s+/g, ' ');
    }

    function applyFilters() {
        const query = normalize(searchInput ? searchInput.value : '');
        let visible = 0;

        articles.forEach(function (article) {
            const matchesCategory = activeCategory === 'all' || article.dataset.category === activeCategory;
            const matchesQuery = query === '' || normalize(article.dataset.search).includes(query);
            const show = matchesCategory && matchesQuery;
            article.hidden = !show;
            if (show) visible += 1;
        });

        if (countNode) countNode.textContent = String(visible);
        if (emptyNode) emptyNode.classList.toggle('visible', visible === 0);
        if (clearButton) clearButton.classList.toggle('visible', query !== '');
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                searchInput.value = '';
                applyFilters();
                searchInput.blur();
            }
        });
    }

    if (clearButton && searchInput) {
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            applyFilters();
            searchInput.focus();
        });
    }

    filters.forEach(function (filter) {
        filter.addEventListener('click', function () {
            activeCategory = filter.dataset.helpFilter || 'all';
            filters.forEach(function (item) {
                const active = item === filter;
                item.classList.toggle('active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            applyFilters();
        });
    });

    document.querySelectorAll('[data-copy-anchor]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const anchor = button.dataset.copyAnchor || '';
            const url = window.location.origin + window.location.pathname + '#' + anchor;
            try {
                await navigator.clipboard.writeText(url);
                const original = button.textContent;
                button.textContent = 'Link copied';
                window.setTimeout(function () { button.textContent = original; }, 1500);
            } catch (error) {
                window.location.hash = anchor;
            }
        });
    });

    if (window.location.hash) {
        const target = document.querySelector(window.location.hash);
        if (target && target.tagName === 'DETAILS') target.open = true;
    }

    applyFilters();
})();
