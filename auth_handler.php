<?php
/**
 * CAASP Authentication Handler
 * * Processes synchronous form submissions for registration and login,
 * ensuring compliance with the CAASP database schema (Locations, Users, Roles, UserRole, Garages, Vendors tables).
 */

// Include database configuration (connect_db() and session_start() are here)
require_once 'api_db_config.php';

// Define the dashboard files for redirection
$dashboard_files = [
    'Customer' => 'customer_dashboard.php',
    'Garage' => 'garage_dashboard.php',
    'Vendor' => 'vendor_dashboard.php',
    'Admin' => 'admin_dashboard.php', 
];

// Helper function to safely redirect
// The $user_id and $role parameters are only used for successful LOGIN redirection
function redirect_with_status($status, $message, $user_id = null, $role = null) {
    
    // Check if redirecting to a dashboard is required (only for successful login)
    if ($status === 'success' && $user_id && $role) {
        
        // 1. Set Session Variables (for security and to hide ID)
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $role;

        // 2. Redirect to the appropriate dashboard page (no query string)
        $dashboard_files = [
            'Customer' => 'customer_dashboard.php',
            'Garage' => 'garage_dashboard.php',
            'Vendor' => 'vendor_dashboard.php',
            'Admin' => 'admin_dashboard.php', 
        ];
        $dashboard_file = $dashboard_files[$role] ?? 'index.html';
        
        header("Location: {$dashboard_file}");
        exit;
    }
    
    // Fallback: Redirect back to index.html (used for all errors and registration success)
    $redirect_url = "index.html?status=" . urlencode($status) . "&message=" . urlencode($message);
    header("Location: " . $redirect_url);
    exit;
}

// Allowed roles for validation (Converts form input to schema name)
$allowed_roles = [
    'customer' => 'Customer', 
    'garage_owner' => 'Garage', 
    'vendor' => 'Vendor',
    'admin' => 'Admin', 
];

// --- Check for Registration Form Submission ---
if (isset($_POST['register_submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role_input = $_POST['role'] ?? ''; 
    $contact = trim($_POST['contact']);
    $city = trim($_POST['city']);
    $district = trim($_POST['district']);
    $business_name = trim($_POST['business_name'] ?? '');
    
    $role_schema = $allowed_roles[$role_input] ?? null;

    // 1. Basic validation
    if (empty($email) || empty($password) || empty($role_schema) || empty($contact) || empty($city) || empty($district)) {
        redirect_with_status('error', 'All required registration fields are missing.');
    }
    
    // 1b. Business Name validation for Garage/Vendor
    $is_business_role = ($role_schema === 'Garage' || $role_schema === 'Vendor'); // NEW: Flag for business roles
    
    if ($is_business_role && empty($business_name)) {
        redirect_with_status('error', 'Business Name is required for ' . ucwords($role_schema) . ' registration.');
    }
    
    // **USING connect_db() defined in api_db_config.php**
    $db = connect_db(); 
    if (!$db) {
        redirect_with_status('error', 'Database connection failed. Check api_db_config.php.');
    }
    
    // Sanitize and prepare
    $email = mysqli_real_escape_string($db, $email);
    $contact = mysqli_real_escape_string($db, $contact);
    $city = mysqli_real_escape_string($db, $city);
    $district = mysqli_real_escape_string($db, $district);
    $business_name = mysqli_real_escape_string($db, $business_name);
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // --- TRANSACTION START ---
    mysqli_begin_transaction($db);
    $location_id = null;
    $user_id = null;

    try {
        // A. Check if user already exists
        $sql_check = "SELECT user_id FROM Users WHERE email = ?";
        if ($stmt = mysqli_prepare($db, $sql_check)) {
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    throw new Exception('Registration failed: This email is already registered.');
                }
            } else {
                throw new Exception('Database error during email check.');
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception('Internal server error (SQL preparation failed during email check).');
        }

        // B. Insert into Locations table (Need placeholder latitude/longitude based on schema)
        $sql_location = "INSERT INTO Locations (city, district, latitude, longitude) VALUES (?, ?, ?, ?)";
        $default_lat = 0.00000000;
        $default_long = 0.00000000;
        
        if ($stmt = mysqli_prepare($db, $sql_location)) {
            mysqli_stmt_bind_param($stmt, "ssdd", $param_city, $param_district, $param_lat, $param_long);
            $param_city = $city;
            $param_district = $district;
            $param_lat = $default_lat;
            $param_long = $default_long;
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to save user location.');
            }
            $location_id = mysqli_insert_id($db);
            mysqli_stmt_close($stmt);
        } else {
             throw new Exception('Internal server error (SQL preparation failed for location).');
        }

        // C. Insert into Users table - ADDING INITIAL BALANCE AND APPROVAL STATUS LOGIC (Requires 'is_approved' column in Users table)
        // Column names used: email, password, contact, location_id, account_balance, is_approved
        $sql_user = "INSERT INTO Users (email, password, contact, location_id, account_balance, is_approved) VALUES (?, ?, ?, ?, ?, ?)";
        
        $initial_balance = 0.00;
        $is_approved = 1; // Default to Approved (for Customer/Admin)
        
        // Only give customers the initial balance
        if ($role_schema === 'Customer') {
            $initial_balance = 10000.00;
        } 
        
        // Set approval status for business roles to PENDING (0)
        if ($is_business_role) {
            $is_approved = 0; // 0 = Pending Approval
        }
        // Note: Admin should be 1 (Approved)

        if ($stmt = mysqli_prepare($db, $sql_user)) {
            // Bind parameters: 3 strings, 1 integer, 1 double, 1 integer (for is_approved)
            mysqli_stmt_bind_param($stmt, "sssidf", 
                $param_email, 
                $param_password, 
                $param_contact, 
                $param_location_id,
                $param_balance,
                $param_is_approved // NEW: Binding the approval status
            );
            $param_email = $email;
            $param_password = $hashed_password; 
            $param_contact = $contact;
            $param_location_id = $location_id;
            $param_balance = $initial_balance; // New: binding the balance
            $param_is_approved = $is_approved; // NEW: binding the approval status
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to create user account. (Check if is_approved column exists)');
            }
            $user_id = mysqli_insert_id($db);
            mysqli_stmt_close($stmt);
        } else {
             throw new Exception('Internal server error (SQL preparation failed for user).');
        }

        // D. Insert into UserRole junction table
        // D1. Get the role_id from the Roles table
        $sql_role_id = "SELECT role_id FROM Roles WHERE role_name = ?";
        $role_id = null;
        if ($stmt = mysqli_prepare($db, $sql_role_id)) {
            mysqli_stmt_bind_param($stmt, "s", $param_role_schema);
            $param_role_schema = $role_schema;
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_bind_result($stmt, $role_id);
                if (!mysqli_stmt_fetch($stmt)) {
                    throw new Exception('Role not found in the Roles table: ' . $role_schema);
                }
            } else {
                throw new Exception('Database error fetching role ID.');
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception('Internal server error (SQL preparation failed for role ID).');
        }
        
        // D2. Insert the user_id and role_id into UserRole
        $sql_user_role = "INSERT INTO UserRole (user_id, role_id) VALUES (?, ?)";
        if ($stmt = mysqli_prepare($db, $sql_user_role)) {
            mysqli_stmt_bind_param($stmt, "ii", $param_user_id, $param_role_id);
            $param_user_id = $user_id;
            $param_role_id = $role_id;
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to link user role.');
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception('Internal server error (SQL preparation failed for UserRole).');
        }

        // E. Insert into Garages or Vendors table (Conditional)
        if ($role_schema === 'Garage') {
            // Insert into Garages table
            $sql_garage = "INSERT INTO Garages (garage_name, user_id, location_id) VALUES (?, ?, ?)";
            if ($stmt = mysqli_prepare($db, $sql_garage)) {
                mysqli_stmt_bind_param($stmt, "sii", $param_garage_name, $param_user_id, $param_location_id);
                $param_garage_name = $business_name;
                $param_user_id = $user_id;
                $param_location_id = $location_id;
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Failed to create Garage entry.');
                }
                mysqli_stmt_close($stmt);
            } else {
                throw new Exception('Internal server error (SQL preparation failed for Garages).');
            }
        } elseif ($role_schema === 'Vendor') {
            // Insert into Vendors table
            $sql_vendor = "INSERT INTO Vendors (vendor_name, user_id, location_id) VALUES (?, ?, ?)";
            if ($stmt = mysqli_prepare($db, $sql_vendor)) {
                mysqli_stmt_bind_param($stmt, "sii", $param_vendor_name, $param_user_id, $param_location_id);
                $param_vendor_name = $business_name;
                $param_user_id = $user_id;
                $param_location_id = $location_id;
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Failed to create Vendor entry.');
                }
                mysqli_stmt_close($stmt);
            } else {
                throw new Exception('Internal server error (SQL preparation failed for Vendors).');
            }
        }
        // Note: Admin role does not require a Garage/Vendor entry

        // F. Commit the transaction if all inserts succeeded
        mysqli_commit($db);
        mysqli_close($db);
        
        // --- REGISTRATION SUCCESS REDIRECT: Redirects back to index.html with success status ---
        
        // NEW: Custom success message based on role
        if ($is_business_role) {
            $success_msg = "Registration successful! Your business account is **pending approval** by the Administrator.";
        } else {
            $success_msg = "Registration successful! Your account is pre-funded with KES 10,000.00. Please log in as a " . ucwords($role_schema) . ".";
        }
        
        redirect_with_status('success', $success_msg); 

    } catch (Exception $e) {
        // --- TRANSACTION ROLLBACK ---
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_with_status('error', $e->getMessage());
    }

} 
// --- Check for Login Form Submission ---
else if (isset($_POST['login_submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $submitted_role_input = $_POST['role'] ?? '';
    
    $submitted_role_schema = $allowed_roles[$submitted_role_input] ?? null;

    if (empty($email) || empty($password) || empty($submitted_role_schema)) {
        redirect_with_status('error', 'All fields are required for login.');
    }
    
    $db = connect_db();
    if (!$db) {
        redirect_with_status('error', 'Database connection failed. Check api_db_config.php.');
    }

    // SQL: Fetch user details, including password, role, and approval status (NEW)
    $sql = "SELECT 
                U.user_id, 
                U.password AS hashed_password, 
                R.role_name AS stored_role,
                U.is_approved
            FROM Users U
            JOIN UserRole UR ON U.user_id = UR.user_id
            JOIN Roles R ON UR.role_id = R.role_id
            WHERE U.email = ? AND R.role_name = ?"; // Filter by both email AND selected role

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "ss", $param_email, $param_role);
        $param_email = $email;
        $param_role = $submitted_role_schema;
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) == 1) {
                // Bind result variables
                // NOTE: U.is_approved is the 4th column selected in the SQL above (must match bind order)
                mysqli_stmt_bind_result($stmt, $user_id, $hashed_password, $stored_role, $is_approved);
                if (mysqli_stmt_fetch($stmt)) {
                    if (password_verify($password, $hashed_password)) {
                        
                        // NEW: Approval Check for Business Roles (Garage/Vendor)
                        if ($stored_role === 'Garage' || $stored_role === 'Vendor') {
                            if ($is_approved == 0) { // Pending
                                mysqli_stmt_close($stmt);
                                mysqli_close($db);
                                redirect_with_status('error', 'Login failed: Your business account is still pending administrator approval.');
                            } elseif ($is_approved == 2) { // Rejected/Suspended
                                mysqli_stmt_close($stmt);
                                mysqli_close($db);
                                redirect_with_status('error', 'Login failed: Your business account has been suspended or rejected by the administrator.');
                            }
                            // If is_approved == 1, proceed to login success below.
                        }
                        
                        // Success! (Or if not a business role, it proceeds here)
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        
                        // --- LOGIN SUCCESS REDIRECT: Sets session and redirects to the appropriate dashboard (no ID in URL) ---
                        redirect_with_status('success', 'Login successful!', $user_id, $stored_role);
                    } else {
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        redirect_with_status('error', 'Invalid password for the specified role and account.');
                    }
                }
            } else {
                mysqli_stmt_close($stmt);
                mysqli_close($db);
                redirect_with_status('error', 'Login failed. Account not found with that email and role combination.');
            }
        } else {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_with_status('error', 'Login failed due to a database query error.');
        }
    } else {
        mysqli_close($db);
        redirect_with_status('error', 'Internal server error during login preparation (SQL preparation failed).');
    }

} else {
    // If accessed directly without a POST, redirect back.
    redirect_with_status('error', 'Access denied. Use the forms to submit data.');
}
?>