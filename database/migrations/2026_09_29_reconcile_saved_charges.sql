-- =====================================================================
--  G-SMaRT Accounting - migration: a reconciliation saved for later keeps its
--  service charge and interest
--
--  What it changes
--    1. seven new columns on bank_reconciliations, for a reconciliation that is
--       saved with "Save for later" and finished another day:
--         service_charge, service_date, service_account_id   the bank's service charge
--         interest_earned, interest_date, interest_account_id  the interest earned
--         class_id                                            their class
--       They start empty (0 and NULL). When the reconciliation is finished, the
--       service charge and interest are posted as before, from these figures.
--
--  Nothing is deleted and no existing row is changed.
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
--    or:         mysql -u root -p accounting_system < 2026_09_29_reconcile_saved_charges.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The columns
--     Written so the file can be run a second time without an error.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN service_charge DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER interest_txn_id',
  'SELECT ''bank_reconciliations: service_charge already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'service_charge');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN service_date DATE NULL AFTER service_charge',
  'SELECT ''bank_reconciliations: service_date already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'service_date');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN service_account_id INT UNSIGNED NULL AFTER service_date',
  'SELECT ''bank_reconciliations: service_account_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'service_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN interest_earned DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER service_account_id',
  'SELECT ''bank_reconciliations: interest_earned already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'interest_earned');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN interest_date DATE NULL AFTER interest_earned',
  'SELECT ''bank_reconciliations: interest_date already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'interest_date');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN interest_account_id INT UNSIGNED NULL AFTER interest_date',
  'SELECT ''bank_reconciliations: interest_account_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'interest_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE bank_reconciliations ADD COLUMN class_id INT UNSIGNED NULL AFTER interest_account_id',
  'SELECT ''bank_reconciliations: class_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'bank_reconciliations' AND column_name = 'class_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'bank', 'bank.reconcile', 'settings_change', 'reconciliation',
        'Reconcile: saved service charge and interest', 'Migration 2026_09_29_reconcile_saved_charges: a reconciliation saved for later keeps its service charge and interest');

-- Done. Nothing else to do: Banking > Reconcile uses the new columns at once.
