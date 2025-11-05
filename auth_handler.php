<?php
/**
 * CAASP Authentication Handler
 * * Processes synchronous form submissions for registration and login,
 * ensuring compliance with the CAASP database schema (Locations, Users, Roles, UserRole, Garages, Vendors tables).
 */

// Include database configuration (assuming this file is in the same directory)
require_once 'api_db_config.php';

// Helper function to safely redirect
function redirect_with_status($status, $message, $role = null) {
    // Start with the base URL and status/message
    $redirect_url = "index.html?status=" . urlencode($status) . "&message=" . urlencode($message);
    
    // Append the role if provided (useful for post-login redirect or debugging)
    if ($role) {
        $redirect_url .= "&role=" . urlencode($role);
    }
    
    header("Location: " . $redirect_url);
    exit;
}

// Allowed roles for validation (Converts form input to schema name)
$allowed_roles = [
    'customer' => 'Customer', 
    'garage_owner' => 'Garage', 
    'vendor' => 'Vendor'
];

// --- Check for Registration Form Submission ---
if (isset($_POST['register_submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role_input = $_POST['role'] ?? ''; 
    // Additional fields from index.html form
    $contact = trim($_POST['contact']);
    $city = trim($_POST['city']);
    $district = trim($_POST['district']);
    // NEW OPTIONAL FIELD: business_name (Garage/Vendor Name)
    $business_name = trim($_POST['business_name'] ?? '');
    
    $role_schema = $allowed_roles[$role_input] ?? null; // Convert input to schema name (e.g., 'vendor' -> 'Vendor')

    // 1. Basic validation
    if (empty($email) || empty($password) || empty($role_schema) || empty($contact) || empty($city) || empty($district)) {
        redirect_with_status('error', 'All required registration fields are missing.');
    }
    
    // 1b. Business Name validation for Garage/Vendor
    if (($role_schema === 'Garage' || $role_schema === 'Vendor') && empty($business_name)) {
        redirect_with_status('error', 'Business Name is required for ' . ucwords($role_schema) . ' registration.');
    }
    
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
        // Using placeholder values as required by the schema's NOT NULL constraints.
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

        // C. Insert into Users table
        // Column names used: email, password, contact, location_id
        $sql_user = "INSERT INTO Users (email, password, contact, location_id) VALUES (?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($db, $sql_user)) {
            // Bind parameters: 3 strings, 1 integer
            mysqli_stmt_bind_param($stmt, "sssi", 
                $param_email, 
                $param_password, 
                $param_contact, 
                $param_location_id
            );
            $param_email = $email;
            $param_password = $hashed_password; 
            $param_contact = $contact;
            $param_location_id = $location_id;
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to create user account.');
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

        // F. Commit the transaction if all inserts succeeded
        mysqli_commit($db);
        mysqli_close($db);
        redirect_with_status('success', "Registration successful! You are signed up as a " . ucwords($role_schema) . " (" . $business_name . "). You can now log in.");

    } catch (Exception $e) {
        // --- TRANSACTION ROLLBACK ---
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_with_status('error', $e->getMessage());
    }

} 
// --- Check for Login Form Submission (No changes needed here for this request) ---
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

    // SQL: Fetch user by email, including their hashed password, and join to get their actual role name(s)
    $sql = "SELECT 
                U.user_id, 
                U.password AS hashed_password, 
                R.role_name AS stored_role 
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
                mysqli_stmt_bind_result($stmt, $user_id, $hashed_password, $stored_role);
                if (mysqli_stmt_fetch($stmt)) {
                    if (password_verify($password, $hashed_password)) {
                        // Success!
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        
                        redirect_with_status('success', 'Login successful! Welcome back, ' . ucwords($stored_role) . '.', $submitted_role_input);
                    } else {
                        mysqli_stmt_close($stmt);
                        mysqli_close($db);
                        redirect_with_status('error', 'Invalid password for the specified role and account.');
                    }
                }
            } else {
                mysqli_stmt_close($stmt);
                mysqli_close($db);
                // The query returns 0 if the email doesn't exist OR if the email exists but doesn't have the selected role.
                redirect_with_status('error', 'Login failed. Account not found with that email and role combination.');
            }
        } else {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            // Log real error here: mysqli_error($db)
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
