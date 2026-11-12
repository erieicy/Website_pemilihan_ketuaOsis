<?php
/**
 * index.php - Halaman Depan Bilik Suara (Validasi One-Time Token)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once __DIR__ . '/config/database.php';

if (isset($_GET['keluar'])) {
    unset($_SESSION['voter_token']);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOpen) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi formulir tidak valid. Silakan coba kembali.';
    } else {
        $inputToken = strtoupper(trim($_POST['kode_token'] ?? ''));
        $inputToken = preg_replace('/[^A-Z0-9]/', '', $inputToken);

        if ($inputToken === '') {
            $error = 'Masukkan kode token terlebih dahulu.';
        } else {
            $stmt = $pdo->prepare('SELECT kode_token, sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
            $stmt->execute([$inputToken]);
            $row = $stmt->fetch();

            if (!$row) {
                // Kondisi A: Token tidak ditemukan
                $error = 'Kode tidak terdaftar! Periksa kembali ketikan Anda.';
            } elseif ((int)$row['sudah_dipakai'] === 1) {
                // Kondisi B: Token sudah pernah dipakai
                $error = 'Kode ini sudah pernah digunakan dan tidak berlaku lagi!';
            } else {
                // Kondisi C: Token sah dan belum dipakai
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
              maxlength="10"
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
      if (input) {
        input.addEventListener('input', function () {
          var pos = this.selectionStart;
          this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
          this.setSelectionRange(pos, pos);
        });
      }
    })();
  </script>
</body>
</html>
