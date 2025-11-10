<?php
/**
 * CAASP Admin Dashboard
 * * Manages business approvals, user accounts, and content moderation.
 * * NOTE: This file assumes the database has the 'is_approved' column in the Users table 
 * and the 'Admin' role in the Roles table.
 */

// Includes database configuration (and starts session)
require_once 'api_db_config.php';

// --- AUTHENTICATION CHECK ---
// This relies on the user logging in successfully via auth_handler.php 
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

// Ensure user is logged in AND is an Admin
if (!$user_id || $role !== 'Admin') {
    session_unset();
    session_destroy();
    header("Location: index.html?status=error&message=" . urlencode("Access denied. Admin login required."));
    exit;
}


// Determine the current view
$current_view = $_GET['view'] ?? 'pending'; // Default to 'pending' approvals
$status_message = $_GET['message'] ?? '';
$status_type = $_GET['status'] ?? '';


// --- DATA FETCHING FUNCTIONS ---

/**
 * Fetches businesses awaiting admin approval (is_approved = 0).
 */
function fetch_pending_businesses($db) {
    $pending_list = [];
    $sql = "
        SELECT 
            U.user_id, U.email, U.contact, U.created_at, R.role_name AS role, 
            COALESCE(G.garage_name, V.vendor_name) AS business_name 
        FROM Users U
        JOIN UserRole UR ON U.user_id = UR.user_id
        JOIN Roles R ON UR.role_id = R.role_id
        LEFT JOIN Garages G ON U.user_id = G.user_id
        LEFT JOIN Vendors V ON U.user_id = V.user_id
        WHERE R.role_name IN ('Garage', 'Vendor') AND U.is_approved = 0 
        ORDER BY U.created_at ASC
    ";
    
    if ($stmt = mysqli_prepare($db, $sql)) {
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $pending_list[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $pending_list;
}

/**
 * Fetches all active/approved listings for moderation/review.
 */
function fetch_all_listings($db) {
    // This fetches the last 10 services and parts across all businesses
    $listings = [];
    
    // Services Query
    $sql_services = "
        SELECT 
            S.service_id AS item_id, 
            S.service_name AS item_name, 
            S.service_price AS price, 
            'Service' AS type, 
            G.garage_name AS business_name
        FROM Services S 
        JOIN Garages G ON S.garage_id = G.garage_id
        ORDER BY S.service_id DESC LIMIT 10
    ";
    
    // Parts Query
    $sql_parts = "
        SELECT 
            P.part_id AS item_id, 
            P.part_name AS item_name, 
            P.part_price AS price, 
            'Part' AS type, 
            V.vendor_name AS business_name
        FROM Parts P 
        JOIN Vendors V ON P.vendor_id = V.vendor_id
        ORDER BY P.part_id DESC LIMIT 10
    ";

    // Combine results
    if ($result = mysqli_query($db, $sql_services)) {
        while ($row = mysqli_fetch_assoc($result)) $listings[] = $row;
    }
    if ($result = mysqli_query($db, $sql_parts)) {
        while ($row = mysqli_fetch_assoc($result)) $listings[] = $row;
    }
    // Note: In a large app, this combining would be done more efficiently.
    return $listings;
}


$db = connect_db();
$pending_businesses = [];
$all_listings = [];

// Data fetching proceeds
if ($db) {
    if ($current_view === 'pending') {
        $pending_businesses = fetch_pending_businesses($db);
    } elseif ($current_view === 'listings') {
        $all_listings = fetch_all_listings($db);
    }
    mysqli_close($db);
} 


// --- End of PHP Logic ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AutoHub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script> 
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .dashboard-grid { 
            display: grid;
            grid-template-columns: 200px 1fr; 
            min-height: 100vh; 
        }
        .sidebar { background-color: #4338ca; color: #f8fafc; } /* Indigo for Admin */
        .nav-link { padding: 0.6rem 1rem; margin: 0.5rem 0; border-left: 4px solid transparent; }
        .nav-link:hover { background-color: #4f46e5; }
        .nav-active { background-color: #4f46e5; border-left-color: #c4b5fd; color: #c4b5fd; font-weight: 600; }
        .nav-active .nav-icon { color: #c4b5fd; }
    </style>
</head>
<body>
    
    <div class="dashboard-grid" id="dashboardGrid">
        
        <aside class="sidebar">
            <div class="px-3 mb-8 pt-4">
                <h1 class="text-2xl font-extrabold text-white">CAASP Admin</h1>
                <p class="text-xs text-indigo-200 mt-1">System Control Panel</p>
            </div>

            <nav>
                <a href="?view=pending" class="nav-link <?= $current_view === 'pending' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="shield-alert" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span>Pending Approvals</span>
                </a>
                <a href="?view=users" class="nav-link <?= $current_view === 'users' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="users" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span>Manage Users</span>
                </a>
                <a href="?view=listings" class="nav-link <?= $current_view === 'listings' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="database" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span>Listings & Content</span>
                </a>
                <a href="?view=reports" class="nav-link <?= $current_view === 'reports' ? 'nav-active' : 'text-gray-300' ?>">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 mr-3 nav-icon"></i>
                    <span>Reports</span>
                </a>
            </nav>

            <div class="absolute bottom-6 left-0 right-0 px-3">
                <a href="index.html" class="flex items-center text-red-300 hover:text-red-100 text-sm font-medium nav-link justify-start">
                    <i data-lucide="log-out" class="w-5 h-5 mr-2"></i>
                    <span>Log Out</span>
                </a>
            </div>
        </aside>

        <main class="main-content">
            
            <header class="mb-8 flex justify-between items-center">
                <h2 class="text-3xl font-bold text-gray-900">
                    <?= ucwords(str_replace('_', ' ', $current_view)) ?> Management
                </h2>
            </header>
            
            <?php if (!empty($status_message)): ?>
                <div class="p-4 rounded-lg mb-6 <?= $status_type === 'success' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300' ?> border" role="alert">
                    <p class="font-semibold"><?= htmlspecialchars($status_message) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($current_view === 'pending'): ?>
                <section class="p-6 bg-white rounded-xl shadow-lg">
                    <h3 class="text-2xl font-bold mb-5 text-gray-800 border-b pb-2">Pending Business Registration Requests (Awaiting Approval)</h3>
                    
                    <div class="overflow-x-auto shadow-md rounded-lg">
                        <table class="min-w-full bg-white border-collapse">
                            <thead class="bg-gray-100">
                                <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                                    <th class="p-4 border-b">User ID</th>
                                    <th class="p-4 border-b">Business Name</th>
                                    <th class="p-4 border-b">Role</th>
                                    <th class="p-4 border-b">Contact Email</th>
                                    <th class="p-4 border-b">Registered</th>
                                    <th class="p-4 border-b">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (!empty($pending_businesses)): ?>
                                    <?php foreach ($pending_businesses as $business): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($business['user_id']) ?></td>
                                            <td class="p-4 text-sm font-medium"><?= htmlspecialchars($business['business_name'] ?? 'N/A') ?></td>
                                            <td class="p-4 text-sm text-indigo-600 font-bold"><?= htmlspecialchars($business['role']) ?></td>
                                            <td class="p-4 text-sm"><?= htmlspecialchars($business['email']) ?></td>
                                            <td class="p-4 text-sm"><?= date('Y-m-d', strtotime($business['created_at'])) ?></td>
                                            <td class="p-4 space-x-2">
                                                <form action="admin_handler.php" method="POST" class="inline-block">
                                                    <input type="hidden" name="action" value="approve_business">
                                                    <input type="hidden" name="user_id" value="<?= $business['user_id'] ?>">
                                                    <button type="submit" class="bg-green-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-green-600">✅ Approve</button>
                                                </form>
                                                <form action="admin_handler.php" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to reject this account? This will permanently suspend access.');">
                                                    <input type="hidden" name="action" value="reject_business">
                                                    <input type="hidden" name="user_id" value="<?= $business['user_id'] ?>">
                                                    <button type="submit" class="bg-red-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-red-600">❌ Reject</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="p-4 text-center text-gray-500">No pending business accounts requiring approval.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                
            <?php elseif ($current_view === 'listings'): ?>
                <section class="p-6 bg-white rounded-xl shadow-lg">
                    <h3 class="text-2xl font-bold mb-5 text-gray-800 border-b pb-2">All Active Listings (Services & Parts)</h3>
                    <p class="text-gray-600 mb-4">You can remove any inappropriate listings directly from the platform.</p>
                    
                    <div class="overflow-x-auto shadow-md rounded-lg">
                        <table class="min-w-full bg-white border-collapse">
                            <thead class="bg-gray-100">
                                <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                                    <th class="p-4 border-b">ID</th>
                                    <th class="p-4 border-b">Item Name</th>
                                    <th class="p-4 border-b">Type</th>
                                    <th class="p-4 border-b">Price</th>
                                    <th class="p-4 border-b">Business</th>
                                    <th class="p-4 border-b">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (!empty($all_listings)): ?>
                                    <?php foreach ($all_listings as $listing): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($listing['item_id']) ?></td>
                                            <td class="p-4 text-sm font-medium"><?= htmlspecialchars($listing['item_name']) ?></td>
                                            <td class="p-4 text-sm <?= $listing['type'] === 'Service' ? 'text-emerald-600' : 'text-red-600' ?>"><?= htmlspecialchars($listing['type']) ?></td>
                                            <td class="p-4 text-sm">KES <?= number_format($listing['price'], 2) ?></td>
                                            <td class="p-4 text-sm"><?= htmlspecialchars($listing['business_name']) ?></td>
                                            <td class="p-4">
                                                <form action="admin_handler.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this listing?');">
                                                    <input type="hidden" name="action" value="delete_listing">
                                                    <input type="hidden" name="item_type" value="<?= $listing['type'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= $listing['item_id'] ?>">
                                                    <button type="submit" class="bg-red-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-red-600">🗑️ Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="p-4 text-center text-gray-500">No active listings found in the system.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                
            <?php elseif ($current_view === 'users'): ?>
                <div class="p-6 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded-lg shadow-md">
                    <p class="font-semibold text-lg mb-2">Manage All Users</p>
                    <p>This section is for suspending/activating any user (Customer, Garage, Vendor). Logic requires fetching all users and their current `is_approved` status.</p>
                </div>

            <?php elseif ($current_view === 'reports'): ?>
                <div class="p-6 bg-blue-100 border border-blue-300 text-blue-800 rounded-lg shadow-md">
                    <p class="font-semibold text-lg mb-2">System Reports</p>
                    <p>This section would generate reports like **Top-Rated Garages**, **Most Viewed Parts**, and **Total Revenue** (based on the `Transactions` table).</p>
                </div>

            <?php endif; ?>
        </main>
    </div>
    
    <script>
        window.onload = function() {
            lucide.createIcons();
        };
    </script>
</body>
</html>