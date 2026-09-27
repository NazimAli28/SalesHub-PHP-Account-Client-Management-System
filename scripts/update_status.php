<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/config.php';

try {
    // Update status based on pc_number: 1 if pc_number is not empty, else 0
    $update_stmt = $conn->prepare("UPDATE accounts SET status = CASE WHEN pc_number IS NOT NULL AND pc_number != '' THEN 1 ELSE 0 END");
    $update_stmt->execute();

    echo "Status updated successfully for all accounts based on pc_number.\n";
    echo "Affected rows: " . $conn->affected_rows . "\n";

    $update_stmt->close();
} catch (Exception $e) {
    echo "Error updating status: " . $e->getMessage() . "\n";
}

$conn->close();
?>
