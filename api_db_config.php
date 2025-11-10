<?php
/**
 * Database Configuration for CAASP (MySQLi)
 * * Defines global database credentials and the reusable connect_db function.
 * This file is included via require_once in auth_handler.php and all dashboard files.
 */

// Database Connection Details
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); 
define('DB_PASSWORD', '');
define('DB_NAME', 'caasp_db'); 
// --- Session Management ---
// Start the session *before* any output is sent.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials 
$db_host = "localhost";
$db_user = "root";
$db_pass = ""; 
$db_name = "caasp_db"; 

/**
 * Establishes a connection to the MySQL database.
 * @return mysqli|false The database connection resource or false on failure.
 */
function connect_db() {
    // These variables are available globally because they were declared outside the function scope
    global $db_host, $db_user, $db_pass, $db_name;
    
    $db = mysqli_connect("localhost", "root", "", "caasp_db");
    
    if (mysqli_connect_errno()) {
        // Log the detailed error but return a generic failure message
        error_log("Failed to connect to MySQL: " . mysqli_connect_error());
        return false;
    }
    
    return $db;
}

?>
