-- =====================================================================
--  G-SMaRT Accounting - migration: Cooperative Management (members, share
--  capital, savings, time deposits, loans, teller, year-end distribution,
--  CDA compliance)
--
--  What it changes
--    1. New tables for the cooperative module (all CREATE TABLE IF NOT EXISTS, nothing of the
--       accounting system is dropped or altered in meaning).
--    2. Three columns: users.coop_branch_id, users.coop_cash_account_id (the teller's branch and
--       cash drawer account) and other_names.member_id (a member's row in Employees & Other Names,
--       which is how members appear on the Subsidiary Ledger).
--    3. Cooperative accounts in the Chart of Accounts, added only where the code is free, and the
--       Cooperative module default accounts.
--    4. The pages of the Cooperative, Savings & Deposits, Loans, Teller, Year-end Distribution and
--       CDA Compliance modules, the new roles (General Manager, Bookkeeper, Loan Officer, Credit
--       Committee, Teller, Membership Officer, Collector, Branch Manager) and their page access.
--    5. Number sequences for members, accounts, loans, receipts, certificates and vouchers, the
--       standard savings transaction codes, member types, a passbook type and the settings.
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations (through 2026_10_22).
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  With several companies, run it on every company database.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_11_01_cooperative.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Setup tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS coop_branches (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  address        VARCHAR(255) NULL,
  phone          VARCHAR(60)  NULL,
  email          VARCHAR(120) NULL,
  class_id       INT UNSIGNED NULL,                 -- the class every posting of this branch carries
  is_head_office TINYINT(1)   NOT NULL DEFAULT 0,
  or_prefix      VARCHAR(10)  NOT NULL DEFAULT '',  -- official receipt series of the branch
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  UNIQUE KEY uq_coop_branches_code (code),
  KEY ix_coop_branches_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  member_kind VARCHAR(15)  NOT NULL DEFAULT 'individual',   -- individual / joint / institutional
  is_regular  TINYINT(1)   NOT NULL DEFAULT 1,              -- regular members vote and share in the surplus
  min_share_capital DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_member_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_clusters (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  branch_id INT UNSIGNED NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_clusters_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_segments (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_segments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coop_lists (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  list_key  VARCHAR(30)  NOT NULL,     -- loan_purpose, collateral_type, account_class, account_group, address_subgroup, savings_group, doc_type, id_type, occupation
  name      VARCHAR(120) NOT NULL,
  extra     VARCHAR(255) NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_coop_lists_key (list_key),
  UNIQUE KEY uq_coop_lists_name (list_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- passbook printing measurements (twips: 1 inch = 1440 twips, as the reference system)
CREATE TABLE IF NOT EXISTS passbook_types (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  description     VARCHAR(255) NULL,
  page_width      INT NOT NULL DEFAULT 12240,     -- twips
  page_height     INT NOT NULL DEFAULT 8640,
  cover_top       INT NOT NULL DEFAULT 900,
  cover_left      INT NOT NULL DEFAULT 2400,
  header_top      INT NOT NULL DEFAULT 700,
  header_left     INT NOT NULL DEFAULT 300,
  footer_top      INT NOT NULL DEFAULT 8000,
  footer_left     INT NOT NULL DEFAULT 100,
  body_top        INT NOT NULL DEFAULT 1230,
  body_left       INT NOT NULL DEFAULT 200,
  body_interval   INT NOT NULL DEFAULT 275,       -- distance between two lines
  start_line_skip INT NOT NULL DEFAULT 12,        -- lines left blank at the top of a new page
  line_skip       INT NOT NULL DEFAULT 1,
  max_lines       INT NOT NULL DEFAULT 26,        -- lines per page
  columns_json    TEXT NULL,                      -- column positions (twips) and widths
  font_size_pt    DECIMAL(4,1) NOT NULL DEFAULT 9.0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_passbook_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- printable document templates (promissory note, disclosure, certificates, notices) with version control
CREATE TABLE IF NOT EXISTS document_templates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_key        VARCHAR(40)  NOT NULL,     -- promissory_note, disclosure, comaker, release_stmt, td_certificate, share_certificate, member_notice, demand_letter
  name           VARCHAR(120) NOT NULL,
  paper          VARCHAR(10)  NOT NULL DEFAULT 'A4',
  body           LONGTEXT     NOT NULL,     -- HTML with {{placeholders}}
  effective_date DATE         NOT NULL,
  version_no     INT          NOT NULL DEFAULT 1,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_doc_templates_key (doc_key, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every print / reprint of a controlled document
CREATE TABLE IF NOT EXISTS document_prints (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doc_key    VARCHAR(40)  NOT NULL,
  ref_type   VARCHAR(30)  NOT NULL,       -- loan, td, sav_account, member, batch, receipt
  ref_id     BIGINT UNSIGNED NOT NULL,
  doc_no     VARCHAR(40)  NULL,
  copy_no    INT          NOT NULL DEFAULT 1,
  reason     VARCHAR(255) NULL,
  printed_by INT UNSIGNED NULL,
  printed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_doc_prints_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Members
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS members (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_no          VARCHAR(20)  NOT NULL,
  other_name_id      INT UNSIGNED NULL,               -- the member's row in other_names (subsidiary ledger name)
  branch_id          INT UNSIGNED NULL,
  member_type_id     INT UNSIGNED NULL,
  member_kind        VARCHAR(15)  NOT NULL DEFAULT 'individual',   -- individual / joint / institutional
  status             VARCHAR(12)  NOT NULL DEFAULT 'applicant',    -- applicant / active / suspended / resigned / terminated / deceased
  status_date        DATE         NULL,
  status_reason      VARCHAR(255) NULL,
  passbook_no        VARCHAR(20)  NULL,
  salutation         VARCHAR(10)  NULL,
  last_name          VARCHAR(80)  NULL,
  first_name         VARCHAR(80)  NULL,
  middle_name        VARCHAR(80)  NULL,
  suffix             VARCHAR(10)  NULL,
  full_name          VARCHAR(200) NOT NULL,           -- "Last, First M." or the institution / joint name
  birth_date         DATE         NULL,
  birth_place        VARCHAR(120) NULL,
  gender             VARCHAR(10)  NULL,
  civil_status       VARCHAR(15)  NULL,
  nationality        VARCHAR(40)  NULL DEFAULT 'Filipino',
  tin                VARCHAR(30)  NULL,
  email              VARCHAR(120) NULL,
  mobile             VARCHAR(40)  NULL,
  res_house          VARCHAR(60)  NULL,
  res_street         VARCHAR(120) NULL,
  res_barangay       VARCHAR(80)  NULL,
  res_city           VARCHAR(80)  NULL,
  res_province        VARCHAR(80)  NULL,
  res_postal         VARCHAR(10)  NULL,
  prov_house         VARCHAR(60)  NULL,
  prov_street        VARCHAR(120) NULL,
  prov_city          VARCHAR(80)  NULL,
  prov_province      VARCHAR(80)  NULL,
  prov_postal        VARCHAR(10)  NULL,
  off_house          VARCHAR(60)  NULL,
  off_street         VARCHAR(120) NULL,
  off_city           VARCHAR(80)  NULL,
  off_province       VARCHAR(80)  NULL,
  off_postal         VARCHAR(10)  NULL,
  education          VARCHAR(60)  NULL,
  year_completed     VARCHAR(10)  NULL,
  course             VARCHAR(120) NULL,
  occupation         VARCHAR(120) NULL,
  organization       VARCHAR(120) NULL,
  employment_type    VARCHAR(30)  NULL,               -- employed / self-employed / business / retired / student / other
  employer_name      VARCHAR(120) NULL,
  employer_contact   VARCHAR(40)  NULL,
  employer_address   VARCHAR(255) NULL,
  membership_date    DATE         NULL,
  pmes_date          DATE         NULL,               -- pre-membership education seminar
  cluster_id         INT UNSIGNED NULL,
  segment_id         INT UNSIGNED NULL,
  subscribed_shares  DECIMAL(15,2) NOT NULL DEFAULT 0,
  par_value          DECIMAL(15,2) NOT NULL DEFAULT 0,
  subscribed_capital DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_officer_id INT UNSIGNED NULL,               -- users.id (loan / account officer)
  account_class      VARCHAR(60)  NULL,               -- e.g. Board of Director, Regular
  account_group      VARCHAR(60)  NULL,
  address_subgroup   VARCHAR(60)  NULL,
  savings_group      VARCHAR(60)  NULL,
  institution_name   VARCHAR(200) NULL,               -- joint / institutional
  hold_flag          TINYINT(1)   NOT NULL DEFAULT 0, -- no transactions until released
  hold_reason        VARCHAR(255) NULL,
  alert_note         VARCHAR(255) NULL,               -- shown to tellers and loan officers
  photo_file         VARCHAR(120) NULL,
  signature_file     VARCHAR(120) NULL,
  deceased_date      DATE         NULL,
  notes              TEXT         NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_members_no (member_no),
  KEY ix_members_name (full_name(150)),
  KEY ix_members_status (status),
  KEY ix_members_branch (branch_id),
  KEY ix_members_other_name (other_name_id),
  KEY ix_members_type (member_type_id),
  KEY ix_members_cluster (cluster_id),
  KEY ix_members_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  contact_type VARCHAR(20)  NOT NULL,     -- mobile / landline / email / fax / other
  country_code VARCHAR(6)   NULL,
  area_code    VARCHAR(6)   NULL,
  number       VARCHAR(60)  NOT NULL,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_member_contacts_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_dependents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  kind         VARCHAR(10)  NOT NULL DEFAULT 'dependent',   -- spouse / dependent
  first_name   VARCHAR(80)  NULL,
  last_name    VARCHAR(80)  NULL,
  middle_name  VARCHAR(80)  NULL,
  relationship VARCHAR(40)  NULL,
  birth_date   DATE         NULL,
  contact_no   VARCHAR(40)  NULL,
  is_dependent TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_member_dependents_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_relatives (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id          INT UNSIGNED NOT NULL,
  relative_member_id INT UNSIGNED NOT NULL,
  relationship       VARCHAR(40)  NULL,
  KEY ix_member_relatives_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_finances (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED NOT NULL,
  kind          VARCHAR(10)  NOT NULL,      -- income / expense / outlay
  name          VARCHAR(120) NOT NULL,
  member_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  spouse_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_finances_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_assets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id   INT UNSIGNED NOT NULL,
  description VARCHAR(160) NOT NULL,
  asset_type  VARCHAR(10)  NOT NULL DEFAULT 'asset',   -- asset / liability
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_assets_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_beneficiaries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  relationship VARCHAR(40)  NULL,
  birth_date   DATE         NULL,
  contact_no   VARCHAR(40)  NULL,
  address      VARCHAR(255) NULL,
  share_pct    DECIMAL(6,2) NOT NULL DEFAULT 100,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_member_beneficiaries_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_representatives (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  name      VARCHAR(160) NOT NULL,
  position  VARCHAR(80)  NULL,
  rep_member_id INT UNSIGNED NULL,
  KEY ix_member_reps_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED NOT NULL,
  doc_type      VARCHAR(60)  NOT NULL,     -- ID, signature, photo, application form, proof of address ...
  doc_no        VARCHAR(60)  NULL,
  file_name     VARCHAR(120) NULL,         -- inside storage/coop/members/
  original_name VARCHAR(190) NULL,
  mime_type     VARCHAR(60)  NULL,
  issued_on     DATE         NULL,
  expires_on    DATE         NULL,
  notes         VARCHAR(255) NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_documents_member (member_id),
  KEY ix_member_documents_expiry (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_status_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  old_status VARCHAR(12)  NULL,
  new_status VARCHAR(12)  NOT NULL,
  changed_on DATE         NOT NULL,
  reason     VARCHAR(255) NULL,
  reference  VARCHAR(80)  NULL,          -- board resolution / claim reference
  changed_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_status_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_attendance (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  meeting_type VARCHAR(20)  NOT NULL,     -- ownership / general_assembly / training / other
  meeting_date DATE         NOT NULL,
  attended     TINYINT(1)   NOT NULL DEFAULT 1,
  remarks      VARCHAR(160) NULL,
  KEY ix_member_attendance_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_notes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  note       TEXT         NOT NULL,
  note_type  VARCHAR(20)  NOT NULL DEFAULT 'note',   -- note / alert / collection / restriction
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_notes_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. Savings, share capital and time deposits
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sav_products (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(10)  NOT NULL,
  name                  VARCHAR(80)  NOT NULL,
  description           VARCHAR(255) NULL,
  product_type          VARCHAR(15)  NOT NULL DEFAULT 'ordinary',   -- ordinary / special / time_deposit / share_capital
  passbook_type_id      INT UNSIGNED NULL,
  liability_account_id  INT UNSIGNED NULL,     -- deposit liability (share capital: the equity account)
  expense_account_id    INT UNSIGNED NULL,     -- interest expense
  accrued_account_id    INT UNSIGNED NULL,     -- accrued interest payable (time deposits)
  opening_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  maintaining_balance   DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_balance           DECIMAL(15,2) NOT NULL DEFAULT 0,     -- balance that must stay after a withdrawal
  max_balance           DECIMAL(15,2) NOT NULL DEFAULT 0,     -- 0 = no limit
  min_deposit           DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_withdrawal        DECIMAL(15,2) NOT NULL DEFAULT 0,
  maturity_value        INT          NOT NULL DEFAULT 0,      -- time deposits: term
  maturity_unit         VARCHAR(6)   NOT NULL DEFAULT 'days', -- days / months / years
  lockin_months         INT          NOT NULL DEFAULT 0,
  preterm_rate          DECIMAL(9,4) NULL,                    -- time deposits: rate paid when pre-terminated (NULL = none)
  preterm_fee           DECIMAL(15,2) NOT NULL DEFAULT 0,
  preterm_fee_account_id INT UNSIGNED NULL,
  dormancy_months       INT          NOT NULL DEFAULT 0,      -- 0 = never dormant
  dormancy_fee          DECIMAL(15,2) NOT NULL DEFAULT 0,
  dormancy_posting      VARCHAR(12)  NOT NULL DEFAULT 'quarterly',
  dormancy_fee_account_id INT UNSIGNED NULL,
  rate_after_maturity   VARCHAR(10)  NOT NULL DEFAULT 'same',  -- same / zero / fixed
  rate_after_value      DECIMAL(9,4) NOT NULL DEFAULT 0,
  interest_basis        VARCHAR(10)  NOT NULL DEFAULT 'adb',   -- adb / lowest / ending
  interest_method       VARCHAR(10)  NOT NULL DEFAULT 'simple', -- simple / compound
  calendar_days         SMALLINT     NOT NULL DEFAULT 365,
  tax_rate              DECIMAL(9,4) NOT NULL DEFAULT 0,       -- withholding on interest, if any
  tax_account_id        INT UNSIGNED NULL,
  eligible_types        VARCHAR(100) NULL,                     -- member_types ids "1,2" (blank = all)
  allow_withdrawal      TINYINT(1)   NOT NULL DEFAULT 1,
  withdrawal_approval   TINYINT(1)   NOT NULL DEFAULT 0,       -- withdrawals need supervisor approval
  par_value             DECIMAL(15,2) NOT NULL DEFAULT 0,      -- share capital
  share_class           VARCHAR(20)  NULL,                     -- common / preferred
  isc_eligible          TINYINT(1)   NOT NULL DEFAULT 0,       -- counts for interest on share capital
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order            INT          NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_sav_products_code (code),
  KEY ix_sav_products_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- interest rates by amount range (and term range for time deposits), with the date they took effect
CREATE TABLE IF NOT EXISTS sav_product_rates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  amount_from    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to      DECIMAL(15,2) NOT NULL DEFAULT 999999999999.99,
  term_from      INT          NOT NULL DEFAULT 0,       -- days (time deposits), 0 = any
  term_to        INT          NOT NULL DEFAULT 0,
  effective_date DATE         NOT NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,       -- percent per annum
  posting_method VARCHAR(12)  NOT NULL DEFAULT 'quarterly',   -- monthly / quarterly / semi_annual / annual / maturity
  KEY ix_spr_product (product_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- transaction codes of the savings ledger (CSD cash savings deposit, CSW cash withdrawal, CK4 check deposit 4 days clearing ...)
CREATE TABLE IF NOT EXISTS sav_txn_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(10)  NOT NULL,
  description         VARCHAR(120) NOT NULL,
  opposite_kind       VARCHAR(6)   NOT NULL DEFAULT 'user',   -- gl: a fixed account; user: the teller's cash account / chosen at entry
  opposite_account_id INT UNSIGNED NULL,
  entry_side          VARCHAR(6)   NOT NULL DEFAULT 'credit', -- credit = money into the account, debit = out
  is_check_deposit    TINYINT(1)   NOT NULL DEFAULT 0,
  clearing_days       INT          NOT NULL DEFAULT 0,
  is_error_correct    TINYINT(1)   NOT NULL DEFAULT 0,
  related_code        VARCHAR(10)  NULL,
  is_system           TINYINT(1)   NOT NULL DEFAULT 0,        -- used by the program itself (interest, fees, loan proceeds ...)
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sav_txn_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sav_accounts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_no        VARCHAR(30)  NOT NULL,
  member_id         INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  branch_id         INT UNSIGNED NULL,
  passbook_no       VARCHAR(20)  NULL,
  passbook_type_id  INT UNSIGNED NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / active / dormant / closed
  opened_on         DATE         NULL,
  approved_by       INT UNSIGNED NULL,
  approved_at       DATETIME     NULL,
  closed_on         DATE         NULL,
  close_reason      VARCHAR(255) NULL,
  balance           DECIMAL(15,2) NOT NULL DEFAULT 0,      -- ledger balance
  uncleared         DECIMAL(15,2) NOT NULL DEFAULT 0,      -- check deposits still clearing
  hold_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,      -- holds (loan security, garnishment)
  interest_earned   DECIMAL(15,2) NOT NULL DEFAULT 0,      -- lifetime interest credited
  last_txn_date     DATE         NULL,
  last_interest_date DATE        NULL,
  dormant_since     DATE         NULL,
  is_restricted     TINYINT(1)   NOT NULL DEFAULT 0,
  restrict_reason   VARCHAR(255) NULL,
  pb_last_line      INT          NOT NULL DEFAULT 0,      -- passbook: last printed line on the page
  pb_page           INT          NOT NULL DEFAULT 1,
  pb_last_txn_id    BIGINT UNSIGNED NULL,                 -- last sav_txns.id printed
  pb_issued_count   INT          NOT NULL DEFAULT 1,      -- passbooks issued (replacements)
  notes             VARCHAR(255) NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL,
  UNIQUE KEY uq_sav_accounts_no (account_no),
  KEY ix_sav_accounts_member (member_id),
  KEY ix_sav_accounts_product (product_id),
  KEY ix_sav_accounts_status (status),
  KEY ix_sav_accounts_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the savings subsidiary ledger: one row per movement, each tied to the GL transaction that posted it
CREATE TABLE IF NOT EXISTS sav_txns (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id    INT UNSIGNED NOT NULL,
  txn_date      DATE         NOT NULL,
  value_date    DATE         NULL,                 -- backdated entries: the date it is effective for interest
  txn_code      VARCHAR(10)  NOT NULL,
  description   VARCHAR(160) NULL,
  debit         DECIMAL(15,2) NOT NULL DEFAULT 0,  -- out of the account
  credit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- into the account
  balance_after DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id        BIGINT UNSIGNED NULL,              -- transactions.id (the GL voucher / receipt)
  or_no         VARCHAR(30)  NULL,
  teller_id     INT UNSIGNED NULL,
  shift_id      INT UNSIGNED NULL,
  clearing_date DATE         NULL,                 -- check deposits
  is_cleared    TINYINT(1)   NOT NULL DEFAULT 1,
  cleared_txn_id BIGINT UNSIGNED NULL,             -- the voucher that cleared or returned the check
  is_voided     TINYINT(1)   NOT NULL DEFAULT 0,
  voided_txn_id BIGINT UNSIGNED NULL,
  pb_printed    TINYINT(1)   NOT NULL DEFAULT 0,
  pb_line       INT          NULL,
  pb_page       INT          NULL,
  ref_type      VARCHAR(20)  NULL,                 -- interest_run, td, loan, dist, batch, transfer
  ref_id        BIGINT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sav_txns_account (account_id, txn_date, id),
  KEY ix_sav_txns_txn (txn_id),
  KEY ix_sav_txns_ref (ref_type, ref_id),
  KEY ix_sav_txns_shift (shift_id),
  KEY ix_sav_txns_clearing (is_cleared, clearing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sav_holds (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id      INT UNSIGNED NOT NULL,
  amount          DECIMAL(15,2) NOT NULL,
  reason          VARCHAR(255) NULL,
  loan_account_id INT UNSIGNED NULL,
  placed_by       INT UNSIGNED NULL,
  placed_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by     INT UNSIGNED NULL,
  released_at     DATETIME     NULL,
  KEY ix_sav_holds_account (account_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one interest posting per product and period (the unique key prevents a second posting of the same period)
CREATE TABLE IF NOT EXISTS sav_interest_runs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  period_from    DATE         NOT NULL,
  period_to      DATE         NOT NULL,
  posting_date   DATE         NOT NULL,
  txn_id         BIGINT UNSIGNED NULL,
  accounts_count INT          NOT NULL DEFAULT 0,
  total_interest DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_tax      DECIMAL(15,2) NOT NULL DEFAULT 0,
  status         VARCHAR(10)  NOT NULL DEFAULT 'posted',   -- preview / posted / voided
  run_by         INT UNSIGNED NULL,
  run_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sav_interest_runs (product_id, period_to, status),
  KEY ix_sav_interest_runs_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sav_interest_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id       INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  basis_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  days         INT          NOT NULL DEFAULT 0,
  rate         DECIMAL(9,4) NOT NULL DEFAULT 0,
  interest     DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net          DECIMAL(15,2) NOT NULL DEFAULT 0,
  sav_txn_id   BIGINT UNSIGNED NULL,
  KEY ix_sav_interest_lines_run (run_id),
  KEY ix_sav_interest_lines_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS td_placements (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id       INT UNSIGNED NOT NULL,          -- the time deposit account (sav_accounts, product_type time_deposit)
  certificate_no   VARCHAR(30)  NOT NULL,
  principal        DECIMAL(15,2) NOT NULL,
  rate             DECIMAL(9,4) NOT NULL,
  term_days        INT          NOT NULL,
  term_label       VARCHAR(30)  NULL,
  placement_date   DATE         NOT NULL,
  maturity_date    DATE         NOT NULL,
  maturity_value   DECIMAL(15,2) NOT NULL,
  interest_method  VARCHAR(10)  NOT NULL DEFAULT 'simple',   -- simple / compound
  compounding      VARCHAR(12)  NOT NULL DEFAULT 'maturity', -- maturity / monthly / quarterly
  payout_mode      VARCHAR(12)  NOT NULL DEFAULT 'maturity', -- maturity / monthly / quarterly
  maturity_action  VARCHAR(20)  NOT NULL DEFAULT 'payout',   -- payout / renew_principal / renew_all / credit_savings
  credit_account_id INT UNSIGNED NULL,                      -- savings account to credit at maturity
  calendar_days    SMALLINT     NOT NULL DEFAULT 365,
  status           VARCHAR(14)  NOT NULL DEFAULT 'active',   -- active / matured / paid / renewed / preterminated
  accrued_interest DECIMAL(15,2) NOT NULL DEFAULT 0,        -- accrued so far (GL: accrued interest payable)
  interest_paid    DECIMAL(15,2) NOT NULL DEFAULT 0,
  renewed_from_id  INT UNSIGNED NULL,
  renewed_to_id    INT UNSIGNED NULL,
  placement_txn_id BIGINT UNSIGNED NULL,
  settled_txn_id   BIGINT UNSIGNED NULL,
  settled_on       DATE         NULL,
  preterm_rate     DECIMAL(9,4) NULL,
  preterm_fee      DECIMAL(15,2) NULL,
  reprint_count    INT          NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_td_certificate (certificate_no),
  KEY ix_td_account (account_id),
  KEY ix_td_status (status, maturity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS td_accruals (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  placement_id INT UNSIGNED NOT NULL,
  accrual_date DATE         NOT NULL,
  period_from  DATE         NOT NULL,
  period_to    DATE         NOT NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_td_accruals (placement_id, period_to),
  KEY ix_td_accruals_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passbook_prints (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  ledger     VARCHAR(10)  NOT NULL DEFAULT 'savings',   -- savings / loan
  print_type VARCHAR(10)  NOT NULL,                     -- cover / lines / footer / reprint / reset / replace
  from_txn   BIGINT UNSIGNED NULL,
  to_txn     BIGINT UNSIGNED NULL,
  line_count INT          NOT NULL DEFAULT 0,
  page_no    INT          NULL,
  reason     VARCHAR(255) NULL,
  printed_by INT UNSIGNED NULL,
  printed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_passbook_prints_account (account_id, ledger)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- batch postings (payroll deductions, capital build-up): checked first, then posted as one voucher
CREATE TABLE IF NOT EXISTS sav_batches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_no     VARCHAR(30)  NOT NULL,
  batch_date   DATE         NOT NULL,
  description  VARCHAR(160) NULL,
  source       VARCHAR(20)  NOT NULL DEFAULT 'payroll',   -- payroll / cbu / import / other
  contra_account_id INT UNSIGNED NULL,                    -- the account the money comes from (payroll clearing, bank)
  branch_id    INT UNSIGNED NULL,
  total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  lines_count  INT          NOT NULL DEFAULT 0,
  status       VARCHAR(10)  NOT NULL DEFAULT 'draft',      -- draft / posted / voided
  txn_id       BIGINT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at    DATETIME     NULL,
  UNIQUE KEY uq_sav_batches_no (batch_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sav_batch_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id    INT UNSIGNED NOT NULL,
  account_id  INT UNSIGNED NULL,                   -- savings account, or
  loan_id     INT UNSIGNED NULL,                   -- loan account (payroll amortization)
  member_no   VARCHAR(20)  NULL,
  account_no  VARCHAR(30)  NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  remarks     VARCHAR(160) NULL,
  error_text  VARCHAR(255) NULL,
  KEY ix_sav_batch_lines_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. Loans
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS loan_products (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(10)  NOT NULL,
  name                  VARCHAR(80)  NOT NULL,
  loan_type             VARCHAR(40)  NULL,             -- Provident, Productive, Emergency ... (free text group)
  description           VARCHAR(255) NULL,
  receivable_account_id INT UNSIGNED NULL,
  past_due_account_id   INT UNSIGNED NULL,             -- balance moved here when past due (optional)
  passbook_type_id      INT UNSIGNED NULL,
  mode_of_payment       VARCHAR(12)  NOT NULL DEFAULT 'monthly',   -- daily / weekly / semi_monthly / monthly / quarterly / semi_annual / annual / lump_sum
  max_loans             INT          NOT NULL DEFAULT 1,           -- open loans of this product a member may have
  term_unit             VARCHAR(6)   NOT NULL DEFAULT 'months',    -- days / months
  term_min              DECIMAL(9,2) NOT NULL DEFAULT 1,
  term_max              DECIMAL(9,2) NOT NULL DEFAULT 36,
  amount_min            DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_max            DECIMAL(15,2) NOT NULL DEFAULT 0,
  charge_method         VARCHAR(12)  NOT NULL DEFAULT 'deducted',  -- deducted / separate / capitalized
  interest_method       VARCHAR(12)  NOT NULL DEFAULT 'diminishing',   -- diminishing / straight
  amort_type            VARCHAR(20)  NOT NULL DEFAULT 'equal_amortization',   -- equal_amortization ("Equal Loan") / equal_principal ("Fixed Principal")
  diminishing_by        VARCHAR(10)  NOT NULL DEFAULT 'same',      -- same (as mode of payment) / daily
  past_due_basis        VARCHAR(20)  NOT NULL DEFAULT 'balance_due_date',   -- balance_due_date / schedule
  rate_default          DECIMAL(9,4) NOT NULL DEFAULT 0,
  rate_opt1             DECIMAL(9,4) NULL,
  rate_opt2             DECIMAL(9,4) NULL,
  rate_opt3             DECIMAL(9,4) NULL,
  calendar_days         SMALLINT     NOT NULL DEFAULT 365,
  interest_account_id   INT UNSIGNED NULL,
  interest_posting      VARCHAR(10)  NOT NULL DEFAULT 'monthly',   -- accrual posting of interest income (monthly / none)
  accrued_int_account_id INT UNSIGNED NULL,                        -- interest receivable
  penalty_grace_days    INT          NOT NULL DEFAULT 0,
  penalty_rate          DECIMAL(9,4) NOT NULL DEFAULT 0,           -- percent per annum
  penalty_base          VARCHAR(20)  NOT NULL DEFAULT 'arrears',   -- arrears / principal_arrears / balance
  penalty_account_id    INT UNSIGNED NULL,
  req_member_months     INT          NOT NULL DEFAULT 0,
  req_comakers          INT          NOT NULL DEFAULT 0,
  req_ndi               DECIMAL(15,2) NOT NULL DEFAULT 0,          -- minimum net disposable income
  req_segment_id        INT UNSIGNED NULL,
  req_capital_mode      VARCHAR(6)   NOT NULL DEFAULT 'min',       -- min (amount) / pct (of loan amount)
  req_capital_value     DECIMAL(15,2) NOT NULL DEFAULT 0,
  req_savings_mode      VARCHAR(6)   NOT NULL DEFAULT 'min',
  req_savings_value     DECIMAL(15,2) NOT NULL DEFAULT 0,
  req_age_min           INT          NOT NULL DEFAULT 0,
  req_age_max           INT          NOT NULL DEFAULT 0,
  requirements_json     TEXT         NULL,                         -- documentary checklist
  needs_ci              TINYINT(1)   NOT NULL DEFAULT 0,
  needs_collateral      TINYINT(1)   NOT NULL DEFAULT 0,
  patronage_eligible    TINYINT(1)   NOT NULL DEFAULT 1,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order            INT          NOT NULL DEFAULT 0,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_loan_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- term- or amount-based rates with effective dates (used before the product's default rate when one matches)
CREATE TABLE IF NOT EXISTS loan_product_rates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  term_from      DECIMAL(9,2) NOT NULL DEFAULT 0,
  term_to        DECIMAL(9,2) NOT NULL DEFAULT 0,       -- 0 = any term
  amount_from    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to      DECIMAL(15,2) NOT NULL DEFAULT 0,      -- 0 = any amount
  effective_date DATE         NOT NULL,
  rate           DECIMAL(9,4) NOT NULL,
  KEY ix_lpr_product (product_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_charges (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL,
  name           VARCHAR(80)  NOT NULL,
  charge_type    VARCHAR(15)  NOT NULL DEFAULT 'financial',   -- financial (income) / non_financial (payable, e.g. insurance) / savings (credited to a member savings product)
  account_id     INT UNSIGNED NULL,
  sav_product_id INT UNSIGNED NULL,
  charge_by      VARCHAR(20)  NOT NULL DEFAULT 'fixed_rate',  -- none / fixed_rate / fixed_amount / rate_by_term / rate_by_amount / amount_by_term / amount_by_amount
  value          DECIMAL(15,4) NOT NULL DEFAULT 0,
  effective_date DATE         NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_loan_charges_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_charge_ranges (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  charge_id  INT UNSIGNED NOT NULL,
  range_from DECIMAL(15,4) NOT NULL DEFAULT 0,
  range_to   DECIMAL(15,4) NOT NULL DEFAULT 0,
  value      DECIMAL(15,4) NOT NULL DEFAULT 0,
  KEY ix_lcr_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_product_charges (
  product_id INT UNSIGNED NOT NULL,
  charge_id  INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_approval_matrix (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  level_no    INT          NOT NULL DEFAULT 1,
  amount_from DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_to   DECIMAL(15,2) NOT NULL DEFAULT 0,      -- 0 = no upper limit
  product_id  INT UNSIGNED NULL,                     -- NULL = every product
  role_id     INT UNSIGNED NULL,                     -- the role that may approve at this level (NULL = anyone with Approve on the page)
  position    VARCHAR(80)  NULL,                     -- printed on the approval
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_lam_level (level_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_applications (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_no   VARCHAR(30)  NOT NULL,
  member_id        INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NOT NULL,
  branch_id        INT UNSIGNED NULL,
  application_date DATE         NOT NULL,
  purpose          VARCHAR(120) NULL,
  purpose_detail   VARCHAR(255) NULL,
  term             DECIMAL(9,2) NOT NULL DEFAULT 0,
  term_unit        VARCHAR(6)   NOT NULL DEFAULT 'months',
  mode_of_payment  VARCHAR(12)  NOT NULL DEFAULT 'monthly',
  applied_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_amount  DECIMAL(15,2) NULL,
  rate             DECIMAL(9,4) NOT NULL DEFAULT 0,
  amort_type       VARCHAR(20)  NULL,
  status           VARCHAR(14)  NOT NULL DEFAULT 'new',   -- new / validated / for_ci / for_approval / approved / disapproved / returned / released / cancelled
  ci_id            INT UNSIGNED NULL,
  monthly_income   DECIMAL(15,2) NOT NULL DEFAULT 0,
  spouse_income    DECIMAL(15,2) NOT NULL DEFAULT 0,
  ndi              DECIMAL(15,2) NOT NULL DEFAULT 0,       -- net disposable income at application
  dependents       INT          NOT NULL DEFAULT 0,
  employer_name    VARCHAR(120) NULL,
  business_name    VARCHAR(120) NULL,
  spouse_name      VARCHAR(120) NULL,
  snapshot_json    TEXT         NULL,                      -- capital, savings, TD, existing loans at application
  eligibility_json TEXT         NULL,                      -- the checks and their results
  officer_id       INT UNSIGNED NULL,
  loan_id          INT UNSIGNED NULL,                      -- the released loan account
  remarks          VARCHAR(255) NULL,
  cancel_reason    VARCHAR(255) NULL,
  previous_loan_id INT UNSIGNED NULL,                      -- renewal / restructuring: the open loan paid off from the proceeds
  is_restructure   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_loan_applications_no (application_no),
  KEY ix_loan_applications_member (member_id),
  KEY ix_loan_applications_status (status),
  KEY ix_loan_applications_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_collaterals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  collateral_type VARCHAR(60)  NOT NULL,
  description     VARCHAR(255) NULL,
  ref_no          VARCHAR(80)  NULL,
  fmv             DECIMAL(15,2) NOT NULL DEFAULT 0,
  loan_value      DECIMAL(15,2) NOT NULL DEFAULT 0,
  expiry_date     DATE         NULL,
  insurance_expiry DATE        NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'held',   -- held / released
  released_on     DATE         NULL,
  KEY ix_loan_collaterals_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_comakers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id    INT UNSIGNED NOT NULL,
  comaker_member_id INT UNSIGNED NULL,
  name              VARCHAR(160) NULL,
  address           VARCHAR(255) NULL,
  amount_guaranteed DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_loan_comakers_app (application_id),
  KEY ix_loan_comakers_member (comaker_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_approvals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id  INT UNSIGNED NOT NULL,
  level_no        INT          NOT NULL DEFAULT 1,
  approver_id     INT UNSIGNED NOT NULL,
  position        VARCHAR(80)  NULL,
  approved_amount DECIMAL(15,2) NULL,
  action          VARCHAR(12)  NOT NULL,       -- approved / disapproved / returned / deferred
  remarks         VARCHAR(255) NULL,
  acted_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_approvals_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_application_docs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  requirement    VARCHAR(120) NOT NULL,
  is_complete    TINYINT(1)   NOT NULL DEFAULT 0,
  file_name      VARCHAR(120) NULL,
  original_name  VARCHAR(190) NULL,
  remarks        VARCHAR(160) NULL,
  KEY ix_loan_app_docs_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_ci (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id   INT UNSIGNED NOT NULL,
  member_id        INT UNSIGNED NOT NULL,
  date_reported    DATE         NOT NULL,
  last_updated     DATE         NULL,
  spouse_name      VARCHAR(120) NULL,
  current_address  VARCHAR(255) NULL,
  previous_address VARCHAR(255) NULL,
  residence_type   VARCHAR(40)  NULL,
  house_type       VARCHAR(40)  NULL,
  building_type    VARCHAR(40)  NULL,
  structure_type   VARCHAR(40)  NULL,
  storeys          INT          NULL,
  rooms            INT          NULL,
  lot_area         DECIMAL(10,2) NULL,
  floor_area       DECIMAL(10,2) NULL,
  estimated_value  DECIMAL(15,2) NULL,
  remarks          TEXT         NULL,
  informants       VARCHAR(255) NULL,
  conducted_by     VARCHAR(120) NULL,
  recommendation   VARCHAR(20)  NULL,       -- recommended / not_recommended / with_conditions
  score            DECIMAL(6,2) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_ci_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_ci_lines (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ci_id     INT UNSIGNED NOT NULL,
  kind      VARCHAR(12)  NOT NULL,     -- child / income / asset / reference
  name      VARCHAR(160) NULL,
  detail1   VARCHAR(160) NULL,         -- child: level | income: business/employer | asset: type | reference: occupation
  detail2   VARCHAR(160) NULL,         -- child: school | income: type | reference: contact
  detail3   VARCHAR(160) NULL,         -- income: position
  years     DECIMAL(5,1) NULL,
  amount    DECIMAL(15,2) NULL,        -- income: net income | asset: amount
  KEY ix_loan_ci_lines_ci (ci_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_accounts (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_no            VARCHAR(30)  NOT NULL,
  application_id     INT UNSIGNED NULL,
  member_id          INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  branch_id          INT UNSIGNED NULL,
  pn_no              VARCHAR(30)  NULL,
  principal          DECIMAL(15,2) NOT NULL,
  rate               DECIMAL(9,4) NOT NULL,
  interest_method    VARCHAR(12)  NOT NULL,
  amort_type         VARCHAR(20)  NOT NULL,
  term               DECIMAL(9,2) NOT NULL,
  term_unit          VARCHAR(6)   NOT NULL,
  mode_of_payment    VARCHAR(12)  NOT NULL,
  calendar_days      SMALLINT     NOT NULL DEFAULT 365,
  release_date       DATE         NOT NULL,
  start_date         DATE         NOT NULL,          -- first amortization date
  due_date           DATE         NOT NULL,          -- maturity
  release_mode       VARCHAR(10)  NOT NULL DEFAULT 'cash',   -- cash / cheque / savings
  cheque_no          VARCHAR(40)  NULL,
  cash_account_id    INT UNSIGNED NULL,
  credit_account_id  INT UNSIGNED NULL,              -- savings account credited with the proceeds
  receivable_account_id INT UNSIGNED NOT NULL,
  interest_account_id INT UNSIGNED NOT NULL,
  penalty_account_id INT UNSIGNED NULL,
  penalty_rate       DECIMAL(9,4) NOT NULL DEFAULT 0,
  penalty_grace_days INT          NOT NULL DEFAULT 0,
  penalty_base       VARCHAR(20)  NOT NULL DEFAULT 'arrears',
  total_interest     DECIMAL(15,2) NOT NULL DEFAULT 0,     -- scheduled interest
  total_charges      DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_proceeds       DECIMAL(15,2) NOT NULL DEFAULT 0,
  previous_loan_payoff DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(14)  NOT NULL DEFAULT 'current',   -- current / past_due / paid / restructured / written_off / cancelled
  principal_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_balance   DECIMAL(15,2) NOT NULL DEFAULT 0,     -- scheduled interest not yet paid
  interest_accrued   DECIMAL(15,2) NOT NULL DEFAULT 0,     -- interest income already recognised (accrual runs)
  penalty_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,     -- penalties charged and unpaid
  penalty_waived     DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_waived    DECIMAL(15,2) NOT NULL DEFAULT 0,
  last_payment_date  DATE         NULL,
  last_penalty_date  DATE         NULL,                    -- penalties computed through this date
  officer_id         INT UNSIGNED NULL,
  release_txn_id     BIGINT UNSIGNED NULL,
  closed_on          DATE         NULL,
  restructured_to_id INT UNSIGNED NULL,
  restructured_from_id INT UNSIGNED NULL,
  written_off_on     DATE         NULL,
  written_off_txn_id BIGINT UNSIGNED NULL,
  recovered_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  pb_last_line       INT          NOT NULL DEFAULT 0,
  pb_page            INT          NOT NULL DEFAULT 1,
  pb_last_txn_id     BIGINT UNSIGNED NULL,
  notes              VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_loan_accounts_no (loan_no),
  KEY ix_loan_accounts_member (member_id),
  KEY ix_loan_accounts_status (status, due_date),
  KEY ix_loan_accounts_product (product_id),
  KEY ix_loan_accounts_branch (branch_id),
  KEY ix_loan_accounts_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_schedule (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  inst_no        INT          NOT NULL,
  due_date       DATE         NOT NULL,
  principal      DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest       DECIMAL(15,2) NOT NULL DEFAULT 0,
  total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_paid  DECIMAL(15,2) NOT NULL DEFAULT 0,
  balance_after  DECIMAL(15,2) NOT NULL DEFAULT 0,     -- principal balance after this instalment
  is_paid        TINYINT(1)   NOT NULL DEFAULT 0,
  paid_on        DATE         NULL,
  UNIQUE KEY uq_loan_schedule (loan_id, inst_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_charges_applied (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  charge_id      INT UNSIGNED NULL,
  name           VARCHAR(80)  NOT NULL,
  charge_type    VARCHAR(15)  NOT NULL DEFAULT 'financial',
  basis_text     VARCHAR(60)  NULL,            -- "3.00 %" / "fixed 150.00"
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  sav_account_id INT UNSIGNED NULL,
  how            VARCHAR(12)  NOT NULL DEFAULT 'deducted',   -- deducted / separate / capitalized
  KEY ix_loan_charges_applied_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the loan subsidiary ledger: every movement with the GL transaction behind it
CREATE TABLE IF NOT EXISTS loan_txns (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id       INT UNSIGNED NOT NULL,
  txn_date      DATE         NOT NULL,
  kind          VARCHAR(16)  NOT NULL,       -- release / payment / penalty / adjustment / waiver / accrual / writeoff / recovery / restructure
  memo          VARCHAR(160) NULL,
  principal_dr  DECIMAL(15,2) NOT NULL DEFAULT 0,   -- charges to the member (release, adjustments)
  interest_dr   DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_dr    DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_cr  DECIMAL(15,2) NOT NULL DEFAULT 0,   -- payments / waivers
  interest_cr   DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_cr    DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_bal DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_bal  DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_bal   DECIMAL(15,2) NOT NULL DEFAULT 0,
  txn_id        BIGINT UNSIGNED NULL,
  or_no         VARCHAR(30)  NULL,
  teller_id     INT UNSIGNED NULL,
  shift_id      INT UNSIGNED NULL,
  is_voided     TINYINT(1)   NOT NULL DEFAULT 0,
  pb_printed    TINYINT(1)   NOT NULL DEFAULT 0,
  pb_line       INT          NULL,
  pb_page       INT          NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_txns_loan (loan_id, txn_date, id),
  KEY ix_loan_txns_txn (txn_id),
  KEY ix_loan_txns_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- which instalments a payment settled (rebuilt from here when a payment is voided)
CREATE TABLE IF NOT EXISTS loan_payment_alloc (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_txn_id BIGINT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  principal   DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest    DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_lpa_txn (loan_txn_id),
  KEY ix_lpa_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_waivers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id         INT UNSIGNED NOT NULL,
  waive_date      DATE         NOT NULL,
  interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  memo            VARCHAR(255) NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending / approved / posted / rejected / voided
  requested_by    INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  txn_id          BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_waivers_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_adjustments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id        INT UNSIGNED NOT NULL,
  adj_date       DATE         NOT NULL,
  adj_account_id INT UNSIGNED NOT NULL,
  principal_amt  DECIMAL(15,2) NOT NULL DEFAULT 0,
  principal_type VARCHAR(8)   NULL,     -- increase / decrease
  interest_amt   DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_type  VARCHAR(8)   NULL,
  penalty_amt    DECIMAL(15,2) NOT NULL DEFAULT 0,
  penalty_type   VARCHAR(8)   NULL,
  memo           VARCHAR(255) NULL,
  txn_id         BIGINT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_adjustments_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_notices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id     INT UNSIGNED NOT NULL,
  notice_type VARCHAR(20)  NOT NULL,      -- reminder / demand / final_demand / followup
  notice_date DATE         NOT NULL,
  amount_due  DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes       VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_loan_notices_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  5. Teller
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS teller_shifts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  branch_id         INT UNSIGNED NULL,
  cash_account_id   INT UNSIGNED NOT NULL,
  business_date     DATE         NOT NULL,
  opened_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_cash      DECIMAL(15,2) NOT NULL DEFAULT 0,
  receipts_total    DECIMAL(15,2) NOT NULL DEFAULT 0,
  disbursements_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash     DECIMAL(15,2) NULL,
  counted_cash      DECIMAL(15,2) NULL,
  over_short        DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  denominations_json TEXT        NULL,
  status            VARCHAR(8)   NOT NULL DEFAULT 'open',   -- open / closed
  closed_at         DATETIME     NULL,
  closed_by         INT UNSIGNED NULL,
  notes             VARCHAR(255) NULL,
  KEY ix_teller_shifts_user (user_id, status),
  KEY ix_teller_shifts_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- official receipts and member disbursements: the header rides on transactions (txn_type coop_or / coop_cv), this is the receipt face
CREATE TABLE IF NOT EXISTS coop_receipts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id           BIGINT UNSIGNED NOT NULL,
  receipt_kind     VARCHAR(4)   NOT NULL DEFAULT 'or',   -- or (money in) / cv (money out)
  receipt_no       VARCHAR(30)  NOT NULL,
  member_id        INT UNSIGNED NULL,
  payor_name       VARCHAR(160) NULL,
  branch_id        INT UNSIGNED NULL,
  shift_id         INT UNSIGNED NULL,
  payment_mode     VARCHAR(10)  NOT NULL DEFAULT 'cash',  -- cash / check / bank / savings (transfer)
  cash_account_id  INT UNSIGNED NULL,
  cheque_json      TEXT         NULL,                     -- [{cheque_no, bank, date, amount}]
  check_pending    DECIMAL(15,2) NOT NULL DEFAULT 0,      -- check money for loan payments / fees waiting in the clearing account
  check_cleared_txn_id BIGINT UNSIGNED NULL,              -- the voucher that cleared it to the bank
  total            DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_back_entry    TINYINT(1)   NOT NULL DEFAULT 0,
  print_count      INT          NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_coop_receipts_no (receipt_kind, receipt_no),
  UNIQUE KEY uq_coop_receipts_txn (txn_id),
  KEY ix_coop_receipts_member (member_id),
  KEY ix_coop_receipts_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coop_receipt_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_id  BIGINT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  kind        VARCHAR(16)  NOT NULL,        -- savings / loan_principal / loan_interest / loan_penalty / charge / other / td
  ref_id      BIGINT UNSIGNED NULL,         -- sav_accounts.id / loan_accounts.id
  account_id  INT UNSIGNED NULL,            -- GL account the line posted to
  description VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_coop_receipt_lines_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- (a first version of this file made the column 12 characters long; widening it again changes nothing)
ALTER TABLE coop_receipt_lines MODIFY kind VARCHAR(16) NOT NULL;


-- ---------------------------------------------------------------------
--  6. Year-end distribution
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS dist_periods (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fiscal_year     SMALLINT     NOT NULL,
  period_from     DATE         NOT NULL,
  period_to       DATE         NOT NULL,
  net_surplus     DECIMAL(15,2) NOT NULL DEFAULT 0,
  reserve_pct     DECIMAL(6,2) NOT NULL DEFAULT 10,
  reserve_amt     DECIMAL(15,2) NOT NULL DEFAULT 0,
  educ_pct        DECIMAL(6,2) NOT NULL DEFAULT 10,
  educ_amt        DECIMAL(15,2) NOT NULL DEFAULT 0,
  educ_apex_pct   DECIMAL(6,2) NOT NULL DEFAULT 50,     -- share of the education fund due to the apex organisation
  cdf_pct         DECIMAL(6,2) NOT NULL DEFAULT 3,
  cdf_amt         DECIMAL(15,2) NOT NULL DEFAULT 0,
  optional_pct    DECIMAL(6,2) NOT NULL DEFAULT 7,
  optional_amt    DECIMAL(15,2) NOT NULL DEFAULT 0,
  isc_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  pr_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'draft',    -- draft / approved / posted / closed
  resolution_no   VARCHAR(60)  NULL,
  resolution_date DATE         NULL,
  approving_officers VARCHAR(255) NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  allocation_txn_id BIGINT UNSIGNED NULL,
  notes           TEXT         NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dist_periods_year (fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dist_batches (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  period_id        INT UNSIGNED NULL,
  kind             VARCHAR(4)   NOT NULL,            -- isc / pr
  batch_no         VARCHAR(30)  NOT NULL,
  description      VARCHAR(160) NULL,
  filters_json     TEXT         NULL,                -- cluster, segment, member types, product, amount range, date range, income accounts
  date_from        DATE         NOT NULL,
  date_to          DATE         NOT NULL,
  method           VARCHAR(6)   NOT NULL DEFAULT 'rate',   -- rate / pool
  rate             DECIMAL(9,4) NOT NULL DEFAULT 0,
  pool             DECIMAL(15,2) NOT NULL DEFAULT 0,
  basis            VARCHAR(10)  NOT NULL DEFAULT 'adb',    -- isc: adb / ending ; pr: income
  credit_mode      VARCHAR(14)  NOT NULL DEFAULT 'savings', -- savings / share_capital / payable
  credit_product_id INT UNSIGNED NULL,
  total_basis      DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  rounding_diff    DECIMAL(15,2) NOT NULL DEFAULT 0,
  members_count    INT          NOT NULL DEFAULT 0,
  status           VARCHAR(10)  NOT NULL DEFAULT 'draft',  -- draft / validated / approved / posted / cancelled
  memo             VARCHAR(255) NULL,
  resolution_ref   VARCHAR(120) NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  posted_at        DATETIME     NULL,
  posted_by        INT UNSIGNED NULL,
  txn_id           BIGINT UNSIGNED NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dist_batches_no (batch_no),
  KEY ix_dist_batches_period (period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dist_batch_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id        INT UNSIGNED NOT NULL,
  member_id       INT UNSIGNED NOT NULL,
  sav_account_id  INT UNSIGNED NULL,       -- the account measured (isc) / the account credited
  basis_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,   -- ADB / income earned
  current_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  computed        DECIMAL(15,2) NOT NULL DEFAULT 0,
  adjustment      DECIMAL(15,2) NOT NULL DEFAULT 0,   -- authorised offset / correction
  net_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_excluded     TINYINT(1)   NOT NULL DEFAULT 0,
  exclude_reason  VARCHAR(160) NULL,
  sav_txn_id      BIGINT UNSIGNED NULL,
  paid_txn_id     BIGINT UNSIGNED NULL,               -- cash payout voucher (payable mode)
  paid_on         DATE         NULL,
  KEY ix_dist_batch_lines_batch (batch_id),
  KEY ix_dist_batch_lines_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  7. Compliance / CDA
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS coop_officers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NULL,
  name       VARCHAR(160) NOT NULL,
  body       VARCHAR(30)  NOT NULL DEFAULT 'board',   -- board / audit / election / credit / education / ethics / mediation / management / other
  position   VARCHAR(80)  NOT NULL,
  term_start DATE         NULL,
  term_end   DATE         NULL,
  trainings  TEXT         NULL,      -- one per line: title, date, provider
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_coop_officers_body (body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cda_compliance (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_year  SMALLINT     NOT NULL,
  item         VARCHAR(120) NOT NULL,     -- CAPR, Audited FS, Social Audit, Performance Report, List of Officers, COC application, Annual Tax Incentives Report ...
  due_date     DATE         NULL,
  submitted_on DATE         NULL,
  reference_no VARCHAR(80)  NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'pending',   -- pending / submitted / accepted / late / not_applicable
  file_name    VARCHAR(120) NULL,
  original_name VARCHAR(190) NULL,
  notes        VARCHAR(255) NULL,
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME     NULL,
  KEY ix_cda_compliance_year (report_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cda_calendar (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(160) NOT NULL,
  category    VARCHAR(30)  NOT NULL DEFAULT 'cda',   -- cda / bir / lgu / internal
  due_date    DATE         NOT NULL,
  recurrence  VARCHAR(10)  NOT NULL DEFAULT 'yearly', -- none / monthly / quarterly / yearly
  remind_days INT          NOT NULL DEFAULT 30,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_cda_calendar_due (is_active, due_date),
  UNIQUE KEY uq_cda_calendar_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opening balances brought over from a previous system (one voucher per import; corrected by adjusting entries, never voided)
CREATE TABLE IF NOT EXISTS coop_imports (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind              VARCHAR(16)  NOT NULL,        -- members / savings / time_deposits / loans
  as_of             DATE         NOT NULL,
  rows_count        INT          NOT NULL DEFAULT 0,
  total             DECIMAL(15,2) NOT NULL DEFAULT 0,
  offset_account_id INT UNSIGNED NULL,
  txn_id            BIGINT UNSIGNED NULL,
  file_name         VARCHAR(190) NULL,
  summary_json      TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_coop_imports_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- which figure of the system fills which line of a regulatory report (editable when the form changes)
CREATE TABLE IF NOT EXISTS cda_report_mappings (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report     VARCHAR(30)  NOT NULL DEFAULT 'capr',
  section    VARCHAR(60)  NULL,
  field_code VARCHAR(40)  NOT NULL,
  label      VARCHAR(160) NOT NULL,
  source     VARCHAR(255) NOT NULL,     -- metric:<name> or account:<code list> or text:<value>
  sort_order INT          NOT NULL DEFAULT 0,
  KEY ix_cda_report_mappings_report (report, sort_order),
  UNIQUE KEY uq_cda_report_mappings_field (report, field_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  8. Columns on existing tables (written so the file can be run twice)
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN coop_branch_id INT UNSIGNED NULL AFTER pos_default_class_id',
  'SELECT ''users: coop_branch_id already exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'coop_branch_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN coop_cash_account_id INT UNSIGNED NULL AFTER coop_branch_id',
  'SELECT ''users: coop_cash_account_id already exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'coop_cash_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE other_names ADD COLUMN member_id INT UNSIGNED NULL AFTER employee_no',
  'SELECT ''other_names: member_id already exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'other_names' AND column_name = 'member_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE other_names ADD KEY ix_other_names_member (member_id)',
  'SELECT ''other_names: ix_other_names_member already exists''')
  FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'other_names' AND index_name = 'ix_other_names_member');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  9. Cooperative accounts (only where the code is still free) and the module default accounts
-- ---------------------------------------------------------------------

INSERT INTO accounts (code, name, account_type, category, normal_balance, is_system)
SELECT * FROM (
  SELECT '1110' AS code, 'Checks and Other Cash Items' AS name, 'Other Current Asset' AS account_type, 'Asset' AS category, 'Debit' AS normal_balance, 0 AS is_system UNION ALL
  SELECT '1210', 'Loans Receivable - Current', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '1211', 'Loans Receivable - Past Due', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '1212', 'Loans Receivable - Restructured', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '1213', 'Loans Receivable - Loans in Litigation', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '1215', 'Allowance for Probable Losses on Loans', 'Other Current Asset', 'Asset', 'Credit', 0 UNION ALL
  SELECT '1220', 'Interest Receivable on Loans', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '1230', 'Payroll Deductions Receivable', 'Other Current Asset', 'Asset', 'Debit', 0 UNION ALL
  SELECT '2300', 'Savings Deposits', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2305', 'Special Savings Deposits', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2310', 'Time Deposits', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2320', 'Accrued Interest Payable on Deposits', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2330', 'Interest on Share Capital Payable', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2340', 'Patronage Refund Payable', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2350', 'Due to Union / Federation (CETF)', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2360', 'Loan Insurance Payable', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '2370', 'Unclaimed Member Distributions', 'Other Current Liability', 'Liability', 'Credit', 0 UNION ALL
  SELECT '3010', 'Paid-up Share Capital - Common', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3020', 'Paid-up Share Capital - Preferred', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3300', 'Reserve Fund', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3310', 'Education and Training Fund', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3320', 'Community Development Fund', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3330', 'Optional Fund', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '3340', 'Undivided Net Surplus', 'Equity', 'Equity', 'Credit', 0 UNION ALL
  SELECT '4400', 'Interest Income on Loans', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4410', 'Service Fees on Loans', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4420', 'Filing Fees', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4430', 'Fines, Penalties and Surcharges', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4440', 'Membership Fees', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4450', 'Pre-termination Fees', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4460', 'Dormancy Fees', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4470', 'Other Cooperative Income', 'Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '4480', 'Recovery of Loans Written Off', 'Other Income', 'Income', 'Credit', 0 UNION ALL
  SELECT '7100', 'Interest Expense on Savings Deposits', 'Other Expense', 'Expense', 'Debit', 0 UNION ALL
  SELECT '7110', 'Interest Expense on Time Deposits', 'Other Expense', 'Expense', 'Debit', 0 UNION ALL
  SELECT '7120', 'Provision for Probable Losses on Loans', 'Expense', 'Expense', 'Debit', 0
) AS new_accounts
WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.code = new_accounts.code);

-- the Cooperative group on Chart of Accounts > Module Default Accounts
INSERT INTO default_accounts (setting_key, label, module, account_id) VALUES
  ('coop_cash',            'Teller Cash on Hand (default)',           'coop', (SELECT id FROM accounts WHERE code = '1001' LIMIT 1)),
  ('coop_coci',            'Checks and Other Cash Items (clearing)',  'coop', (SELECT id FROM accounts WHERE code = '1110' LIMIT 1)),
  ('loans_receivable',     'Loans Receivable - Current',              'coop', (SELECT id FROM accounts WHERE code = '1210' LIMIT 1)),
  ('loans_past_due',       'Loans Receivable - Past Due',             'coop', (SELECT id FROM accounts WHERE code = '1211' LIMIT 1)),
  ('loans_restructured',   'Loans Receivable - Restructured',         'coop', (SELECT id FROM accounts WHERE code = '1212' LIMIT 1)),
  ('loan_loss_allowance',  'Allowance for Probable Losses on Loans',  'coop', (SELECT id FROM accounts WHERE code = '1215' LIMIT 1)),
  ('loan_loss_provision',  'Provision for Probable Losses on Loans',  'coop', (SELECT id FROM accounts WHERE code = '7120' LIMIT 1)),
  ('loan_interest_receivable', 'Interest Receivable on Loans',        'coop', (SELECT id FROM accounts WHERE code = '1220' LIMIT 1)),
  ('loan_interest_income', 'Interest Income on Loans',                'coop', (SELECT id FROM accounts WHERE code = '4400' LIMIT 1)),
  ('loan_penalty_income',  'Fines, Penalties and Surcharges',         'coop', (SELECT id FROM accounts WHERE code = '4430' LIMIT 1)),
  ('loan_service_fee',     'Service Fees on Loans',                   'coop', (SELECT id FROM accounts WHERE code = '4410' LIMIT 1)),
  ('loan_recovery_income', 'Recovery of Loans Written Off',           'coop', (SELECT id FROM accounts WHERE code = '4480' LIMIT 1)),
  ('savings_deposits',     'Savings Deposits (default liability)',    'coop', (SELECT id FROM accounts WHERE code = '2300' LIMIT 1)),
  ('time_deposits',        'Time Deposits',                           'coop', (SELECT id FROM accounts WHERE code = '2310' LIMIT 1)),
  ('deposit_interest_payable', 'Accrued Interest Payable on Deposits','coop', (SELECT id FROM accounts WHERE code = '2320' LIMIT 1)),
  ('savings_interest_expense', 'Interest Expense on Savings Deposits','coop', (SELECT id FROM accounts WHERE code = '7100' LIMIT 1)),
  ('td_interest_expense',  'Interest Expense on Time Deposits',       'coop', (SELECT id FROM accounts WHERE code = '7110' LIMIT 1)),
  ('share_capital',        'Paid-up Share Capital - Common',          'coop', (SELECT id FROM accounts WHERE code = '3010' LIMIT 1)),
  ('isc_payable',          'Interest on Share Capital Payable',       'coop', (SELECT id FROM accounts WHERE code = '2330' LIMIT 1)),
  ('pr_payable',           'Patronage Refund Payable',                'coop', (SELECT id FROM accounts WHERE code = '2340' LIMIT 1)),
  ('net_surplus',          'Undivided Net Surplus',                   'coop', (SELECT id FROM accounts WHERE code = '3340' LIMIT 1)),
  ('reserve_fund',         'Reserve Fund',                            'coop', (SELECT id FROM accounts WHERE code = '3300' LIMIT 1)),
  ('education_fund',       'Education and Training Fund',             'coop', (SELECT id FROM accounts WHERE code = '3310' LIMIT 1)),
  ('education_fund_apex',  'Due to Union / Federation (CETF)',        'coop', (SELECT id FROM accounts WHERE code = '2350' LIMIT 1)),
  ('cdf_fund',             'Community Development Fund',              'coop', (SELECT id FROM accounts WHERE code = '3320' LIMIT 1)),
  ('optional_fund',        'Optional Fund',                           'coop', (SELECT id FROM accounts WHERE code = '3330' LIMIT 1)),
  ('membership_fee_income','Membership Fees',                         'coop', (SELECT id FROM accounts WHERE code = '4440' LIMIT 1)),
  ('coop_other_income',    'Other Cooperative Income',                'coop', (SELECT id FROM accounts WHERE code = '4470' LIMIT 1)),
  ('preterm_fee_income',   'Pre-termination Fees',                    'coop', (SELECT id FROM accounts WHERE code = '4450' LIMIT 1)),
  ('dormancy_fee_income',  'Dormancy Fees',                           'coop', (SELECT id FROM accounts WHERE code = '4460' LIMIT 1)),
  ('coop_insurance_payable','Loan Insurance Payable',                 'coop', (SELECT id FROM accounts WHERE code = '2360' LIMIT 1)),
  ('unclaimed_dist',       'Unclaimed Member Distributions',          'coop', (SELECT id FROM accounts WHERE code = '2370' LIMIT 1))
ON DUPLICATE KEY UPDATE account_id = COALESCE(default_accounts.account_id, VALUES(account_id)), label = VALUES(label), module = VALUES(module);

-- the member control accounts keep a subsidiary ledger by member (Reports > Subsidiary Ledger, Schedule of Accounts)
UPDATE accounts SET with_sl = 1
 WHERE with_sl = 0 AND id IN (SELECT account_id FROM default_accounts WHERE account_id IS NOT NULL AND setting_key IN
   ('loans_receivable', 'loans_past_due', 'loans_restructured', 'loan_interest_receivable', 'savings_deposits', 'time_deposits',
    'share_capital', 'isc_payable', 'pr_payable', 'unclaimed_dist'));

-- ---------------------------------------------------------------------
-- 10. Modules and pages
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES
  ('coop',   'Cooperative',             'people-fill',        12),
  ('sav',    'Savings & Deposits',      'piggy-bank',         13),
  ('loan',   'Loans',                   'cash-coin',          14),
  ('teller', 'Teller',                  'safe2',              15),
  ('dist',   'Year-end Distribution',   'pie-chart',          16),
  ('cda',    'CDA Compliance',          'clipboard2-check',   17);

SET @m_coop   = (SELECT id FROM modules WHERE code = 'coop'   LIMIT 1);
SET @m_sav    = (SELECT id FROM modules WHERE code = 'sav'    LIMIT 1);
SET @m_loan   = (SELECT id FROM modules WHERE code = 'loan'   LIMIT 1);
SET @m_teller = (SELECT id FROM modules WHERE code = 'teller' LIMIT 1);
SET @m_dist   = (SELECT id FROM modules WHERE code = 'dist'   LIMIT 1);
SET @m_cda    = (SELECT id FROM modules WHERE code = 'cda'    LIMIT 1);
SET @psort = (SELECT COALESCE(MAX(sort_order), 2000) FROM pages);

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  -- Cooperative
  ('coop.members',          @m_coop, 'Member Center',                         'center',      'Transactions', @psort + 10, 1),
  ('coop.member',           @m_coop, 'Member Information',                    'transaction', 'Transactions', @psort + 11, 0),
  ('coop.member_statement', @m_coop, 'Member Statement of Account',           'transaction', 'Transactions', @psort + 12, 1),
  ('coop.vouchers',         @m_coop, 'Cooperative Vouchers',                  'transaction', 'Transactions', @psort + 13, 1),
  ('coop.import',           @m_coop, 'Opening Balances Import',               'transaction', 'Transactions', @psort + 14, 1),
  ('coop.profile',          @m_coop, 'Cooperative Profile & Policies',        'setup',       'Setup',        @psort + 20, 1),
  ('coop.branches',         @m_coop, 'Branches',                              'setup',       'Setup',        @psort + 21, 1),
  ('coop.lists',            @m_coop, 'Member Types, Clusters & Other Lists',  'setup',       'Setup',        @psort + 22, 1),
  ('coop.passbooks',        @m_coop, 'Passbook Types',                        'setup',       'Setup',        @psort + 23, 1),
  ('coop.numbering',        @m_coop, 'Document Numbering',                    'setup',       'Setup',        @psort + 24, 1),
  ('coop.templates',        @m_coop, 'Document Templates',                    'setup',       'Setup',        @psort + 25, 1),
  ('rpt.coop_members',      @m_coop, 'Member Master List',                    'report',      'Reports',      @psort + 30, 1),
  ('rpt.coop_member_movement', @m_coop, 'Membership Movement',                'report',      'Reports',      @psort + 31, 1),
  ('rpt.coop_share_capital', @m_coop, 'Share Capital Balances',               'report',      'Reports',      @psort + 32, 1),
  ('rpt.coop_cbu',          @m_coop, 'Capital Build-up',                      'report',      'Reports',      @psort + 33, 1),
  ('rpt.coop_sfc',          @m_coop, 'Statement of Financial Condition',      'report',      'Reports',      @psort + 34, 1),
  ('rpt.coop_sop',          @m_coop, 'Statement of Operations',               'report',      'Reports',      @psort + 35, 1),
  ('rpt.coop_sce',          @m_coop, 'Statement of Changes in Equity',        'report',      'Reports',      @psort + 36, 1),
  ('rpt.coop_statutory_funds', @m_coop, 'Statutory Funds',                    'report',      'Reports',      @psort + 37, 1),
  ('rpt.coop_sl_gl',        @m_coop, 'Subsidiary vs General Ledger Reconciliation', 'report','Reports',      @psort + 38, 1),
  ('rpt.coop_document_prints', @m_coop, 'Document Print History',             'report',      'Reports',      @psort + 39, 1),
  -- Savings & Deposits
  ('sav.accounts',          @m_sav,  'Savings Account Center',                'center',      'Transactions', @psort + 40, 1),
  ('sav.transact',          @m_sav,  'Savings Deposit / Withdrawal',          'transaction', 'Transactions', @psort + 41, 1),
  ('sav.transfer',          @m_sav,  'Transfer Between Accounts',             'transaction', 'Transactions', @psort + 42, 1),
  ('sav.interest',          @m_sav,  'Post Savings Interest',                 'transaction', 'Transactions', @psort + 43, 1),
  ('sav.passbook',          @m_sav,  'Passbook Printing',                     'transaction', 'Transactions', @psort + 44, 1),
  ('sav.td',                @m_sav,  'Time Deposits',                         'transaction', 'Transactions', @psort + 45, 1),
  ('sav.batch',             @m_sav,  'Batch Deductions (Payroll / CBU)',      'transaction', 'Transactions', @psort + 46, 1),
  ('sav.clearing',          @m_sav,  'Check Clearing',                        'transaction', 'Transactions', @psort + 47, 1),
  ('sav.products',          @m_sav,  'Savings & Deposit Products',            'setup',       'Setup',        @psort + 50, 1),
  ('sav.txn_codes',         @m_sav,  'Savings Transaction Codes',             'setup',       'Setup',        @psort + 51, 1),
  ('rpt.sav_list',          @m_sav,  'Savings List',                          'report',      'Reports',      @psort + 60, 1),
  ('rpt.sav_journal',       @m_sav,  'Savings Journal',                       'report',      'Reports',      @psort + 61, 1),
  ('rpt.sav_balances',      @m_sav,  'Savings Account Balances',              'report',      'Reports',      @psort + 62, 1),
  ('rpt.sav_schedule',      @m_sav,  'Schedule of Savings Accounts',          'report',      'Reports',      @psort + 63, 1),
  ('rpt.sav_dormant',       @m_sav,  'Dormant Accounts',                      'report',      'Reports',      @psort + 64, 1),
  ('rpt.sav_interest',      @m_sav,  'Interest Posting Register',             'report',      'Reports',      @psort + 65, 1),
  ('rpt.td_list',           @m_sav,  'Time Deposits by Product',              'report',      'Reports',      @psort + 66, 1),
  ('rpt.td_maturity',       @m_sav,  'Time Deposit Maturity Schedule',        'report',      'Reports',      @psort + 67, 1),
  ('rpt.td_accrued',        @m_sav,  'Time Deposit Accrued Interest',         'report',      'Reports',      @psort + 68, 1),
  ('rpt.sav_statement',     @m_sav,  'Savings Account Statement',             'report',      'Reports',      @psort + 69, 0),
  -- Loans
  ('loan.applications',     @m_loan, 'Loan Application Center',               'center',      'Transactions', @psort + 70, 1),
  ('loan.application',      @m_loan, 'Loan Application Form',                 'transaction', 'Transactions', @psort + 71, 0),
  ('loan.ci',               @m_loan, 'Credit Investigation',                  'transaction', 'Transactions', @psort + 72, 0),
  ('loan.release',          @m_loan, 'Loan Release',                          'transaction', 'Transactions', @psort + 73, 1),
  ('loan.collection',       @m_loan, 'Loan Collection',                       'transaction', 'Transactions', @psort + 74, 1),
  ('loan.accounts',         @m_loan, 'Loan Accounts Center',                  'center',      'Transactions', @psort + 75, 1),
  ('loan.adjustments',      @m_loan, 'Loan Adjustments',                      'transaction', 'Transactions', @psort + 76, 1),
  ('loan.waivers',          @m_loan, 'Interest / Penalty Waiving',            'transaction', 'Transactions', @psort + 77, 1),
  ('loan.restructure',      @m_loan, 'Restructure / Renew / Write Off',       'transaction', 'Transactions', @psort + 78, 1),
  ('loan.docs',             @m_loan, 'Loan Documents',                        'transaction', 'Transactions', @psort + 79, 0),
  ('loan.penalties',        @m_loan, 'Post Loan Penalties & Interest Accrual','transaction', 'Transactions', @psort + 80, 1),
  ('loan.products',         @m_loan, 'Loan Products',                         'setup',       'Setup',        @psort + 85, 1),
  ('loan.charges',          @m_loan, 'Loan Charges',                          'setup',       'Setup',        @psort + 86, 1),
  ('loan.approval_matrix',  @m_loan, 'Loan Approval Matrix',                  'setup',       'Setup',        @psort + 87, 1),
  ('rpt.loan_products',     @m_loan, 'Loan Product List',                     'report',      'Reports',      @psort + 90, 1),
  ('rpt.loan_schedule_recv',@m_loan, 'Schedule of Loans Receivable',          'report',      'Reports',      @psort + 91, 1),
  ('rpt.loan_releases',     @m_loan, 'Loan Releases',                         'report',      'Loans Summary',@psort + 92, 1),
  ('rpt.loan_due_vs_coll',  @m_loan, 'Loan Due vs. Collection',               'report',      'Loans Summary',@psort + 93, 1),
  ('rpt.loan_due',          @m_loan, 'Loans Due for Collection',              'report',      'Loans Summary',@psort + 94, 1),
  ('rpt.loan_collections',  @m_loan, 'Loan Collection List',                  'report',      'Loans Summary',@psort + 95, 1),
  ('rpt.loan_no_payments',  @m_loan, 'Loans With No Payments',                'report',      'Loans Summary',@psort + 96, 1),
  ('rpt.loan_soa',          @m_loan, 'Loan Statement of Account',             'report',      'Loans Summary',@psort + 97, 1),
  ('rpt.loan_aging_maturity', @m_loan, 'Aging of Loans by Maturity',          'report',      'Loans Aging',  @psort + 98, 1),
  ('rpt.loan_aging_par',    @m_loan, 'Aging of Loans at PAR',                 'report',      'Loans Aging',  @psort + 99, 1),
  ('rpt.loan_aging_amort',  @m_loan, 'Aging of Loans by Amortization',        'report',      'Loans Aging',  @psort + 100, 1),
  ('rpt.loan_portfolio',    @m_loan, 'Loan Portfolio Analysis',               'report',      'Loans Aging',  @psort + 101, 1),
  ('rpt.loan_comaker',      @m_loan, 'Co-maker / Guarantor Exposure',         'report',      'Reports',      @psort + 102, 1),
  ('rpt.loan_collateral',   @m_loan, 'Collateral Register',                   'report',      'Reports',      @psort + 103, 1),
  ('rpt.loan_gl_compare',   @m_loan, 'Schedule of Loans vs GL',               'report',      'Reports',      @psort + 104, 1),
  ('rpt.loan_waivers',      @m_loan, 'Waivers and Adjustments',               'report',      'Reports',      @psort + 105, 1),
  ('rpt.loan_writeoffs',    @m_loan, 'Written-off Loans and Recoveries',      'report',      'Reports',      @psort + 106, 1),
  -- Teller
  ('teller.shift',          @m_teller, 'Teller Shift & Cash Count',           'transaction', 'Transactions', @psort + 110, 1),
  ('teller.or',             @m_teller, 'Official Receipt (Member Collection)','transaction', 'Transactions', @psort + 111, 1),
  ('teller.disburse',       @m_teller, 'Member Disbursement (Cash Voucher)',  'transaction', 'Transactions', @psort + 112, 1),
  ('teller.receipts',       @m_teller, 'Official Receipt List',               'center',      'Transactions', @psort + 113, 1),
  ('rpt.teller_journal',    @m_teller, 'Teller Transaction Journal',          'report',      'Reports',      @psort + 120, 1),
  ('rpt.teller_daily',      @m_teller, 'Daily Collection & Disbursement Summary', 'report',  'Reports',      @psort + 121, 1),
  ('rpt.teller_over_short', @m_teller, 'Teller Over / Short',                 'report',      'Reports',      @psort + 122, 1),
  ('rpt.teller_or_list',    @m_teller, 'Official Receipt Register',           'report',      'Reports',      @psort + 123, 1),
  ('rpt.teller_cash_position', @m_teller, 'Cash Position',                    'report',      'Reports',      @psort + 124, 1),
  -- Year-end distribution
  ('dist.surplus',          @m_dist, 'Net Surplus Allocation',                'transaction', 'Transactions', @psort + 130, 1),
  ('dist.isc',              @m_dist, 'Interest on Share Capital',             'transaction', 'Transactions', @psort + 131, 1),
  ('dist.pr',               @m_dist, 'Patronage Refund',                      'transaction', 'Transactions', @psort + 132, 1),
  ('dist.batches',          @m_dist, 'Distribution Batches',                  'center',      'Transactions', @psort + 133, 1),
  ('rpt.dist_register',     @m_dist, 'Distribution Register',                 'report',      'Reports',      @psort + 140, 1),
  ('rpt.dist_unclaimed',    @m_dist, 'Unclaimed Distributions',               'report',      'Reports',      @psort + 141, 1),
  ('rpt.dist_summary',      @m_dist, 'Net Surplus Allocation Summary',        'report',      'Reports',      @psort + 142, 1),
  -- CDA
  ('cda.officers',          @m_cda,  'Officers, Committees & Trainings',      'setup',       'Setup',        @psort + 150, 1),
  ('cda.compliance',        @m_cda,  'Compliance Tracking',                   'transaction', 'Transactions', @psort + 151, 1),
  ('cda.calendar',          @m_cda,  'Regulatory Calendar',                   'transaction', 'Transactions', @psort + 152, 1),
  ('cda.capr',              @m_cda,  'CAPR Data Preparation & Export',        'transaction', 'Transactions', @psort + 153, 1),
  ('cda.mappings',          @m_cda,  'Report Field Mappings',                 'setup',       'Setup',        @psort + 154, 1),
  ('rpt.cda_officers',      @m_cda,  'List of Officers and Committees',       'report',      'Reports',      @psort + 160, 1),
  ('rpt.cda_compliance',    @m_cda,  'Compliance Status',                     'report',      'Reports',      @psort + 161, 1);

-- ---------------------------------------------------------------------
-- 11. Roles and their access
-- ---------------------------------------------------------------------

INSERT IGNORE INTO roles (name, description, is_admin, is_system, all_sites) VALUES
  ('General Manager',    'Opens every cooperative, savings, loan, teller, distribution and compliance page, approves loans and transactions, and sees every report.', 0, 1, 1),
  ('Bookkeeper',         'Records cooperative vouchers, posts interest and penalties, runs the subsidiary ledgers and financial reports.', 0, 1, 1),
  ('Loan Officer',       'Loan applications, credit investigation, loan release, collection, loan accounts and loan reports.', 0, 1, 1),
  ('Credit Committee',   'Reviews and approves loan applications and waivers; views loan reports.', 0, 1, 1),
  ('Teller',             'Teller shift, official receipts, savings deposits and withdrawals, loan collection and passbook printing.', 0, 1, 1),
  ('Membership Officer', 'Member records, member statements, savings account opening and the member reports.', 0, 1, 1),
  ('Collector',          'Loan collection and the collection, due and aging reports.', 0, 1, 1),
  ('Branch Manager',     'Everything of the branch: approves savings accounts, withdrawals, loans and waivers, closes teller shifts, and sees the branch reports.', 0, 1, 1);

-- helper: grant a set of pages to a role (allowed + flags)
-- General Manager: everything in the six modules + home, with every right
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 1, 1, 1 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'General Manager' AND (m.code IN ('coop','sav','loan','teller','dist','cda','home') OR p.page_key IN ('coa.accounts','coa.journal','coa.default_accounts','rpt.trial_balance','rpt.balance_sheet','rpt.income_statement','rpt.general_ledger','rpt.subsidiary_ledger','rpt.schedule_of_accounts','rpt.journal','rpt.cash_flow','rpt.income_statement_class','rpt.balance_sheet_class'));

-- Bookkeeper
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_type = 'report', 0, 1), IF(p.page_type = 'report', 0, 1), 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Bookkeeper' AND (m.code IN ('coop','sav','loan','dist','home') OR p.page_key IN ('coa.accounts','coa.journal','coa.default_accounts','rpt.trial_balance','rpt.balance_sheet','rpt.income_statement','rpt.general_ledger','rpt.subsidiary_ledger','rpt.schedule_of_accounts','rpt.journal','rpt.cash_flow','rpt.teller_journal','rpt.teller_daily','rpt.teller_over_short','rpt.teller_or_list','rpt.teller_cash_position','teller.receipts'))
   AND p.page_key NOT IN ('coop.profile','coop.branches','coop.numbering','sav.products','loan.products','loan.charges','loan.approval_matrix');

-- Loan Officer
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_type = 'report', 0, 1), IF(p.page_type = 'report', 0, 1), 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Loan Officer' AND (m.code IN ('loan','home') OR p.page_key IN ('coop.members','coop.member','coop.member_statement','sav.accounts','rpt.coop_members'))
   AND p.page_key NOT IN ('loan.products','loan.charges','loan.approval_matrix','loan.waivers','loan.restructure');
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 0, 1, 0 FROM roles r, pages p WHERE r.name = 'Loan Officer' AND p.page_key IN ('loan.waivers','loan.restructure');

-- Credit Committee: approves applications and waivers, reads loan reports
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 1 FROM roles r, pages p WHERE r.name = 'Credit Committee' AND p.page_key IN ('loan.applications','loan.application','loan.ci','loan.waivers','loan.accounts','loan.docs','home.dashboard','home.reports');
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id WHERE r.name = 'Credit Committee' AND m.code = 'loan' AND p.page_type = 'report';

-- Teller
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 0, 0, 1, 0 FROM roles r, pages p WHERE r.name = 'Teller' AND p.page_key IN ('home.dashboard','teller.shift','teller.or','teller.disburse','teller.receipts','sav.transact','sav.passbook','sav.clearing','loan.collection','sav.accounts','sav.td','coop.members','rpt.teller_journal','rpt.teller_daily','rpt.teller_or_list','rpt.sav_statement');

-- Membership Officer
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_type = 'report', 0, 1), IF(p.page_type = 'report', 0, 1), 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Membership Officer' AND (m.code IN ('coop','home') OR p.page_key IN ('sav.accounts','sav.passbook','rpt.sav_list','rpt.sav_balances','rpt.sav_statement'))
   AND p.page_key NOT IN ('coop.profile','coop.branches','coop.numbering','coop.vouchers','coop.import','coop.templates');

-- Collector
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 0, 0, 1, 0 FROM roles r, pages p WHERE r.name = 'Collector' AND p.page_key IN ('home.dashboard','teller.shift','teller.or','loan.collection','loan.accounts','rpt.loan_due','rpt.loan_collections','rpt.loan_due_vs_coll','rpt.loan_aging_maturity','rpt.loan_aging_par','rpt.loan_aging_amort','rpt.loan_no_payments','rpt.loan_soa','rpt.teller_journal');

-- Branch Manager: everything in the six modules with approve, except the set-up pages
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 1, 1, 1 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Branch Manager' AND m.code IN ('coop','sav','loan','teller','dist','cda','home') AND p.page_type <> 'setup';

-- the Administrator role already opens everything; the Auditor reads everything (view + print)
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Auditor' AND m.code IN ('coop','sav','loan','teller','dist','cda') AND p.page_key NOT IN ('teller.shift','teller.or','teller.disburse');

-- the Accountant reads the cooperative reports and vouchers and posts interest / penalties
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, IF(p.page_key IN ('coop.vouchers','sav.interest','loan.penalties','dist.surplus','dist.isc','dist.pr','dist.batches','coop.import'), 1, 0), 0, 0, 1, 0 FROM roles r, pages p JOIN modules m ON m.id = p.module_id
 WHERE r.name = 'Accountant' AND m.code IN ('coop','sav','loan','teller','dist','cda') AND (p.page_type = 'report' OR p.page_key IN ('coop.vouchers','sav.interest','loan.penalties','dist.surplus','dist.isc','dist.pr','dist.batches','coop.import','coop.members','sav.accounts','loan.accounts','teller.receipts'));

INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'max_amount', '' FROM roles WHERE name IN ('General Manager', 'Branch Manager');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'closed_period_changes', '0' FROM roles WHERE name IN ('General Manager', 'Branch Manager', 'Bookkeeper', 'Loan Officer', 'Teller', 'Membership Officer', 'Collector', 'Credit Committee');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_waive', '1' FROM roles WHERE name IN ('General Manager', 'Branch Manager', 'Credit Committee');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_backdate', '1' FROM roles WHERE name IN ('General Manager', 'Branch Manager', 'Bookkeeper');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_override_allocation', '1' FROM roles WHERE name IN ('General Manager', 'Branch Manager', 'Loan Officer');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_all_branches', '1' FROM roles WHERE name IN ('General Manager', 'Bookkeeper', 'Credit Committee');
-- amount limits without a supervisor (blank = no limit; a role with no row needs approval for any amount)
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_max_withdrawal', '50000' FROM roles WHERE name IN ('Teller', 'Bookkeeper');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_max_withdrawal', '' FROM roles WHERE name IN ('General Manager', 'Branch Manager');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_max_release', '100000' FROM roles WHERE name IN ('Loan Officer', 'Teller');
INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT id, 'coop_max_release', '' FROM roles WHERE name IN ('General Manager', 'Branch Manager');

-- ---------------------------------------------------------------------
-- 12. Sequences, transaction codes, member types, passbook type, settings
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES
  ('member',          '',      100001, 6),
  ('sav_account',     '',      1, 6),
  ('loan_application','LA-',   1, 6),
  ('loan',            '',      1, 7),
  ('pn',              '',      1, 5),
  ('coop_or',         '',      1, 6),
  ('coop_cv',         'MCV-',  1, 6),
  ('coop_jv',         'CJV-',  1, 6),
  ('loan_release',    'LR-',   1, 6),
  ('td_certificate',  'TD-',   1, 6),
  ('share_certificate','SC-',  1, 6),
  ('balance_certificate','BC-', 1, 6),
  ('sav_batch',       'BAT-',  1, 5),
  ('dist_batch',      'DIST-', 1, 5),
  ('passbook',        '',      1, 6);

INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system) VALUES
  ('CSD',  'Cash Savings Deposit',               'user', NULL, 'credit', 0, 0, 0, NULL, 1),
  ('CSW',  'Cash Savings Withdrawal',            'user', NULL, 'debit',  0, 0, 0, NULL, 1),
  ('CTD',  'Cash Time Deposit',                  'user', NULL, 'credit', 0, 0, 0, NULL, 1),
  ('CTDW', 'Cash Time Deposit Withdrawal',       'user', NULL, 'debit',  0, 0, 0, NULL, 1),
  ('CK3',  'Local Check Deposit 3 Days Clearing','gl',   (SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'credit', 1, 3, 0, NULL, 0),
  ('CK4',  'Local Check Deposit 4 Days Clearing','gl',   (SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'credit', 1, 4, 0, NULL, 0),
  ('CK5',  'Local Check Deposit 5 Days Clearing','gl',   (SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'credit', 1, 5, 0, NULL, 0),
  ('CK7',  'Regional Check Deposit 7 Days Clearing','gl',(SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'credit', 1, 7, 0, NULL, 0),
  ('CKTD', 'Check Time Deposit',                 'gl',   (SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'credit', 1, 5, 0, NULL, 0),
  ('INT',  'Interest Credited',                  'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('WTX',  'Withholding Tax on Interest',        'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('DF',   'Dormancy Fee',                       'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('CF',   'Closing Fee',                        'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('PTF',  'Pre-termination Fee',                'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('LP',   'Loan Proceeds Credited',             'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('LC',   'Loan Charge Credited to Account',    'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('LPY',  'Loan Payment Debited from Account',  'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('TRI',  'Transfer In',                        'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('TRO',  'Transfer Out',                       'gl',   NULL, 'debit',  0, 0, 0, NULL, 1),
  ('ISC',  'Interest on Share Capital',          'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('PR',   'Patronage Refund',                   'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('PDD',  'Payroll Deduction Deposit',          'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('TDM',  'Time Deposit Maturity / Renewal',    'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('OB',   'Opening Balance',                    'gl',   NULL, 'credit', 0, 0, 0, NULL, 1),
  ('RTC',  'Returned Check',                     'gl',   (SELECT id FROM accounts WHERE code = '1110' LIMIT 1), 'debit', 0, 0, 1, 'CK4', 0),
  ('CM',   'Credit Memo (Adjustment)',           'gl',   NULL, 'credit', 0, 0, 1, NULL, 0),
  ('DM',   'Debit Memo (Adjustment)',            'gl',   NULL, 'debit',  0, 0, 1, NULL, 0);

INSERT IGNORE INTO member_types (code, name, member_kind, is_regular, min_share_capital, sort_order) VALUES
  ('REG',   'Regular',                 'individual',    1, 0, 1),
  ('ASI',   'Associate - Individual',  'individual',    0, 0, 2),
  ('ASJ',   'Associate - Joint',       'joint',         0, 0, 3),
  ('ASN',   'Associate - Institutional','institutional',0, 0, 4),
  ('AFT',   'Aflatoun (youth)',        'individual',    0, 0, 5),
  ('AFE',   'Aflateen (youth)',        'individual',    0, 0, 6);

INSERT IGNORE INTO passbook_types (name, description, columns_json) VALUES
  ('Regular Passbook', 'Regular savings passbook', '{"date":200,"code":1400,"debit":2600,"credit":4400,"balance":6200,"teller":8000}'),
  ('Loan Passbook',    'Loan passbook',             '{"date":200,"code":1400,"debit":2600,"credit":4400,"balance":6200,"teller":8000}');

INSERT IGNORE INTO coop_lists (list_key, name, sort_order) VALUES
  ('loan_purpose', 'Business capital', 1), ('loan_purpose', 'Education', 2), ('loan_purpose', 'Medical / emergency', 3),
  ('loan_purpose', 'House repair / construction', 4), ('loan_purpose', 'Appliance / vehicle', 5), ('loan_purpose', 'Agricultural', 6),
  ('loan_purpose', 'Loan consolidation', 7), ('loan_purpose', 'Other', 9),
  ('collateral_type', 'Real estate (TCT / tax declaration)', 1), ('collateral_type', 'Vehicle (OR / CR)', 2), ('collateral_type', 'Deposit hold-out', 3),
  ('collateral_type', 'Share capital hold-out', 4), ('collateral_type', 'Appliance / chattel', 5), ('collateral_type', 'Other', 9),
  ('account_class', 'Regular', 1), ('account_class', 'Board of Director', 2), ('account_class', 'Officer', 3), ('account_class', 'Employee', 4),
  ('doc_type', 'Government ID', 1), ('doc_type', 'Photo', 2), ('doc_type', 'Signature card', 3), ('doc_type', 'Membership application form', 4),
  ('doc_type', 'Proof of address', 5), ('doc_type', 'PMES certificate', 6), ('doc_type', 'Board resolution (institutional)', 7), ('doc_type', 'Other', 9),
  ('id_type', 'PhilSys ID', 1), ('id_type', 'Driver''s license', 2), ('id_type', 'Passport', 3), ('id_type', 'SSS / GSIS', 4), ('id_type', 'UMID', 5), ('id_type', 'Voter''s ID', 6), ('id_type', 'Other', 9);

INSERT IGNORE INTO cda_calendar (title, category, due_date, recurrence, remind_days, notes) VALUES
  ('CAPR and audited financial statements (within 120 days after fiscal year end)', 'cda', CONCAT(YEAR(CURDATE()), '-04-30'), 'yearly', 45, 'Confirm the current CDA deadline for your cooperative''s fiscal year'),
  ('Certificate of Compliance application', 'cda', CONCAT(YEAR(CURDATE()), '-04-30'), 'yearly', 45, 'Submitted with the annual reports'),
  ('Annual Tax Incentives Report / Annual Benefits Report', 'bir', CONCAT(YEAR(CURDATE()), '-04-30'), 'yearly', 45, 'Where applicable to the cooperative'),
  ('General Assembly', 'internal', CONCAT(YEAR(CURDATE()), '-03-31'), 'yearly', 60, 'Set the date fixed by the bylaws');

INSERT IGNORE INTO cda_report_mappings (report, section, field_code, label, source, sort_order) VALUES
  ('capr', 'General',    'registered_name',   'Registered name',            'setting:registered_name', -3),
  ('capr', 'General',    'cda_reg_no',        'CDA registration no.',       'setting:cda_reg_no', -2),
  ('capr', 'General',    'officers',          'Officers and committee members', 'metric:officers_count', -1),
  ('capr', 'Membership', 'members_total',     'Total members',              'metric:members_total', 0),
  ('capr', 'Membership', 'members_regular',   'Regular members',            'metric:members_regular', 1),
  ('capr', 'Membership', 'members_associate', 'Associate members',          'metric:members_associate', 2),
  ('capr', 'Membership', 'members_new',       'New members during the year','metric:members_new', 3),
  ('capr', 'Membership', 'members_left',      'Members who left',           'metric:members_separated', 4),
  ('capr', 'Membership', 'members_male',      'Male members',               'metric:members_male', 5),
  ('capr', 'Membership', 'members_female',    'Female members',             'metric:members_female', 6),
  ('capr', 'Capital',    'share_capital',     'Paid-up share capital',      'metric:paid_up_capital', 10),
  ('capr', 'Capital',    'subscribed_capital','Subscribed share capital',   'metric:subscribed_capital', 11),
  ('capr', 'Deposits',   'savings_deposits',  'Savings deposits',           'metric:savings_deposits', 20),
  ('capr', 'Deposits',   'time_deposits',     'Time deposits',              'metric:time_deposits', 21),
  ('capr', 'Loans',      'loans_released_n',  'Number of loans released during the year', 'metric:loans_released_count', 29),
  ('capr', 'Loans',      'loans_released',    'Loans released during the year', 'metric:loans_released_amount', 30),
  ('capr', 'Loans',      'loans_outstanding', 'Loans receivable outstanding',   'metric:loans_outstanding', 31),
  ('capr', 'Loans',      'loans_past_due',    'Past due loans',                 'metric:loans_past_due', 32),
  ('capr', 'Loans',      'par_ratio',         'Portfolio at risk (%)',          'metric:par_ratio', 33),
  ('capr', 'Loans',      'borrowers',         'Number of borrowers',            'metric:loans_borrowers', 34),
  ('capr', 'Results',    'total_assets',      'Total assets',                   'metric:total_assets', 40),
  ('capr', 'Results',    'total_liabilities', 'Total liabilities',              'metric:total_liabilities', 41),
  ('capr', 'Results',    'total_equity',      'Total members'' equity',         'metric:total_equity', 42),
  ('capr', 'Results',    'revenue',           'Total revenue',                  'metric:gross_revenue', 43),
  ('capr', 'Results',    'expenses',          'Total expenses',                 'metric:total_expenses', 44),
  ('capr', 'Results',    'net_surplus',       'Net surplus',                    'metric:net_surplus', 45),
  ('capr', 'Statutory',  'reserve_fund',      'Reserve fund',                   'metric:reserve_fund', 50),
  ('capr', 'Statutory',  'educ_fund',         'Education and training fund',    'metric:education_fund', 51),
  ('capr', 'Statutory',  'cdf_fund',          'Community development fund',     'metric:cdf_fund', 52),
  ('capr', 'Statutory',  'optional_fund',     'Optional fund',                  'metric:optional_fund', 53),
  ('capr', 'Distribution','isc_declared',     'Interest on share capital declared', 'metric:isc_distributed', 60),
  ('capr', 'Distribution','pr_declared',      'Patronage refund declared',      'metric:pr_distributed', 61);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('coop_registered_name', ''), ('coop_cda_reg_no', ''), ('coop_type', 'Multi-Purpose'), ('coop_category', ''),
  ('coop_fiscal_year_end', '12-31'), ('coop_signatory_gm', ''), ('coop_signatory_chair', ''), ('coop_signatory_treasurer', ''),
  ('coop_signatory_loans', ''), ('coop_signatory_bookkeeper', ''), ('coop_division_name', 'Savings & Credit Division'),
  ('coop_min_share_capital', '0'), ('coop_default_par_value', '100'), ('coop_membership_fee', '0'),
  ('coop_backdate_days', '0'), ('coop_large_txn_amount', '500000'), ('coop_or_require_shift', '1'),
  ('coop_withdrawal_approval_above', '0'), ('coop_dormancy_alert_days', '30'), ('coop_td_maturity_alert_days', '15'),
  ('coop_loan_due_alert_days', '7'), ('coop_doc_expiry_alert_days', '30'), ('coop_allocation_order', 'penalty,interest,principal'),
  ('coop_pn_text', ''), ('coop_disclosure_text', ''), ('coop_comaker_text', ''), ('coop_sav_account_format', '{BRANCH}{MEMBER}{PRODUCT}1'),
  ('coop_loan_account_format', '{BRANCH}-{PRODUCT}-{SEQ}'), ('coop_isc_basis', 'adb'), ('coop_pr_basis', 'income'),
  ('coop_reserve_pct', '10'), ('coop_educ_pct', '10'), ('coop_educ_apex_pct', '50'), ('coop_cdf_pct', '3'), ('coop_optional_pct', '7'),
  ('coop_passbook_header', '1'), ('coop_penalty_on_collection', '1'), ('coop_interest_accrual_loans', '0'),
  ('coop_over_short_tolerance', '0');

-- ---------------------------------------------------------------------
-- 13. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Cooperative Management modules',
        'Migration 2026_11_01: Cooperative, Savings & Deposits, Loans, Teller, Year-end Distribution and CDA Compliance modules added with their tables, accounts, roles and settings');

-- Done. Next steps in the application:
--   Cooperative > Cooperative Profile & Policies: the registered name, CDA registration and signatories.
--   Cooperative > Branches: at least the head office, each with its class and official receipt series.
--   Savings & Deposits > Savings & Deposit Products, Loans > Loan Products and Loan Charges.
--   System Administration > User Accounts: the branch and cash account of each teller.
