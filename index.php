<?php
/**
 * index.php - Halaman Depan Bilik Suara (Validasi One-Time Token)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once __DIR__ . '/config/database.php';

// Keluar bilik suara menggunakan metode POST bertoken CSRF (BUG-SEC-03)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'keluar') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        unset($_SESSION['voter_token']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    header('Location: index.php');
    exit;
}

$pengaturan = get_pengaturan($pdo);
$isOpen     = ($pengaturan['status_voting'] === 'buka');

// Jika pemilih sudah memiliki sesi token aktif yang belum dipakai, arahkan ke bilik suara
if ($isOpen && !empty($_SESSION['voter_token'])) {
    $stmtCheck = $pdo->prepare('SELECT sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
    $stmtCheck->execute([$_SESSION['voter_token']]);
    $tokenData = $stmtCheck->fetch();
    if ($tokenData && (int)$tokenData['sudah_dipakai'] === 0) {
        header('Location: bilik_suara.php');
        exit;
    }
    unset($_SESSION['voter_token']);
}

$error = $_SESSION['voter_error'] ?? '';
unset($_SESSION['voter_error']);

$inputToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOpen && ($_POST['action'] ?? '') !== 'keluar') {
    $waitSec = check_rate_limit('voter_token', 8, 60);
    if ($waitSec > 0) {
        $error = "Terlalu banyak percobaan kode token yang gagal. Silakan tunggu {$waitSec} detik.";
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi formulir tidak valid. Silakan coba kembali.';
    } else {
        $inputToken = strtoupper(trim($_POST['kode_token'] ?? ''));
        $inputToken = substr(preg_replace('/[^A-Z0-9]/', '', $inputToken), 0, 6);

        if ($inputToken === '') {
            $error = 'Masukkan kode token terlebih dahulu.';
        } else {
            $stmt = $pdo->prepare('SELECT kode_token, sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
            $stmt->execute([$inputToken]);
            $row = $stmt->fetch();

            if (!$row) {
                // Kondisi A: Token tidak ditemukan
                record_failed_attempt('voter_token', 60);
                $error = 'Kode tidak terdaftar! Periksa kembali ketikan Anda.';
            } elseif ((int)$row['sudah_dipakai'] === 1) {
                // Kondisi B: Token sudah pernah dipakai
                record_failed_attempt('voter_token', 60);
                $error = 'Kode ini sudah pernah digunakan dan tidak berlaku lagi!';
            } else {
                // Kondisi C: Token sah dan belum dipakai (Regenerasi ID Sesi - BUG-SEC-03)
                clear_rate_limit('voter_token');
                session_regenerate_id(true);
                $_SESSION['voter_token'] = $row['kode_token'];
                header('Location: bilik_suara.php');
                exit;
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
  <title>Bilik Suara &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="assets/css/base.css">
  <link rel="stylesheet" href="assets/css/voter-login.css">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <span class="auth-tag">Bilik Suara E-Voting OSIS</span>
        <h1 class="auth-title"><?= e($pengaturan['nama_sekolah']) ?></h1>
        <p class="auth-subtitle">Periode Pemilihan <?= e($pengaturan['periode']) ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if (!$isOpen): ?>
        <div class="closed-box">
          <h3>Pemungutan Suara Ditutup</h3>
          <p class="text-muted" style="font-size: 13px;">
            Bilik suara saat ini sedang ditutup oleh panitia pemilihan.
          </p>
        </div>
      <?php else: ?>
        <form method="post" action="index.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="form-group">
            <label class="form-label" for="kode_token" style="text-align: center;">Masukkan Kode Token Suara</label>
            <input
              type="text"
              id="kode_token"
              name="kode_token"
              class="token-input"
              maxlength="6"
              placeholder="XXXXXX"
              value="<?= e($inputToken) ?>"
              autofocus
              required
            >
            <span class="form-hint" style="text-align: center;">
              Ketik 6 karakter kode pada kertas token dari panitia.
            </span>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Masuk Bilik Suara</button>
        </form>
      <?php endif; ?>

      <div class="auth-footer">
        <span>Jaringan Lokal (LAN)</span>
        <a href="admin/login.php">Panel Panitia</a>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var input = document.getElementById('kode_token');
      if (!input) return;

      input.addEventListener('paste', function (e) {
        var clipboard = e.clipboardData || window.clipboardData;
        if (!clipboard) return;
        e.preventDefault();
        var pasted = (clipboard.getData('text') || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
        this.value = pasted;
        this.setSelectionRange(pasted.length, pasted.length);
      });

      input.addEventListener('input', function () {
        var rawPos = this.selectionStart || 0;
        var cleanBefore = this.value.slice(0, rawPos).toUpperCase().replace(/[^A-Z0-9]/g, '');
        var cleanFull   = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
        var newPos      = Math.min(cleanFull.length, cleanBefore.length);
        this.value = cleanFull;
        this.setSelectionRange(newPos, newPos);
      });
    })();
  </script>
</body>
</html>
