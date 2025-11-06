<?php
/**
 * CAASP Transaction Handler
 * * Processes POST requests for service/part requests, payment finalization, and account recharge.
 */

require_once 'api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: index.html?status=error&message=" . urlencode("Session expired. Please log in to complete your transaction."));
    exit;
}

// Helper function to safely redirect back to the dashboard with a status
function redirect_to_dashboard($status, $message) {
    // Always redirect to history view after a transaction
    header("Location: customer_dashboard.php?view=history&status=" . urlencode($status) . "&message=" . urlencode($message));
    exit;
}

// Helper function to fetch current balance
function get_current_balance($db, $user_id) {
    $balance = 0.00;
    $sql = "SELECT account_balance FROM Users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $balance);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);
    }
    return $balance;
}


// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to_dashboard('error', 'Invalid transaction request method.');
}

$action = $_POST['action'] ?? '';
$db = connect_db();
if (!$db) {
    redirect_to_dashboard('error', 'Database connection failed. Cannot process transaction.');
}


// ====================================================================
// --- A. PROCESS NEW ORDER/REQUEST (INITIATION) ---
// ====================================================================
if ($action === 'request') {
    // Input collection and validation logic remains the same...
    $item_type = $_POST['item_type'] ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);
    $business_type = $_POST['business_type'] ?? '';
    $business_id = (int)($_POST['business_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0.0);

    if ($item_id === 0 || $business_id === 0 || $amount <= 0 || !in_array($item_type, ['Service', 'Part']) || !in_array($business_type, ['Garage', 'Vendor'])) {
        redirect_to_dashboard('error', 'Missing or invalid transaction details. Please select an item.');
    }

    // Determine SQL fields
    $item_id_field = $item_type === 'Service' ? 'service_id' : 'part_id';
    $business_id_field = $business_type === 'Garage' ? 'target_garage_id' : 'target_vendor_id';

    // TRANSACTION INSERTION (Status set to 'Pending')
    $sql = "INSERT INTO Transactions (initiator_user_id, {$item_id_field}, transaction_amount, {$business_id_field}, status) 
            VALUES (?, ?, ?, ?, 'Pending')";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "iidi", $user_id, $item_id, $amount, $business_id);
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            
            $success_message = $item_type === 'Service' ? 
                               "Service Request Placed Successfully! The {$business_type} will be in contact." :
                               "Part Order Placed Successfully! Awaiting confirmation from the {$business_type}.";
                               
            redirect_to_dashboard('success', $success_message);
            
        } else {
            error_log("Transaction failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_dashboard('error', 'Transaction processing failed: Database insertion error.');
        }
    } else {
        error_log("Transaction prepare failed: " . mysqli_error($db));
        mysqli_close($db);
        redirect_to_dashboard('error', 'Internal server error during transaction setup.');
    }
} 
// ====================================================================
// --- B. PROCESS RECHARGE ---
// ====================================================================
elseif ($action === 'recharge') {
    $recharge_amount = (float)($_POST['recharge_amount'] ?? 0.0);

    if ($recharge_amount <= 0) {
        redirect_to_dashboard('error', 'Please enter a valid amount to recharge.');
    }

    // Begin transaction for safe balance update
    mysqli_begin_transaction($db);
    
    try {
        // 1. Update the user's balance
        $sql_update = "UPDATE Users SET account_balance = account_balance + ? WHERE user_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_update)) {
            mysqli_stmt_bind_param($stmt, "di", $recharge_amount, $user_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to update account balance.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during recharge preparation.");
        }
        
        mysqli_commit($db);
        mysqli_close($db);
        redirect_to_dashboard('success', "Account successfully recharged with KES " . number_format($recharge_amount, 2));

    } catch (Exception $e) {
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_to_dashboard('error', 'Recharge failed: ' . $e->getMessage());
    }

}
// ====================================================================
// --- C. PROCESS FINALIZATION (PAYMENT/CONFIRMATION) ---
// ====================================================================
elseif ($action === 'finalize') {
    $transaction_id = (int)($_POST['transaction_id'] ?? 0);

    if ($transaction_id === 0) {
        redirect_to_dashboard('error', 'Invalid transaction ID provided for finalization.');
    }

    // Begin transaction for safe balance update/check
    mysqli_begin_transaction($db);

    try {
        // 1. Fetch transaction details and current balance (SELECT FOR UPDATE locks the row)
        $sql_fetch = "SELECT T.transaction_amount, T.status, U.account_balance 
                      FROM Transactions T 
                      JOIN Users U ON T.initiator_user_id = U.user_id
                      WHERE T.transaction_id = ? AND T.initiator_user_id = ? FOR UPDATE";

        $transaction_amount = 0.0;
        $status = '';
        $account_balance = 0.0;
        
        if ($stmt = mysqli_prepare($db, $sql_fetch)) {
            mysqli_stmt_bind_param($stmt, "ii", $transaction_id, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_bind_result($stmt, $transaction_amount, $status, $account_balance);
                if (!mysqli_stmt_fetch($stmt)) {
                    throw new Exception('Transaction not found or unauthorized.');
                }
            } else {
                 throw new Exception('Database error during transaction check.');
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception('Internal error during transaction preparation.');
        }

        if ($status !== 'Pending') {
            throw new Exception('Transaction is not pending. Status: ' . $status);
        }

        // 2. CHECK BALANCE
        if ($account_balance < $transaction_amount) {
            throw new Exception('Insufficient funds (KES ' . number_format($account_balance, 2) . ' available). Please recharge.');
        }
        
        // 3. DEDUCT FUNDS and Update Status
        $sql_deduct = "UPDATE Users SET account_balance = account_balance - ? WHERE user_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_deduct)) {
            mysqli_stmt_bind_param($stmt, "di", $transaction_amount, $user_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to deduct funds from account.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during deduction preparation.");
        }
        
        // 4. Update Transaction Status
        $sql_update = "UPDATE Transactions SET status = 'Completed' WHERE transaction_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_update)) {
            mysqli_stmt_bind_param($stmt, "i", $transaction_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to mark transaction as completed.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during status update preparation.");
        }

        // 5. Commit the transaction
        mysqli_commit($db);
        mysqli_close($db);
        
        redirect_to_dashboard('success', 'Payment successful! KES ' . number_format($transaction_amount, 2) . ' deducted. Transaction marked as Completed. You can now leave a rating.');

    } catch (Exception $e) {
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_to_dashboard('error', 'Payment failed: ' . $e->getMessage());
    }
}
// ====================================================================

else {
    mysqli_close($db);
    redirect_to_dashboard('error', 'Unknown action requested.');
}

?>