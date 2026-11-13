<?php
/**
 * admin/logout.php - Keluar dari Sesi Administrator
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';

unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_nama']);
session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

header('Location: login.php');
exit;
