<?php
require_once '../app/config/config.php';

# Start browser sesh
session_start();

# If user id has a session send to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php")
    exit;
}

header("Location: ../api/auth.php?action=login")
