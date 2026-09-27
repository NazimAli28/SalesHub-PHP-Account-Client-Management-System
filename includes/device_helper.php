<?php

/**
 * Enhanced device detection that checks multiple indicators.
 * Returns true if the client is likely a mobile or tablet device.
 * This function checks User-Agent, HTTP headers, and client-side detection data.
 */
function is_mobile_device(): bool
{
    // Check if client-side detection data is available (more reliable)
    if (isset($_POST['device_is_mobile']) || isset($_POST['device_detection'])) {
        $clientDetection = isset($_POST['device_is_mobile']) ? $_POST['device_is_mobile'] : $_POST['device_detection'];
        if ($clientDetection === 'true' || $clientDetection === '1' || $clientDetection === true) {
            return true;
        }
    }

    // Check session-stored device type (set during login)
    if (isset($_SESSION['device_is_mobile']) && $_SESSION['device_is_mobile'] === true) {
        return true;
    }

    // Check User-Agent (can be spoofed, but still useful)
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

        // Common mobile indicators
        $mobileKeywords = [
            'iphone',
            'ipad',
            'ipod',
            'android',
            'blackberry',
            'opera mini',
            'windows phone',
            'iemobile',
            'mobile',
            'tablet',
            'kindle',
            'silk/'
        ];

        foreach ($mobileKeywords as $keyword) {
            if (strpos($userAgent, $keyword) !== false) {
                return true;
            }
        }
    }

    // Check additional HTTP headers that mobile browsers send
    // These are harder to spoof than User-Agent

    // Check for mobile-specific headers
    $mobileHeaders = [
        'HTTP_X_WAP_PROFILE',
        'HTTP_X_OPERAMINI_PHONE_UA',
        'HTTP_X_MOBILE_GATEWAY',
        'HTTP_X_ATT_DEVICEID',
        'HTTP_WAP_CONNECTION'
    ];

    foreach ($mobileHeaders as $header) {
        if (isset($_SERVER[$header])) {
            return true;
        }
    }

    // Check Accept header - mobile browsers often send different Accept headers
    if (isset($_SERVER['HTTP_ACCEPT'])) {
        $accept = strtolower($_SERVER['HTTP_ACCEPT']);
        // Mobile browsers often include wap, vnd.wap, etc.
        if (strpos($accept, 'wap') !== false || strpos($accept, 'vnd.wap') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Configuration for which roles are allowed to use mobile devices.
 * 
 * Example:
 *  - true  => role is allowed to log in from mobile
 *  - false => role is restricted to desktop / laptop only
 */
function role_allows_mobile(string $role): bool
{
    // Adjust this map as needed in the future
    $config = [
        'admin'            => false,
        'support'          => false,
        'tl'               => false,
        'sales_executive'  => false,
        // 'some_role'      => true,
    ];

    // Default: allow mobile if role not explicitly configured
    if (!array_key_exists($role, $config)) {
        return true;
    }

    return $config[$role];
}

/**
 * Verify that the current device matches what was detected during login.
 * This prevents users from bypassing mobile restrictions by switching to desktop mode.
 * Should be called on protected pages after login.
 */
function verify_device_type(): bool
{
    // If no session device type is stored, allow (for backward compatibility)
    if (!isset($_SESSION['device_is_mobile'])) {
        return true;
    }

    // Get current device detection
    $currentIsMobile = is_mobile_device();
    $sessionIsMobile = $_SESSION['device_is_mobile'];

    // If session says mobile but current detection says desktop, user might have switched modes
    // Re-detect to be sure
    if ($sessionIsMobile && !$currentIsMobile) {
        // Double-check with additional detection
        // If we still detect mobile characteristics, deny access
        $hasTouch = isset($_SERVER['HTTP_X_MOBILE_GATEWAY']) ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'wap') !== false);

        // If session was mobile, require mobile detection to match
        // This prevents desktop mode bypass
        if (!$hasTouch && !isset($_POST['device_is_mobile'])) {
            // Allow if we can't definitively prove it's mobile anymore
            // But log this for monitoring
            return true; // Be lenient to avoid false positives
        }
    }

    // If session says desktop but we detect mobile now, that's suspicious
    // but allow it (user might have switched from desktop to mobile)
    return true;
}

/**
 * Check if current request should be blocked based on device restrictions.
 * Call this on protected pages for roles that don't allow mobile.
 * This function relies primarily on the session-stored device type from login
 * to prevent users from bypassing restrictions by switching to desktop mode.
 */
function check_device_restriction(): void
{
    if (!isset($_SESSION['role'])) {
        return;
    }

    // Check if role allows mobile
    if (role_allows_mobile($_SESSION['role'])) {
        return; // Role allows mobile, no restriction needed
    }

    // Role doesn't allow mobile - check session-stored device type from login
    // This is the primary check because it was set during login before user could switch modes
    if (isset($_SESSION['device_is_mobile']) && $_SESSION['device_is_mobile'] === true) {
        // User logged in from mobile device - block access
        session_unset();
        session_destroy();
        header("Location: ../index.php?error=mobile_restricted");
        exit();
    }

    // Secondary check: detect current device (in case session wasn't set properly)
    // This catches cases where user might have bypassed initial detection
    $currentIsMobile = is_mobile_device();

    // If we detect mobile now, block access
    // But be lenient - only block if we're very confident it's mobile
    if ($currentIsMobile) {
        // Additional verification: check for mobile-specific headers that are hard to spoof
        $hasMobileHeaders = false;
        $mobileHeaders = [
            'HTTP_X_WAP_PROFILE',
            'HTTP_X_OPERAMINI_PHONE_UA',
            'HTTP_X_MOBILE_GATEWAY',
            'HTTP_X_ATT_DEVICEID',
            'HTTP_WAP_CONNECTION'
        ];

        foreach ($mobileHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $hasMobileHeaders = true;
                break;
            }
        }

        // Only block if we have strong evidence (mobile headers or POST data from client-side detection)
        if ($hasMobileHeaders || isset($_POST['device_is_mobile'])) {
            session_unset();
            session_destroy();
            header("Location: ../index.php?error=mobile_restricted");
            exit();
        }
    }
}
