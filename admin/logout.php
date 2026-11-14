<?php
/**
 * admin/logout.php - Keluar dari Sesi Administrator
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_nama'], $_SESSION['admin_pass_hash']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
} else {
    header('Location: login.php');
}
exit;
