-- =====================================================================
--  Legacy water transactions for a phase-7 rehearsal of tools/migrate_water.php
--
--  Loaded into a COPY of the former water database (mnssmc_fixture, made from mnssmc_test by
--  tools/dev/water_legacy_fixture.php), never into the real dump. MySQL 5.5 compatible.
--
--  Three posted water bills (one partly paid, one unpaid, one fully paid), one unpaid one-time bill, two
--  official receipts with their items, and the balanced legacy journal of each (the former program's convention:
--  a bill = Dr 11784 Accounts Receivable - Water / Cr 40210 Water Bill Revenue; a collection = Dr 11118 Cash in
--  Vault / Cr 11784).
--
--  Expected at cutover 2026-08-31:
--     open bills        : reading 9001 300.00 + reading 9002 350.00 + one-time 9001 50.00 = 700.00
--     legacy A/R (11784): 500 + 350 + 420 + 50 - 200 - 420 = 700.00   (= the open bills)
--     legacy TB         : 11784 Dr 700.00, 11118 Dr 620.00, 40210 Cr 1,320.00
--     opening journal   : Dr 11118 620.00, Dr 39999 clearing 700.00, Cr 40210 1,320.00  (A/R line 0 = 700 - 700)
--     clearing 39999    : Cr 700.00 (invoices) + Dr 700.00 (journal) = 0.00
-- =====================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';

-- global reference numbers of the documents
INSERT INTO global_billing_sequence (ref_number, created_at) VALUES (1001, '2026-08-05 08:00:00'), (1002, '2026-08-05 08:01:00'), (1003, '2026-08-05 08:02:00'), (1004, '2026-08-10 09:00:00');

-- posted water bills of period 3 (080126_083126)
INSERT INTO bill_readings (reading_id, global_ref_number, period_id, member_id, prev_reading, pres_reading, consumption, is_billed, billing_date, due_date, term_days,
                           amount_due, amount_paid, penalty_amount, billing_journal_voucher_id, branch_id, segment_id, division_id, is_paid, bill_lifecycle_status,
                           entry_mode, encoded_by, created_at, updated_at)
VALUES
  (9001, 1001, 3, 114842, 100, 125, 25, 1, '2026-08-05', '2026-08-20', 15, 500.00, 200.00, 0.00, 'JV-FX-0001', 1, 1, 1, 0, 'active', 'INDIVIDUAL', 'Glen', '2026-08-05 08:00:00', '2026-08-15 10:00:00'),
  (9002, 1002, 3, 114843, 200, 217, 17, 1, '2026-08-05', '2026-08-20', 15, 350.00,   0.00, 0.00, 'JV-FX-0002', 1, 1, 1, 0, 'active', 'INDIVIDUAL', 'Glen', '2026-08-05 08:01:00', '2026-08-05 08:01:00'),
  (9003, 1003, 3, 114844, 300, 321, 21, 1, '2026-08-05', '2026-08-20', 15, 420.00, 420.00, 0.00, 'JV-FX-0003', 1, 1, 1, 1, 'active', 'INDIVIDUAL', 'Glen', '2026-08-05 08:02:00', '2026-08-16 10:00:00');

-- an unpaid one-time bill (bill code 1 WATER BILL: Dr 11784 / Cr 40210)
INSERT INTO one_time_bills (bill_id, global_ref_number, member_id, bill_code_id, quantity, bill_date, due_date, term_days, total_amount, gross_amount, discount_amount,
                            amount_paid, penalty_amount, status, bill_lifecycle_status, created_by, created_at, branch_id, segment_id, division_id)
VALUES (9001, 1004, 114842, 1, 1.00, '2026-08-10', '2026-08-25', 15, 50.00, 50.00, 0.00, 0.00, 0.00, 'unpaid', 'active', 2, '2026-08-10 09:00:00', 1, 1, 1);

-- official receipts
INSERT INTO payment_transactions (transaction_id, receipt_no, member_id, payment_type, base_amount, penalty_amount, compromise_amount, total_paid, tendered_amount, change_amount,
                                  cashier_id, payment_date, business_date, created_at, transaction_status, journal_voucher_id, branch_id, segment_id, division_id,
                                  journal_head_id, journal_entry_id, journal_status, cash_tendered)
VALUES
  (9001, 'OR-FX-0001', 114842, 'water', 200.00, 0.00, 0.00, 200.00, 200.00, 0.00, 2, '2026-08-15 10:00:00', '2026-08-15', '2026-08-15 10:00:00', 'active', 'JV-FX-0005', 1, 1, 1, 'JV-FX-0005', 'OR-FX-0001', 'posted', 200.00),
  (9002, 'OR-FX-0002', 114844, 'water', 420.00, 0.00, 0.00, 420.00, 500.00, 80.00, 2, '2026-08-16 10:00:00', '2026-08-16', '2026-08-16 10:00:00', 'active', 'JV-FX-0006', 1, 1, 1, 'JV-FX-0006', 'OR-FX-0002', 'posted', 500.00);

INSERT INTO payment_transaction_items (item_id, transaction_id, source_type, source_id, description, due_date, amount, principal_amount, penalty_amount, balance_before, balance_after, allocation_status, created_at)
VALUES
  (9001, 9001, 'water', 9001, 'Water bill 080126_083126', '2026-08-20', 200.00, 200.00, 0.00, 500.00, 300.00, 'active', '2026-08-15 10:00:00'),
  (9002, 9002, 'water', 9003, 'Water bill 080126_083126', '2026-08-20', 420.00, 420.00, 0.00, 420.00,   0.00, 'active', '2026-08-16 10:00:00');

-- the legacy journal: vouchers
INSERT INTO journalvoucher_copy1 (JVID, JVEntryID, JVTransDate, JVEntryDate, JVDateLastUpdated, Status, BranchID, SegmentID, DivisionID)
VALUES
  ('JV-FX-0001', '1001',       '2026-08-05 00:00:00', '2026-08-05 08:00:00', '2026-08-05 08:00:00', 'A', 1, 1, 1),
  ('JV-FX-0002', '1002',       '2026-08-05 00:00:00', '2026-08-05 08:01:00', '2026-08-05 08:01:00', 'A', 1, 1, 1),
  ('JV-FX-0003', '1003',       '2026-08-05 00:00:00', '2026-08-05 08:02:00', '2026-08-05 08:02:00', 'A', 1, 1, 1),
  ('JV-FX-0004', '1004',       '2026-08-10 00:00:00', '2026-08-10 09:00:00', '2026-08-10 09:00:00', 'A', 1, 1, 1),
  ('JV-FX-0005', 'OR-FX-0001', '2026-08-15 00:00:00', '2026-08-15 10:00:00', '2026-08-15 10:00:00', 'A', 1, 1, 1),
  ('JV-FX-0006', 'OR-FX-0002', '2026-08-16 00:00:00', '2026-08-16 10:00:00', '2026-08-16 10:00:00', 'A', 1, 1, 1);

-- the legacy journal: lines (the credit / revenue line carries IsJournalHead = 1 as the former program wrote it)
INSERT INTO journalentries_copy1 (JournalID, JournalHeadID, TransRefID, AccountID, Debit, Credit, Description, SLType, SLCode, BookType, IsJournalHead, TransactionDate, IsPosted, Status, entryType, BranchID, SegmentID, DivisionID)
VALUES
  (90001, 'JV-FX-0001', '1001', 40210, 0.000000, 500.000000, 'Water bill 9001 revenue', 4, '114842', 6, 1, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90002, 'JV-FX-0001', '1001', 11784, 500.000000, 0.000000, 'Water bill 9001 receivable', 1, '1000', 6, 0, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90003, 'JV-FX-0002', '1002', 40210, 0.000000, 350.000000, 'Water bill 9002 revenue', 4, '114843', 6, 1, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90004, 'JV-FX-0002', '1002', 11784, 350.000000, 0.000000, 'Water bill 9002 receivable', 1, '1000', 6, 0, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90005, 'JV-FX-0003', '1003', 40210, 0.000000, 420.000000, 'Water bill 9003 revenue', 4, '114844', 6, 1, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90006, 'JV-FX-0003', '1003', 11784, 420.000000, 0.000000, 'Water bill 9003 receivable', 1, '1000', 6, 0, '2026-08-05 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90007, 'JV-FX-0004', '1004', 40210, 0.000000, 50.000000, 'One-time bill 9001 revenue', 4, '114842', 6, 1, '2026-08-10 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90008, 'JV-FX-0004', '1004', 11784, 50.000000, 0.000000, 'One-time bill 9001 receivable', 1, '1000', 6, 0, '2026-08-10 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90009, 'JV-FX-0005', 'OR-FX-0001', 11118, 200.000000, 0.000000, 'OR-FX-0001 cash', 0, '', 6, 1, '2026-08-15 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90010, 'JV-FX-0005', 'OR-FX-0001', 11784, 0.000000, 200.000000, 'OR-FX-0001 receivable settled', 1, '1000', 6, 0, '2026-08-15 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90011, 'JV-FX-0006', 'OR-FX-0002', 11118, 420.000000, 0.000000, 'OR-FX-0002 cash', 0, '', 6, 1, '2026-08-16 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1),
  (90012, 'JV-FX-0006', 'OR-FX-0002', 11784, 0.000000, 420.000000, 'OR-FX-0002 receivable settled', 1, '1000', 6, 0, '2026-08-16 00:00:00', 1, 'A', 'NORMAL', 1, 1, 1);

-- a voided voucher and its void record: Status 'V' lines are not part of the balance (the migration ignores them)
INSERT INTO journalvoucher_copy1 (JVID, JVEntryID, JVTransDate, JVEntryDate, JVDateLastUpdated, Status, BranchID, SegmentID, DivisionID)
VALUES ('JV-FX-0007', 'VOIDED-1', '2026-08-18 00:00:00', '2026-08-18 10:00:00', '2026-08-18 11:00:00', 'V', 1, 1, 1);
INSERT INTO journalentries_copy1 (JournalID, JournalHeadID, TransRefID, AccountID, Debit, Credit, Description, SLType, SLCode, BookType, IsJournalHead, TransactionDate, IsPosted, Status, entryType, BranchID, SegmentID, DivisionID)
VALUES
  (90013, 'JV-FX-0007', 'VOIDED-1', 40210, 0.000000, 999.000000, 'Voided test entry', 4, '', 6, 1, '2026-08-18 00:00:00', 1, 'V', 'NORMAL', 1, 1, 1),
  (90014, 'JV-FX-0007', 'VOIDED-1', 11784, 999.000000, 0.000000, 'Voided test entry', 1, '1000', 6, 0, '2026-08-18 00:00:00', 1, 'V', 'NORMAL', 1, 1, 1);
INSERT INTO journal_voucher_voids (void_id, original_jvid, original_jv_entry_id, replacement_jvid, replacement_jv_entry_id, trans_ref_id, source_type, source_id, reason, voided_amount, voided_line_count, voided_by, voided_at)
VALUES (9001, 'JV-FX-0007', 'VOIDED-1', NULL, NULL, 'VOIDED-1', 'manual', NULL, 'Fixture: voided entry', 999.000000, 2, 2, '2026-08-18 11:00:00');
