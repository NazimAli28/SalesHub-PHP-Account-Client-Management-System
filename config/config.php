<?php
/**
 * Application configuration.
 *
 * Defaults below work with a stock XAMPP install. To override them without
 * touching this file, copy config.local.example.php to config.local.php
 * (git-ignored) and edit the values there.
 */
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Database
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_NAME') || define('DB_NAME', 'saleshub');

// Timezone (PHP name + matching MySQL offset)
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Karachi');
defined('DB_TIMEZONE_OFFSET') || define('DB_TIMEZONE_OFFSET', '+05:00');

// Office network restriction for agent-facing pages (sales executives / TLs).
// Disabled by default so the demo works anywhere; enable it in production.
defined('IP_RESTRICTION_ENABLED') || define('IP_RESTRICTION_ENABLED', false);
if (!isset($allowed_ip_ranges)) {
    $allowed_ip_ranges = ['192.168.1.0/24', '127.0.0.1', '::1'];
}

date_default_timezone_set(APP_TIMEZONE);

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection failed. Check config/config.php (or config.local.php).');
}

$conn->query("SET time_zone = '" . $conn->real_escape_string(DB_TIMEZONE_OFFSET) . "'");
$conn->set_charset('utf8mb4');

/**
 * Whether the current client IP is inside one of $allowed_ip_ranges.
 * Entries may be exact IPs (IPv4/IPv6) or IPv4 CIDR ranges.
 */
function is_ip_allowed()
{
    global $allowed_ip_ranges;

    if (!IP_RESTRICTION_ENABLED) {
        return true;
    }

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($allowed_ip_ranges as $range) {
        if ($client_ip === $range) {
            return true;
        }

        if (strpos($range, '/') !== false && filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            list($subnet, $mask) = explode('/', $range);
            $mask = (int) $mask;
            $netmask = $mask === 0 ? 0 : (~0 << (32 - $mask));

            if ((ip2long($client_ip) & $netmask) === (ip2long($subnet) & $netmask)) {
                return true;
            }
        }
    }

    return false;
}
