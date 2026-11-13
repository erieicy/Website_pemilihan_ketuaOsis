<?php
/**
 * admin/login.php - Halaman Login Administrator (Panitia Pemilihan)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pengaturan = get_pengaturan($pdo);
$error      = '';
$username   = '';
$waitSec    = check_rate_limit('admin_login', 5, 30);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($waitSec > 0) {
        $error = "Terlalu banyak percobaan login gagal. Silakan tunggu {$waitSec} detik.";
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            $stmt = $pdo->prepare('SELECT id, username, password, nama_lengkap FROM admin WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                clear_rate_limit('admin_login');
                clear_rate_limit('admin_reauth');
                clear_rate_limit('install_unlock');
                session_regenerate_id(true);
                $_SESSION['csrf_token']     = bin2hex(random_bytes(32));
                $_SESSION['admin_id']       = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_nama']     = $admin['nama_lengkap'];
                header('Location: index.php');
                exit;
            } else {
                $waitSec = record_failed_attempt('admin_login', 30, 5);
                if ($waitSec > 0) {
                    $error = "Terlalu banyak percobaan login gagal. Silakan tunggu {$waitSec} detik.";
                } else {
                    $error = 'Username atau password salah.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Panitia &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="../assets/css/base.css">
  <link rel="stylesheet" href="../assets/css/voter-login.css">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <span class="auth-tag">Panel Administrator</span>
        <h1 class="auth-title"><?= e($pengaturan['nama_sekolah']) ?></h1>
        <p class="auth-subtitle">Panitia Pemilihan Ketua OSIS <?= e($pengaturan['periode']) ?></p>
      </div>

      <?php if ($waitSec > 0): ?>
        <div class="alert alert-error" id="rl-alert">
          Terlalu banyak percobaan login gagal. Silakan tunggu <strong id="rl-countdown" class="mono" data-wait="<?= (int)$waitSec ?>"><?= (int)$waitSec ?></strong> detik.
        </div>
      <?php elseif ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input
            type="text"
            id="username"
            name="username"
            class="form-control mono"
            maxlength="50"
            value="<?= e($username) ?>"
            <?= $waitSec > 0 ? 'disabled' : 'autofocus' ?>
            required
          >
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input
            type="password"
            id="password"
            name="password"
            class="form-control"
            <?= $waitSec > 0 ? 'disabled' : '' ?>
            required
          >
        </div>
        <button type="submit" id="btn-submit-login" class="btn btn-primary btn-block" <?= $waitSec > 0 ? 'disabled' : '' ?>>
          <?= $waitSec > 0 ? 'Tunggu ' . (int)$waitSec . ' Detik...' : 'Masuk Panel Admin' ?>
        </button>
      </form>

      <div class="auth-footer">
        <a href="../index.php">&larr; Kembali ke Bilik Suara</a>
        <span>Tema: <?= $pengaturan['tema'] === 'dark' ? 'Dark' : 'White' ?></span>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var rlCountdown = document.getElementById('rl-countdown');
      var rlAlert     = document.getElementById('rl-alert');
      var userInp     = document.getElementById('username');
      var passInp     = document.getElementById('password');
      var submitBtn   = document.getElementById('btn-submit-login');

      if (!rlCountdown) return;
      var rem = parseInt(rlCountdown.getAttribute('data-wait') || rlCountdown.textContent, 10) || 0;
      if (rem <= 0) return;

      var rlTimer = setInterval(function () {
        rem -= 1;
        if (rem <= 0) {
          clearInterval(rlTimer);
          if (rlAlert) rlAlert.style.display = 'none';
          if (userInp) {
            userInp.disabled = false;
            userInp.focus();
          }
          if (passInp) passInp.disabled = false;
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Masuk Panel Admin';
          }
        } else {
          rlCountdown.textContent = String(rem);
          if (submitBtn) {
            submitBtn.textContent = 'Tunggu ' + rem + ' Detik...';
          }
        }
      }, 1000);
    })();
  </script>
</body>
</html>
