<?php
/**
 * CAASP Customer Messaging Portal
 * * Allows customers to view existing chat threads and send new messages
 * * to Garages and Vendors, leveraging the Messages database table.
 */

require_once 'api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

// Ensure user is logged in AND is a Customer
if (!$user_id || $role !== 'Customer') {
    session_unset();
    session_destroy();
    header("Location: index.html?status=error&message=" . urlencode("Access denied. Please log in as a Customer."));
    exit;
}

// Check if a specific chat partner user ID is requested
$target_user_id_param = (int)($_GET['target_user_id'] ?? 0);

// --- DATA FETCHING FUNCTIONS ---

/**
 * Fetches the business name or email of a user ID.
 */
function fetch_user_name($db, $target_user_id) {
    // Check if the target user is a Garage
    $sql_garage = "SELECT G.garage_name AS name FROM Garages G JOIN Users U ON G.user_id = U.user_id WHERE U.user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql_garage)) {
        mysqli_stmt_bind_param($stmt, "i", $target_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            mysqli_stmt_close($stmt);
            return htmlspecialchars($row['name']);
        }
        mysqli_stmt_close($stmt);
    }

    // Check if the target user is a Vendor
    $sql_vendor = "SELECT V.vendor_name AS name FROM Vendors V JOIN Users U ON V.user_id = U.user_id WHERE U.user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql_vendor)) {
        mysqli_stmt_bind_param($stmt, "i", $target_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            mysqli_stmt_close($stmt);
            return htmlspecialchars($row['name']);
        }
        mysqli_stmt_close($stmt);
    }

    // Fallback: Use email prefix if no business name is found
    $sql_email = "SELECT email FROM Users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql_email)) {
        mysqli_stmt_bind_param($stmt, "i", $target_user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            mysqli_stmt_close($stmt);
            return htmlspecialchars(explode('@', $row['email'])[0]); // Use name part of email
        }
        mysqli_stmt_close($stmt);
    }

    return "Unknown User";
}


/**
 * Fetches unique chat threads for the current user based on the last message.
 * Uses subqueries to find the latest message and partner ID for each thread.
 */
function fetch_message_threads($db, $user_id) {
    $threads = [];
    
    // SQL to find the latest message ID and the partner ID for each unique conversation
    $sql = "
        SELECT
            M1.message_text,
            M1.sent_at,
            IF(M1.sender_user_id = ?, M1.receiver_user_id, M1.sender_user_id) AS partner_user_id
        FROM Messages M1
        INNER JOIN (
            SELECT
                MAX(message_id) AS max_id
            FROM Messages
            WHERE sender_user_id = ? OR receiver_user_id = ?
            GROUP BY
                LEAST(sender_user_id, receiver_user_id),
                GREATEST(sender_user_id, receiver_user_id)
        ) AS M2 ON M1.message_id = M2.max_id
        ORDER BY M1.sent_at DESC
    ";
    
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "iii", $user_id, $user_id, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $row['name'] = fetch_user_name($db, $row['partner_user_id']); // Fetch the partner's name
                $row['timestamp_formatted'] = date('M j, g:i A', strtotime($row['sent_at']));
                $threads[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    return $threads;
}

/**
 * Fetches the conversation history for two users.
 */
function fetch_conversation($db, $user_id, $target_user_id) {
    $messages = [];
    $sql = "
        SELECT 
            sender_user_id, 
            message_text, 
            sent_at
        FROM Messages
        WHERE 
            (sender_user_id = ? AND receiver_user_id = ?) OR 
            (sender_user_id = ? AND receiver_user_id = ?) 
        ORDER BY sent_at ASC
    ";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "iiii", $user_id, $target_user_id, $target_user_id, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $messages[] = [
                    'sender' => $row['sender_user_id'],
                    'text' => htmlspecialchars($row['message_text']),
                    'time' => date('g:i A', strtotime($row['sent_at'])),
                    'is_sender' => $row['sender_user_id'] == $user_id
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $messages;
}


// --- MESSAGE SUBMISSION LOGIC ---
$error_message = '';
$db_connect = connect_db(); 
if (!$db_connect) {
    die("Database connection failed for message submission.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message_text = trim($_POST['message_text'] ?? '');
    $target_user_id = (int)($_POST['target_user_id'] ?? 0); // User ID of the business owner

    if (!empty($message_text) && $target_user_id > 0) {
        
        $sql_insert = "INSERT INTO Messages (sender_user_id, receiver_user_id, message_text) VALUES (?, ?, ?)";
        
        if ($stmt = mysqli_prepare($db_connect, $sql_insert)) {
            // Sanitize text before binding
            $sanitized_text = mysqli_real_escape_string($db_connect, $message_text);
            mysqli_stmt_bind_param($stmt, "iis", $user_id, $target_user_id, $sanitized_text);
            
            if (!mysqli_stmt_execute($stmt)) {
                 $error_message = "Failed to send message: " . mysqli_error($db_connect);
            }
            mysqli_stmt_close($stmt);

        } else {
            $error_message = "Failed to prepare message insertion.";
        }
        
        // Redirect back to the chat thread (to prevent double submission)
        header("Location: message.php?target_user_id={$target_user_id}");
        exit;
    } else {
        $error_message = "Message or recipient is missing.";
    }
}


// --- Fetch Threads/Conversation for Display ---
$threads = fetch_message_threads($db_connect, $user_id);
$current_chat_name = null;
$messages = [];
$target_user_id = $target_user_id_param; // Use the URL parameter

if ($target_user_id > 0) {
    // Determine the name of the chat partner
    $current_chat_name = fetch_user_name($db_connect, $target_user_id);
    
    // Fetch conversation
    $messages = fetch_conversation($db_connect, $user_id, $target_user_id);
}

mysqli_close($db_connect);


// --- HTML OUTPUT ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - AutoHub</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script> 
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .chat-container { height: 100vh; }
        .thread-item:hover { background-color: #f1f5f9; }
        .chat-bubble-self { background-color: #10B981; color: white; border-bottom-right-radius: 0; }
        .chat-bubble-other { background-color: #e2e8f0; color: #1e293b; border-bottom-left-radius: 0; }
        /* Fix chat area height */
        .chat-messages { height: calc(100vh - 16px - 64px - 80px - 56px); } /* Full height minus header, input, and padding */
    </style>
</head>
<body>

<div class="flex chat-container antialiased text-gray-800">
    <div class="flex flex-col w-64 bg-white border-r border-gray-200">
        <div class="h-16 flex items-center p-4 border-b border-gray-200">
            <h2 class="text-xl font-bold text-gray-900">Message Threads</h2>
        </div>
        
        <div class="overflow-y-auto flex flex-col flex-grow">
            <?php if (!empty($threads)): ?>
                <?php foreach ($threads as $thread): ?>
                    <a href="?target_user_id=<?= $thread['partner_user_id'] ?>" 
                       class="flex items-center p-3 border-b border-gray-100 thread-item <?= ($target_user_id > 0 && $target_user_id === $thread['partner_user_id']) ? 'bg-gray-100' : '' ?>">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white text-sm font-semibold mr-3">
                            <?= substr($thread['name'], 0, 1) ?>
                        </div>
                        <div class="flex-grow overflow-hidden">
                            <p class="text-sm font-semibold truncate"><?= $thread['name'] ?></p>
                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($thread['message_text']) ?></p>
                        </div>
                        <div class="flex-shrink-0 text-xs text-gray-400">
                            <?= htmlspecialchars($thread['timestamp_formatted']) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-center text-sm text-gray-500 p-4">No active conversations. To start one, visit a business profile.</p>
            <?php endif; ?>
        </div>

        <a href="customer_dashboard.php" class="p-4 border-t border-gray-200 flex items-center text-red-500 hover:text-red-700 transition">
            <i data-lucide="arrow-left" class="w-5 h-5 mr-2"></i>
            <span class="font-medium text-sm">Back to Dashboard</span>
        </a>
    </div>

    <div class="flex flex-col flex-auto">
        <?php if ($target_user_id > 0): ?>
            <div class="h-16 flex items-center p-4 border-b border-gray-200 bg-white">
                <h2 class="text-xl font-bold text-gray-900">Chat with <?= $current_chat_name ?></h2>
            </div>
            
            <div class="flex flex-col flex-grow overflow-y-auto p-4 space-y-4 bg-gray-50 chat-messages">
                <?php if (!empty($messages)): ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="flex <?= $message['is_sender'] ? 'justify-end' : 'justify-start' ?>">
                            <div class="max-w-xs lg:max-w-md">
                                <div class="p-3 rounded-xl shadow-md text-sm <?= $message['is_sender'] ? 'chat-bubble-self' : 'chat-bubble-other' ?>">
                                    <?= $message['text'] ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-1 <?= $message['is_sender'] ? 'text-right' : 'text-left' ?>">
                                    <?= $message['time'] ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="flex flex-col flex-grow items-center justify-center text-center p-8">
                        <p class="text-lg text-gray-500">Start a new conversation!</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="h-20 p-4 border-t border-gray-200 bg-white">
                <form method="POST" action="message.php">
                    <input type="hidden" name="target_user_id" value="<?= htmlspecialchars($target_user_id) ?>">
                    <div class="flex items-center space-x-3">
                        <input type="text" name="message_text" placeholder="Type your message..." 
                               class="flex-grow p-3 border border-gray-300 rounded-xl focus:ring-blue-500 focus:border-blue-500 shadow-sm" required>
                        <button type="submit" name="send_message" class="bg-blue-600 text-white p-3 rounded-xl font-semibold hover:bg-blue-700 transition duration-150">
                            <i data-lucide="send" class="w-5 h-5"></i>
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="flex flex-col flex-grow items-center justify-center text-center p-8">
                <i data-lucide="message-square" class="w-16 h-16 text-gray-400 mb-4"></i>
                <p class="text-xl font-semibold text-gray-600">Select a thread to start chatting.</p>
                <p class="text-sm text-gray-500 mt-2">To start a new chat, visit the profile of a Garage or Vendor.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    window.onload = function() {
        lucide.createIcons();
    };
</script>
</body>
</html>