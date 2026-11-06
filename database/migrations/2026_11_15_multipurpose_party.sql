-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: multipurpose cooperative
--  (one party with many roles, business units, names on documents)
--
--  What it changes
--    1. New tables: parties (the Party Master), party_crosswalk (the old customer / vendor / member /
--       other-name IDs and the party each was given), party_role_log, party_match_reviews (identity
--       review), business_units, document_reissues.
--    2. New columns, all empty or zero at first:
--         customers.party_id, vendors.party_id, members.party_id, other_names.party_id
--         transactions.party_id, business_unit_id and the counterparty snapshot (cp_role, cp_name,
--           cp_address, cp_tin, cp_member_no, cp_source, cp_issued_at, cp_reissue_no)
--         journal_lines.business_unit_id, accounts.is_interunit, items.patronage_eligible,
--         price_levels.valid_from / valid_to
--    3. Backfill: every member, customer (jobs follow their customer), vendor and other name gets its
--       own party. Nothing is merged by name; a member's ledger name joins the member's party. Every
--       existing document gets the party and a name snapshot taken from its current master.
--    4. Two business units (SC Savings and Credit, TR Consumer Store) with the cooperative vouchers
--       given to SC and the sales, purchasing, POS and inventory documents given to TR; the rest stay
--       unassigned. Rename, add or deactivate units on Cooperative > Lines of Business.
--    5. The Parties menu (Party Center, Identity Review, Consolidated Party Statement, reports), the
--       Lines of Business page, the Statement of Operations by Line of Business, page access for the
--       cooperative roles, and four special permissions.
--
--  Nothing is deleted, no posted amount changes, no document number changes.
--
--  For an EXISTING database that already has the 2026_11_01 cooperative migration.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  With several companies, run it on every company database.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2026_11_15_multipurpose_party.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Tables
-- ---------------------------------------------------------------------

-- the Party Master: one row per natural or juridical person, whatever roles it holds
CREATE TABLE IF NOT EXISTS parties (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_no        VARCHAR(20)  NOT NULL,                     -- the stable Party ID (P-000001)
  party_type      VARCHAR(12)  NOT NULL DEFAULT 'person',    -- person / organization
  legal_name      VARCHAR(160) NOT NULL,                     -- printed on documents
  display_name    VARCHAR(160) NOT NULL,                     -- shown in lists
  last_name       VARCHAR(80)  NULL,
  first_name      VARCHAR(80)  NULL,
  middle_name     VARCHAR(80)  NULL,
  suffix          VARCHAR(20)  NULL,
  tin             VARCHAR(30)  NULL,
  birth_date      DATE         NULL,
  registration_no VARCHAR(60)  NULL,                         -- SEC / CDA / DTI registration of an organization
  email           VARCHAR(120) NULL,
  mobile          VARCHAR(40)  NULL,
  phone           VARCHAR(40)  NULL,
  address         VARCHAR(255) NULL,
  home_branch_id  INT UNSIGNED NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'active',    -- active / inactive / merged
  merged_into_id  INT UNSIGNED NULL,
  src_table       VARCHAR(12)  NULL,                         -- where the party was first made from (backfill)
  src_id          INT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_parties_no (party_no),
  UNIQUE KEY uq_parties_src (src_table, src_id),
  KEY ix_parties_name (legal_name(100)),
  KEY ix_parties_display (display_name(100)),
  KEY ix_parties_tin (tin),
  KEY ix_parties_mobile (mobile),
  KEY ix_parties_email (email(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the old IDs and the party each one was given (backfill, creation or a reviewed link)
CREATE TABLE IF NOT EXISTS party_crosswalk (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(12)  NOT NULL,                         -- customer / vendor / member / other_name
  source_id   INT UNSIGNED NOT NULL,
  source_ref  VARCHAR(160) NULL,                             -- the name and number at that time
  party_id    INT UNSIGNED NOT NULL,
  how         VARCHAR(12)  NOT NULL DEFAULT 'backfill',      -- backfill / created
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party_crosswalk_src (source, source_id),
  KEY ix_party_crosswalk_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every role granted, revoked, restored, linked or moved, with the reason and who approved it
CREATE TABLE IF NOT EXISTS party_role_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id       INT UNSIGNED NOT NULL,
  role           VARCHAR(12)  NOT NULL,                      -- member / customer / supplier / employee / other / party
  ref_id         INT UNSIGNED NULL,                          -- the role record (customers.id, vendors.id ...)
  action         VARCHAR(12)  NOT NULL,                      -- granted / revoked / restored / moved_in / moved_out / merged / edited
  other_party_id INT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  approved_by    INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_party_role_log_party (party_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- possible duplicates found by the identity review, and what was decided
CREATE TABLE IF NOT EXISTS party_match_reviews (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_a     INT UNSIGNED NOT NULL,                         -- the lower party id
  party_b     INT UNSIGNED NOT NULL,
  basis       VARCHAR(120) NOT NULL,                         -- same TIN, same e-mail, same mobile, same name ...
  score       INT          NOT NULL DEFAULT 0,
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',          -- open / merged / distinct
  decided_by  INT UNSIGNED NULL,
  decided_at  DATETIME     NULL,
  reason      VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party_match_pair (party_a, party_b),
  KEY ix_party_match_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the cooperative's lines of business (savings and credit, consumer store, marketing, services ...)
CREATE TABLE IF NOT EXISTS business_units (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                     VARCHAR(10)  NOT NULL,
  name                     VARCHAR(80)  NOT NULL,
  kind                     VARCHAR(20)  NOT NULL DEFAULT 'other',   -- savings_credit / retail / wholesale / marketing / production / service / other
  nonmember_policy         VARCHAR(10)  NOT NULL DEFAULT 'allow',   -- allow / approval / members (members only)
  patronage_eligible       TINYINT(1)   NOT NULL DEFAULT 1,         -- its member transactions can count for patronage refund
  member_price_level_id    INT UNSIGNED NULL,                       -- price level of members buying here (when the customer has none)
  nonmember_price_level_id INT UNSIGNED NULL,
  default_site_id          INT UNSIGNED NULL,
  description              VARCHAR(255) NULL,
  is_active                TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order               INT          NOT NULL DEFAULT 0,
  created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_units_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- authorized corrections of the counterparty name / address printed on an issued document
CREATE TABLE IF NOT EXISTS document_reissues (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id       BIGINT UNSIGNED NOT NULL,
  reissue_no   INT          NOT NULL,
  old_name     VARCHAR(160) NULL,
  new_name     VARCHAR(160) NULL,
  old_address  VARCHAR(255) NULL,
  new_address  VARCHAR(255) NULL,
  old_tin      VARCHAR(30)  NULL,
  new_tin      VARCHAR(30)  NULL,
  reason       VARCHAR(255) NOT NULL,
  approved_by  INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_document_reissues_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Columns on existing tables (each added only when missing)
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE customers ADD COLUMN party_id INT UNSIGNED NULL AFTER id, ADD KEY ix_customers_party (party_id)', 'SELECT ''customers.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE vendors ADD COLUMN party_id INT UNSIGNED NULL AFTER id, ADD KEY ix_vendors_party (party_id)', 'SELECT ''vendors.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vendors' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE members ADD COLUMN party_id INT UNSIGNED NULL AFTER id, ADD KEY ix_members_party (party_id)', 'SELECT ''members.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE other_names ADD COLUMN party_id INT UNSIGNED NULL AFTER id, ADD KEY ix_other_names_party (party_id)', 'SELECT ''other_names.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'other_names' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions ADD COLUMN party_id INT UNSIGNED NULL AFTER other_name_id, ADD COLUMN business_unit_id INT UNSIGNED NULL AFTER party_id,
     ADD COLUMN cp_role VARCHAR(10) NULL AFTER business_unit_id, ADD COLUMN cp_name VARCHAR(160) NULL AFTER cp_role, ADD COLUMN cp_address VARCHAR(255) NULL AFTER cp_name,
     ADD COLUMN cp_tin VARCHAR(30) NULL AFTER cp_address, ADD COLUMN cp_member_no VARCHAR(30) NULL AFTER cp_tin, ADD COLUMN cp_source VARCHAR(8) NULL AFTER cp_member_no,
     ADD COLUMN cp_issued_at DATETIME NULL AFTER cp_source, ADD COLUMN cp_reissue_no INT NOT NULL DEFAULT 0 AFTER cp_issued_at,
     ADD KEY ix_transactions_party (party_id, txn_date), ADD KEY ix_transactions_bu (business_unit_id)',
  'SELECT ''transactions.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE journal_lines ADD COLUMN business_unit_id INT UNSIGNED NULL AFTER class_id, ADD KEY ix_journal_lines_bu (business_unit_id, account_id)', 'SELECT ''journal_lines.business_unit_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'journal_lines' AND column_name = 'business_unit_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE accounts ADD COLUMN is_interunit TINYINT(1) NOT NULL DEFAULT 0', 'SELECT ''accounts.is_interunit exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'is_interunit');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE items ADD COLUMN patronage_eligible TINYINT(1) NOT NULL DEFAULT 0', 'SELECT ''items.patronage_eligible exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'patronage_eligible');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- price levels with dates (a promotion applies only to documents dated within them)
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE price_levels ADD COLUMN valid_from DATE NULL AFTER adjust_pct, ADD COLUMN valid_to DATE NULL AFTER valid_from', 'SELECT ''price_levels.valid_from exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'price_levels' AND column_name = 'valid_from');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. Backfill: one party for each member, customer, vendor and other name (never merged by name)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('party', 'P-', 1, 6);
SET @pn := (SELECT next_no - 1 FROM sequences WHERE seq_key = 'party');

-- members (an institution is an organization; joint members stay persons)
INSERT INTO parties (party_no, party_type, legal_name, display_name, last_name, first_name, middle_name, suffix, tin, birth_date, email, mobile, address, home_branch_id, src_table, src_id, created_by)
SELECT CONCAT('P-', LPAD(@pn := @pn + 1, 6, '0')),
       IF(m.member_kind = 'institutional', 'organization', 'person'),
       -- the legal name printed on documents: "Maria R. Santos Jr." (the lists keep "Santos, Maria R.")
       IF(m.member_kind = 'individual',
          TRIM(CONCAT_WS(' ', NULLIF(TRIM(m.first_name), ''), IF(NULLIF(TRIM(m.middle_name), '') IS NULL, NULL, CONCAT(LEFT(TRIM(m.middle_name), 1), '.')), NULLIF(TRIM(m.last_name), ''), NULLIF(TRIM(m.suffix), ''))),
          m.full_name),
       m.full_name,
       IF(m.member_kind = 'individual', NULLIF(m.last_name, ''), NULL), IF(m.member_kind = 'individual', NULLIF(m.first_name, ''), NULL),
       IF(m.member_kind = 'individual', NULLIF(m.middle_name, ''), NULL), IF(m.member_kind = 'individual', NULLIF(m.suffix, ''), NULL),
       NULLIF(m.tin, ''), IF(m.member_kind = 'individual', m.birth_date, NULL), NULLIF(m.email, ''), NULLIF(m.mobile, ''),
       NULLIF(CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', NULLIF(m.res_house, ''), NULLIF(m.res_street, ''))), ''), NULLIF(m.res_barangay, ''), NULLIF(m.res_city, ''), NULLIF(m.res_province, '')), ''),
       m.branch_id, 'member', m.id, m.created_by
  FROM members m
 WHERE m.party_id IS NULL AND NOT EXISTS (SELECT 1 FROM parties x WHERE x.src_table = 'member' AND x.src_id = m.id)
 ORDER BY m.id;
UPDATE members m JOIN parties p ON p.src_table = 'member' AND p.src_id = m.id SET m.party_id = p.id WHERE m.party_id IS NULL;
-- a member's ledger name (Employees & Other Names) is the same person
UPDATE other_names o JOIN members m ON m.id = o.member_id SET o.party_id = m.party_id WHERE o.party_id IS NULL AND m.party_id IS NOT NULL;

-- customers (a job is part of its customer, so it takes its customer's party)
INSERT INTO parties (party_no, party_type, legal_name, display_name, tin, email, mobile, phone, address, src_table, src_id)
SELECT CONCAT('P-', LPAD(@pn := @pn + 1, 6, '0')),
       IF(NULLIF(TRIM(c.company_name), '') IS NULL, 'person', 'organization'),
       COALESCE(NULLIF(TRIM(c.company_name), ''), c.name), c.name,
       NULLIF(c.tin, ''), NULLIF(c.email, ''), NULLIF(c.mobile, ''), NULLIF(c.phone, ''), NULLIF(c.billing_address, ''), 'customer', c.id
  FROM customers c
 WHERE c.parent_id IS NULL AND c.party_id IS NULL AND NOT EXISTS (SELECT 1 FROM parties x WHERE x.src_table = 'customer' AND x.src_id = c.id)
 ORDER BY c.id;
UPDATE customers c JOIN parties p ON p.src_table = 'customer' AND p.src_id = c.id SET c.party_id = p.id WHERE c.party_id IS NULL;
UPDATE customers j JOIN customers c ON c.id = j.parent_id SET j.party_id = c.party_id WHERE j.party_id IS NULL AND c.party_id IS NOT NULL;

-- vendors
INSERT INTO parties (party_no, party_type, legal_name, display_name, tin, email, phone, address, src_table, src_id)
SELECT CONCAT('P-', LPAD(@pn := @pn + 1, 6, '0')),
       IF(NULLIF(TRIM(v.company_name), '') IS NULL, 'person', 'organization'),
       COALESCE(NULLIF(TRIM(v.company_name), ''), v.name), v.name,
       NULLIF(v.tin, ''), NULLIF(v.email, ''), NULLIF(v.phone, ''), NULLIF(v.address, ''), 'vendor', v.id
  FROM vendors v
 WHERE v.party_id IS NULL AND NOT EXISTS (SELECT 1 FROM parties x WHERE x.src_table = 'vendor' AND x.src_id = v.id)
 ORDER BY v.id;
UPDATE vendors v JOIN parties p ON p.src_table = 'vendor' AND p.src_id = v.id SET v.party_id = p.id WHERE v.party_id IS NULL;

-- employees and other names
INSERT INTO parties (party_no, party_type, legal_name, display_name, tin, phone, address, src_table, src_id)
SELECT CONCAT('P-', LPAD(@pn := @pn + 1, 6, '0')), 'person', o.name, o.name, NULLIF(o.tin, ''), NULLIF(o.phone, ''), NULLIF(o.address, ''), 'other_name', o.id
  FROM other_names o
 WHERE o.party_id IS NULL AND o.member_id IS NULL AND NOT EXISTS (SELECT 1 FROM parties x WHERE x.src_table = 'other_name' AND x.src_id = o.id)
 ORDER BY o.id;
UPDATE other_names o JOIN parties p ON p.src_table = 'other_name' AND p.src_id = o.id SET o.party_id = p.id WHERE o.party_id IS NULL;

UPDATE sequences SET next_no = GREATEST(next_no, @pn + 1) WHERE seq_key = 'party';

-- the crosswalk: every old ID and the party it went to
INSERT IGNORE INTO party_crosswalk (source, source_id, source_ref, party_id, how)
SELECT 'member', m.id, CONCAT(m.member_no, ' ', m.full_name), m.party_id, 'backfill' FROM members m WHERE m.party_id IS NOT NULL;
INSERT IGNORE INTO party_crosswalk (source, source_id, source_ref, party_id, how)
SELECT 'customer', c.id, c.name, c.party_id, 'backfill' FROM customers c WHERE c.party_id IS NOT NULL;
INSERT IGNORE INTO party_crosswalk (source, source_id, source_ref, party_id, how)
SELECT 'vendor', v.id, v.name, v.party_id, 'backfill' FROM vendors v WHERE v.party_id IS NOT NULL;
INSERT IGNORE INTO party_crosswalk (source, source_id, source_ref, party_id, how)
SELECT 'other_name', o.id, o.name, o.party_id, 'backfill' FROM other_names o WHERE o.party_id IS NOT NULL;

-- the roles each party held at the upgrade
INSERT INTO party_role_log (party_id, role, ref_id, action, reason, created_at)
SELECT m.party_id, 'member', m.id, 'granted', 'Held at the upgrade', m.created_at FROM members m
 WHERE m.party_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM party_role_log l WHERE l.role = 'member' AND l.ref_id = m.id);
INSERT INTO party_role_log (party_id, role, ref_id, action, reason, created_at)
SELECT c.party_id, 'customer', c.id, 'granted', 'Held at the upgrade', c.created_at FROM customers c
 WHERE c.party_id IS NOT NULL AND c.parent_id IS NULL AND NOT EXISTS (SELECT 1 FROM party_role_log l WHERE l.role = 'customer' AND l.ref_id = c.id);
INSERT INTO party_role_log (party_id, role, ref_id, action, reason, created_at)
SELECT v.party_id, 'supplier', v.id, 'granted', 'Held at the upgrade', v.created_at FROM vendors v
 WHERE v.party_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM party_role_log l WHERE l.role = 'supplier' AND l.ref_id = v.id);
INSERT INTO party_role_log (party_id, role, ref_id, action, reason, created_at)
SELECT o.party_id, IF(o.name_type = 'employee', 'employee', 'other'), o.id, 'granted', 'Held at the upgrade', o.created_at FROM other_names o
 WHERE o.party_id IS NOT NULL AND o.member_id IS NULL AND NOT EXISTS (SELECT 1 FROM party_role_log l WHERE l.role IN ('employee','other') AND l.ref_id = o.id);

-- ---------------------------------------------------------------------
--  4. Business units and the documents' unit
-- ---------------------------------------------------------------------

INSERT IGNORE INTO business_units (code, name, kind, nonmember_policy, patronage_eligible, description, sort_order) VALUES
  ('SC', 'Savings and Credit', 'savings_credit', 'members', 1, 'Share capital, savings, time deposits and loans of members', 1),
  ('TR', 'Consumer Store', 'retail', 'allow', 1, 'Retail and trading of goods to members and the public', 2);

SET @bu_sc := (SELECT id FROM business_units WHERE code = 'SC');
SET @bu_tr := (SELECT id FROM business_units WHERE code = 'TR');

-- the unit new documents take when the form does not choose one (Cooperative > Lines of Business)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('bu_default_coop', @bu_sc), ('bu_default_sales', @bu_tr), ('bu_default_purchases', @bu_tr), ('bu_default_pos', @bu_tr),
  ('bu_default_inventory', @bu_tr), ('bu_default_banking', ''), ('bu_default_general', ''),
  ('disabled_modules', ''), ('lock_posted_documents', '1'), ('doc_show_member_no', '1'),
  ('member_price_level_id', ''), ('nonmember_price_level_id', '');

UPDATE transactions SET business_unit_id = @bu_sc
 WHERE business_unit_id IS NULL AND txn_type IN ('coop_or','coop_cv','loan_release','coop_jv');
UPDATE transactions SET business_unit_id = @bu_tr
 WHERE business_unit_id IS NULL AND txn_type IN ('estimate','sales_order','invoice','sales_receipt','statement_charge','finance_charge','payment','credit_memo','refund',
       'purchase_order','bill','vendor_credit','item_receipt','bill_payment','vendor_advance','pos_sale','pos_return','pos_over_short',
       'inventory_adjustment','inventory_transfer','build_assembly','item_processing');
UPDATE journal_lines j JOIN transactions t ON t.id = j.txn_id SET j.business_unit_id = t.business_unit_id
 WHERE j.business_unit_id IS NULL AND t.business_unit_id IS NOT NULL;

-- ---------------------------------------------------------------------
--  5. The party and the name snapshot of every existing document (taken from its current master)
-- ---------------------------------------------------------------------

UPDATE transactions t JOIN customers c ON c.id = t.customer_id SET t.party_id = c.party_id WHERE t.party_id IS NULL AND c.party_id IS NOT NULL;
UPDATE transactions t JOIN vendors v ON v.id = t.vendor_id SET t.party_id = v.party_id WHERE t.party_id IS NULL AND v.party_id IS NOT NULL;
UPDATE transactions t JOIN other_names o ON o.id = t.other_name_id SET t.party_id = o.party_id WHERE t.party_id IS NULL AND o.party_id IS NOT NULL;

UPDATE transactions SET cp_role = CASE
    WHEN txn_type IN ('estimate','sales_order','invoice','sales_receipt','statement_charge','finance_charge','credit_memo','pos_sale','pos_return') THEN 'customer'
    WHEN txn_type IN ('payment','coop_or') THEN 'payer'
    WHEN txn_type IN ('purchase_order','bill','vendor_credit','item_receipt') THEN 'supplier'
    WHEN txn_type IN ('check','bill_payment','refund','vendor_advance','cash_voucher','coop_cv','loan_release') THEN 'payee'
    ELSE 'name' END
 WHERE cp_role IS NULL;

UPDATE transactions t JOIN parties p ON p.id = t.party_id LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id LEFT JOIN other_names o ON o.id = t.other_name_id
   SET t.cp_name = p.legal_name,
       t.cp_address = COALESCE(NULLIF(t.billing_address, ''), c.billing_address, v.address, o.address, p.address),
       t.cp_tin = COALESCE(NULLIF(c.tin, ''), NULLIF(v.tin, ''), NULLIF(o.tin, ''), p.tin),
       t.cp_source = 'upgrade', t.cp_issued_at = NOW()
 WHERE t.cp_name IS NULL;
UPDATE transactions SET cp_name = payee_name, cp_source = 'upgrade', cp_issued_at = NOW()
 WHERE cp_name IS NULL AND party_id IS NULL AND NULLIF(payee_name, '') IS NOT NULL;
UPDATE transactions t JOIN members m ON m.party_id = t.party_id SET t.cp_member_no = m.member_no WHERE t.cp_member_no IS NULL AND t.cp_name IS NOT NULL;

-- ---------------------------------------------------------------------
--  6. Menus, pages, page access and special permissions
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('party', 'Parties', 'person-vcard', 12);
UPDATE modules SET sort_order = 12 WHERE code = 'party';
UPDATE modules SET sort_order = 13 WHERE code = 'coop';
UPDATE modules SET sort_order = 14 WHERE code = 'sav';
UPDATE modules SET sort_order = 15 WHERE code = 'loan';
UPDATE modules SET sort_order = 16 WHERE code = 'teller';
UPDATE modules SET sort_order = 17 WHERE code = 'dist';
UPDATE modules SET sort_order = 18 WHERE code = 'cda';

SET @m_party = (SELECT id FROM modules WHERE code = 'party' LIMIT 1);
SET @m_coop  = (SELECT id FROM modules WHERE code = 'coop' LIMIT 1);
SET @psort = (SELECT COALESCE(MAX(sort_order), 3000) FROM pages);

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('party.center',          @m_party, 'Party Center',                          'center',      'Transactions', @psort + 1, 1),
  ('party.review',          @m_party, 'Identity Review',                       'transaction', 'Transactions', @psort + 2, 1),
  ('party.statement',       @m_party, 'Consolidated Party Statement',          'report',      'Reports',      @psort + 3, 1),
  ('rpt.party_list',        @m_party, 'Party List',                            'report',      'Reports',      @psort + 4, 1),
  ('rpt.party_role_history',@m_party, 'Party Role History',                    'report',      'Reports',      @psort + 5, 1),
  ('rpt.doc_reissues',      @m_party, 'Reissued Documents',                    'report',      'Reports',      @psort + 6, 1),
  ('coop.business_units',   @m_coop,  'Lines of Business',                     'setup',       'Setup',        @psort + 7, 1),
  ('rpt.sop_business_unit', @m_coop,  'Statement of Operations by Line of Business', 'report', 'Reports',      @psort + 8, 1);
UPDATE pages SET name = 'Statement of Operations by Line of Business' WHERE page_key = 'rpt.sop_business_unit';

-- General Manager and Branch Manager: everything of the Parties menu with approve; Lines of Business for the General Manager
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 0, 1, 1 FROM roles r, pages p
 WHERE r.name IN ('General Manager', 'Branch Manager') AND p.page_key IN ('party.center','party.review','party.statement','rpt.party_list','rpt.party_role_history','rpt.doc_reissues','rpt.sop_business_unit');
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 0, 1, 1 FROM roles r, pages p WHERE r.name = 'General Manager' AND p.page_key = 'coop.business_units';
-- Bookkeeper, Accountant: parties (no merging), statements and the unit reports
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_key = 'party.center', 1, 0), IF(p.page_key = 'party.center', 1, 0), 0, 1, 0 FROM roles r, pages p
 WHERE r.name IN ('Bookkeeper', 'Accountant') AND p.page_key IN ('party.center','party.review','party.statement','rpt.party_list','rpt.party_role_history','rpt.doc_reissues','rpt.sop_business_unit');
-- Membership Officer, Loan Officer, A/R Clerk, A/P Clerk: the Party Center (to find and add roles) and the statement
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_key = 'party.center', 1, 0), IF(p.page_key = 'party.center', 1, 0), 0, 1, 0 FROM roles r, pages p
 WHERE r.name IN ('Membership Officer', 'Loan Officer', 'A/R Clerk', 'A/P Clerk') AND p.page_key IN ('party.center','party.statement','rpt.party_list');
-- Teller, Credit Committee, Collector: read the Party Center and the statement
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0 FROM roles r, pages p
 WHERE r.name IN ('Teller', 'Credit Committee', 'Collector') AND p.page_key IN ('party.center','party.statement');
-- Auditor: reads everything new
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0 FROM roles r, pages p
 WHERE r.name = 'Auditor' AND p.page_key IN ('party.center','party.review','party.statement','rpt.party_list','rpt.party_role_history','rpt.doc_reissues','rpt.sop_business_unit','coop.business_units');

-- special permissions
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'party_merge', '1' FROM roles WHERE name IN ('General Manager', 'Branch Manager');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_view_confidential', '1' FROM roles WHERE name IN ('General Manager', 'Branch Manager', 'Membership Officer', 'Loan Officer', 'Credit Committee');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'doc_reissue', '1' FROM roles WHERE name IN ('General Manager', 'Bookkeeper', 'Accountant');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'edit_posted_documents', '1' FROM roles WHERE name IN ('General Manager', 'Accountant');
