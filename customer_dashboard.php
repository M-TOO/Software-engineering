<?php
/**
 * CAASP Customer Dashboard (Desktop/Web Layout)
 * * Reads user identity from session, enforces authentication, and displays content
 * for service/part searching and transaction history using a multi-column, desktop-first UI.
 */

// Includes database configuration (and starts session)
require_once 'api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

// Ensure user is logged in AND is a Customer
if (!$user_id || $role !== 'Customer') {
    // Clear session and redirect to login if unauthorized
    session_unset();
    session_destroy();
    header("Location: index.html?status=error&message=" . urlencode("Access denied. Please log in as a Customer."));
    exit;
}

// Determine the current view (Home, Search, History, etc.)
$current_view = $_GET['view'] ?? 'home'; // Default to 'home'
$status_message = $_GET['message'] ?? '';
$status_type = $_GET['status'] ?? '';


// --- SEARCH & FILTER INPUTS ---
$search_query = trim($_GET['query'] ?? '');
$search_category = trim($_GET['category'] ?? ''); // From Quick Access links
$search_target_type = trim($_GET['target'] ?? ''); // NEW: Target type (Garage or Vendor)

// If a search query or category is present, force the 'search' view
if (!empty($search_query) || !empty($search_category)) {
    $current_view = 'search';
}


// --- DATA FETCHING FUNCTIONS ---

function fetch_customer_data($db, $user_id) {
    $data = ['email' => 'N/A', 'balance' => 0.00];
    $sql = "SELECT email, account_balance FROM Users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $email, $balance);
            if (mysqli_stmt_fetch($stmt)) {
                $data['email'] = $email;
                $data['balance'] = $balance;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $data;
}


/**
 * Executes a full search query based on user input (query, category, and optional target type).
 * This function performs targeted sequential searches (Garages OR Vendors OR BOTH).
 * Location and Price filters have been REMOVED for simplicity.
 * @param mysqli $db The database connection.
 * @return array List of matched businesses.
 */
function execute_full_search($db, $query, $category, $target_type) {
    
    $results = [];
    $is_home_view = empty($query) && empty($category) && empty($target_type);

    $bind_params_master = [];
    $bind_types_master = "";
    
    // --- BINDING AND FILTER LOGIC ---
    // The binding order must be consistent: 1. Item Name, 2. Business Name, 3. Category
    
    // 1. Keyword Search (Item and Business Name)
    if (!empty($query)) {
        $bind_params_master[] = "%{$query}%"; // Item Name placeholder
        $bind_params_master[] = "%{$query}%"; // Business Name placeholder
        $bind_types_master .= "ss";
    }

    // 2. Category Filter
    if (!empty($category)) {
        $bind_params_master[] = "%{$category}%"; // Item Name placeholder
        // Note: Category search currently relies only on Item Name
        $bind_types_master .= "s";
    }
    
    // Determine which searches to run
    $run_garage_search = (empty($target_type) || $target_type === 'Garage');
    $run_vendor_search = (empty($target_type) || $target_type === 'Vendor');
    
    // --- SET ORDER BY / LIMIT ---
    if ($is_home_view) {
        $order_by_limit = "ORDER BY RAND() LIMIT 6"; 
    } else {
        $order_by_limit = "LIMIT 20"; 
    }

    
    // --- Helper function to build the SQL WHERE clause components ---
    function get_where_components($is_garage, $query, $category) {
        $where_parts = [];
        $params_count = 0;
        
        // 1. Keyword Clause
        if (!empty($query)) {
            $item_col = $is_garage ? "S.service_name" : "P.part_name";
            $biz_col = $is_garage ? "G.garage_name" : "V.vendor_name";
            // Uses two placeholders: one for item, one for business name
            $where_parts[] = "({$item_col} LIKE ? OR {$biz_col} LIKE ?)"; 
            $params_count += 2;
        }

        // 2. Category Clause
        if (!empty($category)) {
            $item_col = $is_garage ? "S.service_name" : "P.part_name";
            // Uses one placeholder
            $where_parts[] = "{$item_col} LIKE ?";
            $params_count += 1;
        }
        
        $where_clause = "WHERE " . (empty($where_parts) ? "1=1" : implode(' AND ', $where_parts));
        
        // Return the WHERE clause and the number of parameters it requires
        return ['clause' => $where_clause, 'count' => $params_count];
    }

    $garage_components = get_where_components(true, $query, $category);
    $vendor_components = get_where_components(false, $query, $category);
    
    // Determine the subset of master parameters needed for the Garage query
    $garage_bind_params = array_slice($bind_params_master, 0, $garage_components['count']);
    $garage_bind_types = substr($bind_types_master, 0, $garage_components['count']);
    
    // Determine the subset of master parameters needed for the Vendor query
    $vendor_bind_params = array_slice($bind_params_master, 0, $vendor_components['count']);
    $vendor_bind_types = substr($bind_types_master, 0, $vendor_components['count']);


    // --- Execute Garage Search ---
    if ($run_garage_search) {
        $sql_garage_search = "
            SELECT 
                G.garage_name AS business_name,
                S.service_name AS item_name,
                S.service_price AS price,
                L.city,
                'Garage' AS type,
                G.garage_id AS entity_id,
                U.user_id AS business_user_id
            FROM Garages G
            INNER JOIN Services S ON G.garage_id = S.garage_id
            INNER JOIN Users U ON G.user_id = U.user_id
            INNER JOIN Locations L ON U.location_id = L.location_id
            {$garage_components['clause']}
            {$order_by_limit}
        ";
        
        if ($stmt = mysqli_prepare($db, $sql_garage_search)) {
            
            if (!empty($garage_bind_types) && count($garage_bind_params) > 0) {
                $refs = [];
                $refs[] = &$garage_bind_types; // Use the subset of types
                foreach ($garage_bind_params as $key => $value) {
                    $refs[] = &$garage_bind_params[$key]; // Use the subset of parameters
                }
                 
                if (!call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs))) {
                    error_log("Garage Bind Param Failed: " . mysqli_error($db));
                }
            }
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rating'] = 4.5;
                    $row['reviews'] = 130;
                    $row['image'] = 'https://placehold.co/400x200/3b82f6/ffffff?text=Garage+Service';
                    $results[] = $row;
                }
            } else {
                 error_log("Garage Search Execution Failed: " . mysqli_error($db));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("Garage Search Query Preparation Failed (Check Syntax): " . mysqli_error($db) . "\nQuery: " . $sql_garage_search);
        }
    }


    // --- Execute Vendor Search ---
    if ($run_vendor_search) {
        $sql_vendor_search = "
            SELECT 
                V.vendor_name AS business_name,
                P.part_name AS item_name,
                P.part_price AS price,
                L.city,
                'Vendor' AS type,
                V.vendor_id AS entity_id,
                U.user_id AS business_user_id
            FROM Vendors V
            INNER JOIN Parts P ON V.vendor_id = P.vendor_id
            INNER JOIN Users U ON V.user_id = U.user_id
            INNER JOIN Locations L ON U.location_id = L.location_id
            {$vendor_components['clause']}
            {$order_by_limit}
        ";
        
        if ($stmt = mysqli_prepare($db, $sql_vendor_search)) {
            
            // NOTE: We MUST reuse the SAME binding logic as the Garage search since the parameters are identical.
            if (!empty($vendor_bind_types) && count($vendor_bind_params) > 0) {
                $refs = [];
                $refs[] = &$vendor_bind_types;
                foreach ($vendor_bind_params as $key => $value) {
                    $refs[] = &$vendor_bind_params[$key];
                }
                
                if (!call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs))) {
                    error_log("Vendor Bind Param Failed: " . mysqli_error($db));
                }
            }

            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rating'] = 4.2;
                    $row['reviews'] = 85;
                    $row['image'] = 'https://placehold.co/400x200/ef4444/ffffff?text=Spare+Parts';
                    $results[] = $row;
                }
            } else {
                error_log("Vendor Search Execution Failed: " . mysqli_error($db));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("Vendor Search Query Preparation Failed (Check Syntax): " . mysqli_error($db) . "\nQuery: " . $sql_vendor_search);
        }
    }
    
    // CRITICAL FIX: The search results from both runs are now combined in $results.
    return $results;
}

/**
 * Fetches the transaction history for the logged-in user (REAL DATA).
 * @param mysqli $db The database connection.
 * @param int $user_id The ID of the customer.
 * @return array List of transactions.
 */
function fetch_transaction_history($db, $user_id) {
    $transactions = [];

    $sql = "
        SELECT
            T.transaction_id AS id,
            T.transaction_amount AS amount,
            T.status,
            T.created_at AS date,
            T.target_garage_id,
            T.target_vendor_id,
            T.service_id,
            T.part_id,
            
            COALESCE(G.garage_name, V.vendor_name) AS business,
            COALESCE(S.service_name, P.part_name) AS description,
            CASE
                WHEN T.service_id IS NOT NULL THEN 'Service'
                WHEN T.part_id IS NOT NULL THEN 'Part Order'
                ELSE 'Unknown'
            END AS type,
            COALESCE(GU.user_id, VU.user_id) AS target_user_id,
            R.rating_id IS NOT NULL AS has_rated

        FROM Transactions T
        LEFT JOIN Services S ON T.service_id = S.service_id
        LEFT JOIN Parts P ON T.part_id = P.part_id
        
        LEFT JOIN Garages G ON T.target_garage_id = G.garage_id
        LEFT JOIN Vendors V ON T.target_vendor_id = V.vendor_id
        
        LEFT JOIN Users GU ON G.user_id = GU.user_id
        LEFT JOIN Users VU ON V.user_id = VU.user_id

        LEFT JOIN Ratings R ON T.transaction_id = R.transaction_id
        
        WHERE T.initiator_user_id = ?
        ORDER BY T.created_at DESC
    ";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $row['can_rate'] = ($row['status'] === 'Completed' && !$row['has_rated']);
                $row['date'] = date('Y-m-d', strtotime($row['date']));
                $transactions[] = $row;
            }
        } else {
             error_log("Transaction History Query Execution Failed: " . mysqli_error($db));
        }
        mysqli_stmt_close($stmt);
    } else {
         error_log("Transaction History Query Preparation Failed: " . mysqli_error($db));
    }

    return $transactions;
}


$db = connect_db();
$customer_data = $db ? fetch_customer_data($db, $user_id) : ['email' => 'Database Offline', 'balance' => 0.00];
$customer_email = $customer_data['email'];
$customer_balance = $customer_data['balance'];

$search_results = [];
$transaction_history = [];

if ($db) {
    // NOTE: Passing empty string for $search_location and $price_sort (removed inputs)
    if ($current_view === 'home') {
        // Home view runs both searches with no query
        $search_results = execute_full_search($db, '', '', ''); 
    } elseif ($current_view === 'search') {
        // Search view uses query/category and target type (if available)
        $search_results = execute_full_search($db, $search_query, $search_category, $search_target_type);
    } elseif ($current_view === 'history') {
        $transaction_history = fetch_transaction_history($db, $user_id);
    }
    
    mysqli_close($db);
} 
// If search results are empty, provide a failure message instead of falling back to a static array.
if (empty($search_results) && ($current_view === 'home' || $current_view === 'search')) {
    $no_results_message = "We couldn't find any listings matching your criteria. Try broadening your search or check back later! (Ensure your database has Garages/Vendors with Services/Parts)";
}


// --- End of PHP Logic ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - AutoHub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script> 
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f8fafc;
            /* Added transition for smoother collapse */
            transition: margin-left 0.3s ease-in-out;
        }
        
        /* Main Grid Layout for Desktop */
        .dashboard-grid {
            display: grid;
            /* **CHANGE: Reduced open sidebar width to 200px** */
            grid-template-columns: 200px 1fr; 
            min-height: 100vh;
            /* Added transition for smoother collapse */
            transition: grid-template-columns 0.3s ease-in-out;
        }

        /* Collapsed Grid State */
        .sidebar-collapsed .dashboard-grid {
            /* **CHANGE: Reduced collapsed sidebar width to 70px** */
            grid-template-columns: 70px 1fr; 
        }
        
        /* Styles for the Sidebar */
        .sidebar {
            background-color: #0f172a; /* Dark Blue/Slate */
            color: #f8fafc;
            padding: 2rem 0;
            position: fixed;
            height: 100%;
            /* **CHANGE: Reduced open sidebar width to 200px** */
            width: 200px; /* Base width */
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: width 0.3s ease-in-out;
            overflow-x: hidden; /* Hide overflowing text during collapse */
        }

        /* Collapsed Sidebar State */
        .sidebar-collapsed .sidebar {
            /* **CHANGE: Reduced collapsed sidebar width to 70px** */
            width: 70px; /* Collapsed width */
        }
        
        /* Sidebar Link Styles */
        .nav-link {
            display: flex;
            align-items: center;
            /* **CHANGE: Reduced vertical padding** */
            padding: 0.6rem 1rem;
            margin: 0.5rem 0;
            transition: background-color 0.2s, color 0.2s, padding 0.3s;
            border-left: 4px solid transparent;
            white-space: nowrap; /* Prevent wrapping of text */
            font-size: 0.875rem; /* text-sm for smaller text */
        }

        /* Adjust padding for collapsed state */
        .sidebar-collapsed .nav-link {
            /* Adjust padding to keep icon centered in 70px width */
            padding-left: 1rem; 
            padding-right: 1rem;
            justify-content: center; /* Center content in collapsed state */
        }

        /* Hide text elements during collapse */
        .sidebar-text {
            transition: opacity 0.3s ease-in-out;
        }
        .sidebar-collapsed .sidebar-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
            display: none;
        }

        .nav-link:hover {
            background-color: #1e293b;
        }
        .nav-active {
            background-color: #1e293b;
            border-left-color: #10B981; /* Emerald active color */
            color: #10B981;
            font-weight: 600;
        }
        .nav-active .nav-icon {
            color: #10B981;
        }
        
        /* Main Content Area */
        .main-content {
            grid-column: 2 / 3;
            padding: 2rem;
        }

        /* Business Card Styles */
        .business-card {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .business-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        /* Media query for mobile responsiveness (to avoid fixed sidebar on small screens) */
        @media (max-width: 768px) {
             .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none; /* Hide sidebar on small screen; can be replaced with a top menu if needed */
            }
            .main-content {
                grid-column: 1 / 2;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    
    <div class="dashboard-grid" id="dashboardGrid">
        
        <!-- Fixed Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="px-3 mb-6 flex items-center justify-between">
                <div class="sidebar-text">
                    <!-- **CHANGE: Reduced font size to 2xl** -->
                    <h1 class="text-2xl font-extrabold text-emerald-500">AutoHub</h1>
                    <p class="text-xs text-gray-400 mt-1">Customer Portal</p>
                </div>
                 <!-- Collapse Button inside the sidebar (visible in open state) -->
                <button onclick="toggleSidebar()" class="text-gray-400 hover:text-emerald-500 transition duration-200 p-2 rounded-full">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
            </div>

            <nav>
                <a href="?view=home" class="nav-link <?= $current_view === 'home' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span class="sidebar-text">Dashboard</span>
                </a>
                <a href="?view=search" class="nav-link <?= $current_view === 'search' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="search" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span class="sidebar-text">Search & Find</span>
                </a>
                <a href="?view=history" class="nav-link <?= $current_view === 'history' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="history" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span class="sidebar-text">Transaction History</span>
                </a>
                <a href="message.php" class="nav-link <?= $current_view === 'message' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="message-circle" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span class="sidebar-text">Messages</span>
                </a>
            </nav>

            <div class="absolute bottom-6 left-0 right-0 px-3">
                <div class="border-t border-gray-700 pt-4 mb-3 sidebar-text">
                    <p class="text-sm font-semibold text-gray-300"><?= htmlspecialchars($customer_email) ?></p>
                </div>
                <a href="index.html" class="flex items-center text-red-400 hover:text-red-300 text-sm font-medium nav-link justify-start">
                    <i data-lucide="log-out" class="w-5 h-5 mr-2"></i>
                    <span class="sidebar-text">Log Out</span>
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            
            <!-- Top Header & Search -->
            <header class="mb-8 flex justify-between items-center">
                <h2 class="text-3xl font-bold text-gray-900">
                    <?= $current_view === 'home' ? 'Welcome Back!' : (ucwords($current_view) . ' View') ?>
                </h2>
                <div class="flex items-center space-x-4">
                    <!-- Balance Display -->
                    <div class="bg-blue-500 text-white rounded-lg px-4 py-2 font-semibold flex items-center shadow-md">
                        <i data-lucide="wallet" class="w-5 h-5 mr-2"></i>
                        KES <?= number_format($customer_balance, 2) ?>
                    </div>
                    <!-- Notification Bell -->
                    <button class="text-gray-500 hover:text-gray-700">
                        <i data-lucide="bell" class="w-6 h-6"></i>
                    </button>
                    <!-- User Profile Icon -->
                    <a href="?view=profile" class="text-gray-500 hover:text-gray-700">
                         <i data-lucide="circle-user" class="w-8 h-8"></i>
                    </a>
                </div>
            </header>
            
            <!-- Status Message Display (New) -->
            <?php if (!empty($status_message)): ?>
                <div class="p-4 rounded-lg mb-6 <?= $status_type === 'success' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300' ?> border" role="alert">
                    <p class="font-semibold"><?= htmlspecialchars($status_message) ?></p>
                </div>
            <?php endif; ?>

            <!-- Search Section (Always visible on Home/Search views) -->
            <?php if ($current_view === 'home' || $current_view === 'search'): ?>

            <section class="mb-10 p-6 bg-white rounded-xl shadow-lg border-t-4 border-emerald-500">
                <h3 class="text-xl font-bold mb-4 text-gray-800">Find Your Service or Part</h3>
                <form method="GET" action="customer_dashboard.php">
                    <input type="hidden" name="view" value="search">
                    <div class="flex space-x-3">
                        <div class="relative flex-grow">
                            <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="query" placeholder="Search for garage services or spare parts by keyword" 
                                value="<?= htmlspecialchars($search_query) ?>"
                                class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                        </div>
                        
                        <!-- REMOVED: Location and Price Filters -->
                        
                        <button type="submit" class="bg-emerald-600 text-white px-6 py-3 rounded-xl font-semibold hover:bg-emerald-700 transition duration-150">Search</button>
                    </div>
                </form>
            </section>

            <!-- Conditional Content based on View (Home vs. Search Results) -->
            <?php if (!empty($search_results)): ?>

                <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">
                    <?= $current_view === 'search' ? 'Search Results' : 'Featured Businesses' ?> (<?= count($search_results) ?> Found)
                </h3>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <?php foreach ($search_results as $biz): // Looping over search results ?>
                        <div class="business-card bg-white rounded-xl overflow-hidden flex shadow-lg">
                            <!-- Image Side -->
                            <div class="w-1/3 h-40 bg-gray-200" style="background-image: url('<?= $biz['image'] ?>'); background-size: cover; background-position: center;">
                            </div>
                            <!-- Details Side -->
                            <div class="w-2/3 p-4 flex flex-col justify-between">
                                <div>
                                    <div class="flex justify-between items-start">
                                        <h4 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($biz['business_name']) ?></h4>
                                        <span class="text-xs font-medium px-3 py-1 rounded-full <?= $biz['type'] === 'Garage' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
                                            <?= $biz['type'] ?>
                                        </span>
                                    </div>
                                    <p class="text-sm font-medium text-gray-700 mt-1"><?= htmlspecialchars($biz['item_name']) ?></p>
                                    <p class="text-sm text-gray-600 flex items-center">
                                        <i data-lucide="map-pin" class="w-4 h-4 mr-1 text-gray-400"></i> <?= $biz['city'] ?>
                                    </p>
                                </div>
                                <div class="flex justify-between items-end">
                                    <p class="text-lg font-bold <?= $biz['type'] === 'Garage' ? 'text-emerald-600' : 'text-red-600' ?>">
                                        KES <?= number_format($biz['price'], 2) ?>
                                    </p>
                                    <a href="business_profile.php?type=<?= $biz['type'] ?>&id=<?= $biz['entity_id'] ?>" 
                                        class="bg-blue-600 text-white px-5 py-2 rounded-lg font-semibold hover:bg-blue-700 transition duration-150 text-sm">
                                        View Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($current_view === 'home' || $current_view === 'search'): ?>
                <div class="p-6 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded-lg shadow-md">
                    <p class="font-semibold text-lg mb-2">No Listings Found</p>
                    <p><?= $no_results_message ?? "We couldn't find any listings currently available in the system. Try broadening your search or check back later! (Ensure your database has Garages/Vendors with Services/Parts)" ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Quick Access Column (Always visible on home/search views) -->
            <?php if ($current_view === 'home' || $current_view === 'search'): ?>
                <section class="mt-8">
                    <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">Quick Access Categories</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php
                        // START FIX: Simplified category keywords for better search matching
                        $quick_links = [
                            ['title' => 'Tires & Alignment', 'icon' => 'gauge', 'color' => 'blue', 'keyword' => 'tire', 'target' => 'Garage'],
                            ['title' => 'Oil Change Services', 'icon' => 'droplet', 'color' => 'red', 'keyword' => 'oil', 'target' => 'Garage'],
                            ['title' => 'Brake Systems', 'icon' => 'disc-3', 'color' => 'purple', 'keyword' => 'brake', 'target' => 'Vendor'],
                            ['title' => 'Engine Parts', 'icon' => 'settings', 'color' => 'yellow', 'keyword' => 'engine', 'target' => 'Vendor'],
                        ];
                        foreach ($quick_links as $link):
                        ?>
                        <a href="?view=search&category=<?= $link['keyword'] ?>&target=<?= $link['target'] ?>" class="icon-box p-4 rounded-xl flex flex-col items-center bg-white shadow-md hover:shadow-lg transition duration-200 text-center">
                            <div class="w-12 h-12 bg-<?= $link['color'] ?>-100 rounded-full flex items-center justify-center mb-3">
                                <i data-lucide="<?= $link['icon'] ?>" class="w-6 h-6 text-<?= $link['color'] ?>-600"></i>
                            </div>
                            <p class="font-semibold text-sm text-gray-800"><?= $link['title'] ?></p>
                        </a>
                        <?php endforeach; ?>
                        <!-- END FIX -->
                    </div>
                </section>
            <?php endif; ?>
            

            <?php elseif ($current_view === 'history'): ?>
                <!-- History View -->
                <section class="p-6 bg-white rounded-xl shadow-lg">
                    <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">Transaction History</h3>
                    <!-- Recharge Wallet Section (New) -->
                    <div class="mb-6 p-4 bg-gray-100 rounded-lg flex justify-between items-center">
                        <div class="text-xl font-semibold text-gray-800 flex items-center">
                            <i data-lucide="wallet" class="w-6 h-6 mr-3 text-blue-600"></i>
                            Current Balance: <span class="ml-2 text-blue-600">KES <?= number_format($customer_balance, 2) ?></span>
                        </div>
                        <form action="transaction_handler.php" method="POST" class="flex space-x-2">
                            <input type="hidden" name="action" value="recharge">
                            <input type="number" name="recharge_amount" placeholder="Amount (KES)" required min="100" step="100"
                                class="p-2 border border-gray-300 rounded-lg w-36 text-sm focus:ring-blue-500 focus:border-blue-500">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-blue-700 transition text-sm">Recharge Account</button>
                        </form>
                    </div>
                    <!-- End Recharge Section -->
                    
                    <div class="space-y-4">
                        <p class="text-gray-600">Below is a list of your past service and part transactions. (Data from the **Transactions** table)</p>
                        
                        <div class="overflow-x-auto shadow-md rounded-lg">
                            <table class="min-w-full bg-white border-collapse">
                                <thead class="bg-gray-100">
                                    <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                                        <th class="p-4 border-b">T-ID</th>
                                        <th class="p-4 border-b">Item</th>
                                        <th class="p-4 border-b">Business</th>
                                        <th class="p-4 border-b">Amount</th>
                                        <th class="p-4 border-b">Status</th>
                                        <th class="p-4 border-b">Date</th>
                                        <th class="p-4 border-b">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (!empty($transaction_history)): ?>
                                        <?php foreach ($transaction_history as $transaction): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($transaction['id']) ?></td>
                                                <td class="p-4 text-sm font-medium <?= $transaction['type'] === 'Service' ? 'text-blue-600' : 'text-purple-600' ?>"><?= htmlspecialchars($transaction['description']) ?> </td>
                                                <td class="p-4 text-sm"><?= htmlspecialchars($transaction['business']) ?> (<?= $transaction['type'] ?>)</td>
                                                <td class="p-4 text-sm font-bold text-gray-700">KES <?= number_format($transaction['amount'], 2) ?></td>
                                                <td class="p-4">
                                                    <?php
                                                        $status_class = 'bg-gray-100 text-gray-800';
                                                        if (strpos($transaction['status'], 'Completed') !== false) {
                                                            $status_class = 'bg-green-100 text-green-800';
                                                        } elseif (strpos($transaction['status'], 'Pending') !== false) {
                                                            $status_class = 'bg-blue-100 text-blue-800';
                                                        } elseif (strpos($transaction['status'], 'Cancelled') !== false) {
                                                            $status_class = 'bg-red-100 text-red-800';
                                                        }
                                                    ?>
                                                    <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_class ?>"><?= htmlspecialchars($transaction['status']) ?></span>
                                                </td>
                                                <td class="p-4 text-sm"><?= htmlspecialchars($transaction['date']) ?></td>
                                                <td class="p-4 space-x-2">
                                                    <?php if ($transaction['status'] === 'Completed' && !$transaction['has_rated']): ?>
                                                        <button class="bg-yellow-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-yellow-600">⭐ Rate</button>
                                                    <?php elseif ($transaction['status'] === 'Completed' && $transaction['has_rated']): ?>
                                                        <button class="bg-gray-400 text-white px-3 py-1 rounded-lg text-sm cursor-not-allowed" disabled>Rated</button>
                                                    <?php elseif ($transaction['status'] === 'Pending' && $transaction['target_user_id']): ?>
                                                        <!-- Button to finalize purchase/service -->
                                                        <form action="transaction_handler.php" method="POST" class="inline-block">
                                                            <input type="hidden" name="action" value="finalize">
                                                            <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                                                            <!-- Display amount due and check if balance is sufficient -->
                                                            <?php if ($customer_balance >= $transaction['amount']): ?>
                                                                <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-green-700">✅ Pay KES <?= number_format($transaction['amount'], 2) ?></button>
                                                            <?php else: ?>
                                                                <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-lg text-sm cursor-not-allowed" disabled>❌ Insufficient Funds</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    <?php endif; ?>
                                                    <a href="message.php?target_user_id=<?= $transaction['target_user_id'] ?>" class="bg-blue-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-blue-600 inline-flex items-center justify-center">💬 Chat</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="p-4 text-center text-gray-500">You have no transaction history yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>


            <?php elseif ($current_view === 'profile'): ?>
                <!-- Profile View Placeholder -->
                <section class="p-6 bg-white rounded-xl shadow-lg">
                    <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">
                        Profile Management
                    </h3>
                    <div class="space-y-4">
                        <p class="text-gray-600">This section is currently under development.</p>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <p class="text-lg font-semibold">User: <?= htmlspecialchars($customer_email) ?></p>
                            <p class="text-sm text-gray-500">You are logged in as a Customer.</p>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </main>

    </div>
    
    <!-- JavaScript for Sidebar Collapse -->
    <script>
        // Function to toggle the sidebar state
        function toggleSidebar() {
            const body = document.body;
            // Check current state (using class on body)
            const isCollapsed = body.classList.toggle('sidebar-collapsed');

            // Save state to local storage (optional, but good for persistence)
            localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'open');
        }

        // Load saved state on page load
        window.onload = function() {
            lucide.createIcons();
            const savedState = localStorage.getItem('sidebarState');
            if (savedState === 'collapsed') {
                document.body.classList.add('sidebar-collapsed');
            }
        };
    </script>
</body>
</html>