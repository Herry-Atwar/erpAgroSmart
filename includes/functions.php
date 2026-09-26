<?php
/**
 * Common Helper Functions
 * AgroSmart - Agribusiness Intelligence
 */

// Configure session settings for better compatibility
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters
    ini_set('session.cookie_lifetime', 0); // Until browser closes
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    ini_set('session.cookie_secure', 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // Start the session
    session_start();
}

/**
 * Sanitize input data
 */
function clean_input($data) {
    // Handle arrays recursively
    if (is_array($data)) {
        return array_map('clean_input', $data);
    }
    
    // Handle strings
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Redirect to a page
 */
function redirect($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Set flash message
 */
function set_message($type, $message) {
    $_SESSION['message_type'] = $type;
    $_SESSION['message'] = $message;
}

/**
 * Display flash message
 */
function display_message() {
    if (isset($_SESSION['message'])) {
        $type = $_SESSION['message_type'];
        $message = $_SESSION['message'];
        
        $alert_class = '';
        switch($type) {
            case 'success':
                $alert_class = 'alert-success';
                break;
            case 'error':
                $alert_class = 'alert-danger';
                break;
            case 'warning':
                $alert_class = 'alert-warning';
                break;
            case 'info':
                $alert_class = 'alert-info';
                break;
            default:
                $alert_class = 'alert-info';
        }
        
        echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
        echo $message;
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
        
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
    }
}

/**
 * Format date for display
 */
function format_date($date, $format = 'd M Y') {
    if (empty($date) || $date == '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * Format number with thousand separator
 */
function format_number($number, $decimals = 2) {
    return number_format($number, $decimals, '.', ',');
}

/**
 * Get status badge HTML
 */
function get_status_badge($status) {
    $badges = [
        'Active' => 'success',
        'Inactive' => 'secondary',
        'Suspended' => 'danger',
        'Under Construction' => 'warning',
        'Maintenance' => 'info',
        'Planning' => 'info',
        'Planned' => 'info',
        'In Progress' => 'primary',
        'Completed' => 'success',
        'TBM' => 'warning',
        'TM' => 'success',
        'TR' => 'danger',
        'Replanting' => 'info',
        'Not Ready' => 'secondary',
        'Ready' => 'primary',
        'Harvesting' => 'success',
        'Assigned' => 'warning',
        'On Hold' => 'secondary',
        'Cancelled' => 'danger'
    ];
    
    $badge_class = isset($badges[$status]) ? $badges[$status] : 'secondary';
    return '<span class="badge bg-' . $badge_class . '">' . $status . '</span>';
}

/**
 * Get severity badge HTML for pest control
 */
function get_severity_badge($severity) {
    if (empty($severity)) {
        return '-';
    }
    
    $badges = [
        'Low' => 'success',
        'Medium' => 'warning',
        'High' => 'danger',
        'Critical' => 'dark'
    ];
    
    $badge_class = isset($badges[$severity]) ? $badges[$severity] : 'secondary';
    return '<span class="badge bg-' . $badge_class . '">' . $severity . '</span>';
}

/**
 * Get effectiveness badge HTML for pest control
 */
function get_effectiveness_badge($rating) {
    if (empty($rating)) {
        return '-';
    }
    
    $badges = [
        'Excellent' => 'success',
        'Good' => 'primary',
        'Fair' => 'warning',
        'Poor' => 'danger'
    ];
    
    $badge_class = isset($badges[$rating]) ? $badges[$rating] : 'secondary';
    return '<span class="badge bg-' . $badge_class . '">' . $rating . '</span>';
}

/**
 * Generate breadcrumb
 */
function breadcrumb($items) {
    echo '<nav aria-label="breadcrumb">';
    echo '<ol class="breadcrumb">';
    
    $count = count($items);
    $i = 1;
    
    foreach ($items as $label => $url) {
        if ($i == $count) {
            echo '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
        } else {
            echo '<li class="breadcrumb-item"><a href="' . $url . '">' . $label . '</a></li>';
        }
        $i++;
    }
    
    echo '</ol>';
    echo '</nav>';
}

/**
 * Pagination helper
 */
function paginate($total_records, $records_per_page, $current_page, $base_url) {
    $total_pages = ceil($total_records / $records_per_page);
    
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center">';
    
    // Previous button
    if ($current_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . ($current_page - 1) . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=1">1</a></li>';
        if ($start_page > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . ($current_page + 1) . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}

/**
 * Check if request is POST
 */
function is_post() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Get POST data
 */
function post($key, $default = '') {
    return isset($_POST[$key]) ? clean_input($_POST[$key]) : $default;
}

/**
 * Get GET data
 */
function get($key, $default = '') {
    return isset($_GET[$key]) ? clean_input($_GET[$key]) : $default;
}

/**
 * Generate unique code
 */
function generate_code($prefix, $length = 4) {
    $number = str_pad(rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    return $prefix . $number;
}

/**
 * Calculate plant age from planting date
 */
function calculate_plant_age($planting_date) {
    if (empty($planting_date) || $planting_date == '0000-00-00') {
        return 0;
    }
    
    $date1 = new DateTime($planting_date);
    $date2 = new DateTime();
    $interval = $date1->diff($date2);
    
    return $interval->y;
}

/**
 * Determine plant status based on age
 */
function determine_plant_status($age) {
    if ($age < 3) {
        return 'TBM'; // Immature
    } elseif ($age >= 3 && $age <= 25) {
        return 'TM'; // Mature
    } else {
        return 'TR'; // Rejuvenation
    }
}

/**
 * Export data to CSV
 */
function export_to_csv($filename, $data, $headers = []) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

/**
 * Get current user's company ID from session
 */
function get_user_company_id() {
    return isset($_SESSION['user_company_id']) ? $_SESSION['user_company_id'] : null;
}

/**
 * Set user's company ID in session
 */
function set_user_company_id($company_id) {
    $_SESSION['user_company_id'] = $company_id;
}

/**
 * Check if user has a fixed company
 */
function has_user_company() {
    return !empty(get_user_company_id());
}

/**
 * Get current user's business unit ID from session
 */
function get_user_business_unit_id() {
    return isset($_SESSION['user_business_unit_id']) ? $_SESSION['user_business_unit_id'] : null;
}

/**
 * Set user's business unit ID in session
 */
function set_user_business_unit_id($business_unit_id) {
    $_SESSION['user_business_unit_id'] = $business_unit_id;
}

/**
 * Check if user has a fixed business unit
 */
function has_user_business_unit() {
    return !empty(get_user_business_unit_id());
}

/**
 * Load user settings from database and set in session
 * Call this after user login or at the start of each page
 */
function load_user_settings_from_db($user_id) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT company_id, business_unit_id FROM agrosmart_users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            if ($user['company_id']) {
                set_user_company_id($user['company_id']);
            }
            if ($user['business_unit_id']) {
                set_user_business_unit_id($user['business_unit_id']);
            }
            return true;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get current user ID from session
 * In a real system, this would be set during login
 */
function get_current_user_id() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

/**
 * Set current user ID in session
 */
function set_current_user_id($user_id) {
    $_SESSION['user_id'] = $user_id;
}

/**
 * Simulate user login - load settings from database
 */
function simulate_user_login($user_id) {
    set_current_user_id($user_id);
    return load_user_settings_from_db($user_id);
}

/**
 * Generate pagination with query parameters
 */
function generate_pagination($current_page, $total_pages, $query_params = []) {
    if ($total_pages <= 1) {
        return '';
    }
    
    // Build query string from parameters
    $query_string = '';
    foreach ($query_params as $key => $value) {
        if ($key !== 'page' && !empty($value)) {
            $query_string .= '&' . urlencode($key) . '=' . urlencode($value);
        }
    }
    
    $html = '<nav aria-label="Page navigation">';
    $html .= '<ul class="pagination justify-content-center">';
    
    // Previous button
    if ($current_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . ($current_page - 1) . $query_string . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=1' . $query_string . '">1</a></li>';
        if ($start_page > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="?page=' . $i . $query_string . '">' . $i . '</a></li>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . $query_string . '">' . $total_pages . '</a></li>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . ($current_page + 1) . $query_string . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}
/**
 * Render a coloured SNI/GAPKI standards compliance badge with tooltip.
 *
 * @param  string $status   'pass' | 'warn' | 'fail'
 * @param  string $param    Human-readable parameter name
 * @param  string $display  Standard range string, e.g. "≥22%"
 * @param  string $source   Citation, e.g. "SNI 7182:2015"
 * @return string           HTML <span> badge
 */
function render_std_badge(string $status, string $param, string $display, string $source): string
{
    $map = [
        'pass' => ['success', '✓', 'Meets Standard'],
        'warn' => ['warning', '⚠', 'Needs Attention'],
        'fail' => ['danger',  '✗', 'Does Not Meet Standard'],
    ];
    [$colour, $icon, $label] = $map[$status] ?? ['secondary', '?', $status];
    $tooltip = htmlspecialchars("$param: $display · $source · $label");
    return "<span class=\"badge bg-{$colour}\" title=\"{$tooltip}\" style=\"font-size:0.7rem;\">{$icon} {$display}</span>";
}

// Made with Bob
