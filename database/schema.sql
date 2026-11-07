-- =====================================================================
--  G-SMaRT Integrated Cooperative System - database schema
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
