<?php
declare(strict_types=1);

/**
 * The FB POS screen (v35), drawn by modules/pos/register.php in FB mode. It posts the same fields as the POS Register
 * (cart, tenders, do, customer, class, the approval and below-zero answers), so the register's actions save the sale.
 *
 * Left: a search box, the menu groups (All, the groups of Point of Sale > FB POS Menu & Groups, then Other for items
 * without a group) and the items as pictures with their price; a tap adds one to the order. Right: the order (customer,
 * the lines with their quantity, the totals, the payment methods of the POS) and Complete sale / Suspend / Charge.
 * On a phone the order is under the items, with a bar at the bottom that shows the total and goes to it.
 */

$fbGroups = FbPos::groups(true);
$used = [];
$hasOther = false;
foreach ($itemsJs as $it) {
    if (empty($it['fb'])) {
        continue;
    }
    if ($it['grp'] && isset($fbGroups[$it['grp']])) {
        $used[$it['grp']] = true;
    } else {
        $hasOther = true;
    }
}
$tabs = ['all' => 'All'];
foreach ($fbGroups as $gid => $g) {
    if (isset($used[$gid])) {
        $tabs['g' . $gid] = $g['name'];
    }
}
if ($hasOther && count($tabs) > 1) {
    $tabs['other'] = 'Other';
}
$fbCount = count(array_filter($itemsJs, fn($i) => !empty($i['fb'])));
$canMenu = Access::page('pos.fb_menu');
?>
<div class="fb-pos" id="fbPos">
  <section class="fb-menu" aria-label="Menu">
    <div class="fb-find">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input class="form-control" type="search" id="fbSearch" placeholder="Find an item by name, code or barcode" autocomplete="off" aria-label="Find an item">
    </div>
    <div class="fb-tabs" role="tablist" id="fbTabs">
      <?php foreach ($tabs as $k => $label): ?>
        <button type="button" class="fb-tab<?= $k === 'all' ? ' is-on' : '' ?>" role="tab" aria-selected="<?= $k === 'all' ? 'true' : 'false' ?>" data-tab="<?= h($k) ?>"><?= h($label) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="fb-tiles" id="fbTiles" aria-live="polite"></div>
    <div class="fb-empty" id="fbEmpty" hidden>
      <?php if (!$fbCount): ?>
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
        <div class="fw-semibold">No item is on the FB POS yet</div>
        <div class="small text-muted">Tick "Show on the FB POS" on the items to sell here, with their picture and menu group<?= $canMenu ? ':' : '.' ?></div>
        <?php if ($canMenu): ?><a class="btn btn-sm btn-primary mt-2" href="<?= h(url('pos.fb_menu')) ?>"><i class="bi bi-grid-3x3-gap"></i> FB POS Menu &amp; Groups</a><?php endif; ?>
      <?php else: ?>
        <div class="small text-muted">No item matches.</div>
      <?php endif; ?>
    </div>
    <div class="fb-meta small text-muted"><?= h($terminal['name']) ?>, <?= h($terminal['site_name']) ?>, shift opened <?= h(fdatetime($shift['opened_at'])) ?> by <?= h($shift['full_name']) ?></div>
    <?php if ($suspended): ?>
    <div class="card fb-suspended"><div class="card-header small fw-semibold">Suspended orders</div>
      <div class="table-responsive"><table class="table table-sm mb-0">
      <?php foreach ($suspended as $sp): ?><tr><td><?= h(fdatetime($sp['created_at'])) ?></td><td><?= h($sp['username']) ?></td><td><?= h(entity_name($sp)) ?></td><td class="num"><?= money($sp['total']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['recall' => $sp['id']])) ?>">Recall</a></td></tr><?php endforeach; ?>
      </table></div></div>
    <?php endif; ?>
  </section>

  <aside class="fb-order" id="fbOrder" aria-label="Order">
    <form method="post" id="posForm" class="card fb-order-card">
      <?= csrf_field() ?><?= negative_stock_fields() ?>
      <input type="hidden" name="cart" id="cartField">
      <input type="hidden" name="tenders" id="tenderField">
      <input type="hidden" name="do" id="doField" value="<?= h(is_post() && p('do') === 'charge' ? 'charge' : 'complete') ?>">
      <input type="hidden" name="recall_id" value="<?= h($recallId) ?>">
      <div class="fb-order-head"><span class="fw-semibold"><i class="bi bi-bag" aria-hidden="true"></i> Order</span><span class="small text-muted" id="fbCount">0 items</span></div>
      <div class="fb-order-body">
        <?php if ($recallId): ?><div class="alert alert-info py-1 small mb-2">Recalled suspended order #<?= (int)$recallId ?></div><?php endif; ?>
        <label class="form-label" for="posCustomer">Customer</label>
        <select class="form-select form-select-sm" name="customer_id" id="posCustomer"<?= QuickAdd::attr('customer') ?>><?= options(Lookup::customerOptions(false), $initialCustomer ?? p('customer_id'), 'Walk-in customer') ?></select>
        <div class="small text-muted mt-1" id="posLevel"></div>
        <div id="walkinBox" class="mt-2"><input class="form-control form-control-sm" name="walkin_name" maxlength="120" value="<?= h(is_post() ? p('walkin_name') : ($initialWalkin ?? '')) ?>" placeholder="Name on the order (optional)" aria-label="Name on the order"></div>
        <?php if (Classes::ready()): ?>
        <div class="row g-1 align-items-center mt-1"><label class="col-3 col-form-label col-form-label-sm text-muted" for="posClass">Class</label>
          <div class="col-9"><select class="form-select form-select-sm" name="class_id" id="posClass"><?= options(Classes::options(), is_post() ? p('class_id') : (!empty($keptClass) ? $initialClass : ($initialClass ?? ($terminal['class_id'] ?? null))), Classes::inUse() ? '' : 'No classes yet') ?></select></div></div>
        <?php endif; ?>

        <div class="fb-lines" id="fbLines"></div>
        <div class="fb-lines-empty" id="fbLinesEmpty"><i class="bi bi-hand-index" aria-hidden="true"></i> Tap an item to start the order.</div>

        <div class="fb-sums">
          <div><span>Subtotal (net of VAT)</span><span id="tSub">0.00</span></div>
          <div id="discRow" hidden><span>Discounts</span><span id="tDisc">0.00</span></div>
          <div><span>VAT</span><span id="tTax">0.00</span></div>
          <div class="fb-total"><span>Total</span><span id="tTotal">0.00</span></div>
        </div>

        <div id="tenderRows" class="fb-tenders">
          <?php foreach ($posMethods as $m): ?>
            <div class="input-group input-group-sm mb-1">
              <span class="input-group-text tender-name" title="<?= h($m['name']) ?>"><?= h($m['name']) ?></span>
              <input class="form-control text-end tender-amt" data-method="<?= (int)$m['id'] ?>" data-type="<?= h($m['method_type']) ?>" placeholder="0.00" inputmode="decimal" aria-label="<?= h($m['name']) ?> amount">
              <?php if ($m['method_type'] !== 'cash'): ?><input class="form-control tender-ref" data-method="<?= (int)$m['id'] ?>" placeholder="Ref / approval no." aria-label="<?= h($m['name']) ?> reference"><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="fb-change"><span>Change</span><strong id="tChange">0.00</strong></div>
        <div class="fb-change"><span>Balance due</span><strong id="tDue" class="text-danger">0.00</strong></div>
        <input class="form-control form-control-sm mt-2" name="memo" placeholder="Note (optional)" aria-label="Note">
        <?= Approval::fields() ?>
        <div class="d-grid gap-2 mt-3">
          <button class="btn btn-success btn-lg fb-pay" type="submit" data-do="complete"><i class="bi bi-check2-circle"></i> Complete sale</button>
          <div class="btn-group">
            <button class="btn btn-outline-secondary" type="submit" data-do="suspend"><i class="bi bi-pause-circle"></i> Suspend</button>
            <?php if ($accountCustomers): ?><button class="btn btn-outline-primary" type="submit" data-do="charge" title="Approved account customers only"><i class="bi bi-person-badge"></i> Charge</button><?php endif; ?>
            <button class="btn btn-outline-danger" type="button" id="clearCart"><i class="bi bi-x-circle"></i> Clear</button>
          </div>
          <?php if ($recallId): ?><button class="btn btn-sm btn-outline-danger" type="submit" data-do="cancel_suspended">Cancel suspended order</button><?php endif; ?>
        </div>
      </div>
    </form>
    <details class="card fb-account">
      <summary class="card-header small fw-semibold">Customer paying an account balance</summary>
      <form method="post" class="card-body row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="payment">
        <div class="col-12"><select class="form-select form-select-sm" name="pay_customer_id" required aria-label="Customer"><?= options(Lookup::customerOptions(false), null, 'Customer…') ?></select></div>
        <div class="col-6"><input class="form-control form-control-sm text-end" name="pay_amount" placeholder="Amount" required inputmode="decimal" aria-label="Amount"></div>
        <div class="col-6"><select class="form-select form-select-sm" name="pay_method_id" aria-label="Payment method"><?= options(array_column($methods, 'name', 'id'), null) ?></select></div>
        <div class="col-8"><input class="form-control form-control-sm" name="pay_ref" placeholder="Reference" aria-label="Reference"></div>
        <div class="col-4"><button class="btn btn-sm btn-outline-success w-100">Receive</button></div>
      </form>
    </details>
  </aside>
</div>
<button type="button" class="fb-bar" id="fbBar" hidden><span id="fbBarCount">0 items</span><strong id="fbBarTotal">0.00</strong><span class="fb-bar-go">Order <i class="bi bi-chevron-down"></i></span></button>

<script>
(function () {
  const items = <?= json_encode($itemsJs) ?>;
  const levels = <?= json_encode((object)$levels) ?>;
  const customerLevels = <?= json_encode((object)$customerLevels) ?>;
  const {num, r2, fmt} = AppFmt;
  const r4 = (v) => Math.round((v + Number.EPSILON) * 10000) / 10000;
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
  const custSel = document.getElementById('posCustomer');
  const itemOf = (id) => items.find(i => i.id === id);
  const levelOf = () => levels[customerLevels[custSel ? custSel.value : ''] || ''] || null;
  // the price for the customer on the order: a custom price of the level, else the level's percentage (as on the POS)
  const priceOf = (it) => {
    const lv = levelOf();
    if (it.group && !it.own) {
      let sum = 0;
      for (const [mid, q] of (it.members || [])) { const m = itemOf(mid); if (!m) return it.price; sum += r2(q * priceOf(m)); }
      return r2(sum);
    }
    if (!lv) return it.price;
    const custom = lv.custom ? lv.custom[it.id] : undefined;
    return custom !== undefined ? Number(custom) : r2(it.price * (1 + lv.pct / 100));
  };
  const factorOf = (it, c) => { const u = (it.units || []).find(x => String(x.id) === String(c.uom)); return u ? (num(u.factor) || 1) : 1; };
  let cart = <?= json_encode(array_values($initialCart)) ?>;
  const initialTenders = <?= json_encode($initialTenders) ?>;
  const negativeAllowed = <?= $negativeAllowed ? 'true' : 'false' ?>;

  // ---------------------------------------------------------------- the menu
  const tiles = document.getElementById('fbTiles');
  const empty = document.getElementById('fbEmpty');
  const search = document.getElementById('fbSearch');
  let tab = 'all';
  const groupIds = new Set(Array.from(document.querySelectorAll('.fb-tab')).map(b => b.dataset.tab).filter(t => t[0] === 'g').map(t => +t.slice(1)));
  function inTab(it) {
    if (tab === 'all') return true;
    if (tab === 'other') return !it.grp || !groupIds.has(it.grp);
    return 'g' + it.grp === tab;
  }
  function drawTiles() {
    const words = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const list = items.filter(it => it.fb && (words.length
      ? words.every(w => (it.name + ' ' + it.code + ' ' + (it.barcode || '')).toLowerCase().indexOf(w) >= 0)
      : inTab(it)));
    tiles.innerHTML = list.map(it => {
      const pic = it.img
        ? `<img src="${esc(it.img)}" alt="" loading="lazy">`
        : `<span class="fb-ini" aria-hidden="true">${esc(it.ini)}</span>`;
      const inCart = cart.filter(c => c.item_id === it.id).reduce((s, c) => s + num(c.qty), 0);
      const low = it.stock !== null && it.stock <= 0;
      return `<button type="button" class="fb-tile${inCart ? ' in-cart' : ''}" data-id="${it.id}" aria-label="${esc(it.name)}, ${fmt(priceOf(it))}">
        <span class="fb-pic">${pic}${inCart ? `<span class="fb-qty">${esc(r4(inCart))}</span>` : ''}</span>
        <span class="fb-name">${esc(it.name)}</span>
        <span class="fb-price">${fmt(priceOf(it))}</span>
        ${it.stock !== null ? `<span class="fb-stock${low ? ' is-low' : ''}">${low ? 'Out of stock' : 'Stock ' + esc(r4(it.stock))}</span>` : ''}
      </button>`;
    }).join('');
    empty.hidden = list.length > 0;
  }
  document.getElementById('fbTabs').addEventListener('click', (e) => {
    const b = e.target.closest('.fb-tab');
    if (!b) return;
    tab = b.dataset.tab;
    document.querySelectorAll('.fb-tab').forEach(x => { const on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    search.value = '';
    drawTiles();
  });
  search.addEventListener('input', drawTiles);
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    // a scanned barcode or a code typed in full adds the item; otherwise the only match
    const q = search.value.trim().toLowerCase();
    const hit = items.find(i => (i.barcode && String(i.barcode).toLowerCase() === q) || i.code.toLowerCase() === q)
      || (() => { const t = tiles.querySelectorAll('.fb-tile'); return t.length === 1 ? itemOf(+t[0].dataset.id) : null; })();
    if (hit) { add(hit); search.value = ''; drawTiles(); }
  });
  tiles.addEventListener('click', (e) => {
    const b = e.target.closest('.fb-tile');
    if (!b) return;
    const it = itemOf(+b.dataset.id);
    if (!it) return;
    add(it);
    b.classList.remove('is-tapped'); void b.offsetWidth; b.classList.add('is-tapped');
  });

  // ---------------------------------------------------------------- the order
  const lines = document.getElementById('fbLines');
  function add(it) {
    const price = r4(priceOf(it));
    const ex = cart.find(c => c.item_id === it.id && !num(c.disc) && !c.uom && Math.abs(num(c.price) - price) < 0.00005);
    if (ex) ex.qty = r4(num(ex.qty) + 1);
    else cart.push({item_id: it.id, qty: 1, price: price, disc: 0, dk: 'pct', uom: null, auto: true});
    render();
  }
  function lineAmount(c) {
    const gross = r2(num(c.qty) * num(c.price));
    return c.dk === 'amt' ? r2(gross - Math.min(Math.abs(num(c.disc)), gross)) : r2(gross * (1 - num(c.disc) / 100));
  }
  function render() {
    let sub = 0, tax = 0, disc = 0, count = 0;
    lines.innerHTML = '';
    cart.forEach((c, idx) => {
      const it = itemOf(c.item_id);
      if (!it) return;
      const gross = r2(num(c.qty) * num(c.price));
      const amt = lineAmount(c);
      sub += amt; disc += gross - amt; tax += r2(amt * it.tax / 100); count += num(c.qty);
      const unit = (it.units || []).find(u => String(u.id) === String(c.uom));
      const row = document.createElement('div');
      row.className = 'fb-line';
      row.innerHTML = `<div class="fb-line-main"><div class="fb-line-name">${esc(it.name)}</div>
          <div class="fb-line-sub">${fmt(num(c.price))}${unit ? ' per ' + esc(unit.code) : ' each'}${num(c.disc) ? ', less ' + (c.dk === 'amt' ? fmt(num(c.disc)) : esc(num(c.disc)) + '%') : ''}</div></div>
        <div class="fb-step" role="group" aria-label="Quantity of ${esc(it.name)}">
          <button type="button" data-step="-1" aria-label="One less"><i class="bi bi-dash-lg"></i></button>
          <input value="${esc(r4(num(c.qty)))}" inputmode="decimal" aria-label="Quantity">
          <button type="button" data-step="1" aria-label="One more"><i class="bi bi-plus-lg"></i></button></div>
        <div class="fb-line-amt">${fmt(amt)}</div>
        <button type="button" class="fb-line-rm" aria-label="Remove ${esc(it.name)}"><i class="bi bi-x-lg"></i></button>`;
      row.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => {
        c.qty = r4(num(c.qty) + num(b.dataset.step));
        if (c.qty <= 0) cart.splice(idx, 1);
        render();
      }));
      const q = row.querySelector('input');
      q.addEventListener('change', () => { c.qty = r4(num(q.value)); if (c.qty <= 0) cart.splice(idx, 1); render(); });
      q.addEventListener('focus', () => q.select());
      row.querySelector('.fb-line-rm').addEventListener('click', () => { cart.splice(idx, 1); render(); });
      lines.appendChild(row);
    });
    document.getElementById('fbLinesEmpty').hidden = cart.length > 0;
    const total = r2(sub + tax);
    document.getElementById('tSub').textContent = fmt(sub);
    document.getElementById('tDisc').textContent = fmt(disc);
    document.getElementById('discRow').hidden = disc < 0.005;
    document.getElementById('tTax').textContent = fmt(tax);
    document.getElementById('tTotal').textContent = fmt(total);
    const cnt = r4(count) + (r4(count) === 1 ? ' item' : ' items');
    document.getElementById('fbCount').textContent = cnt;
    document.getElementById('fbBarCount').textContent = cnt;
    document.getElementById('fbBarTotal').textContent = fmt(total);
    document.getElementById('fbBar').hidden = !cart.length;
    let paid = 0, cash = 0, nonCash = 0;
    document.querySelectorAll('.tender-amt').forEach(i => { const v = num(i.value); paid += v; if (i.dataset.type === 'cash') cash += v; else nonCash += v; });
    const cashNeeded = Math.max(0, total - nonCash);
    document.getElementById('tChange').textContent = fmt(Math.max(0, cash - cashNeeded));
    document.getElementById('tDue').textContent = fmt(Math.max(0, total - paid));
    window.posTotal = total;
    drawTiles();
  }
  // the customer decides the price level, as on the POS: lines still at a list price follow it
  function applyCustomer(reprice) {
    const lv = levelOf();
    const box = document.getElementById('posLevel');
    if (box) box.textContent = lv ? 'Price level: ' + lv.name : '';
    const walk = document.getElementById('walkinBox');
    if (walk) walk.style.display = custSel && custSel.value ? 'none' : '';
    if (reprice) {
      cart.forEach(c => { const it = itemOf(c.item_id); if (it && c.auto !== false) { c.price = r4(priceOf(it) * factorOf(it, c)); c.auto = true; } });
      render();
    }
  }
  if (custSel) custSel.addEventListener('change', () => applyCustomer(true));
  document.querySelectorAll('.tender-amt').forEach(i => i.addEventListener('input', render));
  document.querySelectorAll('.tender-amt[data-type=cash]').forEach(i => {
    i.addEventListener('focus', () => {
      if (i.value) return;
      i.value = (window.posTotal || 0).toFixed(2);
      render();
      i.select();
      i.dataset.keepSelection = '1';
    });
    i.addEventListener('mouseup', (e) => { if (i.dataset.keepSelection) { e.preventDefault(); delete i.dataset.keepSelection; } });
    i.addEventListener('keydown', () => { delete i.dataset.keepSelection; });
  });
  document.getElementById('clearCart').addEventListener('click', () => { if (!cart.length) { return; } AppDialog.confirm('Remove every item from the order?', { title: 'Clear the order', ok: 'Clear the order', tone: 'danger' }).then((yes) => { if (yes) { cart = []; render(); } }); });
  document.getElementById('fbBar').addEventListener('click', () => { document.getElementById('fbOrder').scrollIntoView({ behavior: 'smooth', block: 'start' }); });

  // ---------------------------------------------------------------- saving: the same fields and questions as the POS
  const form = document.getElementById('posForm');
  form.querySelectorAll('button[data-do]').forEach(b => b.addEventListener('click', () => { document.getElementById('doField').value = b.dataset.do; }));
  const fmtQty = (v) => (Math.abs(v - Math.round(v)) < 1e-9 ? String(Math.round(v)) : String(r4(v))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const shortItems = () => {
    const want = {};
    cart.forEach((c) => { const it = itemOf(c.item_id); if (it && it.stock !== null) want[it.id] = (want[it.id] || 0) + num(c.qty) * factorOf(it, c); });
    return Object.keys(want).map((id) => itemOf(+id)).filter((it) => want[it.id] > num(it.stock) + 0.00001)
      .map((it) => ({ code: it.code, name: it.name, before: fmtQty(num(it.stock)), after: fmtQty(r4(num(it.stock) - want[it.id])) }));
  };
  form.addEventListener('submit', (e) => {
    const doing = document.getElementById('doField').value;
    const answered = form.elements.confirm_negative && form.elements.confirm_negative.value === '1';
    if (negativeAllowed && !answered && (doing === 'complete' || doing === 'charge') && window.AppNegStock) {
      const short = shortItems();
      if (short.length) {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.AppNegStock.ask(short, () => window.AppNegStock.resend(form, null));
        return;
      }
    }
    document.getElementById('cartField').value = JSON.stringify(cart);
    const t = [];
    document.querySelectorAll('.tender-amt').forEach(i => {
      const ref = document.querySelector('.tender-ref[data-method="' + i.dataset.method + '"]');
      if (num(i.value) > 0) t.push({method_id: +i.dataset.method, amount: num(i.value), ref: ref ? ref.value : ''});
    });
    document.getElementById('tenderField').value = JSON.stringify(t);
  });
  cart.forEach(c => { const it = itemOf(c.item_id); if (it && c.auto === undefined) c.auto = Math.abs(num(c.price) - r4(priceOf(it) * factorOf(it, c))) < 0.00005; });
  initialTenders.forEach((t) => {
    const a = document.querySelector('.tender-amt[data-method="' + (+t.method_id) + '"]');
    if (a && num(t.amount) > 0) a.value = num(t.amount).toFixed(2);
    const ref = document.querySelector('.tender-ref[data-method="' + (+t.method_id) + '"]');
    if (ref && t.ref) ref.value = t.ref;
  });
  applyCustomer(false);
  render();
})();
</script>
