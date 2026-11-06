-- =====================================================================
--  Accounting System - migration: default site and class for POS transactions
--
--  What it changes
--    1. two new columns on the users table, both empty to start with:
--         users.pos_default_site_id    the site a cashier's POS Register opens on
--         users.pos_default_class_id   the class every new sale of that cashier starts with
--       Both are optional. A user with neither set works exactly as before: the register
--       opens on the first terminal that user may use, and the class comes from the
--       terminal, or from the sale before it.
--
--  Nothing is deleted and no existing row is changed.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_26_pos_default_site_class.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The two columns
--     Written so the file can be run a second time without an error.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN pos_default_site_id INT UNSIGNED NULL AFTER employee_no',
  'SELECT ''users: pos_default_site_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'pos_default_site_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN pos_default_class_id INT UNSIGNED NULL AFTER pos_default_site_id',
  'SELECT ''users: pos_default_class_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'pos_default_class_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Keys, so the two columns read quickly.
--     No foreign key is added: a site or class that is later deleted or
--     deactivated is simply treated as "no default" by the program, which
--     keeps the friendly wording on the site and class list pages.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD KEY ix_users_pos_site (pos_default_site_id)',
  'SELECT ''users: ix_users_pos_site already exists''')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'ix_users_pos_site');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD KEY ix_users_pos_class (pos_default_class_id)',
  'SELECT ''users: ix_users_pos_class already exists''')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'ix_users_pos_class');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.settings', 'settings_change', 'settings',
        'Default site & class for POS transactions', 'Migration 2026_09_26_pos_default_site_class: each user can be given a site and a class that their POS Register opens on');

-- Done. Next step in the application:
--   System Administration > Company, Security & Closing Date > Default site & class for POS transactions.
--   Set a site and a class against the cashiers that need one and save. From then on that cashier's
--   POS Register opens on that site, and every new sale starts with that class already chosen.
--   Anyone left blank carries on exactly as before.
