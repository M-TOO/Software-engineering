<?php
/**
 * CAASP Database Configuration
 * * Defines constants for connecting to the database.
 * IMPORTANT: Replace the placeholder values below with your actual database credentials.
 */

// Database Connection Details
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // CHANGE THIS
define('DB_PASSWORD', ''); // CHANGE THIS
define('DB_NAME', 'caasp'); // CHANGE THIS

/**
 * Connects to the database and returns the connection object.
 * @return mysqli|null The database connection object or null on failure.
 */
function connect_db() {
    $link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

    // Check connection
    if ($link === false) {
        // In a real application, log this error instead of echoing it publicly
        return null;
    }
    return $link;
}

?>
