<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Handle form submissions BEFORE any output
if (is_post()) {
    $action = post('action');
    
    // Debug: Log what we're receiving
    error_log("POST Action: " . $action);
    error_log("POST Data: " . print_r($_POST, true));
    
    if ($action == 'add') {
        try {
            $stmt = $db->prepare("
                INSERT INTO companies (company_code, company_name, legal_name, tax_id, address, city, province, 
                                     postal_code, country, phone, email, website, established_date, status, notes, created_by)
                VALUES (:code, :name, :legal_name, :tax_id, :address, :city, :province, :postal_code, :country, 
                        :phone, :email, :website, :established_date, :status, :notes, 'admin')
            ");
            
            $stmt->execute([
                ':code' => post('company_code'),
                ':name' => post('company_name'),
                ':legal_name' => post('legal_name'),
                ':tax_id' => post('tax_id'),
                ':address' => post('address'),
                ':city' => post('city'),
                ':province' => post('province'),
                ':postal_code' => post('postal_code'),
                ':country' => post('country', 'Indonesia'),
                ':phone' => post('phone'),
                ':email' => post('email'),
                ':website' => post('website'),
                ':established_date' => post('established_date'),
                ':status' => post('status', 'Active'),
                ':notes' => post('notes')
            ]);
            
            set_message('success', 'Company added successfully!');
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', 'Error adding company: ' . $e->getMessage());
        }
    }
    
    elseif ($action == 'edit') {
        try {
            $company_id = post('company_id');
            if (empty($company_id)) {
                set_message('error', 'Company ID is required for update');
                redirect('companies.php');
            }
            
            $stmt = $db->prepare("
                UPDATE companies
                SET company_code = :code, company_name = :name, legal_name = :legal_name, tax_id = :tax_id,
                    address = :address, city = :city, province = :province, postal_code = :postal_code,
                    country = :country, phone = :phone, email = :email, website = :website,
                    established_date = :established_date, status = :status, notes = :notes, updated_by = 'admin'
                WHERE company_id = :id
            ");
            
            $result = $stmt->execute([
                ':id' => $company_id,
                ':code' => post('company_code'),
                ':name' => post('company_name'),
                ':legal_name' => post('legal_name') ?: null,
                ':tax_id' => post('tax_id') ?: null,
                ':address' => post('address') ?: null,
                ':city' => post('city') ?: null,
                ':province' => post('province') ?: null,
                ':postal_code' => post('postal_code') ?: null,
                ':country' => post('country') ?: 'Indonesia',
                ':phone' => post('phone') ?: null,
                ':email' => post('email') ?: null,
                ':website' => post('website') ?: null,
                ':established_date' => post('established_date') ?: null,
                ':status' => post('status') ?: 'Active',
                ':notes' => post('notes') ?: null
            ]);
            
            if ($result) {
                set_message('success', 'Company updated successfully!');
            } else {
                set_message('error', 'No changes were made or update failed');
            }
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', 'Error updating company: ' . $e->getMessage());
            error_log("Update error: " . $e->getMessage());
        }
    }
    
    elseif ($action == 'delete') {
        try {
            $stmt = $db->prepare("DELETE FROM companies WHERE company_id = :id");
            $stmt->execute([':id' => post('company_id')]);
            
            set_message('success', 'Company deleted successfully!');
            redirect('companies.php');
        } catch (PDOException $e) {
            set_message('error', 'Error deleting company: ' . $e->getMessage());
        }
    }
}

// Get company for editing (before header)
$edit_company = null;
if (get('action') == 'edit' && get('id')) {
    $stmt = $db->prepare("SELECT * FROM companies WHERE company_id = :id");
    $stmt->execute([':id' => get('id')]);
    $edit_company = $stmt->fetch();
}

// Now include header after form processing
$page_title = "Companies Management";
require_once 'includes/header.php';

// Fetch all companies with statistics
$search = get('search', '');
$status_filter = get('status', '');

// Scope to the logged-in user's company if one is assigned
// Login stores company as $_SESSION['company_id']; fall back to user_company_id for compatibility
$user_company_id = $_SESSION['company_id'] ?? get_user_company_id();

$sql = "SELECT c.*,
        COALESCE(c.total_area_ha, 0) + COALESCE(c.forestry_area_ha, 0) as combined_total_area_ha,
        COALESCE(c.total_plants, 0) as total_plants,
        COALESCE(c.forestry_area_ha, 0) as forestry_area_ha,
        COALESCE(c.total_volume_m3, 0) as total_volume_m3,
        COALESCE(c.total_carbon_stock_ton, 0) as total_carbon_stock_ton,
        COALESCE(c.forestry_blocks, 0) as forestry_blocks,
        (SELECT COUNT(*) FROM business_units bu WHERE bu.company_id = c.company_id AND bu.parent_unit_id IS NULL) as total_business_units,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
         WHERE bu.company_id = c.company_id) as total_blocks,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
         WHERE bu.company_id = c.company_id AND b.operation_type = 'Plantation' AND b.status = 'TM') as tm_blocks,
        (SELECT COUNT(*) FROM blocks b
         INNER JOIN divisions d ON b.division_id = d.division_id
         INNER JOIN business_units bu ON d.business_unit_id = bu.business_unit_id
         WHERE bu.company_id = c.company_id AND b.operation_type = 'Plantation' AND b.status = 'TBM') as tbm_blocks
        FROM companies c
        WHERE 1=1";

if ($user_company_id) {
    $sql .= " AND c.company_id = :user_company_id";
}
if ($search) {
    $sql .= " AND (c.company_code LIKE :search OR c.company_name LIKE :search)";
}
if ($status_filter) {
    $sql .= " AND c.status = :status";
}

$sql .= " ORDER BY c.company_name";

$stmt = $db->prepare($sql);
if ($user_company_id) {
    $stmt->bindValue(':user_company_id', $user_company_id);
}
if ($search) {
    $stmt->bindValue(':search', "%$search%");
}
if ($status_filter) {
    $stmt->bindValue(':status', $status_filter);
}
$stmt->execute();
$companies = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1><i class="bi bi-building"></i> Companies Management</h1>
            <p class="text-muted">Manage company information and hierarchy</p>
        </div>
        <div class="col-auto">
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle"></i> Add New Company
            </button>
        </div>
    </div>
</div>
<!-- Summary Cards -->
<?php
// Calculate totals from recursive summary columns
$total_companies = count($companies);
$total_blocks = array_sum(array_column($companies, 'total_blocks'));

// Include forestry area in total area
$total_area = array_sum(array_map(function($company) {
    return ($company['total_area_ha'] ?? 0) + ($company['forestry_area_ha'] ?? 0);
}, $companies));

$total_plants = array_sum(array_column($companies, 'total_plants'));
$total_business_units = array_sum(array_column($companies, 'total_business_units'));
$total_forestry_area = array_sum(array_column($companies, 'forestry_area_ha'));
?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo $total_companies; ?></h3>
                <p><i class="bi bi-building"></i> Total Companies</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="business_units.php" class="text-decoration-none">
            <div class="card stat-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                <div class="card-body">
                    <h3 class="text-dark"><?php echo $total_business_units; ?></h3>
                    <p class="text-muted"><i class="bi bi-diagram-3"></i> Business Units</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_area); ?></h3>
                <p><i class="bi bi-map"></i> Total Area (Ha)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h3><?php echo format_number($total_plants, 0); ?></h3>
                <p><i class="bi bi-tree"></i> Total Plants</p>
            </div>
        </div>
    </div>
</div>


<!-- Search and Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <input type="text" class="form-control" name="search" placeholder="Search by code or name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="Suspended" <?php echo $status_filter == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="companies.php" class="btn btn-secondary"><i class="bi bi-arrow-clockwise"></i> Reset</a>
                <button type="button" class="btn btn-success" onclick="exportTableToCSV('companies.csv')">
                    <i class="bi bi-file-earmark-excel"></i> Export
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Companies Table -->
<div class="card">
    <div class="card-header" style="background: linear-gradient(135deg, #808000 0%, #E34234 100%); color: white;">
        <i class="bi bi-list-ul"></i> Companies List (<?php echo count($companies); ?> records)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Company Name</th>
                        <th>Province</th>
                        <th>Business Units</th>
                        <th>Blocks</th>
                        <th>Area (Ha)</th>
                        <th>Plants</th>
                        <th>TM/TBM</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($companies)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No companies found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($companies as $company): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($company['company_code']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($company['company_name']); ?>
                                    <?php if (isset($company['province']) && $company['province']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($company['province']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo isset($company['province']) ? htmlspecialchars($company['province']) : '-'; ?></td>
                                <td><span class="badge bg-info"><?php echo $company['total_business_units']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo format_number($company['total_blocks'], 0); ?></span></td>
                                <td class="text-end"><?php echo format_number(($company['total_area_ha'] ?? 0) + ($company['forestry_area_ha'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo format_number($company['total_plants'], 0); ?></td>
                                <td>
                                    <small>
                                        <span class="badge bg-success"><?php echo $company['tm_blocks']; ?> TM</span>
                                        <span class="badge bg-warning"><?php echo $company['tbm_blocks']; ?> TBM</span>
                                    </small>
                                </td>
                                <td><?php echo get_status_badge($company['status']); ?></td>
                                <td>
                                    <a href="?action=edit&id=<?php echo $company['company_id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="business_units.php?company_id=<?php echo $company['company_id']; ?>" class="btn btn-sm btn-info" title="View Business Units">
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                    <form method="POST" action="companies.php" style="display:inline;" onsubmit="return confirmDelete('Delete this company and all related data?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="company_id" value="<?php echo $company['company_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="companies.php">
                <div class="modal-header" style="background-color: <?php echo $edit_company ? 'olive' : '#3065b0'; ?>; color: white;">
                    <h5 class="modal-title">
                        <?php echo $edit_company ? 'Edit Company' : 'Add New Company'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_company ? 'edit' : 'add'; ?>">
                    <?php if ($edit_company): ?>
                        <input type="hidden" name="company_id" value="<?php echo $edit_company['company_id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_code" required 
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['company_code']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_name" required
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['company_name']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Legal Name</label>
                        <input type="text" class="form-control" name="legal_name"
                               value="<?php echo $edit_company ? htmlspecialchars($edit_company['legal_name']) : ''; ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tax ID / NPWP</label>
                            <input type="text" class="form-control" name="tax_id"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['tax_id']) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Established Date</label>
                            <input type="date" class="form-control" name="established_date"
                                   value="<?php echo $edit_company ? $edit_company['established_date'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"><?php echo $edit_company ? htmlspecialchars($edit_company['address']) : ''; ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['city']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Province</label>
                            <input type="text" class="form-control" name="province"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['province']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Postal Code</label>
                            <input type="text" class="form-control" name="postal_code"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['postal_code']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['phone']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['email']) : ''; ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Website</label>
                            <input type="url" class="form-control" name="website"
                                   value="<?php echo $edit_company ? htmlspecialchars($edit_company['website']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Country</label>
                            <input type="text" class="form-control" name="country" value="<?php echo $edit_company ? htmlspecialchars($edit_company['country']) : 'Indonesia'; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Active" <?php echo ($edit_company && $edit_company['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($edit_company && $edit_company['status'] == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Suspended" <?php echo ($edit_company && $edit_company['status'] == 'Suspended') ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo $edit_company ? htmlspecialchars($edit_company['notes']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> <?php echo $edit_company ? 'Update' : 'Save'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_company): ?>
<script>
    // Auto-open modal for editing
    console.log('Edit company data:', <?php echo json_encode($edit_company); ?>);
    document.addEventListener('DOMContentLoaded', function() {
        var editModal = new bootstrap.Modal(document.getElementById('addModal'));
        editModal.show();
    });
</script>
<?php else: ?>
<script>
    console.log('No edit company data. GET params:', <?php echo json_encode($_GET); ?>);
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
