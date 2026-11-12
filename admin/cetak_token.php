<?php
/**
 * admin/cetak_token.php - Halaman Khusus Cetak Kertas Token Siap Gunting (FR-09)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$pengaturan = get_pengaturan($pdo);

$status = $_GET['status'] ?? '0';
if ($status === 'all') {
    $stmt = $pdo->query('SELECT kode_token FROM tokens ORDER BY id ASC');
} else {
    $stmt = $pdo->query('SELECT kode_token FROM tokens WHERE sudah_dipakai = 0 ORDER BY id ASC');
}
$tokens = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cetak Token Suara &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="../assets/css/print-token.css">
</head>
<body>
  <div class="print-toolbar">
    <div>
      <strong>Lembar Cetak Token E-Voting OSIS</strong> &mdash; <?= e($pengaturan['nama_sekolah']) ?>
      <span style="font-size: 12px; color: #555; margin-left: 8px;">(Total: <?= count($tokens) ?> token)</span>
    </div>
    <div style="display: flex; gap: 8px;">
      <a href="token.php" class="print-btn print-btn-outline">&larr; Kembali</a>
      <button type="button" class="print-btn" onclick="window.print()">Cetak Sekarang (Ctrl+P)</button>
    </div>
  </div>

  <div class="print-sheet">
    <?php if (empty($tokens)): ?>
      <p style="text-align: center; padding: 32px; color: #666;">
        Tidak ada token yang belum dipakai untuk dicetak. Silakan generate token terlebih dahulu di halaman Manajemen Token.
      </p>
    <?php else: ?>
      <div class="print-grid">
        <?php foreach ($tokens as $t): ?>
          <div class="token-ticket">
            <div class="ticket-school"><?= e($pengaturan['nama_sekolah']) ?></div>
            <div class="ticket-title">TOKEN PEMILIHAN OSIS <?= e($pengaturan['periode']) ?></div>
            <div class="ticket-code"><?= e($t['kode_token']) ?></div>
            <div class="ticket-note">Sekali Pakai &bull; Rahasia</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
