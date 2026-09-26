<?php
/**
 * User Default Settings
 * Set default company, business unit, and division for users
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

$db = getDB();

// Get all companies
$companies_stmt = $db->query("SELECT company_id, company_name FROM companies ORDER BY company_name");
$companies = $companies_stmt->fetchAll();

// Get all business units
$business_units_stmt = $db->query("SELECT business_unit_id, unit_code, unit_name, company_id FROM business_units ORDER BY unit_code");
$business_units = $business_units_stmt->fetchAll();

// Get all divisions
$divisions_stmt = $db->query("SELECT division_id, division_code, division_name, business_unit_id FROM divisions ORDER BY division_code");
$divisions = $divisions_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post('username');
    $company_id = post('company_id');
    $business_unit_id = post('business_unit_id');
    $division_id = post('division_id');
    
    if (!empty($username)) {
        try {
            // Update user defaults
            $stmt = $db->prepare("
                UPDATE users 
                SET company_id = ?, 
                    business_unit_id = ?, 
                    division_id = ?
                WHERE username = ?
            ");
            $stmt->execute([
                $company_id ?: null,
                $business_unit_id ?: null,
                $division_id ?: null,
                $username
            ]);
            
            set_message('User defaults updated successfully!', 'success');
            redirect('user_defaults.php?username=' . urlencode($username));
        } catch (Exception $e) {
            set_message('Error updating user defaults: ' . $e->getMessage(), 'danger');
        }
    }
}

// Get current user data
$current_username = get('username', 'admin');
$user_data = null;

if ($current_username) {
    $stmt = $db->prepare("SELECT username, company_id, business_unit_id, division_id FROM users WHERE username = ?");
    $stmt->execute([$current_username]);
    $user_data = $stmt->fetch();
}

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 style="color: #166c82;"><i class="bi bi-person-gear"></i> User Default Settings</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active">User Defaults</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php display_message(); ?>

    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header text-white" style="background-color: #166c82;">
                    <i class="bi bi-gear"></i> Set User Default Company, Business Unit & Division
                </div>
                <div class="card-body">
                    <?php if ($user_data): ?>
                        <div class="alert alert-info">
                            <strong>Current Settings for: <?php echo htmlspecialchars($user_data['username']); ?></strong><br>
                            Company ID: <code><?php echo $user_data['company_id'] ?: 'Not Set'; ?></code><br>
                            Business Unit ID: <code><?php echo $user_data['business_unit_id'] ?: 'Not Set'; ?></code><br>
                            Division ID: <code><?php echo $user_data['division_id'] ?: 'Not Set'; ?></code>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="userDefaultsForm">
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" 
                                value="<?php echo htmlspecialchars($current_username); ?>" required>
                            <small class="form-text text-muted">Enter the username to update defaults</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Company</label>
                            <select name="company_id" id="company_id" class="form-select">
                                <option value="">-- Not Set --</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['company_id']; ?>"
                                        <?php echo ($user_data && $user_data['company_id'] == $company['company_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                If set, this company will be pre-selected in forms
                            </small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Business Unit</label>
                            <select name="business_unit_id" id="business_unit_id" class="form-select">
                                <option value="">-- Not Set --</option>
                                <?php foreach ($business_units as $bu): ?>
                                    <option value="<?php echo $bu['business_unit_id']; ?>"
                                        data-company-id="<?php echo $bu['company_id']; ?>"
                                        <?php echo ($user_data && $user_data['business_unit_id'] == $bu['business_unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo $bu['unit_code']; ?> - <?php echo htmlspecialchars($bu['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                If set, this business unit will be pre-selected in forms
                            </small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Division</label>
                            <select name="division_id" id="division_id" class="form-select">
                                <option value="">-- Not Set --</option>
                                <?php foreach ($divisions as $div): ?>
                                    <option value="<?php echo $div['division_id']; ?>"
                                        data-business-unit-id="<?php echo $div['business_unit_id']; ?>"
                                        <?php echo ($user_data && $user_data['division_id'] == $div['division_id']) ? 'selected' : ''; ?>>
                                        <?php echo $div['division_code']; ?> - <?php echo htmlspecialchars($div['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                If set, this division will be pre-selected in forms
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn text-white" style="background-color: #166c82;">
                                <i class="bi bi-check-circle"></i> Save User Defaults
                            </button>
                            <a href="journal_entries.php" class="btn btn-secondary">
                                <i class="bi bi-journal-text"></i> Test in Journal Entries
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Cascading filters
document.addEventListener('DOMContentLoaded', function() {
    const companySelect = document.getElementById('company_id');
    const businessUnitSelect = document.getElementById('business_unit_id');
    const divisionSelect = document.getElementById('division_id');
    
    // Store all options
    const allBusinessUnits = Array.from(businessUnitSelect.options).slice(1);
    const allDivisions = Array.from(divisionSelect.options).slice(1);
    
    // Filter business units by company
    function filterBusinessUnits(companyId, preserveSelection) {
        const currentValue = preserveSelection ? businessUnitSelect.value : '';
        businessUnitSelect.innerHTML = '<option value="">-- Not Set --</option>';
        
        if (companyId) {
            allBusinessUnits.forEach(option => {
                if (option.dataset.companyId === companyId) {
                    businessUnitSelect.appendChild(option.cloneNode(true));
                }
            });
        } else {
            allBusinessUnits.forEach(option => {
                businessUnitSelect.appendChild(option.cloneNode(true));
            });
        }
        
        if (preserveSelection && currentValue) {
            businessUnitSelect.value = currentValue;
        }
    }
    
    // Filter divisions by business unit
    function filterDivisions(businessUnitId, preserveSelection) {
        const currentValue = preserveSelection ? divisionSelect.value : '';
        divisionSelect.innerHTML = '<option value="">-- Not Set --</option>';
        
        if (businessUnitId) {
            allDivisions.forEach(option => {
                if (option.dataset.businessUnitId === businessUnitId) {
                    divisionSelect.appendChild(option.cloneNode(true));
                }
            });
        } else {
            allDivisions.forEach(option => {
                divisionSelect.appendChild(option.cloneNode(true));
            });
        }
        
        if (preserveSelection && currentValue) {
            divisionSelect.value = currentValue;
        }
    }
    
    // Event listeners
    companySelect.addEventListener('change', function() {
        filterBusinessUnits(this.value, false);
        filterDivisions('', false);
    });
    
    businessUnitSelect.addEventListener('change', function() {
        filterDivisions(this.value, false);
    });
    
    // Initialize on page load
    if (companySelect.value) {
        filterBusinessUnits(companySelect.value, true);
    }
    if (businessUnitSelect.value) {
        filterDivisions(businessUnitSelect.value, true);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

// Made with Bob
