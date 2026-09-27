<?php
session_start();

// If access is denied due to IP restriction, force user back to login screen
// and clear any existing session to avoid redirect loops.
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    session_unset();
    session_destroy();
}

// Redirect to dashboard if already logged in (and not in an access_denied state)
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: dashboards/dashboard_admin.php");
            break;
        case 'support':
            header("Location: dashboards/dashboard_support.php");
            break;
        case 'tl':
            header("Location: dashboards/dashboard_tl.php");
            break;
        case 'sales_executive':
            header("Location: dashboards/dashboard_sales_executive.php");
            break;
        default:
            header("Location: index.php");
            break;
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Management - Login</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .login {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f4f4;
        }
    </style>
</head>

<body>
    <section class="login">
        <div class="login-container">
            <div class="login-form">
                <h2>Sales Management System</h2>
                <h3>Login</h3>

                <?php if (isset($_GET['error'])): ?>
                    <div class="error-message">
                        <?php
                        switch ($_GET['error']) {
                            case 'invalid':
                                echo "Invalid username or password.";
                                break;
                            case 'empty':
                                echo "Please fill in all fields.";
                                break;
                            case 'access_denied':
                                echo "Access denied. This application is only accessible from the office network.";
                                break;
                            case 'mobile_restricted':
                                echo "Access denied. Mobile login is not allowed for your account. Please use a desktop or laptop.";
                                break;
                            default:
                                echo "An error occurred.";
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <form action="includes/login_process.php" method="POST" id="loginForm">
                    <!-- Hidden field for device detection -->
                    <input type="hidden" name="device_is_mobile" id="device_is_mobile" value="false">
                    <input type="hidden" name="device_detection" id="device_detection" value="">

                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                    </div>

                    <button type="submit" class="login-btn">Login</button>
                </form>

                <script>
                    // Enhanced mobile device detection that can't be easily bypassed
                    // This detection runs before form submission to catch mobile devices
                    // even when they're in desktop mode
                    (function() {
                        function detectMobileDevice() {
                            var isMobile = false;
                            var detectionData = [];
                            var confidence = 0;

                            // Check for touch capability (mobile devices have touch screens)
                            // This is a strong indicator that can't be easily faked
                            var hasTouch = 'ontouchstart' in window ||
                                navigator.maxTouchPoints > 0 ||
                                navigator.msMaxTouchPoints > 0 ||
                                (navigator.userAgent.match(/Touch/i) !== null);

                            if (hasTouch) {
                                confidence += 3;
                                detectionData.push('touch');
                            }

                            // Get actual screen dimensions (not viewport - these can't be faked easily)
                            var screenWidth = window.screen.width;
                            var screenHeight = window.screen.height;
                            var viewportWidth = window.innerWidth || document.documentElement.clientWidth;

                            // Mobile devices have small physical screens
                            // Even in desktop mode, screen.width remains small
                            if (screenWidth <= 768) {
                                confidence += 3;
                                detectionData.push('small_screen_' + screenWidth);
                            } else if (screenWidth <= 1024 && hasTouch) {
                                confidence += 2;
                                detectionData.push('medium_screen_touch');
                            }

                            // Check for mobile-specific features in navigator
                            if (navigator.userAgentData && navigator.userAgentData.mobile === true) {
                                confidence += 4;
                                detectionData.push('ua_mobile');
                            }

                            // Check device pixel ratio (mobile devices often have high DPR)
                            var dpr = window.devicePixelRatio || 1;
                            if (dpr >= 2 && screenWidth <= 1024) {
                                confidence += 1;
                                detectionData.push('high_dpr_' + dpr);
                            }

                            // Check orientation API (mobile devices support this)
                            if (window.orientation !== undefined) {
                                confidence += 2;
                                detectionData.push('orientation_api');
                            }

                            // Check for mobile-specific media queries
                            var mediaQuery = window.matchMedia('(pointer: coarse)');
                            if (mediaQuery.matches) {
                                confidence += 2;
                                detectionData.push('coarse_pointer');
                            }

                            // Check connection type (mobile devices often have mobile network)
                            if (navigator.connection || navigator.mozConnection || navigator.webkitConnection) {
                                var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                                if (connection && connection.type &&
                                    (connection.type === 'cellular' || connection.type === '2g' ||
                                        connection.type === '3g' || connection.type === '4g' ||
                                        connection.type === '5g')) {
                                    confidence += 2;
                                    detectionData.push('mobile_network_' + connection.type);
                                }
                            }

                            // Check for viewport manipulation (desktop mode on mobile)
                            // If viewport is large but screen is small, it's likely mobile in desktop mode
                            if (viewportWidth > 1024 && screenWidth <= 1024 && hasTouch) {
                                confidence += 2;
                                detectionData.push('viewport_mismatch');
                            }

                            // Check for mobile browser indicators in user agent
                            var ua = navigator.userAgent.toLowerCase();
                            var mobileUAKeywords = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
                            for (var i = 0; i < mobileUAKeywords.length; i++) {
                                if (ua.indexOf(mobileUAKeywords[i]) !== -1) {
                                    confidence += 2;
                                    detectionData.push('ua_' + mobileUAKeywords[i]);
                                    break;
                                }
                            }

                            // If confidence is high enough, consider it mobile
                            // Lower threshold to catch more cases
                            isMobile = confidence >= 2;

                            return {
                                isMobile: isMobile,
                                confidence: confidence,
                                data: detectionData.join(',')
                            };
                        }

                        // Detect device when page loads
                        var detection = detectMobileDevice();
                        document.getElementById('device_is_mobile').value = detection.isMobile ? 'true' : 'false';
                        document.getElementById('device_detection').value = detection.data + '|conf:' + detection.confidence;

                        // Re-detect on form submit (in case user switches modes)
                        document.getElementById('loginForm').addEventListener('submit', function(e) {
                            // Force re-detection right before submit
                            detection = detectMobileDevice();
                            document.getElementById('device_is_mobile').value = detection.isMobile ? 'true' : 'false';
                            document.getElementById('device_detection').value = detection.data + '|conf:' + detection.confidence;
                        });

                        // Also detect on window resize/orientation change
                        window.addEventListener('resize', function() {
                            detection = detectMobileDevice();
                            document.getElementById('device_is_mobile').value = detection.isMobile ? 'true' : 'false';
                            document.getElementById('device_detection').value = detection.data + '|conf:' + detection.confidence;
                        });

                        if (window.orientation !== undefined) {
                            window.addEventListener('orientationchange', function() {
                                setTimeout(function() {
                                    detection = detectMobileDevice();
                                    document.getElementById('device_is_mobile').value = detection.isMobile ? 'true' : 'false';
                                    document.getElementById('device_detection').value = detection.data + '|conf:' + detection.confidence;
                                }, 100);
                            });
                        }
                    })();
                </script>
            </div>
        </div>
    </section>
</body>

</html>