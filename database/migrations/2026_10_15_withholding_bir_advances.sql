-- =====================================================================
--  G-SMaRT Accounting - migration: Withholding Tax Table, BIR Form 2307, BIR Reports,
--  advance payments to suppliers
--
--  What it changes
--    1. A new module, BIR Reports, between Banking and System Administration, with:
--         Withholding Tax Table (bir.wht_table), Print BIR Form 2307 (bir.form_2307), and the reports
--         Summary of Taxes Withheld from Vendors/Suppliers, Expanded Withholding Tax Register (1% / 2%),
--         Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT), VAT Withholding Register (1600-VT),
--         Output VAT / Sales Register, Input VAT / Purchases Register, Creditable VAT Withheld Schedule,
--         Quarterly VAT Return Worksheet (2550Q).
--       The setup page is given to the roles that may open Terms, Tax Codes & Other Lists (same rights);
--       Form 2307 and the reports to the roles that may open the Unpaid Bills Detail report.
--    2. Two new tables: withholding_taxes (the table, seeded with WT on Goods 1%, WT on Services 2%
--       and WT on VAT 5%, all on the amount net of VAT) and payment_withholdings (the taxes each
--       payment withheld, by bill / invoice and type).
--    3. A column wht_ids on vendors and customers: the taxes usually withheld from / by them.
--    4. Accounts: Creditable VAT Withheld, Advances to Suppliers and VAT Withheld Payable (an account of
--       that name is used when there is one; otherwise it is added as 1315, 1410 and 2115, or the next
--       free code), and four module default accounts set to them and to the Withholding Tax Payable
--       account (2110 in the standard chart), each only when not set yet.
--    5. Vendors > Advances to Suppliers (vend.advances) and its report, given to the roles that may
--       open Pay Bills and the Unpaid Bills Detail; number series ADV- and AAP- (advance applied).
--    6. Settings: the company is a withholding agent (on), and the payor details of Form 2307.
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
--    or:         mysql -u root -p accounting_system < 2026_10_15_withholding_bir_advances.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,
  receivable_account_id INT UNSIGNED NULL,
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the taxes usually withheld from a supplier / by a customer
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE vendors ADD COLUMN wht_ids VARCHAR(100) NULL AFTER account_no',
  'SELECT ''vendors: wht_ids already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'vendors' AND column_name = 'wht_ids');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE customers ADD COLUMN wht_ids VARCHAR(100) NULL AFTER tin',
  'SELECT ''customers: wht_ids already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'wht_ids');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Accounts and module default accounts (only when not set yet)
--     An account of the same name is used when there is one; otherwise the account is added
--     with the first free code of a short list, so no account of yours is ever taken over.
-- ---------------------------------------------------------------------

-- Creditable VAT Withheld (asset)
SET @a = (SELECT id FROM accounts WHERE name = 'Creditable VAT Withheld' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '1315' AS c, 1 AS o UNION ALL SELECT '1316', 2 UNION ALL SELECT '1319', 3 UNION ALL SELECT '1395', 4 UNION ALL SELECT '1399', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Creditable VAT Withheld', 'Other Current Asset', 'Asset', 'Debit', '5% VAT withheld by government customers: a VAT credit (2550Q Item 16)'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Creditable VAT Withheld' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('cvat_withheld', 'Creditable VAT Withheld', 'cust', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'cvat_withheld' AND account_id IS NULL;

-- Advances to Suppliers (asset)
SET @a = (SELECT id FROM accounts WHERE name = 'Advances to Suppliers' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '1410' AS c, 1 AS o UNION ALL SELECT '1411', 2 UNION ALL SELECT '1415', 3 UNION ALL SELECT '1419', 4 UNION ALL SELECT '1490', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Advances to Suppliers', 'Other Current Asset', 'Asset', 'Debit', 'Paid to suppliers before their goods or services come'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Advances to Suppliers' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('vendor_advances', 'Advances to Suppliers', 'vend', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'vendor_advances' AND account_id IS NULL;

-- VAT Withheld Payable (liability)
SET @a = (SELECT id FROM accounts WHERE name = 'VAT Withheld Payable' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '2115' AS c, 1 AS o UNION ALL SELECT '2116', 2 UNION ALL SELECT '2119', 3 UNION ALL SELECT '2195', 4 UNION ALL SELECT '2199', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'VAT Withheld Payable', 'Other Current Liability', 'Liability', 'Credit', 'VAT withheld from suppliers, remitted with 1600-VT'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'VAT Withheld Payable' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('vat_withheld_payable', 'VAT Withheld Payable', 'vend', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'vat_withheld_payable' AND account_id IS NULL;

-- Withholding Tax Payable (Expanded): the company's own Withholding Tax Payable account when it has one
SET @a = (SELECT id FROM accounts WHERE name IN ('Withholding Tax Payable', 'Expanded Withholding Tax Payable', 'Withholding Tax Payable - Expanded', 'EWT Payable')
           AND account_type IN ('Other Current Liability', 'Long Term Liability') ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '2110' AS c, 1 AS o UNION ALL SELECT '2111', 2 UNION ALL SELECT '2112', 3 UNION ALL SELECT '2190', 4 UNION ALL SELECT '2198', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Withholding Tax Payable', 'Other Current Liability', 'Liability', 'Credit', '1% / 2% income tax withheld from suppliers, remitted with 0619-E and 1601-EQ'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = COALESCE(@a, (SELECT id FROM accounts WHERE name = 'Withholding Tax Payable' ORDER BY id LIMIT 1));
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('wht_payable', 'Withholding Tax Payable (Expanded)', 'vend', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'wht_payable' AND account_id IS NULL;

-- ---------------------------------------------------------------------
--  3. The Withholding Tax Table: 1% goods, 2% services, 5% VAT, on the amount net of VAT
-- ---------------------------------------------------------------------

INSERT IGNORE INTO withholding_taxes (code, atc, name, tax_kind, rate, base_type, applies_to, description, sort_order) VALUES
  ('WT-GOODS', 'WC158', 'WT on Goods', 'income', 1.0000, 'net_of_vat', 'both', 'Goods bought from regular suppliers by government payors and top withholding agents (RR 11-2018)', 10),
  ('WT-SERVICES', 'WC160', 'WT on Services', 'income', 2.0000, 'net_of_vat', 'both', 'Services bought from regular suppliers by government payors and top withholding agents (RR 11-2018)', 20),
  ('WT-VAT', 'WV010', 'WT on VAT', 'vat', 5.0000, 'net_of_vat', 'both', 'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)', 30);

-- ---------------------------------------------------------------------
--  4. Settings and number series
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('withholding_agent', '1'), ('bir_zip_code', ''), ('bir_signatory_name', ''), ('bir_signatory_title', ''), ('bir_signatory_tin', '');

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES
  ('vendor_advance', 'ADV-', 1, 5),
  ('advance_apply', 'AAP-', 1, 5);

-- ---------------------------------------------------------------------
--  5. The BIR Reports module and its pages
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('bir', 'BIR Reports', 'file-earmark-ruled', 9);
UPDATE modules SET sort_order = 10 WHERE code = 'admin';
UPDATE modules SET sort_order = 11 WHERE code = 'company';

SET @bir = (SELECT id FROM modules WHERE code = 'bir' LIMIT 1);
SET @bir_sort = (SELECT COALESCE(MAX(sort_order), 900) FROM pages);

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('bir.form_2307', @bir, 'Print BIR Form 2307', 'transaction', 'Transactions', @bir_sort + 10, 1),
  ('bir.wht_table', @bir, 'Withholding Tax Table', 'setup', 'Setup', @bir_sort + 20, 1),
  ('rpt.bir_wht_summary', @bir, 'Summary of Taxes Withheld from Vendors/Suppliers', 'report', 'BIR Reports', @bir_sort + 30, 1),
  ('rpt.bir_ewt_register', @bir, 'Expanded Withholding Tax Register (1% / 2%)', 'report', 'BIR Reports', @bir_sort + 40, 1),
  ('rpt.bir_remittance', @bir, 'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)', 'report', 'BIR Reports', @bir_sort + 50, 1),
  ('rpt.bir_vat_withholding', @bir, 'VAT Withholding Register (1600-VT)', 'report', 'BIR Reports', @bir_sort + 60, 1),
  ('rpt.bir_sales_register', @bir, 'Output VAT / Sales Register', 'report', 'BIR Reports', @bir_sort + 70, 1),
  ('rpt.bir_purchase_register', @bir, 'Input VAT / Purchases Register', 'report', 'BIR Reports', @bir_sort + 80, 1),
  ('rpt.bir_cvat_withheld', @bir, 'Creditable VAT Withheld Schedule (2550Q Schedule 3)', 'report', 'BIR Reports', @bir_sort + 90, 1),
  ('rpt.bir_2550q', @bir, 'Quarterly VAT Return Worksheet (2550Q)', 'report', 'BIR Reports', @bir_sort + 100, 1);

-- the setup page: the roles that may open Terms, Tax Codes & Other Lists, with the same rights
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, src.can_create, src.can_edit, src.can_delete, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.lists'
  JOIN pages dst ON dst.page_key = 'bir.wht_table'
 WHERE src.allowed = 1;

-- Form 2307 and the reports: the roles that may open the Unpaid Bills Detail, with its print right
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.unpaid_bills'
  JOIN pages dst ON dst.page_key IN ('bir.form_2307', 'rpt.bir_wht_summary', 'rpt.bir_ewt_register', 'rpt.bir_remittance', 'rpt.bir_vat_withholding',
                                     'rpt.bir_sales_register', 'rpt.bir_purchase_register', 'rpt.bir_cvat_withheld', 'rpt.bir_2550q', 'rpt.vendor_advances')
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  6. Vendors > Advances to Suppliers: the roles that may open Pay Bills, with the same rights
-- ---------------------------------------------------------------------

SET @vend = (SELECT id FROM modules WHERE code = 'vend' LIMIT 1);
SET @vend_sort = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key IN ('vend.bill_received', 'vend.receive_items'));
SET @vend_rsort = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key IN ('rpt.expenses_vendor_detail', 'rpt.vendor_contacts'));

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('vend.advances', @vend, 'Advances to Suppliers', 'transaction', 'Transactions', @vend_sort + 2, 1),
  ('rpt.vendor_advances', @vend, 'Advances to Suppliers', 'report', 'Vendors and Payables Reports', @vend_rsort + 2, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'vend.pay_bills'
  JOIN pages dst ON dst.page_key = 'vend.advances'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  7. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'BIR Reports, Withholding Tax Table, Form 2307, advances to suppliers',
        'Migration 2026_10_15: withholding taxes on payments, BIR Form 2307, BIR Reports module, advance payments to suppliers');

-- Done. Next steps in the application:
--   BIR Reports > Withholding Tax Table: check the rates and accounts; enter the zip code and signatory of Form 2307.
--   Vendors > Vendor Center > Edit: tick the withholding taxes of each supplier you withhold from.
--   Vendors > Pay Bills: the Withholding column; BIR Reports > Print BIR Form 2307 for each supplier and quarter.
