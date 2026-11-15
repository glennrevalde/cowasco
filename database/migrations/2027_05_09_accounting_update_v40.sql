-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 60, the accounting update
--
--  Brings the accounting side of the program up to G-SMaRT Accounting version 40 (its versions 35
--  to 40). The cooperative, member, savings, loan and water-billing tables are not touched.
--
--  What it changes
--    1. FB POS (accounting v35): four columns on items (fb_pos: shown on the FB POS, 0 at first;
--       fb_group_id: its menu group; fb_sort: its order; image_file: its picture), the table
--       fb_pos_groups (the menu groups, empty at first), and two pages under Point of Sale:
--         FB POS                  for the roles that may open the POS Register, with the same rights
--         FB POS Menu & Groups    for the roles that may open POS Terminals, with the same rights
--    2. Cash Count Sheet (accounting v38): the table pos_cash_counts (the bills and coins counted
--       when a shift is closed, empty at first) and the report Point of Sale > Reports > Cash Count
--       Sheet, for the roles that may open the X- and Z-Reading Summary and for the cashiers (the
--       roles that may open Shifts, Cash Drops & Readings: their own shifts).
--    3. Attachments (accounting v40): the tables txn_attachments (the files attached to
--       transactions) and txn_attachment_chunks (their content, in pieces of 256 KB, so a file of
--       any size is saved under MySQL 5.5's default 1 MB packet limit).
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database of version 59 or earlier (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_05_09_accounting_update_v40.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. FB POS: the menu groups, the item columns and the two pages
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fb_pos_groups (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fb_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE items ADD COLUMN fb_pos TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active, ADD COLUMN fb_group_id INT UNSIGNED NULL AFTER fb_pos, ADD COLUMN fb_sort INT NOT NULL DEFAULT 0 AFTER fb_group_id, ADD COLUMN image_file VARCHAR(120) NULL AFTER fb_sort, ADD KEY ix_items_fb (fb_pos, fb_group_id)',
  'SELECT ''items: FB POS columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'fb_pos');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fb_pos  = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @fb_reg  = (SELECT COALESCE(MAX(sort_order), 1400) FROM pages WHERE page_key = 'pos.register');
SET @fb_term = (SELECT COALESCE(MAX(sort_order), 1440) FROM pages WHERE page_key = 'pos.terminals');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('pos.fb',      @fb_pos, 'FB POS',               'transaction', 'Transactions', @fb_reg + 5,  1),
  ('pos.fb_menu', @fb_pos, 'FB POS Menu & Groups', 'setup',       'Setup',        @fb_term + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.register'
  JOIN pages dst ON dst.page_key = 'pos.fb'
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.terminals'
  JOIN pages dst ON dst.page_key = 'pos.fb_menu'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  2. Cash Count Sheet: the bills and coins counted, and the report
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pos_cash_counts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id      INT UNSIGNED  NOT NULL,
  kind          VARCHAR(5)    NOT NULL DEFAULT 'bill',   -- bill or coin
  denomination  DECIMAL(10,2) NOT NULL,
  qty           INT           NOT NULL DEFAULT 0,
  amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_by    INT UNSIGNED  NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pcc_shift (shift_id),
  CONSTRAINT fk_pcc_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ccs_pos  = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @ccs_sort = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key IN ('rpt.pos_readings', 'rpt.pos_ending_inventory'));

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.pos_cash_count', @ccs_pos, 'Cash Count Sheet', 'report', 'Reports', @ccs_sort + 1, 1);

-- the roles of the X- and Z-Reading Summary, with their print right
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.pos_readings'
  JOIN pages dst ON dst.page_key = 'rpt.pos_cash_count'
 WHERE src.allowed = 1;

-- the cashiers (the roles that may open Shifts, Cash Drops & Readings): open and print their own
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, 1, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.shifts'
  JOIN pages dst ON dst.page_key = 'rpt.pos_cash_count'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  3. Attachments: the files attached to transactions and their content
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS txn_attachments (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id  BIGINT UNSIGNED NULL,                 -- empty while the form is not saved yet
  stage_key       VARCHAR(40)     NULL,                 -- the form session of a file not saved yet
  file_name       VARCHAR(255)    NOT NULL,
  mime_type       VARCHAR(150)    NOT NULL DEFAULT 'application/octet-stream',
  file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha1            CHAR(40)        NULL,
  chunks          INT UNSIGNED    NOT NULL DEFAULT 0,
  uploaded_by     INT UNSIGNED    NULL,
  uploaded_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ta_txn (transaction_id),
  KEY ix_ta_stage (stage_key),
  CONSTRAINT fk_ta_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS txn_attachment_chunks (
  attachment_id  INT UNSIGNED NOT NULL,
  seq            INT UNSIGNED NOT NULL,
  data           MEDIUMBLOB   NOT NULL,
  PRIMARY KEY (attachment_id, seq),
  CONSTRAINT fk_tac_att FOREIGN KEY (attachment_id) REFERENCES txn_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'FB POS; FB POS Menu & Groups; Cash Count Sheet; Attachments',
        'Migration 2027_05_09 (version 60, the accounting update to G-SMaRT Accounting version 40): FB POS columns on items, tables fb_pos_groups, pos_cash_counts, txn_attachments and txn_attachment_chunks; pages FB POS, FB POS Menu & Groups and Cash Count Sheet');

-- Done. Next steps in the application:
--   System Administration > Utilities: Verify & Rebuild Data > Rebuild data once (the averages in date order).
--   Point of Sale > FB POS Menu & Groups: the groups and the items of the FB POS; Item List > the item > FB POS: its picture.
--   Shifts, Cash Drops & Readings > Close register: Cash counted in drawer opens the Cash Count Sheet.
--   Make Journal Entries, Write Checks, Cash Voucher, Enter Bills, Create Purchase Orders, Create Sales Orders and
--   Create Invoices have an Attachments box.
