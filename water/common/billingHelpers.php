<?php
/**
 * common/billingHelpers.php
 *
 * Shared utilities for the billing engine:
 *   - generateGlobalRef()   — atomic sequence from global_billing_sequence
 *   - logAudit()            — write a row to water_audit_logs
 *   - recordJournalEntry()  — write voucher header + DR/CR lines into
 *                             journalvoucher_copy1 & journalentries_copy1
 *
 * Account IDs (from the existing mnssmc chart of accounts):
 *   11118  Accounts Receivable      (DR — normal balance DEBIT)
 *   30152  Service / Water Revenue  (CR — normal balance CREDIT)
 *
 * Subsidiary ledger conventions (matching existing journalentries_copy1 rows):
 *   SLType 1 / SLCode '1000'      → AR control account side
 *   SLType 4 / SLCode {member_id} → Revenue subsidiary (per member)
 *
 * Billing postings use BookType 6 (INVOICE). Collections use BookType 7 (CINVOICE).
 */

// ---------------------------------------------------------------------------
// 1. GLOBAL REFERENCE NUMBER GENERATOR
// ---------------------------------------------------------------------------
/**
 * Insert a sentinel row into global_billing_sequence and return the new
 * AUTO_INCREMENT value as an int.
 *
 * Must be called inside the same open transaction as the main billing INSERT
 * so that both commit or roll back together — no orphaned ref numbers.
 *
 * @throws PDOException on DB failure
 */
function generateGlobalRef(PDO $pdo): int
{
    $pdo->exec("INSERT INTO global_billing_sequence () VALUES ()");
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// 2. AUDIT LOGGER
// ---------------------------------------------------------------------------
/**
 * Append one row to water_audit_logs.
 *
 * @param PDO      $pdo
 * @param int|null $userId    Session user (null = system / cron job)
 * @param string   $action    Short verb: 'CREATE_ONE_TIME_BILL', 'SAVE_READING', etc.
 * @param string   $tableName Target table the action affected
 * @param string   $recordId  PK of the affected row (cast to string)
 * @param string   $details   JSON snapshot of key field values at transaction time
 */
function logAudit(
    PDO    $pdo,
    ?int   $userId,
    string $action,
    string $tableName,
    string $recordId,
    string $details
): void {
    $stmt = $pdo->prepare("
        INSERT INTO water_audit_logs (user_id, action, table_name, record_id, details, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $action, $tableName, $recordId, $details]);
}

// ---------------------------------------------------------------------------
// 3. JOURNAL VOUCHER + ENTRIES RECORDER
// ---------------------------------------------------------------------------
/**
 * Write one journal voucher header (journalvoucher_copy1) and its two
 * balanced DR/CR lines (journalentries_copy1).
 *
 * Entry pattern — mirrors the existing accounting data exactly:
 *
 *   IsJournalHead=1  (revenue / header line):
 *     AccountID 30152  Credit = amountDue   SLType=4  SLCode={memberSlCode}
 *
 *   IsJournalHead=0  (AR detail line):
 *     AccountID 11118  Debit  = amountDue   SLType=1  SLCode='1000'
 *
 * When a discount is applied, the net amountDue is used so the entry always
 * balances.  The gross charge and discount amount are captured in the
 * Description field and in water_audit_logs for the full breakdown.
 *
 * The function does NOT open or close a transaction — the caller owns the
 * transaction so all writes commit or roll back atomically.
 *
 * @param PDO      $pdo
 * @param int      $globalRef    From generateGlobalRef() — used as JVEntryID
 *                               and TransRefID to link back to the billing record
 * @param string   $txnDate      'Y-m-d' effective date of the transaction
 * @param string   $description  Human-readable memo (truncated to 200 chars)
 * @param float    $amountDue    Net amount the member owes (post-discount)
 * @param string   $memberSlCode Subsidiary ledger code for the revenue line
 *                               (typically the member_id cast to string)
 * @param int      $branchId     BranchID  — pass 0 if not used
 * @param int      $segmentId    SegmentID — pass 0 if not used
 * @param int      $divisionId   DivisionID — pass 0 if not used
 *
 * @throws PDOException on DB failure
 */
function recordJournalEntry(
    PDO    $pdo,
    int    $globalRef,
    string $txnDate,
    string $description,
    float  $amountDue,
    string $memberSlCode,
    int    $branchId   = 0,
    int    $segmentId  = 0,
    int    $divisionId = 0
): void {
    // -- Generate a UUID v4 for JVID ----------------------------------------
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // RFC 4122 variant
    $jvId    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    // JVEntryID = global_ref_number (sequential, unique — links accounting back
    // to the billing ledger without needing a separate FK column)
    $jvEntryId = (string)$globalRef;

    $desc = mb_substr($description, 0, 200);

    // -- 1. Voucher header ---------------------------------------------------
    $pdo->prepare("
        INSERT INTO journalvoucher_copy1
            (JVID, JVEntryID, JVTransDate, JVEntryDate, JVDateLastUpdated,
             Status, BranchID, SegmentID, DivisionID)
        VALUES
            (?, ?, ?, NOW(), NOW(), 'A', ?, ?, ?)
    ")->execute([$jvId, $jvEntryId, $txnDate, $branchId, $segmentId, $divisionId]);

    // -- 2. Journal entry lines ---------------------------------------------
    $lineStmt = $pdo->prepare("
        INSERT INTO journalentries_copy1
            (JournalHeadID, TransRefID, AccountID, Debit, Credit,
             Description, SLType, SLCode, BookType, IsJournalHead,
             TransactionDate, IsPosted, Status, entryType,
             BranchID, SegmentID, DivisionID)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, 6, ?, NOW(), 1, 'A', 'NORMAL', ?, ?, ?)
    ");

    // CR line — Revenue (IsJournalHead = 1, matches existing convention)
    $lineStmt->execute([
        $jvId,
        $jvEntryId,
        30152,          // AccountID: Service / Water Revenue
        0.000000,       // Debit  = 0
        $amountDue,     // Credit = net amount due
        $desc,
        4,              // SLType 4 = revenue subsidiary ledger
        $memberSlCode,  // SLCode = member identifier
        1,              // IsJournalHead = 1
        $branchId, $segmentId, $divisionId,
    ]);

    // DR line — Accounts Receivable (IsJournalHead = 0)
    $lineStmt->execute([
        $jvId,
        $jvEntryId,
        11118,          // AccountID: Accounts Receivable
        $amountDue,     // Debit  = net amount due
        0.000000,       // Credit = 0
        $desc,
        1,              // SLType 1 = AR control account
        '1000',         // SLCode matches existing AR entries in the dataset
        0,              // IsJournalHead = 0
        $branchId, $segmentId, $divisionId,
    ]);
}