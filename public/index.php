<?php
require_once '../app/config/config.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php")
    exit;
}

header("Location: ../api/auth.php?action=login")
