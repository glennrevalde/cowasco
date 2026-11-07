-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 54
--
--  The report Sales by Customer Detail Group by Customer/Member Type (Customers > Sales Reports), given
--  to every role that opens Sales by Customer Detail, with the same Print right.
--
--  The other changes of version 54 need nothing in the database: the Member type filter of the sales
--  and receivables reports, and the Loan Due panel of Water Billing > Collections (Cashier).
--
--  For an EXISTING database of version 53 (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_04_11_sales_by_member_type.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @m_cust = COALESCE((SELECT module_id FROM pages WHERE page_key = 'rpt.sales_customer_detail' LIMIT 1), (SELECT id FROM modules WHERE code = 'cust' LIMIT 1));
SET @s_scd  = (SELECT COALESCE(MAX(sort_order), 750) FROM pages WHERE page_key = 'rpt.sales_customer_detail');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.sales_customer_type_detail', @m_cust, 'Sales by Customer Detail Group by Customer/Member Type', 'report', 'Sales Reports', @s_scd + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_customer_detail'
  JOIN pages dst ON dst.page_key = 'rpt.sales_customer_type_detail'
 WHERE src.allowed = 1;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Sales by Customer Detail Group by Customer/Member Type',
        'Migration 2027_04_11: the report page, given to the roles that open Sales by Customer Detail');
