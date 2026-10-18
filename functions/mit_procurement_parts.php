<?php

/*
 * Mountaineer IT - procurement part 2 (database update M010): the ways items get onto the list to
 * order, and parts on tickets. Required from functions/mit_procurement.php.
 *
 * - Quote -> invoice: the convert window ticks the lines to order (agent/modals/quote/quote_to_invoice.php
 *   hook mitProcQuoteConvertFields, agent/post/quote.php hook mitProcFromQuoteLine).
 * - An invoice: "Send to procurement" in its menu (agent/modals/mit_procurement/from_invoice.php).
 * - A ticket: the Parts box (mitTicketPartsCard in agent/ticket.php) - each part is from stock (taken
 *   off the product's stock), to order (on the list, its PO status shown) or on hand; "Bill parts"
 *   puts them on a new or existing draft invoice for the ticket's client.
 * - Products have a preferred vendor (products.product_vendor_id).
 */

/*
 * ---------- products: who we buy them from ----------
 */

function mitProductVendorReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        $ready = mitProcReady() && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM products LIKE 'product_vendor_id'")) === 1;
    }
    return $ready;
}

/** Our vendors (not a client's), vendor_id => name. */
function mitProcVendors() {
    global $mysqli;
    static $vendors = null;
    if ($vendors === null) {
        $vendors = [];
        $result = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $vendors[intval($row['vendor_id'])] = $row['vendor_name'];
        }
    }
    return $vendors;
}

/** Vendors to order from as rows (vendor_id, vendor_name), for the pages' selects. */
function mitProcVendorRows() {
    $rows = [];
    foreach (mitProcVendors() as $vendor_id => $vendor_name) {
        $rows[] = ['vendor_id' => $vendor_id, 'vendor_name' => $vendor_name];
    }
    return $rows;
}

function mitProcVendorOptions($selected) {
    $html = '<option value="0">- Later -</option>';
    foreach (mitProcVendors() as $vendor_id => $name) {
        $html .= '<option value="' . $vendor_id . '"' . ($vendor_id === intval($selected) ? ' selected' : '') . '>' . escapeHtml($name) . '</option>';
    }
    return $html;
}

/** The product modals' "Order from" field (agent/modals/product/product_add.php / product_edit.php). */
function mitProductVendorField($product_id) {
    global $mysqli;
    if (!mitProductVendorReady() || !mitProcOn()) {
        return '';
    }
    $current = intval($product_id) ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT product_vendor_id FROM products WHERE product_id = " . intval($product_id)))[0] ?? 0) : 0;
    return '<div class="mb-3"><label class="form-label">Order from <span class="text-muted small">(for purchase orders)</span></label>'
        . '<select class="form-select select2" name="product_vendor_id">' . mitProcVendorOptions($current) . '</select></div>';
}

/** Save it (called from mitProductSave). */
function mitProductVendorSave($product_id, array $post) {
    global $mysqli;
    if (!mitProductVendorReady() || !isset($post['product_vendor_id'])) {
        return;
    }
    $vendor_id = intval($post['product_vendor_id']);
    mysqli_query($mysqli, "UPDATE products SET product_vendor_id = " . ($vendor_id && isset(mitProcVendors()[$vendor_id]) ? $vendor_id : 'NULL') . " WHERE product_id = " . intval($product_id));
}

/** product_id => [type, vendor_id, cost] for a list of products. */
function mitProductsInfo(array $product_ids) {
    global $mysqli;
    $product_ids = array_values(array_filter(array_map('intval', $product_ids)));
    $info = [];
    if ($product_ids) {
        $vendor = mitProductVendorReady() ? 'product_vendor_id' : 'NULL';
        $cost = mitQuoteColumnsReady() ? 'product_cost' : 'NULL';
        $result = mysqli_query($mysqli, "SELECT product_id, product_type, product_tax_id, product_price, $vendor AS vendor_id, $cost AS cost FROM products WHERE product_id IN (" . implode(',', $product_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $info[intval($row['product_id'])] = $row;
        }
    }
    return $info;
}

/*
 * ---------- quote -> invoice ----------
 */

/** The convert window's project picker and "Send to procurement" ticks. */
function mitProcQuoteConvertFields($quote_id, $client_id) {
    global $mysqli;
    if (mitProcLevel() < 2) {
        return '';
    }
    $quote_id = intval($quote_id);
    $lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_name, item_quantity, item_product_id FROM quote_items WHERE item_quote_id = $quote_id ORDER BY item_order, item_id"), MYSQLI_ASSOC);
    if (!$lines) {
        return '';
    }
    $products = mitProductsInfo(array_column($lines, 'item_product_id'));
    $html = '<div class="mb-3">' . mitProcProjectField('mit_proc_project_id', $client_id, 0, 'Project <span class="text-muted small">(for the items to order - optional)</span>') . '</div>'
        . '<div class="border rounded overflow-hidden"><div class="px-3 py-2 bg-body-tertiary border-bottom"><strong><i class="fas fa-cart-plus me-2"></i>Send to procurement</strong>'
        . '<div class="small text-muted">Ticked lines wait under Procurement > To order until they are on a PO. The invoice itself is unchanged.</div></div>'
        . '<table class="table table-sm table-borderless mb-0 align-middle"><thead class="small text-muted"><tr><th style="width: 36px"></th><th>Item</th><th class="text-center">Qty</th><th class="text-end">Our cost</th><th style="width: 190px">Order from</th></tr></thead><tbody>';
    foreach ($lines as $line) {
        $item_id = intval($line['item_id']);
        $product = $products[intval($line['item_product_id'])] ?? null;
        $extra = function_exists('mitQuoteItemExtra') ? mitQuoteItemExtra($quote_id, $item_id) : null;
        $cost = $extra['item_cost'] ?? null;
        $is_service = $product && $product['product_type'] === 'service';
        // Hardware is ticked; services, and typed lines without our cost, are not
        $ticked = $product ? !$is_service : $cost !== null;
        $html .= '<tr class="border-top"><td class="ps-3"><input class="form-check-input" type="checkbox" name="mit_proc_lines[' . $item_id . '][send]" value="1"' . ($ticked ? ' checked' : '') . ' aria-label="Send ' . escapeHtml($line['item_name']) . '"></td>'
            . '<td>' . escapeHtml($line['item_name']) . ($is_service ? ' <span class="badge text-bg-light border">Service</span>' : '') . '</td>'
            . '<td class="text-center">' . mitQty($line['item_quantity']) . '</td>'
            . '<td class="text-end">' . ($cost === null ? '<span class="text-muted">-</span>' : mitMoney($cost)) . '</td>'
            . '<td class="pe-3"><select class="form-select form-select-sm" name="mit_proc_lines[' . $item_id . '][vendor_id]" aria-label="Order from">' . mitProcVendorOptions($product['vendor_id'] ?? 0) . '</select></td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Open projects the agent can see, oldest number last: [{id, label, client}] - client 0 = our own projects. */
function mitProcOpenProjects() {
    global $mysqli;
    $projects = [];
    $result = mysqli_query($mysqli, "SELECT project_id, project_prefix, project_number, project_name, project_client_id FROM projects
        WHERE project_archived_at IS NULL AND project_completed_at IS NULL" . clientScopeSql('project_client_id') . " ORDER BY project_number DESC");
    while ($row = mysqli_fetch_assoc($result)) {
        $projects[] = ['id' => intval($row['project_id']), 'client' => intval($row['project_client_id']),
            'label' => $row['project_prefix'] . $row['project_number'] . ' ' . $row['project_name']];
    }
    return $projects;
}

/** A client's open projects as <option>s (client 0 = our own projects). */
function mitProcProjectOptions($client_id, $selected) {
    $html = '<option value="0">- None -</option>';
    foreach (mitProcOpenProjects() as $project) {
        if ($project['client'] === intval($client_id)) {
            $html .= '<option value="' . $project['id'] . '"' . ($project['id'] === intval($selected) ? ' selected' : '') . '>' . escapeHtml($project['label']) . '</option>';
        }
    }
    return $html;
}

/*
 * The Project field: label, picker of the client's open projects, and - when there are none - why
 * the list is empty, rather than a list with only "None" in it.
 */
function mitProcProjectField($name, $client_id, $selected, $label = 'Project') {
    $options = mitProcProjectOptions($client_id, $selected);
    $empty = substr_count($options, '<option') === 1;
    return '<label class="form-label">' . $label . '</label>'
        . '<select class="form-select select2" name="' . escapeHtml($name) . '" data-mit-project>' . $options . '</select>'
        . '<div class="form-text" data-mit-project-hint' . ($empty ? '' : ' hidden') . '>'
        . ($client_id ? 'This client has no open projects' : 'There are no open projects without a client')
        . ' - completed and archived projects are not listed.</div>';
}

/** Hook in add_quote_to_invoice, after each invoice line: a ticked line goes on the list to order. */
function mitProcFromQuoteLine($quote_item_id, $invoice_item_id, $invoice_id, $client_id, array $post) {
    global $mysqli;
    $line_post = $post['mit_proc_lines'][$quote_item_id] ?? null;
    if (!$line_post || empty($line_post['send']) || mitProcLevel() < 2) {
        return;
    }
    $quote_item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_quote_id, item_name, item_description, item_quantity, item_product_id FROM quote_items WHERE item_id = " . intval($quote_item_id)));
    if (!$quote_item) {
        return;
    }
    $extra = function_exists('mitQuoteItemExtra') ? mitQuoteItemExtra($quote_item['item_quote_id'], $quote_item_id) : null;
    $project_id = intval($post['mit_proc_project_id'] ?? 0);
    if ($project_id && intval(getFieldById('projects', $project_id, 'project_client_id')) !== intval($client_id)) {
        $project_id = 0;
    }
    $vendor_id = intval($line_post['vendor_id'] ?? 0);
    mitProcAddItem([
        'source' => 'quote', 'invoice_id' => $invoice_id, 'invoice_item_id' => $invoice_item_id, 'client_id' => $client_id, 'project_id' => $project_id,
        'product_id' => intval($quote_item['item_product_id']), 'vendor_id' => isset(mitProcVendors()[$vendor_id]) ? $vendor_id : 0,
        'name' => $quote_item['item_name'], 'description' => $quote_item['item_description'], 'quantity' => $quote_item['item_quantity'],
        'unit_cost' => $extra['item_cost'] ?? null,
    ]);
}

/*
 * ---------- an invoice -> procurement ----------
 */

/** How much of each of an invoice's lines is already on the list or ordered, invoice_item_id => qty. */
function mitProcInvoiceQueued($invoice_id) {
    global $mysqli;
    $queued = [];
    $result = mysqli_query($mysqli, "SELECT invoice_item_id, SUM(quantity) AS qty FROM mit_procurement_items WHERE invoice_id = " . intval($invoice_id) . " AND status <> 'cancelled' GROUP BY invoice_item_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $queued[intval($row['invoice_item_id'])] = (float) $row['qty'];
    }
    return $queued;
}

/** The invoice page's "Send to procurement" menu item (agent/invoice.php ⋮ menu). */
function mitProcInvoiceMenuItem($invoice_id) {
    if (mitProcLevel() < 2) {
        return '';
    }
    return '<a class="dropdown-item ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/mit_procurement/from_invoice.php?invoice_id=' . intval($invoice_id) . '">'
        . '<i class="fa fa-fw fa-cart-plus text-secondary me-2"></i>Send to procurement</a>';
}

/*
 * ---------- ticket parts ----------
 */

const MIT_PART_SOURCES = ['stock' => 'From stock', 'order' => 'To order', 'other' => 'On hand'];

/** A ticket's parts, with where each one is: its procurement item, PO and invoice. */
function mitTicketParts($ticket_id) {
    return mitTicketPartsWhere("parts.ticket_id = " . intval($ticket_id));
}

/**
 * Parts on tickets not billed yet, any ticket the agent can see (Procurement > Unbilled parts), oldest first, with
 * their ticket and client. $client_id narrows it to one client.
 */
function mitUnbilledParts($client_id = 0) {
    return mitTicketPartsWhere("(parts.invoice_id = 0 OR invoices.invoice_id IS NULL)" . (intval($client_id) ? " AND parts.client_id = " . intval($client_id) : '')
        . clientScopeSql('parts.client_id'), 'parts.created_at, parts.part_id');
}

/** How many parts are not billed yet (side menu badge). */
function mitUnbilledPartsCount() {
    global $mysqli;
    if (!mitProcReady() || !mitTicketPartsReady()) {
        return 0;
    }
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_ticket_parts parts
        LEFT JOIN invoices ON invoices.invoice_id = parts.invoice_id AND parts.invoice_id > 0
        WHERE (parts.invoice_id = 0 OR invoices.invoice_id IS NULL)" . clientScopeSql('parts.client_id')))[0] ?? 0);
}

/** Parts matching a WHERE (on parts / invoices), with where each one is and its ticket and client. */
function mitTicketPartsWhere($where, $order = 'parts.part_id') {
    global $mysqli;
    if (!mitProcReady() || !mitTicketPartsReady()) {
        return [];
    }
    $result = mysqli_query($mysqli, "SELECT parts.part_id, parts.ticket_id, parts.client_id, parts.product_id, parts.asset_id, parts.name, parts.description, parts.quantity, parts.unit_cost, parts.unit_price, parts.tax_id, parts.source, parts.proc_item_id, parts.invoice_id, parts.invoice_item_id, parts.billed_at, parts.created_by, parts.created_at, proc.status AS proc_status, po.po_id, po.po_prefix, po.po_number, po.status AS po_status, l.received_qty, l.quantity AS po_qty,
            invoice_prefix, invoice_number, invoice_status, asset_name,
            ticket_prefix, ticket_number, ticket_subject, ticket_closed_at, ticket_resolved_at, client_name
        FROM mit_ticket_parts parts
        LEFT JOIN tickets ON tickets.ticket_id = parts.ticket_id
        LEFT JOIN clients ON clients.client_id = parts.client_id
        LEFT JOIN mit_procurement_items proc ON proc.item_id = parts.proc_item_id
        LEFT JOIN mit_po_lines l ON l.proc_item_id = proc.item_id AND proc.item_id > 0
            AND l.po_id IN (SELECT po_id FROM mit_purchase_orders WHERE status <> 'cancelled')
        LEFT JOIN mit_purchase_orders po ON po.po_id = l.po_id
        LEFT JOIN invoices ON invoices.invoice_id = parts.invoice_id AND parts.invoice_id > 0
        LEFT JOIN assets ON assets.asset_id = parts.asset_id AND parts.asset_id > 0
        WHERE $where
        ORDER BY $order");
    $parts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Billed on an invoice that has since been deleted: it can be billed again
        if (intval($row['invoice_id']) && $row['invoice_number'] === null) {
            mysqli_query($mysqli, "UPDATE mit_ticket_parts SET invoice_id = 0, invoice_item_id = 0, billed_at = NULL WHERE part_id = " . intval($row['part_id']));
            $row['invoice_id'] = 0;
        }
        $parts[intval($row['part_id'])] = $row;
    }
    return $parts;
}

function mitTicketPartsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_parts'")) === 1;
    }
    return $ready;
}

/** Where a part is, as a badge (escaped HTML). */
function mitTicketPartStatus(array $part) {
    if ($part['source'] !== 'order') {
        return '<span class="badge rounded-pill text-bg-light border">' . MIT_PART_SOURCES[$part['source']] . '</span>';
    }
    if ($part['proc_status'] === 'cancelled') {
        return '<span class="badge rounded-pill text-bg-secondary">Not ordered</span>';
    }
    if (!$part['po_id']) {
        return '<span class="badge rounded-pill text-bg-warning">Waiting to order</span>';
    }
    $po_link = '<a href="mit_purchase_order.php?po_id=' . intval($part['po_id']) . '" class="small">' . escapeHtml($part['po_prefix'] . $part['po_number']) . '</a>';
    if ($part['proc_status'] === 'received') {
        return '<span class="badge rounded-pill text-bg-success">Received</span> ' . $po_link;
    }
    if ((float) $part['received_qty'] > 0) {
        return '<span class="badge rounded-pill text-bg-warning">' . mitQty($part['received_qty']) . ' of ' . mitQty($part['po_qty']) . ' in</span> ' . $po_link;
    }
    $label = in_array($part['po_status'], ['ordered', 'partial'], true) ? 'Ordered' : 'On a PO';
    return '<span class="badge rounded-pill text-bg-primary">' . $label . '</span> ' . $po_link;
}

/** The ticket page's Parts box (agent/ticket.php, side column, above Linked). */
function mitTicketPartsCard(array $ticket, $can_edit) {
    if (!mitProcOn() || !mitTicketPartsReady()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $parts = mitTicketParts($ticket_id);
    $can_bill = lookupUserPermission('module_sales') >= 2 && intval($ticket['ticket_client_id']);
    if (!$parts && !$can_edit) {
        return '';
    }
    $unbilled = array_filter($parts, function ($part) {
        return !intval($part['invoice_id']);
    });
    $html = '<div class="card mb-3"><div class="card-header px-3 py-2"><h5 class="card-title mt-1"><i class="fas fa-fw fa-microchip me-2"></i>Parts'
        . ($parts ? ' <span class="badge text-bg-light border ms-1">' . count($parts) . '</span>' : '') . '</h5><div class="card-tools">';
    if ($can_edit) {
        $html .= '<button type="button" class="btn btn-tool ajax-modal" title="Add a part" data-modal-url="modals/mit_procurement/part.php?ticket_id=' . $ticket_id . '"><i class="fas fa-plus"></i></button>';
    }
    $html .= '</div></div><div class="card-body p-0">';
    if (!$parts) {
        $html .= '<div class="px-3 py-3 small text-muted">No parts yet. Add one from stock, or one to order.</div>';
    }
    $html .= '<div class="list-group list-group-flush">';
    foreach ($parts as $part_id => $part) {
        $billed = intval($part['invoice_id']);
        $html .= '<div class="list-group-item px-3 py-2"><div class="d-flex align-items-start gap-2"><div class="flex-grow-1" style="min-width:0">'
            . '<div class="fw-semibold text-truncate" title="' . escapeHtml($part['name']) . '">' . mitQty($part['quantity']) . ' × ' . escapeHtml($part['name']) . '</div>'
            . '<div class="small d-flex flex-wrap gap-1 align-items-center mt-1">' . mitTicketPartStatus($part)
            . ($billed ? ' <a href="invoice.php?invoice_id=' . $billed . '" class="badge rounded-pill text-bg-success text-decoration-none">Billed ' . escapeHtml($part['invoice_prefix'] . $part['invoice_number']) . '</a>' : '')
            . '</div>' . ($part['asset_name'] ? '<div class="small text-muted mt-1"><i class="fas fa-desktop me-1"></i>' . escapeHtml($part['asset_name']) . '</div>' : '')
            . '</div><div class="text-end small text-nowrap">' . mitMoney($part['quantity'] * $part['unit_price']) . '</div>';
        if ($can_edit && !$billed) {
            $html .= '<button type="button" class="btn btn-sm btn-light ajax-modal" title="Change" data-modal-url="modals/mit_procurement/part.php?ticket_id=' . $ticket_id . '&part_id=' . $part_id . '"><i class="fas fa-pen"></i></button>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    if ($unbilled && $can_bill) {
        $total = array_sum(array_map(function ($part) {
            return $part['quantity'] * $part['unit_price'];
        }, $unbilled));
        $html .= '<div class="px-3 py-2 border-top d-flex align-items-center"><span class="small text-muted">' . count($unbilled) . ' not billed · ' . mitMoney($total) . '</span>'
            . '<button type="button" class="btn btn-sm btn-outline-success ms-auto ajax-modal" data-modal-url="modals/mit_procurement/parts_bill.php?ticket_id=' . $ticket_id . '"><i class="fas fa-file-invoice-dollar me-1"></i>Bill parts</button></div>';
    }
    return $html . '</div></div>';
}

/** Take (or put back, with a negative $qty) a tangible product from stock. */
function mitProductStockTake($product_id, $qty, $note) {
    global $mysqli;
    $product_id = intval($product_id);
    if (!mitProductIsTangible($product_id)) {
        return;
    }
    // product_stock.stock_qty is whole units (ITFlow's column), so part quantities are rounded for it
    mysqli_query($mysqli, "INSERT INTO product_stock SET stock_qty = " . (0 - round((float) $qty)) . ", stock_note = '" . escapeSql(mb_substr($note, 0, 500)) . "', stock_product_id = $product_id");
}

/** May the signed-in agent see what we pay (costs on ticket parts)? Procurement or Sales access. */
function mitPartsSeeCost() {
    return mitProcLevel() >= 1 || lookupUserPermission('module_sales') >= 1;
}

/** Products' stock on the shelf, product_id => qty, in one query. */
function mitProductStocks(array $product_ids) {
    global $mysqli;
    $product_ids = array_filter(array_map('intval', $product_ids));
    $stocks = [];
    if ($product_ids) {
        $result = mysqli_query($mysqli, "SELECT stock_product_id, SUM(stock_qty) AS qty FROM product_stock WHERE stock_product_id IN (" . implode(',', $product_ids) . ") GROUP BY stock_product_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $stocks[intval($row['stock_product_id'])] = (float) $row['qty'];
        }
    }
    return $stocks;
}

/** A product's stock on the shelf. */
function mitProductStock($product_id) {
    global $mysqli;
    return (float) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_product_id = " . intval($product_id)))[0];
}

/**
 * Add a part to a ticket, or change one not billed yet. Returns [ok, message, part_id]. A part from stock
 * comes off the product's stock; a part to order goes on the procurement list.
 */
function mitTicketPartSave(array $ticket, array $post, $part_id = 0) {
    global $mysqli, $session_user_id;
    $ticket_id = intval($ticket['ticket_id']);
    $client_id = intval($ticket['ticket_client_id']);
    $reference = ticketReference($ticket);
    $name = trim((string) ($post['name'] ?? ''));
    $qty = round((float) str_replace(',', '', (string) ($post['quantity'] ?? '0')), 2);
    if ($name === '' || $qty <= 0) {
        return [false, 'A part needs a name and a quantity.'];
    }
    $price = round((float) str_replace([',', '$'], '', (string) ($post['unit_price'] ?? '0')), 2);
    $source = isset(MIT_PART_SOURCES[$post['source'] ?? '']) ? $post['source'] : 'stock';
    $product_id = intval($post['product_id'] ?? 0);
    if (isset($post['unit_cost'])) {
        $cost_raw = trim(str_replace([',', '$'], '', (string) $post['unit_cost']));
        $cost = $cost_raw === '' || !is_numeric($cost_raw) || (float) $cost_raw < 0 ? null : round((float) $cost_raw, 2);
    } else {
        // No cost field (the agent cannot see costs - mitPartsSeeCost): the part keeps its cost, or takes the product's
        $old_cost = $part_id ? (mitTicketParts($ticket['ticket_id'])[intval($part_id)]['unit_cost'] ?? null) : null;
        $cost = $old_cost !== null ? (float) $old_cost : (mitProductsInfo([$product_id])[$product_id]['cost'] ?? null);
    }
    $tax_id = intval($post['tax_id'] ?? 0);
    $asset_id = intval($post['asset_id'] ?? 0);
    if ($asset_id && intval(getFieldById('assets', $asset_id, 'asset_client_id')) !== $client_id) {
        $asset_id = 0;
    }
    $vendor_id = intval($post['vendor_id'] ?? 0);
    $description = trim((string) ($post['description'] ?? ''));
    $fields = "name = '" . escapeSql(mb_substr($name, 0, 255)) . "', description = " . ($description === '' ? 'NULL' : "'" . escapeSql($description) . "'") . ",
        quantity = $qty, unit_cost = " . ($cost === null ? 'NULL' : $cost) . ", unit_price = $price, tax_id = $tax_id, asset_id = $asset_id";

    if ($part_id) {
        $old = mitTicketParts($ticket_id)[intval($part_id)] ?? null;
        if (!$old || intval($old['invoice_id'])) {
            return [false, 'That part is billed, or no longer on the ticket.'];
        }
        if ($old['source'] === 'order' && !in_array($old['proc_status'], [null, 'waiting', 'cancelled'], true) && ($source !== 'order' || (float) $qty !== (float) $old['quantity'])) {
            return [false, 'That part is already on a purchase order - change it there.'];
        }
        // What it was and what it is now can differ in source - undo the old one's effect first
        if ($old['source'] === 'stock') {
            mitProductStockTake($old['product_id'], -$old['quantity'], "Returned from ticket $reference (part changed)");
        }
        if ($old['source'] === 'order' && $old['proc_status'] === 'waiting' && $source !== 'order') {
            mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'cancelled' WHERE item_id = " . intval($old['proc_item_id']));
        }
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET $fields, source = '$source', product_id = $product_id WHERE part_id = " . intval($part_id));
    } else {
        mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket_id, client_id = $client_id, product_id = $product_id, source = '$source', $fields, created_by = " . intval($session_user_id));
        $part_id = mysqli_insert_id($mysqli);
        $old = null;
    }

    $warning = '';
    if ($source === 'stock' && mitProductIsTangible($product_id)) {
        $left = mitProductStock($product_id) - round($qty);
        mitProductStockTake($product_id, $qty, "Used on ticket $reference");
        if ($left < 0) {
            $warning = ' Stock for this product is now ' . mitQty($left) . ' - check the shelf count.';
        }
    }
    if ($source === 'order') {
        $proc_status = $old['proc_status'] ?? null;
        if ($old && $old['source'] === 'order' && $proc_status === 'waiting') {
            mysqli_query($mysqli, "UPDATE mit_procurement_items SET name = '" . escapeSql(mb_substr($name, 0, 255)) . "', description = " . ($description === '' ? 'NULL' : "'" . escapeSql($description) . "'") . ", quantity = $qty, unit_cost = " . ($cost === null ? 'NULL' : $cost) . ",
                vendor_id = " . (isset(mitProcVendors()[$vendor_id]) ? $vendor_id : 0) . " WHERE item_id = " . intval($old['proc_item_id']));
        } elseif (!$old || $old['source'] !== 'order' || $proc_status === 'cancelled') {
            $proc_item_id = mitProcAddItem(['source' => 'ticket', 'ticket_id' => $ticket_id, 'client_id' => $client_id, 'project_id' => intval($ticket['ticket_project_id'] ?? 0),
                'product_id' => $product_id, 'vendor_id' => isset(mitProcVendors()[$vendor_id]) ? $vendor_id : 0, 'name' => $name, 'description' => $description,
                'quantity' => $qty, 'unit_cost' => $cost, 'note' => "Part for ticket $reference"]);
            mysqli_query($mysqli, "UPDATE mit_ticket_parts SET proc_item_id = $proc_item_id WHERE part_id = " . intval($part_id));
        }
    }
    $what = mitQty($qty) . " × $name (" . strtolower(MIT_PART_SOURCES[$source]) . ')';
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql(($old ? 'Part changed: ' : 'Part added: ') . $what) . "', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
        ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logTicketHistory($ticket_id, escapeSql(mitProcWho() . ($old ? ' changed part ' : ' added part ') . $what));
    logAudit('Ticket', 'Edit', escapeSql(mitProcWho() . ($old ? ' changed part ' : ' added part ') . "$what on ticket $reference"), $client_id, $ticket_id);
    return [true, ($old ? 'Part saved' : 'Part added') . ($source === 'order' ? ' - it is on the list to order' : '') . '.' . $warning, intval($part_id)];
}

/** Take a part off a ticket (not billed): stock goes back, a part still waiting to order comes off the list. */
function mitTicketPartRemove(array $ticket, $part_id) {
    global $mysqli, $session_user_id;
    $part = mitTicketParts($ticket['ticket_id'])[intval($part_id)] ?? null;
    if (!$part || intval($part['invoice_id'])) {
        return [false, 'That part is billed, or no longer on the ticket.'];
    }
    if ($part['source'] === 'order' && !in_array($part['proc_status'], [null, 'waiting', 'cancelled'], true)) {
        return [false, 'That part is on a purchase order - take it off the PO first.'];
    }
    if ($part['source'] === 'stock') {
        mitProductStockTake($part['product_id'], -$part['quantity'], 'Returned from ticket ' . ticketReference($ticket));
    }
    if ($part['source'] === 'order' && $part['proc_status'] === 'waiting') {
        mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'cancelled' WHERE item_id = " . intval($part['proc_item_id']));
    }
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE part_id = " . intval($part_id));
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql('Part removed: ' . mitQty($part['quantity']) . ' × ' . $part['name']) . "', ticket_reply_type = 'Internal',
        ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = " . intval($ticket['ticket_id']));
    logAudit('Ticket', 'Edit', escapeSql(mitProcWho() . ' removed part ' . mitQty($part['quantity']) . ' × ' . $part['name'] . ' from ticket ' . ticketReference($ticket)), intval($ticket['ticket_client_id']), intval($ticket['ticket_id']));
    return [true, 'Part removed'];
}

/**
 * Bill parts: onto a new draft invoice ($invoice_id 0) or one of the client's drafts. $prices =
 * [part_id => price] (the window's edits). Returns [invoice_id, message] (invoice_id 0 on failure).
 */
function mitTicketPartsBill(array $ticket, array $part_ids, array $prices, $invoice_id, $date, $category_id) {
    global $mysqli, $config_invoice_prefix, $config_default_net_terms, $session_company_currency, $session_user_id;
    $ticket_id = intval($ticket['ticket_id']);
    $client_id = intval($ticket['ticket_client_id']);
    if (!$client_id) {
        return [0, 'Move the ticket to a client before billing it.'];
    }
    $parts = array_filter(mitTicketParts($ticket_id), function ($part) use ($part_ids) {
        return in_array(intval($part['part_id']), array_map('intval', $part_ids), true) && !intval($part['invoice_id']);
    });
    if (!$parts) {
        return [0, 'Tick the parts to bill.'];
    }
    $invoice_id = intval($invoice_id);
    if ($invoice_id) {
        $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_prefix, invoice_number FROM invoices WHERE invoice_id = $invoice_id AND invoice_client_id = $client_id AND invoice_status = 'Draft'"));
        if (!$invoice) {
            return [0, "That invoice isn't one of this client's drafts."];
        }
    } else {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? $date : date('Y-m-d');
        $net_terms = intval(getFieldById('clients', $client_id, 'client_net_terms')) ?: intval($config_default_net_terms);
        // The same numbering ITFlow uses (agent/post/ticket.php add_invoice_from_ticket)
        mysqli_query($mysqli, "UPDATE settings SET config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number), config_invoice_next_number = config_invoice_next_number + 1 WHERE company_id = 1");
        $number = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = '" . escapeSql($config_invoice_prefix) . "', invoice_number = $number, invoice_scope = '" . escapeSql('Parts for ticket ' . ticketReference($ticket)) . "',
            invoice_date = '$date', invoice_due = DATE_ADD('$date', INTERVAL $net_terms day), invoice_currency_code = '" . escapeSql($session_company_currency) . "',
            invoice_category_id = " . intval($category_id) . ", invoice_status = 'Draft', invoice_url_key = '" . randomString(32) . "', invoice_client_id = $client_id");
        $invoice_id = mysqli_insert_id($mysqli);
        $invoice = ['invoice_prefix' => $config_invoice_prefix, 'invoice_number' => $number];
        mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = '" . escapeSql('Invoice created from the parts on ticket ' . ticketReference($ticket)) . "', history_invoice_id = $invoice_id");
    }
    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(item_order), 0) FROM invoice_items WHERE item_invoice_id = $invoice_id"))[0]);
    foreach ($parts as $part_id => $part) {
        $price = isset($prices[$part_id]) && is_numeric(str_replace(',', '', (string) $prices[$part_id])) ? round((float) str_replace(',', '', (string) $prices[$part_id]), 2) : (float) $part['unit_price'];
        $qty = (float) $part['quantity'];
        $subtotal = round($price * $qty, 2);
        $tax_percent = intval($part['tax_id']) ? (float) mysqli_fetch_row(mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = " . intval($part['tax_id'])))[0] : 0;
        $tax = round($subtotal * $tax_percent / 100, 2);
        // No item_product_id: stock was already taken when the part was used
        mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '" . escapeSql($part['name']) . "', item_description = '" . escapeSql((string) $part['description']) . "',
            item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax, item_total = " . ($subtotal + $tax) . ", item_order = " . (++$order) . ",
            item_tax_id = " . intval($part['tax_id']) . ", item_invoice_id = $invoice_id");
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET invoice_id = $invoice_id, invoice_item_id = " . mysqli_insert_id($mysqli) . ", unit_price = $price, billed_at = NOW() WHERE part_id = " . intval($part_id));
    }
    // The invoice's amount: its lines less its discount (as ITFlow's add-item does)
    mysqli_query($mysqli, "UPDATE invoices SET invoice_amount = (SELECT COALESCE(SUM(item_total), 0) FROM invoice_items WHERE item_invoice_id = $invoice_id) - invoice_discount_amount WHERE invoice_id = $invoice_id");
    $reference = $invoice['invoice_prefix'] . $invoice['invoice_number'];
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mitSqlHtml('Billed ' . count($parts) . ' part' . (count($parts) === 1 ? '' : 's') . ' on <a href="invoice.php?invoice_id=' . $invoice_id . '">' . escapeHtml($reference) . '</a>.') . "',
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logAudit('Invoice', 'Edit', escapeSql(mitProcWho() . ' billed ' . count($parts) . ' part(s) from ticket ' . ticketReference($ticket) . " on $reference"), $client_id, $invoice_id);
    return [$invoice_id, count($parts) . ' part' . (count($parts) === 1 ? '' : 's') . ' billed on ' . escapeHtml($reference)];
}

/*
 * ---------- tidying up after ITFlow deletes things (cron/mit_procurement_tidy.php) ----------
 */

/**
 * Hook before ITFlow deletes a ticket (functions/ticket.php ticketDeleteForGood, and the client
 * delete in agent/post/client.php): parts taken from stock and not yet billed go back on the shelf.
 * The part rows themselves go with the ticket (ON DELETE CASCADE).
 */
function mitTicketPartsDeleting($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if (!$ticket_id || !mitTicketPartsReady()) {
        return;
    }
    $result = mysqli_query($mysqli, "SELECT product_id, quantity FROM mit_ticket_parts
        WHERE ticket_id = $ticket_id AND source = 'stock' AND invoice_id = 0 AND product_id > 0");
    while ($part = mysqli_fetch_assoc($result)) {
        mitProductStockTake($part['product_id'], -$part['quantity'], "Returned - ticket $ticket_id was deleted");
    }
}

/**
 * ITFlow deletes clients and invoice lines without knowing about our rows, so once a day:
 *   - items still waiting to order for a ticket or client that is gone: cancelled (one already on a PO stays,
 *     as the order is real);
 *   - parts billed on an invoice line that is gone: free to bill again.
 * Safe to run any number of times. Returns lines for the log.
 */
function mitProcTidy() {
    global $mysqli;
    $log = [];
    if (!mitProcReady() || !mitTicketPartsReady()) {
        return $log;
    }
    // A deleted ticket's parts go with it (foreign key, ON DELETE CASCADE) after mitTicketPartsDeleting
    // has put their stock back - so only the items to order and invoice links are left to tidy here
    mysqli_query($mysqli, "UPDATE mit_procurement_items proc SET status = 'cancelled', note = CONCAT_WS(' - ', NULLIF(note, ''), 'Its ticket or client was deleted')
        WHERE status = 'waiting' AND ((ticket_id > 0 AND NOT EXISTS (SELECT 1 FROM tickets WHERE tickets.ticket_id = proc.ticket_id))
            OR (client_id > 0 AND NOT EXISTS (SELECT 1 FROM clients WHERE clients.client_id = proc.client_id)))");
    if (($n = mysqli_affected_rows($mysqli)) > 0) {
        $log[] = "Cancelled $n item(s) to order for deleted tickets or clients";
    }
    mysqli_query($mysqli, "UPDATE mit_ticket_parts parts SET invoice_id = 0, invoice_item_id = 0, billed_at = NULL
        WHERE invoice_item_id > 0 AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_items.item_id = parts.invoice_item_id)");
    if (($n = mysqli_affected_rows($mysqli)) > 0) {
        $log[] = "Freed $n part(s) whose invoice line was deleted";
    }
    return $log;
}
