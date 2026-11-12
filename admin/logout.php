<?php
/**
 * admin/logout.php - Keluar dari Sesi Administrator
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_nama']);
header('Location: login.php');
exit;
