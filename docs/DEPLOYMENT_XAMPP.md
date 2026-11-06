# Installing, running and cutting over on XAMPP (Windows)

Everything below was run on this project's build machine with the same commands (Linux paths there); the results
are in `docs/TEST_EVIDENCE.md`. Commands are typed in a Command Prompt opened in `C:\xampp` unless a step says otherwise.

## 1. Requirements

| | |
|---|---|
| PHP | 8.1 or newer, with `pdo_mysql` and `mbstring` (on by default in XAMPP 8.x). The Excel downloads and the Excel (.xlsx) imports also need `zip`: in `C:\xampp\php\php.ini` remove the `;` before `extension=zip` and restart Apache. Without it the downloads come as CSV and the imports take CSV files. |
| Database | MySQL 5.5.3 or newer, or MariaDB 5.5 or newer. Every schema file and migration is written for MySQL 5.5 (no JSON columns, CTEs or window functions; one auto-timestamp per table; index keys within 767 bytes). Tested on MariaDB 10.11. |
| Web server | Apache from XAMPP (`.htaccess` files included) |
| Browser | any current browser; nothing is loaded from the internet (Bootstrap, icons, date picker and fonts are local) |

PHP and MySQL versions are separate: an old MySQL 5.5 server works, but the PHP that runs the program must be 8.1+.

## 2. A new installation (no former data)

1. Unzip the delivery so that you have `C:\xampp\htdocs\gsmart\index.php`.
2. Copy `config\config.example.php` to `config\config.php` (the delivered `config.php` already holds the XAMPP
   defaults: server `localhost`, user `root`, no password, database `cooperative_system`). Change the values when
   your server differs. No password is stored in the delivery.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Create the database, tables, seeded roles, default accounts and the water module:
   ```
   cd C:\xampp\htdocs\gsmart
   C:\xampp\php\php.exe tools\install.php
   ```
   (`--water-starter` adds the water billing set-up: water accounts and their module defaults, rate codes and tariff
   tiers, pipe sizes, discounts, eight bill codes, the water stockroom and materials; `--demo` adds sample
   transactions; `--water-demo` adds both and a sample water utility; `--drop` rebuilds a database that exists,
   deleting its data.)
   The alternative without a command line is phpMyAdmin > Import of `database\accounting_system_clean.sql` (the
   same as `install.php --water-starter`) or `database\accounting_system.sql` (sample data, the same as
   `--water-demo`). Each holds every table, the water tables and their two views included; nothing else is imported.
   For a cut-over from a former water database (section 3) use `install.php` without `--water-starter` instead:
   the migration brings the former rates, bill codes and accounts, and stops when the water lists already hold rows.
5. Open <http://localhost/gsmart/> and log in as **admin / Admin@12345**; you must choose a new password at once.
6. The former water program's export files (Account Master and Billing Master CSV) are no longer imported by the
   program: Data Import Management was removed in version 49. A former water database is brought in with the
   migration of section 3.
7. Set up before the first water bill (the program refuses to bill until these are done and says which is missing):
   Water Billing > Water Settings (institution name and report signatories, collection policy with the penalty and
   compromise income accounts, organization units), Financial > Module Default Accounts (Water group),
   Water Billing > Bill Codes (A/R and income account of each code; inventory codes linked to an item), a billing
   period, and on System Administration > User Accounts the cash account of each cashier.

Seeded roles: Administrator, Accountant, A/R Clerk, A/P Clerk, Inventory Clerk,
Sales Representative, Cashier, POS Supervisor, Auditor, General Manager, Bookkeeper, Loan Officer, Credit Committee,
Teller, Membership Officer, Collector, Branch Manager. Page rights per role are on Page Access Configuration; the water pages are listed under Water Billing.
Seeded users: `admin` and eight sample users of the sample company (`accountant`, `arclerk`, `apclerk`, `invclerk`,
`salesrep`, `cashier1`, `supervisor`, `auditor`, password `Demo@12345`); change or deactivate them before real use.
Seeded default accounts: the cooperative chart with its module defaults, plus the Water group (A/R, water sales,
penalty, compromise, discounts, default collections cash) to be pointed at the cooperative's own accounts.

## 3. Cut-over: the cooperative database in use + the former water database -> one new database

Names used below: `cooperative_system` = the cooperative database in use, `cowasco_water` = the former water
database, `gsmart` = the new integrated database. Both former databases are only read.

1. **Freeze** both programs (no more transactions) and note the cut-over date, e.g. 2026-10-31.
2. **Back up** both databases (keep the files off the server):
   ```
   mysql\bin\mysqldump -u root -p --routines --triggers --single-transaction cooperative_system > C:\backup\cooperative_system_before_cutover.sql
   mysql\bin\mysqldump -u root -p --routines --triggers --single-transaction cowasco_water      > C:\backup\cowasco_water_before_cutover.sql
   ```
3. **Make the target** from the cooperative database. It copies every table, view, routine and trigger, compares
   row counts and checksums of every table with the source, then runs the upgrade scripts the copy lacks (the water
   module's included):
   ```
   cd C:\xampp\htdocs\gsmart
   C:\xampp\php\php.exe tools\create_target.php --coop-source=cooperative_system --target=gsmart
   ```
   Its report is written to `storage\migration\create_target_gsmart_<time>.md`.
4. **Point the program at the target**: in `config\config.php` set `'name' => 'gsmart'` (or, for the commands of
   this window only, `set DB_NAME=gsmart`).
5. **Dry run** the water migration and read the counts, the verification table and the exceptions:
   ```
   C:\xampp\php\php.exe tools\migrate_water.php --source=cowasco_water --cutover=2026-10-31 --dry-run
   ```
   The dry run also writes `storage\water_migration\<run>-coa_crosswalk.csv`. If the accountant wants former water
   accounts mapped onto accounts the cooperative chart already has, fill its `map_to_code` column and pass the file
   with `--coa-map=` on the real run (on a fresh target: repeat step 3 with `--drop`). Settle what the exceptions ask
   in the former program where possible and dry-run again until the verification says PASS.
6. **Run** it:
   ```
   C:\xampp\php\php.exe tools\migrate_water.php --source=cowasco_water --cutover=2026-10-31
   ```
   A run that stops part-way is started again with the same command: what is already there is skipped row by row.
   Running it a second time writes nothing.
7. **Optional, only when the cooperative database does not already hold them**: the former database's own
   cooperative membership register and savings balances (see the exceptions list for the counts):
   ```
   C:\xampp\php\php.exe tools\migrate_water.php --source=cowasco_water --cutover=2026-10-31 --phase=membership --import-membership --savings-product=RS
   ```
8. **Verify** (also done at the end of every run; can be repeated):
   ```
   C:\xampp\php\php.exe tools\migrate_water.php --source=cowasco_water --cutover=2026-10-31 --phase=verify
   C:\xampp\php\php.exe tools\dev\delivery_evidence.php reconciliation --source=cowasco_water > C:\backup\reconciliation_report.md
   ```
9. **After the run**, in the program: decide the Identity Review pairs (Parties > Identity Review: the same person
   holding several water connections, or a water customer who is also a member, becomes one party); correct the bill
   codes the mapping check lists; set the water penalty and compromise income accounts; give the roles without pages
   their pages; tell the migrated users to log in (they must choose a new password).
10. **Back up** the new database and keep the former databases read-only for reference (their accounting,
    inventory, access and savings history is also inside `gsmart` as `legacy_*` tables).

### Backup and rollback

* **Before go-live**, a failed rehearsal is undone with `tools\rollback_water.php` (dry run first:
  `--dry-run`); it removes exactly what the run wrote and refuses when anything was posted afterwards. The target of
  step 3 can also simply be dropped and made again (`--drop`).
* **After go-live** the way back is the backup of step 2 (the former databases were never written to) or the
  program's own backups: System Administration > Backup & Restore makes a `.sql` file of every table and restores it
  (the water views and audit-trail triggers are put back by the restore). A backup imported with phpMyAdmin instead
  holds tables only; at the next login the program names `2026_12_01_water_billing.sql` as pending, and running it
  again (`php tools\upgrade.php`, or the file in phpMyAdmin) re-creates the views without changing any data.

## 4. Upgrading an installation of the cooperative system in place

Back up the database, copy the program files over the old ones and run the pending upgrade scripts of
`database\migrations` oldest first: `C:\xampp\php\php.exe tools\upgrade.php` runs exactly the ones still missing
(the page shown after login lists them too, for phpMyAdmin). For this version that is `2026_12_01_water_billing.sql`, then `2026_12_20_member_lists_class_je_changes.sql`, then `2026_12_27_member_defaults_savings_center.sql`, then `2027_01_03_loan_collection_error_correct.sql`, then `2027_01_10_teller_menu_loan_release_vouchers.sql`, then `2027_01_17_passbook_designer.sql`, then `2027_01_24_account_types.sql`, then `2027_01_31_adjustment_passbook.sql`, then `2027_02_21_menus_imports_codes.sql`, then `2027_03_07_water_ladder_billing.sql`, then `2027_03_14_share_capital_billing.sql`, then `2027_03_21_share_capital_terms_batch.sql`, then `2027_03_28_regular_joint_subgroups_receipts.sql`.
It is safe to run again. With several companies, the platform's System Updates page runs it on every company. Then follow section 3 from step 5 to bring in a water
database, or leave the water module empty.

## 5. Running the tests on the machine

```
C:\xampp\php\php.exe tests\coop\run.php                       (cooperative cases; builds and drops coop_test)
bash tests/water/run_e2e.sh cowasco_water                      (HTTP end-to-end; needs a Unix shell, e.g. Git Bash; builds coop_water_e2e_test)
C:\xampp\php\php.exe tests\water\backup_restore_test.php       (with DB_NAME of a test database)
```
The tests build their own databases and never touch the cooperative or water databases.
