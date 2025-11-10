//The backend file to process administrative actions (Approve, Reject, Delete).

<?php
/**
 * CAASP Admin Handler
 * * Processes POST requests for managing user approvals and deleting content.
 * * This assumes the 'is_approved' column exists and is used: 0=Pending, 1=Approved, 2=Rejected/Suspended.
 */

require_once 'api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

if (!$user_id || $role !== 'Admin') {
    header("Location: index.html?status=error&message=" . urlencode("Admin session required to perform actions."));
    exit;
}

// Helper function to safely redirect back to the current admin view
function redirect_to_admin_dashboard($status, $message, $view = 'pending') {
    header("Location: admin_dashboard.php?view=" . urlencode($view) . "&status=" . urlencode($status) . "&message=" . urlencode($message));
    exit;
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to_admin_dashboard('error', 'Invalid request method.', 'pending');
}

$action = $_POST['action'] ?? '';
$db = connect_db();
if (!$db) {
    redirect_to_admin_dashboard('error', 'Database connection failed. Cannot process action.');
}


// ====================================================================
// --- A. PROCESS BUSINESS APPROVAL / REJECTION ---
// ====================================================================
if ($action === 'approve_business' || $action === 'reject_business') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    
    // Set status based on action: 1 for Approved, 2 for Rejected/Suspended
    $new_status = ($action === 'approve_business') ? 1 : 2; 
    $message_verb = ($action === 'approve_business') ? 'Approved' : 'Rejected';

    if ($target_user_id === 0) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid user ID.', 'pending');
    }

    // SQL: Update the is_approved status in the Users table
    $sql = "UPDATE Users SET is_approved = ? WHERE user_id = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $new_status, $target_user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('success', "Business account successfully {$message_verb}!", 'pending');
        } else {
            error_log("Approval/Rejection failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', "Database error during {$message_verb} process.", 'pending');
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation.');
    }
} 
// ====================================================================
// --- B. PROCESS LISTING DELETION ---
// ====================================================================
elseif ($action === 'delete_listing') {
    $item_type = $_POST['item_type'] ?? ''; // 'Service' or 'Part'
    $item_id = (int)($_POST['item_id'] ?? 0);

    if ($item_id === 0 || !in_array($item_type, ['Service', 'Part'])) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid listing details provided.', 'listings');
    }

    $table = ($item_type === 'Service') ? 'Services' : 'Parts';
    $id_field = ($item_type === 'Service') ? 'service_id' : 'part_id';
    
    // SQL: DELETE the listing
    $sql = "DELETE FROM {$table} WHERE {$id_field} = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $item_id);
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('success', "{$item_type} listing ID {$item_id} has been permanently deleted.", 'listings');
        } else {
            error_log("Listing deletion failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', 'Database error during listing deletion.', 'listings');
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation.');
    }

} 
// ====================================================================
// --- C. UNKNOWN ACTION ---
// ====================================================================
else {
    mysqli_close($db);
    redirect_to_admin_dashboard('error', 'Unknown action requested.', 'pending');
}

?>