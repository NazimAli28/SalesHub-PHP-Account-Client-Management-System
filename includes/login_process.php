<?php
session_start();
require_once '../config/config.php';
require_once 'device_helper.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Validate input
    if (empty($username) || empty($password)) {
        header("Location: ../index.php?error=empty");
        exit();
    }

    // Prepare and execute query
    $stmt = $conn->prepare("SELECT id, username, password, role, pc_number, name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        // Verify password
        if (password_verify($password, $user['password'])) {
            // Detect device type and store in session
            $isMobile = is_mobile_device();
            $_SESSION['device_is_mobile'] = $isMobile;

            // Store device detection data for debugging/verification
            if (isset($_POST['device_detection'])) {
                $_SESSION['device_detection'] = $_POST['device_detection'];
            }

            // Check device restriction based on role
            if ($isMobile && !role_allows_mobile($user['role'])) {
                // Deny login from mobile for this role
                header("Location: ../index.php?error=mobile_restricted");
                exit();
            }

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            // Persist actual PC number if present; fallback to username
            $_SESSION['pc_number'] = isset($user['pc_number']) && $user['pc_number'] !== '' ? $user['pc_number'] : $user['username'];

            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: ../dashboards/dashboard_admin.php");
                    break;
                case 'support':
                    header("Location: ../dashboards/dashboard_support.php");
                    break;
                case 'tl':
                    header("Location: ../dashboards/dashboard_tl.php");
                    break;
                case 'sales_executive':
                    header("Location: ../dashboards/dashboard_sales_executive.php");
                    break;
                default:
                    header("Location: ../index.php");
                    break;
            }
            exit();
        } else {
            header("Location: ../index.php?error=invalid");
            exit();
        }
    } else {
        header("Location: ../index.php?error=invalid");
        exit();
    }

    $stmt->close();
}

$conn->close();
