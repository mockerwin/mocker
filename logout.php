<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Ensure important headers are set
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Log the logout activity if user is logged in
if (Auth::isLoggedIn()) {
    $userId = Auth::user()['id'];
    logActivity($userId, 'logout', 'User logged out');
}

// Logout the user
Auth::logout();

// Redirect to login page with logged out message
header('Location: ' . SITE_URL . '/login.php?logged_out=1');
exit;
?>
