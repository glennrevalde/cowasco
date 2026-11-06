-- =====================================================================
--  G-SMaRT Accounting - migration: several companies, each with its own database
--
--  Run it on your CURRENT database, as with every earlier migration. That database
--  becomes Company 1; nothing in it is deleted, reset or moved.
--
--  What it changes
--    1. six new columns on the users table. Every existing user gets the values that
--       keep them exactly as they are: approved, created by an administrator.
--         users.approval_status  approved / pending / rejected: employees who join with
--                                the Company Code wait for the Company Owner's approval
--         users.created_via      admin / registration / provisioning / setup / platform
--         users.approved_by      who approved or rejected the registration, and when
--         users.approved_at
--         users.rejected_reason
--         users.is_platform      1 only for the account a Super Admin works through inside
--                                a company; it never appears in the company's user lists
--    2. a new "Company" menu with three pages, open to the same roles as User Accounts:
--         company.profile        Company Profile & Code (the Company Code employees join with)
--         company.users          Employees & Users (approve, reject, activate, roles, passwords)
--         company.join_requests  Join Requests (the registrations waiting for approval)
--       and one page for administrators:
--         admin.multi_company    Multi-Company Setup: registers this database as Company 1,
--                                creates the platform (master) database and the Super Admin
--
--  The master database itself is created by the system when you press "Set up" on the
--  Multi-Company Setup page, not by this file.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_28_multi_company.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The users columns (each added only when it is not there yet)
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN approval_status VARCHAR(10) NOT NULL DEFAULT ''approved'' AFTER status',
  'SELECT ''users: approval_status already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'approval_status');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN created_via VARCHAR(12) NOT NULL DEFAULT ''admin'' AFTER approval_status',
  'SELECT ''users: created_via already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'created_via');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN approved_by INT UNSIGNED NULL AFTER created_via',
  'SELECT ''users: approved_by already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'approved_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
  'SELECT ''users: approved_at already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'approved_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN rejected_reason VARCHAR(255) NULL AFTER approved_at',
  'SELECT ''users: rejected_reason already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'rejected_reason');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD COLUMN is_platform TINYINT(1) NOT NULL DEFAULT 0 AFTER rejected_reason',
  'SELECT ''users: is_platform already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_platform');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE users ADD KEY ix_users_approval (approval_status)',
  'SELECT ''users: ix_users_approval already exists''')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'ix_users_approval');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. The Company menu and its pages
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('company', 'Company', 'buildings', 10);

SET @mc_company_module = (SELECT id FROM modules WHERE code = 'company' LIMIT 1);
SET @mc_admin_module   = (SELECT id FROM modules WHERE code = 'admin' LIMIT 1);
SET @mc_sort_admin     = (SELECT COALESCE(MAX(sort_order), 900) FROM pages WHERE module_id = @mc_admin_module);

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('company.profile',       @mc_company_module, 'Company Profile & Code', 'admin', 'Company', 1000, 1),
  ('company.users',         @mc_company_module, 'Employees & Users',      'admin', 'Company', 1010, 1),
  ('company.join_requests', @mc_company_module, 'Join Requests',          'admin', 'Company', 1020, 1),
  ('admin.multi_company',   @mc_admin_module,   'Multi-Company Setup',    'admin', 'Setup',   @mc_sort_admin + 10, 1);

-- The company pages follow the access User Accounts already has, so the same people can use them.
-- (Administrators and the Company Owner can always open every page.)
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'admin.users'
  JOIN pages dst ON dst.page_key IN ('company.profile', 'company.users', 'company.join_requests');

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Company pages; multi-company', 'Migration 2026_09_28_multi_company: approval columns on users (every existing user approved), Company menu (Profile & Code, Employees & Users, Join Requests) with the access User Accounts has, and Multi-Company Setup');

-- Done. Next steps in the application:
--   1. Log in as an administrator and open System Administration > Multi-Company Setup.
--   2. Choose the Super Admin's username and password and the Company Owner of this company,
--      and press Set up. This database becomes Company 1 and gets its Company Code.
--   3. The Super Admin logs in on the same log-in page and manages every company from there.
