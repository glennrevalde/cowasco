-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: loan adjustments printed in the loan passbook (Loans > Loan Adjustments > Print to Passbook)
--
--  From version 37 a loan adjustment is a line of the loan passbook (code AJ), printed when it is recorded with
--  "Print to Passbook" ticked, or later by Update Passbook. The adjustments recorded before this version were never
--  meant for the passbook: this script marks them as passed over, so they do not come out on the next printing in
--  the middle of the lines already printed. It records when it did so (settings: loan_pb_adjustments_since).
--
--  Nothing is deleted and no amount changes. No table or column is added.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once: the second run changes nothing (an adjustment recorded after the first run stays to
--  be printed). Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_01_31_adjustment_passbook.sql
-- =====================================================================

SET NAMES utf8mb4;

-- the adjustments of before (only on the first run: the setting is not there yet)
UPDATE loan_txns
   SET pb_printed = 1
 WHERE kind = 'adjustment' AND pb_printed = 0 AND pb_page IS NULL
   AND NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'loan_pb_adjustments_since');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('loan_pb_adjustments_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the change.
-- ---------------------------------------------------------------------
