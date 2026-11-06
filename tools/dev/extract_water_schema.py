#!/usr/bin/env python3
"""
One-off generator (kept for transparency): reads the water-billing SQL dump and writes the table definitions
part of database/migrations/2026_12_01_water_billing.sql. Data is never copied here; tools/migrate_water.php does that.

  python3 tools/dev/extract_water_schema.py /path/to/mnssmc_test.sql > /tmp/water_tables.sql
"""
import re, sys

RENAME = {
    'members': 'water_accounts',                 # a water-service account holder, linked to the Party Master
    # legacy accounting and access tables: kept read-only under legacy_ (history and audit evidence)
    'chartofaccntstbl': 'legacy_chartofaccntstbl', 'journalentries_copy1': 'legacy_journalentries',
    'journalvoucher_copy1': 'legacy_journalvoucher', 'journal_voucher_voids': 'legacy_journal_voucher_voids',
    'subsidiaryledger': 'legacy_subsidiaryledger', 'postingmethod': 'legacy_postingmethod', 'book_types': 'legacy_book_types',
    'accounts_payable': 'legacy_accounts_payable', 'accounts_payable_payments': 'legacy_accounts_payable_payments',
    'inventory_purchases': 'legacy_inventory_purchases', 'inventory_purchase_items': 'legacy_inventory_purchase_items',
    'inventory_stock_movements': 'legacy_inventory_stock_movements', 'inventory_adjustments': 'legacy_inventory_adjustments',
    'inventory_reorder_levels': 'legacy_inventory_reorder_levels', 'suppliers': 'legacy_suppliers',
    'operator': 'legacy_operator', 'operator_profile': 'legacy_operator_profile', 'profile': 'legacy_profile',
    'operator_profile_page_permissions': 'legacy_operator_profile_page_permissions', 'access_control_pages': 'legacy_access_control_pages',
    'access_control_presets': 'legacy_access_control_presets', 'access_control_preset_pages': 'legacy_access_control_preset_pages',
    'audit_logs': 'water_audit_logs',
}
DROP = {  # never created in the target: unused or replaced outright
    'users', 'user_profiles', 'user_profile_assignments', 'user_sessions', 'session', 'password_resets',
    'operator_password_reset_requests', 'water_meter_members_backup_fix14',
    'savaccnttransaction', 'savingsaccount_param', 'savingsaccounts', 'savingsinterest', 'savingsrestrictions', 'savingstranscode',
    'savrestrictiontype', 'savings',
}
# FKs to tables that no longer exist in the target are dropped; the columns stay (they hold migrated ids)
FK_DROP_REF = {'operator', 'chartofaccntstbl', 'users', 'suppliers', 'inventory_purchases', 'accounts_payable',
               'access_control_pages', 'operator_profile', 'profile', 'membershipinfo', 'branches', 'savings', 'savingsaccounts',
               'savingstranscode', 'savingsaccount_param', 'postingmethod', 'savrestrictiontype'}

sql = open(sys.argv[1], encoding='utf-8', errors='ignore').read()
stmts = re.findall(r"(CREATE TABLE `(\w+)`\s*\((.*?)\)\s*(ENGINE[^;]*);)", sql, re.S)
out = []
for whole, name, body, tail in stmts:
    if name in DROP:
        continue
    new = RENAME.get(name, name)
    lines = [l.rstrip() for l in body.split('\n') if l.strip()]
    kept = []
    for l in lines:
        m = re.search(r"CONSTRAINT `(\w+)` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)`", l)
        if m:
            ref = m.group(3)
            if ref in FK_DROP_REF or ref in DROP:
                continue
            if ref in RENAME:
                l = l.replace('REFERENCES `%s`' % ref, 'REFERENCES `%s`' % RENAME[ref])
        kept.append(l)
    # a dangling comma on the last line
    kept[-1] = kept[-1].rstrip(',')
    tail = re.sub(r"AUTO_INCREMENT\s*=\s*\d+\s*", '', tail)
    tail = re.sub(r"ROW_FORMAT\s*=\s*\w+", '', tail).strip()
    out.append("CREATE TABLE IF NOT EXISTS `%s` (\n%s\n) %s;\n" % (new, '\n'.join(kept), tail))
print('\n'.join(out))
