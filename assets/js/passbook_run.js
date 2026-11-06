/*
 * The passbook print window (Passbook Printing, Print to Passbook, Update Passbook): every line not yet printed, page
 * by page, in one run. The first page prints when the window opens; when a page is printed and more pages follow, the
 * window asks the teller to turn the passbook to the next page ("Turn the passbook to page 2") and prints it on the
 * next press. After the last page the window says so to the page it was opened from (which asks whether every line
 * printed right) and closes itself.
 */
(function () {
  'use strict';
  const run = document.querySelector('[data-pb-run]');
  if (!run) return;
  const pages = Array.from(run.querySelectorAll('.pb-run-page'));
  const turn = document.querySelector('[data-pb-turn]');
  if (!pages.length) return;
  const q = (s, r) => (r || document).querySelector(s);
  let cur = 0;
  let printing = false;
  let done = false;

  const state = (i, text, cls) => {
    const s = q('[data-state]', pages[i]);
    if (s) s.textContent = text;
    pages[i].classList.remove('is-now', 'is-done');
    if (cls) pages[i].classList.add(cls);
  };

  // only the page being printed goes to the printer; on the screen it is the one marked
  const print = (i) => {
    cur = i;
    printing = true;
    if (turn) turn.hidden = true;
    document.body.classList.add('pb-running');
    pages.forEach((p, k) => p.classList.toggle('is-printing', k === i));
    state(i, 'printing', 'is-now');
    pages[i].scrollIntoView({ block: 'start' });
    window.print();
  };

  const ask = (i) => {
    // page i is next: the passbook is turned to it
    const prev = pages[i - 1];
    const p = pages[i];
    const pg = p.dataset.page;
    q('[data-turn-step]', turn).textContent = 'Page ' + prev.dataset.page + ' is printed: ' + i + ' of ' + pages.length + ' pages done.';
    q('[data-turn-title]', turn).textContent = 'Turn the passbook to page ' + pg;
    q('[data-turn-text]', turn).textContent = 'Take the passbook out of the printer, turn it to page ' + pg + ' and put it back in, open at that page. '
      + (p.dataset.header === '1' ? 'Its header prints first, then line ' + p.dataset.first + '.' : 'Printing goes on at line ' + p.dataset.first + '.')
      + ' ' + p.dataset.count + ' line' + (p.dataset.count === '1' ? '' : 's') + ' to print (' + p.dataset.range + ').';
    q('[data-turn-next] span', turn).textContent = 'Print page ' + pg;
    q('[data-turn-again] span', turn).textContent = 'Print page ' + prev.dataset.page + ' again';
    turn.dataset.next = String(i);
    turn.hidden = false;
    q('[data-turn-next]', turn).focus();
  };

  const finish = () => {
    if (done) return;
    done = true;
    document.body.classList.remove('pb-running');
    const nums = pages.map((p) => Number(p.dataset.page));
    if (typeof window.coopSheetBack === 'function') {
      window.coopSheetBack({ coop: 'passbook-printed', account: run.dataset.account, ledger: run.dataset.ledger, pages: nums });
    }
  };

  window.addEventListener('afterprint', () => {
    if (!printing) return;
    printing = false;
    state(cur, 'printed', 'is-done');
    if (cur < pages.length - 1) ask(cur + 1);
    else finish();
  });

  if (turn) {
    q('[data-turn-next]', turn).addEventListener('click', () => print(Number(turn.dataset.next)));
    q('[data-turn-again]', turn).addEventListener('click', () => print(Number(turn.dataset.next) - 1));
  }

  window.addEventListener('load', () => print(0));
})();
