"use strict";

/* =====================================================================
   The water bill / collection receipt on plain paper (version 57): the Letter sheet shown scaled to the window,
   printed at its own size; an entry longer than its cell is made a little smaller (never below the ratio its
   data-fit-min-ratio allows); every print is counted (registerReceiptPrint.php); autoprint=1 prints at once.
   ===================================================================== */
(() => {
  const stage = document.querySelector("#receiptPreview");
  const scaler = document.querySelector("#receiptPreviewScaler");
  const sheet = document.querySelector("#receiptSheet");
  const printButton = document.querySelector("#printReceiptButton");

  if (!stage || !scaler || !sheet) return;

  const overflows = (el) => el.scrollWidth > el.clientWidth + 0.5;

  const fitOne = (el) => {
    el.style.fontSize = "";
    const base = Number.parseFloat(window.getComputedStyle(el).fontSize);
    if (!Number.isFinite(base) || base <= 0 || !overflows(el)) return;
    const ratio = Number.parseFloat(el.dataset.fitMinRatio || "0.75");
    const min = base * (Number.isFinite(ratio) ? Math.min(1, Math.max(0.6, ratio)) : 0.75);
    let lo = min;
    let hi = base;
    for (let i = 0; i < 16; i += 1) {
      const mid = (lo + hi) / 2;
      el.style.fontSize = `${mid}px`;
      if (overflows(el)) hi = mid; else lo = mid;
    }
    el.style.fontSize = `${lo}px`;
  };

  const fitText = () => sheet.querySelectorAll("[data-fit-text]").forEach(fitOne);

  const fitPreview = () => {
    sheet.style.transform = "none";
    fitText();
    const w = sheet.offsetWidth;
    const h = sheet.offsetHeight;
    const scale = Math.min(1, Math.max(0.2, (stage.clientWidth - 32) / w));
    scaler.style.width = `${w * scale}px`;
    scaler.style.height = `${h * scale}px`;
    sheet.style.transform = `scale(${scale})`;
  };

  let busy = false;

  const registerPrint = async () => {
    const receiptNo = document.body.dataset.receiptNo || "";
    const endpoint = document.body.dataset.printEndpoint || "";
    const csrfToken = document.body.dataset.printCsrf || "";
    if (!receiptNo || !endpoint || !csrfToken) return;
    const response = await fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-Requested-With": "XMLHttpRequest" },
      body: new URLSearchParams({ receipt_no: receiptNo, csrf_token: csrfToken }),
    });
    if (!response.ok) {
      let message = "Receipt print registration failed.";
      try {
        const payload = await response.json();
        if (payload && payload.message) message = payload.message;
      } catch (_) {
        // printing stays possible when the count cannot be kept
      }
      throw new Error(message);
    }
  };

  const printReceipt = async () => {
    if (busy) return;
    busy = true;
    if (printButton) printButton.disabled = true;
    try {
      await registerPrint();
    } catch (error) {
      console.error(error);
    }
    fitText();
    window.print();
  };

  printButton?.addEventListener("click", printReceipt);
  window.addEventListener("resize", () => window.requestAnimationFrame(fitPreview));
  window.addEventListener("beforeprint", () => {
    sheet.style.transform = "none";
    fitText();
    scaler.style.width = "8.5in";
    scaler.style.height = "11in";
  });
  window.addEventListener("afterprint", () => {
    busy = false;
    if (printButton) printButton.disabled = false;
    fitPreview();
  });
  window.addEventListener("load", fitPreview);
  fitPreview();

  if (document.body.dataset.autoprint === "1") {
    window.addEventListener("load", () => window.setTimeout(printReceipt, 300));
  }
})();
