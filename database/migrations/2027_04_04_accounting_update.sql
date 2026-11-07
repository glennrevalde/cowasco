-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 53, the accounting update
--
--  Brings the accounting side of the program up to G-SMaRT Accounting version 34. The cooperative,
--  member, savings, loan and water-billing tables are not touched.
--
--  What it changes
--    1. Item groups: a group price with its price variance account (items.group_variance_account_id),
--       the group a sales line belongs to (transaction_lines.group_*), the item groups sold
--       (table item_group_sales) and the default account Item Group Price Variance (a new income
--       account, code 4070 or the first free of 4071, 4075, 4080, 4090).
--    2. Receive Items lists: how a bill was received (transactions.received_via), filled in for the
--       item receipts and bills already entered.
--    3. Make Deposits: the card and e-wallet receipts ticked to settle (table clearing_settlements).
--    4. Average cost on the books: the quantity on hand of every item at every site is set to the total
--       of its movements, and the average cost to the value left over the quantity left.
--    5. New reports with their page access (each given to the roles that open the report it sits next to):
--         Ending Inventory - POS Cashier (also to the roles with POS Shifts, so the cashiers), Sales by Item
--         Group, POS Sales by Item Group, the Comparative Income Statements and Balance Sheets by Month,
--         Quarter, Year and Custom Range / Date, Average Cost Changes, Trial Balance by Class and Statement
--         of Cash Flows by Class.
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database of version 52 or earlier (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_04_04_accounting_update.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Item groups
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE items ADD COLUMN group_variance_account_id INT UNSIGNED NULL AFTER tax_code_id',
  'SELECT ''items: group_variance_account_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'group_variance_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines ADD COLUMN group_item_id INT UNSIGNED NULL AFTER other_name_id, ADD COLUMN group_ref INT UNSIGNED NULL AFTER group_item_id, ADD COLUMN group_qty DECIMAL(15,4) NULL AFTER group_ref, ADD COLUMN group_rate DECIMAL(15,4) NULL AFTER group_qty, ADD COLUMN group_role VARCHAR(10) NULL AFTER group_rate, ADD KEY ix_tl_group (group_item_id)',
  'SELECT ''transaction_lines: group columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'group_item_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS item_group_sales (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,
  group_ref      INT UNSIGNED NOT NULL DEFAULT 0,
  group_item_id  INT UNSIGNED NOT NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  txn_date       DATE         NOT NULL,
  customer_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  terminal_id    INT UNSIGNED NULL,
  class_id       INT UNSIGNED NULL,
  sales_rep_id   INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the price variance account: an income account of its own, unless one by that name is there already
SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '4070' AS c, 1 AS o UNION ALL SELECT '4071', 2 UNION ALL SELECT '4075', 3 UNION ALL SELECT '4080', 4 UNION ALL SELECT '4090', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Item Group Price Variance', 'Income', 'Income', 'Credit', 'Item groups sold: the group price less the prices of the items in the group'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('group_price_variance', 'Item Group Price Variance', 'inv', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'group_price_variance' AND account_id IS NULL;

-- ---------------------------------------------------------------------
--  2. Receive Items lists: how each bill was received
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions ADD COLUMN received_via VARCHAR(12) NULL AFTER other_name_id',
  'SELECT ''transactions: received_via already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'received_via');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE transactions SET received_via = 'receipt' WHERE txn_type = 'item_receipt' AND received_via IS NULL;
-- a bill an item receipt became when its bill was entered
UPDATE transactions t
  JOIN audit_trail a ON a.record_type = 'transaction' AND a.record_id = t.id AND a.action = 'convert'
   SET t.received_via = 'receipt'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL;
-- a bill entered with Receive Items and Enter Bills against a purchase order
UPDATE transactions t
   SET t.received_via = 'bill'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL AND t.memo LIKE 'Received against %';

-- ---------------------------------------------------------------------
--  3. Make Deposits: the card and e-wallet receipts ticked to settle
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS clearing_settlements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deposit_txn_id  BIGINT UNSIGNED NOT NULL,                -- the deposit that holds the settlement
  account_id      INT UNSIGNED    NOT NULL,                -- the card / e-wallet clearing account
  receipt_txn_id  BIGINT UNSIGNED NOT NULL,                -- the POS sale, payment or sales receipt settled
  amount          DECIMAL(15,2)   NOT NULL DEFAULT 0,      -- what of it was settled
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_deposit (deposit_txn_id),
  KEY ix_cs_receipt (account_id, receipt_txn_id),
  KEY ix_cs_receipt_txn (receipt_txn_id),
  CONSTRAINT fk_cs_deposit FOREIGN KEY (deposit_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_receipt FOREIGN KEY (receipt_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. Average cost on the books
-- ---------------------------------------------------------------------

-- the quantity at each site is the total of the movements there (a site row removed at zero comes back)
INSERT INTO item_site_stock (item_id, site_id, qty_on_hand)
SELECT m.item_id, m.site_id, SUM(m.qty)
  FROM inventory_movements m
  JOIN items i ON i.id = m.item_id
  JOIN sites s ON s.id = m.site_id
 GROUP BY m.item_id, m.site_id
ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand);

-- a site row with no movement holds nothing
UPDATE item_site_stock x
  LEFT JOIN (SELECT DISTINCT item_id, site_id FROM inventory_movements) m
         ON m.item_id = x.item_id AND m.site_id = x.site_id
   SET x.qty_on_hand = 0
 WHERE m.item_id IS NULL AND x.qty_on_hand <> 0;

-- the average cost is the value left over the quantity left (with nothing left, the last average stays)
UPDATE items i
  JOIN (SELECT item_id, SUM(qty) AS q, SUM(total_cost) AS v FROM inventory_movements GROUP BY item_id) b
    ON b.item_id = i.id
   SET i.avg_cost = ROUND(b.v / b.q, 4)
 WHERE i.item_type IN ('inventory', 'assembly') AND b.q > 0.000001 AND b.v > 0.004;

-- ---------------------------------------------------------------------
--  5. The new reports and their page access
-- ---------------------------------------------------------------------

SET @m_pos  = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @m_cust = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @m_coa  = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @m_inv  = COALESCE((SELECT module_id FROM pages WHERE page_key = 'rpt.inv_valuation_detail' LIMIT 1), (SELECT id FROM modules WHERE code = 'inv' LIMIT 1));

SET @s_pei = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key IN ('rpt.pos_void_log', 'rpt.pos_discount_log', 'rpt.pos_readings'));
SET @s_sig = (SELECT COALESCE(MAX(sort_order), 200) FROM pages WHERE page_key = 'rpt.sales_item_detail');
SET @s_pig = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key = 'rpt.pos_sales_item');
SET @s_cmp = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'rpt.income_statement_class');
SET @s_acc = (SELECT COALESCE(MAX(sort_order), 1240) FROM pages WHERE page_key = 'rpt.inv_valuation_detail');
SET @s_tb  = (SELECT COALESCE(MAX(sort_order), 160) FROM pages WHERE page_key = 'rpt.trial_balance');
SET @s_cf  = (SELECT COALESCE(MAX(sort_order), 260) FROM pages WHERE page_key = 'rpt.cash_flow');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.pos_ending_inventory', @m_pos,  'Ending Inventory - POS Cashier',               'report', 'Reports',           @s_pei + 1, 1),
  ('rpt.sales_item_group',     @m_cust, 'Sales by Item Group',                          'report', 'Sales Reports',     @s_sig + 1, 1),
  ('rpt.pos_sales_item_group', @m_pos,  'POS Sales by Item Group',                      'report', 'Reports',           @s_pig + 1, 1),
  ('rpt.cmp_is_month',         @m_coa,  'Comparative Income Statement by Month',        'report', 'Reports',           @s_cmp + 1, 1),
  ('rpt.cmp_is_quarter',       @m_coa,  'Comparative Income Statement by Quarter',      'report', 'Reports',           @s_cmp + 2, 1),
  ('rpt.cmp_is_year',          @m_coa,  'Comparative Income Statement by Year',         'report', 'Reports',           @s_cmp + 3, 1),
  ('rpt.cmp_is_custom',        @m_coa,  'Comparative Income Statement by Custom Range', 'report', 'Reports',           @s_cmp + 4, 1),
  ('rpt.cmp_bs_month',         @m_coa,  'Comparative Balance Sheet by Month',           'report', 'Reports',           @s_cmp + 5, 1),
  ('rpt.cmp_bs_quarter',       @m_coa,  'Comparative Balance Sheet by Quarter',         'report', 'Reports',           @s_cmp + 6, 1),
  ('rpt.cmp_bs_year',          @m_coa,  'Comparative Balance Sheet by Year',            'report', 'Reports',           @s_cmp + 7, 1),
  ('rpt.cmp_bs_custom',        @m_coa,  'Comparative Balance Sheet by Custom Date',     'report', 'Reports',           @s_cmp + 8, 1),
  ('rpt.avg_cost_changes',     @m_inv,  'Average Cost Changes',                         'report', 'Inventory Reports', @s_acc + 5, 1),
  ('rpt.trial_balance_class',  @m_coa,  'Trial Balance by Class',                       'report', 'Reports',           @s_tb + 5,  1),
  ('rpt.cash_flow_class',      @m_coa,  'Statement of Cash Flows by Class',             'report', 'Reports',           @s_cf + 5,  1);

-- each new report is given to the roles that open the report beside it, with the same Print right
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id
  JOIN pages dst ON (sp.page_key = 'rpt.pos_readings'         AND dst.page_key = 'rpt.pos_ending_inventory')
                 OR (sp.page_key = 'rpt.sales_item_summary'   AND dst.page_key = 'rpt.sales_item_group')
                 OR (sp.page_key = 'rpt.pos_sales_item'       AND dst.page_key = 'rpt.pos_sales_item_group')
                 OR (sp.page_key = 'rpt.income_statement'     AND dst.page_key IN ('rpt.cmp_is_month', 'rpt.cmp_is_quarter', 'rpt.cmp_is_year', 'rpt.cmp_is_custom'))
                 OR (sp.page_key = 'rpt.balance_sheet'        AND dst.page_key IN ('rpt.cmp_bs_month', 'rpt.cmp_bs_quarter', 'rpt.cmp_bs_year', 'rpt.cmp_bs_custom'))
                 OR (sp.page_key = 'rpt.inv_valuation_detail' AND dst.page_key = 'rpt.avg_cost_changes')
                 OR (sp.page_key = 'rpt.trial_balance'        AND dst.page_key = 'rpt.trial_balance_class')
                 OR (sp.page_key = 'rpt.cash_flow'            AND dst.page_key = 'rpt.cash_flow_class')
 WHERE src.allowed = 1;

-- the cashiers print the ending inventory of their own shift
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, 1, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.shifts'
  JOIN pages dst ON dst.page_key = 'rpt.pos_ending_inventory'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  6. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Accounting update (version 53)',
        'Migration 2027_04_04: item groups, Receive Items lists, settle ticks, average cost on the books; 14 reports (POS ending inventory, item group sales, comparative statements, average cost changes, class reports)');
