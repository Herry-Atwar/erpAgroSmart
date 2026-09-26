<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();
$page_title = "Sales Management";
require_once 'includes/header.php';

// Get filters
$year         = get('year',       date('Y'));
$month        = get('month',      'all');   // default: all months
$company_filter = get('company_id', '');
$product_type = get('product_type', 'all');
$search       = get('search', '');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete_sale') {
        try {
            $sale_id = post('sale_id');
            $db->prepare("DELETE FROM sale_order_line WHERE order_id = ?")->execute([$sale_id]);
            $db->prepare("DELETE FROM sale_order WHERE id = ?")->execute([$sale_id]);
            $success_message = "Sale deleted successfully!";
        } catch (PDOException $e) {
            $error_message = "Error deleting sale: " . $e->getMessage();
        }
    }

    elseif ($action === 'update_sale') {
        try {
            $sale_id    = post('sale_id');
            $qty        = (float) post('quantity_kg');
            $unit_price = (float) post('unit_price');

            $db->prepare("
                UPDATE sale_order SET
                    date_order        = ?,
                    company_id        = ?,
                    partner_id        = ?,
                    amount_untaxed    = ?,
                    amount_total      = ?,
                    currency          = ?,
                    payment_status    = ?,
                    delivery_location = ?,
                    note              = ?,
                    write_date        = NOW()
                WHERE id = ?
            ")->execute([
                post('sale_date'),
                post('company_id'),
                post('partner_id'),
                $qty * $unit_price,
                $qty * $unit_price,
                post('currency', 'IDR'),
                post('payment_status'),
                post('delivery_location'),
                post('notes'),
                $sale_id,
            ]);

            $db->prepare("
                UPDATE sale_order_line SET
                    product_type     = ?,
                    product_uom_qty  = ?,
                    price_unit       = ?,
                    price_subtotal   = ?,
                    price_total      = ?,
                    write_date       = NOW()
                WHERE order_id = ?
            ")->execute([
                post('product_type'),
                $qty,
                $unit_price,
                $qty * $unit_price,
                $qty * $unit_price,
                $sale_id,
            ]);

            $success_message = "Sale updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating sale: " . $e->getMessage();
        }
    }

    elseif ($action === 'create_sale') {
        try {
            $qty        = (float) post('quantity_kg');
            $unit_price = (float) post('unit_price');
            $total      = $qty * $unit_price;

            $stmt = $db->prepare("
                INSERT INTO sale_order (
                    name, date_order, company_id, partner_id,
                    amount_untaxed, amount_tax, amount_total,
                    currency, payment_status, delivery_location,
                    state, invoice_status, note, create_date, write_date
                ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'draft', 'nothing', ?, NOW(), NOW())
            ");
            $stmt->execute([
                post('invoice_number'),
                post('sale_date'),
                post('company_id'),
                post('partner_id'),
                $total,
                $total,
                post('currency', 'IDR'),
                'pending',
                post('delivery_location'),
                post('notes'),
            ]);

            $order_id = $db->lastInsertId();

            $db->prepare("
                INSERT INTO sale_order_line (
                    order_id, product_type, name, product_uom_qty,
                    qty_delivered, qty_invoiced, price_unit,
                    price_subtotal, price_total, create_date, write_date
                ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $order_id,
                post('product_type'),
                post('product_type') . ' - ' . post('invoice_number'),
                $qty,
                $unit_price,
                $total,
                $total,
            ]);

            $success_message = "Sale created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// Fetch data
$companies = $db->query("SELECT * FROM companies ORDER BY company_code")->fetchAll();
$partners = $db->query("
    SELECT id, name, phone, email, city
    FROM res_partner
    WHERE customer_rank > 0 AND active = TRUE
    ORDER BY name
")->fetchAll();

// No sales_contracts table — return empty array so dropdown shows "No Contract"
$contracts = [];

// Build reusable date WHERE clause
// When month = 'all', only filter by year; otherwise filter by year+month
$date_where  = $month === 'all'
    ? " EXTRACT(YEAR FROM so.date_order) = ?"
    : " EXTRACT(YEAR FROM so.date_order) = ? AND EXTRACT(MONTH FROM so.date_order) = ?";
$date_params = $month === 'all' ? [$year] : [$year, $month];

// Sales summary (from sale_order + sale_order_line)
$summary_sql = "
    SELECT sol.product_type,
           COUNT(DISTINCT so.id)        AS count,
           SUM(sol.product_uom_qty)     AS total_kg,
           SUM(so.amount_total)         AS revenue
    FROM sale_order so
    JOIN sale_order_line sol ON sol.order_id = so.id
    WHERE $date_where
";
$params = $date_params;
if ($company_filter) {
    $summary_sql .= " AND so.company_id = ?";
    $params[] = $company_filter;
}
$summary_sql .= " GROUP BY sol.product_type";
$sales_summary = $db->prepare($summary_sql);
$sales_summary->execute($params);
$summary = $sales_summary->fetchAll();

// Detailed sales
$sales_sql = "
    SELECT so.id          AS sale_id,
           so.name        AS invoice_number,
           so.date_order  AS sale_date,
           so.company_id,
           so.partner_id,
           so.amount_total AS total_amount,
           so.payment_status,
           so.delivery_location,
           so.note        AS notes,
           so.currency,
           so.state,
           sol.product_type,
           sol.product_uom_qty AS quantity_kg,
           sol.price_unit,
           c.company_name,
           p.name         AS partner_name,
           NULL::text     AS contract_number,
           NULL::text     AS contract_status,
           COALESCE(so.note, '')       AS payment_terms
    FROM sale_order so
    JOIN sale_order_line sol ON sol.order_id = so.id
    JOIN companies  c   ON so.company_id  = c.company_id
    JOIN res_partner p  ON so.partner_id  = p.id
    WHERE $date_where
";
$sales_params = $date_params;
if ($company_filter) {
    $sales_sql .= " AND so.company_id = ?";
    $sales_params[] = $company_filter;
}
if ($product_type !== 'all') {
    $sales_sql .= " AND sol.product_type = ?";
    $sales_params[] = $product_type;
}
if ($search) {
    $sales_sql .= " AND (so.name ILIKE ? OR p.name ILIKE ? OR c.company_name ILIKE ?)";
    $search_term = "%$search%";
    $sales_params[] = $search_term;
    $sales_params[] = $search_term;
    $sales_params[] = $search_term;
}
$sales_sql .= " ORDER BY so.date_order DESC";
$sales_stmt = $db->prepare($sales_sql);
$sales_stmt->execute($sales_params);
$sales = $sales_stmt->fetchAll();

$total_revenue  = array_sum(array_column($sales, 'total_amount'));
$total_quantity = array_sum(array_column($sales, 'quantity_kg'));
?>

<div class="container-fluid mt-4">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <h1 style="color: #166c82;"><i class="bi bi-cart-check" style="color: #166c82;"></i> Sales Management</h1>
                <p class="text-muted">Track and manage CPO, Kernel, and FFB sales transactions</p>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-custom-sales" data-bs-toggle="modal" data-bs-target="#createSaleModal">
                    <i class="bi bi-plus-circle"></i> Create New Sale
                </button>
            </div>
        </div>
    </div>

    <?php
    $filter_label = $month === 'all'
        ? "All Months — $year"
        : date('F Y', mktime(0, 0, 0, (int)$month, 1, (int)$year));
    ?>
    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> Filters — <?= $filter_label ?></h5>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <form method="GET" class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" placeholder="Search invoice, customer, company..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select">
                        <option value="all" <?= $month === 'all' ? 'selected' : '' ?>>— All Months —</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>" <?= $m == $month ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= $c['company_id'] ?>" <?= $c['company_id'] == $company_filter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product</label>
                    <select name="product_type" class="form-select">
                        <option value="all">All Products</option>
                        <option value="FFB" <?= $product_type == 'FFB' ? 'selected' : '' ?>>FFB</option>
                        <option value="CPO" <?= $product_type == 'CPO' ? 'selected' : '' ?>>CPO</option>
                        <option value="Kernel" <?= $product_type == 'Kernel' ? 'selected' : '' ?>>Kernel</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-custom-sales w-100"><i class="bi bi-search"></i> Search</button>
                </div>
            </form>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="text-muted">Total Sales</h6>
                            <h4 style="color: #166c82;"><?= count($sales) ?> transactions</h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="text-muted">Total Quantity</h6>
                            <h4 class="text-primary"><?= number_format($total_quantity, 0) ?> kg</h4>
                            <small class="text-muted"><?= number_format($total_quantity/1000, 2) ?> MT</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="text-muted">Total Revenue</h6>
                            <h4 style="color: #166c82;">Rp <?= number_format($total_revenue, 0, ',', '.') ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary by Product -->
            <?php if (!empty($summary)): ?>
            <div class="table-responsive mb-4">
                <h6>Sales Summary by Product</h6>
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Transactions</th>
                            <th class="text-end">Quantity (kg)</th>
                            <th class="text-end">Quantity (MT)</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $s): ?>
                        <tr>
                            <td><strong><?= $s['product_type'] ?></strong></td>
                            <td class="text-end"><?= $s['count'] ?></td>
                            <td class="text-end"><?= number_format($s['total_kg'], 0) ?></td>
                            <td class="text-end"><?= number_format($s['total_kg']/1000, 2) ?></td>
                            <td class="text-end">Rp <?= number_format($s['revenue'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sales List -->
    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <i class="bi bi-list"></i> Sales Transactions (<?= count($sales) ?> transactions)
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th>Company</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th class="text-end">Quantity (kg)</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                        <tr><td colspan="11" class="text-center text-muted">No sales records</td></tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($sale['sale_date'])) ?></td>
                                <td><?= htmlspecialchars($sale['invoice_number']) ?></td>
                                <td><?= htmlspecialchars($sale['company_name']) ?></td>
                                <td><?= htmlspecialchars($sale['partner_name']) ?></td>
                                <td><span class="badge bg-info"><?= $sale['product_type'] ?></span></td>
                                <td class="text-end"><?= number_format($sale['quantity_kg'], 0) ?></td>
                                <td class="text-end">Rp <?= number_format($sale['price_unit'] ?? 0, 0) ?></td>
                                <td class="text-end">Rp <?= number_format($sale['total_amount'], 0, ',', '.') ?></td>
                                <td><?= $sale['payment_terms'] ?></td>
                                <td>
                                    <?php if ($sale['payment_status'] == 'paid'): ?>
                                        <span class="badge" style="background-color: #166c82;">Paid</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><?= ucfirst($sale['payment_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" onclick="viewSale(<?= $sale['sale_id'] ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-warning" onclick="editSale(<?= $sale['sale_id'] ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteSale(<?= $sale['sale_id'] ?>, '<?= htmlspecialchars($sale['invoice_number']) ?>')" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Sale Modal -->
<div class="modal fade" id="createSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Sale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_sale">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sale Date *</label>
                            <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number *</label>
                            <input type="text" name="invoice_number" class="form-control" placeholder="INV-<?= date('Ymd') ?>-001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company *</label>
                            <select name="company_id" class="form-select" required>
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer *</label>
                            <select name="partner_id" class="form-select" id="partner_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($partners as $partner): ?>
                                    <option value="<?= $partner['id'] ?>"><?= htmlspecialchars($partner['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Link to Contract (Optional)</label>
                            <select name="contract_id" class="form-select" id="contract_id" onchange="updateFromContract()">
                                <option value="">No Contract (Spot Sale)</option>
                                <?php foreach ($contracts as $contract): ?>
                                    <option value="<?= $contract['contract_id'] ?>"
                                            data-product="<?= $contract['product_type'] ?>"
                                            data-price="<?= $contract['base_price_per_kg'] ?>"
                                            data-remaining="<?= $contract['remaining_quantity_kg'] ?>"
                                            data-partner="<?= $contract['partner_id'] ?>"
                                            data-pricing="<?= $contract['pricing_type'] ?>">
                                        <?= htmlspecialchars($contract['contract_number']) ?> -
                                        <?= $contract['product_type'] ?> -
                                        <?= htmlspecialchars($contract['partner_name']) ?>
                                        (Remaining: <?= number_format($contract['remaining_quantity_kg'], 0) ?> kg)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select a contract to auto-fill product type and price</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" class="form-select" id="product_type" required>
                                <option value="FFB">FFB (Fresh Fruit Bunch)</option>
                                <option value="CPO">CPO (Crude Palm Oil)</option>
                                <option value="Kernel">Palm Kernel</option>
                                <option value="PKO">PKO (Palm Kernel Oil)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity (kg) *</label>
                            <input type="number" name="quantity_kg" class="form-control" id="quantity_kg" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit Price (per kg) *</label>
                            <input type="number" name="unit_price" class="form-control" id="unit_price" step="0.01" required>
                            <small class="text-muted" id="price_info"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms *</label>
                            <select name="payment_terms" class="form-select" required>
                                <option value="Cash">Cash</option>
                                <option value="Credit 7 days">Credit 7 days</option>
                                <option value="Credit 14 days">Credit 14 days</option>
                                <option value="Credit 30 days">Credit 30 days</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">Create Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Sale Modal -->
<div class="modal fade" id="editSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action"  value="update_sale">
                <input type="hidden" name="sale_id" id="edit_sale_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Sale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sale Date *</label>
                            <input type="date" name="sale_date" id="edit_sale_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" id="edit_invoice_number" class="form-control" disabled>
                            <small class="text-muted">Invoice number cannot be changed</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company *</label>
                            <select name="company_id" id="edit_company_id" class="form-select" required>
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= $c['company_id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer *</label>
                            <select name="partner_id" id="edit_partner_id" class="form-select" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($partners as $partner): ?>
                                    <option value="<?= $partner['id'] ?>"><?= htmlspecialchars($partner['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" id="edit_product_type" class="form-select" required>
                                <option value="FFB">FFB (Fresh Fruit Bunch)</option>
                                <option value="CPO">CPO (Crude Palm Oil)</option>
                                <option value="Kernel">Palm Kernel</option>
                                <option value="PKO">PKO (Palm Kernel Oil)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity (kg) *</label>
                            <input type="number" name="quantity_kg" id="edit_quantity_kg" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit Price (per kg) *</label>
                            <input type="number" name="unit_price" id="edit_unit_price" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" id="edit_payment_status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <select name="currency" id="edit_currency" class="form-select">
                                <option value="IDR">IDR</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Delivery Location</label>
                            <input type="text" name="delivery_location" id="edit_delivery_location" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #166c82; color: white;">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.btn-custom-sales {
    background-color: #166c82;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 0.25rem;
    transition: all 0.3s ease;
}

.btn-custom-sales:hover {
    background-color: #1a7d9a;
    color: white;
}

.btn-custom-sales:active {
    background-color: #145a6d;
}

.page-header {
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e9ecef;
}

.page-header h1 {
    font-size: 2rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.page-header p {
    font-size: 1rem;
    margin-bottom: 0;
}
</style>

<script>
// View sale details
function viewSale(saleId) {
    // TODO: Implement view modal with full sale details
    alert('View sale details for ID: ' + saleId + '\n\nThis feature will show:\n- Full sale information\n- Contract details (if linked)\n- Payment history\n- Delivery information');
}

// Edit sale — embed row data as JSON to avoid extra AJAX round-trip
const salesData = <?php echo json_encode(array_column($sales, null, 'sale_id')); ?>;

function editSale(saleId) {
    const s = salesData[saleId];
    if (!s) { alert('Sale data not found. Please refresh the page.'); return; }

    document.getElementById('edit_sale_id').value           = s.sale_id;
    document.getElementById('edit_invoice_number').value    = s.invoice_number;
    document.getElementById('edit_sale_date').value         = s.sale_date ? s.sale_date.substring(0, 10) : '';
    document.getElementById('edit_company_id').value        = s.company_id;
    document.getElementById('edit_partner_id').value        = s.partner_id;
    document.getElementById('edit_product_type').value      = s.product_type;
    document.getElementById('edit_quantity_kg').value       = s.quantity_kg;
    document.getElementById('edit_unit_price').value        = s.price_unit;
    document.getElementById('edit_payment_status').value    = s.payment_status;
    document.getElementById('edit_currency').value          = s.currency || 'IDR';
    document.getElementById('edit_delivery_location').value = s.delivery_location || '';
    document.getElementById('edit_notes').value             = s.notes || '';

    const modal = new bootstrap.Modal(document.getElementById('editSaleModal'));
    modal.show();
}

// Delete sale
function deleteSale(saleId, invoiceNumber) {
    if (confirm('Are you sure you want to delete sale:\n' + invoiceNumber + '?\n\nThis action cannot be undone.')) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'sales.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_sale';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'sale_id';
        idInput.value = saleId;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-fill form when contract is selected
function updateFromContract() {
    const contractSelect = document.getElementById('contract_id');
    const selectedOption = contractSelect.options[contractSelect.selectedIndex];
    
    if (contractSelect.value) {
        // Get contract data from option attributes
        const productType = selectedOption.getAttribute('data-product');
        const basePrice = selectedOption.getAttribute('data-price');
        const remaining = selectedOption.getAttribute('data-remaining');
        const pricingType = selectedOption.getAttribute('data-pricing');
        
        // Update product type
        document.getElementById('product_type').value = productType;
        
        // Update price if fixed pricing
        if (pricingType === 'fixed' && basePrice) {
            document.getElementById('unit_price').value = basePrice;
            document.getElementById('price_info').textContent = 'Contract price: Rp ' + parseFloat(basePrice).toLocaleString('id-ID');
        } else if (pricingType === 'market_linked') {
            document.getElementById('price_info').textContent = 'Market-linked pricing - enter current market price';
        } else if (pricingType === 'formula_based') {
            document.getElementById('price_info').textContent = 'Formula-based pricing - calculate price based on contract formula';
        }
        
        // Show remaining quantity warning
        const quantityInput = document.getElementById('quantity_kg');
        if (quantityInput) {
            quantityInput.setAttribute('max', remaining);
            quantityInput.setAttribute('placeholder', 'Max: ' + parseFloat(remaining).toLocaleString('id-ID') + ' kg');
        }
    } else {
        // Clear auto-filled values
        document.getElementById('price_info').textContent = '';
        const quantityInput = document.getElementById('quantity_kg');
        if (quantityInput) {
            quantityInput.removeAttribute('max');
            quantityInput.setAttribute('placeholder', '');
        }
    }
}

// Validate quantity against contract remaining
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('#createSaleModal form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const contractSelect = document.getElementById('contract_id');
            const quantityInput = document.getElementById('quantity_kg');
            
            if (contractSelect.value && quantityInput) {
                const selectedOption = contractSelect.options[contractSelect.selectedIndex];
                const remaining = parseFloat(selectedOption.getAttribute('data-remaining'));
                const quantity = parseFloat(quantityInput.value);
                
                if (quantity > remaining) {
                    e.preventDefault();
                    alert('Quantity (' + quantity.toLocaleString('id-ID') + ' kg) exceeds contract remaining quantity (' + remaining.toLocaleString('id-ID') + ' kg)');
                    return false;
                }
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob

<!-- View Sale Modal -->
<div class="modal fade" id="viewSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Sale Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewSaleContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Sale Modal -->
<div class="modal fade" id="editSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editSaleForm">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Edit Sale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editSaleContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save"></i> Update Sale
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
