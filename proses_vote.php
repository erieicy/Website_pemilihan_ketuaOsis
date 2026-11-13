<?php
/**
 * proses_vote.php - Eksekusi Transaksi Atomik Pemungutan Suara & Penghangusan Token
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$pengaturan = get_pengaturan($pdo);
if ($pengaturan['status_voting'] !== 'buka') {
    unset($_SESSION['voter_token']);
    $_SESSION['voter_error'] = 'Pemungutan suara telah ditutup.';
    header('Location: index.php');
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['voter_error'] = 'Permintaan tidak sah. Silakan ulangi proses pemilihan.';
    header('Location: bilik_suara.php');
    exit;
}

$kodeToken  = $_SESSION['voter_token'] ?? '';
$idKandidat = (int)($_POST['id_kandidat'] ?? 0);

// Jika sesi token sudah berakhir saat tombol ditekan, beri penjelasan ke pemilih (BUG-FUN-10.4)
if ($kodeToken === '') {
    $_SESSION['voter_error'] = 'Sesi pemilihan Anda telah berakhir. Silakan masukkan kembali kode token Anda.';
    header('Location: index.php');
    exit;
}

if ($idKandidat <= 0) {
    $_SESSION['voter_error'] = 'Silakan pilih salah satu kandidat terlebih dahulu.';
    header('Location: bilik_suara.php');
    exit;
}

$maxTxAttempts = 5;
for ($txAttempt = 1; $txAttempt <= $maxTxAttempts; $txAttempt++) {
    try {
        // Kurangi gap lock pada index sekunder (idx_sudah_dipakai) saat banyak komputer submit serentak
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();

        // 1. Update atomik pada tabel tokens (Race Condition Protection)
        $stmtToken = $pdo->prepare('
            UPDATE tokens
            SET sudah_dipakai = 1, used_at = NOW()
            WHERE kode_token = ? AND sudah_dipakai = 0
        ');
        $stmtToken->execute([$kodeToken]);

        if ($stmtToken->rowCount() !== 1) {
            $pdo->rollBack();
            unset($_SESSION['voter_token']);
            $_SESSION['voter_error'] = 'Kode ini sudah pernah digunakan dan tidak berlaku lagi!';
            header('Location: index.php');
            exit;
        }

        // 2. Tambahkan jumlah suara kandidat tanpa menyimpan relasi ke token (Voter Anonymity)
        $stmtVote = $pdo->prepare('
            UPDATE kandidat
            SET jumlah_suara = jumlah_suara + 1
            WHERE id = ?
        ');
        $stmtVote->execute([$idKandidat]);

        if ($stmtVote->rowCount() !== 1) {
            $pdo->rollBack();
            $_SESSION['voter_error'] = 'Kandidat tidak ditemukan. Silakan pilih kembali.';
            header('Location: bilik_suara.php');
            exit;
        }

        $pdo->commit();

        // Hentikan sesi token pemilih & regenerasi sesi setelah suara tercatat
        unset($_SESSION['voter_token']);
        session_regenerate_id(true);
        break;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $isLockErr = ($e instanceof PDOException) && (
            (string)$e->getCode() === '40001' ||
            in_array((int)($e->errorInfo[1] ?? 0), [1213, 1205], true)
        );
        if ($isLockErr && $txAttempt < $maxTxAttempts) {
            usleep(random_int(15000, 65000));
            continue;
        }

        error_log('Vote transaction error: ' . $e->getMessage());
        $_SESSION['voter_error'] = 'Terjadi kesalahan saat menyimpan suara. Silakan coba lagi.';
        header('Location: bilik_suara.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="3;url=index.php">
  <title>Suara Tercatat &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="assets/css/base.css">
  <link rel="stylesheet" href="assets/css/voter-login.css">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card" style="text-align: center;">
      <div class="alert alert-success" style="margin-bottom: 20px;">
        <strong>Terima Kasih, Suara Anda Telah Tercatat.</strong>
      </div>
      <h1 class="auth-title" style="margin-bottom: 8px;">Pemilihan Selesai</h1>
      <p class="text-muted" style="font-size: 14px; margin-bottom: 20px;">
        Kode token Anda telah dihanguskan secara otomatis dan kerahasiaan pilihan Anda terjamin.
      </p>
      <p style="font-size: 13px; margin-bottom: 20px;">
        Kembali ke halaman input token dalam <strong id="redirect-countdown" class="mono" data-target="index.php">3</strong> detik...
      </p>
      <a href="index.php" class="btn btn-primary btn-block">Selesai Sekarang</a>
    </div>
  </div>
  <script src="assets/js/bilik-suara.js"></script>
</body>
</html>
