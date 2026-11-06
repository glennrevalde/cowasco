-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the computation of a water rate code (Ladder Rate Billing or Straight Rate Billing)
--
--  What it changes
--    rate_code        billing_method: how the water bills of the rate code are computed from its tiers
--                       'straight'  Straight Rate Billing: the whole consumption at the rate of the tier it
--                                   falls in (250 m3 x 55.40), as before
--                       'ladder'    Ladder Rate Billing: each tier's own cubic meters at that tier's rate,
--                                   added up (the minimum charge of the first tier, then 5 m3 x 31.80,
--                                   10 m3 x 34.30 ...)
--                     Every rate code already on file keeps Straight Rate Billing, so no bill changes until
--                     a rate code is set to Ladder Rate Billing on Water Billing > Rate Codes.
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_03_07_water_ladder_billing.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rate_code') = 0,
  'SELECT ''rate_code: table not installed (water billing not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'rate_code' AND column_name = 'billing_method') = 0,
     'ALTER TABLE `rate_code` ADD COLUMN `billing_method` VARCHAR(10) NOT NULL DEFAULT ''straight'' AFTER `rc_name`',
     'SELECT ''rate_code: billing_method already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a value other than the two methods (a hand edit) is read as Straight Rate Billing
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'rate_code' AND column_name = 'billing_method') = 0,
  'SELECT ''rate_code: no billing_method column''',
  'UPDATE `rate_code` SET `billing_method` = ''straight'' WHERE `billing_method` NOT IN (''straight'', ''ladder'')'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
