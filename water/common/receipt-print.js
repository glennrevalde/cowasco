/* =====================================================================
   A receipt printed on a pre-printed form (version 46): the page shows the form scaled to the window, prints it at
   its own size (the @page size of the layout), counts the print (registerReceiptPrint.php, as the printed receipt
   does) and prints at once when opened with autoprint=1.
   ===================================================================== */
(function () {
  'use strict';
  const stage = document.getElementById('receiptPreview');
  const fit = document.getElementById('receiptSheet');
  const page = fit ? fit.querySelector('.wrl-page') : null;
  const printButton = document.getElementById('printReceiptButton');
  if (!stage || !fit || !page) return;

  function fitPreview() {
    fit.style.transform = 'none';
    fit.style.width = '';
    fit.style.height = '';
    const w = page.offsetWidth, h = page.offsetHeight;
    const scale = Math.min(1, Math.max(0.2, (stage.clientWidth - 8) / w));
    fit.style.transform = 'scale(' + scale + ')';
    fit.style.width = (w * scale) + 'px';
    fit.style.height = (h * scale) + 'px';
  }

  let busy = false;
  async function register() {
    const no = document.body.dataset.receiptNo || '';
    const endpoint = document.body.dataset.printEndpoint || '';
    const csrf = document.body.dataset.printCsrf || '';
    if (!no || !endpoint || !csrf) return;
    const r = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ receipt_no: no, csrf_token: csrf }) });
    if (!r.ok) throw new Error('Receipt print registration failed.');
  }
  async function printReceipt() {
    if (busy) return;
    busy = true;
    if (printButton) printButton.disabled = true;
    try { await register(); } catch (e) { console.error(e); }   // printing stays possible without the count
    window.print();
  }
  if (printButton) printButton.addEventListener('click', printReceipt);
  window.addEventListener('beforeprint', () => { fit.style.transform = 'none'; fit.style.width = ''; fit.style.height = ''; });
  window.addEventListener('afterprint', () => { busy = false; if (printButton) printButton.disabled = false; fitPreview(); });
  window.addEventListener('resize', () => window.requestAnimationFrame(fitPreview));
  window.addEventListener('load', fitPreview);
  fitPreview();
  if (document.body.dataset.autoprint === '1') window.addEventListener('load', () => window.setTimeout(printReceipt, 300));
})();
