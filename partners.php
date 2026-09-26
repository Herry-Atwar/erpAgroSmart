<?php
$page_title = "Partner Management";
require_once 'includes/header.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'create':
                $stmt = $pdo->prepare("
                    INSERT INTO res_partner (
                        name, is_company, email, phone,
                        street, city, zip, country_id, vat, comment, active, create_uid, write_uid
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['company_type'] === 'company' ? 't' : 'f',
                    $_POST['email'] ?? null,
                    $_POST['phone'] ?? null,
                    $_POST['street'] ?? null,
                    $_POST['city'] ?? null,
                    $_POST['zip'] ?? null,
                    $_POST['country_id'] ?? null,
                    $_POST['vat'] ?? null,
                    $_POST['comment'] ?? null,
                    $_SESSION['user_id'],
                    $_SESSION['user_id']
                ]);
                $result = $stmt->fetch();
                echo json_encode(['success' => true, 'id' => $result['id']]);
                exit;
                
            case 'update':
                $stmt = $pdo->prepare("
                    UPDATE res_partner SET
                        name = ?, is_company = ?,
                        email = ?, phone = ?, street = ?, city = ?,
                        zip = ?, country_id = ?, vat = ?, comment = ?,
                        write_uid = ?, write_date = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['company_type'] === 'company' ? 't' : 'f',
                    $_POST['email'] ?? null,
                    $_POST['phone'] ?? null,
                    $_POST['street'] ?? null,
                    $_POST['city'] ?? null,
                    $_POST['zip'] ?? null,
                    $_POST['country_id'] ?? null,
                    $_POST['vat'] ?? null,
                    $_POST['comment'] ?? null,
                    $_SESSION['user_id'],
                    $_POST['partner_id']
                ]);
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete':
                // Soft delete by setting active = false (Odoo standard)
                $stmt = $pdo->prepare("UPDATE res_partner SET active = FALSE WHERE id = ?");
                $stmt->execute([$_POST['partner_id']]);
                echo json_encode(['success' => true]);
                exit;
                
            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM res_partner WHERE id = ?");
                $stmt->execute([$_POST['partner_id']]);
                $partner = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'partner' => $partner]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? 'all';

// Build query
$where_conditions = ["active = TRUE"];
$params = [];

// Add category filter
if ($category_filter === 'customers') {
    $where_conditions[] = "customer_rank > 0";
} elseif ($category_filter === 'suppliers') {
    $where_conditions[] = "supplier_rank > 0";
} elseif ($category_filter === 'employees') {
    $where_conditions[] = "employee = TRUE";
}

if ($search) {
    $where_conditions[] = "(name ILIKE ? OR email ILIKE ? OR phone ILIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

$where_clause = implode(' AND ', $where_conditions);

// Get partners from Odoo's res_partner table
$sql = "
    SELECT
        id,
        name,
        is_company,
        email,
        phone,
        street,
        city,
        zip,
        vat,
        comment,
        employee,
        customer_rank,
        supplier_rank,
        create_date,
        write_date
    FROM res_partner
    WHERE $where_clause
    ORDER BY name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$partners = $stmt->fetchAll();

// Get statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE active = TRUE) as total_partners,
        COUNT(*) FILTER (WHERE customer_rank > 0 AND active = TRUE) as total_customers,
        COUNT(*) FILTER (WHERE supplier_rank > 0 AND active = TRUE) as total_suppliers,
        COUNT(*) FILTER (WHERE employee = TRUE AND active = TRUE) as total_employees
    FROM res_partner
")->fetch();
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 style="color: #166c82;"><i class="bi bi-people"></i> Partner Management</h2>
                    <p class="text-muted">Manage customers, suppliers, and business contacts</p>
                </div>
                <button class="btn btn-success" onclick="showPartnerModal()" style="background-color: #2e7d32; border-color: #2e7d32;">
                    <i class="bi bi-plus-circle"></i> Add New Partner
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #2e7d32;">
                <div class="card-body">
                    <h6 class="card-title">Total Partners</h6>
                    <h3 class="mb-0"><?= number_format($stats['total_partners']) ?></h3>
                    <small>Active partners</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #1976d2;">
                <div class="card-body">
                    <h6 class="card-title">Customers</h6>
                    <h3 class="mb-0"><?= number_format($stats['total_customers']) ?></h3>
                    <small>Customer rank > 0</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #f57c00;">
                <div class="card-body">
                    <h6 class="card-title">Suppliers</h6>
                    <h3 class="mb-0"><?= number_format($stats['total_suppliers']) ?></h3>
                    <small>Supplier rank > 0</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #7b1fa2;">
                <div class="card-body">
                    <h6 class="card-title">Employees</h6>
                    <h3 class="mb-0"><?= number_format($stats['total_employees']) ?></h3>
                    <small>Employee status</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="all" <?= $category_filter === 'all' ? 'selected' : '' ?>>All Partners</option>
                        <option value="customers" <?= $category_filter === 'customers' ? 'selected' : '' ?>>Customers Only</option>
                        <option value="suppliers" <?= $category_filter === 'suppliers' ? 'selected' : '' ?>>Suppliers Only</option>
                        <option value="employees" <?= $category_filter === 'employees' ? 'selected' : '' ?>>Employees Only</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2" style="background-color: #166c82; border-color: #166c82;">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <a href="partners.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Partners Table -->
    <div class="card">
        <div class="card-header text-white" style="background-color: #166c82;">
            <h5 class="mb-0"><i class="bi bi-table"></i> Partners List</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>VAT/NPWP</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($partners)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No partners found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($partners as $partner): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($partner['name']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $partner['is_company'] === 't' ? 'primary' : 'info' ?>">
                                            <?= $partner['is_company'] === 't' ? 'Company' : 'Individual' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $categories = [];
                                        if ($partner['customer_rank'] > 0) {
                                            $categories[] = '<span class="badge bg-primary me-1">Customer</span>';
                                        }
                                        if ($partner['supplier_rank'] > 0) {
                                            $categories[] = '<span class="badge bg-warning text-dark me-1">Supplier</span>';
                                        }
                                        if ($partner['employee'] === 't') {
                                            $categories[] = '<span class="badge bg-success me-1">Employee</span>';
                                        }
                                        echo !empty($categories) ? implode('', $categories) : '<span class="text-muted">-</span>';
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($partner['email'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($partner['phone'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($partner['city'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($partner['vat'] ?? '-') ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-primary" onclick="editPartner(<?= $partner['id'] ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deletePartner(<?= $partner['id'] ?>, '<?= htmlspecialchars($partner['name']) ?>')" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

<!-- Partner Modal -->
<div class="modal fade" id="partnerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #166c82; color: white;">
                <h5 class="modal-title" id="partnerModalTitle">Add New Partner</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="partnerForm">
                <div class="modal-body">
                    <input type="hidden" id="partner_id" name="partner_id">
                    <input type="hidden" name="action" id="form_action" value="create">
                    
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="partner_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="company_type" id="company_type">
                                <option value="person">Individual</option>
                                <option value="company">Company</option>
                            </select>
                        </div>
                        
                        <div class="col-md-8">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="partner_email">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="partner_phone">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Street Address</label>
                            <input type="text" class="form-control" name="street" id="partner_street">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" id="partner_city">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ZIP Code</label>
                            <input type="text" class="form-control" name="zip" id="partner_zip">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Country ID</label>
                            <input type="number" class="form-control" name="country_id" id="partner_country" placeholder="e.g., 100 for Indonesia">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">VAT / NPWP</label>
                            <input type="text" class="form-control" name="vat" id="partner_vat">
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="comment" id="partner_comment" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" style="background-color: #2e7d32; border-color: #2e7d32;">
                        <i class="bi bi-save"></i> Save Partner
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const partnerModal = new bootstrap.Modal(document.getElementById('partnerModal'));

function showPartnerModal() {
    document.getElementById('partnerModalTitle').textContent = 'Add New Partner';
    document.getElementById('partnerForm').reset();
    document.getElementById('form_action').value = 'create';
    document.getElementById('partner_id').value = '';
    partnerModal.show();
}

function editPartner(partnerId) {
    fetch('partners.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=get&partner_id=${partnerId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const p = data.partner;
            document.getElementById('partnerModalTitle').textContent = 'Edit Partner';
            document.getElementById('form_action').value = 'update';
            document.getElementById('partner_id').value = p.id;
            document.getElementById('partner_name').value = p.name || '';
            document.getElementById('company_type').value = p.is_company === 't' ? 'company' : 'person';
            document.getElementById('partner_email').value = p.email || '';
            document.getElementById('partner_phone').value = p.phone || '';
            document.getElementById('partner_street').value = p.street || '';
            document.getElementById('partner_city').value = p.city || '';
            document.getElementById('partner_zip').value = p.zip || '';
            document.getElementById('partner_country').value = p.country_id || '';
            document.getElementById('partner_vat').value = p.vat || '';
            document.getElementById('partner_comment').value = p.comment || '';
            partnerModal.show();
        }
    });
}

function deletePartner(partnerId, partnerName) {
    if (confirm(`Are you sure you want to delete partner "${partnerName}"?`)) {
        fetch('partners.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete&partner_id=${partnerId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Partner deleted successfully');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

document.getElementById('partnerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('partners.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Partner saved successfully');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob