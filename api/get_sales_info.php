<?php
session_start();

header('Content-Type: application/json');

// Require any logged-in user
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discord_email'])) {
    $email = trim($_POST['discord_email']);
    
    if (empty($email)) {
        echo json_encode(['name' => '', 'pc_number' => '']);
        exit;
    }
    
    // First, get pc_number from accounts
    $stmt = $conn->prepare("SELECT pc_number FROM accounts WHERE LOWER(discord_email) = LOWER(?) AND status = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pc_number = '';
    if ($row = $result->fetch_assoc()) {
        $pc_number = trim($row['pc_number'] ?? '');
    }
    $stmt->close();
    
    // Then, get name from users using pc_number as username
    $name = '';
    if (!empty($pc_number)) {
        $user_stmt = $conn->prepare("SELECT name FROM users WHERE username = ?");
        $user_stmt->bind_param("s", $pc_number);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $name = trim($user_row['name'] ?? '');
        }
        $user_stmt->close();
    }
    
    echo json_encode([
        'name' => $name,
        'pc_number' => $pc_number
    ]);
} else {
    echo json_encode(['name' => '', 'pc_number' => '']);
}

$conn->close();
?>
