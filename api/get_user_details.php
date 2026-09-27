<?php
session_start();

// Check if user is logged in and is admin or support
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

$user_id = (int)$_GET['user_id'];

// Get user's pc_number
$user_stmt = $conn->prepare("SELECT pc_number FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit();
}

$user = $user_result->fetch_assoc();
$pc_number = $user['pc_number'];
$user_stmt->close();

// Get email counts
$counts = [
    'total' => 0,
    'active' => 0,
    'spam' => 0,
    'limited' => 0,
    'disabled' => 0,
    'violation' => 0
];

if (!empty($pc_number)) {
    $query = "SELECT status, COUNT(*) as count FROM accounts WHERE pc_number = ? GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $pc_number);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $status = strtolower($row['status']);
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int)$row['count'];
        }
        $counts['total'] += (int)$row['count'];
    }
    $stmt->close();
}

$conn->close();

header('Content-Type: application/json');
echo json_encode($counts);
?>
