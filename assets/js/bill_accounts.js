/* =====================================================================
   Bill Accounts Center (Water Billing > Billing): the Export links and Refresh follow the filters and the search as
   they change (the search as you type replaces the lines and the address of the page, js/savings.js); the export
   holds every line found, not only the page shown.
   ===================================================================== */
(function () {
  'use strict';
  const bar = document.getElementById('baBar');
  if (!bar) return;
  const follow = () => {
    const u = new URL(window.location.href);
    u.searchParams.delete('partial');
    u.searchParams.delete('pg');
    bar.querySelectorAll('[data-ba-export]').forEach((a) => {
      const x = new URL(u.href);
      x.searchParams.set('export', a.dataset.baExport);
      a.href = x.href;
    });
    const p = new URL(u.href);
    p.searchParams.set('a', 'print');
    bar.querySelectorAll('[data-print-link]').forEach((a) => { a.href = p.href; });
    const r = bar.querySelector('[data-ba-refresh]');
    if (r) r.href = u.href;
  };
  document.addEventListener('sv:rows', follow);
})();
