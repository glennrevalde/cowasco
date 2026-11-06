-- =====================================================================
--  Accounting System - migration: fixed asset maintenance, asset locations,
--  supplier debit memos, company logo
--
--  What it changes
--    1. new tables   asset_locations            (Location list of the fixed assets)
--                    asset_maintenance          (repair and maintenance records)
--                    asset_maintenance_parts    (spare parts: part no., serial, model, size ...)
--                    asset_maintenance_labor    (man-hours or bulk labor charges)
--                    asset_maintenance_photos   (pictures of the materials / parts)
--                    asset_pm_schedules         (preventive maintenance tasks and their due dates)
--                    asset_pm_log               (completed preventive maintenance)
--    2. new columns  fixed_assets: location_id, class_id (default class), current_meter, meter_unit
--       Locations already typed on the assets are turned into the Location list.
--    3. number sequences MNT-00001 (maintenance records) and DM-00001 (supplier debit memos)
--    4. company logo settings (System Administration > Company, Security & Closing Date)
--    5. new pages and their access for the standard roles:
--       Supplier Debit Memos, Asset Maintenance, Preventive Maintenance, Asset Locations
--       and six maintenance reports
--
--  For an EXISTING database that already has 2026_09_19_classes_cash_voucher.sql.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_21_asset_maintenance_debit_memo_logo.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed_assets.location_id
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE fixed_assets
     ADD COLUMN location_id INT UNSIGNED NULL AFTER location,
     ADD KEY ix_fa_location (location_id),
     ADD CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id)',
  'SELECT ''fixed_assets: location_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND column_name = 'location_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- fixed_assets.class_id (default class: used by the depreciation entry when no class is chosen)
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE fixed_assets
     ADD COLUMN class_id INT UNSIGNED NULL AFTER site_id,
     ADD KEY ix_fa_class (class_id),
     ADD CONSTRAINT fk_fa_class FOREIGN KEY (class_id) REFERENCES classes(id)',
  'SELECT ''fixed_assets: class_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND column_name = 'class_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- fixed_assets.current_meter / meter_unit (odometer or running hours, for maintenance that is due by usage)
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE fixed_assets
     ADD COLUMN current_meter DECIMAL(15,2) NULL,
     ADD COLUMN meter_unit VARCHAR(20) NULL',
  'SELECT ''fixed_assets: current_meter already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND column_name = 'current_meter');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- the locations already typed on the assets become the Location list
INSERT IGNORE INTO asset_locations (name)
SELECT DISTINCT TRIM(location) FROM fixed_assets WHERE location IS NOT NULL AND TRIM(location) <> '';

UPDATE fixed_assets fa JOIN asset_locations al ON al.name = TRIM(fa.location)
   SET fa.location_id = al.id
 WHERE fa.location_id IS NULL AND fa.location IS NOT NULL AND TRIM(fa.location) <> '';

CREATE TABLE IF NOT EXISTS asset_pm_schedules (
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

CREATE TABLE IF NOT EXISTS asset_maintenance (
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

CREATE TABLE IF NOT EXISTS asset_maintenance_parts (
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

CREATE TABLE IF NOT EXISTS asset_maintenance_labor (
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

CREATE TABLE IF NOT EXISTS asset_maintenance_photos (
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

CREATE TABLE IF NOT EXISTS asset_pm_log (
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
--  2. Number sequences
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('maintenance', 'MNT-', 1, 5);
INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('debit_memo', 'DM-', 1, 5);

-- ---------------------------------------------------------------------
--  3. Settings: company logo
--     company_logo = file name inside storage/company/ (empty = no logo)
--     logo_targets = where the logo is printed, one key per line (chosen on the settings page)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('company_logo', '');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('logo_targets', '');

-- ---------------------------------------------------------------------
--  4. Pages (menu and Page Access Configuration)
-- ---------------------------------------------------------------------

SET @acct_fa_module   = (SELECT id FROM modules WHERE code = 'fa' LIMIT 1);
SET @acct_vend_module = (SELECT id FROM modules WHERE code = 'vend' LIMIT 1);
SET @acct_sort_disp   = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'fa.disposal');
SET @acct_sort_cat    = (SELECT COALESCE(MAX(sort_order), 310) FROM pages WHERE page_key = 'fa.categories');
SET @acct_sort_farpt  = (SELECT COALESCE(MAX(sort_order), 350) FROM pages WHERE page_key = 'rpt.fa_additions');
SET @acct_sort_bills  = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key = 'vend.bills');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('vend.debit_memos',     @acct_vend_module, 'Supplier Debit Memos',            'transaction', 'Transactions', @acct_sort_bills + 1, 1),
  ('fa.maintenance',       @acct_fa_module,   'Asset Maintenance',               'transaction', 'Transactions', @acct_sort_disp + 1,  1),
  ('fa.pm',                @acct_fa_module,   'Preventive Maintenance',          'transaction', 'Transactions', @acct_sort_disp + 2,  1),
  ('fa.locations',         @acct_fa_module,   'Asset Locations',                 'setup',       'Setup',        @acct_sort_cat + 1,   1),
  ('rpt.fa_maint_history', @acct_fa_module,   'Maintenance History',             'report',      'Reports',      @acct_sort_farpt + 1, 1),
  ('rpt.fa_maint_cost',    @acct_fa_module,   'Maintenance Cost by Asset',       'report',      'Reports',      @acct_sort_farpt + 2, 1),
  ('rpt.fa_maint_parts',   @acct_fa_module,   'Spare Parts Used',                'report',      'Reports',      @acct_sort_farpt + 3, 1),
  ('rpt.fa_maint_labor',   @acct_fa_module,   'Maintenance Labor',               'report',      'Reports',      @acct_sort_farpt + 4, 1),
  ('rpt.fa_pm_upcoming',   @acct_fa_module,   'Upcoming Preventive Maintenance', 'report',      'Reports',      @acct_sort_farpt + 5, 1),
  ('rpt.fa_pm_log',        @acct_fa_module,   'Completed Preventive Maintenance','report',      'Reports',      @acct_sort_farpt + 6, 1);

-- ---------------------------------------------------------------------
--  5. Page access
--     Administrators can open every page already.
--     Supplier Debit Memos follow Enter Bills; Asset Maintenance and Preventive Maintenance follow
--     the Fixed Asset Register; Asset Locations follows Asset Categories; the reports follow the
--     Fixed Asset Register Report (your own roles are copied the same way).
-- ---------------------------------------------------------------------

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'vend.bills'
  JOIN pages dst ON dst.page_key = 'vend.debit_memos';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'fa.register'
  JOIN pages dst ON dst.page_key IN ('fa.maintenance', 'fa.pm');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'fa.categories'
  JOIN pages dst ON dst.page_key = 'fa.locations';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.fa_register'
  JOIN pages dst ON dst.page_key IN ('rpt.fa_maint_history', 'rpt.fa_maint_cost', 'rpt.fa_maint_parts', 'rpt.fa_maint_labor', 'rpt.fa_pm_upcoming', 'rpt.fa_pm_log');

-- Record the change in the audit trail
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'page_access_change', 'pages',
        'Asset maintenance and debit memos', 'Migration 2026_09_21_asset_maintenance_debit_memo_logo: asset maintenance, preventive maintenance, asset locations, supplier debit memos and company logo added');

-- Done. Next steps in the application:
--   1. Fixed Assets > Asset Locations: review the Location list made from your assets.
--   2. Fixed Assets > Fixed Asset Register: set the default class of each asset (optional).
--   3. Fixed Assets > Preventive Maintenance: add the tasks to be reminded of (registration renewal, change oil, cleaning ...).
--   4. System Administration > Company, Security & Closing Date: upload the company logo and choose where it prints.
