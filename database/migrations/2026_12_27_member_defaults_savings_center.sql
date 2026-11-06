-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the member's water, customer and supplier defaults, and the savings transaction center
--
--  What it changes
--    1. The member's water defaults (Member Information > Water Defaults), used for the member's water-service
--       account so the member is recorded once, on the membership form:
--         members.water_zone, water_sequence, water_rc_id (rate code / classification), water_pipe_size_id,
--         water_connection_date, water_disconnection_date, water_connection_status (active / inactive),
--         water_account_id (the member's own water-service account)
--         water_accounts.connection_date        the date the connection started
--       The customer and supplier defaults are kept on the member's own customer and supplier records.
--    2. Savings Transaction Center: one transaction number for every teller savings and time deposit
--       transaction, the transaction code as the basis of the journal entry, error corrections, cheque details
--       and back entries:
--         sav_txns.trans_no, opposite_account_id, cheque_json, is_back_entry, corrects_id, corrected_by_id
--         sav_txn_codes.is_memo                 debit / credit memo codes (the GL account is chosen each time)
--         the number series sav_trans, the error-correct codes ECSD, ECSW, ECM, EDM and ECK.. (one for each
--         check deposit code), CAIW (cash accrued interest withdrawal of a time deposit) and TDI
--    3. User profile cash account: roles.coop_cash_account_id, the cash account of the tellers of a profile
--       (used when the user has none of his own)
--    4. Passbook printing: passbook_types.header_json (account name and number on every page), cover_json (the
--       inside cover), skip_lines (lines never printed, e.g. the centre fold), dash_empty, footer_text
--    5. Time deposit accrued interest withdrawals: td_interest_payouts and td_placements.accrued_paid
--    6. Menu: Savings & Deposits > Savings Transaction Center (the access of Savings Deposit / Withdrawal, which
--       is now called Savings Account Transaction); Time Deposits is now called Time Deposit Center
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2026_12_27_member_defaults_savings_center.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1 to 5. The new columns (a table of a module that is not installed is skipped)
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_zone') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_zone` INT NULL',
     'SELECT ''members: water_zone already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_sequence') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_sequence` VARCHAR(50) NULL',
     'SELECT ''members: water_sequence already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_rc_id') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_rc_id` INT NULL',
     'SELECT ''members: water_rc_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_pipe_size_id') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_pipe_size_id` INT UNSIGNED NULL',
     'SELECT ''members: water_pipe_size_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_connection_date') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_connection_date` DATE NULL',
     'SELECT ''members: water_connection_date already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_disconnection_date') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_disconnection_date` DATE NULL',
     'SELECT ''members: water_disconnection_date already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_connection_status') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_connection_status` VARCHAR(10) NOT NULL DEFAULT ''active''',
     'SELECT ''members: water_connection_status already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'members') = 0,
  'SELECT ''members: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_account_id') = 0,
     'ALTER TABLE `members` ADD COLUMN `water_account_id` INT NULL',
     'SELECT ''members: water_account_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'water_accounts') = 0,
  'SELECT ''water_accounts: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'connection_date') = 0,
     'ALTER TABLE `water_accounts` ADD COLUMN `connection_date` DATE NULL',
     'SELECT ''water_accounts: connection_date already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'trans_no') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `trans_no` VARCHAR(20) NULL',
     'SELECT ''sav_txns: trans_no already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'opposite_account_id') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `opposite_account_id` INT UNSIGNED NULL',
     'SELECT ''sav_txns: opposite_account_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'cheque_json') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `cheque_json` TEXT NULL',
     'SELECT ''sav_txns: cheque_json already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'is_back_entry') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `is_back_entry` TINYINT(1) NOT NULL DEFAULT 0',
     'SELECT ''sav_txns: is_back_entry already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'corrects_id') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `corrects_id` BIGINT UNSIGNED NULL',
     'SELECT ''sav_txns: corrects_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txns') = 0,
  'SELECT ''sav_txns: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'corrected_by_id') = 0,
     'ALTER TABLE `sav_txns` ADD COLUMN `corrected_by_id` BIGINT UNSIGNED NULL',
     'SELECT ''sav_txns: corrected_by_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_txn_codes') = 0,
  'SELECT ''sav_txn_codes: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txn_codes' AND column_name = 'is_memo') = 0,
     'ALTER TABLE `sav_txn_codes` ADD COLUMN `is_memo` TINYINT(1) NOT NULL DEFAULT 0',
     'SELECT ''sav_txn_codes: is_memo already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'roles') = 0,
  'SELECT ''roles: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roles' AND column_name = 'coop_cash_account_id') = 0,
     'ALTER TABLE `roles` ADD COLUMN `coop_cash_account_id` INT UNSIGNED NULL',
     'SELECT ''roles: coop_cash_account_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'header_json') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `header_json` TEXT NULL',
     'SELECT ''passbook_types: header_json already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'cover_json') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `cover_json` TEXT NULL',
     'SELECT ''passbook_types: cover_json already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'skip_lines') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `skip_lines` VARCHAR(60) NULL',
     'SELECT ''passbook_types: skip_lines already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'dash_empty') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `dash_empty` TINYINT(1) NOT NULL DEFAULT 1',
     'SELECT ''passbook_types: dash_empty already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passbook_types') = 0,
  'SELECT ''passbook_types: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'footer_text') = 0,
     'ALTER TABLE `passbook_types` ADD COLUMN `footer_text` VARCHAR(160) NULL',
     'SELECT ''passbook_types: footer_text already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'td_placements') = 0,
  'SELECT ''td_placements: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'td_placements' AND column_name = 'accrued_paid') = 0,
     'ALTER TABLE `td_placements` ADD COLUMN `accrued_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00',
     'SELECT ''td_placements: accrued_paid already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'trans_no') = 0,
  'SELECT ''sav_txns: no trans_no column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND index_name = 'ix_sav_txns_trans') = 0,
     'ALTER TABLE `sav_txns` ADD KEY `ix_sav_txns_trans` (`trans_no`)',
     'SELECT ''sav_txns: ix_sav_txns_trans already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND column_name = 'corrects_id') = 0,
  'SELECT ''sav_txns: no corrects_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sav_txns' AND index_name = 'ix_sav_txns_corrects') = 0,
     'ALTER TABLE `sav_txns` ADD KEY `ix_sav_txns_corrects` (`corrects_id`)',
     'SELECT ''sav_txns: ix_sav_txns_corrects already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'water_account_id') = 0,
  'SELECT ''members: no water_account_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'members' AND index_name = 'ix_members_water_account') = 0,
     'ALTER TABLE `members` ADD KEY `ix_members_water_account` (`water_account_id`)',
     'SELECT ''members: ix_members_water_account already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  5. Time deposit accrued interest withdrawn before maturity (code CAIW)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS td_interest_payouts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  placement_id  INT UNSIGNED NOT NULL,                 -- td_placements.id
  payout_date   DATE NOT NULL,
  amount        DECIMAL(15,2) NOT NULL,                -- interest withdrawn
  accrued_used  DECIMAL(15,2) NOT NULL DEFAULT 0.00,   -- the part taken from the accrued interest payable
  expensed      DECIMAL(15,2) NOT NULL DEFAULT 0.00,   -- the part not accrued yet, expensed at the withdrawal
  txn_id        BIGINT UNSIGNED NULL,                  -- the voucher (transactions.id)
  trans_no      VARCHAR(20) NULL,                      -- the savings transaction number
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_td_payouts_placement (placement_id),
  KEY ix_td_payouts_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. The savings transaction number series and the transaction codes
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('sav_trans', '', 1, 7);

-- the debit and credit memos: the GL account on the other side is chosen on each memo
UPDATE sav_txn_codes SET is_memo = 1, is_error_correct = 0 WHERE code IN ('CM', 'DM') AND related_code IS NULL;
-- a returned check is its own transaction (R Check), not the error correction of a deposit
UPDATE sav_txn_codes SET is_error_correct = 0, related_code = NULL WHERE code = 'RTC' AND is_error_correct = 1;

-- error-correct codes: the negation of an erroneous transaction of the related code, printed in the passbook
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT 'ECSD', 'Error Correct Cash Savings Deposit', 'user', NULL, 'credit', 0, 0, 1, 'CSD', 0, 1, 0 FROM DUAL
 WHERE EXISTS (SELECT 1 FROM sav_txn_codes WHERE code = 'CSD');
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT 'ECSW', 'Error Correct Cash Savings Withdrawal', 'user', NULL, 'debit', 0, 0, 1, 'CSW', 0, 1, 0 FROM DUAL
 WHERE EXISTS (SELECT 1 FROM sav_txn_codes WHERE code = 'CSW');
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT 'ECM', 'Error Correct Credit Memo', 'gl', NULL, 'credit', 0, 0, 1, 'CM', 0, 1, 1 FROM DUAL
 WHERE EXISTS (SELECT 1 FROM sav_txn_codes WHERE code = 'CM');
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT 'EDM', 'Error Correct Debit Memo', 'gl', NULL, 'debit', 0, 0, 1, 'DM', 0, 1, 1 FROM DUAL
 WHERE EXISTS (SELECT 1 FROM sav_txn_codes WHERE code = 'DM');
-- one error-correct code for each check deposit code (ECK3 for CK3 ...), on the same clearing account
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT CONCAT('E', c.code), LEFT(CONCAT('Error Correct ', c.description), 120), 'gl', c.opposite_account_id, c.entry_side, 0, 0, 1, c.code, 0, 1, 0
  FROM sav_txn_codes c
 WHERE c.is_check_deposit = 1 AND c.code LIKE 'CK%' AND CHAR_LENGTH(c.code) <= 9
   AND NOT EXISTS (SELECT 1 FROM (SELECT related_code FROM sav_txn_codes WHERE is_error_correct = 1) x WHERE x.related_code = c.code);
-- the accrued interest of a time deposit withdrawn before maturity, and the interest credited to the time deposit
INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
VALUES ('CAIW', 'Cash Accrued Interest Withdrawal', 'user', NULL, 'debit', 0, 0, 0, NULL, 1, 1, 0),
       ('TDI', 'Time Deposit Interest Credited', 'gl', NULL, 'credit', 0, 0, 0, NULL, 1, 1, 0);

-- ---------------------------------------------------------------------
--  6. The Savings Transaction Center page, with the access of Savings Deposit / Withdrawal
-- ---------------------------------------------------------------------

SET @sav_module = (SELECT module_id FROM pages WHERE page_key = 'sav.accounts' LIMIT 1);
SET @sav_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'sav.accounts');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'sav.center', @sav_module, 'Savings Transaction Center', 'center', 'Transactions', @sav_sort + 5, 1,
       'Every savings and time deposit transaction of the tellers, in one transaction number series.'
  FROM DUAL WHERE @sav_module IS NOT NULL;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'sav.transact'
  JOIN pages dst ON dst.page_key = 'sav.center';

UPDATE pages SET name = 'Savings Account Transaction' WHERE page_key = 'sav.transact' AND name = 'Savings Deposit / Withdrawal';
UPDATE pages SET name = 'Time Deposit Center' WHERE page_key = 'sav.td' AND name = 'Time Deposits';

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new columns.
-- ---------------------------------------------------------------------
