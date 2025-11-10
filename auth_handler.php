<?php
session_start(); // 1. START THE PHP SESSION

/**
 * CAASP Authentication Handler
 * Processes synchronous form submissions for registration and login,
 * ensuring compliance with the CAASP database schema.
 */

// Include database configuration (connect_db() and session_start() are here)
require_once 'api_db_config.php';

<<<<<<< HEAD
// Define the dashboard URLs (CORRECTED PATHS AND CASING)
const GARAGE_DASHBOARD = 'Garage_Owner/garage_dashboard.php';
const CUSTOMER_DASHBOARD = 'customer_dashboard.php'; // Assuming customer_dashboard.php is in the root
const VENDOR_DASHBOARD = 'Vendor/vendor_dashboard.php';
const LOGIN_PAGE = 'index.html';

// Helper function to safely redirect
function redirect_with_status($status, $message, $target_page = LOGIN_PAGE, $role = null) {
    // If redirecting to the login page, append status messages via URL params
    if ($target_page === LOGIN_PAGE) {
        $redirect_url = LOGIN_PAGE . "?status=" . urlencode($status) . "&message=" . urlencode($message);
        if ($role) {
            $redirect_url .= "&role=" . urlencode($role);
        }
        header("Location: " . $redirect_url);
    } else {
        // If redirecting to a dashboard, we assume session variables carry status
        // and we only need the base target URL.
        header("Location: " . $target_page);
    }
=======
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
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
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
    
<<<<<<< HEAD
    $role_schema = $allowed_roles[$role_input] ?? null; 
=======
    $role_schema = $allowed_roles[$role_input] ?? null;
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060

    // 1. Basic validation
    if (empty($email) || empty($password) || empty($role_schema) || empty($contact) || empty($city) || empty($district)) {
        redirect_with_status('error', 'All required registration fields are missing.', LOGIN_PAGE);
    }
    
    // 1b. Business Name validation for Garage/Vendor
<<<<<<< HEAD
    $is_business_role = ($role_schema === 'Garage' || $role_schema === 'Vendor'); // NEW: Flag for business roles
    
    if ($is_business_role && empty($business_name)) {
        redirect_with_status('error', 'Business Name is required for ' . ucwords($role_schema) . ' registration.');
=======
    if (($role_schema === 'Garage' || $role_schema === 'Vendor') && empty($business_name)) {
        redirect_with_status('error', 'Business Name is required for ' . ucwords($role_schema) . ' registration.', LOGIN_PAGE);
>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
    }
    
    // **USING connect_db() defined in api_db_config.php**
    $db = connect_db(); 
    if (!$db) {
        redirect_with_status('error', 'Database connection failed. Check api_db_config.php.', LOGIN_PAGE);
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

<<<<<<< HEAD
        // B. Insert into Locations table
=======
        // B. Insert into Locations table (Need placeholder latitude/longitude based on schema)
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
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

<<<<<<< HEAD
        // C. Insert into Users table - ADDING INITIAL BALANCE AND APPROVAL STATUS LOGIC (Requires 'is_approved' column in Users table)
        // Column names used: email, password, contact, location_id, account_balance, is_approved
        $sql_user = "INSERT INTO Users (email, password, contact, location_id, account_balance, is_approved) VALUES (?, ?, ?, ?, ?, ?)";
=======
<<<<<<< HEAD
        // C. Insert into Users table
        $sql_user = "INSERT INTO Users (email, password, contact, location_id) VALUES (?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($db, $sql_user)) {
            mysqli_stmt_bind_param($stmt, "sssi", 
=======
        // C. Insert into Users table - ADDING INITIAL BALANCE LOGIC
        // Column names used: email, password, contact, location_id, account_balance
        $sql_user = "INSERT INTO Users (email, password, contact, location_id, account_balance) VALUES (?, ?, ?, ?, ?)";
>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
        
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
<<<<<<< HEAD
            // Bind parameters: 3 strings, 1 integer, 1 double, 1 integer (for is_approved)
            mysqli_stmt_bind_param($stmt, "sssidf", 
=======
            // Bind parameters: 3 strings, 1 integer, 1 double
            mysqli_stmt_bind_param($stmt, "sssid", 
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
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
<<<<<<< HEAD
=======
        // D1. Get the role_id from the Roles table
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
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
<<<<<<< HEAD
        redirect_with_status('success', "Registration successful! You are signed up as a " . ucwords($role_schema) . " (" . $business_name . "). You can now log in.", LOGIN_PAGE);
=======
        
        // --- REGISTRATION SUCCESS REDIRECT: Redirects back to index.html with success status ---
        
        // NEW: Custom success message based on role
        if ($is_business_role) {
            $success_msg = "Registration successful! Your business account is **pending approval** by the Administrator.";
        } else {
            $success_msg = "Registration successful! Your account is pre-funded with KES 10,000.00. Please log in as a " . ucwords($role_schema) . ".";
        }
        
        redirect_with_status('success', $success_msg); 
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060

    } catch (Exception $e) {
        // --- TRANSACTION ROLLBACK ---
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_with_status('error', $e->getMessage(), LOGIN_PAGE);
    }

} 
// --- Check for Login Form Submission ---
else if (isset($_POST['login_submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $submitted_role_input = $_POST['role'] ?? '';
    
    $submitted_role_schema = $allowed_roles[$submitted_role_input] ?? null;

    if (empty($email) || empty($password) || empty($submitted_role_schema)) {
        redirect_with_status('error', 'All fields are required for login.', LOGIN_PAGE);
    }
    
    $db = connect_db();
    if (!$db) {
        redirect_with_status('error', 'Database connection failed. Check api_db_config.php.', LOGIN_PAGE);
    }

    // SQL: Fetch user details, including password, role, and approval status (NEW)
    $sql = "SELECT 
                U.user_id, 
<<<<<<< HEAD
                U.password AS hashed_password, 
                R.role_name AS stored_role,
                U.is_approved
=======
                U.password AS hashed_password
>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
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
<<<<<<< HEAD
                // NOTE: U.is_approved is the 4th column selected in the SQL above (must match bind order)
                mysqli_stmt_bind_result($stmt, $user_id, $hashed_password, $stored_role, $is_approved);
=======
                mysqli_stmt_bind_result($stmt, $user_id, $hashed_password);
>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
                if (mysqli_stmt_fetch($stmt)) {
                    
                    // 2. Verify Password
                    if (password_verify($password, $hashed_password)) {
                        
<<<<<<< HEAD
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
=======
                        // 3. Set basic Session Variables
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['role'] = $submitted_role_schema;
                        
                        $dashboard_target = LOGIN_PAGE;
                        
                        // 4. Determine Dashboard and fetch specific ID
                        if ($submitted_role_schema == 'Garage') {
                            // Fetch garage_id
                            $sql_garage = "SELECT garage_id FROM Garages WHERE user_id = ?";
                            if ($stmt_g = mysqli_prepare($db, $sql_garage)) {
                                mysqli_stmt_bind_param($stmt_g, "i", $user_id);
                                mysqli_stmt_execute($stmt_g);
                                mysqli_stmt_bind_result($stmt_g, $garage_id);
                                if (mysqli_stmt_fetch($stmt_g)) {
                                    $_SESSION['garage_id'] = $garage_id;
                                    $dashboard_target = GARAGE_DASHBOARD;
                                }
                                mysqli_stmt_close($stmt_g);
                            }
                            // Fallback if garage ID fetch fails: stay on login page with error
                            if($dashboard_target === LOGIN_PAGE) {
                                redirect_with_status('error', 'Login successful, but Garage profile data is missing.', LOGIN_PAGE);
                            }
                        } elseif ($submitted_role_schema == 'Vendor') {
                            // Fetch vendor_id (FIXED)
                            $sql_vendor = "SELECT vendor_id FROM Vendors WHERE user_id = ?";
                            if ($stmt_v = mysqli_prepare($db, $sql_vendor)) {
                                mysqli_stmt_bind_param($stmt_v, "i", $user_id);
                                mysqli_stmt_execute($stmt_v);
                                mysqli_stmt_bind_result($stmt_v, $vendor_id);
                                if (mysqli_stmt_fetch($stmt_v)) {
                                    $_SESSION['vendor_id'] = $vendor_id; // CRITICAL: SESSION VARIABLE SET
                                    $dashboard_target = VENDOR_DASHBOARD;
                                }
                                mysqli_stmt_close($stmt_v);
                            }
                            // Fallback if vendor ID fetch fails: stay on login page with error
                            if($dashboard_target === LOGIN_PAGE) {
                                redirect_with_status('error', 'Login successful, but Vendor profile data is missing.', LOGIN_PAGE);
                            }
                        } else {
                            // Customer
                            $dashboard_target = CUSTOMER_DASHBOARD;
                        }

>>>>>>> b0c676c81ffc2f9edd4e68477bc7a010bb030fc7
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        
<<<<<<< HEAD
                        // 5. Redirect to the determined Dashboard
                        redirect_with_status('success', 'Login successful!', $dashboard_target);
                        
=======
                        // --- LOGIN SUCCESS REDIRECT: Sets session and redirects to the appropriate dashboard (no ID in URL) ---
                        redirect_with_status('success', 'Login successful!', $user_id, $stored_role);
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
                    } else {
                        // Invalid Password
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        redirect_with_status('error', 'Invalid password for the specified role and account.', LOGIN_PAGE);
                    }
                }
            } else {
                mysqli_stmt_close($stmt);
                mysqli_close($db);
<<<<<<< HEAD
                // The query returns 0 if the email doesn't exist OR if the email exists but doesn't have the selected role.
                redirect_with_status('error', 'Login failed. Account not found with that email and role combination.', LOGIN_PAGE);
=======
                redirect_with_status('error', 'Login failed. Account not found with that email and role combination.');
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
            }
        } else {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
<<<<<<< HEAD
            // Log real error here: mysqli_error($db)
            redirect_with_status('error', 'Login failed due to a database query error.', LOGIN_PAGE);
=======
            redirect_with_status('error', 'Login failed due to a database query error.');
>>>>>>> dd453477155b8a2181e05f84b2cef3fbef382060
        }
    } else {
        mysqli_close($db);
        redirect_with_status('error', 'Internal server error during login preparation (SQL preparation failed).', LOGIN_PAGE);
    }

} else {
    // If accessed directly without a POST, redirect back.
    redirect_with_status('error', 'Access denied. Use the forms to submit data.', LOGIN_PAGE);
}
?>