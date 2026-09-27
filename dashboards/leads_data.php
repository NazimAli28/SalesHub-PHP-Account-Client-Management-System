<?php
session_start();

// Check if user is logged in and is sales_executive
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sales_executive') {
    header("Location: ../index.php");
    exit();
}

// Prevent cache so Back won't restore stale form/modal state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (sales_executive role doesn't allow mobile)
check_device_restriction();

// Check IP restriction for agent
if (!is_ip_allowed()) {
    header("Location: ../index.php?error=access_denied");
    exit();
}

// Fetch Discord emails assigned to the current agent
// Always fetch pc_number from users table to ensure accuracy
$agent_pc_number = $_SESSION['username']; // default
if (isset($_SESSION['user_id'])) {
    $tmp_stmt = $conn->prepare("SELECT pc_number, name FROM users WHERE id = ?");
    $tmp_stmt->bind_param("i", $_SESSION['user_id']);
    $tmp_stmt->execute();
    $tmp_res = $tmp_stmt->get_result();
    if ($tmp_row = $tmp_res->fetch_assoc()) {
        if (!empty($tmp_row['pc_number'])) {
            $agent_pc_number = $tmp_row['pc_number'];
            $_SESSION['pc_number'] = $agent_pc_number;
        }
        if (!empty($tmp_row['name'])) {
            $_SESSION['name'] = $tmp_row['name'];
        }
    }
    $tmp_stmt->close();
}

// Always fetch name if not set
if (!isset($_SESSION['name'])) {
    if (isset($_SESSION['user_id'])) {
        $tmp_stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $tmp_stmt->bind_param("i", $_SESSION['user_id']);
        $tmp_stmt->execute();
        $tmp_res = $tmp_stmt->get_result();
        if ($tmp_row = $tmp_res->fetch_assoc()) {
            if (!empty($tmp_row['name'])) {
                $_SESSION['name'] = $tmp_row['name'];
            }
        }
        $tmp_stmt->close();
    }
}

$discord_emails = [];
$stmt = $conn->prepare("SELECT discord_email FROM accounts WHERE pc_number = ? AND status = 1 AND discord_email IS NOT NULL AND discord_email != ''");
$stmt->bind_param("s", $agent_pc_number);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $discord_emails[] = $row['discord_email'];
}
$stmt->close();

// Sync sales_executive for existing records on account reassignment
foreach ($discord_emails as $email) {
    // Get current agent name for this discord_email
    $sync_stmt = $conn->prepare("SELECT pc_number FROM accounts WHERE discord_email = ? AND status = 1");
    $sync_stmt->bind_param("s", $email);
    $sync_stmt->execute();
    $sync_res = $sync_stmt->get_result();
    $current_pc = null;
    if ($sync_row = $sync_res->fetch_assoc()) {
        $current_pc = $sync_row['pc_number'];
    }
    $sync_stmt->close();

    if ($current_pc) {
        // Get agent name from users table
        $name_stmt = $conn->prepare("SELECT name FROM users WHERE pc_number = ? OR username = ?");
        $name_stmt->bind_param("ss", $current_pc, $current_pc);
        $name_stmt->execute();
        $name_res = $name_stmt->get_result();
        $current_name = null;
        if ($name_row = $name_res->fetch_assoc()) {
            $current_name = $name_row['name'];
        }
        $name_stmt->close();

        if ($current_name) {
            // Update records where discord_email matches and sales_executive differs
            $update_stmt = $conn->prepare("UPDATE leads_data SET sales_executive = ? WHERE discord_email = ? AND sales_executive != ?");
            $update_stmt->bind_param("sss", $current_name, $email, $current_name);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
}

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS leads_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE,
    sales_executive VARCHAR(255),
    closer_name VARCHAR(255),
    discord_email VARCHAR(255),
    client_discord_username VARCHAR(255),
    client_email VARCHAR(255),
    service_items TEXT,
    follow_up_stage VARCHAR(255),
    last_message TEXT,
    scenario_if_lost TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($table_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';

    // Ensure approvals table exists
    $conn->query("CREATE TABLE IF NOT EXISTS approvals (
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
	)");

    if ($action === 'delete') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id.');
        }
        $id = intval($_POST['id']);

        // Create approval ticket instead of deleting immediately
        $changes = ['id' => $id];
        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('leads_data', ?, 'delete', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        $ins->execute();
        $ins->close();

        header("Location: " . basename(__FILE__) . "?queued=1");
        exit();
    }

    // Server-side validation for required fields (create/update)
    $required_fields = ['date', 'sales_executive', 'closer_name', 'discord_email', 'client_discord_username', 'follow_up_stage'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            die("Error: Field '$field' is required.");
        }
    }

    // Validate date field
    if (empty($_POST['date']) || !DateTime::createFromFormat('Y-m-d', $_POST['date'])) {
        die("Error: Invalid date.");
    }

    // Validate service items (at least one should be selected)
    if (!isset($_POST['service_items']) || !is_array($_POST['service_items']) || count($_POST['service_items']) < 1) {
        die("Error: At least one Service Item must be selected.");
    }

    // Collect and sanitize inputs
    $date = $_POST['date'];
    $sales_executive = htmlspecialchars($_POST['sales_executive']);
    $closer_name = htmlspecialchars($_POST['closer_name']);
    $discord_email = filter_var($_POST['discord_email'], FILTER_SANITIZE_EMAIL);
    $client_discord_username = htmlspecialchars($_POST['client_discord_username']);
    $client_email = filter_var($_POST['client_email'], FILTER_SANITIZE_EMAIL);
    $service_items = implode(',', $_POST['service_items']);
    $follow_up_stage = htmlspecialchars($_POST['follow_up_stage']);
    $last_message = isset($_POST['last_message']) ? htmlspecialchars($_POST['last_message']) : '';
    $scenario_if_lost = isset($_POST['scenario_if_lost']) ? htmlspecialchars($_POST['scenario_if_lost']) : '';

    if ($action === 'update') {
        if (!isset($_POST['id'])) {
            die('Error: Missing id for update.');
        }
        $id = intval($_POST['id']);

        // Compare with existing to build changes diff
        $curStmt = $conn->prepare("SELECT * FROM leads_data WHERE id = ?");
        $curStmt->bind_param("i", $id);
        $curStmt->execute();
        $curRes = $curStmt->get_result();
        $current = $curRes->fetch_assoc();
        $curStmt->close();

        if (!$current) {
            die('Error: Record not found.');
        }

        $proposed = [
            'date' => $date,
            'sales_executive' => $sales_executive,
            'closer_name' => $closer_name,
            'discord_email' => $discord_email,
            'client_discord_username' => $client_discord_username,
            'client_email' => $client_email,
            'service_items' => $service_items,
            'follow_up_stage' => $follow_up_stage,
            'last_message' => $last_message,
            'scenario_if_lost' => $scenario_if_lost
        ];

        $changes = [];
        foreach ($proposed as $field => $value) {
            $curVal = isset($current[$field]) ? (string)$current[$field] : null;
            if ($curVal !== (string)$value) {
                $changes[$field] = $value;
            }
        }

        if (empty($changes)) {
            header("Location: " . basename(__FILE__) . "?nochange=1");
            exit();
        }

        $json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        $submittedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'sales_executive';
        $submittedById = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

        $ins = $conn->prepare("INSERT INTO approvals (table_name, record_id, action, changes_json, submitted_by, submitted_by_user_id) VALUES ('leads_data', ?, 'update', ?, ?, ?)");
        $ins->bind_param("issi", $id, $json, $submittedBy, $submittedById);
        $ins->execute();
        $ins->close();

        header("Location: " . basename(__FILE__) . "?queued=1");
        exit();
    }

    // Default: create
    $stmt = $conn->prepare("INSERT INTO leads_data (date, sales_executive, closer_name, discord_email, client_discord_username, client_email, service_items, follow_up_stage, last_message, scenario_if_lost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssssss", $date, $sales_executive, $closer_name, $discord_email, $client_discord_username, $client_email, $service_items, $follow_up_stage, $last_message, $scenario_if_lost);
    $stmt->execute();
    $stmt->close();

    header("Location: " . basename(__FILE__) . "?success=1");
    exit();
}

// Fetch all entries owned by the agent's discord_emails with optional filters
$entries = [];
if (!empty($discord_emails)) {
    $placeholders = str_repeat('?,', count($discord_emails) - 1) . '?';
    $sql = "SELECT * FROM leads_data WHERE discord_email IN ($placeholders)";
    $params = $discord_emails;
    $types = str_repeat('s', count($discord_emails));

    // Add filters
    if (!empty($_GET['filter_date'])) {
        $sql .= " AND date = ?";
        $params[] = $_GET['filter_date'];
        $types .= 's';
    }
    if (!empty($_GET['filter_discord_email'])) {
        $sql .= " AND discord_email = ?";
        $params[] = $_GET['filter_discord_email'];
        $types .= 's';
    }
    if (!empty($_GET['filter_client_discord_username'])) {
        $sql .= " AND client_discord_username LIKE ?";
        $params[] = '%' . $_GET['filter_client_discord_username'] . '%';
        $types .= 's';
    }
    if (!empty($_GET['filter_service_items'])) {
        $sql .= " AND service_items LIKE ?";
        $params[] = '%' . $_GET['filter_service_items'] . '%';
        $types .= 's';
    }
    if (!empty($_GET['filter_follow_up_stage'])) {
        $sql .= " AND follow_up_stage = ?";
        $params[] = $_GET['filter_follow_up_stage'];
        $types .= 's';
    }

    $sql .= " ORDER BY date DESC, created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $entries[] = $row;
        }
    }
    $result->close();
    $stmt->close();
}

// Service Items options (same as fresh_sale_options from client_retention_form)
$service_items_options = [
    'Logo/PFP',
    'Banner',
    'logo and banners',
    'Mascot Logo',
    'Avatar',
    'Animated Avatar',
    'Animated Logo',
    'Twitch Stream Pack',
    'Animated Stream Pack',
    '3D Stream pack',
    'Animated Gifs',
    'Overlay/ Screens / Banners',
    'Animated Overlay',
    'Animated Screens',
    'Animated Banner',
    'Emotes (Static)',
    'Emotes (Animated)',
    'Static Alerts',
    'Animated Alerts',
    'OC Character',
    'Sub Badges/ Stickers',
    'Pannels',
    'Stinger Transition',
    'Static Wallpaper',
    'Animated wallpaper',
    '3D Static Wallpaper',
    '3D Animated Wallpaper',
    'Static PNG Model',
    'Expression on PNG Tuber',
    '2D Character Sheet',
    '2D Half Body Vtuber Model (Static)',
    '2D Half Body Vtuber Model (Rigging)',
    '2D Full Body (Static)',
    '2D Full Body (Rigging)',
    '2D Intro',
    '2D gaming Room',
    '3D Live Vtuber Model Full Body',
    '3D Live Vtuber Model Half Body',
    '3D Scene',
    '3D Animation',
    '3D intro',
    '3D gaming Room',
    'Character Design (Static)',
    'Character Design (Animated)',
    'Animated YouTube Background for Songs',
    'Animated YouTube Intro',
    'YT Logo & Banner',
    'YT Video Editing Package',
    'YT Thumbnails',
    'YT Banner',
    'Wall Art',
    'Book Cover Art (Front Face)',
    'Poster Design (Static Design)',
    'Music Cover Art',
    'Minecraft Skin',
    'Flash Art',
    'Controller Designs',
    'Arm Sleeve Tattoo',
    'Custom business card',
    'Website',
    'Merch Design',
    'Server Managing/Server Creation',
    'Animated Mockup',
    'Proof Reading',
    'New\'s latter designs'
];

// Follow Up Stage options
$follow_up_stage_options = [
    'Shows interest in idea',
    'Shows interest in portfolio',
    'Show Interest in Payment'
];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads Data Form - Sales Executive Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .accounts-container {
            margin: 20px 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
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

        .table-wrapper {
            overflow-x: auto;
        }

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .accounts-table th,
        .accounts-table td {
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .accounts-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .accounts-table tr:hover {
            background: #f8f9fa;
        }

        .btn-add {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .btn-add:hover {
            background: #0056b3;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 800px;
            max-height: 80%;
            overflow-y: auto;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        select[multiple] {
            height: 120px;
        }

        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #0056b3;
        }

        .btn-edit {
            background: #ffc107;
            color: #212529;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 5px;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .custom-multiselect {
            position: relative;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: white;
        }

        .selected-items {
            display: flex;
            flex-wrap: wrap;
            min-height: 36px;
            padding: 4px;
        }

        .selected-item {
            background: #007bff;
            color: white;
            padding: 4px 8px;
            margin: 2px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            font-size: 14px;
        }

        .selected-item .remove {
            margin-left: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .dropdown-toggle {
            padding: 8px;
            cursor: pointer;
            background: #f8f9fa;
            border-top: 1px solid #ccc;
            text-align: center;
        }

        .dropdown-list {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
        }

        .option {
            padding: 8px;
            cursor: pointer;
        }

        .option:hover {
            background: #f8f9fa;
        }

        .option.selected {
            background: #007bff;
            color: white;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }
    </style>
    <script>
        function openModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }

        function openEditModal(data) {
            const m = document.getElementById('editModal');
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_date').value = data.date;
            document.getElementById('edit_sales_executive').value = data.sales_executive;
            document.getElementById('edit_closer_name').value = data.closer_name;
            document.getElementById('edit_discord_email').value = data.discord_email;
            document.getElementById('edit_client_discord_username').value = data.client_discord_username;
            document.getElementById('edit_client_email').value = data.client_email;
            // multi-select for service items
            const multi = document.getElementById('edit_service_items');
            const selected = (data.service_items || '').split(',').map(s => s.trim()).filter(Boolean);
            for (let i = 0; i < multi.options.length; i++) {
                multi.options[i].selected = selected.includes(multi.options[i].value);
            }
            if (window.customMultiSelects && window.customMultiSelects['edit_service_items'] && typeof window.customMultiSelects['edit_service_items'].refresh === 'function') {
                window.customMultiSelects['edit_service_items'].refresh();
            }
            document.getElementById('edit_follow_up_stage').value = data.follow_up_stage;
            document.getElementById('edit_last_message').value = data.last_message || '';
            document.getElementById('edit_scenario_if_lost').value = data.scenario_if_lost || '';
            m.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Edit handlers
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    const d = this.dataset;
                    openEditModal({
                        id: d.id,
                        date: d.date,
                        sales_executive: d.sales_executive,
                        closer_name: d.closer_name,
                        discord_email: d.discord_email,
                        client_discord_username: d.client_discord_username,
                        client_email: d.client_email,
                        service_items: d.service_items,
                        follow_up_stage: d.follow_up_stage,
                        last_message: d.last_message,
                        scenario_if_lost: d.scenario_if_lost
                    });
                });
            });

            // Delete handlers
            const delForm = document.getElementById('deleteForm');
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.dataset.id;
                    if (confirm('Are you sure you want to delete this entry?')) {
                        delForm.querySelector('input[name="id"]').value = id;
                        delForm.submit();
                    }
                });
            });

            // Custom Multi-Select (tag-style dropdown) for service_items
            window.customMultiSelects = window.customMultiSelects || {};

            function initCustomMultiSelect(selectEl) {
                if (!selectEl || selectEl.dataset.enhanced === '1') return null;
                selectEl.dataset.enhanced = '1';

                const wrapper = document.createElement('div');
                wrapper.className = 'custom-multiselect';

                const selectedItems = document.createElement('div');
                selectedItems.className = 'selected-items';

                const toggle = document.createElement('div');
                toggle.className = 'dropdown-toggle';
                toggle.textContent = 'Select items';

                const list = document.createElement('div');
                list.className = 'dropdown-list';

                // Build option items
                const optionEls = [];
                for (let i = 0; i < selectEl.options.length; i++) {
                    const opt = selectEl.options[i];
                    const item = document.createElement('div');
                    item.className = 'option';
                    item.textContent = opt.textContent;
                    item.dataset.value = opt.value;
                    if (opt.selected) item.classList.add('selected');
                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const value = this.dataset.value;
                        const currentlySelected = getSelectedValues();
                        const isSelected = currentlySelected.includes(value);
                        if (isSelected) {
                            // remove
                            setSelectedValues(currentlySelected.filter(v => v !== value));
                        } else {
                            setSelectedValues([...currentlySelected, value]);
                        }
                    });
                    list.appendChild(item);
                    optionEls.push({
                        option: opt,
                        node: item
                    });
                }

                // Helpers
                function getSelectedValues() {
                    const vals = [];
                    for (let i = 0; i < selectEl.options.length; i++) {
                        if (selectEl.options[i].selected) vals.push(selectEl.options[i].value);
                    }
                    return vals;
                }

                function setSelectedValues(values) {
                    // Sync select
                    for (let i = 0; i < selectEl.options.length; i++) {
                        selectEl.options[i].selected = values.includes(selectEl.options[i].value);
                    }
                    // Refresh UI
                    refreshUI();
                }

                function refreshUI() {
                    const values = getSelectedValues();
                    // tags
                    selectedItems.innerHTML = '';
                    values.forEach(v => {
                        const option = Array.from(selectEl.options).find(o => o.value === v);
                        if (!option) return;
                        const tag = document.createElement('div');
                        tag.className = 'selected-item';
                        tag.textContent = option.textContent;
                        const remove = document.createElement('span');
                        remove.className = 'remove';
                        remove.textContent = '×';
                        remove.title = 'Remove';
                        remove.addEventListener('click', function(e) {
                            e.stopPropagation();
                            setSelectedValues(values.filter(val => val !== v));
                        });
                        tag.appendChild(remove);
                        selectedItems.appendChild(tag);
                    });
                    // options highlight
                    optionEls.forEach(({
                        option,
                        node
                    }) => {
                        if (option.selected) node.classList.add('selected');
                        else node.classList.remove('selected');
                    });
                    // placeholder if empty
                    if (values.length === 0) {
                        const ph = document.createElement('span');
                        ph.style.color = '#6c757d';
                        ph.style.padding = '6px';
                        ph.textContent = 'Select at least one item';
                        selectedItems.appendChild(ph);
                    }
                }

                // Toggle behavior
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    list.style.display = (list.style.display === 'block') ? 'none' : 'block';
                });
                document.addEventListener('click', function() {
                    list.style.display = 'none';
                });

                // Compose
                wrapper.appendChild(selectedItems);
                wrapper.appendChild(toggle);
                wrapper.appendChild(list);

                // Insert and hide original select
                selectEl.style.display = 'none';
                selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);

                // Initial UI
                refreshUI();

                return {
                    refresh: refreshUI
                };
            }

            // Initialize on Add and Edit selects
            const addSelect = document.getElementById('service_items');
            const editSelect = document.getElementById('edit_service_items');
            if (addSelect) {
                const api = initCustomMultiSelect(addSelect);
                if (api) window.customMultiSelects['service_items'] = api;
            }
            if (editSelect) {
                const api = initCustomMultiSelect(editSelect);
                if (api) window.customMultiSelects['edit_service_items'] = api;
            }

            // Client-side validation: enforce at least one selection on submit
            function validateSelection(selectEl) {
                const count = Array.from(selectEl.options).filter(o => o.selected).length;
                return count >= 1;
            }

            const addForm = document.querySelector('#addModal form');
            if (addForm && addSelect) {
                addForm.addEventListener('submit', function(e) {
                    if (!validateSelection(addSelect)) {
                        e.preventDefault();
                        alert('Please select at least one Service Item.');
                    }
                });
            }
            const editForm = document.querySelector('#editModal form');
            if (editForm && editSelect) {
                editForm.addEventListener('submit', function(e) {
                    if (!validateSelection(editSelect)) {
                        e.preventDefault();
                        alert('Please select at least one Service Item.');
                    }
                });
            }
        });

        // Ensure modal/form is closed and cleared on back/restore (bfcache) and fresh loads
        window.addEventListener('pageshow', function(event) {
            try {
                closeModal();
                var form = document.getElementById('editForm');
                if (form) {
                    form.reset();
                }
            } catch (e) {}
        });
    </script>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>Leads Data Form</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>
            <div class="accounts-container">
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">Leads data added successfully!</div>
                <?php endif; ?>
                <?php if (isset($_GET['updated'])): ?>
                    <div class="alert alert-success">Leads data updated successfully!</div>
                <?php endif; ?>
                <?php if (isset($_GET['deleted'])): ?>
                    <div class="alert alert-success">Leads data deleted successfully!</div>
                <?php endif; ?>

                <button type="button" class="btn-add" onclick="openModal()">Add New Leads Data</button>

                <!-- Hidden Delete Form -->
                <form id="deleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="">
                </form>

                <!-- Filter Form -->
                <div class="filter-container" style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h3>Filter Leads Data</h3>
                    <form method="GET" action="">
                        <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: end;">
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_date">Date:</label>
                                <input type="date" id="filter_date" name="filter_date" value="<?php echo isset($_GET['filter_date']) ? htmlspecialchars($_GET['filter_date']) : ''; ?>">
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_discord_email">Discord Email:</label>
                                <select id="filter_discord_email" name="filter_discord_email">
                                    <option value="">All</option>
                                    <?php foreach ($discord_emails as $email): ?>
                                        <option value="<?php echo htmlspecialchars($email); ?>" <?php echo (isset($_GET['filter_discord_email']) && $_GET['filter_discord_email'] === $email) ? 'selected' : ''; ?>><?php echo htmlspecialchars($email); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_client_discord_username">Client Discord Username:</label>
                                <input type="text" id="filter_client_discord_username" name="filter_client_discord_username" value="<?php echo isset($_GET['filter_client_discord_username']) ? htmlspecialchars($_GET['filter_client_discord_username']) : ''; ?>" placeholder="Partial match">
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_service_items">Service Items:</label>
                                <input type="text" id="filter_service_items" name="filter_service_items" value="<?php echo isset($_GET['filter_service_items']) ? htmlspecialchars($_GET['filter_service_items']) : ''; ?>" placeholder="Partial match">
                            </div>
                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                <label for="filter_follow_up_stage">Follow Up Stage:</label>
                                <select id="filter_follow_up_stage" name="filter_follow_up_stage">
                                    <option value="">All</option>
                                    <?php foreach ($follow_up_stage_options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo (isset($_GET['filter_follow_up_stage']) && $_GET['filter_follow_up_stage'] === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Filter</button>
                                <a href="<?php echo basename(__FILE__); ?>" style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">Clear Filters</a>
                            </div>
                        </div>
                    </form>
                </div>

                <h2>Leads Data Entries</h2>
                <?php if ($entries): ?>
                    <div class="table-wrapper">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Sales Executive</th>
                                    <th>Closer Name</th>
                                    <th>Discord Email</th>
                                    <th>Client Discord Username</th>
                                    <th>Client Email</th>
                                    <th>Service Items</th>
                                    <th>Follow Up Stage</th>
                                    <th>Last Message</th>
                                    <th>Scenario If Lost</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial_counter = count($entries); ?>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><?php echo $serial_counter--; ?></td>
                                        <td><?php echo htmlspecialchars($entry['id']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['date']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['sales_executive']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['closer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['discord_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_discord_username']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['client_email']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['service_items']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['follow_up_stage']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['last_message']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['scenario_if_lost']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td>
                                            <button type="button" class="btn-edit"
                                                data-id="<?php echo htmlspecialchars($entry['id']); ?>"
                                                data-date="<?php echo htmlspecialchars($entry['date']); ?>"
                                                data-sales_executive="<?php echo htmlspecialchars($entry['sales_executive']); ?>"
                                                data-closer_name="<?php echo htmlspecialchars($entry['closer_name']); ?>"
                                                data-discord_email="<?php echo htmlspecialchars($entry['discord_email']); ?>"
                                                data-client_discord_username="<?php echo htmlspecialchars($entry['client_discord_username']); ?>"
                                                data-client_email="<?php echo htmlspecialchars($entry['client_email']); ?>"
                                                data-service_items="<?php echo htmlspecialchars($entry['service_items']); ?>"
                                                data-follow_up_stage="<?php echo htmlspecialchars($entry['follow_up_stage']); ?>"
                                                data-last_message="<?php echo htmlspecialchars($entry['last_message']); ?>"
                                                data-scenario_if_lost="<?php echo htmlspecialchars($entry['scenario_if_lost']); ?>">Edit</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No entries found.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Modal -->
        <div id="addModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Add New Leads Data</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="form-group">
                        <label for="date">Date: <span style="color: red;">*</span></label>
                        <input type="date" id="date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="sales_executive">Sales Executive: <span style="color: red;">*</span></label>
                        <input type="text" id="sales_executive" name="sales_executive" value="<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="closer_name">Closer Name: <span style="color: red;">*</span></label>
                        <input type="text" id="closer_name" name="closer_name" required>
                    </div>
                    <div class="form-group">
                        <label for="discord_email">Discord Email: <span style="color: red;">*</span></label>
                        <select id="discord_email" name="discord_email" required>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="client_discord_username">Client Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="client_email">Client's Email:</label>
                        <input type="email" id="client_email" name="client_email">
                    </div>
                    <div class="form-group">
                        <label for="service_items">Service Items: <span style="color: red;">*</span></label>
                        <select id="service_items" name="service_items[]" multiple required>
                            <?php foreach ($service_items_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Select at least one service item</small>
                    </div>
                    <div class="form-group">
                        <label for="follow_up_stage">Follow Up Stage: <span style="color: red;">*</span></label>
                        <select id="follow_up_stage" name="follow_up_stage" required>
                            <option value="">Select Follow Up Stage</option>
                            <?php foreach ($follow_up_stage_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="last_message">Last Message:</label>
                        <textarea id="last_message" name="last_message"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="scenario_if_lost">Scenario If Lost:</label>
                        <textarea id="scenario_if_lost" name="scenario_if_lost"></textarea>
                    </div>
                    <button type="submit">Submit</button>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit Leads Data</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="form-group">
                        <label for="edit_date">Date: <span style="color: red;">*</span></label>
                        <input type="date" id="edit_date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_sales_executive">Sales Executive: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_sales_executive" name="sales_executive" required readonly>
                    </div>
                    <div class="form-group">
                        <label for="edit_closer_name">Closer Name: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_closer_name" name="closer_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_discord_email">Discord Email: <span style="color: red;">*</span></label>
                        <select id="edit_discord_email" name="discord_email" required readonly>
                            <option value="">Select Discord Email</option>
                            <?php foreach ($discord_emails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_client_discord_username">Client Discord Username: <span style="color: red;">*</span></label>
                        <input type="text" id="edit_client_discord_username" name="client_discord_username" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_client_email">Client's Email:</label>
                        <input type="email" id="edit_client_email" name="client_email">
                    </div>
                    <div class="form-group">
                        <label for="edit_service_items">Service Items: <span style="color: red;">*</span></label>
                        <select id="edit_service_items" name="service_items[]" multiple required>
                            <?php foreach ($service_items_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Select at least one service item</small>
                    </div>
                    <div class="form-group">
                        <label for="edit_follow_up_stage">Follow Up Stage: <span style="color: red;">*</span></label>
                        <select id="edit_follow_up_stage" name="follow_up_stage" required>
                            <option value="">Select Follow Up Stage</option>
                            <?php foreach ($follow_up_stage_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_message">Last Message:</label>
                        <textarea id="edit_last_message" name="last_message"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_scenario_if_lost">Scenario If Lost:</label>
                        <textarea id="edit_scenario_if_lost" name="scenario_if_lost"></textarea>
                    </div>
                    <button type="submit">Update</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>