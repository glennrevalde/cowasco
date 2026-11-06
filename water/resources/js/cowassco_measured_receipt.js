"use strict";

(() => {
  const stage = document.querySelector("#receiptPreview");
  const scaler = document.querySelector("#receiptPreviewScaler");
  const sheet = document.querySelector("#receiptSheet");
  const printButton = document.querySelector("#printReceiptButton");

  if (!stage || !scaler || !sheet) return;

  const overflows = (element) =>
    element.scrollWidth > element.clientWidth + 0.5 ||
    element.scrollHeight > element.clientHeight + 0.5;

  const fitOneTextNode = (element) => {
    element.style.fontSize = "";

    const baseSize = Number.parseFloat(window.getComputedStyle(element).fontSize);
    if (!Number.isFinite(baseSize) || baseSize <= 0 || !overflows(element)) return;

    const configuredRatio = Number.parseFloat(element.dataset.fitMinRatio || "0.62");
    const minimumRatio = Number.isFinite(configuredRatio)
      ? Math.min(1, Math.max(0.35, configuredRatio))
      : 0.62;

    let lower = baseSize * minimumRatio;
    let upper = baseSize;

    for (let attempt = 0; attempt < 18; attempt += 1) {
      const candidate = (lower + upper) / 2;
      element.style.fontSize = `${candidate}px`;

      if (overflows(element)) {
        upper = candidate;
      } else {
        lower = candidate;
      }
    }

    element.style.fontSize = `${lower}px`;
  };

  const fitReceiptText = () => {
    sheet.querySelectorAll("[data-fit-text]").forEach(fitOneTextNode);
  };

  const fitPreview = () => {
    sheet.style.transform = "none";
    fitReceiptText();

    const sheetWidth = sheet.offsetWidth;
    const sheetHeight = sheet.offsetHeight;
    const availableWidth = Math.max(1, stage.clientWidth - 42);
    const scale = Math.min(1, availableWidth / sheetWidth);

    scaler.style.width = `${sheetWidth * scale}px`;
    scaler.style.height = `${sheetHeight * scale}px`;
    sheet.style.transform = `scale(${scale})`;
    sheet.style.transformOrigin = "top left";
  };

  let printInProgress = false;

  const registerPrint = async () => {
    const receiptNo = document.body.dataset.receiptNo || "";
    const endpoint = document.body.dataset.printEndpoint || "";
    const csrfToken = document.body.dataset.printCsrf || "";
    if (!receiptNo || !endpoint || !csrfToken) return;

    const body = new URLSearchParams({ receipt_no: receiptNo, csrf_token: csrfToken });
    const response = await fetch(endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "X-Requested-With": "XMLHttpRequest",
      },
      body,
    });

    if (!response.ok) {
      let message = "Receipt print registration failed.";
      try {
        const payload = await response.json();
        if (payload && payload.message) message = payload.message;
      } catch (_) {
        // Printing must remain possible when the audit counter is unavailable.
      }
      throw new Error(message);
    }
  };

  const printReceipt = async () => {
    if (printInProgress) return;
    printInProgress = true;
    if (printButton) printButton.disabled = true;

    try {
      await registerPrint();
    } catch (error) {
      console.error(error);
    }

    fitReceiptText();
    window.print();
  };

  printButton?.addEventListener("click", printReceipt);
  window.addEventListener("resize", () => window.requestAnimationFrame(fitPreview));
  window.addEventListener("beforeprint", () => {
    fitReceiptText();
    sheet.style.transform = "none";
    scaler.style.width = "22cm";
    scaler.style.height = "10cm";
  });
  window.addEventListener("afterprint", () => {
    printInProgress = false;
    if (printButton) printButton.disabled = false;
    fitPreview();
  });
  window.addEventListener("load", fitPreview);

  if (document.body.dataset.autoprint === "1") {
    window.addEventListener("load", () => window.setTimeout(printReceipt, 300));
  }
})();
