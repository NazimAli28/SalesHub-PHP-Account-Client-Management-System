<?php
session_start();

// Check if user is logged in and is admin or support
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/config.php';

$pending = [];
$query = "
SELECT a.*, u.pc_number, u.name as submitted_by_name,
CASE
WHEN a.table_name = 'accounts' THEN (SELECT discord_email FROM accounts WHERE id = a.record_id)
WHEN a.table_name = 'leads_data' THEN (SELECT discord_email FROM leads_data WHERE id = a.record_id)
WHEN a.table_name = 'client_retention' THEN (SELECT discord_email FROM client_retention WHERE id = a.record_id)
WHEN a.table_name = 'socials_data' THEN (SELECT discord_email FROM socials_data WHERE id = a.record_id)
ELSE NULL
END as discord_emails
FROM approvals a
LEFT JOIN users u ON a.submitted_by_user_id = u.id
WHERE a.status = 'pending' AND a.submitted_by_user_id != ?
ORDER BY a.id DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pending[] = $row;
    }
    $result->free();
}
$stmt->close();
$count = count($pending);
$conn->close();

header('Content-Type: application/json');
echo json_encode(['count' => $count, 'approvals' => $pending]);
?>
