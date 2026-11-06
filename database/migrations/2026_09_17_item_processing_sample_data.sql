-- =====================================================================
--  Accounting System - OPTIONAL sample data for Disassemble / Process Items
--
--  Run AFTER 2026_09_17_item_processing.sql, only if you want examples to try.
--  Adds 4 items and 3 processing formulas (no transactions):
--    BRK-USB50  Break bulk: a box of 50 USB drives -> 50 single drives   (de-kitting, physical units)
--    TD-PC      Trade-in PC teardown -> RAM, SSD + scrap metal by-product (disassembly, relative sales value)
--    RFB-PC     Trade-in PC + new SSD -> refurbished PC + recovered RAM   (co-product, fixed percentages)
--  Uses the sample items RAM-8GB, SSD-512 and USB-032, the Accessories / Computers / Supplies
--  categories and accounts 4000, 5000, 1200, 5200. Anything missing is simply skipped.
--  Safe to run more than once. MySQL 5.5.3+ / MariaDB 5.5+.
-- =====================================================================

SET NAMES utf8mb4;

SET @acct_income = (SELECT id FROM accounts WHERE code = '4000' LIMIT 1);
SET @acct_cogs   = (SELECT id FROM accounts WHERE code = '5000' LIMIT 1);
SET @acct_asset  = (SELECT id FROM accounts WHERE code = '1200' LIMIT 1);
SET @acct_labor  = (SELECT id FROM accounts WHERE code = '5200' LIMIT 1);
SET @tax_vat     = (SELECT id FROM tax_codes WHERE code = 'VAT12' LIMIT 1);
SET @tax_ex      = (SELECT id FROM tax_codes WHERE code = 'EX' LIMIT 1);
SET @vendor_td   = (SELECT id FROM vendors WHERE name = 'TechDistributors Inc.' LIMIT 1);

-- ---------------------------------------------------------------------
--  Items
-- ---------------------------------------------------------------------
INSERT IGNORE INTO items (item_type, code, barcode, name, description, purchase_description, category_id, unit, cost, avg_cost, sales_price,
                          income_account_id, cogs_account_id, asset_account_id, tax_code_id, preferred_vendor_id, reorder_point, max_stock, is_active)
VALUES
  ('inventory', 'USB-032-BX50', '4800000000110', 'USB Flash Drive 32GB (Box of 50)', 'USB Flash Drive 32GB, box of 50', 'USB Flash Drive 32GB, box of 50',
   (SELECT id FROM item_categories WHERE name = 'Accessories' LIMIT 1), 'box', 8500, 0, 16500, @acct_income, @acct_cogs, @acct_asset, @tax_vat, @vendor_td, 1, 5, 1),
  ('inventory', 'TRD-PC', NULL, 'Trade-in Desktop PC (used)', 'Used desktop PC taken in for parts / refurbishing', 'Trade-in desktop PC',
   (SELECT id FROM item_categories WHERE name = 'Computers' LIMIT 1), 'pc', 3000, 0, 0, @acct_income, @acct_cogs, @acct_asset, @tax_vat, NULL, 0, 0, 1),
  ('inventory', 'REF-PC', NULL, 'Refurbished Desktop PC', 'Refurbished desktop PC with new SSD, 90-day warranty', NULL,
   (SELECT id FROM item_categories WHERE name = 'Computers' LIMIT 1), 'pc', 0, 0, 14990, @acct_income, @acct_cogs, @acct_asset, @tax_vat, NULL, 0, 0, 1),
  ('inventory', 'SCRAP-KG', NULL, 'Scrap Metal and Plastic', 'Scrap metal and plastic (sold by the kilo)', NULL,
   (SELECT id FROM item_categories WHERE name = 'Supplies' LIMIT 1), 'kg', 15, 0, 25, @acct_income, @acct_cogs, @acct_asset, COALESCE(@tax_ex, @tax_vat), NULL, 0, 0, 1);

-- ---------------------------------------------------------------------
--  Formulas
-- ---------------------------------------------------------------------
INSERT IGNORE INTO process_formulas (code, name, process_type, alloc_method, description, is_active) VALUES
  ('BRK-USB50', 'Break bulk: USB 32GB box of 50', 'dekit', 'quantity', 'Open a box of 50 and sell the drives one by one.', 1),
  ('TD-PC', 'Trade-in PC teardown', 'disassembly', 'sales_value', 'Strip a trade-in PC for resaleable parts; the chassis is sold as scrap by the kilo.', 1),
  ('RFB-PC', 'Refurbish trade-in PC (new SSD)', 'co_product', 'percent', 'Trade-in PC plus a new SSD become a refurbished PC; the old RAM is recovered for resale.', 1);

-- Lines are added only to formulas that have none yet.

-- BRK-USB50: 1 box in, 50 drives out
SET @f = (SELECT f.id FROM process_formulas f WHERE f.code = 'BRK-USB50' AND NOT EXISTS (SELECT 1 FROM process_formula_lines x WHERE x.formula_id = f.id));
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 1, 'input', i.id, NULL, 1, NULL, 0, 0, NULL FROM items i WHERE i.code = 'USB-032-BX50' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 2, 'output', i.id, NULL, 50, 'co_product', 0, 0, NULL FROM items i WHERE i.code = 'USB-032' AND @f IS NOT NULL;

-- TD-PC: 1 trade-in PC + 1 hour labor -> 2 RAM, 1 SSD, 4 kg scrap (by-product at 15.00 / kg)
SET @f = (SELECT f.id FROM process_formulas f WHERE f.code = 'TD-PC' AND NOT EXISTS (SELECT 1 FROM process_formula_lines x WHERE x.formula_id = f.id));
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 1, 'input', i.id, NULL, 1, NULL, 0, 0, NULL FROM items i WHERE i.code = 'TRD-PC' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 2, 'cost', NULL, @acct_labor, 0, NULL, 0, 150, 'Technician labor (1 hour)' FROM DUAL WHERE @f IS NOT NULL AND @acct_labor IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 3, 'output', i.id, NULL, 2, 'co_product', 0, 0, NULL FROM items i WHERE i.code = 'RAM-8GB' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 4, 'output', i.id, NULL, 1, 'co_product', 0, 0, NULL FROM items i WHERE i.code = 'SSD-512' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 5, 'output', i.id, NULL, 4, 'by_product', 15, 0, NULL FROM items i WHERE i.code = 'SCRAP-KG' AND @f IS NOT NULL;

-- RFB-PC: trade-in PC + new SSD + labor -> refurbished PC (85%) + 2 recovered RAM (15%) + 1 kg scrap (no value)
SET @f = (SELECT f.id FROM process_formulas f WHERE f.code = 'RFB-PC' AND NOT EXISTS (SELECT 1 FROM process_formula_lines x WHERE x.formula_id = f.id));
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 1, 'input', i.id, NULL, 1, NULL, 0, 0, NULL FROM items i WHERE i.code = 'TRD-PC' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 2, 'input', i.id, NULL, 1, NULL, 0, 0, NULL FROM items i WHERE i.code = 'SSD-512' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 3, 'cost', NULL, @acct_labor, 0, NULL, 0, 400, 'Refurbishing labor and testing' FROM DUAL WHERE @f IS NOT NULL AND @acct_labor IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 4, 'output', i.id, NULL, 1, 'co_product', 85, 0, NULL FROM items i WHERE i.code = 'REF-PC' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 5, 'output', i.id, NULL, 2, 'co_product', 15, 0, NULL FROM items i WHERE i.code = 'RAM-8GB' AND @f IS NOT NULL;
INSERT INTO process_formula_lines (formula_id, line_no, line_type, item_id, account_id, qty, output_role, alloc_basis, amount, description)
SELECT @f, 6, 'output', i.id, NULL, 1, 'scrap', 0, 0, NULL FROM items i WHERE i.code = 'SCRAP-KG' AND @f IS NOT NULL;

-- Try them: Inventory > Processing Formulas > Run (buy a few USB-032-BX50 / TRD-PC first with Enter Bills).
