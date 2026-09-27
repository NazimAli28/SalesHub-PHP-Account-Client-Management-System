<?php
session_start();

// Check if user is logged in and is admin or support
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'support'])) {
    header("Location: ../index.php");
    exit();
}

// Prevent cache so Back won't restore stale form/modal state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/config.php';
require_once '../includes/device_helper.php';

// Check device restriction (admin and support roles don't allow mobile)
check_device_restriction();

// Check IP restriction for support (admin can access from anywhere)
// if ($_SESSION['role'] === 'support' && !is_ip_allowed()) {
//     header("Location: ../index.php?error=access_denied");
//     exit();
// }

// Pagination settings
$rows_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $rows_per_page;

// Filter settings
$filter_username = isset($_GET['filter_username']) ? trim($_GET['filter_username']) : '';
$filter_name = isset($_GET['filter_name']) ? trim($_GET['filter_name']) : '';
$filter_role = isset($_GET['filter_role']) ? $_GET['filter_role'] : '';
$filter_pc = isset($_GET['filter_pc']) ? trim($_GET['filter_pc']) : '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];
$types = '';

if (!empty($filter_username)) {
    $where_conditions[] = "username LIKE ?";
    $params[] = "%" . $filter_username . "%";
    $types .= 's';
}
if (!empty($filter_name)) {
    $where_conditions[] = "name LIKE ?";
    $params[] = "%" . $filter_name . "%";
    $types .= 's';
}
if (!empty($filter_role)) {
    $where_conditions[] = "role = ?";
    $params[] = $filter_role;
    $types .= 's';
}
if (!empty($filter_pc)) {
    $where_conditions[] = "pc_number = ?";
    $params[] = $filter_pc;
    $types .= 's';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        // Add new user
        $username = trim($_POST['username']);
        $name = trim($_POST['name']);
        $password = $_POST['password'];
        $role = $_POST['role'];
        $pc_number = trim($_POST['pc_number']);

        if (empty($username) || empty($name) || empty($password) || empty($role)) {
            $_SESSION['message'] = 'All fields are required.';
            $_SESSION['message_type'] = 'error';
        } else {
            // Check if username already exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $_SESSION['message'] = 'Username already exists.';
                $_SESSION['message_type'] = 'error';
            } else {
                // Insert new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert_stmt = $conn->prepare("INSERT INTO users (username, name, password, role, pc_number) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->bind_param("sssss", $username, $name, $hashed_password, $role, $pc_number);

                if ($insert_stmt->execute()) {
                    $_SESSION['message'] = 'User added successfully.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Error adding user: ' . $conn->error;
                    $_SESSION['message_type'] = 'error';
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
    } elseif (isset($_POST['edit_user'])) {
        // Edit existing user
        $user_id = $_POST['user_id'];
        $username = trim($_POST['username']);
        $name = trim($_POST['name']);
        $role = $_POST['role'];
        $password = $_POST['password'];
        $pc_number = trim($_POST['pc_number']);

        if (empty($username) || empty($name) || empty($role)) {
            $_SESSION['message'] = 'Username, name and role are required.';
            $_SESSION['message_type'] = 'error';
        } else {
            // Check if username already exists (excluding current user)
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_stmt->bind_param("si", $username, $user_id);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $_SESSION['message'] = 'Username already exists.';
                $_SESSION['message_type'] = 'error';
            } else {
                if (!empty($password)) {
                    // Update with new password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET username = ?, name = ?, password = ?, role = ?, pc_number = ? WHERE id = ?");
                    $update_stmt->bind_param("sssssi", $username, $name, $hashed_password, $role, $pc_number, $user_id);
                } else {
                    // Update without changing password
                    $update_stmt = $conn->prepare("UPDATE users SET username = ?, name = ?, role = ?, pc_number = ? WHERE id = ?");
                    $update_stmt->bind_param("ssssi", $username, $name, $role, $pc_number, $user_id);
                }

                if ($update_stmt->execute()) {
                    // Update agent_name in accounts table for this pc_number
                    $update_accounts_stmt = $conn->prepare("UPDATE accounts SET agent_name = ? WHERE pc_number = ?");
                    $update_accounts_stmt->bind_param("ss", $name, $username);
                    $update_accounts_stmt->execute();
                    $update_accounts_stmt->close();

                    $_SESSION['message'] = 'User updated successfully.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Error updating user: ' . $conn->error;
                    $_SESSION['message_type'] = 'error';
                }
                $update_stmt->close();
            }
            $check_stmt->close();
        }
    } elseif (isset($_POST['delete_user'])) {
        // Delete user
        $user_id = $_POST['user_id'];

        $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $delete_stmt->bind_param("i", $user_id);

        if ($delete_stmt->execute()) {
            $_SESSION['message'] = 'User deleted successfully.';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Error deleting user: ' . $conn->error;
            $_SESSION['message_type'] = 'error';
        }
        $delete_stmt->close();
    }

    $redirect_params = $_GET;
    $redirect_params['page'] = 1;
    $redirect_url = $_SERVER['PHP_SELF'] . (empty($redirect_params) ? '' : '?' . http_build_query($redirect_params));
    header("Location: " . $redirect_url);
    exit();
}

// Get total users for pagination with filters
$total_users_query = "SELECT COUNT(*) as count FROM users $where_clause";
if (!empty($params)) {
    $total_stmt = $conn->prepare($total_users_query);
    $total_stmt->bind_param($types, ...$params);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_users = $total_result->fetch_assoc()['count'];
    $total_stmt->close();
} else {
    $total_users_result = $conn->query("SELECT COUNT(*) as count FROM users");
    $total_users = $total_users_result->fetch_assoc()['count'];
}
$total_pages = ceil($total_users / $rows_per_page);

// Fetch paginated users with filters
$users = [];
$fetch_query = "SELECT id, username, name, role, created_at, pc_number FROM users $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?";

$bind_params = array_merge($params, [$rows_per_page, $offset]);
$bind_types = $types . 'ii';

$fetch_stmt = $conn->prepare($fetch_query);
if ($fetch_stmt) {
    if ($fetch_stmt->bind_param($bind_types, ...$bind_params) && $fetch_stmt->execute()) {
        $fetch_result = $fetch_stmt->get_result();
        while ($row = $fetch_result->fetch_assoc()) {
            $users[] = $row;
        }
        $fetch_result->free();
        $fetch_stmt->close();
    } else {
        $fetch_stmt->close();
        $message = 'Error fetching users: ' . $conn->error;
        $message_type = 'error';
    }
} else {
    $message = 'Error fetching users: ' . $conn->error;
    $message_type = 'error';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Sales Management</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .user-management-container {
            padding: 20px;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-add {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .btn-add:hover {
            background: #218838;
        }

        .form-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            display: none;
        }

        .form-container.show {
            display: block;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .form-actions {
            text-align: right;
        }

        .btn-submit {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #0056b3;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 10px;
        }

        .btn-cancel:hover {
            background: #545b62;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .users-table th,
        .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .users-table tr:hover {
            background: #f8f9fa;
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

        .btn-details {
            background: #17a2b8;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 5px;
        }

        .btn-details:hover {
            background: #138496;
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #aaa;
        }

        .modal-close:hover {
            color: #000;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .page-link {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 2px;
            background: #f8f9fa;
            color: #007bff;
            text-decoration: none;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .page-link:hover {
            background: #e9ecef;
        }

        .page-link.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1>User Management</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="../includes/logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="user-management-container">
                <a href="dashboard_<?php echo $_SESSION['role']; ?>.php" class="back-link">&larr; Back to Dashboard</a>

                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <button class="btn-add" onclick="toggleForm('add')">Add New User</button>

                <!-- Filter Form -->
                <div class="form-container" style="display: block; margin-bottom: 20px;">
                    <h3>Filter Users</h3>
                    <form method="get" id="filter-form">
                        <div class="form-group" style="display: inline-block; width: 23%; margin-right: 2%;">
                            <label for="filter_username">Username:</label>
                            <input type="text" id="filter_username" name="filter_username" value="<?php echo htmlspecialchars($filter_username); ?>">
                        </div>
                        <div class="form-group" style="display: inline-block; width: 23%; margin-right: 2%;">
                            <label for="filter_name">Name:</label>
                            <input type="text" id="filter_name" name="filter_name" value="<?php echo htmlspecialchars($filter_name); ?>">
                        </div>
                        <div class="form-group" style="display: inline-block; width: 23%; margin-right: 2%;">
                            <label for="filter_role">Role:</label>
                            <select id="filter_role" name="filter_role">
                                <option value="">All Roles</option>
                                <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="support" <?php echo $filter_role === 'support' ? 'selected' : ''; ?>>Support</option>
                                <option value="tl" <?php echo $filter_role === 'tl' ? 'selected' : ''; ?>>Team Leader</option>
                                <option value="sales_executive" <?php echo $filter_role === 'sales_executive' ? 'selected' : ''; ?>>Sales Executive</option>
                            </select>
                        </div>
                        <div class="form-group" style="display: inline-block; width: 23%;">
                            <label for="filter_pc">PC Number:</label>
                            <input type="text" id="filter_pc" name="filter_pc" value="<?php echo htmlspecialchars($filter_pc); ?>">
                        </div>
                        <div class="form-actions" style="display: inline-block; vertical-align: top; margin-top: 25px; margin-left: 10px;">
                            <button type="submit" class="btn-submit">Filter</button>
                            <a href="?page=1" class="btn-cancel" style="text-decoration: none; padding: 10px 20px; display: inline-block; margin-left: 5px;">Clear Filters</a>
                        </div>
                    </form>
                </div>

                <div id="add-form" class="form-container">
                    <h3>Add New User</h3>
                    <form id="add-user-form" method="post">
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="name">Name:</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="text" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="role">Role:</label>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="support">Support</option>
                                <option value="tl">Team Leader</option>
                                <option value="sales_executive">Sales Executive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="pc_number">PC Number:</label>
                            <input type="text" id="pc_number" name="pc_number">
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_user" class="btn-submit">Add User</button>
                            <button type="button" class="btn-cancel" onclick="toggleForm('add')">Cancel</button>
                        </div>
                    </form>
                </div>

                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Serial No</th>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>PC Number</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8">No users found matching the filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $index => $user): ?>
                                <tr>
                                    <td><?php echo ($page - 1) * $rows_per_page + $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                                    <td><?php echo htmlspecialchars($user['pc_number']); ?></td>
                                    <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                    <td>
                                        <button class="btn-edit" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['role'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['pc_number'], ENT_QUOTES); ?>')">Edit</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn-delete" onclick="return confirm('Are you sure you want to delete this user?')">Delete</button>
                                        </form>
                                        <button class="btn-details" onclick="showUserDetails(<?php echo $user['id']; ?>)">Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php
                    // Build query string for pagination links
                    $query_params = $_GET;
                    unset($query_params['page']); // Remove page from query params
                    $base_query = http_build_query($query_params);
                    $separator = empty($base_query) ? '?' : '&';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $base_query ? $separator . $base_query : ''; ?>" class="page-link">Previous</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $base_query ? $separator . $base_query : ''; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $base_query ? $separator . $base_query : ''; ?>" class="page-link">Next</a>
                    <?php endif; ?>
                </div>

                <div id="edit-modal" class="modal">
                    <div class="modal-content">
                        <span class="modal-close" onclick="closeModal()">&times;</span>
                        <h3>Edit User</h3>
                        <form id="edit-user-form" method="post">
                            <input type="hidden" id="edit-user-id" name="user_id">
                            <div class="form-group">
                                <label for="edit-username">Username:</label>
                                <input type="text" id="edit-username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="edit-name">Name:</label>
                                <input type="text" id="edit-name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="edit-password">Password:</label>
                                <input type="text" id="edit-password" name="password">
                            </div>
                            <div class="form-group">
                                <label for="edit-role">Role:</label>
                                <select id="edit-role" name="role" required>
                                    <option value="admin">Admin</option>
                                    <option value="support">Support</option>
                                    <option value="tl">Team Leader</option>
                                    <option value="sales_executive">Sales Executive</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit-pc_number">PC Number:</label>
                                <input type="text" id="edit-pc_number" name="pc_number">
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="edit_user" class="btn-submit">Update User</button>
                                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="details-modal" class="modal">
                    <div class="modal-content">
                        <span class="modal-close" onclick="closeDetailsModal()">&times;</span>
                        <h3>User Details</h3>
                        <div id="details-content">
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleForm(formType) {
            const addForm = document.getElementById('add-form');
            const editModal = document.getElementById('edit-modal');
            if (formType === 'add') {
                addForm.classList.toggle('show');
                editModal.classList.remove('show');
                togglePcNumberVisibility('add');
            } else if (formType === 'edit') {
                editModal.classList.add('show');
                addForm.classList.remove('show');
                togglePcNumberVisibility('edit');
            }
        }

        function editUser(id, username, name, role, pc_number) {
            document.getElementById('edit-user-id').value = id;
            document.getElementById('edit-username').value = username;
            document.getElementById('edit-name').value = name;
            document.getElementById('edit-role').value = role;
            document.getElementById('edit-pc_number').value = pc_number;
            document.getElementById('edit-password').value = '';
            document.getElementById('edit-modal').classList.add('show');
            togglePcNumberVisibility('edit');
        }

        function closeModal() {
            document.getElementById('edit-modal').classList.remove('show');
        }

        function togglePcNumberVisibility(formType) {
            const roleSelect = formType === 'add' ? document.getElementById('role') : document.getElementById('edit-role');
            const pcNumberGroup = formType === 'add' ? document.getElementById('pc_number').parentElement : document.getElementById('edit-pc_number').parentElement;

            if (roleSelect.value === 'sales_executive') {
                pcNumberGroup.style.display = 'block';
            } else {
                pcNumberGroup.style.display = 'none';
            }
        }

        // Event listeners for role changes
        document.getElementById('role').addEventListener('change', function() {
            togglePcNumberVisibility('add');
        });

        document.getElementById('edit-role').addEventListener('change', function() {
            togglePcNumberVisibility('edit');
        });

        // Initial hide on page load
        document.addEventListener('DOMContentLoaded', function() {
            togglePcNumberVisibility('add');
            togglePcNumberVisibility('edit');
        });

        // Close modal when clicking outside
        document.getElementById('edit-modal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });

        function showUserDetails(userId) {
            const modal = document.getElementById('details-modal');
            const content = document.getElementById('details-content');
            content.innerHTML = '<p>Loading...</p>';
            modal.classList.add('show');

            fetch('../api/get_user_details.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        content.innerHTML = '<p>Error: ' + data.error + '</p>';
                    } else {
                        content.innerHTML = `
                            <p><strong>Total Emails:</strong> ${data.total}</p>
                            <p><strong>Active:</strong> ${data.active}</p>
                            <p><strong>Spam:</strong> ${data.spam}</p>
                            <p><strong>Limited:</strong> ${data.limited}</p>
                            <p><strong>Disabled:</strong> ${data.disabled}</p>
                            <p><strong>Violation:</strong> ${data.violation}</p>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = '<p>Error loading details.</p>';
                });
        }

        function closeDetailsModal() {
            document.getElementById('details-modal').classList.remove('show');
        }

        // Ensure modal/form is closed and cleared on back/restore (bfcache) and fresh loads
        window.addEventListener('pageshow', function(event) {
            try {
                closeModal();
                closeDetailsModal();
                var addForm = document.getElementById('add-user-form');
                if (addForm) {
                    addForm.reset();
                }
                var editForm = document.getElementById('edit-user-form');
                if (editForm) {
                    editForm.reset();
                }
            } catch (e) {}
        });
    </script>
</body>

</html>