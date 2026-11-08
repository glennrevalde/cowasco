-- =====================================================================
--  G-SMaRT Integrated Cooperative System - clean database (set-up lists and users, no transactions)
--  Generated 2026-11-08 19:44:19 +08:00
--  237 tables (149 cooperative, 88 water billing) and 2 views,
--  2,921 rows (0 ledger documents, 0 water-service accounts)
--
--  Import:  phpMyAdmin > Import > choose this file > Go
--      or:  mysql -u root -p < accounting_system_clean.sql
--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.
--  Import as root (or a user with CREATE, CREATE VIEW, INSERT, REFERENCES and TRIGGER privileges).
--  WARNING: importing drops and recreates every table in the database below.
--
--  Default login: admin / Admin@12345 (a new password is required at first login)
--  Includes the water billing set-up: water accounts, rate codes and tariff, pipe sizes, discounts,
--  bill codes, the water stockroom and materials.
--  No water-service account, bill or receipt. No class yet: add the classes on Financial >
--  Class List before the first cooperative or water transaction (they are required there).
--  Not for a cut-over from a former water database: see docs/DEPLOYMENT_XAMPP.md section 3.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS `cooperative_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cooperative_system`;

-- ---------------------------------------------------------------------
--  1. TABLES
-- ---------------------------------------------------------------------
-- =====================================================================
--  G-SMaRT Integrated Cooperative System - database schema
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================


-- ---------------------------------------------------------------------
--  SYSTEM ADMINISTRATION AND SECURITY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  employee_no          VARCHAR(30)  NULL,
  pos_default_site_id  INT UNSIGNED NULL,                 -- the site this user's POS Register opens on
  pos_default_class_id INT UNSIGNED NULL,                 -- the class every new sale of theirs starts with
  coop_branch_id       INT UNSIGNED NULL,                 -- the cooperative branch the user works in (NULL = all)
  coop_cash_account_id INT UNSIGNED NULL,                 -- the teller's cash drawer account
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  approval_status      VARCHAR(10)  NOT NULL DEFAULT 'approved',  -- approved / pending / rejected (joined with the Company Code)
  created_via          VARCHAR(12)  NOT NULL DEFAULT 'admin',     -- admin / registration / provisioning / setup / platform
  approved_by          INT UNSIGNED NULL,                         -- who approved or rejected the registration
  approved_at          DATETIME     NULL,
  rejected_reason      VARCHAR(255) NULL,
  is_platform          TINYINT(1)   NOT NULL DEFAULT 0,           -- the account a Super Admin works through inside the company
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_approval (approval_status),
  KEY ix_users_pos_site (pos_default_site_id),
  KEY ix_users_pos_class (pos_default_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  is_admin    TINYINT(1)   NOT NULL DEFAULT 0,   -- full access to everything
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- standard role shipped with the system
  all_sites   TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = limited to role_sites
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  coop_cash_account_id INT UNSIGNED NULL,         -- profile cash account: the cash of its tellers who have none of their own
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_roles;
CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS modules;
CREATE TABLE modules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  icon       VARCHAR(40)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_modules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(60)  NOT NULL,
  module_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  page_type    ENUM('center','transaction','list','report','setup','admin') NOT NULL DEFAULT 'transaction',
  menu_group   VARCHAR(40)  NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  show_in_menu TINYINT(1)   NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_pages_key (page_key),
  CONSTRAINT fk_pages_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page access + action permissions per role (View = allowed)
DROP TABLE IF EXISTS role_page_access;
CREATE TABLE role_page_access (
  role_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_id),
  CONSTRAINT fk_rpa_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpa_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-level overrides always take precedence over roles
DROP TABLE IF EXISTS user_page_overrides;
CREATE TABLE user_page_overrides (
  user_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, page_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensitive permissions set separately from the grid
--  view_costs, view_bank_balances, credit_limit_override, coa_changes,
--  closed_period_changes (0/1); max_discount_pct, max_price_override_pct,
--  max_amount (number, blank = unlimited)
DROP TABLE IF EXISTS role_special_permissions;
CREATE TABLE role_special_permissions (
  role_id    INT UNSIGNED NOT NULL,
  perm_key   VARCHAR(40)  NOT NULL,
  perm_value VARCHAR(40)  NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rsp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, read-only audit trail (see triggers at the end of the file)
DROP TABLE IF EXISTS audit_trail;
CREATE TABLE audit_trail (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50)  NULL,
  user_roles  VARCHAR(255) NULL,
  ip_address  VARCHAR(45)  NULL,
  workstation VARCHAR(255) NULL,
  site_id     INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  module      VARCHAR(20)  NOT NULL,
  page_key    VARCHAR(60)  NULL,
  action      VARCHAR(30)  NOT NULL,
  record_type VARCHAR(40)  NULL,
  record_id   BIGINT       NULL,
  record_ref  VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NULL,
  changes     LONGTEXT     NULL,   -- JSON [{field, old, new}]
  snapshot    LONGTEXT     NULL,   -- JSON copy of the record
  reason      VARCHAR(255) NULL,
  approved_by VARCHAR(50)  NULL,
  KEY ix_audit_date (created_at),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_record (record_type, record_id),
  KEY ix_audit_action (action),
  KEY ix_audit_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS memorized_reports;
CREATE TABLE memorized_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  group_name  VARCHAR(60)  NULL,
  report_key  VARCHAR(60)  NOT NULL,
  query_string TEXT        NULL,
  is_shared   TINYINT(1)   NOT NULL DEFAULT 0,
  schedule    VARCHAR(10)  NULL,          -- daily, weekly, monthly (tools/send_scheduled_reports.php)
  email_to    VARCHAR(255) NULL,
  last_sent_at DATETIME    NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sequences;
CREATE TABLE sequences (
  seq_key VARCHAR(30) NOT NULL PRIMARY KEY,
  prefix  VARCHAR(10) NOT NULL DEFAULT '',
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad     TINYINT      NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHART OF ACCOUNTS AND LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                       'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                       'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  type_id         INT UNSIGNED NULL,                    -- the account type chosen (account_types); NULL = the built-in type of account_type
  category        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  normal_balance  ENUM('Debit','Credit') NOT NULL,
  parent_id       INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  bank_account_no VARCHAR(40)  NULL,
  with_sl         TINYINT(1)   NOT NULL DEFAULT 0,    -- keeps a subsidiary ledger: every entry needs a name
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,                  -- set by the application (DB::update)
  is_interunit    TINYINT(1)   NOT NULL DEFAULT 0,    -- due to / due from another business unit (eliminated when consolidating)
  UNIQUE KEY uq_accounts_code (code),
  KEY ix_acc_with_sl (with_sl),
  KEY ix_accounts_type (type_id),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account types: the fifteen built-in ones (written by the 2027_01_24 migration) and the user's own, each behaving
-- as one built-in type (base_type, which accounts.account_type keeps) and linked to the Statement of Cash Flows
DROP TABLE IF EXISTS account_types;
CREATE TABLE account_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  base_type   ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                   'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                   'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  cash_flow   VARCHAR(10)  NOT NULL DEFAULT '',     -- cash / operating / investing / financing ('' = income and expense)
  sort_order  INT          NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_account_types_name (name),
  KEY ix_account_types_base (base_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module default accounts (ar, ap, undeposited_funds, output_tax, ...)
DROP TABLE IF EXISTS default_accounts;
CREATE TABLE default_accounts (
  setting_key VARCHAR(40)  NOT NULL PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  module      VARCHAR(20)  NOT NULL,
  account_id  INT UNSIGNED NULL,
  CONSTRAINT fk_da_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS terms;
CREATE TABLE terms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  net_days      INT          NOT NULL DEFAULT 0,
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_days INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  rate                DECIMAL(6,3) NOT NULL DEFAULT 0,
  sales_account_id    INT UNSIGNED NULL,   -- Output Tax
  purchase_account_id INT UNSIGNED NULL,   -- Input Tax
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tax_code (code),
  CONSTRAINT fk_tax_sales FOREIGN KEY (sales_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tax_purch FOREIGN KEY (purchase_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(40)  NOT NULL,
  method_type         ENUM('cash','check','card','ewallet','bank_transfer','voucher','other') NOT NULL DEFAULT 'other',
  clearing_account_id INT UNSIGNED NULL,   -- POS clearing account for card / e-wallet / voucher / bank
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_pos         TINYINT(1)   NOT NULL DEFAULT 1,   -- offered as a payment option at the POS register
  CONSTRAINT fk_pm_account FOREIGN KEY (clearing_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_levels;
CREATE TABLE price_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40)  NOT NULL,
  adjust_pct DECIMAL(6,2) NOT NULL DEFAULT 0,  -- e.g. -10 = 10% below standard price
  valid_from DATE         NULL,                -- a promotion: applies to documents dated within these dates
  valid_to   DATE         NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sales_reps;
CREATE TABLE sales_reps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  initials  VARCHAR(10)  NULL,
  user_id   INT UNSIGNED NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS customer_types;
CREATE TABLE customer_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS billing_groups;
CREATE TABLE billing_groups (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class List: your own way of splitting the books (branches, departments, projects ...).
-- Every transaction can carry a class; the ledger keeps it, so financial reports can filter by it.
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees and other names (payees and subsidiary ledgers that are neither customers nor vendors)
DROP TABLE IF EXISTS other_names;
CREATE TABLE other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id    INT UNSIGNED NULL,                     -- the Party Master record
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  member_id   INT UNSIGNED NULL,                     -- cooperative member this name stands for (name_type 'member')
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name),
  KEY ix_other_names_member (member_id),
  KEY ix_other_names_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CUSTOMERS AND VENDORS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id            INT UNSIGNED NULL,          -- the Party Master record (a job takes its customer's)
  parent_id           INT UNSIGNED NULL,          -- job / project under a customer
  name                VARCHAR(120) NOT NULL,
  company_name        VARCHAR(120) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(40)  NULL,
  mobile              VARCHAR(40)  NULL,
  email               VARCHAR(120) NULL,
  billing_address     VARCHAR(255) NULL,
  shipping_address    VARCHAR(255) NULL,
  tin                 VARCHAR(30)  NULL,
  wht_ids             VARCHAR(100) NULL,          -- withholding taxes it usually withholds from its payments
  terms_id            INT UNSIGNED NULL,
  credit_limit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = no limit
  price_level_id      INT UNSIGNED NULL,
  tax_code_id         INT UNSIGNED NULL,
  payment_method_id   INT UNSIGNED NULL,
  customer_type_id    INT UNSIGNED NULL,
  sales_rep_id        INT UNSIGNED NULL,
  billing_group_id    INT UNSIGNED NULL,
  allow_pos_charge    TINYINT(1)   NOT NULL DEFAULT 0,  -- approved account customer at the POS
  notes               TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_customers_name (name),
  KEY ix_customers_party (party_id),
  CONSTRAINT fk_cust_parent FOREIGN KEY (parent_id) REFERENCES customers(id),
  CONSTRAINT fk_cust_terms  FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_cust_pl     FOREIGN KEY (price_level_id) REFERENCES price_levels(id),
  CONSTRAINT fk_cust_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_cust_pm     FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_cust_type   FOREIGN KEY (customer_type_id) REFERENCES customer_types(id),
  CONSTRAINT fk_cust_rep    FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_cust_bg     FOREIGN KEY (billing_group_id) REFERENCES billing_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vendors;
CREATE TABLE vendors (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id                   INT UNSIGNED NULL,   -- the Party Master record
  name                       VARCHAR(120) NOT NULL,
  company_name               VARCHAR(120) NULL,
  contact_person             VARCHAR(120) NULL,
  phone                      VARCHAR(40)  NULL,
  fax                        VARCHAR(40)  NULL,
  email                      VARCHAR(120) NULL,
  address                    VARCHAR(255) NULL,
  tin                        VARCHAR(30)  NULL,
  terms_id                   INT UNSIGNED NULL,
  credit_limit               DECIMAL(15,2) NOT NULL DEFAULT 0,
  default_expense_account_id INT UNSIGNED NULL,
  tax_code_id                INT UNSIGNED NULL,
  account_no                 VARCHAR(40)  NULL,   -- our account number with the vendor
  wht_ids                    VARCHAR(100) NULL,   -- withholding taxes usually withheld from it (withholding_taxes ids, "1,3")
  notes                      TEXT         NULL,
  is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_vendors_name (name),
  KEY ix_vendors_party (party_id),
  CONSTRAINT fk_vend_terms FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_vend_exp   FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_vend_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entity_notes;
CREATE TABLE entity_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('customer','vendor','item') NOT NULL,
  entity_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  is_todo     TINYINT(1)   NOT NULL DEFAULT 0,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  INVENTORY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS sites;
CREATE TABLE sites (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  site_type ENUM('warehouse','store','stockroom','van','damaged','other') NOT NULL DEFAULT 'warehouse',
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_categories;
CREATE TABLE item_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units of measure: a set holds the base unit (stock and costs are kept in it)
-- plus the larger or smaller units a document may be entered in
DROP TABLE IF EXISTS uom_sets;
CREATE TABLE uom_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  uom_kind   VARCHAR(10)  NOT NULL DEFAULT 'count',  -- count, weight, volume, length, time, other
  base_unit  VARCHAR(20)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_uom_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS uom_units;
CREATE TABLE uom_units (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id              INT UNSIGNED NOT NULL,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(60)  NOT NULL,
  factor              DECIMAL(15,6) NOT NULL DEFAULT 1,  -- base units in one of this unit
  is_base             TINYINT(1)   NOT NULL DEFAULT 0,
  is_sales_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_purchase_default TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_uom_units (set_id, code),
  CONSTRAINT fk_uom_units_set FOREIGN KEY (set_id) REFERENCES uom_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS items;
CREATE TABLE items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type            ENUM('service','inventory','non_inventory','assembly','other_charge','subtotal','group','discount','payment') NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  barcode              VARCHAR(60)  NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_description VARCHAR(255) NULL,
  category_id          INT UNSIGNED NULL,
  unit                 VARCHAR(20)  NULL,
  uom_set_id           INT UNSIGNED NULL,                  -- U/M set (blank = single unit)
  sales_uom_id         INT UNSIGNED NULL,                  -- unit offered first on sales forms
  purchase_uom_id      INT UNSIGNED NULL,                  -- unit offered first on purchase forms
  cost                 DECIMAL(15,4) NOT NULL DEFAULT 0,  -- standard purchase cost
  avg_cost             DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost
  sales_price          DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_pct         DECIMAL(6,2)  NOT NULL DEFAULT 0,  -- discount items: percent (0 = use sales_price as amount)
  income_account_id    INT UNSIGNED NULL,
  cogs_account_id      INT UNSIGNED NULL,
  asset_account_id     INT UNSIGNED NULL,
  expense_account_id   INT UNSIGNED NULL,
  tax_code_id          INT UNSIGNED NULL,
  group_variance_account_id INT UNSIGNED NULL,             -- group items: account of the group price less the consolidated price
  preferred_vendor_id  INT UNSIGNED NULL,
  reorder_point        DECIMAL(15,4) NOT NULL DEFAULT 0,
  max_stock            DECIMAL(15,4) NOT NULL DEFAULT 0,
  build_point          DECIMAL(15,4) NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  patronage_eligible   TINYINT(1)   NOT NULL DEFAULT 0,    -- member purchases or deliveries of it count for patronage refund
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_barcode (barcode),
  CONSTRAINT fk_items_cat    FOREIGN KEY (category_id) REFERENCES item_categories(id),
  CONSTRAINT fk_items_inc    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_cogs   FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_asset  FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_exp    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_items_gvar   FOREIGN KEY (group_variance_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_vendor FOREIGN KEY (preferred_vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_items_uom    FOREIGN KEY (uom_set_id) REFERENCES uom_sets(id),
  CONSTRAINT fk_items_suom   FOREIGN KEY (sales_uom_id) REFERENCES uom_units(id),
  CONSTRAINT fk_items_puom   FOREIGN KEY (purchase_uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill of materials for assemblies, and members of group items
DROP TABLE IF EXISTS item_components;
CREATE TABLE item_components (
  parent_item_id    INT UNSIGNED NOT NULL,
  component_item_id INT UNSIGNED NOT NULL,
  qty               DECIMAL(15,4) NOT NULL DEFAULT 1,
  PRIMARY KEY (parent_item_id, component_item_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_comp   FOREIGN KEY (component_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing formulas (multi-output bills of materials) for disassembly,
-- de-kitting / break-bulk and co-product / by-product processing
DROP TABLE IF EXISTS process_formulas;
CREATE TABLE process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formula lines, quantities per batch. input = consumed, output = produced, cost = labor / overhead absorbed
DROP TABLE IF EXISTS process_formula_lines;
CREATE TABLE process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_level_items;
CREATE TABLE price_level_items (
  price_level_id INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  custom_price   DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (price_level_id, item_id),
  CONSTRAINT fk_pli_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_item  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_site_stock;
CREATE TABLE item_site_stock (
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, site_id),
  CONSTRAINT fk_iss_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iss_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TRANSACTIONS (all modules)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_type           VARCHAR(30)  NOT NULL,
  -- estimate, sales_order, invoice, sales_receipt, statement_charge, finance_charge,
  -- payment, credit_memo, refund, purchase_order, bill, vendor_credit, item_receipt,
  -- bill_payment, check, deposit, transfer, journal, inventory_adjustment,
  -- inventory_transfer, build_assembly, pos_sale, pos_return, depreciation,
  -- asset_disposal, bank_charge, bank_interest, item_processing, cash_voucher, vendor_advance
  txn_no             VARCHAR(30)  NULL,
  txn_date           DATE         NOT NULL,
  due_date           DATE         NULL,
  ship_date          DATE         NULL,
  ref_no             VARCHAR(60)  NULL,
  customer_id        INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,
  payee_name         VARCHAR(120) NULL,
  account_id         INT UNSIGNED NULL,     -- AR / AP / bank / deposit-to / from account
  account2_id        INT UNSIGNED NULL,     -- transfer-to / cash-back / adjustment account
  site_id            INT UNSIGNED NULL,
  site2_id           INT UNSIGNED NULL,     -- transfer-to site
  terms_id           INT UNSIGNED NULL,
  sales_rep_id       INT UNSIGNED NULL,
  payment_method_id  INT UNSIGNED NULL,
  billing_address    VARCHAR(255) NULL,
  shipping_address   VARCHAR(255) NULL,
  memo               VARCHAR(255) NULL,
  customer_message   VARCHAR(255) NULL,
  subtotal           DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_back_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20)  NOT NULL DEFAULT 'open',
  -- open, closed, paid, partial, pending, accepted, suspended, completed, void
  is_pending         TINYINT(1)   NOT NULL DEFAULT 0,
  is_voided          TINYINT(1)   NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME     NULL,
  to_be_printed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_printed         TINYINT(1)   NOT NULL DEFAULT 0,
  restock            TINYINT(1)   NOT NULL DEFAULT 1,     -- credit memos / POS returns
  credit_disposition VARCHAR(10)  NULL,                   -- keep, apply, refund
  progress_pct       DECIMAL(6,2) NULL,                   -- progress invoicing
  source_txn_id      BIGINT UNSIGNED NULL,                -- converted from / refund of
  terminal_id        INT UNSIGNED NULL,
  pos_shift_id       INT UNSIGNED NULL,
  receipt_no         VARCHAR(30)  NULL,
  amount_tendered    DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_given       DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_by        INT UNSIGNED NULL,
  batch_ref          VARCHAR(40)  NULL,
  process_type       VARCHAR(20)  NULL,                   -- item processing: disassembly, dekit, co_product
  alloc_method       VARCHAR(20)  NULL,                   -- item processing: cost allocation method
  process_formula_id INT UNSIGNED NULL,                   -- item processing: formula used
  process_batches    DECIMAL(15,4) NULL,                  -- item processing: formula batches run
  class_id           INT UNSIGNED NULL,                   -- class of the whole transaction (lines may override it)
  other_name_id      INT UNSIGNED NULL,                   -- payee from Employees & Other Names
  received_via       VARCHAR(12)  NULL,                   -- receipt = Receive Items, bill = Receive Items and Enter Bills
  party_id           INT UNSIGNED NULL,                   -- the Party Master record of the counterparty
  business_unit_id   INT UNSIGNED NULL,                   -- line of business (business_units)
  cp_role            VARCHAR(10)  NULL,                   -- counterparty snapshot: customer / supplier / payee / payer / name
  cp_name            VARCHAR(160) NULL,                   -- name printed on the document, taken when it was issued
  cp_address         VARCHAR(255) NULL,
  cp_tin             VARCHAR(30)  NULL,
  cp_member_no       VARCHAR(30)  NULL,
  cp_source          VARCHAR(8)   NULL,                   -- save / upgrade / reissue
  cp_issued_at       DATETIME     NULL,
  cp_reissue_no      INT          NOT NULL DEFAULT 0,     -- authorized corrections of the printed name
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_txn_type_date (txn_type, txn_date),
  KEY ix_txn_customer (customer_id, txn_date),
  KEY ix_txn_vendor (vendor_id, txn_date),
  KEY ix_txn_status (status),
  KEY ix_txn_source (source_txn_id),
  KEY ix_txn_shift (pos_shift_id),
  KEY ix_txn_formula (process_formula_id),
  KEY ix_txn_class (class_id),
  KEY ix_txn_other_name (other_name_id),
  KEY ix_transactions_party (party_id, txn_date),
  KEY ix_transactions_bu (business_unit_id),
  CONSTRAINT fk_txn_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_txn_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_txn_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_formula  FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account2 FOREIGN KEY (account2_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_site     FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_site2    FOREIGN KEY (site2_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_txn_rep      FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_txn_pm       FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transaction_lines;
CREATE TABLE transaction_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id          BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'item',   -- item, expense, journal, deposit, component, input, output, cost
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  uom_id          INT UNSIGNED NULL,                      -- unit the line was entered in
  uom_factor      DECIMAL(15,6) NOT NULL DEFAULT 1,       -- base units in one entered unit
  uom_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,       -- quantity as entered
  uom_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price as entered
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_kind   VARCHAR(3)    NOT NULL DEFAULT 'pct',   -- pct = percent of the line, amt = amount off
  discount_pct    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,       -- journal: + debit / - credit
  tax_code_id     INT UNSIGNED NULL,
  tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  site_id         INT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,                      -- customer:job for billable costs / journal
  vendor_id       INT UNSIGNED NULL,
  is_billable     TINYINT(1)   NOT NULL DEFAULT 0,
  billed_txn_id   BIGINT UNSIGNED NULL,                   -- invoice that billed this cost
  ref_txn_id      BIGINT UNSIGNED NULL,                   -- deposit: payment / receipt being deposited
  pos_shift_id    INT UNSIGNED NULL,                      -- deposit: the POS shift whose cash the line deposits
  source_line_id  BIGINT UNSIGNED NULL,                   -- estimate / SO / PO line
  qty_fulfilled   DECIMAL(15,4) NOT NULL DEFAULT 0,       -- SO invoiced / PO received
  amount_fulfilled DECIMAL(15,2) NOT NULL DEFAULT 0,      -- estimate progress-invoiced
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  cost_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,       -- COGS captured at posting
  value_change    DECIMAL(15,2) NOT NULL DEFAULT 0,       -- inventory value adjustment
  output_role     VARCHAR(12)  NULL,                      -- item processing output: co_product, by_product, scrap
  alloc_basis     DECIMAL(15,4) NOT NULL DEFAULT 0,       -- item processing: price / weight / percent / value entered
  alloc_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,       -- item processing: share of the total cost
  planned_qty     DECIMAL(15,4) NULL,                     -- item processing: quantity expected by the formula
  class_id        INT UNSIGNED NULL,                      -- class of this line (blank = the transaction's class)
  other_name_id   INT UNSIGNED NULL,                      -- subsidiary ledger: employee / other name
  group_item_id   INT UNSIGNED NULL,                      -- item group sold: the group this line belongs to
  group_ref       INT UNSIGNED NULL,                      -- which group of the document (several of the same group can be on it)
  group_qty       DECIMAL(15,4) NULL,                     -- groups sold on the line's group
  group_rate      DECIMAL(15,4) NULL,                     -- price of one group
  group_role      VARCHAR(10)  NULL,                      -- member = an item of the group, variance = group price less consolidated price
  KEY ix_tl_txn (txn_id),
  KEY ix_tl_group (group_item_id),
  KEY ix_tl_item (item_id),
  KEY ix_tl_ref (ref_txn_id),
  KEY ix_tl_shift (pos_shift_id),
  KEY ix_tl_source (source_line_id),
  KEY ix_tl_class (class_id),
  KEY ix_tl_other_name (other_name_id),
  CONSTRAINT fk_tl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_tl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_tl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_tl_site    FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_tl_cust    FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_tl_uom     FOREIGN KEY (uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments / credits applied to invoices and bills
DROP TABLE IF EXISTS payment_applications;
CREATE TABLE payment_applications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,   -- payment, credit memo, bill payment, vendor credit
  target_txn_id  BIGINT UNSIGNED NOT NULL,   -- invoice, statement/finance charge, bill
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pa_payment (payment_txn_id),
  KEY ix_pa_target (target_txn_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withholding Tax Table (BIR Reports): the taxes a payment can have withheld
DROP TABLE IF EXISTS withholding_taxes;
CREATE TABLE withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,                   -- BIR alphanumeric tax code (WC158, WC160, WV010 ...)
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',   -- expanded withholding of income tax, or VAT / business tax withheld
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,     -- percent
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,                   -- withheld from suppliers (blank = module default)
  receivable_account_id INT UNSIGNED NULL,                   -- withheld by customers (blank = module default)
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes withheld by a payment (bill payment or customer payment), by document paid and type: BIR Form 2307 and the BIR Reports
DROP TABLE IF EXISTS payment_withholdings;
CREATE TABLE payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,        -- the bill / invoice it was withheld on
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item group sales (System Administration > Track Item Group Sales transactions): one row per group sold on a document
DROP TABLE IF EXISTS item_group_sales;
CREATE TABLE item_group_sales (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,
  group_ref      INT UNSIGNED NOT NULL DEFAULT 0,
  group_item_id  INT UNSIGNED NOT NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  txn_date       DATE         NOT NULL,
  customer_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  terminal_id    INT UNSIGNED NULL,
  class_id       INT UNSIGNED NULL,
  sales_rep_id   INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,   -- groups sold
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,   -- price of one group
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- what the groups sold for (before tax)
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,   -- the items of the groups at their own prices
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- amount less consolidated (to the price variance account)
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Card and e-wallet receipts ticked to settle on Make Deposits (v32, Details > Settle)
DROP TABLE IF EXISTS clearing_settlements;
CREATE TABLE clearing_settlements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deposit_txn_id  BIGINT UNSIGNED NOT NULL,                -- the deposit that holds the settlement
  account_id      INT UNSIGNED    NOT NULL,                -- the card / e-wallet clearing account
  receipt_txn_id  BIGINT UNSIGNED NOT NULL,                -- the POS sale, payment or sales receipt settled
  amount          DECIMAL(15,2)   NOT NULL DEFAULT 0,      -- what of it was settled
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_deposit (deposit_txn_id),
  KEY ix_cs_receipt (account_id, receipt_txn_id),
  KEY ix_cs_receipt_txn (receipt_txn_id),
  CONSTRAINT fk_cs_deposit FOREIGN KEY (deposit_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_receipt FOREIGN KEY (receipt_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General ledger (generated automatically by the posting engine)
DROP TABLE IF EXISTS journal_lines;
CREATE TABLE journal_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_date          DATE         NOT NULL,
  account_id        INT UNSIGNED NOT NULL,
  debit             DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(15,2) NOT NULL DEFAULT 0,
  customer_id       INT UNSIGNED NULL,
  vendor_id         INT UNSIGNED NULL,
  item_id           INT UNSIGNED NULL,
  site_id           INT UNSIGNED NULL,
  class_id          INT UNSIGNED NULL,
  business_unit_id  INT UNSIGNED NULL,     -- line of business, copied from the document
  other_name_id     INT UNSIGNED NULL,
  memo              VARCHAR(255) NULL,
  cleared           TINYINT(1)   NOT NULL DEFAULT 0,
  reconciliation_id INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jl_account_date (account_id, txn_date),
  KEY ix_jl_txn (txn_id),
  KEY ix_jl_customer (customer_id),
  KEY ix_jl_vendor (vendor_id),
  KEY ix_jl_class (class_id, txn_date),
  KEY ix_jl_other_name (other_name_id),
  KEY ix_journal_lines_bu (business_unit_id, account_id),
  CONSTRAINT fk_jl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_jl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Changes to saved journal entries: made only when a second, authorized user approves them, with the explanation
-- of the change, the entry as it was and as proposed (Chart of Accounts > Journal Entry Changes)
DROP TABLE IF EXISTS journal_edit_requests;
CREATE TABLE journal_edit_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id           BIGINT UNSIGNED NOT NULL,            -- the journal entry (transactions.id)
  status           VARCHAR(12)  NOT NULL DEFAULT 'pending',  -- pending, approved, rejected, withdrawn, superseded
  explanation      VARCHAR(1000) NOT NULL,              -- why the entry is changed (required)
  before_json      MEDIUMTEXT   NOT NULL,               -- the entry as it was when the change was asked
  proposed_json    MEDIUMTEXT   NOT NULL,               -- the entry as proposed
  before_hash      CHAR(32)     NOT NULL,               -- refuses the approval when the entry changed meanwhile
  requested_by     INT UNSIGNED NOT NULL,
  requested_at     DATETIME     NOT NULL,
  decided_by       INT UNSIGNED NULL,
  decided_at       DATETIME     NULL,
  decision_remarks VARCHAR(255) NULL,
  KEY ix_jer_txn (txn_id, status),
  KEY ix_jer_status (status, id),
  KEY ix_jer_requester (requested_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perpetual inventory movements (generated automatically)
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id      BIGINT UNSIGNED NOT NULL,
  txn_line_id BIGINT UNSIGNED NULL,
  txn_date    DATE         NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- total on hand (all sites) after the movement
  avg_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- average cost after the movement
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_im_item_date (item_id, txn_date),
  KEY ix_im_txn (txn_id),
  CONSTRAINT fk_im_txn  FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_im_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VENDORS - RECEIPT MANAGEMENT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS receipt_documents;
CREATE TABLE receipt_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(80)  NULL,
  source        ENUM('upload','email','mobile') NOT NULL DEFAULT 'upload',
  vendor_id     INT UNSIGNED NULL,
  receipt_date  DATE         NULL,
  amount        DECIMAL(15,2) NULL,
  account_id    INT UNSIGNED NULL,
  memo          VARCHAR(255) NULL,
  status        ENUM('for_review','matched','created','rejected') NOT NULL DEFAULT 'for_review',
  linked_txn_id BIGINT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  CONSTRAINT fk_rd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_rd_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rd_txn     FOREIGN KEY (linked_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FIXED ASSETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS asset_categories;
CREATE TABLE asset_categories (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)  NOT NULL,
  asset_account_id       INT UNSIGNED NOT NULL,
  accum_dep_account_id   INT UNSIGNED NOT NULL,
  dep_expense_account_id INT UNSIGNED NOT NULL,
  default_method         ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL DEFAULT 'straight_line',
  default_life_years     DECIMAL(5,2) NOT NULL DEFAULT 5,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_ac_asset FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_accum FOREIGN KEY (accum_dep_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_exp   FOREIGN KEY (dep_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_locations;
CREATE TABLE asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fixed_assets;
CREATE TABLE fixed_assets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_no            VARCHAR(30)  NOT NULL,
  description         VARCHAR(160) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  location            VARCHAR(120) NULL,
  location_id         INT UNSIGNED NULL,
  site_id             INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,      -- default class (depreciation entry when no class is chosen)
  serial_no           VARCHAR(60)  NULL,
  vendor_id           INT UNSIGNED NULL,
  acquisition_date    DATE         NOT NULL,
  cost                DECIMAL(15,2) NOT NULL,
  salvage_value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  useful_life_years   DECIMAL(5,2) NOT NULL,
  method              ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL,
  db_multiplier       DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  total_units         DECIMAL(15,2) NULL,      -- units of production
  unit_label          VARCHAR(30)  NULL,
  -- change in estimate: new basis applied from basis_date forward
  basis_date          DATE         NULL,
  basis_nbv           DECIMAL(15,2) NULL,
  basis_life_months   INT          NULL,
  -- existing assets: depreciation recorded before the system was used
  opening_accum_dep   DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_through     DATE         NULL,
  acquisition_txn_id  BIGINT UNSIGNED NULL,
  status              ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  disposal_date       DATE         NULL,
  disposal_proceeds   DECIMAL(15,2) NULL,
  disposal_txn_id     BIGINT UNSIGNED NULL,
  notes               TEXT         NULL,
  current_meter       DECIMAL(15,2) NULL,     -- odometer / running hours
  meter_unit          VARCHAR(20)  NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_fa_no (asset_no),
  KEY ix_fa_location (location_id),
  KEY ix_fa_class (class_id),
  CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id),
  CONSTRAINT fk_fa_class  FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_fa_cat    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
  CONSTRAINT fk_fa_site   FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_fa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_fa_acq    FOREIGN KEY (acquisition_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fa_disp   FOREIGN KEY (disposal_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_depreciation;
CREATE TABLE asset_depreciation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units_used   DECIMAL(15,2) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_period (asset_id, period_year, period_month),
  CONSTRAINT fk_ad_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_meter_readings;
CREATE TABLE asset_meter_readings (
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units        DECIMAL(15,2) NOT NULL,
  entered_by   INT UNSIGNED NULL,
  entered_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, period_year, period_month),
  CONSTRAINT fk_amr_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed asset maintenance and preventive maintenance
DROP TABLE IF EXISTS asset_pm_schedules;
CREATE TABLE asset_pm_schedules (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id         INT UNSIGNED NOT NULL,
  task_name        VARCHAR(160) NOT NULL,
  task_type        VARCHAR(30)  NOT NULL DEFAULT 'other',  -- registration, change_oil, cleaning, inspection, insurance, other
  interval_value   INT          NULL,                      -- repeat every N ... (NULL = one time only)
  interval_unit    VARCHAR(10)  NULL,                      -- days, weeks, months, years
  meter_interval   DECIMAL(15,2) NULL,                     -- or every N km / hours
  next_due_date    DATE         NULL,
  next_due_meter   DECIMAL(15,2) NULL,
  remind_days      INT          NOT NULL DEFAULT 14,       -- start reminding this many days before the due date
  last_done_date   DATE         NULL,
  last_done_meter  DECIMAL(15,2) NULL,
  assigned_to      VARCHAR(120) NULL,
  notes            VARCHAR(255) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_pm_asset (asset_id),
  KEY ix_pm_due (is_active, next_due_date),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance;
CREATE TABLE asset_maintenance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maint_no            VARCHAR(30)  NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  maint_date          DATE         NOT NULL,
  maint_type          VARCHAR(20)  NOT NULL DEFAULT 'repair',     -- repair, preventive, inspection, upgrade
  status              VARCHAR(20)  NOT NULL DEFAULT 'completed',  -- in_progress, completed, void
  problem             VARCHAR(255) NULL,                          -- what was reported / why
  description         TEXT         NULL,                          -- maintenance description: the work done
  vendor_id           INT UNSIGNED NULL,                          -- supplier of the parts / service
  performed_by        VARCHAR(120) NULL,                          -- mechanic, technician or shop
  ref_no              VARCHAR(60)  NULL,                          -- supplier invoice / job order no.
  meter_reading       DECIMAL(15,2) NULL,
  downtime_days       DECIMAL(7,2) NULL,
  parts_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  labor_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  total               DECIMAL(15,2) NOT NULL DEFAULT 0,
  with_vat            TINYINT(1)   NOT NULL DEFAULT 0,
  payment_mode        VARCHAR(20)  NOT NULL DEFAULT 'none',       -- none, bill, check, cash_voucher
  pay_account_id      INT UNSIGNED NULL,                          -- bank (check) or cash account (cash voucher)
  parts_account_id    INT UNSIGNED NULL,
  labor_account_id    INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,
  txn_id              BIGINT UNSIGNED NULL,                       -- the bill, check or cash voucher created for it
  schedule_id         INT UNSIGNED NULL,                          -- preventive maintenance task completed by this record
  next_service_date   DATE         NULL,
  notes               TEXT         NULL,
  void_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_maint_no (maint_no),
  KEY ix_maint_asset (asset_id, maint_date),
  KEY ix_maint_vendor (vendor_id),
  KEY ix_maint_txn (txn_id),
  KEY ix_maint_class (class_id),
  KEY ix_maint_schedule (schedule_id),
  CONSTRAINT fk_maint_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id),
  CONSTRAINT fk_maint_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_maint_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_maint_txn      FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_maint_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_parts;
CREATE TABLE asset_maintenance_parts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  part_no         VARCHAR(60)  NULL,
  description     VARCHAR(200) NOT NULL,
  serial_no       VARCHAR(60)  NULL,
  model_no        VARCHAR(60)  NULL,
  size_spec       VARCHAR(60)  NULL,
  brand           VARCHAR(60)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 1,
  unit            VARCHAR(20)  NULL,
  unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  warranty_until  DATE         NULL,
  KEY ix_mparts_maint (maintenance_id),
  KEY ix_mparts_partno (part_no),
  CONSTRAINT fk_mparts_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_labor;
CREATE TABLE asset_maintenance_labor (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(200) NOT NULL,
  worker          VARCHAR(120) NULL,
  charge_type     VARCHAR(10)  NOT NULL DEFAULT 'hourly',   -- hourly (man-hours x rate) or bulk (one charge)
  hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_mlabor_maint (maintenance_id),
  CONSTRAINT fk_mlabor_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_photos;
CREATE TABLE asset_maintenance_photos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  file_name       VARCHAR(120) NOT NULL,      -- name inside storage/maintenance/
  original_name   VARCHAR(190) NULL,
  mime_type       VARCHAR(60)  NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  caption         VARCHAR(190) NULL,
  uploaded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mphotos_maint (maintenance_id),
  CONSTRAINT fk_mphotos_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_pm_log;
CREATE TABLE asset_pm_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id     INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,
  task_name       VARCHAR(160) NOT NULL,
  due_date        DATE         NULL,
  done_date       DATE         NOT NULL,
  done_meter      DECIMAL(15,2) NULL,
  maintenance_id  INT UNSIGNED NULL,
  remarks         VARCHAR(255) NULL,
  done_by         INT UNSIGNED NULL,          -- the user who acknowledged that the task is complete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pmlog_schedule (schedule_id),
  KEY ix_pmlog_asset (asset_id, done_date),
  CONSTRAINT fk_pmlog_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmlog_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  POINT OF SALE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS pos_terminals;
CREATE TABLE pos_terminals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  site_id         INT UNSIGNED NOT NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  receipt_prefix  VARCHAR(10)  NOT NULL DEFAULT 'OR',
  next_receipt_no INT UNSIGNED NOT NULL DEFAULT 1,
  next_z_no       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  class_id        INT UNSIGNED NULL,                      -- default class of sales made at this terminal
  UNIQUE KEY uq_term_code (code),
  CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_term_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_term_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_shifts;
CREATE TABLE pos_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terminal_id    INT UNSIGNED NOT NULL,
  cashier_id     INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  opened_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  deposit_txn_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  corrected_by   INT UNSIGNED NULL,          -- who last corrected the closing entries (float, drops, count)
  corrected_at   DATETIME     NULL,
  KEY ix_shift_term (terminal_id, status),
  CONSTRAINT fk_shift_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_tenders;
CREATE TABLE pos_tenders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,     -- net amount applied to the sale (after change)
  reference         VARCHAR(60)  NULL,
  KEY ix_pt_txn (txn_id),
  CONSTRAINT fk_pt_txn FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_pm  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_cash_drops;
CREATE TABLE pos_cash_drops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_readings;
CREATE TABLE pos_readings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reading_type   ENUM('X','Z') NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  z_no           INT UNSIGNED NULL,
  business_date  DATE         NOT NULL,
  first_receipt  VARCHAR(30)  NULL,
  last_receipt   VARCHAR(30)  NULL,
  sales_count    INT          NOT NULL DEFAULT 0,
  gross_sales    DECIMAL(15,2) NOT NULL DEFAULT 0,
  discounts      DECIMAL(15,2) NOT NULL DEFAULT 0,
  returns        DECIMAL(15,2) NOT NULL DEFAULT 0,
  voids          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(15,2) NOT NULL DEFAULT 0,
  tenders_json   TEXT         NULL,
  cash_drops     DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_term  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_pr_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time approval codes a supervisor generates for a cashier or clerk (used once, then expired)
DROP TABLE IF EXISTS approval_codes;
CREATE TABLE approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,          -- the user it is meant for (NULL: any user)
  page_key      VARCHAR(60)  NULL,          -- the page it may approve on (NULL: any page the issuer may approve on)
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / used / expired / cancelled
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at),
  CONSTRAINT fk_apc_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKING
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS bank_reconciliations;
CREATE TABLE bank_reconciliations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  statement_date     DATE         NOT NULL,
  beginning_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  ending_balance     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cleared_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
  difference         DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_charge_txn_id BIGINT UNSIGNED NULL,
  interest_txn_id    BIGINT UNSIGNED NULL,
  service_charge     DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_date       DATE         NULL,
  service_account_id INT UNSIGNED NULL,
  interest_earned    DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_date      DATE         NULL,
  interest_account_id INT UNSIGNED NULL,
  class_id           INT UNSIGNED NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by       INT UNSIGNED NULL,
  completed_at       DATETIME     NULL,
  KEY ix_br_account (account_id, statement_date),
  CONSTRAINT fk_br_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the items cleared in each reconciliation (used by the reports)
DROP TABLE IF EXISTS reconciliation_items;
CREATE TABLE reconciliation_items (
  reconciliation_id INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,   -- + deposit / - payment
  cleared           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, txn_id),
  CONSTRAINT fk_ri_rec FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleared transactions changed after reconciliation
DROP TABLE IF EXISTS reconciliation_discrepancies;
CREATE TABLE reconciliation_discrepancies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT UNSIGNED NULL,
  account_id        INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_ref           VARCHAR(80)  NULL,
  change_type       VARCHAR(20)  NOT NULL,   -- amount changed, voided, deleted, uncleared
  cleared_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  new_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  changed_by        INT UNSIGNED NULL,
  changed_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rd_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SECURITY - site and terminal assignments
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS role_sites;
CREATE TABLE role_sites (
  role_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, site_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_sites;
CREATE TABLE user_sites (
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, site_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_terminals;
CREATE TABLE user_terminals (
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, terminal_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Memorized transactions, budgets and forecasts, report layouts,
--  CSV imports and database backups
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS memorized_txns;
CREATE TABLE memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budgets;
CREATE TABLE budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budget_lines;
CREATE TABLE budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS report_layouts;
CREATE TABLE report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS import_batches;
CREATE TABLE import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS backup_log;
CREATE TABLE backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  COOPERATIVE MANAGEMENT (members, share capital, savings, time deposits,
--  loans, teller, year-end distribution, CDA compliance)
--  The same tables as database/migrations/2026_11_01_cooperative.sql.
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS coop_branches;
CREATE TABLE coop_branches (
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

DROP TABLE IF EXISTS member_types;
CREATE TABLE member_types (
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

DROP TABLE IF EXISTS member_clusters;
CREATE TABLE member_clusters (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  branch_id INT UNSIGNED NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_clusters_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_segments;
CREATE TABLE member_segments (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_member_segments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS coop_lists;
CREATE TABLE coop_lists (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  list_key  VARCHAR(30)  NOT NULL,     -- loan_purpose, collateral_type, account_class, account_group, address_subgroup, savings_group, doc_type, id_type, occupation
  name      VARCHAR(120) NOT NULL,
  extra     VARCHAR(255) NULL,
  sort_order INT         NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  KEY ix_coop_lists_key (list_key),
  UNIQUE KEY uq_coop_lists_name (list_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS passbook_types;
CREATE TABLE passbook_types (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL,
  description     VARCHAR(255) NULL,
  ledger          VARCHAR(10)  NOT NULL DEFAULT 'savings',   -- savings / loan: each kind has its own design
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
  start_line_skip INT NOT NULL DEFAULT 12,        -- the first line never printed on (the centre fold); 0 = none
  line_skip       INT NOT NULL DEFAULT 1,         -- how many lines are skipped from start_line_skip
  max_lines       INT NOT NULL DEFAULT 26,        -- lines per page
  columns_json    TEXT NULL,                      -- column positions (twips) and widths
  font_size_pt    DECIMAL(4,1) NOT NULL DEFAULT 9.0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  header_json     TEXT NULL,                      -- account name and number on every page (positions)
  cover_json      TEXT NULL,                      -- the inside cover (positions)
  skip_lines      VARCHAR(60) NULL,               -- lines never printed (e.g. the centre fold): 13 or 13,14
  dash_empty      TINYINT(1) NOT NULL DEFAULT 1,  -- a dash in the empty amount column
  footer_text     VARCHAR(160) NULL,              -- printed by Print Passbook Footer
  layout_json     TEXT NULL,                      -- the design: cover, header, body, footer fields (Passbook Types)
  UNIQUE KEY uq_passbook_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS document_templates;
CREATE TABLE document_templates (
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

DROP TABLE IF EXISTS document_prints;
CREATE TABLE document_prints (
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

DROP TABLE IF EXISTS members;
CREATE TABLE members (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  party_id           INT UNSIGNED NULL,               -- the Party Master record
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
  -- Water Defaults (Member Information): the member's water-service account
  water_zone               INT          NULL,
  water_sequence           VARCHAR(50)  NULL,
  water_rc_id              INT          NULL,               -- rate code / classification (rate_code.rc_id)
  water_pipe_size_id       INT UNSIGNED NULL,
  water_connection_date    DATE         NULL,
  water_disconnection_date DATE         NULL,               -- a date makes the connection Inactive
  water_connection_status  VARCHAR(10)  NOT NULL DEFAULT 'active',  -- active / inactive
  water_account_id         INT          NULL,               -- water_accounts.pkey (the member's own account)
  UNIQUE KEY uq_members_no (member_no),
  KEY ix_members_name (full_name(150)),
  KEY ix_members_status (status),
  KEY ix_members_branch (branch_id),
  KEY ix_members_other_name (other_name_id),
  KEY ix_members_type (member_type_id),
  KEY ix_members_cluster (cluster_id),
  KEY ix_members_mobile (mobile),
  KEY ix_members_party (party_id),
  KEY ix_members_water_account (water_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_contacts;
CREATE TABLE member_contacts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  contact_type VARCHAR(20)  NOT NULL,     -- mobile / landline / email / fax / other
  country_code VARCHAR(6)   NULL,
  area_code    VARCHAR(6)   NULL,
  number       VARCHAR(60)  NOT NULL,
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_member_contacts_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_dependents;
CREATE TABLE member_dependents (
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

DROP TABLE IF EXISTS member_relatives;
CREATE TABLE member_relatives (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id          INT UNSIGNED NOT NULL,
  relative_member_id INT UNSIGNED NOT NULL,
  relationship       VARCHAR(40)  NULL,
  KEY ix_member_relatives_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_finances;
CREATE TABLE member_finances (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED NOT NULL,
  kind          VARCHAR(10)  NOT NULL,      -- income / expense / outlay
  name          VARCHAR(120) NOT NULL,
  member_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  spouse_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_finances_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_assets;
CREATE TABLE member_assets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id   INT UNSIGNED NOT NULL,
  description VARCHAR(160) NOT NULL,
  asset_type  VARCHAR(10)  NOT NULL DEFAULT 'asset',   -- asset / liability
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_member_assets_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_beneficiaries;
CREATE TABLE member_beneficiaries (
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

DROP TABLE IF EXISTS member_representatives;
CREATE TABLE member_representatives (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  name      VARCHAR(160) NOT NULL,
  position  VARCHAR(80)  NULL,
  rep_member_id INT UNSIGNED NULL,
  KEY ix_member_reps_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_documents;
CREATE TABLE member_documents (
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

DROP TABLE IF EXISTS member_status_history;
CREATE TABLE member_status_history (
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

DROP TABLE IF EXISTS member_attendance;
CREATE TABLE member_attendance (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id    INT UNSIGNED NOT NULL,
  meeting_type VARCHAR(20)  NOT NULL,     -- ownership / general_assembly / training / other
  meeting_date DATE         NOT NULL,
  attended     TINYINT(1)   NOT NULL DEFAULT 1,
  remarks      VARCHAR(160) NULL,
  KEY ix_member_attendance_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS member_notes;
CREATE TABLE member_notes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  note       TEXT         NOT NULL,
  note_type  VARCHAR(20)  NOT NULL DEFAULT 'note',   -- note / alert / collection / restriction
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_notes_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_products;
CREATE TABLE sav_products (
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

DROP TABLE IF EXISTS sav_product_rates;
CREATE TABLE sav_product_rates (
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

DROP TABLE IF EXISTS sav_txn_codes;
CREATE TABLE sav_txn_codes (
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
  is_memo             TINYINT(1)   NOT NULL DEFAULT 0,        -- debit / credit memo: the GL account is chosen each time
  UNIQUE KEY uq_sav_txn_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_accounts;
CREATE TABLE sav_accounts (
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

DROP TABLE IF EXISTS sav_txns;
CREATE TABLE sav_txns (
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
  trans_no      VARCHAR(20)  NULL,                 -- Savings Transaction Center number (one series for savings and TD)
  opposite_account_id INT UNSIGNED NULL,           -- the account of the other side of the entry (cash, GL, memo)
  cheque_json   TEXT         NULL,                 -- cheque details (check deposits)
  is_back_entry TINYINT(1)   NOT NULL DEFAULT 0,
  corrects_id   BIGINT UNSIGNED NULL,              -- the transaction this error correction negates
  corrected_by_id BIGINT UNSIGNED NULL,            -- the error correction that negated this one
  KEY ix_sav_txns_account (account_id, txn_date, id),
  KEY ix_sav_txns_txn (txn_id),
  KEY ix_sav_txns_ref (ref_type, ref_id),
  KEY ix_sav_txns_shift (shift_id),
  KEY ix_sav_txns_clearing (is_cleared, clearing_date),
  KEY ix_sav_txns_trans (trans_no),
  KEY ix_sav_txns_corrects (corrects_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_holds;
CREATE TABLE sav_holds (
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

DROP TABLE IF EXISTS sav_interest_runs;
CREATE TABLE sav_interest_runs (
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

DROP TABLE IF EXISTS sav_interest_lines;
CREATE TABLE sav_interest_lines (
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

DROP TABLE IF EXISTS td_placements;
CREATE TABLE td_placements (
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
  accrued_paid     DECIMAL(15,2) NOT NULL DEFAULT 0.00,     -- accrued interest withdrawn before maturity (CAIW)
  UNIQUE KEY uq_td_certificate (certificate_no),
  KEY ix_td_account (account_id),
  KEY ix_td_status (status, maturity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time deposit accrued interest withdrawn before maturity (code CAIW)
DROP TABLE IF EXISTS td_interest_payouts;
CREATE TABLE td_interest_payouts (
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

DROP TABLE IF EXISTS td_accruals;
CREATE TABLE td_accruals (
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

DROP TABLE IF EXISTS passbook_prints;
CREATE TABLE passbook_prints (
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

DROP TABLE IF EXISTS sav_batches;
CREATE TABLE sav_batches (
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
  class_id     INT UNSIGNED NULL,                           -- the class of its postings (Class List)
  UNIQUE KEY uq_sav_batches_no (batch_no),
  KEY ix_sav_batches_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sav_batch_lines;
CREATE TABLE sav_batch_lines (
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

DROP TABLE IF EXISTS loan_products;
CREATE TABLE loan_products (
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
  addl_interest         VARCHAR(10)  NOT NULL DEFAULT 'auto',      -- additional interest when past due: auto / arrears / maturity / none
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

DROP TABLE IF EXISTS loan_product_rates;
CREATE TABLE loan_product_rates (
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

DROP TABLE IF EXISTS loan_charges;
CREATE TABLE loan_charges (
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

DROP TABLE IF EXISTS loan_charge_ranges;
CREATE TABLE loan_charge_ranges (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  charge_id  INT UNSIGNED NOT NULL,
  range_from DECIMAL(15,4) NOT NULL DEFAULT 0,
  range_to   DECIMAL(15,4) NOT NULL DEFAULT 0,
  value      DECIMAL(15,4) NOT NULL DEFAULT 0,
  KEY ix_lcr_charge (charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_product_charges;
CREATE TABLE loan_product_charges (
  product_id INT UNSIGNED NOT NULL,
  charge_id  INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_approval_matrix;
CREATE TABLE loan_approval_matrix (
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

DROP TABLE IF EXISTS loan_applications;
CREATE TABLE loan_applications (
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
  class_id         INT UNSIGNED NULL,                       -- the class of a renewal or restructure (Class List)
  UNIQUE KEY uq_loan_applications_no (application_no),
  KEY ix_loan_applications_member (member_id),
  KEY ix_loan_applications_status (status),
  KEY ix_loan_applications_branch (branch_id),
  KEY ix_loan_applications_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_collaterals;
CREATE TABLE loan_collaterals (
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

DROP TABLE IF EXISTS loan_comakers;
CREATE TABLE loan_comakers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id    INT UNSIGNED NOT NULL,
  comaker_member_id INT UNSIGNED NULL,
  name              VARCHAR(160) NULL,
  address           VARCHAR(255) NULL,
  amount_guaranteed DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_loan_comakers_app (application_id),
  KEY ix_loan_comakers_member (comaker_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_approvals;
CREATE TABLE loan_approvals (
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

DROP TABLE IF EXISTS loan_application_docs;
CREATE TABLE loan_application_docs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  requirement    VARCHAR(120) NOT NULL,
  is_complete    TINYINT(1)   NOT NULL DEFAULT 0,
  file_name      VARCHAR(120) NULL,
  original_name  VARCHAR(190) NULL,
  remarks        VARCHAR(160) NULL,
  KEY ix_loan_app_docs_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_ci;
CREATE TABLE loan_ci (
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

DROP TABLE IF EXISTS loan_ci_lines;
CREATE TABLE loan_ci_lines (
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

DROP TABLE IF EXISTS loan_accounts;
CREATE TABLE loan_accounts (
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
  voucher_no         VARCHAR(30)  NULL,                    -- the voucher ID of the release (LR- series)
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
  KEY ix_loan_accounts_app (application_id),
  KEY ix_loan_accounts_voucher (voucher_no),
  KEY ix_loan_accounts_release_txn (release_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_schedule;
CREATE TABLE loan_schedule (
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

DROP TABLE IF EXISTS loan_charges_applied;
CREATE TABLE loan_charges_applied (
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

DROP TABLE IF EXISTS loan_txns;
CREATE TABLE loan_txns (
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
  corrects_id     BIGINT UNSIGNED NULL,      -- an error correction: the loan payment it negates
  corrected_by_id BIGINT UNSIGNED NULL,      -- a payment: the error correction that negated it
  KEY ix_loan_txns_loan (loan_id, txn_date, id),
  KEY ix_loan_txns_txn (txn_id),
  KEY ix_loan_txns_shift (shift_id),
  KEY ix_loan_txns_corrects (corrects_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_payment_alloc;
CREATE TABLE loan_payment_alloc (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_txn_id BIGINT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  principal   DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest    DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_lpa_txn (loan_txn_id),
  KEY ix_lpa_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_waivers;
CREATE TABLE loan_waivers (
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
  class_id        INT UNSIGNED NULL,                        -- the class of the waiver's posting (Class List)
  KEY ix_loan_waivers_loan (loan_id),
  KEY ix_loan_waivers_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS loan_adjustments;
CREATE TABLE loan_adjustments (
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

DROP TABLE IF EXISTS loan_notices;
CREATE TABLE loan_notices (
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

DROP TABLE IF EXISTS teller_shifts;
CREATE TABLE teller_shifts (
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

DROP TABLE IF EXISTS coop_receipts;
CREATE TABLE coop_receipts (
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

DROP TABLE IF EXISTS coop_receipt_lines;
CREATE TABLE coop_receipt_lines (
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

DROP TABLE IF EXISTS dist_periods;
CREATE TABLE dist_periods (
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

DROP TABLE IF EXISTS dist_batches;
CREATE TABLE dist_batches (
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

DROP TABLE IF EXISTS dist_batch_lines;
CREATE TABLE dist_batch_lines (
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

DROP TABLE IF EXISTS coop_officers;
CREATE TABLE coop_officers (
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

DROP TABLE IF EXISTS cda_compliance;
CREATE TABLE cda_compliance (
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

DROP TABLE IF EXISTS cda_calendar;
CREATE TABLE cda_calendar (
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
DROP TABLE IF EXISTS coop_imports;
CREATE TABLE coop_imports (
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

DROP TABLE IF EXISTS cda_report_mappings;
CREATE TABLE cda_report_mappings (
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
--  MULTIPURPOSE: PARTY MASTER, BUSINESS UNITS, DOCUMENT REISSUES
-- ---------------------------------------------------------------------

-- the Party Master: one row per natural or juridical person, whatever roles it holds
DROP TABLE IF EXISTS parties;
CREATE TABLE parties (
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
DROP TABLE IF EXISTS party_crosswalk;
CREATE TABLE party_crosswalk (
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
DROP TABLE IF EXISTS party_role_log;
CREATE TABLE party_role_log (
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
DROP TABLE IF EXISTS party_match_reviews;
CREATE TABLE party_match_reviews (
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
DROP TABLE IF EXISTS business_units;
CREATE TABLE business_units (
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
DROP TABLE IF EXISTS document_reissues;
CREATE TABLE document_reissues (
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
--  2. WATER BILLING TABLES (database/migrations/2026_12_01_water_billing.sql)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `legacy_access_control_pages`;
CREATE TABLE `legacy_access_control_pages` (
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `file_name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `label` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `module_group` varchar(80) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`page_key`) USING BTREE,
  UNIQUE INDEX `uq_access_control_pages_file`(`file_name`) USING BTREE,
  INDEX `idx_access_control_pages_group`(`module_group`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_access_control_preset_pages`;
CREATE TABLE `legacy_access_control_preset_pages` (
  `preset_key` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  PRIMARY KEY (`preset_key`, `page_key`) USING BTREE,
  INDEX `idx_access_control_preset_pages_page`(`page_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_access_control_presets`;
CREATE TABLE `legacy_access_control_presets` (
  `preset_key` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`preset_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_accounts_payable`;
CREATE TABLE `legacy_accounts_payable` (
  `ap_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `purchase_no` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `supplier_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `ap_account_id` int(11) NOT NULL,
  `original_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `purchase_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `status` enum('open','partial','paid','voided') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'open',
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`ap_id`) USING BTREE,
  UNIQUE INDEX `uq_ap_purchase`(`purchase_id`) USING BTREE,
  INDEX `idx_ap_supplier`(`supplier_id`) USING BTREE,
  INDEX `idx_ap_status`(`status`, `due_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_accounts_payable_payments`;
CREATE TABLE `legacy_accounts_payable_payments` (
  `ap_payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ap_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12, 2) NOT NULL,
  `payment_account` int(11) NOT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `request_key` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`ap_payment_id`) USING BTREE,
  UNIQUE INDEX `uq_appay_request_key`(`request_key`) USING BTREE,
  INDEX `idx_appay_ap`(`ap_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `addresses`;
CREATE TABLE `addresses` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NULL DEFAULT NULL,
  `housebldg` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `street` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `other_address_detail` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `zippostal_code` smallint(6) NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_addresses_city_status`(`city_id`, `status`) USING BTREE,
  INDEX `idx_addresses_street`(`street`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table will contain the detailed address';

DROP TABLE IF EXISTS `addresstype`;
CREATE TABLE `addresstype` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(15) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `name_UNIQUE`(`name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'types of address';

DROP TABLE IF EXISTS `water_audit_logs`;
CREATE TABLE `water_audit_logs` (
  `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `idx_audit_user`(`user_id`) USING BTREE,
  INDEX `idx_audit_action`(`action`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `bill_adjustments`;
CREATE TABLE `bill_adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_type` enum('water','one_time','installment','recurring') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `adjustment_type` enum('debit','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('draft','posted','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'posted',
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_bill_adjustments_bill`(`bill_type`, `bill_id`) USING BTREE,
  INDEX `idx_bill_adjustments_date`(`business_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `bill_codes`;
CREATE TABLE `bill_codes` (
  `code_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `type` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `ar_account` int(11) NULL DEFAULT NULL,
  `income_account` int(11) NULL DEFAULT NULL,
  `cogs_account` int(11) NULL DEFAULT NULL,
  `asset_account` int(11) NULL DEFAULT NULL,
  `cost_price` decimal(10, 2) NULL DEFAULT 0.00,
  `sales_price` decimal(10, 2) NULL DEFAULT 0.00,
  `allow_member_discount` tinyint(1) NOT NULL DEFAULT 0,
  `modified_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`code_id`) USING BTREE,
  UNIQUE INDEX `code`(`code`) USING BTREE,
  INDEX `fk_bill_codes_user`(`modified_by`) USING BTREE,
  INDEX `fk_bc_ar`(`ar_account`) USING BTREE,
  INDEX `fk_bc_inc`(`income_account`) USING BTREE,
  INDEX `fk_bc_cogs`(`cogs_account`) USING BTREE,
  INDEX `fk_bc_ast`(`asset_account`) USING BTREE,
  INDEX `idx_bill_codes_allow_discount`(`allow_member_discount`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `bill_periods`;
CREATE TABLE `bill_periods` (
  `period_id` int(10) NOT NULL AUTO_INCREMENT,
  `bp_code` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('open','closed') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'open',
  `opened_by` int(11) NOT NULL,
  `closed_by` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `closed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`period_id`) USING BTREE,
  UNIQUE INDEX `uniq_bp_code`(`bp_code`) USING BTREE,
  INDEX `idx_opened_by`(`opened_by`) USING BTREE,
  INDEX `idx_closed_by`(`closed_by`) USING BTREE,
  INDEX `idx_bill_periods_start_print`(`start_date`, `period_id`) USING BTREE,
  INDEX `idx_bill_periods_date_range`(`start_date`, `end_date`, `status`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `bill_readings`;
CREATE TABLE `bill_readings` (
  `reading_id` int(11) NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `period_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `prev_reading` int(11) NOT NULL DEFAULT 0,
  `pres_reading` int(11) NOT NULL DEFAULT 0,
  `consumption` int(11) NOT NULL DEFAULT 0,
  `is_billed` tinyint(1) NOT NULL DEFAULT 0,
  `billing_date` date NULL DEFAULT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `amount_due` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `snapshot_version` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `rate_code_id_snapshot` int(11) NULL DEFAULT NULL,
  `pipe_size_id_snapshot` int(10) UNSIGNED NULL DEFAULT NULL,
  `pipe_size_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `rate_code_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `tariff_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `discount_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `calculation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `base_charge_snapshot` decimal(14, 2) NULL DEFAULT NULL,
  `discount_amount_snapshot` decimal(14, 2) NULL DEFAULT NULL,
  `snapshot_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `snapshot_created_at` datetime NULL DEFAULT NULL,
  `billing_journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `revision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `is_edited_override` tinyint(1) NOT NULL DEFAULT 0,
  `entry_mode` enum('INDIVIDUAL','BATCH') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'INDIVIDUAL',
  `batch_group_id` int(11) NULL DEFAULT NULL,
  `encoded_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`reading_id`) USING BTREE,
  INDEX `ix_bill_readings_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `uq_reading_period_member`(`period_id`, `member_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_reading`(`global_ref_number`) USING BTREE,
  INDEX `fk_readings_period`(`period_id`) USING BTREE,
  INDEX `fk_readings_member`(`member_id`) USING BTREE,
  INDEX `idx_bill_readings_member_period`(`member_id`, `period_id`, `is_billed`, `is_paid`) USING BTREE,
  INDEX `idx_bill_readings_member_period_print`(`member_id`, `period_id`) USING BTREE,
  INDEX `idx_bill_readings_lifecycle`(`bill_lifecycle_status`, `member_id`, `period_id`) USING BTREE,
  INDEX `idx_bill_readings_snapshot`(`snapshot_version`, `snapshot_hash`) USING BTREE,
  INDEX `idx_water_collectible_balance`(`member_id`, `is_billed`, `is_paid`, `bill_lifecycle_status`, `due_date`) USING BTREE,
  CONSTRAINT `fk_readings_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_readings_period` FOREIGN KEY (`period_id`) REFERENCES `bill_periods` (`period_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `legacy_book_types`;
CREATE TABLE `legacy_book_types` (
  `book_type_id` int(11) NOT NULL,
  `book_type_key` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `book_type_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`book_type_id`) USING BTREE,
  UNIQUE INDEX `book_type_key`(`book_type_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `Deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `name_UNIQUE`(`name`) USING BTREE,
  INDEX `name_idx`(`name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `bussinessprofile`;
CREATE TABLE `bussinessprofile` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `address1` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `address2` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `logoName` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `logoImage` mediumblob NULL,
  `telephoneNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `faxNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `cellularNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `sssNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `pagibigNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `philhealthNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `tinNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `website` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `IsBranch` tinyint(4) NOT NULL DEFAULT 0,
  `parentBusProfID` int(11) NOT NULL DEFAULT 0,
  `lockProfile` tinyint(4) NULL DEFAULT NULL,
  `lockDate` datetime NULL DEFAULT NULL,
  `lockPassword` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateCreated` datetime NOT NULL,
  `createdBy` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `modifiedBy` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table describes the company information';

DROP TABLE IF EXISTS `legacy_chartofaccntstbl`;
CREATE TABLE `legacy_chartofaccntstbl` (
  `AccntID` int(11) NOT NULL,
  `AccntName` varchar(45) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `BaseAccnt` int(11) NULL DEFAULT NULL,
  `ParentAccnt` int(11) NULL DEFAULT NULL,
  `SummaryAccnt` varchar(45) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `Type` int(11) NOT NULL,
  `Description` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `WithSL` tinyint(1) NULL DEFAULT 0,
  `CashFlowType` int(11) NOT NULL DEFAULT 0,
  `CreateTime` datetime NOT NULL,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`AccntID`) USING BTREE,
  INDEX `ParentAccntIdx`(`ParentAccnt`, `CashFlowType`) USING BTREE,
  INDEX `BaseAccntIdx`(`BaseAccnt`, `ParentAccnt`, `CashFlowType`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

DROP TABLE IF EXISTS `cities`;
CREATE TABLE `cities` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `city` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `province` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `country` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_cities_status_city`(`status`, `city`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table will hold the address information';

DROP TABLE IF EXISTS `companyofficers`;
CREATE TABLE `companyofficers` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `busProfID` int(11) NOT NULL,
  `name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `position` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `contactNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateCreated` datetime NOT NULL,
  `createdBy` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `modifiedBy` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `contactchannels`;
CREATE TABLE `contactchannels` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_contactchannels_status_name`(`status`, `name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'table that stores mode of contact';

DROP TABLE IF EXISTS `data_import_batches`;
CREATE TABLE `data_import_batches` (
  `batch_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `stored_file_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staged',
  `options_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `row_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `started_at` datetime NULL DEFAULT NULL,
  `completed_at` datetime NULL DEFAULT NULL,
  `last_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `stage_next_row` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `mapping_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `mapping_version` int(11) NOT NULL DEFAULT 0,
  `reconciliation_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  PRIMARY KEY (`batch_id`) USING BTREE,
  INDEX `idx_data_import_batch_status`(`status`, `created_at`) USING BTREE,
  INDEX `idx_data_import_batch_hash`(`source_sha256`, `import_type`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `data_import_mapping_profiles`;
CREATE TABLE `data_import_mapping_profiles` (
  `profile_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_name` varchar(80) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `import_type` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'any',
  `version` int(11) NOT NULL DEFAULT 1,
  `options_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`profile_id`) USING BTREE,
  UNIQUE INDEX `uq_import_profile_version`(`profile_name`, `import_type`, `version`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `data_import_mapping_rules`;
CREATE TABLE `data_import_mapping_rules` (
  `rule_key` varchar(60) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `rule_value` varchar(60) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unresolved',
  `notes` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `confirmed_by` int(11) NULL DEFAULT NULL,
  `confirmed_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`rule_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `data_import_rows`;
CREATE TABLE `data_import_rows` (
  `row_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `row_number` int(10) UNSIGNED NOT NULL,
  `external_account_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `raw_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issues_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `import_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'ready',
  `payment_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'not_applicable',
  `recurring_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'not_applicable',
  `target_member_id` int(11) NULL DEFAULT NULL,
  `target_bill_id` int(11) NULL DEFAULT NULL,
  `target_payment_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `target_recurring_ids_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `imported_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`row_id`) USING BTREE,
  UNIQUE INDEX `uq_data_import_batch_row`(`batch_id`, `row_number`) USING BTREE,
  INDEX `idx_data_import_primary_queue`(`batch_id`, `import_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_payment_queue`(`batch_id`, `payment_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_recurring_queue`(`batch_id`, `recurring_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_account`(`external_account_no`) USING BTREE,
  INDEX `idx_data_import_source_key`(`source_key`) USING BTREE,
  CONSTRAINT `fk_data_import_rows_batch` FOREIGN KEY (`batch_id`) REFERENCES `data_import_batches` (`batch_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `discount_privilege_rules`;
CREATE TABLE `discount_privilege_rules` (
  `discount_rule_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_id` int(10) UNSIGNED NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `module_context` enum('all','water','one_time','installment','recurring') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'all',
  `discount_method` enum('percent','fixed','free_cubic','none') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `max_discount_amount` decimal(10, 2) NULL DEFAULT NULL,
  `max_discount_cubic` int(10) UNSIGNED NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`discount_rule_id`) USING BTREE,
  UNIQUE INDEX `uq_discount_privilege_rule`(`discount_id`, `bill_code_id`, `module_context`) USING BTREE,
  INDEX `idx_dpr_discount`(`discount_id`) USING BTREE,
  INDEX `idx_dpr_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_dpr_active_module`(`is_active`, `module_context`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `discounted_members`;
CREATE TABLE `discounted_members` (
  `dm_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(10) NOT NULL,
  `discount_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`dm_id`) USING BTREE,
  UNIQUE INDEX `uq_discounted_member_discount`(`member_id`, `discount_id`) USING BTREE,
  INDEX `fk_discounted_members_discount`(`discount_id`) USING BTREE,
  INDEX `idx_discounted_members_member_discount`(`member_id`, `discount_id`) USING BTREE,
  CONSTRAINT `fk_discounted_members_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_discounted_members_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `discounts`;
CREATE TABLE `discounts` (
  `discount_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_type` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `water_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `water_discount_method` enum('none','percent','fixed','free_cubic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `water_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `water_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `water_max_discount_cubic` int(10) UNSIGNED NULL DEFAULT NULL,
  `one_time_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `one_time_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `one_time_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `one_time_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `installment_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `installment_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `installment_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `installment_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `recurring_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `recurring_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `recurring_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `recurring_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`discount_id`) USING BTREE,
  UNIQUE INDEX `uq_discount_type`(`discount_type`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `global_billing_sequence`;
CREATE TABLE `global_billing_sequence` (
  `ref_number` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ref_number`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ic_divisions`;
CREATE TABLE `ic_divisions` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `ic_segments`;
CREATE TABLE `ic_segments` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `installment_bills`;
CREATE TABLE `installment_bills` (
  `installment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 1.00,
  `inventory_unit_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `inventory_total_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('monthly','quarterly','semi_annually','annually') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(14, 2) NOT NULL,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `term` int(11) NOT NULL,
  `amortization_type` enum('fixed','flexible') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `status` enum('active','completed','cancelled','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `revision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`installment_id`) USING BTREE,
  INDEX `ix_installment_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_installment`(`global_ref_number`) USING BTREE,
  INDEX `fk_installment_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_inst_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_installment_member_status`(`member_id`, `status`) USING BTREE,
  INDEX `idx_installment_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_installment_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_installment_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `installment_schedules`;
CREATE TABLE `installment_schedules` (
  `schedule_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `installment_id` int(10) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'pending',
  `paid_at` datetime NULL DEFAULT NULL,
  `payment_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `marked_by` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  PRIMARY KEY (`schedule_id`) USING BTREE,
  UNIQUE INDEX `uq_installment_due_date`(`installment_id`, `due_date`) USING BTREE,
  INDEX `idx_installment_schedules_installment_status_due`(`installment_id`, `status`, `due_date`) USING BTREE,
  INDEX `fk_installment_marked_operator`(`marked_by`) USING BTREE,
  CONSTRAINT `fk_installment_schedule` FOREIGN KEY (`installment_id`) REFERENCES `installment_bills` (`installment_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `legacy_inventory_adjustments`;
CREATE TABLE `legacy_inventory_adjustments` (
  `adjustment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` int(10) UNSIGNED NOT NULL,
  `adjustment_date` date NOT NULL,
  `adjustment_type` enum('IN','OUT') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_inv_adj_code`(`code_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `inventory_issuance_items`;
CREATE TABLE `inventory_issuance_items` (
  `issuance_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id` int(10) UNSIGNED NOT NULL,
  `code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `asset_account` int(11) NOT NULL,
  PRIMARY KEY (`issuance_item_id`) USING BTREE,
  UNIQUE INDEX `uq_issuance_item`(`issuance_id`, `code_id`) USING BTREE,
  INDEX `idx_issuance_item_code`(`code_id`) USING BTREE,
  INDEX `idx_issuance_item_asset`(`asset_account`) USING BTREE,
  CONSTRAINT `fk_issuance_item_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_issuance_item_header` FOREIGN KEY (`issuance_id`) REFERENCES `inventory_issuances` (`issuance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `inventory_issuances`;
CREATE TABLE `inventory_issuances` (
  `issuance_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `issuance_date` date NOT NULL,
  `recipient` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `department` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversal_journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversal_journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('POSTED','REVERSED') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'POSTED',
  `reversal_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversed_at` datetime NULL DEFAULT NULL,
  `reversed_by` int(11) NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`issuance_id`) USING BTREE,
  UNIQUE INDEX `uq_inventory_issuance_no`(`issuance_no`) USING BTREE,
  INDEX `idx_inventory_issuance_date`(`issuance_date`) USING BTREE,
  INDEX `idx_inventory_issuance_status`(`status`) USING BTREE,
  INDEX `idx_inventory_issuance_expense`(`expense_account_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `legacy_inventory_purchase_items`;
CREATE TABLE `legacy_inventory_purchase_items` (
  `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` int(10) UNSIGNED NOT NULL,
  `code_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `asset_account` int(11) NOT NULL,
  PRIMARY KEY (`item_id`) USING BTREE,
  INDEX `idx_purchase_item_purchase`(`purchase_id`) USING BTREE,
  INDEX `idx_purchase_item_code`(`code_id`) USING BTREE,
  INDEX `idx_purchase_item_asset`(`asset_account`) USING BTREE,
  CONSTRAINT `fk_purchase_item_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_inventory_purchases`;
CREATE TABLE `legacy_inventory_purchases` (
  `purchase_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_no` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `supplier_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `payment_type` enum('Cash','Credit') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Cash',
  `payment_account` int(11) NOT NULL,
  `remarks` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `total_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `void_journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `void_journal_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` enum('Posted','Voided') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Posted',
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`purchase_id`) USING BTREE,
  UNIQUE INDEX `uq_purchase_no`(`purchase_no`) USING BTREE,
  INDEX `idx_purchase_supplier`(`supplier_id`) USING BTREE,
  INDEX `idx_purchase_payment_account`(`payment_account`) USING BTREE,
  INDEX `idx_purchase_journal`(`journal_head_id`, `journal_entry_id`) USING BTREE,
  INDEX `idx_purchase_void_journal`(`void_journal_head_id`, `void_journal_entry_id`) USING BTREE,
  INDEX `idx_purchase_created_by`(`created_by`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_inventory_reorder_levels`;
CREATE TABLE `legacy_inventory_reorder_levels` (
  `code_id` int(10) UNSIGNED NOT NULL,
  `reorder_level` decimal(12, 2) NOT NULL DEFAULT 5.00,
  `updated_by` int(11) NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`code_id`) USING BTREE,
  CONSTRAINT `fk_reorder_bill_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `legacy_inventory_stock_movements`;
CREATE TABLE `legacy_inventory_stock_movements` (
  `movement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` int(10) UNSIGNED NOT NULL,
  `ref_type` enum('PURCHASE','VOID_PURCHASE','SALE','ADJUSTMENT','ISSUANCE','VOID_ISSUANCE') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `ref_id` int(10) UNSIGNED NOT NULL,
  `movement_date` date NOT NULL,
  `qty_in` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `qty_out` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `movement_value` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`movement_id`) USING BTREE,
  INDEX `idx_stock_code`(`code_id`) USING BTREE,
  INDEX `idx_stock_ref`(`ref_type`, `ref_id`) USING BTREE,
  INDEX `idx_stock_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_stock_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `jointmember`;
CREATE TABLE `jointmember` (
  `pkey` int(11) NOT NULL,
  `Name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `accountno` int(11) NULL DEFAULT NULL,
  `Status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_journal_voucher_voids`;
CREATE TABLE `legacy_journal_voucher_voids` (
  `void_id` int(11) NOT NULL AUTO_INCREMENT,
  `original_jvid` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `original_jv_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `replacement_jvid` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `replacement_jv_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `trans_ref_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `source_type` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'bill_edit',
  `source_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `voided_amount` decimal(20, 6) NOT NULL DEFAULT 0.000000,
  `voided_line_count` int(11) NOT NULL DEFAULT 0,
  `voided_by` int(11) NULL DEFAULT NULL,
  `voided_at` datetime NOT NULL,
  PRIMARY KEY (`void_id`) USING BTREE,
  UNIQUE INDEX `uq_jv_void_original`(`original_jvid`) USING BTREE,
  INDEX `idx_jv_void_replacement`(`replacement_jvid`) USING BTREE,
  INDEX `idx_jv_void_source`(`source_type`, `source_id`) USING BTREE,
  INDEX `idx_jv_void_ref`(`trans_ref_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_journalentries`;
CREATE TABLE `legacy_journalentries` (
  `JournalID` int(11) NOT NULL AUTO_INCREMENT,
  `JournalHeadID` varchar(37) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `TransRefID` varchar(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `AccountID` int(11) NOT NULL,
  `Debit` decimal(20, 6) NULL DEFAULT 0.000000,
  `Credit` decimal(20, 6) NULL DEFAULT 0.000000,
  `Description` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `SLType` int(11) NULL DEFAULT NULL,
  `SLCode` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `BookType` int(11) NULL DEFAULT NULL,
  `IsJournalHead` tinyint(1) NOT NULL DEFAULT 0,
  `TransactionDate` datetime NULL DEFAULT NULL,
  `IsPosted` tinyint(1) NULL DEFAULT 0,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  `entryType` enum('NORMAL','CLOSING') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'NORMAL',
  `BranchID` int(11) NULL DEFAULT NULL,
  `SegmentID` int(11) NULL DEFAULT NULL,
  `DivisionID` int(11) NULL DEFAULT NULL,
  PRIMARY KEY (`JournalID`, `JournalHeadID`) USING BTREE,
  INDEX `DateIdx`(`TransactionDate`) USING BTREE,
  INDEX `AccountsIdx`(`AccountID`, `TransactionDate`, `JournalHeadID`) USING BTREE,
  INDEX `SubsidiaryIdx`(`SLType`, `SLCode`, `TransactionDate`, `AccountID`) USING BTREE,
  INDEX `TransRefIdx`(`TransRefID`, `Status`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

DROP TABLE IF EXISTS `legacy_journalvoucher`;
CREATE TABLE `legacy_journalvoucher` (
  `JVID` varchar(37) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `JVEntryID` varchar(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `JVTransDate` datetime NOT NULL COMMENT 'Journal Date',
  `JVEntryDate` datetime NOT NULL COMMENT 'Date the journal was entered or created',
  `JVDateLastUpdated` datetime NOT NULL,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  `BranchID` tinyint(4) NULL DEFAULT NULL,
  `SegmentID` tinyint(4) NULL DEFAULT NULL,
  `DivisionID` tinyint(4) NULL DEFAULT NULL,
  PRIMARY KEY (`JVID`) USING BTREE,
  UNIQUE INDEX `JournalEntryID_UNIQUE`(`JVEntryID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

DROP TABLE IF EXISTS `manual_disconnection_logs`;
CREATE TABLE `manual_disconnection_logs` (
  `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bill_source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `bill_source_id` int(11) NULL DEFAULT NULL,
  `amount_due` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `due_date` date NULL DEFAULT NULL,
  `disconnection_date` date NULL DEFAULT NULL,
  `previous_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `new_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `action_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `action_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `idx_mdl_member`(`member_id`) USING BTREE,
  INDEX `idx_mdl_action`(`action`) USING BTREE,
  INDEX `idx_mdl_source`(`bill_source_type`, `bill_source_id`) USING BTREE,
  INDEX `idx_mdl_action_at`(`action_at`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `memberaddress`;
CREATE TABLE `memberaddress` (
  `member_key` int(11) NOT NULL,
  `address_key` int(11) NOT NULL,
  `dtaddressFrom` date NOT NULL,
  `address_type_key` int(11) NULL DEFAULT NULL,
  `dtaddressto` date NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`member_key`, `address_key`, `dtaddressFrom`) USING BTREE,
  INDEX `FK_Member_ID_idx`(`member_key`) USING BTREE,
  INDEX `FK_Address_ID_idx`(`address_key`) USING BTREE,
  INDEX `FK_Address_Type_idx`(`address_type_key`) USING BTREE,
  INDEX `idx_memberaddress_status_type_to`(`member_key`, `status`, `address_type_key`, `dtaddressto`) USING BTREE,
  INDEX `idx_memberaddress_member_status_type`(`member_key`, `status`, `address_type_key`) USING BTREE,
  CONSTRAINT `FK_Address_ID` FOREIGN KEY (`address_key`) REFERENCES `addresses` (`pkey`) ON DELETE RESTRICT ON UPDATE NO ACTION,
  CONSTRAINT `FK_Address_Type` FOREIGN KEY (`address_type_key`) REFERENCES `addresstype` (`pkey`) ON DELETE RESTRICT ON UPDATE NO ACTION
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this holds the member address information';

DROP TABLE IF EXISTS `membercontactchannels`;
CREATE TABLE `membercontactchannels` (
  `conchannelkey` int(11) NOT NULL,
  `memberkey` int(11) NOT NULL,
  `activefromdate` date NOT NULL,
  `activetodate` date NULL DEFAULT NULL,
  `contactdata` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`activefromdate`, `memberkey`, `conchannelkey`) USING BTREE,
  INDEX `idx_mcc_member_status_to`(`memberkey`, `status`, `activetodate`) USING BTREE,
  INDEX `idx_mcc_contactdata`(`contactdata`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table store member contact information';

DROP TABLE IF EXISTS `water_accounts`;
CREATE TABLE `water_accounts` (
  `pkey` int(11) NOT NULL,
  `salutation` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `lastname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `firstname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `middlename` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateofbirth` date NULL DEFAULT NULL,
  `placeofbirth` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `tinno` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `gender` enum('Male','Female') CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `civilstatus` enum('Single','Married','Widow','Widower','Separated') CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `education` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `courselevel` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `yeargraduated` year(4) NULL DEFAULT NULL,
  `occupation` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `affiliatedOrg` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `datecreated` datetime NULL DEFAULT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  `account_number` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `zone` int(11) NULL DEFAULT NULL,
  `meter_sequence` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `rc_id` int(11) NULL DEFAULT NULL,
  `pipe_size_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `wmdiscount_id` int(11) NULL DEFAULT NULL,
  `connection_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `disconnected_at` datetime NULL DEFAULT NULL,
  `disconnected_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `disconnection_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `reconnected_at` datetime NULL DEFAULT NULL,
  `reconnected_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `reconnection_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `address_subgroup` varchar(60) NULL DEFAULT NULL,
  `connection_date` date NULL DEFAULT NULL COMMENT 'the date the connection started',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `ix_water_accounts_subgroup`(`address_subgroup`) USING BTREE,
  UNIQUE INDEX `account_number`(`account_number`) USING BTREE,
  INDEX `idx_members_rc_status`(`rc_id`, `status`) USING BTREE,
  INDEX `idx_members_status_name`(`status`, `lastname`, `firstname`) USING BTREE,
  INDEX `idx_members_zone`(`zone`) USING BTREE,
  INDEX `idx_members_gender`(`gender`) USING BTREE,
  INDEX `idx_members_civilstatus`(`civilstatus`) USING BTREE,
  INDEX `idx_members_status_zone_name`(`status`, `zone`, `lastname`, `firstname`) USING BTREE,
  INDEX `idx_members_meter_sequence`(`meter_sequence`) USING BTREE,
  INDEX `idx_members_pipe_size`(`pipe_size_id`) USING BTREE,
  CONSTRAINT `fk_members_pipe_size` FOREIGN KEY (`pipe_size_id`) REFERENCES `water_pipe_sizes` (`pipe_size_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_members_rate_code` FOREIGN KEY (`rc_id`) REFERENCES `rate_code` (`rc_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table hold information of members.';

DROP TABLE IF EXISTS `membershipinfo`;
CREATE TABLE `membershipinfo` (
  `memberID` int(11) NOT NULL,
  `membershipDate` date NOT NULL,
  `membershipStatus` tinyint(4) NOT NULL,
  `membershipType` tinyint(4) NOT NULL,
  `clusterKey` int(11) NULL DEFAULT NULL,
  `segmentationKey` int(11) NULL DEFAULT NULL,
  `pmesDate` date NULL DEFAULT NULL,
  `passbook` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateCreated` datetime NULL DEFAULT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `createdBy` varchar(36) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `modifiedBy` varchar(36) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  `memberName` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`memberID`) USING BTREE,
  UNIQUE INDEX `passbook_UNIQUE`(`passbook`) USING BTREE,
  INDEX `cluster_idx`(`clusterKey`, `status`) USING BTREE,
  INDEX `segment_idx`(`segmentationKey`, `status`) USING BTREE,
  INDEX `status_idx`(`status`) USING BTREE,
  INDEX `membership_idx`(`membershipDate`, `membershipStatus`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `one_time_bills`;
CREATE TABLE `one_time_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 1.00,
  `inventory_unit_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `inventory_total_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(14, 2) NOT NULL,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','paid','cancelled','deleted') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unpaid',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `revision_note` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `source_type` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `source_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `source_billing_date` date NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  INDEX `ix_one_time_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_onetime`(`global_ref_number`) USING BTREE,
  UNIQUE INDEX `uq_one_time_recurring_source`(`source_type`, `source_id`, `source_billing_date`) USING BTREE,
  INDEX `member_id`(`member_id`) USING BTREE,
  INDEX `bill_code_id`(`bill_code_id`) USING BTREE,
  INDEX `created_by`(`created_by`) USING BTREE,
  INDEX `idx_otb_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_one_time_member_status_due`(`member_id`, `status`, `due_date`) USING BTREE,
  INDEX `idx_one_time_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_one_time_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_one_time_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_operator`;
CREATE TABLE `legacy_operator` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `accesslevel` tinyint(4) NOT NULL,
  `description` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `relatedkey` int(11) NULL DEFAULT NULL,
  `isSystem` tinyint(4) NOT NULL DEFAULT 0,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `operator_notification_reads`;
CREATE TABLE `operator_notification_reads` (
  `operator_id` int(11) NOT NULL,
  `notification_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`operator_id`, `notification_key`) USING BTREE,
  INDEX `idx_notification_read_at`(`read_at`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `legacy_operator_profile`;
CREATE TABLE `legacy_operator_profile` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `operatorID` int(11) NOT NULL,
  `profID` int(11) NOT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `uq_operator_profile_assignment`(`operatorID`, `profID`) USING BTREE,
  INDEX `operator_idx`(`operatorID`) USING BTREE,
  INDEX `profile_idx`(`profID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_operator_profile_page_permissions`;
CREATE TABLE `legacy_operator_profile_page_permissions` (
  `operator_profile_id` int(11) NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by_operator_id` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`operator_profile_id`, `page_key`) USING BTREE,
  INDEX `idx_opp_page`(`page_key`) USING BTREE,
  INDEX `idx_opp_granted_by`(`granted_by_operator_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `payment_adjustments`;
CREATE TABLE `payment_adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `adjustment_type` enum('reversal','refund') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('pending','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_payment_adjustments_transaction`(`transaction_id`) USING BTREE,
  CONSTRAINT `fk_payment_adjustment_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `payment_transaction_items`;
CREATE TABLE `payment_transaction_items` (
  `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('water','installment','onetime','recurring','sharecap') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `due_date` date NULL DEFAULT NULL,
  `amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `principal_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance_before` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `allocation_status` enum('active','reversed','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`item_id`) USING BTREE,
  UNIQUE INDEX `uq_payment_transaction_source`(`transaction_id`, `source_type`, `source_id`) USING BTREE,
  INDEX `idx_pti_transaction`(`transaction_id`) USING BTREE,
  INDEX `idx_pti_source`(`source_type`, `source_id`) USING BTREE,
  INDEX `idx_pti_active_source`(`source_type`, `source_id`, `allocation_status`) USING BTREE,
  CONSTRAINT `fk_pti_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_transactions`;
CREATE TABLE `payment_transactions` (
  `transaction_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` int(11) NOT NULL,
  `payment_type` enum('water','installment','onetime','recurring','mixed','sharecap') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `compromise_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `share_capital_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `tendered_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `receipt_print_count` int(11) NOT NULL DEFAULT 0,
  `last_printed_at` datetime NULL DEFAULT NULL,
  `cashier_id` int(10) UNSIGNED NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `business_date` date NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `transaction_status` enum('active','reversed','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `idempotency_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_status` enum('draft','posted','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `reversed_at` datetime NULL DEFAULT NULL,
  `reversed_by` int(11) NULL DEFAULT NULL,
  `reversal_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `reversal_journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `cash_tendered` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`transaction_id`) USING BTREE,
  INDEX `ix_payment_transactions_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_receipt_no`(`receipt_no`) USING BTREE,
  UNIQUE INDEX `uq_payment_idempotency`(`idempotency_key`) USING BTREE,
  INDEX `idx_member`(`member_id`) USING BTREE,
  INDEX `idx_cashier`(`cashier_id`) USING BTREE,
  INDEX `idx_payment_transactions_receipt`(`receipt_no`) USING BTREE,
  INDEX `idx_payment_transactions_date`(`payment_date`) USING BTREE,
  INDEX `idx_payment_transactions_member_date`(`member_id`, `payment_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `legacy_postingmethod`;
CREATE TABLE `legacy_postingmethod` (
  `pkey` int(11) NOT NULL,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table holds the records of all postings types';

DROP TABLE IF EXISTS `legacy_profile`;
CREATE TABLE `legacy_profile` (
  `profID` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `typeid` int(11) NOT NULL,
  `groupid` int(11) NOT NULL,
  `cashGL` int(11) NOT NULL DEFAULT 0,
  `issystem` tinyint(4) NOT NULL DEFAULT 0,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`profID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `rate_code`;
CREATE TABLE `rate_code` (
  `rc_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_code` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `rc_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `billing_method` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'straight',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rc_id`) USING BTREE,
  INDEX `idx_rate_code_code`(`rc_code`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `recurring_bill_payments`;
CREATE TABLE `recurring_bill_payments` (
  `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`payment_id`) USING BTREE,
  INDEX `idx_recurring_payment_bill`(`bill_id`) USING BTREE,
  INDEX `idx_recurring_payment_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_payment_bill` FOREIGN KEY (`bill_id`) REFERENCES `recurring_bills` (`bill_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `recurring_bills`;
CREATE TABLE `recurring_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `subscription_id` int(10) UNSIGNED NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `billing_period_start` date NOT NULL,
  `billing_period_end` date NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NOT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `total_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','paid','overdue','cancelled','deleted') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unpaid',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `revision_note` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  INDEX `ix_recurring_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `uniq_recurring_subscription_bill_date`(`subscription_id`, `bill_date`) USING BTREE,
  UNIQUE INDEX `uniq_recurring_global_ref`(`global_ref_number`) USING BTREE,
  INDEX `idx_recurring_bill_subscription`(`subscription_id`) USING BTREE,
  INDEX `idx_recurring_bill_plan`(`plan_id`) USING BTREE,
  INDEX `idx_recurring_bill_member`(`member_id`) USING BTREE,
  INDEX `idx_recurring_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_recurring_bill_due_status`(`status`, `due_date`) USING BTREE,
  INDEX `idx_recurring_bill_created_by`(`created_by`) USING BTREE,
  INDEX `idx_recur_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_recurring_bills_member_status_due`(`member_id`, `status`, `due_date`) USING BTREE,
  INDEX `idx_recurring_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_recurring_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_plan` FOREIGN KEY (`plan_id`) REFERENCES `recurring_plans` (`plan_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `recurring_subscriptions` (`subscription_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `recurring_plans`;
CREATE TABLE `recurring_plans` (
  `plan_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `frequency` enum('Monthly','Quarterly','Semi-Annually','Yearly') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Monthly',
  `first_billing_date` date NOT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `status` enum('active','inactive') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`plan_id`) USING BTREE,
  INDEX `idx_recurring_plan_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_recurring_plan_status`(`status`) USING BTREE,
  INDEX `idx_recurring_plan_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_plan_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `recurring_subscriptions`;
CREATE TABLE `recurring_subscriptions` (
  `subscription_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(11) NOT NULL,
  `subscription_start_date` date NOT NULL,
  `next_billing_date` date NOT NULL,
  `last_billed_date` date NULL DEFAULT NULL,
  `status` enum('active','paused','ended') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`subscription_id`) USING BTREE,
  INDEX `idx_recurring_sub_plan`(`plan_id`) USING BTREE,
  INDEX `idx_recurring_sub_member`(`member_id`) USING BTREE,
  INDEX `idx_recurring_sub_due`(`status`, `next_billing_date`) USING BTREE,
  INDEX `idx_recurring_sub_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_sub_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `recurring_plans` (`plan_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `setup_tutorial_progress`;
CREATE TABLE `setup_tutorial_progress` (
  `operator_id` int(11) NOT NULL,
  `operator_profile_id` int(11) NOT NULL DEFAULT 0,
  `requirement_key` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `state` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `first_seen_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`operator_id`, `requirement_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_subsidiaryledger`;
CREATE TABLE `legacy_subsidiaryledger` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `slcode` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `sltype` tinyint(4) NOT NULL,
  `slname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `pkey_UNIQUE`(`pkey`) USING BTREE,
  INDEX `slcode_type_idx`(`slcode`, `sltype`, `isdeleted`) USING BTREE,
  INDEX `slName_idx`(`slname`, `isdeleted`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_suppliers`;
CREATE TABLE `legacy_suppliers` (
  `supplier_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `contact_person` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `contact_number` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `address` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `status` enum('Active','Inactive') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`supplier_id`) USING BTREE,
  UNIQUE INDEX `uq_supplier_name`(`supplier_name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `share_capital_plans`;
CREATE TABLE `share_capital_plans` (
  `plan_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(10) UNSIGNED NOT NULL,
  `sav_account_id` int(10) UNSIGNED NOT NULL,
  `frequency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `posting_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `batch_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `next_bill_date` date NULL DEFAULT NULL,
  `last_bill_date` date NULL DEFAULT NULL,
  `hold_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `held_at` datetime NULL DEFAULT NULL,
  `held_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `delete_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`plan_id`) USING BTREE,
  INDEX `ix_scp_member`(`member_id`) USING BTREE,
  INDEX `ix_scp_account`(`sav_account_id`) USING BTREE,
  INDEX `ix_scp_due`(`status`, `next_bill_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `share_capital_bills`;
CREATE TABLE `share_capital_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `sav_account_id` int(10) UNSIGNED NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime NULL DEFAULT NULL,
  `cancelled_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  UNIQUE INDEX `uq_scb_plan_date`(`plan_id`, `bill_date`) USING BTREE,
  INDEX `ix_scb_member_status`(`member_id`, `status`) USING BTREE,
  INDEX `ix_scb_date`(`bill_date`) USING BTREE,
  INDEX `ix_scb_due`(`status`, `due_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `share_capital_collections`;
CREATE TABLE `share_capital_collections` (
  `collection_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `receipt_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `sav_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `ledger_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `trans_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `reversal_sav_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `reversal_ledger_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `reversed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`collection_id`) USING BTREE,
  INDEX `ix_scc_bill`(`bill_id`) USING BTREE,
  INDEX `ix_scc_transaction`(`transaction_id`) USING BTREE,
  INDEX `ix_scc_receipt`(`receipt_no`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `water_balance_uploads`;
CREATE TABLE `water_balance_uploads` (
  `upload_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `line_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`upload_id`),
  KEY `ix_wbu_created` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `water_balance_upload_lines`;
CREATE TABLE `water_balance_upload_lines` (
  `line_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_id` int(10) UNSIGNED NOT NULL,
  `line_no` int(10) UNSIGNED NOT NULL,
  `file_row` int(10) UNSIGNED NULL DEFAULT NULL,
  `billing_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `water_account_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `sav_account_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `installments` smallint(5) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`line_id`),
  KEY `ix_wbul_upload` (`upload_id`, `line_no`),
  KEY `ix_wbul_account` (`water_account_id`, `billing_type`, `bill_date`),
  KEY `ix_wbul_bill` (`billing_type`, `bill_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `syssequence`;
CREATE TABLE `syssequence` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT COMMENT 'primary key for the sequence',
  `SeqName` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'Name of the sequence',
  `SeqDesc` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'detailed description for which this sequence applies',
  `SeqType` int(11) NOT NULL COMMENT 'numerice or alpha numeric sequence',
  `SeqPrefixCode` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL COMMENT 'Prefix code for the sequence number',
  `SeqCurValue` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'current value for the sequence',
  `SeqStep` int(11) NOT NULL COMMENT 'number of step per iteration',
  `SeqStatus` varchar(2) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A' COMMENT 'A-Active or I-Inactive',
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `SeqName_UNIQUE`(`SeqName`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table holds the sequence numbering for transaction and records';

DROP TABLE IF EXISTS `water_billing_settings`;
CREATE TABLE `water_billing_settings` (
  `setting_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `water_meter_discounted_members`;
CREATE TABLE `water_meter_discounted_members` (
  `wmdm_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `wmdiscount_id` int(11) NOT NULL,
  `assigned_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`wmdm_id`) USING BTREE,
  UNIQUE INDEX `unique_member_discount`(`member_id`) USING BTREE,
  UNIQUE INDEX `uq_water_discount_member`(`member_id`) USING BTREE,
  INDEX `fk_wmdm_discount`(`wmdiscount_id`) USING BTREE,
  CONSTRAINT `fk_wmdm_discount` FOREIGN KEY (`wmdiscount_id`) REFERENCES `water_meter_discounts` (`wmdiscount_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_wmdm_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `water_meter_discounts`;
CREATE TABLE `water_meter_discounts` (
  `wmdiscount_id` int(11) NOT NULL AUTO_INCREMENT,
  `wmd_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'e.g., Senior Citizen, Board of Directors, Secretary',
  `free_water_m3` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `percent_discount` decimal(7, 4) NOT NULL DEFAULT 0.0000,
  `fixed_discount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `active_discount` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'none',
  `max_m3_for_discount` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`wmdiscount_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `water_meter_members`;
CREATE TABLE `water_meter_members` (
  `wmm_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_id` int(11) NOT NULL COMMENT 'Links to rate_code (Member Type)',
  `pipe_size_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `bill_code_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `from_cb` int(11) NOT NULL,
  `to_cb` int(11) NULL DEFAULT NULL COMMENT 'NULL means Infinity',
  `amount` decimal(14, 4) NOT NULL,
  `bill_type` enum('FIXED','VARIABLE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'VARIABLE',
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`wmm_id`) USING BTREE,
  UNIQUE INDEX `uq_water_rate_pipe_start`(`rc_id`, `pipe_size_id`, `from_cb`) USING BTREE,
  INDEX `idx_wmm_bill_code_id`(`bill_code_id`) USING BTREE,
  INDEX `idx_wmm_rc_pipe_range`(`rc_id`, `pipe_size_id`, `from_cb`, `to_cb`) USING BTREE,
  INDEX `fk_wmm_pipe_size`(`pipe_size_id`) USING BTREE,
  CONSTRAINT `fk_wmm_pipe_size` FOREIGN KEY (`pipe_size_id`) REFERENCES `water_pipe_sizes` (`pipe_size_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `water_pipe_sizes`;
CREATE TABLE `water_pipe_sizes` (
  `pipe_size_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `size_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `size_label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `diameter_inches` decimal(8, 4) NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`pipe_size_id`) USING BTREE,
  UNIQUE INDEX `uq_water_pipe_size_code`(`size_code`) USING BTREE,
  UNIQUE INDEX `uq_water_pipe_size_label`(`size_label`) USING BTREE,
  INDEX `idx_water_pipe_size_active_diameter`(`is_active`, `diameter_inches`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

DROP TABLE IF EXISTS `water_rates`;
CREATE TABLE `water_rates` (
  `rate_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_id` int(11) NOT NULL DEFAULT 1,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `from_cb` int(11) NOT NULL,
  `to_cb` int(11) NULL DEFAULT NULL COMMENT 'NULL signifies Unlimited / Infinity',
  `amount` decimal(10, 2) NOT NULL,
  `bill_type` enum('FIXED','VARIABLE') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'VARIABLE',
  `free_m3_water_rate` int(11) NOT NULL DEFAULT 0,
  `percent_discount_water_rate` decimal(5, 2) NOT NULL DEFAULT 0.00,
  `fixed_discount_water_rate` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `max_m3_water_rate` int(11) NULL DEFAULT NULL,
  `active_discount_type` enum('percent','fixed','none') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'none',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`rate_id`) USING BTREE,
  INDEX `fk_wr_discount`(`discount_id`) USING BTREE,
  CONSTRAINT `fk_wr_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

DROP TABLE IF EXISTS `legacy_savingsaccounts`;
CREATE TABLE `legacy_savingsaccounts` (
  `accountno` bigint(20) NOT NULL AUTO_INCREMENT,
  `memberid` int(11) NOT NULL,
  `savingsid` int(11) NOT NULL,
  `branchid` int(11) NOT NULL,
  `unitsplan` int(11) DEFAULT NULL,
  `ammountperunit` decimal(15,10) DEFAULT NULL,
  `maturitydate` date DEFAULT NULL,
  `applicationdate` date NOT NULL,
  `checkedby` int(11) DEFAULT NULL COMMENT 'user ID of the teller who included the savings account',
  `approvedby` int(11) DEFAULT NULL COMMENT 'user id of the manager who approved the savings.',
  `dateapproved` date DEFAULT NULL,
  `lasttransdate` date DEFAULT NULL COMMENT 'the last date of activity for this particular savings account, this will be usefull in determining if the savings account is dormant',
  `withdrawabledate` date DEFAULT NULL,
  `curpassbookprintline` tinyint(4) NOT NULL DEFAULT 0,
  `passbooktype` int(11) NOT NULL,
  `status` enum('CLOSED','PENDING','ACTIVE','DORMANT','WITHDRAWN') NOT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datelastmodified` datetime DEFAULT NULL,
  `closeApprovedBy` int(11) DEFAULT NULL,
  `dateClosed` datetime DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `hasRestriction` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`accountno`) USING BTREE,
  KEY `memberID_idx` (`memberid`) USING BTREE,
  KEY `savingsID_idx` (`savingsid`) USING BTREE,
  KEY `branchId_idx` (`branchid`) USING BTREE,
  KEY `fkeybranchID_idx` (`branchid`) USING BTREE,
  KEY `savAccount_idx` (`branchid`,`memberid`,`savingsid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

DROP TABLE IF EXISTS `legacy_savaccnttransaction`;
CREATE TABLE `legacy_savaccnttransaction` (
  `savtransHeadID` varchar(37) NOT NULL,
  `savtransEntryID` varchar(32) NOT NULL,
  `savaccountno` bigint(20) NOT NULL,
  `savtransEntryDate` datetime NOT NULL,
  `savtransTransDate` datetime NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `savTransCode` int(11) NOT NULL,
  `memo` varchar(200) DEFAULT NULL,
  `sessionid` varchar(37) NOT NULL,
  `isprinted` tinyint(4) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  `operatorkey` int(11) DEFAULT NULL,
  `TDCertNo` varchar(20) DEFAULT NULL,
  `errTransRefNo` varchar(37) DEFAULT NULL,
  PRIMARY KEY (`savtransHeadID`) USING BTREE,
  KEY `fk_savaccount_idx` (`savaccountno`) USING BTREE,
  KEY `fk_savTransCode_idx` (`savTransCode`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

DROP TABLE IF EXISTS `legacy_savingstranscode`;
CREATE TABLE `legacy_savingstranscode` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `transcode` varchar(10) DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  `journalside` enum('DEBIT','CREDIT') DEFAULT NULL,
  `oppAccountType` enum('GLTYPE','USERTYPE','SAVINGSTYPE') DEFAULT NULL,
  `oppAccountID` int(11) DEFAULT NULL,
  `relatedcode` varchar(10) DEFAULT NULL,
  `iserrorcorrect` tinyint(4) DEFAULT NULL,
  `IsCheckDeposit` tinyint(4) DEFAULT NULL,
  `checkClearingDays` int(11) DEFAULT NULL,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datelastmodified` datetime DEFAULT NULL,
  `status` varchar(1) DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

DROP TABLE IF EXISTS `legacy_savingsinterest`;
CREATE TABLE `legacy_savingsinterest` (
  `IntKey` int(11) NOT NULL AUTO_INCREMENT,
  `savingskey` int(11) NOT NULL,
  `Inttype` int(11) NOT NULL,
  `datafrom` int(15) NOT NULL,
  `datato` int(15) NOT NULL,
  `interestrate` decimal(5,2) NOT NULL,
  `postingMethod` int(11) NOT NULL,
  `effectivedate` date NOT NULL,
  `status` char(1) NOT NULL DEFAULT 'A',
  PRIMARY KEY (`IntKey`,`savingskey`) USING BTREE,
  KEY `fk_savingsId_idx` (`savingskey`) USING BTREE,
  KEY `fk_posting_idx` (`postingMethod`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='this table holds records of interest rates configure per savings, each savings product can hold 1 or many interest rates';

DROP TABLE IF EXISTS `legacy_savingsaccount_param`;
CREATE TABLE `legacy_savingsaccount_param` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `savsaccountno` bigint(20) NOT NULL,
  `paramname` varchar(45) NOT NULL,
  `paramvalue` varchar(500) DEFAULT NULL,
  `relatedparamkey` int(11) DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE,
  KEY `frelatedparamkey_idx` (`relatedparamkey`) USING BTREE,
  KEY `fkeysavAccountno_idx` (`savsaccountno`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

DROP TABLE IF EXISTS `legacy_savingsrestrictions`;
CREATE TABLE `legacy_savingsrestrictions` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `savaccountNo` bigint(20) NOT NULL,
  `sessionID` varchar(45) NOT NULL,
  `approvedby` varchar(45) NOT NULL,
  `operatorkey` varchar(45) NOT NULL,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datemodified` datetime DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  KEY `fk_savaccountNo_idx` (`savaccountNo`) USING BTREE,
  KEY `fk_restrictionType_idx` (`type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Savings Restrictions';

DROP TABLE IF EXISTS `legacy_savrestrictiontype`;
CREATE TABLE `legacy_savrestrictiontype` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) NOT NULL,
  `description` varchar(200) NOT NULL,
  `createdBy` varchar(45) DEFAULT NULL,
  `modifiedBy` varchar(45) DEFAULT NULL,
  `dateCreated` datetime DEFAULT NULL,
  `dateModified` datetime DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Table for savings restrictions type.';

DROP TABLE IF EXISTS `water_postings`;
CREATE TABLE water_postings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key       VARCHAR(64)  NOT NULL,                 
  source_type     VARCHAR(30)  NOT NULL,                 
  source_id       VARCHAR(64)  NOT NULL,                 
  doc_no          VARCHAR(60)  NULL,                     
  water_account_id INT         NULL,                     
  party_id        INT UNSIGNED NULL,
  txn_id          BIGINT UNSIGNED NULL,                  
  txn_type        VARCHAR(30)  NULL,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(12)  NOT NULL DEFAULT 'posted',
  reversal_of_id  BIGINT UNSIGNED NULL,                  
  reason          VARCHAR(255) NULL,
  branch_id       INT NULL,
  segment_id      INT NULL,
  division_id     INT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME     NULL,
  class_id        INT UNSIGNED NULL,                     
  UNIQUE KEY uq_water_postings_event (event_key),
  KEY ix_water_postings_class (class_id),
  KEY ix_water_postings_source (source_type, source_id),
  KEY ix_water_postings_txn (txn_id),
  KEY ix_water_postings_account (water_account_id),
  KEY ix_water_postings_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `water_coa_map`;
CREATE TABLE water_coa_map (
  legacy_accnt_id INT          NOT NULL PRIMARY KEY,
  legacy_name     VARCHAR(45)  NULL,
  legacy_type     INT          NULL,
  account_id      INT UNSIGNED NULL,
  how             VARCHAR(12)  NOT NULL DEFAULT 'imported',   
  note            VARCHAR(255) NULL,
  KEY ix_water_coa_map_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `water_user_map`;
CREATE TABLE water_user_map (
  legacy_operator_id INT        NOT NULL PRIMARY KEY,
  legacy_name        VARCHAR(45) NULL,
  user_id            INT UNSIGNED NULL,
  note               VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `water_migration_log`;
CREATE TABLE water_migration_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id      VARCHAR(32)  NOT NULL,
  phase       VARCHAR(40)  NOT NULL,
  status      VARCHAR(12)  NOT NULL,                      
  rows_read   INT          NOT NULL DEFAULT 0,
  rows_written INT         NOT NULL DEFAULT 0,
  rows_skipped INT         NOT NULL DEFAULT 0,
  amount_in   DECIMAL(15,2) NULL,
  amount_out  DECIMAL(15,2) NULL,
  notes       TEXT         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_water_migration_log_run (run_id, phase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- the integration columns of the water tables (added only where missing)
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN party_id INT UNSIGNED NULL AFTER pkey, ADD KEY ix_water_accounts_party (party_id)', 'SELECT ''water_accounts.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'party_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN customer_id INT UNSIGNED NULL AFTER party_id, ADD KEY ix_water_accounts_customer (customer_id)', 'SELECT ''water_accounts.customer_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'customer_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN legacy_member_id INT NULL AFTER customer_id', 'SELECT ''water_accounts.legacy_member_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'legacy_member_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE bill_codes ADD COLUMN item_id INT UNSIGNED NULL AFTER asset_account, ADD KEY ix_bill_codes_item (item_id)', 'SELECT ''bill_codes.item_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bill_codes' AND column_name = 'item_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE inventory_issuances ADD COLUMN txn_id BIGINT UNSIGNED NULL AFTER status, ADD COLUMN site_id INT UNSIGNED NULL AFTER txn_id, ADD KEY ix_inventory_issuances_txn (txn_id)', 'SELECT ''inventory_issuances.txn_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_issuances' AND column_name = 'txn_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE inventory_issuance_items ADD COLUMN item_id INT UNSIGNED NULL AFTER code_id', 'SELECT ''inventory_issuance_items.item_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_issuance_items' AND column_name = 'item_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE branches ADD COLUMN class_id INT UNSIGNED NULL AFTER description', 'SELECT ''branches.class_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'branches' AND column_name = 'class_id');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

-- the two compatibility views the water pages read (the chart of accounts and the operators)
DROP VIEW IF EXISTS chartofaccntstbl;
CREATE VIEW chartofaccntstbl AS
SELECT a.id AS AccntID, a.name AS AccntName, 0 AS BaseAccnt, COALESCE(a.parent_id, 0) AS ParentAccnt,
       CAST('' AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS SummaryAccnt,
       CASE a.category WHEN 'Asset' THEN 1 WHEN 'Liability' THEN 2 WHEN 'Equity' THEN 3 WHEN 'Income' THEN 4 ELSE 5 END AS `Type`,
       COALESCE(a.description, a.name) AS Description, a.with_sl AS WithSL, 0 AS CashFlowType, a.created_at AS CreateTime,
       CAST(IF(a.is_active = 1, 'A', 'I') AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Status, a.code AS AccntCode, a.account_type AS AccntType
  FROM accounts a;
DROP VIEW IF EXISTS operator;
CREATE VIEW operator AS
SELECT u.id AS pkey, u.username AS name, CAST('' AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS password, 0 AS accesslevel, COALESCE(u.full_name, u.username) AS description,
       NULL AS relatedkey, u.is_platform AS isSystem, IF(u.status = 'active', 0, 1) AS deleted,
       NULL AS createdby, u.created_at AS date_created, NULL AS modifiedby, u.updated_at AS date_modified
  FROM users u;

-- ---------------------------------------------------------------------
--  3. DATA
-- ---------------------------------------------------------------------

-- settings (101 rows)
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('allow_negative_stock','1','2026-10-19 00:49:43'),
('audit_retention_years','10','2026-10-19 00:49:43'),
('bir_signatory_name','','2026-10-19 00:49:43'),
('bir_signatory_tin','','2026-10-19 00:49:43'),
('bir_signatory_title','','2026-10-19 00:49:43'),
('bir_zip_code','','2026-10-19 00:49:43'),
('bt_printer_autoprint','0','2026-10-19 00:49:43'),
('bt_printer_cut','0','2026-10-19 00:49:43'),
('bt_printer_enabled','0','2026-10-19 00:49:43'),
('bt_printer_feed','4','2026-10-19 00:49:43'),
('bt_printer_name','','2026-10-19 00:49:43'),
('bt_printer_width','32','2026-10-19 00:49:43'),
('bu_default_banking','','2026-10-19 00:49:43'),
('bu_default_coop','1','2026-10-19 00:49:43'),
('bu_default_general','','2026-10-19 00:49:43'),
('bu_default_inventory','2','2026-10-19 00:49:43'),
('bu_default_pos','2','2026-10-19 00:49:43'),
('bu_default_purchases','2','2026-10-19 00:49:43'),
('bu_default_sales','2','2026-10-19 00:49:43'),
('closing_date','','2026-10-19 00:49:43'),
('company_address','123 Rizal Avenue, Makati City, Metro Manila','2026-10-19 00:49:43'),
('company_email','accounting@sampletrading.test','2026-10-19 00:49:43'),
('company_logo','','2026-10-19 00:49:43'),
('company_name','Sample Trading Company','2026-10-19 00:49:43'),
('company_phone','(02) 8123-4567','2026-10-19 00:49:43'),
('company_tin','000-123-456-000','2026-10-19 00:49:43'),
('coop_allocation_order','penalty,interest,principal','2026-10-19 00:49:43'),
('coop_backdate_days','0','2026-10-19 00:49:43'),
('coop_category','','2026-10-19 00:49:43'),
('coop_cda_reg_no','','2026-10-19 00:49:43'),
('coop_cdf_pct','3','2026-10-19 00:49:43'),
('coop_comaker_text','','2026-10-19 00:49:43'),
('coop_default_par_value','100','2026-10-19 00:49:43'),
('coop_disclosure_text','','2026-10-19 00:49:43'),
('coop_division_name','Savings & Credit Division','2026-10-19 00:49:43'),
('coop_doc_expiry_alert_days','30','2026-10-19 00:49:43'),
('coop_dormancy_alert_days','30','2026-10-19 00:49:43'),
('coop_educ_apex_pct','50','2026-10-19 00:49:43'),
('coop_educ_pct','10','2026-10-19 00:49:43'),
('coop_fiscal_year_end','12-31','2026-10-19 00:49:43'),
('coop_interest_accrual_loans','0','2026-10-19 00:49:43'),
('coop_isc_basis','adb','2026-10-19 00:49:43'),
('coop_large_txn_amount','500000','2026-10-19 00:49:43'),
('coop_loan_account_format','{BRANCH}-{PRODUCT}-{SEQ}','2026-10-19 00:49:43'),
('coop_loan_due_alert_days','7','2026-10-19 00:49:43'),
('coop_membership_fee','0','2026-10-19 00:49:43'),
('coop_min_share_capital','0','2026-10-19 00:49:43'),
('coop_optional_pct','7','2026-10-19 00:49:43'),
('coop_or_require_shift','1','2026-10-19 00:49:43'),
('coop_over_short_tolerance','0','2026-10-19 00:49:43'),
('coop_passbook_header','1','2026-10-19 00:49:43'),
('coop_penalty_on_collection','1','2026-10-19 00:49:43'),
('coop_pn_text','','2026-10-19 00:49:43'),
('coop_pr_basis','income','2026-10-19 00:49:43'),
('coop_registered_name','','2026-10-19 00:49:43'),
('coop_reserve_pct','10','2026-10-19 00:49:43'),
('coop_sav_account_format','{BRANCH}{MEMBER}{PRODUCT}1','2026-10-19 00:49:43'),
('coop_signatory_bookkeeper','','2026-10-19 00:49:43'),
('coop_signatory_chair','','2026-10-19 00:49:43'),
('coop_signatory_gm','','2026-10-19 00:49:43'),
('coop_signatory_loans','','2026-10-19 00:49:43'),
('coop_signatory_treasurer','','2026-10-19 00:49:43'),
('coop_td_maturity_alert_days','15','2026-10-19 00:49:43'),
('coop_type','Multi-Purpose','2026-10-19 00:49:43'),
('coop_withdrawal_approval_above','0','2026-10-19 00:49:43'),
('costing_method','weighted_average','2026-10-19 00:49:43'),
('disabled_modules','','2026-10-19 00:49:43'),
('doc_show_member_no','1','2026-10-19 00:49:43'),
('finance_charge_grace_days','0','2026-10-19 00:49:43'),
('finance_charge_min','50','2026-10-19 00:49:43'),
('finance_charge_on_charges','0','2026-10-19 00:49:43'),
('finance_charge_rate','12','2026-10-19 00:49:43'),
('fiscal_year_start_month','1','2026-10-19 00:49:43'),
('item_search_by_page','','2026-10-19 00:49:43'),
('item_search_format','code_name','2026-10-19 00:49:43'),
('large_discount_pct','10','2026-10-19 00:49:43'),
('loan_pb_adjustments_since','2026-10-29 23:56:43','2026-10-29 23:56:43'),
('lock_posted_documents','1','2026-10-19 00:49:43'),
('logo_targets','','2026-10-19 00:49:43'),
('max_failed_logins','5','2026-10-19 00:49:43'),
('member_price_level_id','','2026-10-19 00:49:43'),
('nonmember_price_level_id','','2026-10-19 00:49:43'),
('pos_posting_mode','per_sale','2026-10-19 00:49:43'),
('pos_receipt_footer','Thank you for shopping!\nThis serves as your official receipt.','2026-10-19 00:49:43'),
('pos_receipt_show_customer','1','2026-10-19 00:49:43'),
('pos_receipt_title','OFFICIAL RECEIPT','2026-10-19 00:49:43'),
('pos_receipt_title_charge','CHARGE INVOICE','2026-10-19 00:49:43'),
('pos_receipt_title_payment','ACKNOWLEDGEMENT RECEIPT','2026-10-19 00:49:43'),
('pos_receipt_title_return','RETURN / REFUND','2026-10-19 00:49:43'),
('pw_expiry_days','90','2026-10-19 00:49:43'),
('pw_min_length','8','2026-10-19 00:49:43'),
('pw_require_number','1','2026-10-19 00:49:43'),
('pw_require_symbol','1','2026-10-19 00:49:43'),
('pw_require_upper','1','2026-10-19 00:49:43'),
('session_timeout_minutes','30','2026-10-19 00:49:43'),
('water_business_unit_id','3','2026-10-19 00:49:44'),
('water_cutover_date','','2026-10-19 00:49:44'),
('water_legacy_posting_locked','1','2026-10-19 00:49:44'),
('water_penalty_as_finance_charge','1','2026-10-19 00:49:44'),
('water_source_db','','2026-10-19 00:49:44');
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('withholding_agent','1','2026-10-19 00:49:43');

-- users (9 rows)
INSERT INTO `users` (`id`,`username`,`password_hash`,`full_name`,`email`,`employee_no`,`pos_default_site_id`,`pos_default_class_id`,`coop_branch_id`,`coop_cash_account_id`,`status`,`approval_status`,`created_via`,`approved_by`,`approved_at`,`rejected_reason`,`is_platform`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'admin','$2y$10$jCG4KhFX/18cYuP1WM5o.OCoMnurxZDZaH9olFOKfe0i3pAX0KPbe','System Administrator','admin@sampletrading.test','EMP-001',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(2,'accountant','$2y$10$7CzpKObR/v3qfjX08fyEj.q286d7UsqCvCIqIuwEhz/t6fdQyuNze','Ana Cruz','accountant@sampletrading.test','EMP-002',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(3,'arclerk','$2y$10$uGgBN9i0lt9znwZzyY1DnuOwkxJQcEB3IMUhVnTtOToX.r0OUkh16','Ben Garcia','arclerk@sampletrading.test','EMP-003',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(4,'apclerk','$2y$10$fOIJ1uQ6c6h9Q4gkYFDzlOI.muWXzmglNcZE.Sz.jtuK3XjCqdZAS','Carla Lim','apclerk@sampletrading.test','EMP-004',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(5,'invclerk','$2y$10$amAcbKJl6SY/PutS29drwe/zHc1D7P0QvMxVUlj4DfzobYcGEE3r6','Dan Villanueva','invclerk@sampletrading.test','EMP-005',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(6,'salesrep','$2y$10$8EFJIpV6uUt/EFEYbxOmm.15pGAY/u.DuZ3.KRZPCBClM6sFRtgn2','Maria Santos','salesrep@sampletrading.test','EMP-006',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(7,'cashier1','$2y$10$4qRem62kT01A8XZR4x2DD.tRi9CZGyBVv9ttMGaG3q1gaK5q3WNMi','Ella Ramos','cashier1@sampletrading.test','EMP-007',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(8,'supervisor','$2y$10$THtIjNg6TFDyKl4kU7MJS.eh2cyO.ACQi1CPqo7YOaivitDd.Kn2S','Fred Tan','supervisor@sampletrading.test','EMP-008',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL),
(9,'auditor','$2y$10$i8i.eg8c2P/h55p.rjWWn.XJ.sW08SjBZjIMHUT57SudVP3eH02ke','Grace Uy','auditor@sampletrading.test','EMP-009',NULL,NULL,NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,0,'2026-10-19 00:49:43',0,NULL,NULL,NULL,'2026-10-19 00:49:43',NULL);

-- roles (17 rows)
INSERT INTO `roles` (`id`,`name`,`description`,`is_admin`,`is_system`,`all_sites`,`created_at`,`updated_at`,`coop_cash_account_id`) VALUES
(1,'Administrator','Everything in every module, including user accounts, roles, page access, the closing date, and the audit trail.',1,1,1,'2026-10-19 00:49:43',NULL,NULL),
(2,'Accountant','Chart of Accounts, Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(3,'A/R Clerk','Customers module transactions and the Customer and Receivables reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(4,'A/P Clerk','Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(5,'Inventory Clerk','Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(6,'Sales Representative','Estimates, sales orders, and invoices, and the Sales reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(7,'Cashier','POS sales, returns, and cash drops at the assigned terminal only.',0,1,0,'2026-10-19 00:49:43',NULL,NULL),
(8,'POS Supervisor','Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(9,'Auditor','View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(10,'General Manager','Opens every cooperative, savings, loan, teller, distribution and compliance page, approves loans and transactions, and sees every report.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(11,'Bookkeeper','Records cooperative vouchers, posts interest and penalties, runs the subsidiary ledgers and financial reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(12,'Loan Officer','Loan applications, credit investigation, loan release, collection, loan accounts and loan reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(13,'Credit Committee','Reviews and approves loan applications and waivers; views loan reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(14,'Teller','Teller shift, official receipts, savings deposits and withdrawals, loan collection and passbook printing.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(15,'Membership Officer','Member records, member statements, savings account opening and the member reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(16,'Collector','Loan collection and the collection, due and aging reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL),
(17,'Branch Manager','Everything of the branch: approves savings accounts, withdrawals, loans and waivers, closes teller shifts, and sees the branch reports.',0,1,1,'2026-10-19 00:49:43',NULL,NULL);

-- user_roles (9 rows)
INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES
(1,1),
(2,2),
(3,3),
(4,4),
(5,5),
(6,6),
(7,7),
(8,8),
(9,9);

-- modules (19 rows)
INSERT INTO `modules` (`id`,`code`,`name`,`icon`,`sort_order`) VALUES
(1,'home','Home','house',1),
(2,'coa','Financial','journal-bookmark',2),
(3,'fa','Fixed Assets','building',14),
(4,'cust','Customers','people',10),
(5,'vend','Vendors','truck',12),
(6,'inv','Inventory','box-seam',13),
(7,'pos','Point of Sale','cart',11),
(8,'bank','Banking','bank',8),
(9,'bir','BIR Reports','file-earmark-ruled',16),
(10,'admin','System Administration','shield-lock',19),
(11,'company','Company','buildings',9),
(12,'party','Parties','person-vcard',15),
(13,'coop','Members & Others','people-fill',3),
(14,'sav','Savings & Deposits','piggy-bank',5),
(15,'loan','Loans','cash-coin',6),
(16,'teller','Teller','safe2',4),
(17,'dist','Year-end Distribution','pie-chart',17),
(18,'cda','CDA Compliance','clipboard2-check',18),
(19,'water','Water Billing','droplet-half',7);

-- pages (376 rows)
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(1,'home.dashboard',1,'Home Dashboard','center','Home',10,1,NULL),
(2,'home.reports',1,'Report Center','center','Home',20,1,NULL),
(3,'home.memorized',1,'Memorized Reports','list','Home',30,1,NULL),
(4,'home.report_layouts',1,'Saved Report Layouts','list','Home',40,1,NULL),
(5,'home.search',1,'Search','center','Home',50,0,NULL),
(6,'home.find',1,'Find','center','Home',60,1,NULL),
(7,'rpt.find',1,'Find Results','report','Reports',70,0,NULL),
(8,'coa.accounts',2,'Chart of Accounts','list','Lists',80,1,NULL),
(9,'coa.classes',2,'Class List','list','Lists',90,1,NULL),
(10,'coa.names',2,'Employees & Other Names','list','Lists',100,1,NULL),
(11,'coa.memorized_txns',2,'Memorized Transactions','list','Lists',110,1,NULL),
(12,'coa.budgets',2,'Set Up Budgets','setup','Setup',120,1,NULL),
(13,'coa.journal',2,'Make Journal Entries','transaction','Transactions',130,1,NULL),
(14,'coa.je_edits',2,'Journal Entry Changes','list','Transactions',140,1,NULL),
(15,'coa.default_accounts',2,'Module Default Accounts','setup','Setup',150,1,NULL),
(16,'coa.lists',2,'Terms, Tax Codes & Other Lists','setup','Setup',160,1,NULL),
(17,'rpt.trial_balance',2,'Trial Balance','report','Reports',170,1,NULL),
(18,'rpt.balance_sheet',2,'Balance Sheet','report','Reports',180,1,NULL),
(19,'rpt.balance_sheet_class',2,'Balance Sheet by Class','report','Reports',190,1,NULL),
(20,'rpt.trial_balance_range',2,'Trial Balance by Date Range','report','Reports',200,1,NULL),
(21,'rpt.trial_balance_totals',2,'Trial Balance of Totals','report','Reports',210,1,NULL),
(22,'rpt.balance_sheet_range',2,'Balance Sheet by Date Range','report','Reports',220,1,NULL),
(23,'rpt.budget_income',2,'Income Statement vs Budget','report','Reports',230,1,NULL),
(24,'rpt.budget_balance',2,'Balance Sheet vs Budget','report','Reports',240,1,NULL),
(25,'rpt.income_statement',2,'Income Statement','report','Reports',250,1,NULL),
(26,'rpt.income_statement_class',2,'Income Statement by Class','report','Reports',260,1,NULL),
(27,'rpt.cash_flow',2,'Statement of Cash Flows','report','Reports',270,1,NULL),
(28,'rpt.general_ledger',2,'General Ledger','report','Reports',280,1,NULL),
(29,'rpt.subsidiary_ledger',2,'Subsidiary Ledger','report','Reports',290,1,NULL),
(30,'rpt.schedule_of_accounts',2,'Schedule of Accounts','report','Reports',300,1,NULL),
(31,'rpt.journal',2,'Journal','report','Reports',310,1,NULL),
(32,'fa.register',3,'Fixed Asset Register','center','Transactions',320,1,NULL),
(33,'fa.depreciation',3,'Run Depreciation','transaction','Transactions',330,1,NULL),
(34,'fa.disposal',3,'Dispose of Assets','transaction','Transactions',340,1,NULL),
(35,'fa.maintenance',3,'Asset Maintenance','transaction','Transactions',350,1,NULL),
(36,'fa.pm',3,'Preventive Maintenance','transaction','Transactions',360,1,NULL),
(37,'fa.categories',3,'Asset Categories','setup','Setup',370,1,NULL),
(38,'fa.locations',3,'Asset Locations','setup','Setup',380,1,NULL),
(39,'rpt.fa_register',3,'Fixed Asset Register Report','report','Reports',390,1,NULL),
(40,'rpt.fa_schedule',3,'Depreciation Schedule by Asset','report','Reports',400,1,NULL),
(41,'rpt.fa_by_category',3,'Depreciation by Category','report','Reports',410,1,NULL),
(42,'rpt.fa_additions',3,'Additions and Disposals','report','Reports',420,1,NULL),
(43,'rpt.fa_maint_history',3,'Maintenance History','report','Reports',430,1,NULL),
(44,'rpt.fa_maint_cost',3,'Maintenance Cost by Asset','report','Reports',440,1,NULL),
(45,'rpt.fa_maint_parts',3,'Spare Parts Used','report','Reports',450,1,NULL),
(46,'rpt.fa_maint_labor',3,'Maintenance Labor','report','Reports',460,1,NULL),
(47,'rpt.fa_pm_upcoming',3,'Upcoming Preventive Maintenance','report','Reports',470,1,NULL),
(48,'rpt.fa_pm_log',3,'Completed Preventive Maintenance','report','Reports',480,1,NULL),
(49,'cust.center',4,'Customer Center','center','Transactions',490,1,NULL),
(50,'cust.estimates',4,'Create Estimates','transaction','Transactions',500,1,NULL),
(51,'cust.sales_orders',4,'Create Sales Orders','transaction','Transactions',510,1,NULL),
(52,'cust.invoices',4,'Create Invoices','transaction','Transactions',520,1,NULL),
(53,'cust.batch_invoices',4,'Create Batch Invoices','transaction','Transactions',530,1,NULL),
(54,'cust.sales_receipts',4,'Enter Sales Receipts','transaction','Transactions',540,1,NULL),
(55,'cust.statement_charges',4,'Enter Statement Charges','transaction','Transactions',550,1,NULL),
(56,'cust.statements',4,'Create Statements','transaction','Transactions',560,1,NULL),
(57,'cust.finance_charges',4,'Assess Finance Charges','transaction','Transactions',570,1,NULL),
(58,'cust.forecast',4,'Set Up Forecast','setup','Transactions',580,1,NULL),
(59,'cust.payments',4,'Receive Payments','transaction','Transactions',590,1,NULL),
(60,'cust.credit_memos',4,'Create Credit Memos/Refunds','transaction','Transactions',600,1,NULL),
(61,'rpt.ar_aging_summary',4,'A/R Aging Summary','report','Customer and Receivables Reports',610,1,NULL),
(62,'rpt.ar_aging_detail',4,'A/R Aging Detail','report','Customer and Receivables Reports',620,1,NULL),
(63,'rpt.customer_balance_summary',4,'Customer Balance Summary','report','Customer and Receivables Reports',630,1,NULL),
(64,'rpt.customer_balance_detail',4,'Customer Balance Detail','report','Customer and Receivables Reports',640,1,NULL),
(65,'rpt.open_invoices',4,'Open Invoices','report','Customer and Receivables Reports',650,1,NULL),
(66,'rpt.collections',4,'Collections Report','report','Customer and Receivables Reports',660,1,NULL),
(67,'rpt.avg_days_summary',4,'Average Days to Pay Summary','report','Customer and Receivables Reports',670,1,NULL),
(68,'rpt.avg_days_detail',4,'Average Days to Pay','report','Customer and Receivables Reports',680,1,NULL),
(69,'rpt.ar_graph',4,'Accounts Receivable Graph','report','Customer and Receivables Reports',690,1,NULL),
(70,'rpt.unbilled_costs',4,'Unbilled Cost by Job','report','Customer and Receivables Reports',700,1,NULL),
(71,'rpt.txn_list_customer',4,'Transaction List by Customer','report','Customer and Receivables Reports',710,1,NULL),
(72,'rpt.item_price_list',4,'Item Price List','report','Customer and Receivables Reports',720,1,NULL),
(73,'rpt.sales_customer_summary',4,'Sales by Customer Summary','report','Sales Reports',730,1,NULL),
(74,'rpt.forecast_vs_actual',4,'Forecast vs Actual by Customer','report','Sales Reports',740,1,NULL),
(75,'rpt.sales_customer_detail',4,'Sales by Customer Detail','report','Sales Reports',750,1,NULL),
(76,'rpt.income_customer_summary',4,'Income by Customer Summary','report','Sales Reports',760,1,NULL),
(77,'rpt.income_customer_detail',4,'Income by Customer Detail','report','Sales Reports',770,1,NULL),
(78,'rpt.sales_item_summary',4,'Sales by Item Summary','report','Sales Reports',780,1,NULL),
(79,'rpt.sales_item_detail',4,'Sales by Item Detail','report','Sales Reports',790,1,NULL),
(80,'rpt.sales_rep_summary',4,'Sales by Rep Summary','report','Sales Reports',800,1,NULL),
(81,'rpt.sales_rep_detail',4,'Sales by Rep Detail','report','Sales Reports',810,1,NULL),
(82,'rpt.inventory_sales_site',4,'Inventory Sales by Site','report','Sales Reports',820,1,NULL),
(83,'rpt.sales_inv_movement',4,'Sales Report Through Inventory Movement','report','Sales Reports',830,1,NULL),
(84,'rpt.pending_sales',4,'Pending Sales','report','Sales Reports',840,1,NULL),
(85,'rpt.open_so_customer',4,'Open Sales Orders by Customer','report','Sales Reports',850,1,NULL),
(86,'rpt.open_so_item',4,'Open Sales Orders by Item','report','Sales Reports',860,1,NULL),
(87,'vend.center',5,'Vendor Center','center','Transactions',870,1,NULL),
(88,'vend.bill_tracker',5,'Bill Tracker','center','Transactions',880,1,NULL),
(89,'vend.bills',5,'Enter Bills','transaction','Transactions',890,1,NULL),
(90,'vend.debit_memos',5,'Supplier Debit Memos','transaction','Transactions',900,1,NULL),
(91,'vend.pay_bills',5,'Pay Bills','transaction','Transactions',910,1,NULL),
(92,'vend.receipts',5,'Receipt Management','transaction','Transactions',920,1,NULL),
(93,'vend.purchase_orders',5,'Create Purchase Orders','transaction','Transactions',930,1,NULL),
(94,'vend.receive_bill',5,'Receive Items and Enter Bills','transaction','Transactions',940,1,NULL),
(95,'vend.receive_items',5,'Receive Items','transaction','Transactions',950,1,NULL),
(96,'vend.bill_received',5,'Enter Bill for Received Items','transaction','Transactions',960,1,NULL),
(97,'vend.advances',5,'Advances to Suppliers','transaction','Transactions',970,1,NULL),
(98,'rpt.ap_aging_summary',5,'A/P Aging Summary','report','Vendors and Payables Reports',980,1,NULL),
(99,'rpt.ap_aging_detail',5,'A/P Aging Detail','report','Vendors and Payables Reports',990,1,NULL),
(100,'rpt.vendor_balance_summary',5,'Vendor Balance Summary','report','Vendors and Payables Reports',1000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(101,'rpt.vendor_balance_detail',5,'Vendor Balance Detail','report','Vendors and Payables Reports',1010,1,NULL),
(102,'rpt.unpaid_bills',5,'Unpaid Bills Detail','report','Vendors and Payables Reports',1020,1,NULL),
(103,'rpt.txn_list_vendor',5,'Transaction List by Vendor','report','Vendors and Payables Reports',1030,1,NULL),
(104,'rpt.vendor_contacts',5,'Vendor Contact List','report','Vendors and Payables Reports',1040,1,NULL),
(105,'rpt.expenses_vendor_summary',5,'Expenses by Vendor Summary','report','Vendors and Payables Reports',1050,1,NULL),
(106,'rpt.expenses_vendor_detail',5,'Expenses by Vendor Detail','report','Vendors and Payables Reports',1060,1,NULL),
(107,'rpt.vendor_advances',5,'Advances to Suppliers','report','Vendors and Payables Reports',1070,1,NULL),
(108,'inv.center',6,'Inventory Center','center','Transactions',1080,1,NULL),
(109,'inv.items',6,'Item List','list','Lists',1090,1,NULL),
(110,'inv.categories',6,'Category List','list','Lists',1100,1,NULL),
(111,'inv.sites',6,'Inventory Site List','list','Lists',1110,1,NULL),
(112,'inv.uom',6,'U/M Set List','list','Lists',1120,1,NULL),
(113,'inv.formulas',6,'Processing Formulas (Multi-Output BOM)','list','Lists',1130,1,NULL),
(114,'inv.transfers',6,'Transfer Inventory','transaction','Transactions',1140,1,NULL),
(115,'inv.adjust',6,'Adjust Quantity/Value on Hand','transaction','Transactions',1150,1,NULL),
(116,'inv.build',6,'Build Assemblies','transaction','Transactions',1160,1,NULL),
(117,'inv.process',6,'Disassemble / Process Items','transaction','Transactions',1170,1,NULL),
(118,'inv.physical_count',6,'Physical Inventory Count','transaction','Transactions',1180,1,NULL),
(119,'inv.change_prices',6,'Change Item Prices','transaction','Transactions',1190,1,NULL),
(120,'inv.set_prices',6,'Set Item Prices and Levels','transaction','Transactions',1200,1,NULL),
(121,'rpt.qoh_by_site',6,'Quantity on Hand by Site','report','Inventory Reports',1210,1,NULL),
(122,'rpt.inv_valuation_summary',6,'Inventory Valuation Summary','report','Inventory Reports',1220,1,NULL),
(123,'rpt.inv_valuation_site',6,'Inventory Valuation Summary by Site','report','Inventory Reports',1230,1,NULL),
(124,'rpt.inv_valuation_detail',6,'Inventory Valuation Detail','report','Inventory Reports',1240,1,NULL),
(125,'rpt.stock_status_item',6,'Inventory Stock Status by Item','report','Inventory Reports',1250,1,NULL),
(126,'rpt.stock_status_vendor',6,'Inventory Stock Status by Vendor','report','Inventory Reports',1260,1,NULL),
(127,'rpt.stock_status_site',6,'Inventory Stock Status by Site','report','Inventory Reports',1270,1,NULL),
(128,'rpt.physical_worksheet',6,'Physical Inventory Worksheet','report','Inventory Reports',1280,1,NULL),
(129,'rpt.pending_builds',6,'Pending Builds','report','Inventory Reports',1290,1,NULL),
(130,'rpt.pending_builds_site',6,'Pending Builds by Site','report','Inventory Reports',1300,1,NULL),
(131,'rpt.processing_register',6,'Processing & Disassembly Register','report','Inventory Reports',1310,1,NULL),
(132,'rpt.coproduct_costs',6,'Co-product & By-product Cost Allocation','report','Inventory Reports',1320,1,NULL),
(133,'rpt.process_yield',6,'Processing Yield Analysis','report','Inventory Reports',1330,1,NULL),
(134,'rpt.negative_inventory',6,'Negative Inventory Listing','report','Inventory Reports',1340,1,NULL),
(135,'rpt.inv_no_movement',6,'No. of Days Inventory Has No Movement','report','Inventory Reports',1350,1,NULL),
(136,'rpt.inv_custom',6,'Customized Inventory Report','report','Inventory Reports',1360,1,NULL),
(137,'rpt.inv_movement_summary',6,'Inventory Movement Summary Report','report','Inventory Reports',1370,1,NULL),
(138,'rpt.inv_movement_detail',6,'Inventory Movement Detailed Report','report','Inventory Reports',1380,1,NULL),
(139,'rpt.inv_movement_txns',6,'Inventory Movement Transactions','report','Inventory Reports',1390,0,NULL),
(140,'pos.register',7,'POS Register','transaction','Transactions',1400,1,NULL),
(141,'pos.shifts',7,'Shifts, Cash Drops & Readings','transaction','Transactions',1410,1,NULL),
(142,'pos.receipts',7,'POS Receipts, Returns & Voids','transaction','Transactions',1420,1,NULL),
(143,'pos.approval_codes',7,'Approval Codes','transaction','Transactions',1430,1,NULL),
(144,'pos.terminals',7,'POS Terminals','setup','Setup',1440,1,NULL),
(145,'rpt.pos_sales_txn',7,'POS Sales by Transaction No. (detail)','report','Reports',1450,1,NULL),
(146,'rpt.pos_sales_item',7,'POS Sales by Item','report','Reports',1460,1,NULL),
(147,'rpt.pos_sales_category',7,'POS Sales by Category','report','Reports',1470,1,NULL),
(148,'rpt.pos_sales_cashier',7,'POS Sales by Cashier & Payment Method','report','Reports',1480,1,NULL),
(149,'rpt.pos_sales_payment',7,'POS Sales by Payment Method','report','Reports',1490,0,NULL),
(150,'rpt.pos_sales_hour',7,'POS Sales by Hour','report','Reports',1500,1,NULL),
(151,'rpt.pos_daily_summary',7,'POS Daily Sales Summary','report','Reports',1510,1,NULL),
(152,'rpt.pos_readings',7,'X- and Z-Reading Summary','report','Reports',1520,1,NULL),
(153,'rpt.pos_discount_log',7,'POS Discount Log','report','Reports',1530,1,NULL),
(154,'rpt.pos_void_log',7,'POS Void and Refund Log','report','Reports',1540,1,NULL),
(155,'bank.write_checks',8,'Write Checks','transaction','Transactions',1550,1,NULL),
(156,'bank.cash_voucher',8,'Cash Voucher','transaction','Transactions',1560,1,NULL),
(157,'bank.register',8,'Use Register','center','Transactions',1570,1,NULL),
(158,'bank.deposits',8,'Make Deposits','transaction','Transactions',1580,1,NULL),
(159,'bank.transfers',8,'Transfer Funds','transaction','Transactions',1590,1,NULL),
(160,'bank.reconcile',8,'Reconcile','transaction','Transactions',1600,1,NULL),
(161,'rpt.recon_summary',8,'Reconciliation Summary','report','Reports',1610,1,NULL),
(162,'rpt.recon_detail',8,'Reconciliation Detail','report','Reports',1620,1,NULL),
(163,'rpt.recon_discrepancy',8,'Reconciliation Discrepancy','report','Reports',1630,1,NULL),
(164,'rpt.check_register',8,'Check Register','report','Reports',1640,1,NULL),
(165,'bir.form_2307',9,'Print BIR Form 2307','transaction','Transactions',1650,1,NULL),
(166,'bir.wht_table',9,'Withholding Tax Table','setup','Setup',1660,1,NULL),
(167,'rpt.bir_wht_summary',9,'Summary of Taxes Withheld from Vendors/Suppliers','report','BIR Reports',1670,1,NULL),
(168,'rpt.bir_ewt_register',9,'Expanded Withholding Tax Register (1% / 2%)','report','BIR Reports',1680,1,NULL),
(169,'rpt.bir_remittance',9,'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)','report','BIR Reports',1690,1,NULL),
(170,'rpt.bir_vat_withholding',9,'VAT Withholding Register (1600-VT)','report','BIR Reports',1700,1,NULL),
(171,'rpt.bir_sales_register',9,'Output VAT / Sales Register','report','BIR Reports',1710,1,NULL),
(172,'rpt.bir_purchase_register',9,'Input VAT / Purchases Register','report','BIR Reports',1720,1,NULL),
(173,'rpt.bir_cvat_withheld',9,'Creditable VAT Withheld Schedule (2550Q Schedule 3)','report','BIR Reports',1730,1,NULL),
(174,'rpt.bir_2550q',9,'Quarterly VAT Return Worksheet (2550Q)','report','BIR Reports',1740,1,NULL),
(175,'admin.users',10,'User Accounts','admin','Setup',1750,1,NULL),
(176,'admin.roles',10,'User Role Configuration','admin','Setup',1760,1,NULL),
(177,'admin.page_access',10,'Page Access Configuration','admin','Setup',1770,1,NULL),
(178,'admin.effective_access',10,'Effective Access (View as User)','admin','Setup',1780,1,NULL),
(179,'admin.settings',10,'Company, Security & Closing Date','admin','Setup',1790,1,NULL),
(180,'admin.utilities',10,'Utilities: Verify & Rebuild Data','admin','Setup',1800,1,NULL),
(181,'admin.import',10,'Import from CSV','admin','Utilities',1810,1,NULL),
(182,'admin.backup',10,'Backup & Restore','admin','Utilities',1820,1,NULL),
(183,'admin.multi_company',10,'Multi-Company Setup','admin','Setup',1830,1,NULL),
(184,'admin.audit_trail',10,'Audit Trail','report','Audit Reports',1840,1,NULL),
(185,'admin.txn_history',10,'Transaction History','report','Audit Reports',1850,1,NULL),
(186,'rpt.voided_deleted_summary',10,'Voided and Deleted Transactions Summary','report','Audit Reports',1860,1,NULL),
(187,'rpt.voided_deleted_detail',10,'Voided and Deleted Transactions Detail','report','Audit Reports',1870,1,NULL),
(188,'rpt.user_activity',10,'User Activity','report','Audit Reports',1880,1,NULL),
(189,'rpt.login_history',10,'Login History','report','Audit Reports',1890,1,NULL),
(190,'rpt.user_access',10,'User Access and Permission Changes','report','Audit Reports',1900,1,NULL),
(191,'company.profile',11,'Company Profile & Code','admin','Company',1910,1,NULL),
(192,'company.users',11,'Employees & Users','admin','Company',1920,1,NULL),
(193,'company.join_requests',11,'Join Requests','admin','Company',1930,1,NULL),
(194,'coop.members',13,'Member Center','center','Transactions',1940,1,NULL),
(195,'coop.member',13,'Member Information','transaction','Transactions',1950,0,NULL),
(196,'coop.member_statement',13,'Member Statement of Account','transaction','Transactions',1960,1,NULL),
(197,'coop.vouchers',13,'Cooperative Vouchers','transaction','Transactions',1970,0,NULL),
(198,'coop.import',13,'Opening Balances Import','transaction','Transactions',1980,1,NULL),
(199,'coop.profile',13,'Cooperative Profile & Policies','setup','Setup',1990,1,NULL),
(200,'coop.branches',13,'Branches','setup','Setup',2000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(201,'coop.lists',13,'Member Types, Clusters & Other Lists','setup','Setup',2010,1,NULL),
(202,'coop.passbooks',13,'Passbook Types','setup','Setup',2020,1,NULL),
(203,'coop.numbering',13,'Document Numbering','setup','Setup',2030,1,NULL),
(204,'coop.templates',13,'Document Templates','setup','Setup',2040,1,NULL),
(205,'rpt.coop_members',13,'Member Master List','report','Reports',2050,1,NULL),
(206,'rpt.coop_member_movement',13,'Membership Movement','report','Reports',2060,1,NULL),
(207,'rpt.coop_share_capital',13,'Share Capital Balances','report','Reports',2070,1,NULL),
(208,'rpt.coop_cbu',13,'Capital Build-up','report','Reports',2080,1,NULL),
(209,'rpt.coop_sfc',13,'Statement of Financial Condition','report','Reports',2090,1,NULL),
(210,'rpt.coop_sop',13,'Statement of Operations','report','Reports',2100,1,NULL),
(211,'rpt.coop_sce',13,'Statement of Changes in Equity','report','Reports',2110,1,NULL),
(212,'rpt.coop_statutory_funds',13,'Statutory Funds','report','Reports',2120,1,NULL),
(213,'rpt.coop_sl_gl',13,'Subsidiary vs General Ledger Reconciliation','report','Reports',2130,1,NULL),
(214,'rpt.coop_document_prints',13,'Document Print History','report','Reports',2140,1,NULL),
(215,'coop.business_units',13,'Lines of Business','setup','Setup',2150,1,NULL),
(216,'rpt.sop_business_unit',13,'Statement of Operations by Line of Business','report','Reports',2160,1,NULL),
(217,'party.center',12,'Party Center','center','Transactions',2170,1,NULL),
(218,'party.review',12,'Identity Review','transaction','Transactions',2180,1,NULL),
(219,'party.statement',12,'Consolidated Party Statement','report','Reports',2190,1,NULL),
(220,'rpt.party_list',12,'Party List','report','Reports',2200,1,NULL),
(221,'rpt.party_role_history',12,'Party Role History','report','Reports',2210,1,NULL),
(222,'rpt.doc_reissues',12,'Reissued Documents','report','Reports',2220,1,NULL),
(223,'sav.accounts',14,'Savings Account Center','center','Transactions',2230,1,NULL),
(224,'sav.center',16,'Savings Transaction Center','center','Transactions',2752,1,NULL),
(225,'sav.transact',14,'Savings Account Transaction','transaction','Transactions',2250,1,NULL),
(226,'sav.transfer',14,'Transfer Between Accounts','transaction','Transactions',2260,1,NULL),
(227,'sav.interest',14,'Post Savings Interest','transaction','Transactions',2270,1,NULL),
(228,'sav.passbook',14,'Passbook Printing','transaction','Transactions',2280,1,NULL),
(229,'sav.td',14,'Time Deposit Center','transaction','Transactions',2290,1,NULL),
(230,'sav.batch',14,'Batch Deductions (Payroll / CBU)','transaction','Transactions',2300,1,NULL),
(231,'sav.clearing',14,'Check Clearing','transaction','Transactions',2310,1,NULL),
(232,'sav.products',14,'Savings & Deposit Products','setup','Setup',2320,1,NULL),
(233,'sav.txn_codes',14,'Savings Transaction Codes','setup','Setup',2330,1,NULL),
(234,'rpt.sav_list',14,'Savings List','report','Reports',2340,1,NULL),
(235,'rpt.sav_journal',14,'Savings Journal','report','Reports',2350,1,NULL),
(236,'rpt.sav_balances',14,'Savings Account Balances','report','Reports',2360,1,NULL),
(237,'rpt.sav_schedule',14,'Schedule of Savings Accounts','report','Reports',2370,1,NULL),
(238,'rpt.sav_dormant',14,'Dormant Accounts','report','Reports',2380,1,NULL),
(239,'rpt.sav_interest',14,'Interest Posting Register','report','Reports',2390,1,NULL),
(240,'rpt.td_list',14,'Time Deposits by Product','report','Reports',2400,1,NULL),
(241,'rpt.td_maturity',14,'Time Deposit Maturity Schedule','report','Reports',2410,1,NULL),
(242,'rpt.td_accrued',14,'Time Deposit Accrued Interest','report','Reports',2420,1,NULL),
(243,'rpt.sav_statement',14,'Savings Account Statement','report','Reports',2430,0,NULL),
(244,'loan.applications',15,'Loan Application Center','center','Transactions',2440,1,NULL),
(245,'loan.application',15,'Loan Application Form','transaction','Transactions',2450,0,NULL),
(246,'loan.ci',15,'Credit Investigation','transaction','Transactions',2460,0,NULL),
(247,'loan.release',15,'Loan Release','transaction','Transactions',2470,1,NULL),
(248,'loan.collection',16,'Loan Collection','transaction','Transactions',2754,1,NULL),
(249,'loan.accounts',15,'Loan Accounts Center','center','Transactions',2490,1,NULL),
(250,'loan.adjustments',15,'Loan Adjustments','transaction','Transactions',2500,1,NULL),
(251,'loan.waivers',15,'Interest / Penalty Waiving','transaction','Transactions',2510,1,NULL),
(252,'loan.restructure',15,'Restructure / Renew / Write Off','transaction','Transactions',2520,1,NULL),
(253,'loan.docs',15,'Loan Documents','transaction','Transactions',2530,0,NULL),
(254,'loan.penalties',15,'Post Loan Penalties & Interest Accrual','transaction','Transactions',2540,1,NULL),
(255,'loan.products',15,'Loan Products','setup','Setup',2550,1,NULL),
(256,'loan.charges',15,'Loan Charges','setup','Setup',2560,1,NULL),
(257,'loan.approval_matrix',15,'Loan Approval Matrix','setup','Setup',2570,1,NULL),
(258,'rpt.loan_products',15,'Loan Product List','report','Reports',2580,1,NULL),
(259,'rpt.loan_schedule_recv',15,'Schedule of Loans Receivable','report','Reports',2590,1,NULL),
(260,'rpt.loan_releases',15,'Loan Releases','report','Loans Summary',2600,1,NULL),
(261,'rpt.loan_due_vs_coll',15,'Loan Due vs. Collection','report','Loans Summary',2610,1,NULL),
(262,'rpt.loan_due',15,'Loans Due for Collection','report','Loans Summary',2620,1,NULL),
(263,'rpt.loan_collections',15,'Loan Collection List','report','Loans Summary',2630,1,NULL),
(264,'rpt.loan_no_payments',15,'Loans With No Payments','report','Loans Summary',2640,1,NULL),
(265,'rpt.loan_soa',15,'Loan Statement of Account','report','Loans Summary',2650,1,NULL),
(266,'rpt.loan_aging_maturity',15,'Aging of Loans by Maturity','report','Loans Aging',2660,1,NULL),
(267,'rpt.loan_aging_par',15,'Aging of Loans at PAR','report','Loans Aging',2670,1,NULL),
(268,'rpt.loan_aging_amort',15,'Aging of Loans by Amortization','report','Loans Aging',2680,1,NULL),
(269,'rpt.loan_portfolio',15,'Loan Portfolio Analysis','report','Loans Aging',2690,1,NULL),
(270,'rpt.loan_comaker',15,'Co-maker / Guarantor Exposure','report','Reports',2700,1,NULL),
(271,'rpt.loan_collateral',15,'Collateral Register','report','Reports',2710,1,NULL),
(272,'rpt.loan_gl_compare',15,'Schedule of Loans vs GL','report','Reports',2720,1,NULL),
(273,'rpt.loan_waivers',15,'Waivers and Adjustments','report','Reports',2730,1,NULL),
(274,'rpt.loan_writeoffs',15,'Written-off Loans and Recoveries','report','Reports',2740,1,NULL),
(275,'teller.shift',16,'Teller Shift & Cash Count','transaction','Transactions',2750,1,NULL),
(276,'teller.or',16,'Official Receipt (Member Collection)','transaction','Transactions',2760,1,NULL),
(277,'teller.disburse',16,'Member Disbursement (Cash Voucher)','transaction','Transactions',2770,0,NULL),
(278,'teller.receipts',16,'Official Receipt List','center','Transactions',2780,1,NULL),
(279,'rpt.teller_journal',16,'Teller Transaction Journal','report','Reports',2790,1,NULL),
(280,'rpt.teller_daily',16,'Daily Collection & Disbursement Summary','report','Reports',2800,1,NULL),
(281,'rpt.teller_over_short',16,'Teller Over / Short','report','Reports',2810,1,NULL),
(282,'rpt.teller_or_list',16,'Official Receipt Register','report','Reports',2820,1,NULL),
(283,'rpt.teller_cash_position',16,'Cash Position','report','Reports',2830,1,NULL),
(284,'dist.surplus',17,'Net Surplus Allocation','transaction','Transactions',2840,1,NULL),
(285,'dist.isc',17,'Interest on Share Capital','transaction','Transactions',2850,1,NULL),
(286,'dist.pr',17,'Patronage Refund','transaction','Transactions',2860,1,NULL),
(287,'dist.batches',17,'Distribution Batches','center','Transactions',2870,1,NULL),
(288,'rpt.dist_register',17,'Distribution Register','report','Reports',2880,1,NULL),
(289,'rpt.dist_unclaimed',17,'Unclaimed Distributions','report','Reports',2890,1,NULL),
(290,'rpt.dist_summary',17,'Net Surplus Allocation Summary','report','Reports',2900,1,NULL),
(291,'cda.officers',18,'Officers, Committees & Trainings','setup','Setup',2910,1,NULL),
(292,'cda.compliance',18,'Compliance Tracking','transaction','Transactions',2920,1,NULL),
(293,'cda.calendar',18,'Regulatory Calendar','transaction','Transactions',2930,1,NULL),
(294,'cda.capr',18,'CAPR Data Preparation & Export','transaction','Transactions',2940,1,NULL),
(295,'cda.mappings',18,'Report Field Mappings','setup','Setup',2950,1,NULL),
(296,'rpt.cda_officers',18,'List of Officers and Committees','report','Reports',2960,1,NULL),
(297,'rpt.cda_compliance',18,'Compliance Status','report','Reports',2970,1,NULL),
(298,'rpt.water_collection_summary',19,'Collection Summary','report','Reports',2980,1,NULL),
(299,'rpt.water_revenue_period',19,'Revenue by Billing Period','report','Reports',2990,1,NULL),
(300,'rpt.water_receivables_source',19,'Receivables by Source','report','Reports',3000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(301,'rpt.water_payment_history',19,'Payment History (Ledger)','report','Reports',3010,1,NULL),
(302,'rpt.water_account_statement',19,'Water Service Account Statement','report','Reports',3020,1,NULL),
(303,'rpt.water_posting_log',19,'Water Posting Log & Exceptions','report','Reports',3030,1,NULL),
(304,'rpt.water_mapping_check',19,'Account Mapping Validation','report','Reports',3040,1,NULL),
(305,'water.dashboard',19,'Water Dashboard','transaction','Overview',3050,1,NULL),
(306,'water.billing_management',19,'Billing Management','transaction','Billing',3060,1,NULL),
(307,'water.bill_period',19,'Bill Period Management','transaction','Billing',3070,1,NULL),
(308,'water.bill_codes',19,'Bill Codes','transaction','Billing',3080,1,NULL),
(309,'water.reading_entry',19,'Reading Entry','transaction','Billing',3090,1,NULL),
(310,'water.meter_reading_list_print',19,'Meter Reading List Print','transaction','Billing',3100,0,NULL),
(311,'water.reading_history',19,'Reading History','transaction','Billing',3110,1,NULL),
(312,'water.billing_history',19,'Billing History','transaction','Billing',3120,1,NULL),
(313,'water.generate_soa',19,'Generate SOA / Post Billing','transaction','Billing',3130,1,NULL),
(314,'water.billing_list',19,'Billing List','transaction','Billing',3140,1,NULL),
(315,'water.update_billing',19,'Update Billing','transaction','Billing',3150,1,NULL),
(316,'water.billing_adjustments',19,'Billing Adjustments','transaction','Billing',3160,1,NULL),
(317,'water.recurring_bill',19,'Recurring Billing','transaction','Billing',3170,1,NULL),
(318,'water.installment_bill',19,'Installment Billing','transaction','Billing',3180,1,NULL),
(319,'water.one_time_billing',19,'One-Time Billing','transaction','Billing',3190,1,NULL),
(320,'water.member_list',19,'Water Service Accounts','transaction','Service & Materials',3200,1,NULL),
(321,'water.collection_management',19,'Collection Management','transaction','Collection',3210,1,NULL),
(322,'water.water_bills_collection',19,'Collections (Cashier)','transaction','Collection',3220,1,NULL),
(323,'water.other_payments_collection',19,'Other Payments Collection','transaction','Collection',3230,0,NULL),
(324,'water.other_payments',19,'Other Payments','transaction','Collection',3240,0,NULL),
(325,'water.payment_history',19,'Payment History','transaction','Collection',3250,1,NULL),
(326,'water.disconnection_management',19,'Disconnection Management','transaction','Collection',3260,1,NULL),
(327,'water.collection_receipt',19,'Collection Receipt Print','transaction','Collection',3270,0,NULL),
(328,'water.collection_corrections',19,'Collection Corrections','transaction','Collection',3280,0,NULL),
(329,'water.customer_ledger',19,'Customer Ledger','transaction','Collection',3290,0,NULL),
(330,'water.rates_management',19,'Rates Management','transaction','Rates & Discounts',3300,1,NULL),
(331,'water.water_rates',19,'Water Rate Schedule','transaction','Rates & Discounts',3310,1,NULL),
(332,'water.water_meter',19,'Water Meter Rates','transaction','Rates & Discounts',3320,1,NULL),
(333,'water.rate_codes',19,'Rate Codes','transaction','Rates & Discounts',3330,1,NULL),
(334,'water.discount_management',19,'Discount Management','transaction','Rates & Discounts',3340,1,NULL),
(335,'water.discounts',19,'General Discounts','transaction','Rates & Discounts',3350,1,NULL),
(336,'water.discounted_members',19,'Discounted Accounts','transaction','Rates & Discounts',3360,1,NULL),
(337,'water.reports_management',19,'Water Reports','center','Reports',3370,1,NULL),
(338,'water.generate_official_receipt',19,'Generate Official Receipt','center','Reports',3380,0,NULL),
(339,'water.generate_disconnection_notice',19,'Generate Disconnection Notice','center','Reports',3390,0,NULL),
(340,'water.settings',19,'Water Settings','transaction','Setup',3400,1,NULL),
(341,'water.institution_configuration',19,'Institution Configuration','transaction','Setup',3410,0,NULL),
(342,'water.collection_policy_configuration',19,'Collection Policy','transaction','Setup',3420,0,NULL),
(343,'water.organization_units',19,'Organization Units','transaction','Setup',3430,0,'Branches, divisions and segments of the utility (former program).'),
(344,'water.branches',19,'Branches','transaction','Setup',3440,0,NULL),
(345,'water.divisions',19,'Divisions','transaction','Setup',3450,0,NULL),
(346,'water.segments',19,'Segments','transaction','Setup',3460,0,NULL),
(347,'water.import_management',19,'Data Import Management','transaction','Setup',3470,1,NULL),
(348,'water.item_issuance',19,'Material Issuance','transaction','Service & Materials',3480,1,NULL),
(349,'water.search',19,'Water Search','transaction','Setup',3490,0,NULL),
(350,'water.notifications',19,'Water Notifications','transaction','Setup',3500,0,NULL),
(351,'water.help_center',19,'Water Help Center','transaction','Setup',3510,0,NULL),
(352,'water.user_manual',19,'Water User Manual','transaction','Setup',3520,0,NULL),
(353,'water.coming_soon',19,'Coming Soon','transaction','Setup',3530,0,NULL),
(354,'loan.vouchers',15,'Loan Release Vouchers','center','Transactions',2475,1,'Every voucher of a loan release: the cash vouchers and the checks (Banking), with their class.'),
(355,'coa.account_types',2,'Account Types','list','Lists',81,1,'The account types: the built-in ones and those of your own (Cash, Loans Receivable ...), each a classification of the financial statements, linked to the Statement of Cash Flows.'),
(356,'rpt.balance_sheet_summary',2,'Balance Sheet Summary','report','Reports',181,1,'The balance sheet with the main accounts only: each main account with the total of its sub-accounts.'),
(357,'rpt.income_statement_summary',2,'Income Statement Summary','report','Reports',251,1,'The income statement with the main accounts only: each main account with the total of its sub-accounts.'),
(358,'water.share_capital_billing',19,'Fixed Share Capital Billing','transaction','Billing',3195,1,'Fixed share capital contributions of members, posted on their day and collected by the water cashier.'),
(359,'water.address_subgroups',19,'Address Sub-groups','transaction','Service & Materials',3205,1,'Add, rename, sort, switch off or delete the address sub-groups of the members and the water-service accounts.'),
(360,'water.receipt_layouts',19,'Receipt Layout Setup','transaction','Setup',3405,1,'The pre-printed water bill / collection receipt and official receipt: paper, margins and where each entry prints.'),
(361,'rpt.pos_ending_inventory',7,'Ending Inventory - POS Cashier','report','Reports',1541,1,NULL),
(362,'rpt.sales_item_group',4,'Sales by Item Group','report','Sales Reports',791,1,NULL),
(363,'rpt.pos_sales_item_group',7,'POS Sales by Item Group','report','Reports',1461,1,NULL),
(364,'rpt.cmp_is_month',2,'Comparative Income Statement by Month','report','Reports',261,1,NULL),
(365,'rpt.cmp_is_quarter',2,'Comparative Income Statement by Quarter','report','Reports',262,1,NULL),
(366,'rpt.cmp_is_year',2,'Comparative Income Statement by Year','report','Reports',263,1,NULL),
(367,'rpt.cmp_is_custom',2,'Comparative Income Statement by Custom Range','report','Reports',264,1,NULL),
(368,'rpt.cmp_bs_month',2,'Comparative Balance Sheet by Month','report','Reports',265,1,NULL),
(369,'rpt.cmp_bs_quarter',2,'Comparative Balance Sheet by Quarter','report','Reports',266,1,NULL),
(370,'rpt.cmp_bs_year',2,'Comparative Balance Sheet by Year','report','Reports',267,1,NULL),
(371,'rpt.cmp_bs_custom',2,'Comparative Balance Sheet by Custom Date','report','Reports',268,1,NULL),
(372,'rpt.avg_cost_changes',6,'Average Cost Changes','report','Inventory Reports',1245,1,NULL),
(373,'rpt.trial_balance_class',2,'Trial Balance by Class','report','Reports',175,1,NULL),
(374,'rpt.cash_flow_class',2,'Statement of Cash Flows by Class','report','Reports',275,1,NULL),
(375,'rpt.sales_customer_type_detail',4,'Sales by Customer Detail Group by Customer/Member Type','report','Sales Reports',755,1,NULL),
(376,'water.bill_balance_upload',19,'Upload Bill Balances','transaction','Billing',3197,1,'Upload the open balances of the recurring, installment, one-time and fixed share capital bills from a file, reviewed line by line before they are recorded.');

-- role_page_access (1450 rows)
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,1,1,0,0,0,1,0),
(2,2,1,0,0,0,1,0),
(2,3,1,0,0,0,1,0),
(2,4,1,0,0,0,1,0),
(2,5,1,0,0,0,1,0),
(2,6,1,0,0,0,1,0),
(2,7,1,0,0,0,1,0),
(2,8,1,1,1,1,1,0),
(2,9,1,1,1,1,1,0),
(2,10,1,1,1,1,1,0),
(2,11,1,1,1,1,1,0),
(2,12,1,1,1,1,1,0),
(2,13,1,1,1,1,1,0),
(2,14,1,1,1,1,1,0),
(2,15,1,1,1,1,1,0),
(2,16,1,1,1,1,1,0),
(2,17,1,1,1,1,1,0),
(2,18,1,1,1,1,1,0),
(2,19,1,1,1,1,1,0),
(2,20,1,1,1,1,1,0),
(2,21,1,1,1,1,1,0),
(2,22,1,1,1,1,1,0),
(2,23,1,1,1,1,1,0),
(2,24,1,1,1,1,1,0),
(2,25,1,1,1,1,1,0),
(2,26,1,1,1,1,1,0),
(2,27,1,1,1,1,1,0),
(2,28,1,1,1,1,1,0),
(2,29,1,1,1,1,1,0),
(2,30,1,1,1,1,1,0),
(2,31,1,1,1,1,1,0),
(2,32,1,1,1,1,1,0),
(2,33,1,1,1,1,1,0),
(2,34,1,1,1,1,1,0),
(2,35,1,1,1,1,1,0),
(2,36,1,1,1,1,1,0),
(2,37,1,1,1,1,1,0),
(2,38,1,1,1,1,1,0),
(2,39,1,1,1,1,1,0),
(2,40,1,1,1,1,1,0),
(2,41,1,1,1,1,1,0),
(2,42,1,1,1,1,1,0),
(2,43,1,1,1,1,1,0),
(2,44,1,1,1,1,1,0),
(2,45,1,1,1,1,1,0),
(2,46,1,1,1,1,1,0),
(2,47,1,1,1,1,1,0),
(2,48,1,1,1,1,1,0),
(2,49,1,0,0,0,1,0),
(2,52,1,0,0,0,1,0),
(2,59,1,0,0,0,1,0),
(2,61,1,0,0,0,1,0),
(2,62,1,0,0,0,1,0),
(2,63,1,0,0,0,1,0),
(2,64,1,0,0,0,1,0),
(2,65,1,0,0,0,1,0),
(2,66,1,0,0,0,1,0),
(2,67,1,0,0,0,1,0),
(2,68,1,0,0,0,1,0),
(2,69,1,0,0,0,1,0),
(2,70,1,0,0,0,1,0),
(2,71,1,0,0,0,1,0),
(2,72,1,0,0,0,1,0),
(2,73,1,0,0,0,1,0),
(2,74,1,0,0,0,1,0),
(2,75,1,0,0,0,1,0),
(2,76,1,0,0,0,1,0),
(2,77,1,0,0,0,1,0),
(2,78,1,0,0,0,1,0),
(2,79,1,0,0,0,1,0),
(2,80,1,0,0,0,1,0),
(2,81,1,0,0,0,1,0),
(2,82,1,0,0,0,1,0),
(2,83,1,0,0,0,1,0),
(2,84,1,0,0,0,1,0),
(2,85,1,0,0,0,1,0),
(2,86,1,0,0,0,1,0),
(2,87,1,0,0,0,1,0),
(2,88,1,0,0,0,1,0),
(2,89,1,0,0,0,1,0),
(2,91,1,0,0,0,1,0),
(2,97,1,0,0,0,1,0),
(2,98,1,0,0,0,1,0),
(2,99,1,0,0,0,1,0),
(2,100,1,0,0,0,1,0),
(2,101,1,0,0,0,1,0),
(2,102,1,0,0,0,1,0),
(2,103,1,0,0,0,1,0),
(2,104,1,0,0,0,1,0),
(2,105,1,0,0,0,1,0),
(2,106,1,0,0,0,1,0),
(2,107,1,0,0,0,1,0),
(2,108,1,0,0,0,1,0),
(2,109,1,0,0,0,1,0),
(2,110,1,0,0,0,1,0),
(2,112,1,0,0,0,1,0),
(2,113,1,0,0,0,1,0),
(2,115,1,0,0,0,1,0),
(2,117,1,0,0,0,1,0),
(2,121,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,122,1,0,0,0,1,0),
(2,123,1,0,0,0,1,0),
(2,124,1,0,0,0,1,0),
(2,125,1,0,0,0,1,0),
(2,126,1,0,0,0,1,0),
(2,127,1,0,0,0,1,0),
(2,128,1,0,0,0,1,0),
(2,129,1,0,0,0,1,0),
(2,130,1,0,0,0,1,0),
(2,131,1,0,0,0,1,0),
(2,132,1,0,0,0,1,0),
(2,133,1,0,0,0,1,0),
(2,134,1,0,0,0,1,0),
(2,135,1,0,0,0,1,0),
(2,136,1,0,0,0,1,0),
(2,137,1,0,0,0,1,0),
(2,138,1,0,0,0,1,0),
(2,139,1,0,0,0,1,0),
(2,145,1,0,0,0,1,0),
(2,146,1,0,0,0,1,0),
(2,147,1,0,0,0,1,0),
(2,148,1,0,0,0,1,0),
(2,149,1,0,0,0,1,0),
(2,150,1,0,0,0,1,0),
(2,151,1,0,0,0,1,0),
(2,152,1,0,0,0,1,0),
(2,153,1,0,0,0,1,0),
(2,154,1,0,0,0,1,0),
(2,155,1,1,1,1,1,0),
(2,156,1,1,1,1,1,0),
(2,157,1,1,1,1,1,0),
(2,158,1,1,1,1,1,0),
(2,159,1,1,1,1,1,0),
(2,160,1,1,1,1,1,0),
(2,161,1,1,1,1,1,0),
(2,162,1,1,1,1,1,0),
(2,163,1,1,1,1,1,0),
(2,164,1,1,1,1,1,0),
(2,165,1,1,1,1,1,0),
(2,166,1,1,1,1,1,0),
(2,167,1,1,1,1,1,0),
(2,168,1,1,1,1,1,0),
(2,169,1,1,1,1,1,0),
(2,170,1,1,1,1,1,0),
(2,171,1,1,1,1,1,0),
(2,172,1,1,1,1,1,0),
(2,173,1,1,1,1,1,0),
(2,174,1,1,1,1,1,0),
(2,180,1,0,0,0,1,0),
(2,194,1,0,0,0,1,0),
(2,197,1,1,0,0,1,0),
(2,198,1,1,0,0,1,0),
(2,205,1,0,0,0,1,0),
(2,206,1,0,0,0,1,0),
(2,207,1,0,0,0,1,0),
(2,208,1,0,0,0,1,0),
(2,209,1,0,0,0,1,0),
(2,210,1,0,0,0,1,0),
(2,211,1,0,0,0,1,0),
(2,212,1,0,0,0,1,0),
(2,213,1,0,0,0,1,0),
(2,214,1,0,0,0,1,0),
(2,216,1,0,0,0,1,0),
(2,217,1,1,1,0,1,0),
(2,218,1,0,0,0,1,0),
(2,219,1,0,0,0,1,0),
(2,220,1,0,0,0,1,0),
(2,221,1,0,0,0,1,0),
(2,222,1,0,0,0,1,0),
(2,223,1,0,0,0,1,0),
(2,227,1,1,0,0,1,0),
(2,234,1,0,0,0,1,0),
(2,235,1,0,0,0,1,0),
(2,236,1,0,0,0,1,0),
(2,237,1,0,0,0,1,0),
(2,238,1,0,0,0,1,0),
(2,239,1,0,0,0,1,0),
(2,240,1,0,0,0,1,0),
(2,241,1,0,0,0,1,0),
(2,242,1,0,0,0,1,0),
(2,243,1,0,0,0,1,0),
(2,249,1,0,0,0,1,0),
(2,254,1,1,0,0,1,0),
(2,258,1,0,0,0,1,0),
(2,259,1,0,0,0,1,0),
(2,260,1,0,0,0,1,0),
(2,261,1,0,0,0,1,0),
(2,262,1,0,0,0,1,0),
(2,263,1,0,0,0,1,0),
(2,264,1,0,0,0,1,0),
(2,265,1,0,0,0,1,0),
(2,266,1,0,0,0,1,0),
(2,267,1,0,0,0,1,0),
(2,268,1,0,0,0,1,0),
(2,269,1,0,0,0,1,0),
(2,270,1,0,0,0,1,0),
(2,271,1,0,0,0,1,0),
(2,272,1,0,0,0,1,0),
(2,273,1,0,0,0,1,0),
(2,274,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,278,1,0,0,0,1,0),
(2,279,1,0,0,0,1,0),
(2,280,1,0,0,0,1,0),
(2,281,1,0,0,0,1,0),
(2,282,1,0,0,0,1,0),
(2,283,1,0,0,0,1,0),
(2,284,1,1,0,0,1,0),
(2,285,1,1,0,0,1,0),
(2,286,1,1,0,0,1,0),
(2,287,1,1,0,0,1,0),
(2,288,1,0,0,0,1,0),
(2,289,1,0,0,0,1,0),
(2,290,1,0,0,0,1,0),
(2,296,1,0,0,0,1,0),
(2,297,1,0,0,0,1,0),
(2,298,1,0,0,0,1,0),
(2,299,1,0,0,0,1,0),
(2,300,1,0,0,0,1,0),
(2,301,1,0,0,0,1,0),
(2,302,1,0,0,0,1,0),
(2,303,1,0,0,0,1,0),
(2,304,1,0,0,0,1,0),
(2,305,1,0,0,0,1,0),
(2,306,1,0,0,0,1,0),
(2,307,1,0,0,0,1,0),
(2,308,1,0,0,0,1,0),
(2,309,1,0,0,0,1,0),
(2,310,1,0,0,0,1,0),
(2,311,1,0,0,0,1,0),
(2,312,1,0,0,0,1,0),
(2,313,1,0,0,0,1,0),
(2,314,1,0,0,0,1,0),
(2,315,1,0,0,0,1,0),
(2,316,1,0,0,0,1,0),
(2,317,1,0,0,0,1,0),
(2,318,1,0,0,0,1,0),
(2,319,1,0,0,0,1,0),
(2,320,1,0,0,0,1,0),
(2,321,1,0,0,0,1,0),
(2,322,1,0,0,0,1,0),
(2,323,1,0,0,0,1,0),
(2,324,1,0,0,0,1,0),
(2,325,1,0,0,0,1,0),
(2,326,1,0,0,0,1,0),
(2,327,1,0,0,0,1,0),
(2,328,1,0,0,0,1,0),
(2,329,1,0,0,0,1,0),
(2,330,1,0,0,0,1,0),
(2,331,1,0,0,0,1,0),
(2,332,1,0,0,0,1,0),
(2,333,1,0,0,0,1,0),
(2,334,1,0,0,0,1,0),
(2,335,1,0,0,0,1,0),
(2,336,1,0,0,0,1,0),
(2,337,1,0,0,0,1,0),
(2,338,1,0,0,0,1,0),
(2,339,1,0,0,0,1,0),
(2,340,1,0,0,0,1,0),
(2,341,1,0,0,0,1,0),
(2,342,1,0,0,0,1,0),
(2,343,1,0,0,0,1,0),
(2,344,1,0,0,0,1,0),
(2,345,1,0,0,0,1,0),
(2,346,1,0,0,0,1,0),
(2,347,1,0,0,0,1,0),
(2,348,1,0,0,0,1,0),
(2,349,1,0,0,0,1,0),
(2,350,1,0,0,0,1,0),
(2,351,1,0,0,0,1,0),
(2,352,1,0,0,0,1,0),
(2,353,1,0,0,0,1,0),
(2,355,1,1,1,1,1,0),
(2,356,1,1,1,1,1,0),
(2,357,1,1,1,1,1,0),
(2,358,1,0,0,0,1,0),
(2,359,1,0,0,0,1,0),
(2,360,1,0,0,0,1,0),
(2,361,1,0,0,0,1,0),
(2,362,1,0,0,0,1,0),
(2,363,1,0,0,0,1,0),
(2,364,1,0,0,0,1,0),
(2,365,1,0,0,0,1,0),
(2,366,1,0,0,0,1,0),
(2,367,1,0,0,0,1,0),
(2,368,1,0,0,0,1,0),
(2,369,1,0,0,0,1,0),
(2,370,1,0,0,0,1,0),
(2,371,1,0,0,0,1,0),
(2,372,1,0,0,0,1,0),
(2,373,1,0,0,0,1,0),
(2,374,1,0,0,0,1,0),
(2,375,1,0,0,0,1,0),
(2,376,1,0,0,0,1,0),
(3,1,1,0,0,0,1,0),
(3,2,1,0,0,0,1,0),
(3,3,1,0,0,0,1,0),
(3,4,1,0,0,0,1,0),
(3,5,1,0,0,0,1,0),
(3,6,1,0,0,0,1,0),
(3,7,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,49,1,1,1,0,1,0),
(3,50,1,1,1,0,1,0),
(3,51,1,1,1,0,1,0),
(3,52,1,1,1,0,1,0),
(3,53,1,1,1,0,1,0),
(3,54,1,1,1,0,1,0),
(3,55,1,1,1,0,1,0),
(3,56,1,1,1,0,1,0),
(3,57,1,1,1,0,1,0),
(3,58,1,1,1,0,1,0),
(3,59,1,1,1,0,1,0),
(3,60,1,1,1,0,1,0),
(3,61,1,0,0,0,1,0),
(3,62,1,0,0,0,1,0),
(3,63,1,0,0,0,1,0),
(3,64,1,0,0,0,1,0),
(3,65,1,0,0,0,1,0),
(3,66,1,0,0,0,1,0),
(3,67,1,0,0,0,1,0),
(3,68,1,0,0,0,1,0),
(3,69,1,0,0,0,1,0),
(3,70,1,0,0,0,1,0),
(3,71,1,0,0,0,1,0),
(3,72,1,0,0,0,1,0),
(3,109,1,0,0,0,0,0),
(3,217,1,1,1,0,1,0),
(3,219,1,0,0,0,1,0),
(3,220,1,0,0,0,1,0),
(4,1,1,0,0,0,1,0),
(4,2,1,0,0,0,1,0),
(4,3,1,0,0,0,1,0),
(4,4,1,0,0,0,1,0),
(4,5,1,0,0,0,1,0),
(4,6,1,0,0,0,1,0),
(4,7,1,0,0,0,1,0),
(4,87,1,1,1,0,1,0),
(4,88,1,1,1,0,1,0),
(4,89,1,1,1,0,1,0),
(4,90,1,1,1,0,1,0),
(4,91,1,1,1,0,1,0),
(4,92,1,1,1,0,1,0),
(4,93,1,1,1,0,1,0),
(4,94,1,1,1,0,1,0),
(4,95,1,1,1,0,1,0),
(4,96,1,1,1,0,1,0),
(4,97,1,1,1,0,1,0),
(4,98,1,0,0,0,1,0),
(4,99,1,0,0,0,1,0),
(4,100,1,0,0,0,1,0),
(4,101,1,0,0,0,1,0),
(4,102,1,0,0,0,1,0),
(4,103,1,0,0,0,1,0),
(4,104,1,0,0,0,1,0),
(4,105,1,0,0,0,1,0),
(4,106,1,0,0,0,1,0),
(4,107,1,0,0,0,1,0),
(4,109,1,0,0,0,0,0),
(4,165,1,0,0,0,1,0),
(4,166,1,0,0,0,0,0),
(4,167,1,0,0,0,1,0),
(4,168,1,0,0,0,1,0),
(4,169,1,0,0,0,1,0),
(4,170,1,0,0,0,1,0),
(4,217,1,1,1,0,1,0),
(4,219,1,0,0,0,1,0),
(4,220,1,0,0,0,1,0),
(5,1,1,0,0,0,1,0),
(5,2,1,0,0,0,1,0),
(5,3,1,0,0,0,1,0),
(5,4,1,0,0,0,1,0),
(5,5,1,0,0,0,1,0),
(5,6,1,0,0,0,1,0),
(5,7,1,0,0,0,1,0),
(5,93,1,0,0,0,1,0),
(5,95,1,1,1,0,1,0),
(5,108,1,0,0,0,1,0),
(5,109,1,1,1,0,1,0),
(5,110,1,1,1,1,1,0),
(5,111,1,1,1,0,1,0),
(5,112,1,1,1,0,1,0),
(5,113,1,1,1,0,1,0),
(5,114,1,1,1,0,1,0),
(5,115,1,1,1,0,1,0),
(5,116,1,1,1,0,1,0),
(5,117,1,1,1,0,1,0),
(5,118,1,0,0,0,1,0),
(5,121,1,0,0,0,1,0),
(5,125,1,0,0,0,1,0),
(5,126,1,0,0,0,1,0),
(5,127,1,0,0,0,1,0),
(5,128,1,0,0,0,1,0),
(5,129,1,0,0,0,1,0),
(5,130,1,0,0,0,1,0),
(5,131,1,0,0,0,1,0),
(5,133,1,0,0,0,1,0),
(5,134,1,0,0,0,1,0),
(5,135,1,0,0,0,1,0),
(5,137,1,0,0,0,1,0),
(5,138,1,0,0,0,1,0),
(5,139,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(6,1,1,0,0,0,1,0),
(6,2,1,0,0,0,1,0),
(6,3,1,0,0,0,1,0),
(6,4,1,0,0,0,1,0),
(6,5,1,0,0,0,1,0),
(6,6,1,0,0,0,1,0),
(6,7,1,0,0,0,1,0),
(6,49,1,0,0,0,1,0),
(6,50,1,1,1,0,1,0),
(6,51,1,1,1,0,1,0),
(6,52,1,1,1,0,1,0),
(6,72,1,0,0,0,1,0),
(6,73,1,0,0,0,1,0),
(6,74,1,0,0,0,1,0),
(6,75,1,0,0,0,1,0),
(6,76,1,0,0,0,1,0),
(6,77,1,0,0,0,1,0),
(6,78,1,0,0,0,1,0),
(6,79,1,0,0,0,1,0),
(6,80,1,0,0,0,1,0),
(6,81,1,0,0,0,1,0),
(6,82,1,0,0,0,1,0),
(6,83,1,0,0,0,1,0),
(6,84,1,0,0,0,1,0),
(6,85,1,0,0,0,1,0),
(6,86,1,0,0,0,1,0),
(6,139,1,0,0,0,1,0),
(6,362,1,0,0,0,1,0),
(6,375,1,0,0,0,1,0),
(7,1,1,0,0,0,0,0),
(7,140,1,1,0,0,1,0),
(7,141,1,1,0,0,1,0),
(7,142,1,1,0,1,1,0),
(7,298,1,1,1,0,1,0),
(7,301,1,1,1,0,1,0),
(7,305,1,1,1,0,1,0),
(7,320,1,1,1,0,1,0),
(7,321,1,1,1,0,1,0),
(7,322,1,1,1,0,1,0),
(7,323,1,1,1,0,1,0),
(7,324,1,1,1,0,1,0),
(7,325,1,1,1,0,1,0),
(7,327,1,1,1,0,1,0),
(7,329,1,1,1,0,1,0),
(7,338,1,1,1,0,1,0),
(7,359,1,1,1,0,1,0),
(7,361,1,0,0,0,1,0),
(8,1,1,0,0,0,0,0),
(8,2,1,0,0,0,0,0),
(8,140,1,1,1,1,1,1),
(8,141,1,1,1,1,1,1),
(8,142,1,1,1,1,1,1),
(8,143,1,1,1,1,1,1),
(8,144,1,0,0,0,1,0),
(8,145,1,0,0,0,1,0),
(8,146,1,0,0,0,1,0),
(8,147,1,0,0,0,1,0),
(8,148,1,0,0,0,1,0),
(8,149,1,0,0,0,1,0),
(8,150,1,0,0,0,1,0),
(8,151,1,0,0,0,1,0),
(8,152,1,0,0,0,1,0),
(8,153,1,0,0,0,1,0),
(8,154,1,0,0,0,1,0),
(8,361,1,0,0,0,1,0),
(8,363,1,0,0,0,1,0),
(9,1,1,0,0,0,1,0),
(9,2,1,0,0,0,1,0),
(9,3,1,0,0,0,1,0),
(9,4,1,0,0,0,1,0),
(9,5,1,0,0,0,1,0),
(9,6,1,0,0,0,1,0),
(9,7,1,0,0,0,1,0),
(9,8,1,0,0,0,1,0),
(9,9,1,0,0,0,1,0),
(9,10,1,0,0,0,1,0),
(9,11,1,0,0,0,1,0),
(9,12,1,0,0,0,1,0),
(9,13,1,0,0,0,1,0),
(9,14,1,0,0,0,1,0),
(9,15,1,0,0,0,1,0),
(9,16,1,0,0,0,1,0),
(9,17,1,0,0,0,1,0),
(9,18,1,0,0,0,1,0),
(9,19,1,0,0,0,1,0),
(9,20,1,0,0,0,1,0),
(9,21,1,0,0,0,1,0),
(9,22,1,0,0,0,1,0),
(9,23,1,0,0,0,1,0),
(9,24,1,0,0,0,1,0),
(9,25,1,0,0,0,1,0),
(9,26,1,0,0,0,1,0),
(9,27,1,0,0,0,1,0),
(9,28,1,0,0,0,1,0),
(9,29,1,0,0,0,1,0),
(9,30,1,0,0,0,1,0),
(9,31,1,0,0,0,1,0),
(9,32,1,0,0,0,1,0),
(9,33,1,0,0,0,1,0),
(9,34,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,35,1,0,0,0,1,0),
(9,36,1,0,0,0,1,0),
(9,37,1,0,0,0,1,0),
(9,38,1,0,0,0,1,0),
(9,39,1,0,0,0,1,0),
(9,40,1,0,0,0,1,0),
(9,41,1,0,0,0,1,0),
(9,42,1,0,0,0,1,0),
(9,43,1,0,0,0,1,0),
(9,44,1,0,0,0,1,0),
(9,45,1,0,0,0,1,0),
(9,46,1,0,0,0,1,0),
(9,47,1,0,0,0,1,0),
(9,48,1,0,0,0,1,0),
(9,49,1,0,0,0,1,0),
(9,50,1,0,0,0,1,0),
(9,51,1,0,0,0,1,0),
(9,52,1,0,0,0,1,0),
(9,53,1,0,0,0,1,0),
(9,54,1,0,0,0,1,0),
(9,55,1,0,0,0,1,0),
(9,56,1,0,0,0,1,0),
(9,57,1,0,0,0,1,0),
(9,58,1,0,0,0,1,0),
(9,59,1,0,0,0,1,0),
(9,60,1,0,0,0,1,0),
(9,61,1,0,0,0,1,0),
(9,62,1,0,0,0,1,0),
(9,63,1,0,0,0,1,0),
(9,64,1,0,0,0,1,0),
(9,65,1,0,0,0,1,0),
(9,66,1,0,0,0,1,0),
(9,67,1,0,0,0,1,0),
(9,68,1,0,0,0,1,0),
(9,69,1,0,0,0,1,0),
(9,70,1,0,0,0,1,0),
(9,71,1,0,0,0,1,0),
(9,72,1,0,0,0,1,0),
(9,73,1,0,0,0,1,0),
(9,74,1,0,0,0,1,0),
(9,75,1,0,0,0,1,0),
(9,76,1,0,0,0,1,0),
(9,77,1,0,0,0,1,0),
(9,78,1,0,0,0,1,0),
(9,79,1,0,0,0,1,0),
(9,80,1,0,0,0,1,0),
(9,81,1,0,0,0,1,0),
(9,82,1,0,0,0,1,0),
(9,83,1,0,0,0,1,0),
(9,84,1,0,0,0,1,0),
(9,85,1,0,0,0,1,0),
(9,86,1,0,0,0,1,0),
(9,87,1,0,0,0,1,0),
(9,88,1,0,0,0,1,0),
(9,89,1,0,0,0,1,0),
(9,90,1,0,0,0,1,0),
(9,91,1,0,0,0,1,0),
(9,92,1,0,0,0,1,0),
(9,93,1,0,0,0,1,0),
(9,94,1,0,0,0,1,0),
(9,95,1,0,0,0,1,0),
(9,96,1,0,0,0,1,0),
(9,97,1,0,0,0,1,0),
(9,98,1,0,0,0,1,0),
(9,99,1,0,0,0,1,0),
(9,100,1,0,0,0,1,0),
(9,101,1,0,0,0,1,0),
(9,102,1,0,0,0,1,0),
(9,103,1,0,0,0,1,0),
(9,104,1,0,0,0,1,0),
(9,105,1,0,0,0,1,0),
(9,106,1,0,0,0,1,0),
(9,107,1,0,0,0,1,0),
(9,108,1,0,0,0,1,0),
(9,109,1,0,0,0,1,0),
(9,110,1,0,0,0,1,0),
(9,111,1,0,0,0,1,0),
(9,112,1,0,0,0,1,0),
(9,113,1,0,0,0,1,0),
(9,114,1,0,0,0,1,0),
(9,115,1,0,0,0,1,0),
(9,116,1,0,0,0,1,0),
(9,117,1,0,0,0,1,0),
(9,118,1,0,0,0,1,0),
(9,119,1,0,0,0,1,0),
(9,120,1,0,0,0,1,0),
(9,121,1,0,0,0,1,0),
(9,122,1,0,0,0,1,0),
(9,123,1,0,0,0,1,0),
(9,124,1,0,0,0,1,0),
(9,125,1,0,0,0,1,0),
(9,126,1,0,0,0,1,0),
(9,127,1,0,0,0,1,0),
(9,128,1,0,0,0,1,0),
(9,129,1,0,0,0,1,0),
(9,130,1,0,0,0,1,0),
(9,131,1,0,0,0,1,0),
(9,132,1,0,0,0,1,0),
(9,133,1,0,0,0,1,0),
(9,134,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,135,1,0,0,0,1,0),
(9,136,1,0,0,0,1,0),
(9,137,1,0,0,0,1,0),
(9,138,1,0,0,0,1,0),
(9,139,1,0,0,0,1,0),
(9,142,1,0,0,0,1,0),
(9,143,1,0,0,0,1,0),
(9,144,1,0,0,0,1,0),
(9,145,1,0,0,0,1,0),
(9,146,1,0,0,0,1,0),
(9,147,1,0,0,0,1,0),
(9,148,1,0,0,0,1,0),
(9,149,1,0,0,0,1,0),
(9,150,1,0,0,0,1,0),
(9,151,1,0,0,0,1,0),
(9,152,1,0,0,0,1,0),
(9,153,1,0,0,0,1,0),
(9,154,1,0,0,0,1,0),
(9,155,1,0,0,0,1,0),
(9,156,1,0,0,0,1,0),
(9,157,1,0,0,0,1,0),
(9,158,1,0,0,0,1,0),
(9,159,1,0,0,0,1,0),
(9,160,1,0,0,0,1,0),
(9,161,1,0,0,0,1,0),
(9,162,1,0,0,0,1,0),
(9,163,1,0,0,0,1,0),
(9,164,1,0,0,0,1,0),
(9,165,1,0,0,0,1,0),
(9,166,1,0,0,0,1,0),
(9,167,1,0,0,0,1,0),
(9,168,1,0,0,0,1,0),
(9,169,1,0,0,0,1,0),
(9,170,1,0,0,0,1,0),
(9,171,1,0,0,0,1,0),
(9,172,1,0,0,0,1,0),
(9,173,1,0,0,0,1,0),
(9,174,1,0,0,0,1,0),
(9,184,1,0,0,0,1,0),
(9,185,1,0,0,0,1,0),
(9,186,1,0,0,0,1,0),
(9,187,1,0,0,0,1,0),
(9,188,1,0,0,0,1,0),
(9,189,1,0,0,0,1,0),
(9,190,1,0,0,0,1,0),
(9,194,1,0,0,0,1,0),
(9,195,1,0,0,0,1,0),
(9,196,1,0,0,0,1,0),
(9,197,1,0,0,0,1,0),
(9,198,1,0,0,0,1,0),
(9,199,1,0,0,0,1,0),
(9,200,1,0,0,0,1,0),
(9,201,1,0,0,0,1,0),
(9,202,1,0,0,0,1,0),
(9,203,1,0,0,0,1,0),
(9,204,1,0,0,0,1,0),
(9,205,1,0,0,0,1,0),
(9,206,1,0,0,0,1,0),
(9,207,1,0,0,0,1,0),
(9,208,1,0,0,0,1,0),
(9,209,1,0,0,0,1,0),
(9,210,1,0,0,0,1,0),
(9,211,1,0,0,0,1,0),
(9,212,1,0,0,0,1,0),
(9,213,1,0,0,0,1,0),
(9,214,1,0,0,0,1,0),
(9,215,1,0,0,0,1,0),
(9,216,1,0,0,0,1,0),
(9,217,1,0,0,0,1,0),
(9,218,1,0,0,0,1,0),
(9,219,1,0,0,0,1,0),
(9,220,1,0,0,0,1,0),
(9,221,1,0,0,0,1,0),
(9,222,1,0,0,0,1,0),
(9,223,1,0,0,0,1,0),
(9,224,1,0,0,0,1,0),
(9,225,1,0,0,0,1,0),
(9,226,1,0,0,0,1,0),
(9,227,1,0,0,0,1,0),
(9,228,1,0,0,0,1,0),
(9,229,1,0,0,0,1,0),
(9,230,1,0,0,0,1,0),
(9,231,1,0,0,0,1,0),
(9,232,1,0,0,0,1,0),
(9,233,1,0,0,0,1,0),
(9,234,1,0,0,0,1,0),
(9,235,1,0,0,0,1,0),
(9,236,1,0,0,0,1,0),
(9,237,1,0,0,0,1,0),
(9,238,1,0,0,0,1,0),
(9,239,1,0,0,0,1,0),
(9,240,1,0,0,0,1,0),
(9,241,1,0,0,0,1,0),
(9,242,1,0,0,0,1,0),
(9,243,1,0,0,0,1,0),
(9,244,1,0,0,0,1,0),
(9,245,1,0,0,0,1,0),
(9,246,1,0,0,0,1,0),
(9,247,1,0,0,0,1,0),
(9,248,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,249,1,0,0,0,1,0),
(9,250,1,0,0,0,1,0),
(9,251,1,0,0,0,1,0),
(9,252,1,0,0,0,1,0),
(9,253,1,0,0,0,1,0),
(9,254,1,0,0,0,1,0),
(9,255,1,0,0,0,1,0),
(9,256,1,0,0,0,1,0),
(9,257,1,0,0,0,1,0),
(9,258,1,0,0,0,1,0),
(9,259,1,0,0,0,1,0),
(9,260,1,0,0,0,1,0),
(9,261,1,0,0,0,1,0),
(9,262,1,0,0,0,1,0),
(9,263,1,0,0,0,1,0),
(9,264,1,0,0,0,1,0),
(9,265,1,0,0,0,1,0),
(9,266,1,0,0,0,1,0),
(9,267,1,0,0,0,1,0),
(9,268,1,0,0,0,1,0),
(9,269,1,0,0,0,1,0),
(9,270,1,0,0,0,1,0),
(9,271,1,0,0,0,1,0),
(9,272,1,0,0,0,1,0),
(9,273,1,0,0,0,1,0),
(9,274,1,0,0,0,1,0),
(9,278,1,0,0,0,1,0),
(9,279,1,0,0,0,1,0),
(9,280,1,0,0,0,1,0),
(9,281,1,0,0,0,1,0),
(9,282,1,0,0,0,1,0),
(9,283,1,0,0,0,1,0),
(9,284,1,0,0,0,1,0),
(9,285,1,0,0,0,1,0),
(9,286,1,0,0,0,1,0),
(9,287,1,0,0,0,1,0),
(9,288,1,0,0,0,1,0),
(9,289,1,0,0,0,1,0),
(9,290,1,0,0,0,1,0),
(9,291,1,0,0,0,1,0),
(9,292,1,0,0,0,1,0),
(9,293,1,0,0,0,1,0),
(9,294,1,0,0,0,1,0),
(9,295,1,0,0,0,1,0),
(9,296,1,0,0,0,1,0),
(9,297,1,0,0,0,1,0),
(9,298,1,0,0,0,1,0),
(9,299,1,0,0,0,1,0),
(9,300,1,0,0,0,1,0),
(9,301,1,0,0,0,1,0),
(9,302,1,0,0,0,1,0),
(9,303,1,0,0,0,1,0),
(9,304,1,0,0,0,1,0),
(9,305,1,0,0,0,1,0),
(9,306,1,0,0,0,1,0),
(9,307,1,0,0,0,1,0),
(9,308,1,0,0,0,1,0),
(9,309,1,0,0,0,1,0),
(9,310,1,0,0,0,1,0),
(9,311,1,0,0,0,1,0),
(9,312,1,0,0,0,1,0),
(9,313,1,0,0,0,1,0),
(9,314,1,0,0,0,1,0),
(9,315,1,0,0,0,1,0),
(9,316,1,0,0,0,1,0),
(9,317,1,0,0,0,1,0),
(9,318,1,0,0,0,1,0),
(9,319,1,0,0,0,1,0),
(9,320,1,0,0,0,1,0),
(9,321,1,0,0,0,1,0),
(9,322,1,0,0,0,1,0),
(9,323,1,0,0,0,1,0),
(9,324,1,0,0,0,1,0),
(9,325,1,0,0,0,1,0),
(9,326,1,0,0,0,1,0),
(9,327,1,0,0,0,1,0),
(9,328,1,0,0,0,1,0),
(9,329,1,0,0,0,1,0),
(9,330,1,0,0,0,1,0),
(9,331,1,0,0,0,1,0),
(9,332,1,0,0,0,1,0),
(9,333,1,0,0,0,1,0),
(9,334,1,0,0,0,1,0),
(9,335,1,0,0,0,1,0),
(9,336,1,0,0,0,1,0),
(9,337,1,0,0,0,1,0),
(9,338,1,0,0,0,1,0),
(9,339,1,0,0,0,1,0),
(9,340,1,0,0,0,1,0),
(9,341,1,0,0,0,1,0),
(9,342,1,0,0,0,1,0),
(9,343,1,0,0,0,1,0),
(9,344,1,0,0,0,1,0),
(9,345,1,0,0,0,1,0),
(9,346,1,0,0,0,1,0),
(9,347,1,0,0,0,1,0),
(9,348,1,0,0,0,1,0),
(9,349,1,0,0,0,1,0),
(9,350,1,0,0,0,1,0),
(9,351,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(9,352,1,0,0,0,1,0),
(9,353,1,0,0,0,1,0),
(9,354,1,0,0,0,1,0),
(9,355,1,0,0,0,1,0),
(9,356,1,0,0,0,1,0),
(9,357,1,0,0,0,1,0),
(9,358,1,0,0,0,1,0),
(9,359,1,0,0,0,1,0),
(9,360,1,0,0,0,1,0),
(9,361,1,0,0,0,1,0),
(9,362,1,0,0,0,1,0),
(9,363,1,0,0,0,1,0),
(9,364,1,0,0,0,1,0),
(9,365,1,0,0,0,1,0),
(9,366,1,0,0,0,1,0),
(9,367,1,0,0,0,1,0),
(9,368,1,0,0,0,1,0),
(9,369,1,0,0,0,1,0),
(9,370,1,0,0,0,1,0),
(9,371,1,0,0,0,1,0),
(9,372,1,0,0,0,1,0),
(9,373,1,0,0,0,1,0),
(9,374,1,0,0,0,1,0),
(9,375,1,0,0,0,1,0),
(9,376,1,0,0,0,1,0),
(10,1,1,1,1,1,1,1),
(10,2,1,1,1,1,1,1),
(10,3,1,1,1,1,1,1),
(10,4,1,1,1,1,1,1),
(10,5,1,1,1,1,1,1),
(10,6,1,1,1,1,1,1),
(10,7,1,1,1,1,1,1),
(10,8,1,1,1,1,1,1),
(10,13,1,1,1,1,1,1),
(10,14,1,1,1,1,1,1),
(10,15,1,1,1,1,1,1),
(10,17,1,1,1,1,1,1),
(10,18,1,1,1,1,1,1),
(10,19,1,1,1,1,1,1),
(10,25,1,1,1,1,1,1),
(10,26,1,1,1,1,1,1),
(10,27,1,1,1,1,1,1),
(10,28,1,1,1,1,1,1),
(10,29,1,1,1,1,1,1),
(10,30,1,1,1,1,1,1),
(10,31,1,1,1,1,1,1),
(10,194,1,1,1,1,1,1),
(10,195,1,1,1,1,1,1),
(10,196,1,1,1,1,1,1),
(10,197,1,1,1,1,1,1),
(10,198,1,1,1,1,1,1),
(10,199,1,1,1,1,1,1),
(10,200,1,1,1,1,1,1),
(10,201,1,1,1,1,1,1),
(10,202,1,1,1,1,1,1),
(10,203,1,1,1,1,1,1),
(10,204,1,1,1,1,1,1),
(10,205,1,1,1,1,1,1),
(10,206,1,1,1,1,1,1),
(10,207,1,1,1,1,1,1),
(10,208,1,1,1,1,1,1),
(10,209,1,1,1,1,1,1),
(10,210,1,1,1,1,1,1),
(10,211,1,1,1,1,1,1),
(10,212,1,1,1,1,1,1),
(10,213,1,1,1,1,1,1),
(10,214,1,1,1,1,1,1),
(10,215,1,1,1,0,1,1),
(10,216,1,1,1,0,1,1),
(10,217,1,1,1,0,1,1),
(10,218,1,1,1,0,1,1),
(10,219,1,1,1,0,1,1),
(10,220,1,1,1,0,1,1),
(10,221,1,1,1,0,1,1),
(10,222,1,1,1,0,1,1),
(10,223,1,1,1,1,1,1),
(10,224,1,1,1,1,1,1),
(10,225,1,1,1,1,1,1),
(10,226,1,1,1,1,1,1),
(10,227,1,1,1,1,1,1),
(10,228,1,1,1,1,1,1),
(10,229,1,1,1,1,1,1),
(10,230,1,1,1,1,1,1),
(10,231,1,1,1,1,1,1),
(10,232,1,1,1,1,1,1),
(10,233,1,1,1,1,1,1),
(10,234,1,1,1,1,1,1),
(10,235,1,1,1,1,1,1),
(10,236,1,1,1,1,1,1),
(10,237,1,1,1,1,1,1),
(10,238,1,1,1,1,1,1),
(10,239,1,1,1,1,1,1),
(10,240,1,1,1,1,1,1),
(10,241,1,1,1,1,1,1),
(10,242,1,1,1,1,1,1),
(10,243,1,1,1,1,1,1),
(10,244,1,1,1,1,1,1),
(10,245,1,1,1,1,1,1),
(10,246,1,1,1,1,1,1),
(10,247,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,248,1,1,1,1,1,1),
(10,249,1,1,1,1,1,1),
(10,250,1,1,1,1,1,1),
(10,251,1,1,1,1,1,1),
(10,252,1,1,1,1,1,1),
(10,253,1,1,1,1,1,1),
(10,254,1,1,1,1,1,1),
(10,255,1,1,1,1,1,1),
(10,256,1,1,1,1,1,1),
(10,257,1,1,1,1,1,1),
(10,258,1,1,1,1,1,1),
(10,259,1,1,1,1,1,1),
(10,260,1,1,1,1,1,1),
(10,261,1,1,1,1,1,1),
(10,262,1,1,1,1,1,1),
(10,263,1,1,1,1,1,1),
(10,264,1,1,1,1,1,1),
(10,265,1,1,1,1,1,1),
(10,266,1,1,1,1,1,1),
(10,267,1,1,1,1,1,1),
(10,268,1,1,1,1,1,1),
(10,269,1,1,1,1,1,1),
(10,270,1,1,1,1,1,1),
(10,271,1,1,1,1,1,1),
(10,272,1,1,1,1,1,1),
(10,273,1,1,1,1,1,1),
(10,274,1,1,1,1,1,1),
(10,275,1,1,1,1,1,1),
(10,276,1,1,1,1,1,1),
(10,277,1,1,1,1,1,1),
(10,278,1,1,1,1,1,1),
(10,279,1,1,1,1,1,1),
(10,280,1,1,1,1,1,1),
(10,281,1,1,1,1,1,1),
(10,282,1,1,1,1,1,1),
(10,283,1,1,1,1,1,1),
(10,284,1,1,1,1,1,1),
(10,285,1,1,1,1,1,1),
(10,286,1,1,1,1,1,1),
(10,287,1,1,1,1,1,1),
(10,288,1,1,1,1,1,1),
(10,289,1,1,1,1,1,1),
(10,290,1,1,1,1,1,1),
(10,291,1,1,1,1,1,1),
(10,292,1,1,1,1,1,1),
(10,293,1,1,1,1,1,1),
(10,294,1,1,1,1,1,1),
(10,295,1,1,1,1,1,1),
(10,296,1,1,1,1,1,1),
(10,297,1,1,1,1,1,1),
(10,298,1,1,1,1,1,1),
(10,299,1,1,1,1,1,1),
(10,300,1,1,1,1,1,1),
(10,301,1,1,1,1,1,1),
(10,302,1,1,1,1,1,1),
(10,303,1,1,1,1,1,1),
(10,304,1,1,1,1,1,1),
(10,305,1,1,1,1,1,1),
(10,306,1,1,1,1,1,1),
(10,307,1,1,1,1,1,1),
(10,308,1,1,1,1,1,1),
(10,309,1,1,1,1,1,1),
(10,310,1,1,1,1,1,1),
(10,311,1,1,1,1,1,1),
(10,312,1,1,1,1,1,1),
(10,313,1,1,1,1,1,1),
(10,314,1,1,1,1,1,1),
(10,315,1,1,1,1,1,1),
(10,316,1,1,1,1,1,1),
(10,317,1,1,1,1,1,1),
(10,318,1,1,1,1,1,1),
(10,319,1,1,1,1,1,1),
(10,320,1,1,1,1,1,1),
(10,321,1,1,1,1,1,1),
(10,322,1,1,1,1,1,1),
(10,323,1,1,1,1,1,1),
(10,324,1,1,1,1,1,1),
(10,325,1,1,1,1,1,1),
(10,326,1,1,1,1,1,1),
(10,327,1,1,1,1,1,1),
(10,328,1,1,1,1,1,1),
(10,329,1,1,1,1,1,1),
(10,330,1,1,1,1,1,1),
(10,331,1,1,1,1,1,1),
(10,332,1,1,1,1,1,1),
(10,333,1,1,1,1,1,1),
(10,334,1,1,1,1,1,1),
(10,335,1,1,1,1,1,1),
(10,336,1,1,1,1,1,1),
(10,337,1,1,1,1,1,1),
(10,338,1,1,1,1,1,1),
(10,339,1,1,1,1,1,1),
(10,340,1,1,1,1,1,1),
(10,341,1,1,1,1,1,1),
(10,342,1,1,1,1,1,1),
(10,343,1,1,1,1,1,1),
(10,344,1,1,1,1,1,1),
(10,345,1,1,1,1,1,1),
(10,346,1,1,1,1,1,1),
(10,347,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(10,348,1,1,1,1,1,1),
(10,349,1,1,1,1,1,1),
(10,350,1,1,1,1,1,1),
(10,351,1,1,1,1,1,1),
(10,352,1,1,1,1,1,1),
(10,353,1,1,1,1,1,1),
(10,354,1,1,1,1,1,1),
(10,355,1,1,1,1,1,1),
(10,356,1,1,1,1,1,1),
(10,357,1,1,1,1,1,1),
(10,358,1,1,1,1,1,1),
(10,359,1,1,1,1,1,1),
(10,360,1,1,1,1,1,1),
(10,364,1,0,0,0,1,0),
(10,365,1,0,0,0,1,0),
(10,366,1,0,0,0,1,0),
(10,367,1,0,0,0,1,0),
(10,368,1,0,0,0,1,0),
(10,369,1,0,0,0,1,0),
(10,370,1,0,0,0,1,0),
(10,371,1,0,0,0,1,0),
(10,373,1,0,0,0,1,0),
(10,374,1,0,0,0,1,0),
(10,376,1,1,1,1,1,1),
(11,1,1,1,1,0,1,0),
(11,2,1,1,1,0,1,0),
(11,3,1,1,1,0,1,0),
(11,4,1,1,1,0,1,0),
(11,5,1,1,1,0,1,0),
(11,6,1,1,1,0,1,0),
(11,7,1,0,0,0,1,0),
(11,8,1,1,1,0,1,0),
(11,13,1,1,1,0,1,0),
(11,14,1,1,1,0,1,0),
(11,15,1,1,1,0,1,0),
(11,17,1,0,0,0,1,0),
(11,18,1,0,0,0,1,0),
(11,25,1,0,0,0,1,0),
(11,27,1,0,0,0,1,0),
(11,28,1,0,0,0,1,0),
(11,29,1,0,0,0,1,0),
(11,30,1,0,0,0,1,0),
(11,31,1,0,0,0,1,0),
(11,194,1,1,1,0,1,0),
(11,195,1,1,1,0,1,0),
(11,196,1,1,1,0,1,0),
(11,197,1,1,1,0,1,0),
(11,198,1,1,1,0,1,0),
(11,201,1,1,1,0,1,0),
(11,202,1,1,1,0,1,0),
(11,204,1,1,1,0,1,0),
(11,205,1,0,0,0,1,0),
(11,206,1,0,0,0,1,0),
(11,207,1,0,0,0,1,0),
(11,208,1,0,0,0,1,0),
(11,209,1,0,0,0,1,0),
(11,210,1,0,0,0,1,0),
(11,211,1,0,0,0,1,0),
(11,212,1,0,0,0,1,0),
(11,213,1,0,0,0,1,0),
(11,214,1,0,0,0,1,0),
(11,216,1,0,0,0,1,0),
(11,217,1,1,1,0,1,0),
(11,218,1,0,0,0,1,0),
(11,219,1,0,0,0,1,0),
(11,220,1,0,0,0,1,0),
(11,221,1,0,0,0,1,0),
(11,222,1,0,0,0,1,0),
(11,223,1,1,1,0,1,0),
(11,224,1,1,1,0,1,0),
(11,225,1,1,1,0,1,0),
(11,226,1,1,1,0,1,0),
(11,227,1,1,1,0,1,0),
(11,228,1,1,1,0,1,0),
(11,229,1,1,1,0,1,0),
(11,230,1,1,1,0,1,0),
(11,231,1,1,1,0,1,0),
(11,233,1,1,1,0,1,0),
(11,234,1,0,0,0,1,0),
(11,235,1,0,0,0,1,0),
(11,236,1,0,0,0,1,0),
(11,237,1,0,0,0,1,0),
(11,238,1,0,0,0,1,0),
(11,239,1,0,0,0,1,0),
(11,240,1,0,0,0,1,0),
(11,241,1,0,0,0,1,0),
(11,242,1,0,0,0,1,0),
(11,243,1,0,0,0,1,0),
(11,244,1,1,1,0,1,0),
(11,245,1,1,1,0,1,0),
(11,246,1,1,1,0,1,0),
(11,247,1,1,1,0,1,0),
(11,248,1,1,1,0,1,0),
(11,249,1,1,1,0,1,0),
(11,250,1,1,1,0,1,0),
(11,251,1,1,1,0,1,0),
(11,252,1,1,1,0,1,0),
(11,253,1,1,1,0,1,0),
(11,254,1,1,1,0,1,0),
(11,258,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(11,259,1,0,0,0,1,0),
(11,260,1,0,0,0,1,0),
(11,261,1,0,0,0,1,0),
(11,262,1,0,0,0,1,0),
(11,263,1,0,0,0,1,0),
(11,264,1,0,0,0,1,0),
(11,265,1,0,0,0,1,0),
(11,266,1,0,0,0,1,0),
(11,267,1,0,0,0,1,0),
(11,268,1,0,0,0,1,0),
(11,269,1,0,0,0,1,0),
(11,270,1,0,0,0,1,0),
(11,271,1,0,0,0,1,0),
(11,272,1,0,0,0,1,0),
(11,273,1,0,0,0,1,0),
(11,274,1,0,0,0,1,0),
(11,278,1,1,1,0,1,0),
(11,279,1,0,0,0,1,0),
(11,280,1,0,0,0,1,0),
(11,281,1,0,0,0,1,0),
(11,282,1,0,0,0,1,0),
(11,283,1,0,0,0,1,0),
(11,284,1,1,1,0,1,0),
(11,285,1,1,1,0,1,0),
(11,286,1,1,1,0,1,0),
(11,287,1,1,1,0,1,0),
(11,288,1,0,0,0,1,0),
(11,289,1,0,0,0,1,0),
(11,290,1,0,0,0,1,0),
(11,298,1,0,0,0,1,0),
(11,299,1,0,0,0,1,0),
(11,300,1,0,0,0,1,0),
(11,301,1,0,0,0,1,0),
(11,302,1,0,0,0,1,0),
(11,303,1,0,0,0,1,0),
(11,304,1,0,0,0,1,0),
(11,305,1,0,0,0,1,0),
(11,306,1,0,0,0,1,0),
(11,307,1,0,0,0,1,0),
(11,308,1,0,0,0,1,0),
(11,309,1,0,0,0,1,0),
(11,310,1,0,0,0,1,0),
(11,311,1,0,0,0,1,0),
(11,312,1,0,0,0,1,0),
(11,313,1,0,0,0,1,0),
(11,314,1,0,0,0,1,0),
(11,315,1,0,0,0,1,0),
(11,316,1,0,0,0,1,0),
(11,317,1,0,0,0,1,0),
(11,318,1,0,0,0,1,0),
(11,319,1,0,0,0,1,0),
(11,320,1,0,0,0,1,0),
(11,321,1,0,0,0,1,0),
(11,322,1,0,0,0,1,0),
(11,323,1,0,0,0,1,0),
(11,324,1,0,0,0,1,0),
(11,325,1,0,0,0,1,0),
(11,326,1,0,0,0,1,0),
(11,327,1,0,0,0,1,0),
(11,328,1,0,0,0,1,0),
(11,329,1,0,0,0,1,0),
(11,330,1,0,0,0,1,0),
(11,331,1,0,0,0,1,0),
(11,332,1,0,0,0,1,0),
(11,333,1,0,0,0,1,0),
(11,334,1,0,0,0,1,0),
(11,335,1,0,0,0,1,0),
(11,336,1,0,0,0,1,0),
(11,337,1,0,0,0,1,0),
(11,338,1,0,0,0,1,0),
(11,339,1,0,0,0,1,0),
(11,340,1,0,0,0,1,0),
(11,341,1,0,0,0,1,0),
(11,342,1,0,0,0,1,0),
(11,343,1,0,0,0,1,0),
(11,344,1,0,0,0,1,0),
(11,345,1,0,0,0,1,0),
(11,346,1,0,0,0,1,0),
(11,347,1,0,0,0,1,0),
(11,348,1,0,0,0,1,0),
(11,349,1,0,0,0,1,0),
(11,350,1,0,0,0,1,0),
(11,351,1,0,0,0,1,0),
(11,352,1,0,0,0,1,0),
(11,353,1,0,0,0,1,0),
(11,354,1,1,1,0,1,0),
(11,355,1,1,1,0,1,0),
(11,356,1,0,0,0,1,0),
(11,357,1,0,0,0,1,0),
(11,358,1,0,0,0,1,0),
(11,359,1,0,0,0,1,0),
(11,360,1,0,0,0,1,0),
(11,364,1,0,0,0,1,0),
(11,365,1,0,0,0,1,0),
(11,366,1,0,0,0,1,0),
(11,367,1,0,0,0,1,0),
(11,368,1,0,0,0,1,0),
(11,369,1,0,0,0,1,0),
(11,370,1,0,0,0,1,0),
(11,371,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(11,373,1,0,0,0,1,0),
(11,374,1,0,0,0,1,0),
(11,376,1,0,0,0,1,0),
(12,1,1,1,1,0,1,0),
(12,2,1,1,1,0,1,0),
(12,3,1,1,1,0,1,0),
(12,4,1,1,1,0,1,0),
(12,5,1,1,1,0,1,0),
(12,6,1,1,1,0,1,0),
(12,7,1,0,0,0,1,0),
(12,194,1,1,1,0,1,0),
(12,195,1,1,1,0,1,0),
(12,196,1,1,1,0,1,0),
(12,205,1,0,0,0,1,0),
(12,217,1,1,1,0,1,0),
(12,219,1,0,0,0,1,0),
(12,220,1,0,0,0,1,0),
(12,223,1,1,1,0,1,0),
(12,244,1,1,1,0,1,0),
(12,245,1,1,1,0,1,0),
(12,246,1,1,1,0,1,0),
(12,247,1,1,1,0,1,0),
(12,248,1,1,1,0,1,0),
(12,249,1,1,1,0,1,0),
(12,250,1,1,1,0,1,0),
(12,251,1,1,1,0,1,0),
(12,252,1,1,1,0,1,0),
(12,253,1,1,1,0,1,0),
(12,254,1,1,1,0,1,0),
(12,258,1,0,0,0,1,0),
(12,259,1,0,0,0,1,0),
(12,260,1,0,0,0,1,0),
(12,261,1,0,0,0,1,0),
(12,262,1,0,0,0,1,0),
(12,263,1,0,0,0,1,0),
(12,264,1,0,0,0,1,0),
(12,265,1,0,0,0,1,0),
(12,266,1,0,0,0,1,0),
(12,267,1,0,0,0,1,0),
(12,268,1,0,0,0,1,0),
(12,269,1,0,0,0,1,0),
(12,270,1,0,0,0,1,0),
(12,271,1,0,0,0,1,0),
(12,272,1,0,0,0,1,0),
(12,273,1,0,0,0,1,0),
(12,274,1,0,0,0,1,0),
(12,354,1,1,1,0,1,0),
(13,1,1,0,0,0,1,1),
(13,2,1,0,0,0,1,1),
(13,217,1,0,0,0,1,0),
(13,219,1,0,0,0,1,0),
(13,244,1,0,0,0,1,1),
(13,245,1,0,0,0,1,1),
(13,246,1,0,0,0,1,1),
(13,249,1,0,0,0,1,1),
(13,251,1,0,0,0,1,1),
(13,253,1,0,0,0,1,1),
(13,258,1,0,0,0,1,0),
(13,259,1,0,0,0,1,0),
(13,260,1,0,0,0,1,0),
(13,261,1,0,0,0,1,0),
(13,262,1,0,0,0,1,0),
(13,263,1,0,0,0,1,0),
(13,264,1,0,0,0,1,0),
(13,265,1,0,0,0,1,0),
(13,266,1,0,0,0,1,0),
(13,267,1,0,0,0,1,0),
(13,268,1,0,0,0,1,0),
(13,269,1,0,0,0,1,0),
(13,270,1,0,0,0,1,0),
(13,271,1,0,0,0,1,0),
(13,272,1,0,0,0,1,0),
(13,273,1,0,0,0,1,0),
(13,274,1,0,0,0,1,0),
(14,1,1,1,0,0,1,0),
(14,194,1,1,0,0,1,0),
(14,217,1,0,0,0,1,0),
(14,219,1,0,0,0,1,0),
(14,223,1,1,0,0,1,0),
(14,224,1,1,0,0,1,0),
(14,225,1,1,0,0,1,0),
(14,228,1,1,0,0,1,0),
(14,229,1,1,0,0,1,0),
(14,231,1,1,0,0,1,0),
(14,243,1,1,0,0,1,0),
(14,248,1,1,0,0,1,0),
(14,275,1,1,0,0,1,0),
(14,276,1,1,0,0,1,0),
(14,277,1,1,0,0,1,0),
(14,278,1,1,0,0,1,0),
(14,279,1,1,0,0,1,0),
(14,280,1,1,0,0,1,0),
(14,282,1,1,0,0,1,0),
(14,298,1,1,1,0,1,0),
(14,301,1,1,1,0,1,0),
(14,305,1,1,1,0,1,0),
(14,320,1,1,1,0,1,0),
(14,321,1,1,1,0,1,0),
(14,322,1,1,1,0,1,0),
(14,323,1,1,1,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(14,324,1,1,1,0,1,0),
(14,325,1,1,1,0,1,0),
(14,327,1,1,1,0,1,0),
(14,329,1,1,1,0,1,0),
(14,338,1,1,1,0,1,0),
(14,359,1,1,1,0,1,0),
(15,1,1,1,1,0,1,0),
(15,2,1,1,1,0,1,0),
(15,3,1,1,1,0,1,0),
(15,4,1,1,1,0,1,0),
(15,5,1,1,1,0,1,0),
(15,6,1,1,1,0,1,0),
(15,7,1,0,0,0,1,0),
(15,194,1,1,1,0,1,0),
(15,195,1,1,1,0,1,0),
(15,196,1,1,1,0,1,0),
(15,201,1,1,1,0,1,0),
(15,202,1,1,1,0,1,0),
(15,205,1,0,0,0,1,0),
(15,206,1,0,0,0,1,0),
(15,207,1,0,0,0,1,0),
(15,208,1,0,0,0,1,0),
(15,209,1,0,0,0,1,0),
(15,210,1,0,0,0,1,0),
(15,211,1,0,0,0,1,0),
(15,212,1,0,0,0,1,0),
(15,213,1,0,0,0,1,0),
(15,214,1,0,0,0,1,0),
(15,217,1,1,1,0,1,0),
(15,219,1,0,0,0,1,0),
(15,220,1,0,0,0,1,0),
(15,223,1,1,1,0,1,0),
(15,228,1,1,1,0,1,0),
(15,234,1,0,0,0,1,0),
(15,236,1,0,0,0,1,0),
(15,243,1,0,0,0,1,0),
(16,1,1,1,0,0,1,0),
(16,217,1,0,0,0,1,0),
(16,219,1,0,0,0,1,0),
(16,248,1,1,0,0,1,0),
(16,249,1,1,0,0,1,0),
(16,261,1,1,0,0,1,0),
(16,262,1,1,0,0,1,0),
(16,263,1,1,0,0,1,0),
(16,264,1,1,0,0,1,0),
(16,265,1,1,0,0,1,0),
(16,266,1,1,0,0,1,0),
(16,267,1,1,0,0,1,0),
(16,268,1,1,0,0,1,0),
(16,275,1,1,0,0,1,0),
(16,276,1,1,0,0,1,0),
(16,279,1,1,0,0,1,0),
(17,1,1,1,1,1,1,1),
(17,2,1,1,1,1,1,1),
(17,3,1,1,1,1,1,1),
(17,4,1,1,1,1,1,1),
(17,5,1,1,1,1,1,1),
(17,6,1,1,1,1,1,1),
(17,7,1,1,1,1,1,1),
(17,194,1,1,1,1,1,1),
(17,195,1,1,1,1,1,1),
(17,196,1,1,1,1,1,1),
(17,197,1,1,1,1,1,1),
(17,198,1,1,1,1,1,1),
(17,205,1,1,1,1,1,1),
(17,206,1,1,1,1,1,1),
(17,207,1,1,1,1,1,1),
(17,208,1,1,1,1,1,1),
(17,209,1,1,1,1,1,1),
(17,210,1,1,1,1,1,1),
(17,211,1,1,1,1,1,1),
(17,212,1,1,1,1,1,1),
(17,213,1,1,1,1,1,1),
(17,214,1,1,1,1,1,1),
(17,216,1,1,1,0,1,1),
(17,217,1,1,1,0,1,1),
(17,218,1,1,1,0,1,1),
(17,219,1,1,1,0,1,1),
(17,220,1,1,1,0,1,1),
(17,221,1,1,1,0,1,1),
(17,222,1,1,1,0,1,1),
(17,223,1,1,1,1,1,1),
(17,224,1,1,1,1,1,1),
(17,225,1,1,1,1,1,1),
(17,226,1,1,1,1,1,1),
(17,227,1,1,1,1,1,1),
(17,228,1,1,1,1,1,1),
(17,229,1,1,1,1,1,1),
(17,230,1,1,1,1,1,1),
(17,231,1,1,1,1,1,1),
(17,234,1,1,1,1,1,1),
(17,235,1,1,1,1,1,1),
(17,236,1,1,1,1,1,1),
(17,237,1,1,1,1,1,1),
(17,238,1,1,1,1,1,1),
(17,239,1,1,1,1,1,1),
(17,240,1,1,1,1,1,1),
(17,241,1,1,1,1,1,1),
(17,242,1,1,1,1,1,1),
(17,243,1,1,1,1,1,1);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,244,1,1,1,1,1,1),
(17,245,1,1,1,1,1,1),
(17,246,1,1,1,1,1,1),
(17,247,1,1,1,1,1,1),
(17,248,1,1,1,1,1,1),
(17,249,1,1,1,1,1,1),
(17,250,1,1,1,1,1,1),
(17,251,1,1,1,1,1,1),
(17,252,1,1,1,1,1,1),
(17,253,1,1,1,1,1,1),
(17,254,1,1,1,1,1,1),
(17,258,1,1,1,1,1,1),
(17,259,1,1,1,1,1,1),
(17,260,1,1,1,1,1,1),
(17,261,1,1,1,1,1,1),
(17,262,1,1,1,1,1,1),
(17,263,1,1,1,1,1,1),
(17,264,1,1,1,1,1,1),
(17,265,1,1,1,1,1,1),
(17,266,1,1,1,1,1,1),
(17,267,1,1,1,1,1,1),
(17,268,1,1,1,1,1,1),
(17,269,1,1,1,1,1,1),
(17,270,1,1,1,1,1,1),
(17,271,1,1,1,1,1,1),
(17,272,1,1,1,1,1,1),
(17,273,1,1,1,1,1,1),
(17,274,1,1,1,1,1,1),
(17,275,1,1,1,1,1,1),
(17,276,1,1,1,1,1,1),
(17,277,1,1,1,1,1,1),
(17,278,1,1,1,1,1,1),
(17,279,1,1,1,1,1,1),
(17,280,1,1,1,1,1,1),
(17,281,1,1,1,1,1,1),
(17,282,1,1,1,1,1,1),
(17,283,1,1,1,1,1,1),
(17,284,1,1,1,1,1,1),
(17,285,1,1,1,1,1,1),
(17,286,1,1,1,1,1,1),
(17,287,1,1,1,1,1,1),
(17,288,1,1,1,1,1,1),
(17,289,1,1,1,1,1,1),
(17,290,1,1,1,1,1,1),
(17,292,1,1,1,1,1,1),
(17,293,1,1,1,1,1,1),
(17,294,1,1,1,1,1,1),
(17,296,1,1,1,1,1,1),
(17,297,1,1,1,1,1,1),
(17,354,1,1,1,1,1,1);

-- role_special_permissions (75 rows)
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(2,'closed_period_changes','0'),
(2,'coa_changes','1'),
(2,'credit_limit_override','0'),
(2,'design_reports','1'),
(2,'doc_reissue','1'),
(2,'edit_posted_documents','1'),
(2,'max_amount',''),
(2,'max_discount_pct','0'),
(2,'max_price_override_pct','0'),
(2,'view_bank_balances','1'),
(2,'view_costs','1'),
(3,'credit_limit_override','0'),
(3,'max_amount','50000'),
(3,'max_discount_pct','10'),
(3,'max_price_override_pct','5'),
(3,'view_bank_balances','0'),
(3,'view_costs','0'),
(4,'max_amount','100000'),
(4,'view_bank_balances','0'),
(4,'view_costs','1'),
(5,'max_amount','20000'),
(5,'view_costs','0'),
(6,'max_discount_pct','5'),
(6,'max_price_override_pct','0'),
(6,'view_costs','0'),
(7,'max_amount','0'),
(7,'max_discount_pct','5'),
(7,'max_price_override_pct','0'),
(7,'view_costs','0'),
(8,'max_amount','20000'),
(8,'max_discount_pct','30'),
(8,'max_price_override_pct','20'),
(8,'view_costs','0'),
(9,'view_bank_balances','1'),
(9,'view_costs','1'),
(10,'closed_period_changes','0'),
(10,'coop_all_branches','1'),
(10,'coop_backdate','1'),
(10,'coop_max_release',''),
(10,'coop_max_withdrawal',''),
(10,'coop_override_allocation','1'),
(10,'coop_view_confidential','1'),
(10,'coop_waive','1'),
(10,'doc_reissue','1'),
(10,'edit_posted_documents','1'),
(10,'max_amount',''),
(10,'party_merge','1'),
(11,'closed_period_changes','0'),
(11,'coop_all_branches','1'),
(11,'coop_backdate','1'),
(11,'coop_max_withdrawal','50000'),
(11,'doc_reissue','1'),
(12,'closed_period_changes','0'),
(12,'coop_max_release','100000'),
(12,'coop_override_allocation','1'),
(12,'coop_view_confidential','1'),
(13,'closed_period_changes','0'),
(13,'coop_all_branches','1'),
(13,'coop_view_confidential','1'),
(13,'coop_waive','1'),
(14,'closed_period_changes','0'),
(14,'coop_max_release','100000'),
(14,'coop_max_withdrawal','50000'),
(15,'closed_period_changes','0'),
(15,'coop_view_confidential','1'),
(16,'closed_period_changes','0'),
(17,'closed_period_changes','0'),
(17,'coop_backdate','1'),
(17,'coop_max_release',''),
(17,'coop_max_withdrawal',''),
(17,'coop_override_allocation','1'),
(17,'coop_view_confidential','1'),
(17,'coop_waive','1'),
(17,'max_amount',''),
(17,'party_merge','1');

-- audit_trail (4 rows)
INSERT INTO `audit_trail` (`id`,`created_at`,`user_id`,`username`,`user_roles`,`ip_address`,`workstation`,`site_id`,`terminal_id`,`module`,`page_key`,`action`,`record_type`,`record_id`,`record_ref`,`amount`,`changes`,`snapshot`,`reason`,`approved_by`) VALUES
(1,'2026-10-19 00:49:43',NULL,'system','Database migration','127.0.0.1','SQL migration',NULL,NULL,'admin','admin.page_access','access_change','page',NULL,'Cooperative Management modules',NULL,NULL,NULL,'Migration 2026_11_01: Cooperative, Savings & Deposits, Loans, Teller, Year-end Distribution and CDA Compliance modules added with their tables, accounts, roles and settings',NULL),
(2,'2026-11-07 21:26:48',NULL,'system','Database migration','127.0.0.1','SQL migration',NULL,NULL,'admin','admin.page_access','access_change','page',NULL,'Accounting update (version 53)',NULL,NULL,NULL,'Migration 2027_04_04: item groups, Receive Items lists, settle ticks, average cost on the books; 14 reports (POS ending inventory, item group sales, comparative statements, average cost changes, class reports)',NULL),
(3,'2026-11-07 23:37:21',NULL,'system','Database migration','127.0.0.1','SQL migration',NULL,NULL,'admin','admin.page_access','access_change','page',NULL,'Sales by Customer Detail Group by Customer/Member Type',NULL,NULL,NULL,'Migration 2027_04_11: the report page, given to the roles that open Sales by Customer Detail',NULL),
(4,'2026-11-08 19:44:11',NULL,'system','Database migration','127.0.0.1','SQL migration',NULL,NULL,'admin','admin.page_access','access_change','page',NULL,'Upload Bill Balances',NULL,NULL,NULL,'Migration 2027_04_18: the uploads of bill balances and the page, given to the roles that have One-Time Billing',NULL);

-- sequences (43 rows)
INSERT INTO `sequences` (`seq_key`,`prefix`,`next_no`,`pad`) VALUES
('adjustment','ADJ-',1,5),
('advance_apply','AAP-',1,5),
('asset','FA-',1,5),
('balance_certificate','BC-',1,6),
('bill','BILL-',1,5),
('build','BLD-',1,5),
('cash_voucher','CAV-',1,5),
('check','',1001,4),
('coop_cv','MCV-',1,6),
('coop_jv','CJV-',1,6),
('coop_or','',1,6),
('credit_memo','CM-',1,5),
('debit_memo','DM-',1,5),
('deposit','DEP-',1,5),
('dist_batch','DIST-',1,5),
('estimate','EST-',1,5),
('finance_charge','FC-',1,5),
('inv_transfer','IT-',1,5),
('invoice','INV-',1,5),
('item_receipt','IR-',1,5),
('journal','JE-',1,5),
('loan','',1,7),
('loan_application','LA-',1,6),
('loan_release','LR-',1,6),
('maintenance','MNT-',1,5),
('member','',100001,6),
('party','P-',17,6),
('passbook','',1,6),
('payment','PMT-',1,5),
('pn','',1,5),
('pos','POS-',1,6),
('processing','PRC-',1,5),
('purchase_order','PO-',1,5),
('sales_order','SO-',1,5),
('sales_receipt','SR-',1,5),
('sav_account','',1,6),
('sav_batch','BAT-',1,5),
('sav_trans','',1,7),
('share_certificate','SC-',1,6),
('statement_charge','SC-',1,5),
('td_certificate','TD-',1,6),
('transfer','TRF-',1,5),
('vendor_advance','ADV-',1,5);

-- accounts (121 rows)
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`type_id`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`,`is_interunit`) VALUES
(1,'1000','Cash','Bank',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(2,'1001','Cash on Hand - Store Registers','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(3,'1005','Petty Cash Fund','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(4,'1006','Revolving Fund','Bank',NULL,'Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(5,'1010','Cash in Bank - BDO Checking','Bank',NULL,'Asset','Debit',1,NULL,'0012-3456-7890',0,1,0,'2026-10-19 00:49:43',NULL,0),
(6,'1020','Cash in Bank - BPI Savings','Bank',NULL,'Asset','Debit',1,NULL,'1234-5678-90',0,1,0,'2026-10-19 00:49:43',NULL,0),
(7,'1050','Undeposited Funds','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(8,'1060','Card Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(9,'1065','E-wallet Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(10,'1070','P.O. Card Clearing','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(11,'1100','Accounts Receivable','Accounts Receivable',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(12,'1150','Allowance for Doubtful Accounts','Other Current Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(13,'1200','Inventory','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(14,'1300','Input Tax','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(15,'1310','Creditable Withholding Tax','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(16,'1315','Creditable VAT Withheld','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(17,'1400','Prepaid Expenses','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(18,'1410','Advances to Suppliers','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(19,'1500','Land','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(20,'1510','Building','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(21,'1515','Accumulated Depreciation - Building','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(22,'1520','Machinery and Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(23,'1525','Accumulated Depreciation - Machinery','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(24,'1530','Transportation Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(25,'1535','Accumulated Depreciation - Transportation','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(26,'1540','Furniture and Fixtures','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(27,'1545','Accumulated Depreciation - Furniture','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(28,'1550','Computer and POS Equipment','Fixed Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(29,'1555','Accumulated Depreciation - Computer and POS','Fixed Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(30,'1600','Security Deposits','Other Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(31,'2000','Accounts Payable','Accounts Payable',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(32,'2050','Company Credit Card','Credit Card',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(33,'2100','Output Tax','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(34,'2110','Withholding Tax Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(35,'2115','VAT Withheld Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(36,'2120','Gift Vouchers Outstanding','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(37,'2130','Customer Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(38,'2200','Accrued Expenses','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(39,'2500','Loans Payable','Long Term Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(40,'3000','Owner\'s Capital','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(41,'3100','Owner\'s Drawings','Equity',NULL,'Equity','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(42,'3200','Retained Earnings','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(43,'3900','Opening Balance Equity','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-10-19 00:49:43',NULL,0),
(44,'4000','Sales','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(45,'4010','Service Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(46,'4020','Delivery Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(47,'4050','Sales Returns and Allowances','Income',NULL,'Income','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(48,'4060','Sales Discounts','Income',NULL,'Income','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(49,'4110','Reimbursable Expense Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(50,'4100','Finance Charge Income','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(51,'4200','Interest Income','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(52,'4300','Gain (Loss) on Disposal of Assets','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(53,'5000','Cost of Goods Sold','Cost of Goods Sold',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(54,'5050','Purchase Discounts','Cost of Goods Sold',NULL,'Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(55,'5100','Inventory Adjustments and Shrinkage','Cost of Goods Sold',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(56,'5200','Direct Labor Absorbed','Cost of Goods Sold',NULL,'Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(57,'6000','Salaries and Wages','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(58,'6010','Rent Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(59,'6020','Utilities Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(60,'6030','Repairs and Maintenance','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(61,'6040','Office Supplies Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(62,'6050','Fuel and Oil','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(63,'6060','Meals and Representation','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(64,'6070','Professional Fees','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(65,'6080','Bank and Merchant Charges','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(66,'6090','Depreciation Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(67,'6100','Freight and Delivery','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(68,'6110','Purchases - Non-inventory','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(69,'6200','Cash Over and Short','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(70,'6900','Miscellaneous Expense','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(71,'7000','Interest Expense','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(72,'1110','Checks and Other Cash Items','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(73,'1210','Loans Receivable - Current','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(74,'1211','Loans Receivable - Past Due','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(75,'1212','Loans Receivable - Restructured','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(76,'1213','Loans Receivable - Loans in Litigation','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(77,'1215','Allowance for Probable Losses on Loans','Other Current Asset',NULL,'Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(78,'1220','Interest Receivable on Loans','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(79,'1230','Payroll Deductions Receivable','Other Current Asset',NULL,'Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(80,'2300','Savings Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(81,'2305','Special Savings Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(82,'2310','Time Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(83,'2320','Accrued Interest Payable on Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(84,'2330','Interest on Share Capital Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(85,'2340','Patronage Refund Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(86,'2350','Due to Union / Federation (CETF)','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(87,'2360','Loan Insurance Payable','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(88,'2370','Unclaimed Member Distributions','Other Current Liability',NULL,'Liability','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(89,'3010','Paid-up Share Capital - Common','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,1,1,0,'2026-10-19 00:49:43',NULL,0),
(90,'3020','Paid-up Share Capital - Preferred','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(91,'3300','Reserve Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(92,'3310','Education and Training Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(93,'3320','Community Development Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(94,'3330','Optional Fund','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(95,'3340','Undivided Net Surplus','Equity',NULL,'Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(96,'4400','Interest Income on Loans','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(97,'4410','Service Fees on Loans','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(98,'4420','Filing Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(99,'4430','Fines, Penalties and Surcharges','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(100,'4440','Membership Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0);
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`type_id`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`,`is_interunit`) VALUES
(101,'4450','Pre-termination Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(102,'4460','Dormancy Fees','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(103,'4470','Other Cooperative Income','Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(104,'4480','Recovery of Loans Written Off','Other Income',NULL,'Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(105,'7100','Interest Expense on Savings Deposits','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(106,'7110','Interest Expense on Time Deposits','Other Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(107,'7120','Provision for Probable Losses on Loans','Expense',NULL,'Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-19 00:49:43',NULL,0),
(135,'1120','Accounts Receivable - Water','Accounts Receivable',NULL,'Asset','Debit',NULL,'Water bills and other charges billed to water-service accounts',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(136,'2135','Water Service Deposits','Other Current Liability',NULL,'Liability','Credit',NULL,'Service (meter) deposits billed to water-service accounts, refundable',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(137,'4500','Water Sales','Income',NULL,'Income','Credit',NULL,'Metered water sales (Water Utility)',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(138,'4510','Water Connection and Service Fees','Income',NULL,'Income','Credit',NULL,'Connection, reconnection and maintenance fees',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(139,'4520','Water Penalties and Surcharges','Income',NULL,'Income','Credit',NULL,'Penalties charged on late water payments',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(140,'4530','Water Compromise Income','Income',NULL,'Income','Credit',NULL,'Compromise settlements of water accounts',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(141,'4540','Sales - Water Meters and Materials','Income',NULL,'Income','Credit',NULL,'Water meters and materials sold to consumers',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(142,'4550','Discounts on Water Sales','Income',NULL,'Income','Debit',NULL,'Senior citizen and other discounts granted on water bills',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(143,'1002','Cash on Hand - Water Collections','Bank',NULL,'Asset','Debit',1,'Water collections of the cashiers, before deposit',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(144,'39999','Legacy Water Balances Clearing','Equity',NULL,'Equity','Credit',NULL,'Offset of the water receivables brought in from the former water program at the cut-over',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(145,'2136','Share Capital Collected with Water Bills','Other Current Liability',NULL,'Liability','Credit',NULL,'Share capital collected from water consumers with their bills, held until it is credited to the member\'s share capital account',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(146,'2137','Damayan Contributions Payable','Other Current Liability',NULL,'Liability','Credit',NULL,'Damayan (mutual aid) contributions collected with water bills',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(147,'2138','Funos Contributions Payable','Other Current Liability',NULL,'Liability','Credit',NULL,'Funos collected with water bills (a charge of the former water program), held until its treatment is decided',NULL,0,1,0,'2026-10-19 00:49:44',NULL,0),
(148,'4070','Item Group Price Variance','Income',NULL,'Income','Credit',NULL,'Item groups sold: the group price less the prices of the items in the group',NULL,0,1,0,'2026-11-07 21:26:48',NULL,0);

-- account_types (15 rows)
INSERT INTO `account_types` (`id`,`name`,`base_type`,`cash_flow`,`sort_order`,`description`,`is_system`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Bank','Bank','cash',10,'Cash on hand and in banks',1,1,'2026-10-28 00:41:13',NULL),
(2,'Accounts Receivable','Accounts Receivable','operating',20,'What customers owe on invoices',1,1,'2026-10-28 00:41:13',NULL),
(3,'Other Current Asset','Other Current Asset','operating',30,'Loans and advances, prepaid expenses, inventory, clearing accounts',1,1,'2026-10-28 00:41:13',NULL),
(4,'Fixed Asset','Fixed Asset','investing',40,'Property and equipment and their accumulated depreciation',1,1,'2026-10-28 00:41:13',NULL),
(5,'Other Asset','Other Asset','investing',50,'Investments, deposits and other long-term assets',1,1,'2026-10-28 00:41:13',NULL),
(6,'Accounts Payable','Accounts Payable','operating',60,'What is owed to suppliers on bills',1,1,'2026-10-28 00:41:13',NULL),
(7,'Credit Card','Credit Card','operating',70,'Credit card balances',1,1,'2026-10-28 00:41:13',NULL),
(8,'Other Current Liability','Other Current Liability','operating',80,'Deposits, taxes, accruals and other debts due within a year',1,1,'2026-10-28 00:41:13',NULL),
(9,'Long Term Liability','Long Term Liability','financing',90,'Debts due after a year',1,1,'2026-10-28 00:41:13',NULL),
(10,'Equity','Equity','financing',100,'Capital, share capital, reserves, retained earnings',1,1,'2026-10-28 00:41:13',NULL),
(11,'Income','Income','',110,'Income from the main business',1,1,'2026-10-28 00:41:13',NULL),
(12,'Other Income','Other Income','',120,'Income outside the main business',1,1,'2026-10-28 00:41:13',NULL),
(13,'Cost of Goods Sold','Cost of Goods Sold','',130,'The cost of what was sold',1,1,'2026-10-28 00:41:13',NULL),
(14,'Expense','Expense','',140,'The costs of running the business',1,1,'2026-10-28 00:41:13',NULL),
(15,'Other Expense','Other Expense','',150,'Expenses outside the operations',1,1,'2026-10-28 00:41:13',NULL);

-- default_accounts (71 rows)
INSERT INTO `default_accounts` (`setting_key`,`label`,`module`,`account_id`) VALUES
('ap','Accounts Payable','vend',31),
('ar','Accounts Receivable','cust',11),
('card_clearing','Card Clearing','pos',8),
('cash_on_hand','Cash on Hand (Register)','pos',2),
('cash_over_short','Cash Over and Short','pos',69),
('cdf_fund','Community Development Fund','coop',93),
('cogs','Cost of Goods Sold','inv',53),
('coop_cash','Teller Cash on Hand (default)','coop',2),
('coop_coci','Checks and Other Cash Items (clearing)','coop',72),
('coop_insurance_payable','Loan Insurance Payable','coop',87),
('coop_other_income','Other Cooperative Income','coop',103),
('cvat_withheld','Creditable VAT Withheld','cust',16),
('cwt','Creditable Withholding Tax','cust',15),
('default_expense','Default Purchases / Expense','vend',68),
('deposit_interest_payable','Accrued Interest Payable on Deposits','coop',83),
('dormancy_fee_income','Dormancy Fees','coop',102),
('education_fund','Education and Training Fund','coop',92),
('education_fund_apex','Due to Union / Federation (CETF)','coop',86),
('ewallet_clearing','E-wallet Clearing','pos',9),
('finance_charge_income','Finance Charge Income','cust',50),
('gain_loss_disposal','Gain / Loss on Disposal of Assets','fa',52),
('group_price_variance','Item Group Price Variance','inv',148),
('input_tax','Input Tax','vend',14),
('interest_income','Interest Income','bank',51),
('inventory_adjustment','Inventory Adjustment (Shrinkage)','inv',55),
('inventory_asset','Inventory Asset','inv',13),
('isc_payable','Interest on Share Capital Payable','coop',84),
('loan_interest_income','Interest Income on Loans','coop',96),
('loan_interest_receivable','Interest Receivable on Loans','coop',78),
('loan_loss_allowance','Allowance for Probable Losses on Loans','coop',77),
('loan_loss_provision','Provision for Probable Losses on Loans','coop',107),
('loan_penalty_income','Fines, Penalties and Surcharges','coop',99),
('loan_recovery_income','Recovery of Loans Written Off','coop',104),
('loan_service_fee','Service Fees on Loans','coop',97),
('loans_past_due','Loans Receivable - Past Due','coop',74),
('loans_receivable','Loans Receivable - Current','coop',73),
('loans_restructured','Loans Receivable - Restructured','coop',75),
('membership_fee_income','Membership Fees','coop',100),
('merchant_fees','Bank / Merchant Charges','bank',65),
('net_surplus','Undivided Net Surplus','coop',95),
('opening_equity','Opening Balance Equity','coa',43),
('optional_fund','Optional Fund','coop',94),
('output_tax','Output Tax','cust',33),
('pr_payable','Patronage Refund Payable','coop',85),
('preterm_fee_income','Pre-termination Fees','coop',101),
('processing_absorbed','Processing Labor & Overhead Absorbed','inv',56),
('processing_variance','Processing / Disassembly Variance','inv',55),
('purchase_discounts','Purchase Discounts','vend',54),
('reimbursable_income','Reimbursable Expense Income','cust',49),
('reserve_fund','Reserve Fund','coop',91),
('retained_earnings','Retained Earnings','coa',42),
('sales_discounts','Sales Discounts','cust',48),
('sales_income','Default Sales Income','cust',44),
('sales_returns','Sales Returns and Allowances','cust',47),
('savings_deposits','Savings Deposits (default liability)','coop',80),
('savings_interest_expense','Interest Expense on Savings Deposits','coop',105),
('share_capital','Paid-up Share Capital - Common','coop',89),
('td_interest_expense','Interest Expense on Time Deposits','coop',106),
('time_deposits','Time Deposits','coop',82),
('unclaimed_dist','Unclaimed Member Distributions','coop',88),
('undeposited_funds','Undeposited Funds','cust',7),
('vat_withheld_payable','VAT Withheld Payable','vend',35),
('vendor_advances','Advances to Suppliers','vend',18),
('water_ar','Water Accounts Receivable','water',135),
('water_cash','Water Collections Cash (default)','water',143),
('water_compromise','Water Compromise Income','water',140),
('water_discount','Water Discounts Granted','water',142),
('water_income','Water Sales (metered water)','water',137),
('water_legacy_clearing','Legacy Water Balances Clearing','water',144),
('water_penalty','Water Penalty / Surcharge Income','water',139),
('wht_payable','Withholding Tax Payable (Expanded)','vend',34);

-- terms (5 rows)
INSERT INTO `terms` (`id`,`name`,`net_days`,`discount_pct`,`discount_days`,`is_active`) VALUES
(1,'Due on receipt',0,'0.00',0,1),
(2,'Net 15',15,'0.00',0,1),
(3,'Net 30',30,'0.00',0,1),
(4,'2% 10 Net 30',30,'2.00',10,1),
(5,'Net 60',60,'0.00',0,1);

-- tax_codes (4 rows)
INSERT INTO `tax_codes` (`id`,`code`,`name`,`rate`,`sales_account_id`,`purchase_account_id`,`is_active`) VALUES
(1,'VAT12','VAT 12%','12.000',33,14,1),
(2,'ZR','Zero-rated','0.000',33,14,1),
(3,'EX','VAT-exempt','0.000',33,14,1),
(4,'NT','Non-taxable','0.000',33,14,1);

-- payment_methods (9 rows)
INSERT INTO `payment_methods` (`id`,`name`,`method_type`,`clearing_account_id`,`is_active`,`show_in_pos`) VALUES
(1,'Cash','cash',NULL,1,1),
(2,'Check','check',NULL,1,0),
(3,'Credit/Debit Card','card',8,1,1),
(4,'GCash','ewallet',9,1,1),
(5,'Maya','ewallet',9,1,1),
(6,'Bank Transfer','bank_transfer',NULL,1,0),
(7,'Gift Voucher','voucher',36,1,1),
(8,'P.O. Card','card',10,1,1),
(9,'Bank','bank_transfer',5,1,1);

-- price_levels (10 rows)
INSERT INTO `price_levels` (`id`,`name`,`adjust_pct`,`valid_from`,`valid_to`,`is_active`) VALUES
(1,'Wholesale','-5.00',NULL,NULL,1),
(2,'VIP','-10.00',NULL,NULL,1),
(3,'Retail Plus','5.00',NULL,NULL,1),
(4,'Price Level 4','0.00',NULL,NULL,1),
(5,'Price Level 5','0.00',NULL,NULL,1),
(6,'Price Level 6','0.00',NULL,NULL,1),
(7,'Price Level 7','0.00',NULL,NULL,1),
(8,'Price Level 8','0.00',NULL,NULL,1),
(9,'Price Level 9','0.00',NULL,NULL,1),
(10,'Price Level 10','0.00',NULL,NULL,1);

-- sales_reps (2 rows)
INSERT INTO `sales_reps` (`id`,`name`,`initials`,`user_id`,`commission_pct`,`is_active`) VALUES
(1,'Maria Santos','MS',6,'3.00',1),
(2,'Jose Reyes','JR',NULL,'2.50',1);

-- customer_types (4 rows)
INSERT INTO `customer_types` (`id`,`name`) VALUES
(1,'Corporate'),
(2,'Retail'),
(3,'School'),
(4,'Government');

-- billing_groups (1 rows)
INSERT INTO `billing_groups` (`id`,`name`) VALUES
(1,'Monthly Service Contracts');

-- customers (9 rows)
INSERT INTO `customers` (`id`,`party_id`,`parent_id`,`name`,`company_name`,`contact_person`,`phone`,`mobile`,`email`,`billing_address`,`shipping_address`,`tin`,`wht_ids`,`terms_id`,`credit_limit`,`price_level_id`,`tax_code_id`,`payment_method_id`,`customer_type_id`,`sales_rep_id`,`billing_group_id`,`allow_pos_charge`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,1,NULL,'ABC Trading Corp.','ABC Trading Corporation','Mr. Albert Co','(02) 8777-1001',NULL,'ap@abctrading.test','45 Buendia Ave., Makati City','45 Buendia Ave., Makati City','111-222-333-000',NULL,3,'250000.00',NULL,1,2,1,1,NULL,1,NULL,1,'2026-10-19 00:49:43',NULL),
(2,1,1,'Office Renovation Project',NULL,NULL,NULL,NULL,NULL,'45 Buendia Ave., Makati City',NULL,NULL,NULL,3,'0.00',NULL,1,NULL,1,1,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL),
(3,2,NULL,'Metro School Supplies','Metro School Supplies Inc.','Ms. Bea Lim','(02) 8777-2002',NULL,'purchasing@metroschool.test','88 España Blvd., Manila',NULL,NULL,NULL,2,'100000.00',1,1,NULL,2,2,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL),
(4,3,NULL,'Cebu Tech Hub','Cebu Tech Hub Co.','Mr. Carlo Sy','(032) 255-3003',NULL,'finance@cebutech.test','IT Park, Lahug, Cebu City',NULL,NULL,NULL,4,'150000.00',2,1,NULL,1,2,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL),
(5,4,NULL,'Bright Minds Academy','Bright Minds Academy','Dr. Dina Reyes','(02) 8777-4004',NULL,'admin@brightminds.test','12 Katipunan Ave., Quezon City',NULL,NULL,NULL,2,'60000.00',NULL,1,NULL,3,1,1,0,NULL,1,'2026-10-19 00:49:43',NULL),
(6,5,NULL,'Green Valley Clinic','Green Valley Clinic','Dr. Eric Tan','(02) 8777-5005',NULL,NULL,'5 Shaw Blvd., Mandaluyong',NULL,NULL,NULL,2,'40000.00',NULL,1,NULL,1,1,1,0,NULL,1,'2026-10-19 00:49:43',NULL),
(7,6,NULL,'City Government of Makati','City Government of Makati','Procurement Office','(02) 8870-1000',NULL,'procurement@makati.test','Makati City Hall, J.P. Rizal St., Makati City',NULL,'000-456-789-000','3,1',3,'0.00',NULL,1,2,4,NULL,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL),
(8,7,NULL,'Juan Dela Cruz',NULL,'Juan Dela Cruz',NULL,'0917-555-0000',NULL,NULL,NULL,NULL,NULL,1,'0.00',NULL,1,1,2,NULL,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL),
(9,8,NULL,'Walk-in Customer',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'0.00',NULL,1,1,2,NULL,NULL,0,NULL,1,'2026-10-19 00:49:43',NULL);

-- vendors (8 rows)
INSERT INTO `vendors` (`id`,`party_id`,`name`,`company_name`,`contact_person`,`phone`,`fax`,`email`,`address`,`tin`,`terms_id`,`credit_limit`,`default_expense_account_id`,`tax_code_id`,`account_no`,`wht_ids`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,16,'TechDistributors Inc.','TechDistributors Inc.','Rico Lopez','(02) 8555-0101',NULL,'sales@techdist.test','Pasig City','770-665-736-000',3,'0.00',NULL,1,'TD-88012','1',NULL,1,'2026-10-19 00:49:43',NULL),
(2,17,'Office Depot PH','Office Depot PH','Liza Go','(02) 8555-0202',NULL,'orders@officedepot.test','Quezon City','936-412-406-000',2,'0.00',61,1,'OD-4410',NULL,NULL,1,'2026-10-19 00:49:43',NULL),
(3,18,'Meralco','Meralco','Customer Service','16211',NULL,'billing@meralco.test','Pasig City','470-303-958-000',1,'0.00',59,1,'1234-5678-9012',NULL,NULL,1,'2026-10-19 00:49:43',NULL),
(4,19,'Metro Property Leasing','Metro Property Leasing','Tony Chua','(02) 8555-0303',NULL,'leasing@metroprop.test','Makati City','204-917-858-000',1,'0.00',58,1,'L-2291',NULL,NULL,1,'2026-10-19 00:49:43',NULL),
(5,20,'Petron Service Station','Petron Service Station',NULL,'(02) 8555-0404',NULL,NULL,'Makati City','128-831-749-000',1,'0.00',62,1,NULL,NULL,NULL,1,'2026-10-19 00:49:43',NULL),
(6,21,'JRS Express','JRS Express','Operations','(02) 8555-0505',NULL,'ops@jrs.test','Manila','123-447-598-000',2,'0.00',67,1,'JRS-7781','2',NULL,1,'2026-10-19 00:49:43',NULL),
(7,22,'Accounting Partners & Co.','Accounting Partners & Co.','Atty. Reyes','(02) 8555-0606',NULL,'hello@apco.test','Taguig City','159-106-543-000',3,'0.00',64,1,NULL,'2',NULL,1,'2026-10-19 00:49:43',NULL),
(8,23,'Industrial Machines Corp.','Industrial Machines Corp.','Sales','(02) 8555-0707',NULL,'sales@imc.test','Laguna','538-240-770-000',3,'0.00',NULL,1,'IMC-100','1',NULL,1,'2026-10-19 00:49:43',NULL);

-- sites (6 rows)
INSERT INTO `sites` (`id`,`code`,`name`,`site_type`,`address`,`is_active`) VALUES
(1,'WH','Main Warehouse','warehouse',NULL,1),
(2,'ST1','Makati Store','store',NULL,1),
(3,'ST2','Cebu Store','store',NULL,1),
(4,'VAN1','Delivery Van 1','van',NULL,1),
(5,'DMG','Damaged Goods','damaged',NULL,1),
(6,'WTR','Water Utility Stockroom','stockroom',NULL,1);

-- item_categories (7 rows)
INSERT INTO `item_categories` (`id`,`name`) VALUES
(1,'Computers'),
(2,'Accessories'),
(3,'Services'),
(4,'Bundles'),
(5,'Supplies'),
(6,'Charges'),
(7,'Water Materials');

-- uom_sets (5 rows)
INSERT INTO `uom_sets` (`id`,`name`,`uom_kind`,`base_unit`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Piece','count','pc',1,'2026-10-19 00:49:43',NULL),
(2,'Weight (kg)','weight','kg',1,'2026-10-19 00:49:43',NULL),
(3,'Volume (L)','volume','L',1,'2026-10-19 00:49:43',NULL),
(4,'Length (m)','length','m',1,'2026-10-19 00:49:43',NULL),
(5,'Time (hour)','time','hr',1,'2026-10-19 00:49:43',NULL);

-- uom_units (16 rows)
INSERT INTO `uom_units` (`id`,`set_id`,`code`,`name`,`factor`,`is_base`,`is_sales_default`,`is_purchase_default`,`sort_order`) VALUES
(1,1,'pc','Piece','1.000000',1,1,0,0),
(2,1,'dz','Dozen (12 pieces)','12.000000',0,0,0,1),
(3,1,'box','Box of 24','24.000000',0,0,1,2),
(4,1,'case','Case of 144','144.000000',0,0,0,3),
(5,2,'kg','Kilogram','1.000000',1,1,1,0),
(6,2,'g','Gram','0.001000',0,0,0,1),
(7,2,'sack','Sack of 25 kg','25.000000',0,0,0,2),
(8,3,'L','Liter','1.000000',1,1,1,0),
(9,3,'mL','Milliliter','0.001000',0,0,0,1),
(10,3,'drum','Drum of 200 L','200.000000',0,0,0,2),
(11,4,'m','Meter','1.000000',1,1,1,0),
(12,4,'cm','Centimeter','0.010000',0,0,0,1),
(13,4,'roll','Roll of 100 m','100.000000',0,0,0,2),
(14,5,'hr','Hour','1.000000',1,1,1,0),
(15,5,'day','Day (8 hours)','8.000000',0,0,0,1),
(16,5,'wk','Week (40 hours)','40.000000',0,0,0,2);

-- items (30 rows)
INSERT INTO `items` (`id`,`item_type`,`code`,`barcode`,`name`,`description`,`purchase_description`,`category_id`,`unit`,`uom_set_id`,`sales_uom_id`,`purchase_uom_id`,`cost`,`avg_cost`,`sales_price`,`discount_pct`,`income_account_id`,`cogs_account_id`,`asset_account_id`,`expense_account_id`,`tax_code_id`,`group_variance_account_id`,`preferred_vendor_id`,`reorder_point`,`max_stock`,`build_point`,`is_active`,`created_at`,`updated_at`,`patronage_eligible`) VALUES
(1,'inventory','LAP-001','4800000000011','Laptop 14-inch i5 16GB','Laptop 14-inch i5 16GB','Laptop 14-inch i5 16GB',1,'pc',1,NULL,NULL,'32000.0000','0.0000','39990.0000','0.00',44,53,13,NULL,1,NULL,1,'5.0000','20.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(2,'inventory','MON-024','4800000000028','Monitor 24-inch IPS','Monitor 24-inch IPS','Monitor 24-inch IPS',1,'pc',1,NULL,NULL,'6500.0000','0.0000','8990.0000','0.00',44,53,13,NULL,1,NULL,1,'5.0000','30.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(3,'inventory','KEY-001','4800000000035','Keyboard USB','Keyboard USB','Keyboard USB',2,'pc',1,NULL,NULL,'650.0000','0.0000','999.0000','0.00',44,53,13,NULL,1,NULL,1,'10.0000','50.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(4,'inventory','MOU-001','4800000000042','Wireless Mouse','Wireless Mouse','Wireless Mouse',2,'pc',1,NULL,NULL,'350.0000','0.0000','599.0000','0.00',44,53,13,NULL,1,NULL,1,'15.0000','80.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(5,'inventory','USB-032','4800000000059','USB Flash Drive 32GB','USB Flash Drive 32GB','USB Flash Drive 32GB',2,'pc',1,NULL,NULL,'180.0000','0.0000','350.0000','0.00',44,53,13,NULL,1,NULL,1,'20.0000','100.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(6,'inventory','BAG-001','4800000000066','Laptop Backpack','Laptop Backpack','Laptop Backpack',2,'pc',1,NULL,NULL,'500.0000','0.0000','899.0000','0.00',44,53,13,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(7,'inventory','CAB-HDMI','4800000000073','HDMI Cable 1.5m','HDMI Cable 1.5m','HDMI Cable 1.5m',2,'pc',1,NULL,NULL,'90.0000','0.0000','199.0000','0.00',44,53,13,NULL,1,NULL,1,'20.0000','100.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(8,'inventory','RAM-8GB','4800000000080','RAM 8GB DDR4','RAM 8GB DDR4','RAM 8GB DDR4',2,'pc',1,NULL,NULL,'1100.0000','0.0000','1690.0000','0.00',44,53,13,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(9,'inventory','SSD-512','4800000000097','SSD 512GB NVMe','SSD 512GB NVMe','SSD 512GB NVMe',2,'pc',1,NULL,NULL,'2200.0000','0.0000','3290.0000','0.00',44,53,13,NULL,1,NULL,1,'10.0000','40.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(10,'service','LAB-ASM',NULL,'Assembly Labor (per set)','Assembly labor',NULL,3,'hr',5,NULL,NULL,'150.0000','0.0000','0.0000','0.00',45,56,NULL,56,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(11,'assembly','SET-OFFICE','4800000000103','Office Monitor Set','Monitor + keyboard + mouse + HDMI cable, assembled',NULL,4,'pc',1,NULL,NULL,'0.0000','0.0000','12990.0000','0.00',44,53,13,NULL,1,NULL,NULL,'3.0000','15.0000','3.0000',1,'2026-10-19 00:49:43',NULL,0),
(12,'service','SRV-INSTALL',NULL,'Installation and Setup','On-site installation and setup',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','1500.0000','0.00',45,53,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(13,'service','SRV-REPAIR',NULL,'Repair Service (per hour)','Repair service',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','800.0000','0.00',45,53,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(14,'service','SRV-MAINT',NULL,'Monthly IT Maintenance','Monthly IT maintenance contract',NULL,3,'hr',5,NULL,NULL,'0.0000','0.0000','5000.0000','0.00',45,53,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(15,'non_inventory','NI-TONER',NULL,'Toner Cartridge (special order)','Toner cartridge - special order',NULL,5,'pc',1,NULL,NULL,'2500.0000','0.0000','3200.0000','0.00',44,53,NULL,53,1,NULL,2,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(16,'other_charge','DEL-FEE',NULL,'Delivery Fee','Delivery fee',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','250.0000','0.00',46,53,NULL,67,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(17,'other_charge','REIMB',NULL,'Reimbursable Expense','Reimbursable expense',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',49,53,NULL,70,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(18,'other_charge','LATE-FEE',NULL,'Late Payment Fee','Late payment fee',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','200.0000','0.00',50,53,NULL,NULL,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(19,'discount','DISC-10',NULL,'10% Discount','10% discount',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','10.00',48,53,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(20,'discount','DISC-AMT',NULL,'Fixed Discount','Discount',NULL,6,'pc',1,NULL,NULL,'0.0000','0.0000','100.0000','0.00',48,53,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(21,'subtotal','SUBTOTAL',NULL,'Subtotal','Subtotal',NULL,NULL,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',44,53,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(22,'group','GRP-WFH',NULL,'Work-from-Home Bundle','Laptop + mouse + backpack',NULL,4,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',44,53,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(23,'payment','DOWNPMT',NULL,'Down Payment Received','Less: down payment received',NULL,NULL,'pc',1,NULL,NULL,'0.0000','0.0000','0.0000','0.00',44,53,NULL,NULL,NULL,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(24,'inventory','USB-032-BX50','4800000000110','USB Flash Drive 32GB (Box of 50)','USB Flash Drive 32GB, box of 50','USB Flash Drive 32GB, box of 50',2,'box',NULL,NULL,NULL,'8500.0000','0.0000','16500.0000','0.00',44,53,13,NULL,1,NULL,1,'1.0000','5.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(25,'inventory','TRD-PC',NULL,'Trade-in Desktop PC (used)','Used desktop PC taken in for parts / refurbishing','Trade-in desktop PC',1,'pc',1,NULL,NULL,'3000.0000','0.0000','0.0000','0.00',44,53,13,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(26,'inventory','REF-PC',NULL,'Refurbished Desktop PC','Refurbished desktop PC with new SSD, 90-day warranty',NULL,1,'pc',1,NULL,NULL,'0.0000','0.0000','14990.0000','0.00',44,53,13,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(27,'inventory','SCRAP-KG',NULL,'Scrap Metal and Plastic','Scrap metal and plastic (sold by the kilo)',NULL,5,'kg',2,NULL,NULL,'15.0000','0.0000','25.0000','0.00',44,53,13,NULL,3,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-19 00:49:43',NULL,0),
(28,'inventory','WM-050',NULL,'Water Meter 1/2 inch','Water Meter 1/2 inch','Water Meter 1/2 inch',7,'pc',NULL,NULL,NULL,'750.0000','0.0000','1200.0000','0.00',141,53,13,NULL,NULL,NULL,NULL,'10.0000','0.0000','0.0000',1,'2026-10-19 00:49:44',NULL,0),
(29,'inventory','PVC-050',NULL,'PVC Pipe 1/2 inch x 3 m','PVC Pipe 1/2 inch x 3 m','PVC Pipe 1/2 inch x 3 m',7,'length',NULL,NULL,NULL,'85.0000','0.0000','120.0000','0.00',141,53,13,NULL,NULL,NULL,NULL,'30.0000','0.0000','0.0000',1,'2026-10-19 00:49:44',NULL,0),
(30,'inventory','WFS-STD',NULL,'Service Connection Fittings Set','Service Connection Fittings Set','Service Connection Fittings Set',7,'set',NULL,NULL,NULL,'260.0000','0.0000','380.0000','0.00',141,53,13,NULL,NULL,NULL,NULL,'10.0000','0.0000','0.0000',1,'2026-10-19 00:49:44',NULL,0);

-- item_components (8 rows)
INSERT INTO `item_components` (`parent_item_id`,`component_item_id`,`qty`) VALUES
(11,2,'1.0000'),
(11,3,'1.0000'),
(11,4,'1.0000'),
(11,7,'1.0000'),
(11,10,'1.0000'),
(22,1,'1.0000'),
(22,4,'1.0000'),
(22,6,'1.0000');

-- process_formulas (3 rows)
INSERT INTO `process_formulas` (`id`,`code`,`name`,`process_type`,`alloc_method`,`site_id`,`description`,`is_active`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'BRK-USB50','Break bulk: USB 32GB box of 50','dekit','quantity',NULL,'Open a box of 50 and sell the drives one by one.',1,NULL,'2026-10-19 00:49:43',NULL),
(2,'TD-PC','Trade-in PC teardown','disassembly','sales_value',NULL,'Strip a trade-in PC for resaleable parts; the chassis is sold as scrap by the kilo.',1,NULL,'2026-10-19 00:49:43',NULL),
(3,'RFB-PC','Refurbish trade-in PC (new SSD)','co_product','percent',NULL,'Trade-in PC plus a new SSD become a refurbished PC; the old RAM is recovered for resale.',1,NULL,'2026-10-19 00:49:43',NULL);

-- process_formula_lines (13 rows)
INSERT INTO `process_formula_lines` (`id`,`formula_id`,`line_no`,`line_type`,`item_id`,`account_id`,`description`,`qty`,`output_role`,`alloc_basis`,`amount`) VALUES
(1,1,1,'input',24,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(2,1,2,'output',5,NULL,NULL,'50.0000','co_product','0.0000','0.00'),
(3,2,1,'input',25,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(4,2,2,'cost',NULL,56,'Technician labor (1 hour)','0.0000',NULL,'0.0000','150.00'),
(5,2,3,'output',8,NULL,NULL,'2.0000','co_product','0.0000','0.00'),
(6,2,4,'output',9,NULL,NULL,'1.0000','co_product','0.0000','0.00'),
(7,2,5,'output',27,NULL,NULL,'4.0000','by_product','15.0000','0.00'),
(8,3,1,'input',25,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(9,3,2,'input',9,NULL,NULL,'1.0000',NULL,'0.0000','0.00'),
(10,3,3,'cost',NULL,56,'Refurbishing labor and testing','0.0000',NULL,'0.0000','400.00'),
(11,3,4,'output',26,NULL,NULL,'1.0000','co_product','85.0000','0.00'),
(12,3,5,'output',8,NULL,NULL,'2.0000','co_product','15.0000','0.00'),
(13,3,6,'output',27,NULL,NULL,'1.0000','scrap','0.0000','0.00');

-- price_level_items (1 rows)
INSERT INTO `price_level_items` (`price_level_id`,`item_id`,`custom_price`) VALUES
(2,1,'36500.0000');

-- withholding_taxes (3 rows)
INSERT INTO `withholding_taxes` (`id`,`code`,`atc`,`name`,`tax_kind`,`rate`,`base_type`,`applies_to`,`payable_account_id`,`receivable_account_id`,`description`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'WT-GOODS','WC158','WT on Goods','income','1.0000','net_of_vat','both',NULL,NULL,'Goods bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',10,1,'2026-10-19 00:49:43',NULL),
(2,'WT-SERVICES','WC160','WT on Services','income','2.0000','net_of_vat','both',NULL,NULL,'Services bought from regular suppliers by government payors and top withholding agents (RR 11-2018)',20,1,'2026-10-19 00:49:43',NULL),
(3,'WT-VAT','WV010','WT on VAT','vat','5.0000','net_of_vat','both',NULL,NULL,'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)',30,1,'2026-10-19 00:49:43',NULL);

-- asset_categories (6 rows)
INSERT INTO `asset_categories` (`id`,`name`,`asset_account_id`,`accum_dep_account_id`,`dep_expense_account_id`,`default_method`,`default_life_years`,`is_active`) VALUES
(1,'Buildings',20,21,66,'straight_line','25.00',1),
(2,'Machinery and Equipment',22,23,66,'straight_line','5.00',1),
(3,'Production Machinery (usage-based)',22,23,66,'units_of_production','5.00',1),
(4,'Transportation Equipment',24,25,66,'declining_balance','5.00',1),
(5,'Furniture and Fixtures',26,27,66,'straight_line','10.00',1),
(6,'Computer and POS Equipment',28,29,66,'declining_balance','3.00',1);

-- pos_terminals (2 rows)
INSERT INTO `pos_terminals` (`id`,`code`,`name`,`site_id`,`cash_account_id`,`receipt_prefix`,`next_receipt_no`,`next_z_no`,`is_active`,`class_id`) VALUES
(1,'POS-01','Makati Counter 1',2,2,'MK1-',1,1,1,NULL),
(2,'POS-02','Cebu Counter 1',3,2,'CB1-',1,1,1,NULL);

-- role_sites (2 rows)
INSERT INTO `role_sites` (`role_id`,`site_id`) VALUES
(7,2),
(7,3);

-- user_sites (2 rows)
INSERT INTO `user_sites` (`user_id`,`site_id`) VALUES
(7,2),
(8,2);

-- user_terminals (2 rows)
INSERT INTO `user_terminals` (`user_id`,`terminal_id`) VALUES
(7,1),
(8,1);

-- member_types (7 rows)
INSERT INTO `member_types` (`id`,`code`,`name`,`member_kind`,`is_regular`,`min_share_capital`,`is_active`,`sort_order`) VALUES
(1,'REG','Regular','individual',1,'0.00',1,1),
(2,'ASI','Associate - Individual','individual',0,'0.00',1,2),
(3,'ASJ','Associate - Joint','joint',0,'0.00',1,3),
(4,'ASN','Associate - Institutional','institutional',0,'0.00',1,4),
(5,'AFT','Aflatoun (youth)','individual',0,'0.00',1,5),
(6,'AFE','Aflateen (youth)','individual',0,'0.00',1,6),
(7,'RGJ','Regular - Joint','joint',1,'0.00',1,3);

-- coop_lists (33 rows)
INSERT INTO `coop_lists` (`id`,`list_key`,`name`,`extra`,`sort_order`,`is_active`) VALUES
(1,'loan_purpose','Business capital',NULL,1,1),
(2,'loan_purpose','Education',NULL,2,1),
(3,'loan_purpose','Medical / emergency',NULL,3,1),
(4,'loan_purpose','House repair / construction',NULL,4,1),
(5,'loan_purpose','Appliance / vehicle',NULL,5,1),
(6,'loan_purpose','Agricultural',NULL,6,1),
(7,'loan_purpose','Loan consolidation',NULL,7,1),
(8,'loan_purpose','Other',NULL,9,1),
(9,'collateral_type','Real estate (TCT / tax declaration)',NULL,1,1),
(10,'collateral_type','Vehicle (OR / CR)',NULL,2,1),
(11,'collateral_type','Deposit hold-out',NULL,3,1),
(12,'collateral_type','Share capital hold-out',NULL,4,1),
(13,'collateral_type','Appliance / chattel',NULL,5,1),
(14,'collateral_type','Other',NULL,9,1),
(15,'account_class','Regular',NULL,1,1),
(16,'account_class','Board of Director',NULL,2,1),
(17,'account_class','Officer',NULL,3,1),
(18,'account_class','Employee',NULL,4,1),
(19,'doc_type','Government ID',NULL,1,1),
(20,'doc_type','Photo',NULL,2,1),
(21,'doc_type','Signature card',NULL,3,1),
(22,'doc_type','Membership application form',NULL,4,1),
(23,'doc_type','Proof of address',NULL,5,1),
(24,'doc_type','PMES certificate',NULL,6,1),
(25,'doc_type','Board resolution (institutional)',NULL,7,1),
(26,'doc_type','Other',NULL,9,1),
(27,'id_type','PhilSys ID',NULL,1,1),
(28,'id_type','Driver\'s license',NULL,2,1),
(29,'id_type','Passport',NULL,3,1),
(30,'id_type','SSS / GSIS',NULL,4,1),
(31,'id_type','UMID',NULL,5,1),
(32,'id_type','Voter\'s ID',NULL,6,1),
(33,'id_type','Other',NULL,9,1);

-- passbook_types (2 rows)
INSERT INTO `passbook_types` (`id`,`name`,`description`,`ledger`,`page_width`,`page_height`,`cover_top`,`cover_left`,`header_top`,`header_left`,`footer_top`,`footer_left`,`body_top`,`body_left`,`body_interval`,`start_line_skip`,`line_skip`,`max_lines`,`columns_json`,`font_size_pt`,`is_active`,`header_json`,`cover_json`,`skip_lines`,`dash_empty`,`footer_text`,`layout_json`) VALUES
(1,'Regular Passbook','Regular savings passbook','savings',6480,10080,900,2400,700,300,8500,100,1230,200,275,12,1,26,'{\"date\":200,\"code\":1400,\"debit\":2600,\"credit\":4400,\"balance\":6200,\"teller\":8000}','9.0',1,NULL,NULL,NULL,1,NULL,'{\"cover\":[{\"f\":\"name\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":0,\"y\":460,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"address\",\"x\":0,\"y\":920,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"contact\",\"x\":0,\"y\":1380,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"branch\",\"x\":0,\"y\":1840,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"header\":[{\"f\":\"name\",\"x\":160,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":4430,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"body\":[{\"f\":\"line\",\"x\":0,\"y\":0,\"w\":470,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"date\",\"x\":530,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"code\",\"x\":1210,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"credit\",\"x\":1700,\"y\":0,\"w\":930,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"-\",\"t\":\"\",\"fs\":0},{\"f\":\"debit\",\"x\":2750,\"y\":0,\"w\":930,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"-\",\"t\":\"\",\"fs\":0},{\"f\":\"balance\",\"x\":3750,\"y\":0,\"w\":1130,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"0\",\"t\":\"\",\"fs\":0},{\"f\":\"teller\",\"x\":4950,\"y\":0,\"w\":290,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"footer\":[{\"f\":\"text\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"Balance as of {date}: {balance}\",\"fs\":0}],\"opt\":{\"header\":\"new_page\",\"footer_full\":0,\"font\":\"mono\",\"cpi\":20}}'),
(2,'Loan Passbook','Loan passbook','loan',6840,9720,900,2400,700,300,9300,100,1230,1,275,13,2,29,'{\"date\":200,\"code\":1400,\"debit\":2600,\"credit\":4400,\"balance\":6200,\"teller\":8000}','9.0',1,NULL,NULL,NULL,1,NULL,'{\"cover\":[{\"f\":\"name\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"number\",\"x\":0,\"y\":460,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"address\",\"x\":0,\"y\":920,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"contact\",\"x\":0,\"y\":1380,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"branch\",\"x\":0,\"y\":1840,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"header\":[{\"f\":\"loan_type\",\"x\":700,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"term\",\"x\":4220,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"mos\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"due\",\"x\":5600,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"body\":[{\"f\":\"line\",\"x\":0,\"y\":0,\"w\":570,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"date\",\"x\":680,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"n\\/j\\/y\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"ref\",\"x\":1340,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"release\",\"x\":1900,\"y\":0,\"w\":850,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"prin\",\"x\":2900,\"y\":0,\"w\":730,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"bal\",\"x\":3800,\"y\":0,\"w\":900,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"0\",\"t\":\"\",\"fs\":0},{\"f\":\"int\",\"x\":4800,\"y\":0,\"w\":700,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"fines\",\"x\":5700,\"y\":0,\"w\":510,\"a\":\"r\",\"fmt\":\"comma\",\"z\":\"\",\"t\":\"\",\"fs\":0},{\"f\":\"teller\",\"x\":6300,\"y\":0,\"w\":320,\"a\":\"r\",\"fmt\":\"\",\"z\":\"\",\"t\":\"\",\"fs\":0}],\"footer\":[{\"f\":\"text\",\"x\":0,\"y\":0,\"w\":0,\"a\":\"l\",\"fmt\":\"\",\"z\":\"\",\"t\":\"Loan balance as of {date}: {balance}\",\"fs\":0}],\"opt\":{\"header\":\"new_page\",\"footer_full\":0,\"font\":\"mono\",\"cpi\":20}}');

-- sav_txn_codes (39 rows)
INSERT INTO `sav_txn_codes` (`id`,`code`,`description`,`opposite_kind`,`opposite_account_id`,`entry_side`,`is_check_deposit`,`clearing_days`,`is_error_correct`,`related_code`,`is_system`,`is_active`,`is_memo`) VALUES
(1,'CSD','Cash Savings Deposit','user',NULL,'credit',0,0,0,NULL,1,1,0),
(2,'CSW','Cash Savings Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(3,'CTD','Cash Time Deposit','user',NULL,'credit',0,0,0,NULL,1,1,0),
(4,'CTDW','Cash Time Deposit Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(5,'CK3','Local Check Deposit 3 Days Clearing','gl',72,'credit',1,3,0,NULL,0,1,0),
(6,'CK4','Local Check Deposit 4 Days Clearing','gl',72,'credit',1,4,0,NULL,0,1,0),
(7,'CK5','Local Check Deposit 5 Days Clearing','gl',72,'credit',1,5,0,NULL,0,1,0),
(8,'CK7','Regional Check Deposit 7 Days Clearing','gl',72,'credit',1,7,0,NULL,0,1,0),
(9,'CKTD','Check Time Deposit','gl',72,'credit',1,5,0,NULL,0,1,0),
(10,'INT','Interest Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(11,'WTX','Withholding Tax on Interest','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(12,'DF','Dormancy Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(13,'CF','Closing Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(14,'PTF','Pre-termination Fee','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(15,'LP','Loan Proceeds Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(16,'LC','Loan Charge Credited to Account','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(17,'LPY','Loan Payment Debited from Account','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(18,'TRI','Transfer In','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(19,'TRO','Transfer Out','gl',NULL,'debit',0,0,0,NULL,1,1,0),
(20,'ISC','Interest on Share Capital','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(21,'PR','Patronage Refund','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(22,'PDD','Payroll Deduction Deposit','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(23,'TDM','Time Deposit Maturity / Renewal','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(24,'OB','Opening Balance','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(25,'RTC','Returned Check','gl',72,'debit',0,0,0,NULL,0,1,0),
(26,'CM','Credit Memo (Adjustment)','gl',NULL,'credit',0,0,0,NULL,0,1,1),
(27,'DM','Debit Memo (Adjustment)','gl',NULL,'debit',0,0,0,NULL,0,1,1),
(28,'ECSD','Error Correct Cash Savings Deposit','user',NULL,'credit',0,0,1,'CSD',0,1,0),
(29,'ECSW','Error Correct Cash Savings Withdrawal','user',NULL,'debit',0,0,1,'CSW',0,1,0),
(30,'ECM','Error Correct Credit Memo','gl',NULL,'credit',0,0,1,'CM',0,1,1),
(31,'EDM','Error Correct Debit Memo','gl',NULL,'debit',0,0,1,'DM',0,1,1),
(32,'ECK3','Error Correct Local Check Deposit 3 Days Clearing','gl',72,'credit',0,0,1,'CK3',0,1,0),
(33,'ECK4','Error Correct Local Check Deposit 4 Days Clearing','gl',72,'credit',0,0,1,'CK4',0,1,0),
(34,'ECK5','Error Correct Local Check Deposit 5 Days Clearing','gl',72,'credit',0,0,1,'CK5',0,1,0),
(35,'ECK7','Error Correct Regional Check Deposit 7 Days Clearing','gl',72,'credit',0,0,1,'CK7',0,1,0),
(36,'ECKTD','Error Correct Check Time Deposit','gl',72,'credit',0,0,1,'CKTD',0,1,0),
(39,'CAIW','Cash Accrued Interest Withdrawal','user',NULL,'debit',0,0,0,NULL,1,1,0),
(40,'TDI','Time Deposit Interest Credited','gl',NULL,'credit',0,0,0,NULL,1,1,0),
(41,'INI','Initial Savings Import','gl',43,'credit',0,0,0,NULL,0,1,0);

-- cda_calendar (4 rows)
INSERT INTO `cda_calendar` (`id`,`title`,`category`,`due_date`,`recurrence`,`remind_days`,`notes`,`is_active`) VALUES
(1,'CAPR and audited financial statements (within 120 days after fiscal year end)','cda','2026-04-30','yearly',45,'Confirm the current CDA deadline for your cooperative\'s fiscal year',1),
(2,'Certificate of Compliance application','cda','2026-04-30','yearly',45,'Submitted with the annual reports',1),
(3,'Annual Tax Incentives Report / Annual Benefits Report','bir','2026-04-30','yearly',45,'Where applicable to the cooperative',1),
(4,'General Assembly','internal','2026-03-31','yearly',60,'Set the date fixed by the bylaws',1);

-- cda_report_mappings (32 rows)
INSERT INTO `cda_report_mappings` (`id`,`report`,`section`,`field_code`,`label`,`source`,`sort_order`) VALUES
(1,'capr','General','registered_name','Registered name','setting:registered_name',-3),
(2,'capr','General','cda_reg_no','CDA registration no.','setting:cda_reg_no',-2),
(3,'capr','General','officers','Officers and committee members','metric:officers_count',-1),
(4,'capr','Membership','members_total','Total members','metric:members_total',0),
(5,'capr','Membership','members_regular','Regular members','metric:members_regular',1),
(6,'capr','Membership','members_associate','Associate members','metric:members_associate',2),
(7,'capr','Membership','members_new','New members during the year','metric:members_new',3),
(8,'capr','Membership','members_left','Members who left','metric:members_separated',4),
(9,'capr','Membership','members_male','Male members','metric:members_male',5),
(10,'capr','Membership','members_female','Female members','metric:members_female',6),
(11,'capr','Capital','share_capital','Paid-up share capital','metric:paid_up_capital',10),
(12,'capr','Capital','subscribed_capital','Subscribed share capital','metric:subscribed_capital',11),
(13,'capr','Deposits','savings_deposits','Savings deposits','metric:savings_deposits',20),
(14,'capr','Deposits','time_deposits','Time deposits','metric:time_deposits',21),
(15,'capr','Loans','loans_released_n','Number of loans released during the year','metric:loans_released_count',29),
(16,'capr','Loans','loans_released','Loans released during the year','metric:loans_released_amount',30),
(17,'capr','Loans','loans_outstanding','Loans receivable outstanding','metric:loans_outstanding',31),
(18,'capr','Loans','loans_past_due','Past due loans','metric:loans_past_due',32),
(19,'capr','Loans','par_ratio','Portfolio at risk (%)','metric:par_ratio',33),
(20,'capr','Loans','borrowers','Number of borrowers','metric:loans_borrowers',34),
(21,'capr','Results','total_assets','Total assets','metric:total_assets',40),
(22,'capr','Results','total_liabilities','Total liabilities','metric:total_liabilities',41),
(23,'capr','Results','total_equity','Total members\' equity','metric:total_equity',42),
(24,'capr','Results','revenue','Total revenue','metric:gross_revenue',43),
(25,'capr','Results','expenses','Total expenses','metric:total_expenses',44),
(26,'capr','Results','net_surplus','Net surplus','metric:net_surplus',45),
(27,'capr','Statutory','reserve_fund','Reserve fund','metric:reserve_fund',50),
(28,'capr','Statutory','educ_fund','Education and training fund','metric:education_fund',51),
(29,'capr','Statutory','cdf_fund','Community development fund','metric:cdf_fund',52),
(30,'capr','Statutory','optional_fund','Optional fund','metric:optional_fund',53),
(31,'capr','Distribution','isc_declared','Interest on share capital declared','metric:isc_distributed',60),
(32,'capr','Distribution','pr_declared','Patronage refund declared','metric:pr_distributed',61);

-- parties (16 rows)
INSERT INTO `parties` (`id`,`party_no`,`party_type`,`legal_name`,`display_name`,`last_name`,`first_name`,`middle_name`,`suffix`,`tin`,`birth_date`,`registration_no`,`email`,`mobile`,`phone`,`address`,`home_branch_id`,`status`,`merged_into_id`,`src_table`,`src_id`,`notes`,`created_by`,`created_at`,`updated_by`,`updated_at`) VALUES
(1,'P-000001','organization','ABC Trading Corporation','ABC Trading Corp.',NULL,NULL,NULL,NULL,'111-222-333-000',NULL,NULL,'ap@abctrading.test',NULL,'(02) 8777-1001','45 Buendia Ave., Makati City',NULL,'active',NULL,'customer',1,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(2,'P-000002','organization','Metro School Supplies Inc.','Metro School Supplies',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'purchasing@metroschool.test',NULL,'(02) 8777-2002','88 España Blvd., Manila',NULL,'active',NULL,'customer',3,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(3,'P-000003','organization','Cebu Tech Hub Co.','Cebu Tech Hub',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'finance@cebutech.test',NULL,'(032) 255-3003','IT Park, Lahug, Cebu City',NULL,'active',NULL,'customer',4,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(4,'P-000004','organization','Bright Minds Academy','Bright Minds Academy',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'admin@brightminds.test',NULL,'(02) 8777-4004','12 Katipunan Ave., Quezon City',NULL,'active',NULL,'customer',5,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(5,'P-000005','organization','Green Valley Clinic','Green Valley Clinic',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'(02) 8777-5005','5 Shaw Blvd., Mandaluyong',NULL,'active',NULL,'customer',6,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(6,'P-000006','organization','City Government of Makati','City Government of Makati',NULL,NULL,NULL,NULL,'000-456-789-000',NULL,NULL,'procurement@makati.test',NULL,'(02) 8870-1000','Makati City Hall, J.P. Rizal St., Makati City',NULL,'active',NULL,'customer',7,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(7,'P-000007','person','Juan Dela Cruz','Juan Dela Cruz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0917-555-0000',NULL,NULL,NULL,'active',NULL,'customer',8,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(8,'P-000008','person','Walk-in Customer','Walk-in Customer',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,'customer',9,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(16,'P-000009','organization','TechDistributors Inc.','TechDistributors Inc.',NULL,NULL,NULL,NULL,'770-665-736-000',NULL,NULL,'sales@techdist.test',NULL,'(02) 8555-0101','Pasig City',NULL,'active',NULL,'vendor',1,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(17,'P-000010','organization','Office Depot PH','Office Depot PH',NULL,NULL,NULL,NULL,'936-412-406-000',NULL,NULL,'orders@officedepot.test',NULL,'(02) 8555-0202','Quezon City',NULL,'active',NULL,'vendor',2,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(18,'P-000011','organization','Meralco','Meralco',NULL,NULL,NULL,NULL,'470-303-958-000',NULL,NULL,'billing@meralco.test',NULL,'16211','Pasig City',NULL,'active',NULL,'vendor',3,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(19,'P-000012','organization','Metro Property Leasing','Metro Property Leasing',NULL,NULL,NULL,NULL,'204-917-858-000',NULL,NULL,'leasing@metroprop.test',NULL,'(02) 8555-0303','Makati City',NULL,'active',NULL,'vendor',4,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(20,'P-000013','organization','Petron Service Station','Petron Service Station',NULL,NULL,NULL,NULL,'128-831-749-000',NULL,NULL,NULL,NULL,'(02) 8555-0404','Makati City',NULL,'active',NULL,'vendor',5,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(21,'P-000014','organization','JRS Express','JRS Express',NULL,NULL,NULL,NULL,'123-447-598-000',NULL,NULL,'ops@jrs.test',NULL,'(02) 8555-0505','Manila',NULL,'active',NULL,'vendor',6,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(22,'P-000015','organization','Accounting Partners & Co.','Accounting Partners & Co.',NULL,NULL,NULL,NULL,'159-106-543-000',NULL,NULL,'hello@apco.test',NULL,'(02) 8555-0606','Taguig City',NULL,'active',NULL,'vendor',7,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL),
(23,'P-000016','organization','Industrial Machines Corp.','Industrial Machines Corp.',NULL,NULL,NULL,NULL,'538-240-770-000',NULL,NULL,'sales@imc.test',NULL,'(02) 8555-0707','Laguna',NULL,'active',NULL,'vendor',8,NULL,NULL,'2026-10-19 00:49:43',NULL,NULL);

-- party_crosswalk (17 rows)
INSERT INTO `party_crosswalk` (`id`,`source`,`source_id`,`source_ref`,`party_id`,`how`,`created_at`) VALUES
(1,'customer',1,'ABC Trading Corp.',1,'backfill','2026-10-19 00:49:43'),
(2,'customer',2,'Office Renovation Project',1,'backfill','2026-10-19 00:49:43'),
(3,'customer',3,'Metro School Supplies',2,'backfill','2026-10-19 00:49:43'),
(4,'customer',4,'Cebu Tech Hub',3,'backfill','2026-10-19 00:49:43'),
(5,'customer',5,'Bright Minds Academy',4,'backfill','2026-10-19 00:49:43'),
(6,'customer',6,'Green Valley Clinic',5,'backfill','2026-10-19 00:49:43'),
(7,'customer',7,'City Government of Makati',6,'backfill','2026-10-19 00:49:43'),
(8,'customer',8,'Juan Dela Cruz',7,'backfill','2026-10-19 00:49:43'),
(9,'customer',9,'Walk-in Customer',8,'backfill','2026-10-19 00:49:43'),
(16,'vendor',1,'TechDistributors Inc.',16,'backfill','2026-10-19 00:49:43'),
(17,'vendor',2,'Office Depot PH',17,'backfill','2026-10-19 00:49:43'),
(18,'vendor',3,'Meralco',18,'backfill','2026-10-19 00:49:43'),
(19,'vendor',4,'Metro Property Leasing',19,'backfill','2026-10-19 00:49:43'),
(20,'vendor',5,'Petron Service Station',20,'backfill','2026-10-19 00:49:43'),
(21,'vendor',6,'JRS Express',21,'backfill','2026-10-19 00:49:43'),
(22,'vendor',7,'Accounting Partners & Co.',22,'backfill','2026-10-19 00:49:43'),
(23,'vendor',8,'Industrial Machines Corp.',23,'backfill','2026-10-19 00:49:43');

-- party_role_log (16 rows)
INSERT INTO `party_role_log` (`id`,`party_id`,`role`,`ref_id`,`action`,`other_party_id`,`reason`,`approved_by`,`created_by`,`created_at`) VALUES
(1,1,'customer',1,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(2,2,'customer',3,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(3,3,'customer',4,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(4,4,'customer',5,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(5,5,'customer',6,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(6,6,'customer',7,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(7,7,'customer',8,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(8,8,'customer',9,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(16,16,'supplier',1,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(17,17,'supplier',2,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(18,18,'supplier',3,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(19,19,'supplier',4,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(20,20,'supplier',5,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(21,21,'supplier',6,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(22,22,'supplier',7,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43'),
(23,23,'supplier',8,'granted',NULL,'Held at the upgrade',NULL,NULL,'2026-10-19 00:49:43');

-- business_units (3 rows)
INSERT INTO `business_units` (`id`,`code`,`name`,`kind`,`nonmember_policy`,`patronage_eligible`,`member_price_level_id`,`nonmember_price_level_id`,`default_site_id`,`description`,`is_active`,`sort_order`,`created_at`) VALUES
(1,'SC','Savings and Credit','savings_credit','members',1,NULL,NULL,NULL,'Share capital, savings, time deposits and loans of members',1,1,'2026-10-19 00:49:43'),
(2,'TR','Consumer Store','retail','allow',1,NULL,NULL,NULL,'Retail and trading of goods to members and the public',1,2,'2026-10-19 00:49:43'),
(3,'WU','Water Utility','services','allow',0,NULL,NULL,6,'Water service: metered water sales, connection fees, materials and collections of water-service accounts',1,3,'2026-10-19 00:49:44');

-- addresstype (3 rows)
INSERT INTO `addresstype` (`pkey`,`name`,`description`,`status`) VALUES
(1,'Residence','Residence Address','A'),
(2,'Provincial','Provincial Address','A'),
(3,'Office','Office/Business Address','A');

-- bill_codes (12 rows)
INSERT INTO `bill_codes` (`code_id`,`code`,`description`,`type`,`ar_account`,`income_account`,`cogs_account`,`asset_account`,`item_id`,`cost_price`,`sales_price`,`allow_member_discount`,`modified_by`,`created_at`,`updated_at`) VALUES
(1,'WATER BILL','Monthly metered water consumption','Service',135,137,NULL,NULL,NULL,'0.00','0.00',1,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(2,'CONNECTION FEE','New service connection fee','Service',135,138,NULL,NULL,NULL,'0.00','1500.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(3,'RECONNECTION FEE','Reconnection after a disconnection','Service',135,138,NULL,NULL,NULL,'0.00','300.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(4,'SERVICE DEPOSIT','Refundable service (meter) deposit','Charges',135,136,NULL,NULL,NULL,'0.00','500.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(5,'WATER METER','Water meter 1/2 inch sold to the consumer','Inventory',135,141,53,13,28,'750.00','1200.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(6,'MAINTENANCE FEE','Monthly line maintenance fee (commercial connections)','Charges',135,138,NULL,NULL,NULL,'0.00','50.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(7,'PVC PIPE','PVC pipe 1/2 inch x 3 m (service line materials)','Inventory',135,141,53,13,29,'85.00','120.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(8,'FITTINGS SET','Service connection fittings set','Inventory',135,141,53,13,30,'260.00','380.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(9,'SHARE CAPITAL','Share capital contribution collected with the water bill','Charges',135,145,NULL,NULL,NULL,'0.00','50.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(10,'DAMAYAN','Damayan (mutual aid) contribution collected with the water bill','Charges',135,146,NULL,NULL,NULL,'0.00','50.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(11,'FUNOS','Funos collected with the water bill (former program charge)','Charges',135,147,NULL,NULL,NULL,'0.00','70.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44'),
(12,'METER INSTALLATION','Meter installation amount billed with the water bill','Service',135,141,NULL,NULL,NULL,'0.00','400.00',0,1,'2026-10-19 00:49:44','2026-10-19 00:49:44');

-- branches (1 rows)
INSERT INTO `branches` (`pkey`,`name`,`description`,`class_id`,`Deleted`,`createdby`,`date_created`,`modifiedby`,`date_modified`) VALUES
(1,'Main Branch','Default branch',NULL,0,'system','2026-05-26 09:28:41',NULL,NULL);

-- bussinessprofile (1 rows)
INSERT INTO `bussinessprofile` (`pkey`,`name`,`description`,`address1`,`address2`,`logoName`,`logoImage`,`telephoneNo`,`faxNo`,`cellularNo`,`sssNo`,`pagibigNo`,`philhealthNo`,`tinNo`,`email`,`website`,`IsBranch`,`parentBusProfID`,`lockProfile`,`lockDate`,`lockPassword`,`dateCreated`,`createdBy`,`dateLastModified`,`modifiedBy`,`isdeleted`) VALUES
(1,'Sample Trading Company','Water Utility','123 Rizal Avenue, Makati City, Metro Manila','',NULL,NULL,'(02) 8123-4567',NULL,NULL,NULL,NULL,NULL,'000-123-456-000','accounting@sampletrading.test',NULL,0,0,NULL,NULL,NULL,'2026-10-19 00:49:44','system',NULL,NULL,0);

-- cities (145 rows)
INSERT INTO `cities` (`pkey`,`city`,`province`,`country`,`status`) VALUES
(1,'Caloocan','','Philippines','A'),
(2,'Las Piñas','','Philippines','A'),
(3,'Makati','','Philippines','A'),
(4,'Malabon','','Philippines','A'),
(5,'Mandaluyong','','Philippines','A'),
(6,'Manila','','Philippines','A'),
(7,'Marikina','','Philippines','A'),
(8,'Muntinlupa','','Philippines','A'),
(9,'Navotas','','Philippines','A'),
(10,'Parañaque','','Philippines','A'),
(11,'Pasay','','Philippines','A'),
(12,'Pasig','','Philippines','A'),
(13,'Quezon City','','Philippines','A'),
(14,'San Juan','','Philippines','A'),
(15,'Taguig','','Philippines','A'),
(16,'Valenzuela','','Philippines','A'),
(17,'Butuan','Agusan del Norte','Philippines','A'),
(18,'Cabadbaran','Agusan del Norte','Philippines','A'),
(19,'Bayugan','Agusan del Sur','Philippines','A'),
(20,'Legazpi','Albay','Philippines','A'),
(21,'Ligao','Albay','Philippines','A'),
(22,'Tabaco','Albay','Philippines','A'),
(23,'Isabela','Basilan','Philippines','A'),
(24,'Lamitan','Basilan','Philippines','A'),
(25,'Balanga','Bataan','Philippines','A'),
(26,'Batangas City','Batangas','Philippines','A'),
(27,'Lipa','Batangas','Philippines','A'),
(28,'Tanauan','Batangas','Philippines','A'),
(29,'Baguio','Benguet','Philippines','A'),
(30,'Tagbilaran','Bohol','Philippines','A'),
(31,'Malaybalay','Bukidnon','Philippines','A'),
(32,'Valencia','Bukidnon','Philippines','A'),
(33,'Malolos','Bulacan','Philippines','A'),
(34,'Meycauayan','Bulacan','Philippines','A'),
(35,'San Jose del Monte','Bulacan','Philippines','A'),
(36,'Tuguegarao','Cagayan','Philippines','A'),
(37,'Iriga','Camarines Sur','Philippines','A'),
(38,'Naga','Camarines Sur','Philippines','A'),
(39,'Roxas','Capiz','Philippines','A'),
(40,'Bacoor','Cavite','Philippines','A'),
(41,'Cavite City','Cavite','Philippines','A'),
(42,'Dasmariñas','Cavite','Philippines','A'),
(43,'Imus','Cavite','Philippines','A'),
(44,'Tagaytay','Cavite','Philippines','A'),
(45,'Trece Martires','Cavite','Philippines','A'),
(46,'Bogo','Cebu','Philippines','A'),
(47,'Carcar','Cebu','Philippines','A'),
(48,'Cebu City','Cebu','Philippines','A'),
(49,'Danao','Cebu','Philippines','A'),
(50,'Lapu-Lapu','Cebu','Philippines','A'),
(51,'Mandaue','Cebu','Philippines','A'),
(52,'Naga','Cebu','Philippines','A'),
(53,'Talisay','Cebu','Philippines','A'),
(54,'Toledo','Cebu','Philippines','A'),
(55,'Kidapawan','Cotabato (North)','Philippines','A'),
(56,'Panabo','Davao del Norte','Philippines','A'),
(57,'Samal','Davao del Norte','Philippines','A'),
(58,'Tagum','Davao del Norte','Philippines','A'),
(59,'Davao City','Davao del Sur','Philippines','A'),
(60,'Digos','Davao del Sur','Philippines','A'),
(61,'Mati','Davao Oriental','Philippines','A'),
(62,'Borongan','Eastern Samar','Philippines','A'),
(63,'Batac','Ilocos Norte','Philippines','A'),
(64,'Laoag','Ilocos Norte','Philippines','A'),
(65,'Candon','Ilocos Sur','Philippines','A'),
(66,'Vigan','Ilocos Sur','Philippines','A'),
(67,'Iloilo City','Iloilo','Philippines','A'),
(68,'Passi','Iloilo','Philippines','A'),
(69,'Cauayan','Isabela','Philippines','A'),
(70,'Ilagan','Isabela','Philippines','A'),
(71,'Santiago','Isabela','Philippines','A'),
(72,'Tabuk','Kalinga','Philippines','A'),
(73,'San Fernando','La Union','Philippines','A'),
(74,'Biñan','Laguna','Philippines','A'),
(75,'Cabuyao','Laguna','Philippines','A'),
(76,'Calamba','Laguna','Philippines','A'),
(77,'San Pablo','Laguna','Philippines','A'),
(78,'Santa Rosa','Laguna','Philippines','A'),
(79,'Iligan','Lanao del Norte','Philippines','A'),
(80,'Marawi','Lanao del Sur','Philippines','A'),
(81,'Baybay','Leyte','Philippines','A'),
(82,'Ormoc','Leyte','Philippines','A'),
(83,'Tacloban','Leyte','Philippines','A'),
(84,'Cotabato City','Maguindanao','Philippines','A'),
(85,'Masbate City','Masbate','Philippines','A'),
(86,'Oroquieta','Misamis Occidental','Philippines','A'),
(87,'Ozamiz','Misamis Occidental','Philippines','A'),
(88,'Tangub','Misamis Occidental','Philippines','A'),
(89,'Cagayan de Oro','Misamis Oriental','Philippines','A'),
(90,'El Salvador','Misamis Oriental','Philippines','A'),
(91,'Gingoog','Misamis Oriental','Philippines','A'),
(92,'Bacolod','Negros Occidental','Philippines','A'),
(93,'Bago','Negros Occidental','Philippines','A'),
(94,'Cadiz','Negros Occidental','Philippines','A'),
(95,'Escalante','Negros Occidental','Philippines','A'),
(96,'Himamaylan','Negros Occidental','Philippines','A'),
(97,'Kabankalan','Negros Occidental','Philippines','A'),
(98,'La Carlota','Negros Occidental','Philippines','A'),
(99,'Sagay','Negros Occidental','Philippines','A'),
(100,'San Carlos','Negros Occidental','Philippines','A');
INSERT INTO `cities` (`pkey`,`city`,`province`,`country`,`status`) VALUES
(101,'Silay','Negros Occidental','Philippines','A'),
(102,'Sipalay','Negros Occidental','Philippines','A'),
(103,'Talisay','Negros Occidental','Philippines','A'),
(104,'Victorias','Negros Occidental','Philippines','A'),
(105,'Bais','Negros Oriental','Philippines','A'),
(106,'Bayawan','Negros Oriental','Philippines','A'),
(107,'Canlaon','Negros Oriental','Philippines','A'),
(108,'Dumaguete','Negros Oriental','Philippines','A'),
(109,'Guihulngan','Negros Oriental','Philippines','A'),
(110,'Tanjay','Negros Oriental','Philippines','A'),
(111,'Cabanatuan','Nueva Ecija','Philippines','A'),
(112,'Gapan','Nueva Ecija','Philippines','A'),
(113,'Muñoz','Nueva Ecija','Philippines','A'),
(114,'Palayan','Nueva Ecija','Philippines','A'),
(115,'San Jose','Nueva Ecija','Philippines','A'),
(116,'Calapan','Oriental Mindoro','Philippines','A'),
(117,'Puerto Princesa','Palawan','Philippines','A'),
(118,'Angeles','Pampanga','Philippines','A'),
(119,'Mabalacat','Pampanga','Philippines','A'),
(120,'San Fernando','Pampanga','Philippines','A'),
(121,'Alaminos','Pangasinan','Philippines','A'),
(122,'Dagupan','Pangasinan','Philippines','A'),
(123,'San Carlos','Pangasinan','Philippines','A'),
(124,'Urdaneta','Pangasinan','Philippines','A'),
(125,'Lucena','Quezon','Philippines','A'),
(126,'Tayabas','Quezon','Philippines','A'),
(127,'Antipolo','Rizal','Philippines','A'),
(128,'Calbayog','Samar (Western)','Philippines','A'),
(129,'Catbalogan','Samar (Western)','Philippines','A'),
(130,'Sorsogon City','Sorsogon','Philippines','A'),
(131,'General Santos','South Cotabato','Philippines','A'),
(132,'Koronadal','South Cotabato','Philippines','A'),
(133,'Maasin','Southern Leyte','Philippines','A'),
(134,'Tacurong','Sultan Kudarat','Philippines','A'),
(135,'Surigao City','Surigao del Norte','Philippines','A'),
(136,'Bislig','Surigao del Sur','Philippines','A'),
(137,'Tandag','Surigao del Sur','Philippines','A'),
(138,'Tarlac City','Tarlac','Philippines','A'),
(139,'Olongapo','Zambales','Philippines','A'),
(140,'Dapitan','Zamboanga del Norte','Philippines','A'),
(141,'Dipolog','Zamboanga del Norte','Philippines','A'),
(142,'Pagadian','Zamboanga del Sur','Philippines','A'),
(143,'Zamboanga City','Zamboanga del Sur','Philippines','A'),
(144,'Cordova','Cebu','Philippines','A'),
(145,'Sibonga','Cebu','Philippines','A');

-- contactchannels (4 rows)
INSERT INTO `contactchannels` (`pkey`,`name`,`description`,`datecreated`,`datelastmodified`,`status`) VALUES
(1,'Telephone','Tel. no.','2013-01-21 22:23:23',NULL,'A'),
(2,'Mobile','Mobile no.','2013-01-21 22:23:23',NULL,'A'),
(3,'Office','Tel. no.','2013-01-21 22:23:23',NULL,'A'),
(4,'E-mail','E-mail Address','2013-01-21 22:23:23',NULL,'A');

-- data_import_mapping_profiles (1 rows)
INSERT INTO `data_import_mapping_profiles` (`profile_id`,`profile_name`,`import_type`,`version`,`options_json`,`created_by`,`created_at`) VALUES
(1,'Former water program files','any',1,'{\"rate_map\":{\"A\":2,\"B\":1,\"C\":6,\"D\":5},\"pipe_map\":{\"A\":2,\"B\":2,\"C\":2,\"D\":2},\"default_pipe_size_id\":2,\"address_mode\":\"source\",\"default_city_id\":0,\"default_zip\":0,\"source_discount_id\":0,\"senior_discount_id\":1,\"default_class_id\":0,\"stage_payments\":true,\"recurring_map\":{\"Share Capital\":1,\"DamayanContribution\":2,\"Funos\":3,\"Meter Instal Amt\":4,\"Recon Fee\":5},\"existing_account_mode\":\"skip\"}',1,'2026-10-19 00:49:44');

-- data_import_mapping_rules (21 rows)
INSERT INTO `data_import_mapping_rules` (`rule_key`,`rule_value`,`status`,`notes`,`confirmed_by`,`confirmed_at`,`updated_at`,`version`) VALUES
('amt_paid_meaning','tendered','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('blank_optional_fee','zero','confirmed','Default of the starter set-up',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',1),
('charge:DamayanContribution','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Fittings Fee','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Funos','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Installation Fee','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Membership Fee','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Meter Instal Amt','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:MonthlyAmortization','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Other Fees','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Recon Fee','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Share Capital','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('charge:Water Meter','recurring_plan','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('date_order','mdy','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('disconnection_meaning','scheduled','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('gl_policy','opening_balance','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('negative_usage','review','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('paid_rule','flag_receipt_date','confirmed','Default of the starter set-up',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',1),
('partial_payment','allocate_partial','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1),
('penalty_compromise','both','confirmed','Default of the starter set-up',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',1),
('receipt_scope','global','assumed','Default of the starter set-up',NULL,NULL,'2026-10-19 00:49:44',1);

-- discount_privilege_rules (2 rows)
INSERT INTO `discount_privilege_rules` (`discount_rule_id`,`discount_id`,`bill_code_id`,`module_context`,`discount_method`,`discount_value`,`max_discount_amount`,`max_discount_cubic`,`is_active`,`created_at`,`updated_at`) VALUES
(1,2,1,'water','free_cubic','10.00',NULL,10,1,'2026-05-27 21:32:08','2026-07-27 19:44:19'),
(2,1,1,'water','percent','5.00','100.00',NULL,1,'2026-05-27 21:32:08','2026-07-27 19:44:19');

-- discounts (2 rows)
INSERT INTO `discounts` (`discount_id`,`discount_type`,`is_active`,`water_discount_active`,`water_discount_method`,`water_discount_value`,`water_max_discount_amount`,`water_max_discount_cubic`,`one_time_discount_active`,`one_time_discount_method`,`one_time_discount_value`,`one_time_max_discount_amount`,`installment_discount_active`,`installment_discount_method`,`installment_discount_value`,`installment_max_discount_amount`,`recurring_discount_active`,`recurring_discount_method`,`recurring_discount_value`,`recurring_max_discount_amount`,`created_at`,`updated_at`) VALUES
(1,'Senior Citizen',1,1,'percent','5.00','100.00',NULL,0,'none','0.00',NULL,0,'none','0.00',NULL,0,'none','0.00',NULL,'2026-06-08 11:27:51','2026-06-08 11:27:51'),
(2,'Employees',1,1,'free_cubic','10.00',NULL,NULL,0,'none','0.00',NULL,0,'none','0.00',NULL,0,'none','0.00',NULL,'2026-07-27 19:40:04','2026-07-31 18:37:18');

-- ic_divisions (1 rows)
INSERT INTO `ic_divisions` (`pkey`,`name`,`datecreated`,`datelastmodified`,`status`) VALUES
(1,'General Division','2026-05-26 09:28:41',NULL,'A');

-- ic_segments (1 rows)
INSERT INTO `ic_segments` (`pkey`,`name`,`datecreated`,`datelastmodified`,`status`) VALUES
(1,'General Segment','2026-05-26 09:28:41',NULL,'A');

-- rate_code (4 rows)
INSERT INTO `rate_code` (`rc_id`,`rc_code`,`rc_name`,`billing_method`,`created_at`) VALUES
(1,'A','RESIDENTIAL REGULAR / INSTITUTIONAL','straight','2026-05-27 21:18:28'),
(2,'A','RESIDENTIAL ASSOCIATE / INSTITUTIONAL','straight','2026-05-27 21:18:28'),
(5,'B','COMMERCIAL REGULAR / INSTITUTIONAL / INDUSTRIAL','straight','2026-07-31 15:36:58'),
(6,'B','COMMERCIAL ASSOCIATE / INSTITUTIONAL / INDUSTRIAL','straight','2026-07-31 15:37:22');

-- recurring_plans (5 rows)
INSERT INTO `recurring_plans` (`plan_id`,`bill_code_id`,`amount`,`frequency`,`first_billing_date`,`term_days`,`status`,`created_by`,`created_at`,`updated_at`,`disconnection_days`) VALUES
(1,9,'50.00','Monthly','2026-10-19',15,'active',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',0),
(2,10,'50.00','Monthly','2026-10-19',15,'active',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',0),
(3,11,'70.00','Monthly','2026-10-19',15,'active',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',0),
(4,12,'400.00','Monthly','2026-10-19',15,'active',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',0),
(5,3,'300.00','Monthly','2026-10-19',15,'active',1,'2026-10-19 00:49:44','2026-10-19 00:49:44',0);

-- water_billing_settings (21 rows)
INSERT INTO `water_billing_settings` (`setting_key`,`setting_value`,`description`,`created_at`,`updated_at`) VALUES
('accounting_asset_type','1','Legacy account type: asset','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('accounting_equity_type','3','Legacy account type: equity','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('accounting_expense_type','5','Legacy account type: expense','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('accounting_income_type','4','Legacy account type: income','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('accounting_liability_type','2','Legacy account type: liability','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_compromise_account_id','140','Compromise income account','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_max_backdate_days','31','How far back a collection may be dated','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_penalty_account_id','139','Penalty income account','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_penalty_grace_days','0','Grace days before a penalty','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_penalty_mode','none','Automatic penalty mode','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('collection_penalty_value','0','Automatic penalty value','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('manual_compromise_amount','0.00','Default compromise amount','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('manual_compromise_enabled','1','Cashier may enter a compromise','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('manual_penalty_amount','0.00','Default penalty amount','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('manual_penalty_enabled','1','Cashier may enter a penalty','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('recurring_scheduler_command','php process/runRecurringBilling.php --operator=OPERATOR_ID','Recurring billing command','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('recurring_scheduler_mode','cli','Recurring billing scheduler','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('session_inactivity_hours','24','Session inactivity limit of the water pages','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('water_billing_bill_code_id','1','Bill code used by Water Billing','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('water_billing_default_ar_account_id','135','Default water A/R account','2026-10-19 00:49:44','2026-10-19 00:49:44'),
('water_billing_default_income_account_id','137','Default water income account','2026-10-19 00:49:44','2026-10-19 00:49:44');

-- water_meter_discounts (1 rows)
INSERT INTO `water_meter_discounts` (`wmdiscount_id`,`wmd_name`,`free_water_m3`,`percent_discount`,`fixed_discount`,`active_discount`,`max_m3_for_discount`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'Employees',10,'0.0000','0.00','none',NULL,'system','2026-05-27 21:32:08','2026-05-27 21:32:08');

-- water_meter_members (52 rows)
INSERT INTO `water_meter_members` (`wmm_id`,`rc_id`,`pipe_size_id`,`bill_code_id`,`from_cb`,`to_cb`,`amount`,`bill_type`,`created_by`,`created_at`,`updated_at`) VALUES
(7,1,2,NULL,0,5,'144.5000','FIXED','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(8,1,2,NULL,6,10,'30.8000','VARIABLE','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(9,1,2,NULL,11,20,'33.3000','VARIABLE','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(10,1,2,NULL,21,30,'43.8000','VARIABLE','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(11,1,2,NULL,31,40,'49.1000','VARIABLE','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(12,1,2,NULL,41,NULL,'54.4000','VARIABLE','system','2026-07-31 15:41:40','2026-07-31 15:41:40'),
(13,2,2,NULL,0,5,'145.5000','FIXED','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(14,2,2,NULL,6,10,'31.8000','VARIABLE','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(15,2,2,NULL,11,20,'34.3000','VARIABLE','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(16,2,2,NULL,21,30,'44.8000','VARIABLE','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(17,2,2,NULL,31,40,'50.1000','VARIABLE','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(18,2,2,NULL,41,NULL,'55.4000','VARIABLE','system','2026-07-31 15:43:31','2026-07-31 15:43:31'),
(19,6,2,NULL,0,15,'831.0000','FIXED','system','2026-07-31 15:45:45','2026-07-31 15:45:45'),
(20,6,2,NULL,16,30,'69.2000','VARIABLE','system','2026-07-31 15:45:45','2026-07-31 15:45:45'),
(21,6,2,NULL,31,500,'83.0000','VARIABLE','system','2026-07-31 15:45:45','2026-07-31 15:45:45'),
(22,6,2,NULL,501,1000,'93.0000','VARIABLE','system','2026-07-31 15:45:45','2026-07-31 15:45:45'),
(23,6,2,NULL,1001,NULL,'103.0000','VARIABLE','system','2026-07-31 15:45:45','2026-07-31 15:45:45'),
(24,6,3,NULL,0,15,'1329.0000','FIXED','system','2026-07-31 15:47:44','2026-07-31 15:47:44'),
(25,6,3,NULL,16,30,'110.7000','VARIABLE','system','2026-07-31 15:47:44','2026-07-31 15:47:44'),
(26,6,3,NULL,31,500,'132.9000','VARIABLE','system','2026-07-31 15:47:44','2026-07-31 15:47:44'),
(27,6,3,NULL,501,1000,'142.9000','VARIABLE','system','2026-07-31 15:47:44','2026-07-31 15:47:44'),
(28,6,3,NULL,1001,NULL,'152.9000','VARIABLE','system','2026-07-31 15:47:44','2026-07-31 15:47:44'),
(29,6,4,NULL,0,15,'2658.8000','FIXED','system','2026-07-31 15:51:15','2026-07-31 15:51:15'),
(30,6,4,NULL,16,30,'221.4000','VARIABLE','system','2026-07-31 15:51:15','2026-07-31 15:51:15'),
(31,6,4,NULL,31,500,'265.7000','VARIABLE','system','2026-07-31 15:51:15','2026-07-31 15:51:15'),
(32,6,4,NULL,501,1000,'275.7000','VARIABLE','system','2026-07-31 15:51:15','2026-07-31 15:51:15'),
(33,6,4,NULL,1001,NULL,'285.7000','VARIABLE','system','2026-07-31 15:51:15','2026-07-31 15:51:15'),
(34,6,6,NULL,0,15,'6643.6000','FIXED','system','2026-07-31 16:11:02','2026-07-31 16:11:02'),
(35,6,6,NULL,16,30,'553.6000','VARIABLE','system','2026-07-31 16:11:02','2026-07-31 16:11:02'),
(36,6,6,NULL,31,500,'664.3000','VARIABLE','system','2026-07-31 16:11:02','2026-07-31 16:11:02'),
(37,6,6,NULL,501,1000,'674.3000','VARIABLE','system','2026-07-31 16:11:02','2026-07-31 16:11:02'),
(38,6,6,NULL,1001,NULL,'684.3000','VARIABLE','system','2026-07-31 16:11:02','2026-07-31 16:11:02'),
(39,5,2,NULL,0,15,'830.0000','FIXED','system','2026-07-31 16:41:20','2026-07-31 16:41:20'),
(40,5,2,NULL,16,30,'68.2000','VARIABLE','system','2026-07-31 16:41:20','2026-07-31 16:41:20'),
(41,5,2,NULL,31,500,'82.0000','VARIABLE','system','2026-07-31 16:41:20','2026-07-31 16:41:20'),
(42,5,2,NULL,501,1000,'92.0000','VARIABLE','system','2026-07-31 16:41:20','2026-07-31 16:41:20'),
(43,5,2,NULL,1001,NULL,'102.0000','VARIABLE','system','2026-07-31 16:41:20','2026-07-31 16:41:20'),
(49,5,3,NULL,0,15,'1328.7000','FIXED','system','2026-07-31 16:52:33','2026-07-31 16:52:33'),
(50,5,3,NULL,16,30,'109.7000','VARIABLE','system','2026-07-31 16:52:33','2026-07-31 16:52:33'),
(51,5,3,NULL,31,500,'131.9000','VARIABLE','system','2026-07-31 16:52:33','2026-07-31 16:52:33'),
(52,5,3,NULL,501,1000,'141.9000','VARIABLE','system','2026-07-31 16:52:33','2026-07-31 16:52:33'),
(53,5,3,NULL,1001,NULL,'151.9000','VARIABLE','system','2026-07-31 16:52:33','2026-07-31 16:52:33'),
(54,5,4,NULL,0,15,'2657.6000','FIXED','system','2026-07-31 16:53:53','2026-07-31 16:53:53'),
(55,5,4,NULL,16,30,'220.4000','VARIABLE','system','2026-07-31 16:53:53','2026-07-31 16:53:53'),
(56,5,4,NULL,31,500,'264.7000','VARIABLE','system','2026-07-31 16:53:53','2026-07-31 16:53:53'),
(57,5,4,NULL,501,1000,'274.7000','VARIABLE','system','2026-07-31 16:53:53','2026-07-31 16:53:53'),
(58,5,4,NULL,1001,NULL,'284.7000','VARIABLE','system','2026-07-31 16:53:53','2026-07-31 16:53:53'),
(64,5,6,NULL,0,15,'6643.6000','FIXED','system','2026-09-04 15:42:39','2026-09-04 15:42:39'),
(65,5,6,NULL,16,30,'553.6000','VARIABLE','system','2026-09-04 15:42:39','2026-09-04 15:42:39'),
(66,5,6,NULL,31,500,'664.3000','VARIABLE','system','2026-09-04 15:42:39','2026-09-04 15:42:39'),
(67,5,6,NULL,501,1000,'764.3000','VARIABLE','system','2026-09-04 15:42:39','2026-09-04 15:42:39'),
(68,5,6,NULL,1001,NULL,'864.3000','VARIABLE','system','2026-09-04 15:42:39','2026-09-04 15:42:39');

-- water_pipe_sizes (7 rows)
INSERT INTO `water_pipe_sizes` (`pipe_size_id`,`size_code`,`size_label`,`diameter_inches`,`is_active`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'UNSPECIFIED','Unspecified / Existing',NULL,1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00'),
(2,'1/2','1/2 inch','0.5000',1,'system','2026-07-27 17:09:00','2026-07-31 18:02:50'),
(3,'3/4','3/4 inch','0.7500',1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00'),
(4,'1','1 inch','1.0000',1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00'),
(5,'1-1/4','1-1/4 inches','1.2500',1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00'),
(6,'1-1/2','1-1/2 inches','1.5000',1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00'),
(7,'2','2 inches','2.0000',1,'system','2026-07-27 17:09:00','2026-07-27 17:09:00');

-- ---------------------------------------------------------------------
--  4. AUDIT TRAIL PROTECTION (created after the data is loaded)
-- ---------------------------------------------------------------------
--  Audit trail protection: entries can never be changed or deleted,
--  not even by administrators or directly in the database.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_trail_no_update;
DROP TRIGGER IF EXISTS trg_audit_trail_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_trail_no_update BEFORE UPDATE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are read-only and cannot be changed.';
END$$

CREATE TRIGGER trg_audit_trail_no_delete BEFORE DELETE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are permanent and cannot be deleted.';
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
