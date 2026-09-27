<?php
session_start();

// Handle support ticket submission (allow any logged-in user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_id'], $_POST['query'])) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php?error=login_required");
        exit();
    }

    require_once '../config/config.php';

    // Ensure approvals table exists
    $createApprovals = "
    CREATE TABLE IF NOT EXISTS approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(64) NOT NULL,
        record_id INT NOT NULL,
        action ENUM('update','delete','create') NOT NULL DEFAULT 'update',
        changes_json JSON NOT NULL,
        submitted_by VARCHAR(100) NOT NULL,
        submitted_by_user_id INT NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by VARCHAR(100) NULL,
        reviewed_by_user_id INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewer_comment TEXT NULL
    )";
    $conn->query($createApprovals);

    // Insert the support ticket as a pending approval
    $entry_id = (int)$_POST['entry_id'];
    $discord_email = trim($_POST['discord_email']);
    $order_number = trim($_POST['order_number']);
    $query = trim($_POST['query']);
    $submitted_by = $_SESSION['username'] ?? 'unknown';
    $submitted_by_user_id = (int)$_SESSION['user_id'];

    $changes = json_encode([
        'entry_id' => $entry_id,
        'discord_email' => $discord_email,
        'order_number' => $order_number,
        'query' => $query,
        'type' => 'support_ticket'
    ]);

    $stmt = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('support_ticket', ?, 'create', ?, ?, ?)");
    $stmt->bind_param("issi", $entry_id, $changes, $submitted_by, $submitted_by_user_id);
    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        header("Location: client_retention_form.php?success=support");
        exit();
    } else {
        $stmt->close();
        $conn->close();
        header("Location: client_retention_form.php?error=submission_failed");
        exit();
    }
}

// Only admin/support can view the page
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (admin and support roles don't allow mobile)
check_device_restriction();

// Check IP restriction for support (admin can access from anywhere)
// if ($_SESSION['role'] === 'support' && !is_ip_allowed()) {
//     header("Location: ../index.php?error=access_denied");
//     exit();
// }

// Handle approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_id'], $_POST['decision'])) {
    $approvalId = (int)$_POST['approval_id'];
    $decision = $_POST['decision'] === 'approve' ? 'approve' : 'reject';
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    $stmt = $conn->prepare("SELECT * FROM approvals WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $approvalId);
    $stmt->execute();
    $res = $stmt->get_result();
    $approval = $res->fetch_assoc();
    $stmt->close();

    if ($approval) {
        // Extract common variables
        $table = $approval['table_name'];
        $recordId = (int)$approval['record_id'];
        $action = $approval['action'];
        $changes = json_decode($approval['changes_json'], true);

        if ($decision === 'approve') {
            // Apply change to target table

            if ($action === 'update' && is_array($changes) && $changes) {
                // Define integer fields for each table (DECIMAL fields are handled as strings in MySQLi)
                $integer_fields = [
                    'accounts' => ['has_client', 'status'],
                    // For client_retention, upsale_order_number is stored as a comma-delimited string,
                    // so only order_number is treated as integer.
                    'client_retention' => ['order_number'],
                    'leads_data' => []
                ];

                $fields = [];
                $params = [];
                $types = '';
                $intFields = isset($integer_fields[$table]) ? $integer_fields[$table] : [];

                foreach ($changes as $field => $value) {
                    // Skip placeholder fields like _action
                    if (strpos($field, '_') === 0) {
                        continue;
                    }
                    $fields[] = "`$field` = ?";
                    $params[] = $value;
                    // Use 'i' for integer fields, 's' for others
                    if (in_array($field, $intFields) || is_int($value)) {
                        $types .= 'i';
                    } else {
                        $types .= 's';
                    }
                }

                if (!empty($fields)) {
                    $sql = "UPDATE `$table` SET " . implode(", ", $fields) . " WHERE id = ?";
                    $params[] = $recordId;
                    $types .= 'i';
                    $upd = $conn->prepare($sql);
                    $upd->bind_param($types, ...$params);
                    $upd->execute();
                    $upd->close();
                }

                // If standings was changed, clear pending_standings
                if ($table === 'accounts' && isset($changes['standings'])) {
                    $clear_pending = $conn->prepare("UPDATE accounts SET pending_standings = NULL WHERE id = ?");
                    $clear_pending->bind_param("i", $recordId);
                    $clear_pending->execute();
                    $clear_pending->close();
                }
            } elseif ($action === 'delete') {
                $sql = "DELETE FROM `$table` WHERE id = ?";
                $del = $conn->prepare($sql);
                $del->bind_param("i", $recordId);
                $del->execute();
                $del->close();
            }
        }

        // Update approval row
        $now = date('Y-m-d H:i:00');
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $reviewedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'support';
        $reviewedById = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $upd = $conn->prepare("UPDATE approvals SET status = ?, reviewed_by = ?, reviewed_by_user_id = ?, reviewed_at = ?, reviewer_comment = ? WHERE id = ?");
        $upd->bind_param("ssissi", $status, $reviewedBy, $reviewedById, $now, $comment, $approvalId);
        $upd->execute();
        $upd->close();

        // If rejected and it's an accounts update, clear pending_standings
        if ($decision === 'reject' && $table === 'accounts' && $action === 'update') {
            $clear_pending = $conn->prepare("UPDATE accounts SET pending_standings = NULL WHERE id = ?");
            $clear_pending->bind_param("i", $recordId);
            $clear_pending->execute();
            $clear_pending->close();
        }

        header("Location: support_tickets.php?done=1");
        exit();
    }
}

// Fetch pending approvals (exclude self-submitted)
$pending = [];
$query = "
SELECT a.*, u.pc_number, u.name as submitted_by_name,
CASE
WHEN a.table_name = 'accounts' THEN (SELECT discord_email FROM accounts WHERE id = a.record_id)
WHEN a.table_name = 'leads_data' THEN (SELECT discord_email FROM leads_data WHERE id = a.record_id)
WHEN a.table_name = 'client_retention' THEN (SELECT discord_email FROM client_retention WHERE id = a.record_id)
WHEN a.table_name = 'socials_data' THEN (SELECT discord_email FROM socials_data WHERE id = a.record_id)
WHEN a.table_name = 'support_ticket' THEN JSON_UNQUOTE(JSON_EXTRACT(a.changes_json, '$.discord_email'))
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - Approvals</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .tickets-container {
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            gap: 16px
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .ticket {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            padding: 16px
        }

        .ticket h3 {
            margin: 0 0 8px 0
        }

        .ticket-meta {
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 10px;
            line-height: 1.4
        }

        .changes {
            font-family: monospace;
            background: #f8f9fa;
            border: 1px solid #eee;
            border-radius: 6px;
            padding: 10px;
            white-space: pre-wrap
        }

        .actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            align-items: center
        }

        .btn-approve {
            background: #28a745;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer
        }

        .btn-reject {
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer
        }

        .comment {
            flex: 1;
            min-width: 200px;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Support Tickets - Approvals</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <a href="approvals_history.php" class="btn" style="margin-left: 20px;">View Approval History</a>
            <div class="tickets-container">
                <?php if (isset($_GET['done'])): ?>
                    <div class="alert alert-success">Decision submitted.</div>
                <?php endif; ?>

                <?php if (empty($pending)): ?>
                    <p>No pending approvals.</p>
                <?php else: ?>
                    <?php foreach ($pending as $row): ?>
                        <div class="ticket">
                            <h3><?php echo htmlspecialchars(strtoupper($row['action'])) . " - " . htmlspecialchars($row['table_name']); ?> #<?php echo (int)$row['record_id']; ?></h3>
                            <div class="ticket-meta">
                                <strong>Submitted by:</strong> <?php echo htmlspecialchars($row['submitted_by_name'] ?: $row['submitted_by']); ?><br>
                                <strong>Discord Email:</strong> <?php echo htmlspecialchars($row['discord_emails'] ?: 'N/A'); ?><br>
                                <strong>PC Number:</strong> <?php echo htmlspecialchars($row['pc_number'] ?: 'N/A'); ?><br>
                                <strong>Date:</strong> <?php echo htmlspecialchars($row['created_at']); ?>
                            </div>
                            <div class="changes"><?php
                                                    if ($row['table_name'] === 'support_ticket') {
                                                        $changes = json_decode($row['changes_json'], true);
                                                        echo htmlspecialchars("Query: " . ($changes['query'] ?? 'N/A') . "\nOrder Number: " . ($changes['order_number'] ?? 'N/A'));
                                                    } else {
                                                        $pretty = json_encode(json_decode($row['changes_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                                        echo htmlspecialchars($pretty);
                                                    }
                                                    ?></div>
                            <form method="post" class="actions">
                                <input type="hidden" name="approval_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="text" name="comment" class="comment" placeholder="Add a comment (optional)">
                                <button type="submit" name="decision" value="approve" class="btn-approve">Approve</button>
                                <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let previousCount = <?php echo count($pending); ?>;
        let seenIds = new Set(<?php echo json_encode(array_column($pending, 'id')); ?>);

        function renderTicket(ticket) {
            let changesPretty;
            if (ticket.table_name === 'support_ticket') {
                const changes = JSON.parse(ticket.changes_json);
                changesPretty = `Query: ${changes.query || 'N/A'}\nOrder Number: ${changes.order_number || 'N/A'}`;
            } else {
                changesPretty = JSON.stringify(JSON.parse(ticket.changes_json), null, 2);
            }
            const submittedByName = ticket.submitted_by_name || ticket.submitted_by || 'Unknown';
            return `
                <div class="ticket">
                    <h3>${ticket.action.toUpperCase()} - ${ticket.table_name} #${ticket.record_id}</h3>
                    <div class="ticket-meta">
                        <strong>Submitted by:</strong> ${submittedByName}<br>
                        <strong>Discord Email:</strong> ${ticket.discord_emails || 'N/A'}<br>
                        <strong>PC Number:</strong> ${ticket.pc_number || 'N/A'}<br>
                        <strong>Date:</strong> ${ticket.created_at}
                    </div>
                    <div class="changes">${changesPretty}</div>
                    <form method="post" class="actions">
                        <input type="hidden" name="approval_id" value="${ticket.id}">
                        <input type="text" name="comment" class="comment" placeholder="Add a comment (optional)">
                        <button type="submit" name="decision" value="approve" class="btn-approve">Approve</button>
                        <button type="submit" name="decision" value="reject" class="btn-reject">Reject</button>
                    </form>
                </div>
            `;
        }

        setInterval(function() {
            fetch('../api/check_approvals.php')
                .then(response => response.json())
                .then(data => {
                    const currentCount = data.count;
                    const newApprovals = data.approvals.filter(ticket => !seenIds.has(ticket.id));

                    if (newApprovals.length > 0) {
                        // Play notification sound
                        const audio = new Audio('../assets/sounds/alert.mp3');
                        audio.play();

                        // Show alert
                        alert('New pending approval!');

                        // Add new tickets to the page
                        const container = document.querySelector('.tickets-container');
                        newApprovals.forEach(ticket => {
                            container.insertAdjacentHTML('afterbegin', renderTicket(ticket));
                            seenIds.add(ticket.id);
                        });
                    }
                    previousCount = currentCount;
                })
                .catch(error => console.error('Error fetching approvals:', error));
        }, 5000);
    </script>
</body>

</html>