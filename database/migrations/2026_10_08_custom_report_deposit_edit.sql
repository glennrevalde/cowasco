-- =====================================================================
--  G-SMaRT Accounting - migration: Customized Inventory Report, editing deposits
--
--  What it changes
--    1. A new report, Inventory > Reports > Customized Inventory Report (rpt.inv_custom): one line
--       per item with the columns the user picks (gross profit per item, gross profit %, stock,
--       value, prices, sales and purchases of a period). It is given to the roles that may open
--       the Inventory Valuation Summary, with the same print right.
--    2. An empty column on transaction_lines, pos_shift_id: on a deposit, the POS shift whose cash
--       the line takes to the bank. It lets a deposit be edited, and lets a deposit follow a later
--       correction of the shift's closing entries. The lines of the deposits already made are
--       linked to their shifts by the words "(shift #n)" the program wrote on them.
--
--  Nothing is deleted and no amount is changed.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  With several companies, run it on every company database (the Super Admin's
--  Companies > Database updates > Update all companies does that).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_10_08_custom_report_deposit_edit.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The Customized Inventory Report, given to the roles that may open the Inventory Valuation Summary
-- ---------------------------------------------------------------------

SET @inv_module = (SELECT id FROM modules WHERE code = 'inv' LIMIT 1);
SET @inv_sort   = (SELECT COALESCE(MAX(sort_order), 600) FROM pages WHERE page_key IN ('rpt.inv_no_movement', 'rpt.negative_inventory'));

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.inv_custom', @inv_module, 'Customized Inventory Report', 'report', 'Inventory Reports', @inv_sort + 2, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.inv_valuation_summary'
  JOIN pages dst ON dst.page_key = 'rpt.inv_custom'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  2. The POS shift of a deposit line
--     Written so the file can be run a second time without an error.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines ADD COLUMN pos_shift_id INT UNSIGNED NULL AFTER ref_txn_id',
  'SELECT ''transaction_lines: pos_shift_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'pos_shift_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines ADD KEY ix_tl_shift (pos_shift_id)',
  'SELECT ''transaction_lines: ix_tl_shift already exists''')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND index_name = 'ix_tl_shift');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the lines of the deposits already made: "POS cash <terminal> <date> (shift #n)"
UPDATE transaction_lines l
  JOIN pos_shifts s ON s.deposit_txn_id = l.txn_id
   SET l.pos_shift_id = s.id
 WHERE l.pos_shift_id IS NULL AND l.line_kind = 'deposit'
   AND l.description LIKE CONCAT('%(shift #', s.id, ')');

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Customized Inventory Report, deposit editing', 'Migration 2026_10_08: Customized Inventory Report page, POS shift on deposit lines');

-- Done. Next steps in the application:
--   Inventory > Reports > Customized Inventory Report: pick the columns in its Columns box.
--   Banking > Make Deposits > open a deposit > Edit.
