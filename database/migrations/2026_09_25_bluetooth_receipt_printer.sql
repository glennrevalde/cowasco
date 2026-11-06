-- =====================================================================
--  Accounting System - migration: Bluetooth receipt printer
--
--  What it changes
--    1. six new settings, all switched off or left at a sensible value, so nothing
--       about the way receipts print today changes until they are turned on:
--         bt_printer_enabled    offer a Bluetooth printer on every receipt   (0 = no)
--         bt_printer_width      characters per line: 32 = 58 mm, 48 = 80 mm  (32)
--         bt_printer_name       only offer printers whose name starts with   (blank = all)
--         bt_printer_feed       blank lines fed after each receipt           (4)
--         bt_printer_cut        cut the paper at the end                     (0 = no)
--         bt_printer_autoprint  print the moment a receipt opens             (0 = no)
--
--  No table, column or index is added, changed or dropped, and no existing row is
--  touched. Nothing is deleted.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_25_bluetooth_receipt_printer.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Settings
--     INSERT IGNORE, so a setting already changed by hand keeps its value.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_enabled', '0');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_width', '32');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_name', '');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_feed', '4');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_cut', '0');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bt_printer_autoprint', '0');

-- ---------------------------------------------------------------------
--  2. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.settings', 'settings_change', 'settings',
        'Bluetooth receipt printer', 'Migration 2026_09_25_bluetooth_receipt_printer: receipts can be sent from the browser to a Bluetooth roll printer');

-- Done. Next steps in the application:
--   1. System Administration > Company, Security & Closing Date > Bluetooth receipt printer:
--      tick "Offer a Bluetooth printer on every receipt" and choose the paper width
--      (32 characters for a 58 mm roll, which is what the Officom OC-PT210 takes).
--   2. Switch the printer on, then use "Test print" on that same page to pair it and
--      check the paper width.
--   3. From then on every receipt has a "Bluetooth printer" button beside "Print".
--
--   The browser does the talking, so it has to be Chrome, Edge, Opera or Samsung Internet
--   on Android (or Chrome / Edge on a computer), and the system has to be opened on an
--   https:// address or on http://localhost/. On a plain http:// network address the
--   browser blocks Bluetooth, and the receipt page says so.
