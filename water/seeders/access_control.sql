/*
  FIXED Detailed Access Control for WaterBillingSystem

  Run this file to create/repair all access-control tables and seed ALL current pages.
  This version matches your current navbar and current views folder.
*/

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `access_control_pages` (
  `page_key` varchar(100) NOT NULL,
  `file_name` varchar(150) NOT NULL,
  `label` varchar(150) NOT NULL,
  `module_group` varchar(80) NOT NULL,
  `description` varchar(255) NULL DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`page_key`),
  UNIQUE KEY `uq_access_control_pages_file` (`file_name`),
  KEY `idx_access_control_pages_group` (`module_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `access_control_presets` (
  `preset_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `description` varchar(255) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`preset_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `access_control_preset_pages` (
  `preset_key` varchar(50) NOT NULL,
  `page_key` varchar(100) NOT NULL,
  PRIMARY KEY (`preset_key`, `page_key`),
  KEY `idx_access_control_preset_pages_page` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `user_page_permissions` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `page_key` varchar(100) NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`, `page_key`),
  KEY `idx_user_page_permissions_page` (`page_key`),
  KEY `idx_user_page_permissions_granted_by` (`granted_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `access_control_presets`
(`preset_key`, `label`, `description`, `created_at`, `updated_at`)
VALUES
('user', 'User', 'Limited operational staff access.', NOW(), NOW()),
('admin', 'Admin', 'Operational administrator access, excluding detailed access control.', NOW(), NOW()),
('superadmin', 'Superadmin', 'Full system access.', NOW(), NOW())
ON DUPLICATE KEY UPDATE
`label` = VALUES(`label`),
`description` = VALUES(`description`),
`updated_at` = NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('dashboard', 'dashboard.php', 'Dashboard', 'Main', 'Main dashboard / overview', 10, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('billing_management', 'billing_management.php', 'Billing Management', 'Billing', 'Billing dashboard.', 100, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('bill_period', 'bill_period.php', 'Bill Period Management', 'Billing', 'Create and manage billing periods.', 110, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('bill_codes', 'bill_codes.php', 'Bill Codes', 'Billing', 'Manage bill/charge codes.', 120, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('reading_entry', 'reading_entry.php', 'Reading Entry', 'Billing', 'Enter water meter readings.', 130, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('reading_history', 'reading_history.php', 'Reading History', 'Billing', 'View reading history.', 140, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('billing_history', 'billing_history.php', 'Billing History', 'Billing', 'View billing history.', 150, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('generate_soa', 'generate_soa.php', 'Generate SOA / Post Billing', 'Billing', 'Generate statement of account and post bills.', 160, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('update_billing', 'update_billing.php', 'Update Billing', 'Billing', 'Update existing billing information.', 170, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('recurring_bill', 'recurring_bill.php', 'Recurring Billing', 'Billing', 'Manage recurring bills.', 180, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('installment_bill', 'installment_bill.php', 'Installment Billing', 'Billing', 'Manage installment bills.', 190, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('one_time_billing', 'one_time_billing.php', 'One-Time Billing', 'Billing', 'Manage one-time bills.', 200, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('member_list', 'member_list.php', 'Member List', 'Members', 'View and manage members/customers.', 300, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('collection_management', 'collection_management.php', 'Collection Management', 'Collection', 'Collection dashboard.', 400, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('water_bills_collection', 'water_bills_collection.php', 'Water Bills Collection', 'Collection', 'Collect water bill payments.', 410, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('other_payments_collection', 'other_payments_collection.php', 'Other Payments Collection', 'Collection', 'Collect non-water-bill payments.', 420, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('other_payments', 'other_payments.php', 'Other Payments', 'Collection', 'Manage/view other payments.', 430, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('payment_history', 'payment_history.php', 'Payment History', 'Collection', 'View payment history.', 440, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('discount_management', 'discount_management.php', 'Discount Management', 'Rates & Discounts', 'Discount dashboard.', 500, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('discounts', 'discounts.php', 'Discounts', 'Rates & Discounts', 'Manage discounts.', 510, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('discounted_members', 'discounted_members.php', 'Discounted Members', 'Rates & Discounts', 'Manage discounted members.', 520, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('water_rate_discounts', 'water_rate_discounts.php', 'Water Rate Discounts', 'Rates & Discounts', 'Manage water rate discounts.', 530, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('rates_management', 'rates_management.php', 'Rates Management', 'Rates & Discounts', 'Rates dashboard.', 540, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('water_rates', 'water_rates.php', 'Water Rates', 'Rates & Discounts', 'Manage water rates.', 550, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('water_meter', 'water_meter.php', 'Water Meter', 'Rates & Discounts', 'Manage water meter records.', 560, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('rate_codes', 'rate_codes.php', 'Rate Codes', 'Rates & Discounts', 'Manage rate codes.', 570, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('inventory_management', 'inventory_management.php', 'Inventory Management', 'Inventory', 'Inventory dashboard.', 600, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('inventory_purchase', 'inventory_purchase.php', 'Inventory Purchase', 'Inventory', 'Record inventory purchases.', 610, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('inventory_adjustment', 'inventory_adjustment.php', 'Inventory Adjustment', 'Inventory', 'Adjust inventory balances.', 620, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('inventory_items', 'inventory_items.php', 'Inventory Items', 'Inventory', 'Manage inventory item masterlist.', 630, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('inventory_history', 'inventory_history.php', 'Inventory History', 'Inventory', 'View inventory history.', 640, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('accounting_management', 'accounting_management.php', 'Accounting', 'Accounting', 'Accounting dashboard.', 700, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('journal_entry', 'journal_entry.php', 'Journal Entry', 'Accounting', 'Manual journal entry.', 710, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('subsidiary_ledger_report', 'subsidiary_ledger_report.php', 'Subsidiary Ledger Report', 'Accounting', 'View subsidiary ledger movements.', 720, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('accounts_payable', 'accounts_payable.php', 'Accounts Payable', 'Accounting', 'Track and pay accounts payable.', 730, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('chart_of_accounts', 'chart_of_accounts.php', 'Chart of Accounts', 'Accounting', 'View/manage chart of accounts.', 740, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('general_ledger', 'general_ledger.php', 'General Ledger', 'Accounting', 'View general ledger.', 750, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('trial_balance', 'trial_balance.php', 'Trial Balance', 'Accounting', 'View trial balance.', 760, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('balance_sheet', 'balance_sheet.php', 'Balance Sheet', 'Accounting', 'View balance sheet.', 770, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('income_statement', 'income_statement.php', 'Income Statement', 'Accounting', 'View income statement.', 780, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('reports_management', 'reports_management.php', 'Reports', 'Reports', 'Reports dashboard.', 800, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('organization_units', 'organization_units.php', 'Organization Units', 'System', 'Manage branches, divisions, and segments.', 900, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('branches', 'branches.php', 'Branches', 'System', 'Branch setup page.', 901, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('divisions', 'divisions.php', 'Divisions', 'System', 'Division setup page.', 902, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('segments', 'segments.php', 'Segments', 'System', 'Segment setup page.', 903, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('settings', 'settings.php', 'Settings', 'System', 'System/user settings.', 910, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('access_control', 'access_control.php', 'Access Control', 'System', 'Detailed page-level access control.', 920, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('profile', 'profile.php', 'Profile', 'System', 'User profile.', 930, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('search', 'search.php', 'Search', 'System', 'Global search.', 940, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

INSERT INTO `access_control_pages` (`page_key`, `file_name`, `label`, `module_group`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES ('notifications', 'notifications.php', 'Notifications', 'System', 'Notifications.', 950, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE `file_name`=VALUES(`file_name`), `label`=VALUES(`label`), `module_group`=VALUES(`module_group`), `description`=VALUES(`description`), `sort_order`=VALUES(`sort_order`), `is_active`=1, `updated_at`=NOW();

DELETE FROM `access_control_preset_pages`;

INSERT INTO `access_control_preset_pages` (`preset_key`, `page_key`) VALUES
('user', 'dashboard'),
('user', 'member_list'),
('user', 'billing_management'),
('user', 'bill_period'),
('user', 'reading_entry'),
('user', 'reading_history'),
('user', 'billing_history'),
('user', 'collection_management'),
('user', 'water_bills_collection'),
('user', 'other_payments_collection'),
('user', 'payment_history'),
('user', 'rates_management'),
('user', 'rate_codes'),
('user', 'water_meter'),
('user', 'discount_management'),
('user', 'discounts'),
('user', 'discounted_members'),
('user', 'water_rate_discounts'),
('user', 'inventory_management'),
('user', 'inventory_items'),
('user', 'inventory_history'),
('user', 'reports_management'),
('user', 'profile'),
('user', 'search'),
('user', 'notifications');

INSERT INTO `access_control_preset_pages` (`preset_key`, `page_key`) VALUES
('admin', 'dashboard'),
('admin', 'billing_management'),
('admin', 'bill_period'),
('admin', 'bill_codes'),
('admin', 'reading_entry'),
('admin', 'reading_history'),
('admin', 'billing_history'),
('admin', 'generate_soa'),
('admin', 'update_billing'),
('admin', 'recurring_bill'),
('admin', 'installment_bill'),
('admin', 'one_time_billing'),
('admin', 'member_list'),
('admin', 'collection_management'),
('admin', 'water_bills_collection'),
('admin', 'other_payments_collection'),
('admin', 'other_payments'),
('admin', 'payment_history'),
('admin', 'discount_management'),
('admin', 'discounts'),
('admin', 'discounted_members'),
('admin', 'water_rate_discounts'),
('admin', 'rates_management'),
('admin', 'water_rates'),
('admin', 'water_meter'),
('admin', 'rate_codes'),
('admin', 'inventory_management'),
('admin', 'inventory_purchase'),
('admin', 'inventory_adjustment'),
('admin', 'inventory_items'),
('admin', 'inventory_history'),
('admin', 'accounting_management'),
('admin', 'journal_entry'),
('admin', 'subsidiary_ledger_report'),
('admin', 'accounts_payable'),
('admin', 'chart_of_accounts'),
('admin', 'general_ledger'),
('admin', 'trial_balance'),
('admin', 'balance_sheet'),
('admin', 'income_statement'),
('admin', 'reports_management'),
('admin', 'organization_units'),
('admin', 'branches'),
('admin', 'divisions'),
('admin', 'segments'),
('admin', 'settings'),
('admin', 'profile'),
('admin', 'search'),
('admin', 'notifications');

INSERT INTO `access_control_preset_pages` (`preset_key`, `page_key`) VALUES
('superadmin', 'dashboard'),
('superadmin', 'billing_management'),
('superadmin', 'bill_period'),
('superadmin', 'bill_codes'),
('superadmin', 'reading_entry'),
('superadmin', 'reading_history'),
('superadmin', 'billing_history'),
('superadmin', 'generate_soa'),
('superadmin', 'update_billing'),
('superadmin', 'recurring_bill'),
('superadmin', 'installment_bill'),
('superadmin', 'one_time_billing'),
('superadmin', 'member_list'),
('superadmin', 'collection_management'),
('superadmin', 'water_bills_collection'),
('superadmin', 'other_payments_collection'),
('superadmin', 'other_payments'),
('superadmin', 'payment_history'),
('superadmin', 'discount_management'),
('superadmin', 'discounts'),
('superadmin', 'discounted_members'),
('superadmin', 'water_rate_discounts'),
('superadmin', 'rates_management'),
('superadmin', 'water_rates'),
('superadmin', 'water_meter'),
('superadmin', 'rate_codes'),
('superadmin', 'inventory_management'),
('superadmin', 'inventory_purchase'),
('superadmin', 'inventory_adjustment'),
('superadmin', 'inventory_items'),
('superadmin', 'inventory_history'),
('superadmin', 'accounting_management'),
('superadmin', 'journal_entry'),
('superadmin', 'subsidiary_ledger_report'),
('superadmin', 'accounts_payable'),
('superadmin', 'chart_of_accounts'),
('superadmin', 'general_ledger'),
('superadmin', 'trial_balance'),
('superadmin', 'balance_sheet'),
('superadmin', 'income_statement'),
('superadmin', 'reports_management'),
('superadmin', 'organization_units'),
('superadmin', 'branches'),
('superadmin', 'divisions'),
('superadmin', 'segments'),
('superadmin', 'settings'),
('superadmin', 'access_control'),
('superadmin', 'profile'),
('superadmin', 'search'),
('superadmin', 'notifications');

/* Keep superadmins fully visible in the UI. */
INSERT INTO `user_page_permissions`
(`user_id`, `page_key`, `can_access`, `granted_by`, `created_at`, `updated_at`)
SELECT u.id, p.page_key, 1, u.id, NOW(), NOW()
FROM users u
CROSS JOIN access_control_pages p
WHERE u.role = 'superadmin'
ON DUPLICATE KEY UPDATE
can_access = 1,
updated_at = NOW();

SET FOREIGN_KEY_CHECKS = 1;
