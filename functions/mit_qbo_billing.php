<?php

/*
 * Mountaineer IT - QuickBooks Online billing (required from functions/mit_qbo.php): approving invoices, batches, locks and
 * payments coming back.
 *
 * - Approval (mit_invoice_approvals): every invoice is approved before it can go into a batch. What was approved is kept as
 *   a fingerprint (client, dates, amounts, discount, lines); an invoice whose fingerprint has changed shows "Changed since
 *   approval" and must be approved again - no hooks needed in ITFlow's many edit paths for that.
 * - Batches (mit_qbo_batches / mit_qbo_batch_invoices): approved invoices are added to an open batch and the batch is sent;
 *   each invoice becomes a QuickBooks invoice (number = ITFlow's prefix + number), ITFlow marks a Draft as Sent, and QuickBooks
 *   emails it when the batch says so. Failed ones keep their reason and can be sent again.
 * - Locks: an invoice in a batch (any entry not removed) can't be changed in ITFlow - one guarded line in each of ITFlow's
 *   invoice handlers (mitQboGuardInvoice). Taking it out of a batch unlocks it; one already in QuickBooks is voided there
 *   (or only unlinked) and goes back to Draft and unapproved.
 * - While a company is connected QuickBooks emails invoices (ITFlow's invoice emails are refused - mitQboGuardEmail - and the
 *   nightly job neither sends recurring invoices nor reminders) and takes the payments (ITFlow refuses payments on invoices in
 *   QuickBooks - mitQboGuardPayment). mitQboPaymentSync (cron job mit_qbo_sync, hourly, or "Check payments now") keeps one
 *   ITFlow payment per QuickBooks invoice equal to what has been paid there.
 * Permission: Financial edit approves, batches and sends; Sales view (as for Invoices) sees the pages.
 */

const MIT_QBO_ENTRY_STATUSES = [
    'queued' => ['Waiting to send', 'text-bg-secondary'],
    'sent' => ['In QuickBooks', 'text-bg-success'],
    'failed' => ['Failed', 'text-bg-danger'],
    'removed' => ['Removed', 'text-bg-light border'],
];
const MIT_QBO_BATCH_STATUSES = [
    'open' => ['Open', 'text-bg-secondary'],
    'partial' => ['Partly sent', 'text-bg-warning'],
    'sent' => ['Sent', 'text-bg-success'],
];
/** Invoices that may be approved and batched (paid, part-paid, cancelled and non-billable ones are not). */
const MIT_QBO_BATCHABLE_SQL = "invoices.invoice_status IN ('Draft', 'Sent', 'Viewed') AND invoices.invoice_archived_at IS NULL";

/** May the signed-in person see the pages (level 1: Sales view, like Invoices) or approve, batch and send (2+: Financial edit)? */
function mitQboCan($level = 2) {
    return $level <= 1 ? lookupUserPermission('module_sales') >= 1 : lookupUserPermission('module_financial') >= $level;
}

/*
 * ---------- approval ----------
 */

/** What an invoice is now, as one hash: client, dates, amounts, discount, currency, number and every line. */
function mitInvoiceFingerprint($invoice_id) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_client_id, invoice_prefix, invoice_number, invoice_date, invoice_due,
        invoice_discount_amount, invoice_credit_amount, invoice_amount, invoice_currency_code, invoice_note FROM invoices WHERE invoice_id = $invoice_id"));
    if (!$invoice) {
        return '';
    }
    $parts = [json_encode(array_values($invoice))];
    $result = mysqli_query($mysqli, "SELECT item_name, item_description, item_quantity, item_price, item_tax_id, item_product_id, item_total
        FROM invoice_items WHERE item_invoice_id = $invoice_id AND item_archived_at IS NULL ORDER BY item_order, item_id");
    while ($row = mysqli_fetch_row($result)) {
        $parts[] = json_encode($row);
    }
    return hash('sha256', implode("\n", $parts));
}

/** Approval of many invoices at once: invoice_id => none | approved | changed (approved, but changed since). */
function mitInvoiceApprovalStates(array $invoice_ids) {
    global $mysqli;
    $ids = array_values(array_filter(array_unique(array_map('intval', $invoice_ids))));
    $states = array_fill_keys($ids, 'none');
    if (!$ids || !mitQboReady()) {
        return $states;
    }
    $result = mysqli_query($mysqli, "SELECT invoice_id, fingerprint FROM mit_invoice_approvals WHERE invoice_id IN (" . implode(',', $ids) . ")");
    while ($row = mysqli_fetch_assoc($result)) {
        $states[intval($row['invoice_id'])] = hash_equals($row['fingerprint'], mitInvoiceFingerprint($row['invoice_id'])) ? 'approved' : 'changed';
    }
    return $states;
}

/** One invoice's approval: none | approved | changed. */
function mitInvoiceApprovalState($invoice_id) {
    return mitInvoiceApprovalStates([$invoice_id])[intval($invoice_id)] ?? 'none';
}

/** Approve invoices (as they are now). Returns [approved, refused => [invoice_id => why]]. */
function mitInvoiceApprove(array $invoice_ids) {
    global $mysqli, $session_user_id, $session_name;
    $approved = 0;
    $refused = [];
    foreach (array_unique(array_map('intval', $invoice_ids)) as $invoice_id) {
        $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_amount, invoice_client_id FROM invoices
            WHERE invoice_id = $invoice_id AND " . MIT_QBO_BATCHABLE_SQL . clientScopeSql('invoices.invoice_client_id')));
        if (!$invoice) {
            $refused[$invoice_id] = 'not an invoice that can be approved (paid, cancelled, non-billable, archived or not yours to see)';
            continue;
        }
        if ($why = mitQboInvoiceLock($invoice_id)) {
            $refused[$invoice_id] = $why;
            continue;
        }
        $fingerprint = mitInvoiceFingerprint($invoice_id);
        $amount = floatval($invoice['invoice_amount']);
        $by = intval($session_user_id);
        mysqli_query($mysqli, "INSERT INTO mit_invoice_approvals SET invoice_id = $invoice_id, fingerprint = '$fingerprint', approved_amount = $amount, approved_by = $by, approved_at = NOW()
            ON DUPLICATE KEY UPDATE fingerprint = '$fingerprint', approved_amount = $amount, approved_by = $by, approved_at = NOW()");
        mysqli_query($mysqli, "INSERT INTO history SET history_status = '" . escapeSql(mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id"))[0]) . "',
            history_description = 'Approved for QuickBooks by " . escapeSql(stripslashes($session_name)) . "', history_invoice_id = $invoice_id");
        $approved++;
    }
    if ($approved) {
        logAudit('Invoice', 'Edit', escapeSql(stripslashes($session_name)) . " approved $approved invoice" . ($approved === 1 ? '' : 's') . ' for QuickBooks');
    }
    return [$approved, $refused];
}

/** Take an approval back (not while the invoice is in a batch). */
function mitInvoiceUnapprove($invoice_id) {
    global $mysqli, $session_name;
    $invoice_id = intval($invoice_id);
    if ($why = mitQboInvoiceLock($invoice_id)) {
        return $why;
    }
    mysqli_query($mysqli, "DELETE FROM mit_invoice_approvals WHERE invoice_id = $invoice_id");
    if (mysqli_affected_rows($mysqli)) {
        mysqli_query($mysqli, "INSERT INTO history SET history_status = (SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id),
            history_description = 'Approval for QuickBooks taken back by " . escapeSql(stripslashes($session_name)) . "', history_invoice_id = $invoice_id");
    }
    return '';
}

/*
 * ---------- locks and guards ----------
 */

/** The invoice's live batch entry (not removed), with its batch, or null. */
function mitQboActiveEntry($invoice_id) {
    global $mysqli;
    if (!mitQboReady()) {
        return null;
    }
    $invoice_id = intval($invoice_id);
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT e.*, b.batch_name, b.batch_status FROM mit_qbo_batch_invoices e
        JOIN mit_qbo_batches b ON b.batch_id = e.batch_id WHERE e.invoice_id = $invoice_id AND e.entry_status != 'removed' ORDER BY e.entry_id DESC LIMIT 1")) ?: null;
}

/** Why an invoice can't be changed in ITFlow right now ('' = it can). */
function mitQboInvoiceLock($invoice_id) {
    $entry = mitQboActiveEntry($invoice_id);
    if (!$entry) {
        return '';
    }
    $batch = 'QuickBooks batch "' . $entry['batch_name'] . '"';
    return $entry['entry_status'] === 'sent'
        ? "This invoice is in QuickBooks Online ($batch) and is locked - take it out of the batch to change it."
        : "This invoice is in $batch and is locked - take it out of the batch to change it.";
}

/** For ITFlow's invoice handlers: refuse a change to a locked invoice (flash + back). One line each, guarded with function_exists. */
function mitQboGuardInvoice($invoice_id) {
    if ($why = mitQboInvoiceLock($invoice_id)) {
        flashAlert(escapeHtml($why), 'error');
        redirect();
    }
}

/** While QuickBooks is connected it emails the invoices - ITFlow's invoice emails are refused. */
function mitQboGuardEmail() {
    if (mitQboConnected()) {
        flashAlert('QuickBooks Online emails invoices - approve the invoice and send it in a QuickBooks batch (Billing > QuickBooks Batches).', 'error');
        redirect();
    }
}

/** Is the invoice in QuickBooks (sent there, not taken out)? Its payments are then recorded in QuickBooks. */
function mitQboInQuickBooks($invoice_id) {
    $entry = mitQboActiveEntry($invoice_id);
    return $entry && $entry['entry_status'] === 'sent';
}

/** An invoice in QuickBooks takes its payments there - ITFlow refuses them (the sync brings them back). */
function mitQboGuardPayment($invoice_id) {
    if (mitQboInQuickBooks($invoice_id)) {
        flashAlert('Payments for this invoice are recorded in QuickBooks Online - they come back to ITFlow on their own.', 'error');
        redirect();
    }
}

/** A payment the QuickBooks sync keeps can't be changed or deleted by hand. */
function mitQboGuardPaymentEdit($payment_id) {
    global $mysqli;
    if (!mitQboReady()) {
        return;
    }
    $payment_id = intval($payment_id);
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_qbo_batch_invoices WHERE payment_id = $payment_id AND entry_status = 'sent' LIMIT 1"))) {
        flashAlert('That payment comes from QuickBooks Online and follows it - change it in QuickBooks.', 'error');
        redirect();
    }
}

/** For the nightly job: does QuickBooks handle invoice emails and reminders? */
function mitQboHandlesInvoiceMail() {
    return mitQboConnected();
}

/*
 * ---------- batches ----------
 */

/** Approved invoices not in a batch yet, newest first (client scope applies). */
function mitQboReadyInvoices($client_id = 0, $limit = 500) {
    global $mysqli;
    $client_sql = $client_id ? ' AND invoices.invoice_client_id = ' . intval($client_id) : '';
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT invoices.invoice_id, invoice_prefix, invoice_number, invoice_date, invoice_due, invoice_amount, invoice_status,
            invoice_currency_code, client_id, client_name
        FROM invoices JOIN mit_invoice_approvals a ON a.invoice_id = invoices.invoice_id JOIN clients ON client_id = invoice_client_id
        WHERE " . MIT_QBO_BATCHABLE_SQL . $client_sql . clientScopeSql('invoices.invoice_client_id') . "
            AND NOT EXISTS (SELECT 1 FROM mit_qbo_batch_invoices e WHERE e.invoice_id = invoices.invoice_id AND e.entry_status != 'removed')
        ORDER BY invoice_date DESC, invoice_number DESC LIMIT " . intval($limit));
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['invoice_id'])] = $row;
    }
    // Only those still as they were approved
    $states = mitInvoiceApprovalStates(array_keys($rows));
    foreach ($rows as $id => $row) {
        $rows[$id]['approval'] = $states[$id] ?? 'none';
    }
    return $rows;
}

/** Make an open batch. Returns its id. */
function mitQboBatchCreate($name, $email) {
    global $mysqli, $session_user_id;
    $name = trim((string) $name) !== '' ? trim((string) $name) : 'Batch ' . date('M j, Y g:i A');
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batches SET batch_name = '" . escapeSql(mb_substr($name, 0, 200)) . "', batch_email = " . ($email ? 1 : 0) . ",
        created_by = " . intval($session_user_id));
    return mysqli_insert_id($mysqli);
}

/** Add approved invoices to an open batch. Returns [added, refused => [invoice_id => why]]. */
function mitQboBatchAdd($batch_id, array $invoice_ids) {
    global $mysqli, $session_user_id;
    $batch_id = intval($batch_id);
    $batch = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT batch_status FROM mit_qbo_batches WHERE batch_id = $batch_id"));
    if (!$batch) {
        return [0, [0 => 'That batch does not exist.']];
    }
    $added = 0;
    $refused = [];
    if (!mitLock('qbo_batches', 20)) {
        return [0, [0 => 'Another batch change is running - try again in a moment.']];
    }
    try {
        foreach (array_unique(array_map('intval', $invoice_ids)) as $invoice_id) {
            $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_amount FROM invoices WHERE invoice_id = $invoice_id AND " . MIT_QBO_BATCHABLE_SQL
                . clientScopeSql('invoices.invoice_client_id')));
            if (!$invoice) {
                $refused[$invoice_id] = 'not an invoice that can be batched';
                continue;
            }
            if (mitQboActiveEntry($invoice_id)) {
                $refused[$invoice_id] = 'already in a batch';
                continue;
            }
            if (mitInvoiceApprovalState($invoice_id) !== 'approved') {
                $refused[$invoice_id] = 'not approved (or changed since it was approved)';
                continue;
            }
            $fingerprint = mitInvoiceFingerprint($invoice_id);
            mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $batch_id, invoice_id = $invoice_id, entry_status = 'queued',
                fingerprint = '$fingerprint', amount = " . floatval($invoice['invoice_amount']) . ", added_by = " . intval($session_user_id));
            $added++;
        }
        mitQboBatchRestatus($batch_id);
    } finally {
        mitUnlock('qbo_batches');
    }
    return [$added, $refused];
}

/** A batch's status from its invoices: sent (all in QuickBooks), partial (some), open (none). */
function mitQboBatchRestatus($batch_id) {
    global $mysqli;
    $batch_id = intval($batch_id);
    $c = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(entry_status = 'sent') AS sent, SUM(entry_status IN ('queued', 'failed')) AS waiting
        FROM mit_qbo_batch_invoices WHERE batch_id = $batch_id"));
    $status = intval($c['sent']) && !intval($c['waiting']) ? 'sent' : (intval($c['sent']) ? 'partial' : 'open');
    mysqli_query($mysqli, "UPDATE mit_qbo_batches SET batch_status = '$status' WHERE batch_id = $batch_id");
    return $status;
}

/** Send one entry to QuickBooks (create the invoice, mark ITFlow's Sent, email from QuickBooks). Returns '' or the problem. */
function mitQboSendEntry(array $entry, array $items, $email) {
    global $mysqli, $session_name;
    $entry_id = intval($entry['entry_id']);
    $invoice_id = intval($entry['invoice_id']);
    $fail = function ($why) use ($mysqli, $entry_id) {
        mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'failed', error = '" . escapeSql(mb_substr($why, 0, 2000)) . "' WHERE entry_id = $entry_id");
        return $why;
    };
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_status, invoice_amount, invoice_prefix, invoice_number FROM invoices WHERE invoice_id = $invoice_id"));
    if (!$invoice) {
        return $fail('The invoice no longer exists.');
    }
    if (!hash_equals((string) $entry['fingerprint'], mitInvoiceFingerprint($invoice_id))) {
        return $fail('The invoice changed after it was batched - take it out, approve it again and add it back.');
    }
    $warnings = [];
    if ((string) $entry['qbo_invoice_id'] === '') {
        [$payload, $problems] = mitQboInvoicePayload($invoice_id, $items, intval($entry['batch_id']));
        if ($problems) {
            return $fail(implode(' ', $problems));
        }
        try {
            $answer = mitQboApi('POST', '/invoice', $payload);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate Document Number') !== false) {
                $message .= ' - QuickBooks already has an invoice ' . mitQboDocNumber($invoice) . ' (turn on Custom transaction numbers in QuickBooks, or void the old one).';
            }
            return $fail($message);
        }
        $qbo = $answer['Invoice'] ?? [];
        $qbo_id = escapeSql((string) ($qbo['Id'] ?? ''));
        $total = floatval($qbo['TotalAmt'] ?? 0);
        if (abs($total - floatval($invoice['invoice_amount'])) > 0.005) {
            $warnings[] = 'QuickBooks total ' . number_format($total, 2) . ' differs from ITFlow\'s ' . number_format(floatval($invoice['invoice_amount']), 2)
                . ' (QuickBooks works the tax out itself).';
        }
        mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'sent', error = NULL, qbo_invoice_id = '$qbo_id',
            qbo_sync_token = '" . escapeSql((string) ($qbo['SyncToken'] ?? '0')) . "', qbo_doc_number = '" . escapeSql((string) ($qbo['DocNumber'] ?? '')) . "',
            qbo_total = $total, qbo_balance = " . floatval($qbo['Balance'] ?? $total) . ", sent_at = NOW() WHERE entry_id = $entry_id");
        $entry['qbo_invoice_id'] = $qbo['Id'] ?? '';
        $entry['bill_email'] = $qbo['BillEmail']['Address'] ?? '';
        // ITFlow follows: a Draft becomes Sent (QuickBooks has it), with the step in its history
        if ($invoice['invoice_status'] === 'Draft') {
            mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $invoice_id AND invoice_status = 'Draft'");
        }
        mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Sent', history_description = 'Sent to QuickBooks Online (batch "
            . intval($entry['batch_id']) . ") by " . escapeSql(stripslashes($session_name ?? 'ITFlow')) . "', history_invoice_id = $invoice_id");
    } else {
        mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'sent', error = NULL WHERE entry_id = $entry_id");
    }
    // QuickBooks emails it to the billing contacts when the batch says so
    if ($email && empty($entry['emailed_at'])) {
        if (!mitQboClientEmails(mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_client_id FROM invoices WHERE invoice_id = $invoice_id"))[0])) {
            $warnings[] = 'Not emailed - the client has no billing or primary contact with an email address.';
        } else {
            try {
                mitQboApi('POST', '/invoice/' . rawurlencode((string) $entry['qbo_invoice_id']) . '/send');
                mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET emailed_at = NOW() WHERE entry_id = $entry_id");
            } catch (Throwable $e) {
                $warnings[] = 'In QuickBooks, but not emailed: ' . $e->getMessage();
            }
        }
    }
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET warning = " . ($warnings ? "'" . escapeSql(mb_substr(implode(' ', $warnings), 0, 2000)) . "'" : 'NULL') . "
        WHERE entry_id = $entry_id");
    return '';
}

/** Send a batch: every waiting or failed invoice in it. Returns [sent, failed => [invoice => why]]. */
function mitQboBatchSend($batch_id) {
    global $mysqli, $session_user_id, $session_name;
    $batch_id = intval($batch_id);
    $batch = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_qbo_batches WHERE batch_id = $batch_id"));
    if (!$batch) {
        throw new RuntimeException('That batch does not exist.');
    }
    if (!mitLock("qbo_batch_$batch_id", 5)) {
        throw new RuntimeException('This batch is already being sent.');
    }
    $sent = 0;
    $failed = [];
    try {
        $items = mitQboList('items');
        $result = mysqli_query($mysqli, "SELECT e.*, invoice_prefix, invoice_number FROM mit_qbo_batch_invoices e JOIN invoices ON invoices.invoice_id = e.invoice_id
            WHERE e.batch_id = $batch_id AND e.entry_status IN ('queued', 'failed')" . clientScopeSql('invoices.invoice_client_id') . " ORDER BY e.entry_id");
        while ($entry = mysqli_fetch_assoc($result)) {
            $why = mitQboSendEntry($entry, $items, intval($batch['batch_email']) === 1);
            if ($why === '') {
                $sent++;
            } else {
                $failed[$entry['invoice_prefix'] . $entry['invoice_number']] = $why;
            }
        }
        mysqli_query($mysqli, "UPDATE mit_qbo_batches SET sent_by = " . intval($session_user_id) . ", sent_at = NOW() WHERE batch_id = $batch_id");
        mitQboBatchRestatus($batch_id);
    } finally {
        mitUnlock("qbo_batch_$batch_id");
    }
    logAudit('Invoice', 'Edit', escapeSql(stripslashes($session_name)) . " sent QuickBooks batch " . escapeSql($batch['batch_name']) . " ($sent sent, " . count($failed) . ' failed)');
    return [$sent, $failed];
}

/**
 * Take an invoice out of its batch. Not yet in QuickBooks: it just comes out (and unlocks). In QuickBooks: $how 'void' voids it
 * there (refused while it has payments), 'unlink' leaves it there - either way ITFlow's invoice unlocks, goes back to Draft
 * and needs approving again. Returns '' or the problem.
 */
function mitQboRemoveEntry($entry_id, $how = 'void') {
    global $mysqli, $session_user_id, $session_name;
    $entry_id = intval($entry_id);
    $entry = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT e.*, invoice_status, invoice_client_id FROM mit_qbo_batch_invoices e JOIN invoices ON invoices.invoice_id = e.invoice_id
        WHERE e.entry_id = $entry_id AND e.entry_status != 'removed'" . clientScopeSql('invoices.invoice_client_id')));
    if (!$entry) {
        return 'That invoice is not in a batch.';
    }
    $invoice_id = intval($entry['invoice_id']);
    $removed_how = 'removed';
    if ($entry['entry_status'] === 'sent' && (string) $entry['qbo_invoice_id'] !== '') {
        if ($how === 'void') {
            try {
                $qbo = mitQboApi('GET', '/invoice/' . rawurlencode($entry['qbo_invoice_id']))['Invoice'] ?? [];
                if (round(floatval($qbo['Balance'] ?? 0), 2) < round(floatval($qbo['TotalAmt'] ?? 0), 2)) {
                    return 'It has payments in QuickBooks - remove them there first (or take it out without voiding).';
                }
                mitQboApi('POST', '/invoice', ['Id' => (string) $qbo['Id'], 'SyncToken' => (string) $qbo['SyncToken']], ['operation' => 'void']);
            } catch (Throwable $e) {
                return 'QuickBooks did not void it: ' . $e->getMessage();
            }
            $removed_how = 'voided';
        } else {
            $removed_how = 'unlinked';
        }
        // ITFlow's invoice goes back to a Draft to be approved again; the sync's payment (if any) goes with the link
        if ($entry['payment_id']) {
            mysqli_query($mysqli, "DELETE FROM payments WHERE payment_id = " . intval($entry['payment_id']));
        }
        mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Draft' WHERE invoice_id = $invoice_id AND invoice_status IN ('Sent', 'Viewed', 'Partial', 'Paid')");
        mysqli_query($mysqli, "DELETE FROM mit_invoice_approvals WHERE invoice_id = $invoice_id");
        mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = 'Taken out of QuickBooks batch " . intval($entry['batch_id'])
            . ($removed_how === 'voided' ? ' and voided in QuickBooks' : ' (left in QuickBooks)') . " by " . escapeSql(stripslashes($session_name)) . "', history_invoice_id = $invoice_id");
    }
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'removed', removed_by = " . intval($session_user_id) . ", removed_at = NOW(),
        removed_how = '$removed_how' WHERE entry_id = $entry_id");
    mitQboBatchRestatus($entry['batch_id']);
    return '';
}

/** Delete a batch with nothing in QuickBooks (its waiting invoices come out and unlock). Returns '' or the problem. */
function mitQboBatchDelete($batch_id) {
    global $mysqli;
    $batch_id = intval($batch_id);
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_qbo_batch_invoices WHERE batch_id = $batch_id AND entry_status = 'sent' LIMIT 1"))) {
        return 'Part of this batch is in QuickBooks - take those invoices out first.';
    }
    mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = $batch_id");
    return '';
}

/*
 * ---------- payments back from QuickBooks ----------
 */

/**
 * Keep ITFlow's payments in step with QuickBooks: for each invoice in QuickBooks (not fully paid yet, or checked more than a day
 * ago, sent in the last year), what has been paid there becomes one ITFlow payment ("QuickBooks Online") - added, changed or
 * removed - and ITFlow's status follows. Paid in full there = paid in full here (QuickBooks' own tax rounding aside).
 * Returns ['checked' => n, 'changed' => n, 'errors' => [...]]; throws when QuickBooks can't be reached.
 */
function mitQboPaymentSync() {
    global $mysqli;
    $report = ['checked' => 0, 'changed' => 0, 'errors' => []];
    $account = mitQboSettings()['payment_account'];
    if (!$account) {
        throw new RuntimeException('Pick the account payments from QuickBooks go to (Admin > Integrations > QuickBooks Online > Connection).');
    }
    $result = mysqli_query($mysqli, "SELECT e.entry_id, e.invoice_id, e.qbo_invoice_id, e.qbo_doc_number, e.payment_id, invoice_amount, invoice_currency_code
        FROM mit_qbo_batch_invoices e JOIN invoices ON invoices.invoice_id = e.invoice_id
        WHERE e.entry_status = 'sent' AND e.qbo_invoice_id IS NOT NULL AND e.sent_at > NOW() - INTERVAL 1 YEAR
            AND (e.qbo_balance IS NULL OR e.qbo_balance > 0 OR e.paid_synced_at IS NULL OR e.paid_synced_at < NOW() - INTERVAL 1 DAY)
        ORDER BY e.paid_synced_at IS NOT NULL, e.paid_synced_at LIMIT 2000");
    $entries = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $entries[(string) $row['qbo_invoice_id']] = $row;
    }
    foreach (array_chunk(array_keys($entries), 30, true) as $chunk) {
        $ids = implode(',', array_map('mitQboQuote', $chunk));
        $found = [];
        foreach (mitQboQueryAll('Invoice', "Id IN ($ids)") as $qbo) {
            $found[(string) $qbo['Id']] = $qbo;
        }
        foreach ($chunk as $qbo_id) {
            $entry = $entries[$qbo_id];
            $report['checked']++;
            $qbo = $found[$qbo_id] ?? null;
            if (!$qbo) {
                $report['errors'][] = 'QuickBooks no longer has invoice ' . $entry['qbo_doc_number'] . ' - was it deleted there?';
                mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET warning = 'QuickBooks no longer has this invoice.', paid_synced_at = NOW() WHERE entry_id = " . intval($entry['entry_id']));
                continue;
            }
            if (mitQboApplyPaid($entry, floatval($qbo['TotalAmt'] ?? 0), floatval($qbo['Balance'] ?? 0), $account)) {
                $report['changed']++;
            }
        }
    }
    mitSetSetting('qbo_last_sync', date('Y-m-d H:i:s'));
    mitSetSetting('qbo_last_error', $report['errors'] ? implode(' ', array_slice($report['errors'], 0, 3)) : '');
    return $report;
}

/** One invoice's paid amount from QuickBooks into ITFlow. Returns true when ITFlow changed. */
function mitQboApplyPaid(array $entry, $qbo_total, $qbo_balance, $account) {
    global $mysqli;
    $entry_id = intval($entry['entry_id']);
    $invoice_id = intval($entry['invoice_id']);
    $amount = floatval($entry['invoice_amount']);
    // What the sync's payment should be: all of ITFlow's amount when QuickBooks says paid, else what was paid there - less any
    // payment recorded in ITFlow before it went to QuickBooks
    $paid = round($qbo_balance <= 0.004 ? $amount : min($amount, max(0, $qbo_total - $qbo_balance)), 2);
    $own_id = intval($entry['payment_id'] ?? 0);
    $others = floatval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_invoice_id = $invoice_id
        AND payment_id != $own_id"))[0]);
    $target = round(max(0, $paid - $others), 2);
    $current = $own_id ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_amount FROM payments WHERE payment_id = $own_id")) : null;
    $changed = false;
    if ($target > 0 && !$current) {
        $currency = escapeSql((string) $entry['invoice_currency_code']);
        $reference = escapeSql('QuickBooks invoice ' . $entry['qbo_doc_number']);
        mysqli_query($mysqli, "INSERT INTO payments SET payment_date = CURDATE(), payment_amount = $target, payment_currency_code = '$currency',
            payment_method = 'QuickBooks Online', payment_reference = '$reference', payment_account_id = " . intval($account) . ", payment_invoice_id = $invoice_id");
        $own_id = mysqli_insert_id($mysqli);
        $changed = true;
    } elseif ($target > 0 && round(floatval($current['payment_amount']), 2) !== $target) {
        mysqli_query($mysqli, "UPDATE payments SET payment_amount = $target, payment_date = CURDATE() WHERE payment_id = $own_id");
        $changed = true;
    } elseif ($target <= 0 && $current) {
        mysqli_query($mysqli, "DELETE FROM payments WHERE payment_id = $own_id");
        $own_id = 0;
        $changed = true;
    }
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET payment_id = " . ($own_id ? $own_id : 'NULL') . ", qbo_total = " . floatval($qbo_total) . ",
        qbo_balance = " . floatval($qbo_balance) . ", paid_synced_at = NOW() WHERE entry_id = $entry_id");
    if ($changed) {
        $before = mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id"))[0];
        $after = updateInvoiceStatusFromPayments($invoice_id);
        mysqli_query($mysqli, "INSERT INTO history SET history_status = '" . escapeSql((string) ($after ?? $before)) . "', history_description = 'Payments from QuickBooks Online: "
            . number_format($paid, 2) . " paid', history_invoice_id = $invoice_id");
    }
    return $changed;
}

/*
 * ---------- on ITFlow's pages ----------
 */

/** QuickBooks state for many invoices (for list badges): invoice_id => [approval, entry status|null]. */
function mitQboInvoiceStates(array $invoice_ids) {
    global $mysqli;
    $ids = array_values(array_filter(array_unique(array_map('intval', $invoice_ids))));
    $states = [];
    if (!$ids || !mitQboReady()) {
        return $states;
    }
    foreach (mitInvoiceApprovalStates($ids) as $id => $approval) {
        $states[$id] = ['approval' => $approval, 'entry' => null];
    }
    $result = mysqli_query($mysqli, "SELECT invoice_id, entry_status FROM mit_qbo_batch_invoices WHERE invoice_id IN (" . implode(',', $ids) . ") AND entry_status != 'removed'");
    while ($row = mysqli_fetch_assoc($result)) {
        $states[intval($row['invoice_id'])]['entry'] = $row['entry_status'];
    }
    return $states;
}

/** The invoice ids in a list page's result (read, then rewound for the page's own loop). */
function mitQboResultIds($result) {
    $ids = [];
    if ($result instanceof mysqli_result && mitQboConnected()) {
        while ($row = mysqli_fetch_assoc($result)) {
            $ids[] = intval($row['invoice_id'] ?? 0);
        }
        if (mysqli_num_rows($result)) {
            mysqli_data_seek($result, 0);
        }
    }
    return $ids;
}

/** A small badge for an invoice row (agent/invoices.php) - $page_ids lets the page's states be read in one go. */
function mitQboListBadge($invoice_id, array $page_ids = []) {
    static $states = null;
    if (!mitQboConnected()) {
        return '';
    }
    if ($states === null || !isset($states[intval($invoice_id)])) {
        $states = ($states ?? []) + mitQboInvoiceStates($page_ids ?: [$invoice_id]);
    }
    $state = $states[intval($invoice_id)] ?? null;
    if (!$state) {
        return '';
    }
    if ($state['entry'] === 'sent') {
        return '<span class="badge text-bg-success ms-1" title="In QuickBooks Online"><i class="fas fa-lock me-1"></i>QBO</span>';
    }
    if ($state['entry']) {
        return '<span class="badge text-bg-info ms-1" title="In a QuickBooks batch"><i class="fas fa-lock me-1"></i>Batched</span>';
    }
    if ($state['approval'] === 'approved') {
        return '<span class="badge text-bg-primary ms-1" title="Approved for QuickBooks">Approved</span>';
    }
    if ($state['approval'] === 'changed') {
        return '<span class="badge text-bg-warning ms-1" title="Changed since it was approved">Re-approve</span>';
    }
    return '';
}

/** The QuickBooks strip on an invoice page (agent/invoice.php, one line): approval, batch, lock, and its buttons. */
function mitQboInvoiceBar($invoice_id) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    if (!mitQboReady() || !mitQboConnected() && !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_invoice_approvals WHERE invoice_id = $invoice_id"))) {
        return '';
    }
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id"));
    $entry = mitQboActiveEntry($invoice_id);
    $approval = mitInvoiceApprovalState($invoice_id);
    $approval_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT a.approved_at, user_name FROM mit_invoice_approvals a LEFT JOIN users ON user_id = a.approved_by WHERE a.invoice_id = $invoice_id"));
    $csrf = $_SESSION['csrf_token'];
    $can = mitQboCan(2);
    $batchable = in_array($invoice['invoice_status'] ?? '', ['Draft', 'Sent', 'Viewed'], true);
    $parts = [];
    $buttons = '';
    if ($entry) {
        [$label, $class] = MIT_QBO_ENTRY_STATUSES[$entry['entry_status']] ?? [$entry['entry_status'], 'text-bg-secondary'];
        $parts[] = '<i class="fas fa-lock me-1"></i>Locked - <span class="badge ' . $class . '">' . $label . '</span> in batch <a href="/agent/mit_qbo_batch.php?batch_id='
            . intval($entry['batch_id']) . '">' . escapeHtml($entry['batch_name']) . '</a>'
            . ($entry['entry_status'] === 'sent' ? ' as ' . escapeHtml((string) $entry['qbo_doc_number'])
                . ($entry['emailed_at'] ? ', emailed by QuickBooks' : '') . ($entry['qbo_balance'] !== null ? ' - balance there ' . number_format(floatval($entry['qbo_balance']), 2) : '') : '')
            . ($entry['entry_status'] === 'failed' && $entry['error'] ? ' - <span class="text-danger">' . escapeHtml($entry['error']) . '</span>' : '');
    } elseif ($approval === 'approved') {
        $parts[] = '<span class="badge text-bg-primary">Approved</span> for QuickBooks by ' . escapeHtml((string) ($approval_row['user_name'] ?? 'someone'))
            . ' on ' . date('M j, g:i A', strtotime($approval_row['approved_at'])) . ' - ready for a batch';
        if ($can) {
            $buttons .= '<a class="btn btn-sm btn-outline-secondary" href="/agent/post.php?mit_invoice_unapprove=' . $invoice_id . '&csrf_token=' . $csrf . '">Take approval back</a>';
        }
    } elseif ($approval === 'changed') {
        $parts[] = '<span class="badge text-bg-warning">Changed since approval</span> - approve it again before it can be batched';
    } elseif ($batchable) {
        $parts[] = '<span class="badge text-bg-secondary">Awaiting approval</span> for QuickBooks';
    } else {
        $parts[] = 'Not for QuickBooks (' . escapeHtml((string) $invoice['invoice_status']) . ')';
    }
    if (!$entry && $approval !== 'approved' && $batchable && $can) {
        $buttons .= '<a class="btn btn-sm btn-primary" href="/agent/post.php?mit_invoice_approve=' . $invoice_id . '&csrf_token=' . $csrf . '"><i class="fas fa-check me-1"></i>Approve</a>';
    }
    // While QuickBooks handles them, ITFlow's own email and (for invoices in QuickBooks) payment buttons and edits are hidden;
    // the handlers refuse them anyway
    $hide = ['[data-modal-url*="invoice_email.php"]', 'button[form="quickSendInvoice"]'];
    if ($entry) {
        $hide = array_merge($hide, ['[data-modal-url*="invoice_edit.php"]', '[data-modal-url*="invoice_item_edit"]', 'a[href*="delete_invoice_item="]',
            'tr:has(button[name="add_invoice_item"])', 'a[href*="cancel_invoice="]', 'a[href*="delete_invoice="]']);
        if ($entry['entry_status'] === 'sent') {
            $hide = array_merge($hide, ['[data-modal-url*="payment_add.php"]', '[data-modal-url*="payment_saved_method_add.php"]']);
        }
    }
    return '<div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 py-2 d-print-none" data-mit-qbo-bar>'
        . '<i class="fas fa-file-export text-success"></i><strong class="me-1">QuickBooks</strong><span class="me-auto">' . implode(' ', $parts) . '</span>' . $buttons . '</div>'
        . '<style>' . implode(', ', $hide) . ' { display: none !important; }</style>';
}

/** Side-menu counts: invoices awaiting approval, and approved ones waiting for a batch. */
function mitQboNavCounts() {
    global $mysqli;
    $scope = clientScopeSql('invoices.invoice_client_id');
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT
        (SELECT COUNT(*) FROM invoices WHERE " . MIT_QBO_BATCHABLE_SQL . " AND invoices.invoice_status = 'Draft'$scope
            AND NOT EXISTS (SELECT 1 FROM mit_invoice_approvals a WHERE a.invoice_id = invoices.invoice_id)),
        (SELECT COUNT(*) FROM invoices JOIN mit_invoice_approvals a ON a.invoice_id = invoices.invoice_id WHERE " . MIT_QBO_BATCHABLE_SQL . "$scope
            AND NOT EXISTS (SELECT 1 FROM mit_qbo_batch_invoices e WHERE e.invoice_id = invoices.invoice_id AND e.entry_status != 'removed'))"));
    return ['awaiting' => intval($row[0]), 'ready' => intval($row[1])];
}
