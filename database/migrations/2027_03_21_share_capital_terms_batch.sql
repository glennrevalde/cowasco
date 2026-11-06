-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: Fixed Share Capital Billing, Term (days) and batch billing
--
--  What it adds
--    share_capital_plans.term_days   the term of the bills of a member's fixed billing, in days: the due date of
--                                    a bill is its posting date plus the term; empty (NULL) = no due date (open)
--    share_capital_plans.batch_no    the batch or import that put the member on fixed billing (empty when added
--                                    one by one)
--    share_capital_bills.due_date    the due date of a bill (empty = open)
--    share_capital_bills.term_days   the term it was posted with
--
--  Every billing and bill on file keeps no due date (open), as before. Nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations (2027_03_14_share_capital_billing.sql
--  first). New installs get it from tools/install.php. Safe to run more than once. Works on MySQL 5.5.3+ and
--  MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_03_21_share_capital_terms_batch.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'share_capital_plans') = 0,
  'SELECT ''share_capital_plans: run 2027_03_14_share_capital_billing.sql first''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'share_capital_plans' AND column_name = 'term_days') = 0,
     'ALTER TABLE `share_capital_plans` ADD COLUMN `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL AFTER `amount`',
     'SELECT ''share_capital_plans.term_days already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'share_capital_plans') = 0,
  'SELECT ''share_capital_plans: run 2027_03_14_share_capital_billing.sql first''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'share_capital_plans' AND column_name = 'batch_no') = 0,
     'ALTER TABLE `share_capital_plans` ADD COLUMN `batch_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `status`',
     'SELECT ''share_capital_plans.batch_no already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills') = 0,
  'SELECT ''share_capital_bills: run 2027_03_14_share_capital_billing.sql first''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills' AND column_name = 'due_date') = 0,
     'ALTER TABLE `share_capital_bills` ADD COLUMN `due_date` date NULL DEFAULT NULL AFTER `bill_date`',
     'SELECT ''share_capital_bills.due_date already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills') = 0,
  'SELECT ''share_capital_bills: run 2027_03_14_share_capital_billing.sql first''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills' AND column_name = 'term_days') = 0,
     'ALTER TABLE `share_capital_bills` ADD COLUMN `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL AFTER `due_date`',
     'SELECT ''share_capital_bills.term_days already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the bills a cashier looks for by due date
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills') = 0
    OR (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills' AND index_name = 'ix_scb_due') > 0,
  'SELECT ''share_capital_bills.ix_scb_due: nothing to do''',
  'ALTER TABLE `share_capital_bills` ADD INDEX `ix_scb_due`(`status`, `due_date`) USING BTREE'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
