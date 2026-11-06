-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the Loan Collection Form - error correction of an erroneous payslip
--
--  What it changes
--    loan_txns.corrects_id      the loan payment an error correction negates
--    loan_txns.corrected_by_id  the error correction that negated this payment
--    an index on loan_txns.corrects_id
--
--  An error correction (Loan Collection Form > Error Correct) is a row of its own on the loan ledger, with the
--  amounts of the payment negative, so the loan passbook keeps both lines.
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_01_03_loan_collection_error_correct.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_txns') = 0,
  'SELECT ''loan_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_txns' AND column_name = 'corrects_id') = 0,
     'ALTER TABLE `loan_txns` ADD COLUMN `corrects_id` BIGINT UNSIGNED NULL',
     'SELECT ''loan_txns: corrects_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_txns') = 0,
  'SELECT ''loan_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_txns' AND column_name = 'corrected_by_id') = 0,
     'ALTER TABLE `loan_txns` ADD COLUMN `corrected_by_id` BIGINT UNSIGNED NULL',
     'SELECT ''loan_txns: corrected_by_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_txns' AND column_name = 'corrects_id') = 0,
  'SELECT ''loan_txns: no corrects_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_txns' AND index_name = 'ix_loan_txns_corrects') = 0,
     'ALTER TABLE `loan_txns` ADD KEY `ix_loan_txns_corrects` (`corrects_id`)',
     'SELECT ''loan_txns: ix_loan_txns_corrects already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
