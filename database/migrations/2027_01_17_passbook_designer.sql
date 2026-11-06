-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the Passbook Type Setup of the former program, with the design of each passbook
--
--  What it changes
--    passbook_types   ledger: savings or loan; a loan passbook has its own design (header: Loan Type, Term,
--                     Due Date; lines: Date, Reference No., Release, Loan Payment, Loan Balance, Interest
--                     Payment, Fines / Surcharges Payment, Teller ID), separate from the savings passbook
--    passbook_types   layout_json: the design of the cover, the header, the body and the footer (the fields, their
--                     place in tups from the Top / Left of their part, width, alignment, format), saved on
--                     Cooperative > Passbook Types; until a type is saved there, its settings of before are used
--    passbook_types   Start Line Skip and No. of Line Skip now mean what they meant in the former program: the
--                     first line never printed on (the centre fold) and how many lines are skipped from it.
--                     Printing always starts on line 1. A type that listed the fold lines ("Lines never printed
--                     on", e.g. 13,14) takes them as Start Line Skip 13 and No. of Line Skip 2.
--    passbook_types   the "Loan Passbook" type installed with the program, if it was never changed, takes the
--                     set-up of the former program's loan passbook: Start Line Skip 13, No. of Line Skip 2,
--                     Max Lines 29, Body Left 1, a page 4.75 x 6.75 inches (6,840 x 9,720 tups), the footer
--                     below the last line (Top 9,300)
--
--  Nothing is deleted and no posted amount changes. The lines already printed keep their page and line.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_01_17_passbook_designer.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. the kind of passbook and its design
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'ledger') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `ledger` VARCHAR(10) NOT NULL DEFAULT ''savings'' AFTER `description`',
     'SELECT ''passbook_types: ledger already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'layout_json') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `layout_json` TEXT NULL',
     'SELECT ''passbook_types: layout_json already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. a type a loan product prints with is a loan passbook (a type not designed yet: layout_json still empty)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'layout_json') = 0
    OR (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_products' AND column_name = 'passbook_type_id') = 0,
  'SELECT ''passbook_types: nothing to mark''',
  'UPDATE `passbook_types` t JOIN (SELECT DISTINCT passbook_type_id FROM `loan_products` WHERE passbook_type_id IS NOT NULL) l ON l.passbook_type_id = t.id
      SET t.ledger = ''loan'' WHERE t.layout_json IS NULL'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a type no product uses yet, named a loan passbook
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'layout_json') = 0,
  'SELECT ''passbook_types: nothing to mark''',
  'UPDATE `passbook_types` t LEFT JOIN (SELECT DISTINCT passbook_type_id FROM `sav_products` WHERE passbook_type_id IS NOT NULL) s ON s.passbook_type_id = t.id
      SET t.ledger = ''loan'' WHERE t.layout_json IS NULL AND s.passbook_type_id IS NULL AND t.name LIKE ''%loan%'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. the centre fold: "Lines never printed on" (e.g. 13,14) becomes Start Line Skip 13, No. of Line Skip 2
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'skip_lines') = 0,
  'SELECT ''passbook_types: no skip_lines column''',
  'UPDATE `passbook_types`
      SET start_line_skip = CAST(TRIM(SUBSTRING_INDEX(TRIM(skip_lines), '','', 1)) AS UNSIGNED),
          line_skip = LENGTH(TRIM(skip_lines)) - LENGTH(REPLACE(TRIM(skip_lines), '','', '''')) + 1,
          skip_lines = NULL
    WHERE skip_lines IS NOT NULL AND TRIM(skip_lines) <> '''''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  4. the Loan Passbook installed with the program, never changed: the former program's loan passbook
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'layout_json') = 0,
  'SELECT ''passbook_types: nothing to set''',
  'UPDATE `passbook_types` SET start_line_skip = 13, line_skip = 2, max_lines = 29, body_left = 1, page_width = 6840, page_height = 9720, footer_top = 9300, ledger = ''loan''
    WHERE name = ''Loan Passbook'' AND layout_json IS NULL AND header_json IS NULL AND cover_json IS NULL
      AND start_line_skip = 12 AND line_skip = 1 AND max_lines = 26 AND body_left = 200
      AND columns_json = ''{"date":200,"code":1400,"debit":2600,"credit":4400,"balance":6200,"teller":8000}'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new columns. Then open
--  Cooperative > Passbook Types, check each type (its kind, its lines, its design) and save it.
-- ---------------------------------------------------------------------
