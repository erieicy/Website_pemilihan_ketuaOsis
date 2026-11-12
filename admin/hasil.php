<?php
/**
 * admin/hasil.php - Grafik Perolehan Suara & Mode Kunci Hasil (FR-11)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$message = $_SESSION['flash_hasil_msg'] ?? '';
unset($_SESSION['flash_hasil_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_lock') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $newState = (int)($_POST['tampilkan_hasil'] ?? 0) === 1 ? 1 : 0;
        $stmtUpd  = $pdo->prepare('UPDATE pengaturan SET tampilkan_hasil = ? WHERE id = 1');
        $stmtUpd->execute([$newState]);
        $_SESSION['flash_hasil_msg'] = $newState === 1
            ? 'Hasil perhitungan suara dibuka dan ditampilkan di layar.'
            : 'Hasil perhitungan suara berhasil dikunci (disembunyikan).';
        header('Location: hasil.php');
        exit;
    }
}

$pengaturan = get_pengaturan($pdo);
$isUnlocked = ((int)$pengaturan['tampilkan_hasil'] === 1);

$stmtStats = $pdo->query("
    SELECT
      COUNT(*) AS total_token,
      COALESCE(SUM(CASE WHEN sudah_dipakai = 1 THEN 1 ELSE 0 END), 0) AS token_terpakai,
      COALESCE(SUM(CASE WHEN sudah_dipakai = 0 THEN 1 ELSE 0 END), 0) AS token_sisa
    FROM tokens
");
$stats = $stmtStats->fetch();
$totalToken    = (int)($stats['total_token'] ?? 0);
$tokenTerpakai = (int)($stats['token_terpakai'] ?? 0);
$tokenSisa     = (int)($stats['token_sisa'] ?? 0);

$stmtKandidat = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtKandidat->fetchAll();

$totalSuaraSah = 0;
foreach ($kandidatList as $k) {
    $totalSuaraSah += (int)$k['jumlah_suara'];
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hasil Perhitungan Suara &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="../assets/css/base.css">
  <link rel="stylesheet" href="../assets/css/admin-layout.css">
  <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
</head>
<body>
  <div class="admin-shell">
    <aside class="admin-sidebar">
      <div>
        <div class="sidebar-brand">
          <h2>E-Voting OSIS</h2>
          <span><?= e($pengaturan['nama_sekolah']) ?></span>
        </div>
        <nav class="sidebar-nav">
          <a href="index.php" class="nav-link">Dashboard</a>
          <a href="kandidat.php" class="nav-link">Data Kandidat</a>
          <a href="token.php" class="nav-link">Manajemen Token</a>
          <a href="hasil.php" class="nav-link active">Hasil Perhitungan</a>
          <a href="pengaturan.php" class="nav-link">Pengaturan</a>
        </nav>
      </div>
      <div class="sidebar-footer">
        <div class="sidebar-user">Login: <strong><?= e($_SESSION['admin_nama'] ?? 'Admin') ?></strong></div>
        <div class="sidebar-actions">
          <a href="../index.php" class="btn btn-sm">Bilik Suara</a>
          <a href="logout.php" class="btn btn-sm btn-danger">Keluar</a>
        </div>
      </div>
    </aside>

    <main class="admin-main">
      <div class="page-header">
        <div>
          <h1>Hasil Perhitungan Suara Pemilihan Ketua OSIS</h1>
          <p><?= e($pengaturan['nama_sekolah']) ?> &mdash; Periode <?= e($pengaturan['periode']) ?></p>
        </div>

        <div class="filter-bar no-print">
          <!-- Tombol Toggle Kunci Hasil (FR-11) -->
          <form method="post" action="hasil.php" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_lock">
            <input type="hidden" name="tampilkan_hasil" value="<?= $isUnlocked ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $isUnlocked ? '' : 'btn-primary' ?>">
              <?= $isUnlocked ? 'Kunci / Sembunyikan Hasil' : 'Buka Hasil Perhitungan Suara' ?>
            </button>
          </form>

          <?php if ($isUnlocked): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()">Cetak Berita Acara / Hasil</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success no-print"><?= e($message) ?></div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Total Suara Sah Masuk</div>
          <div class="stat-value"><?= number_format($totalSuaraSah, 0, ',', '.') ?></div>
          <div class="stat-meta">Akumulasi seluruh kandidat</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Token Terpakai</div>
          <div class="stat-value"><?= number_format($tokenTerpakai, 0, ',', '.') ?></div>
          <div class="stat-meta">Dari total <?= number_format($totalToken, 0, ',', '.') ?> token</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Token Belum Dipakai</div>
          <div class="stat-value"><?= number_format($tokenSisa, 0, ',', '.') ?></div>
          <div class="stat-meta">Sisa kertas token</div>
        </div>
      </div>

      <?php if (!$isUnlocked): ?>
        <!-- Tampilan Terkunci (FR-11) -->
        <div class="panel">
          <div class="panel-body">
            <div class="locked-panel">
              <h3>Grafik Perolehan Suara Sedang Dikunci</h3>
              <p>
                Fitur <strong>Lock Quick Count</strong> aktif untuk mencegah kebocoran tren suara selama proses pemilihan berlangsung. Klik tombol di bawah apabila pemungutan suara telah selesai dan perhitungan resmi dimulai.
              </p>
              <form method="post" action="hasil.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_lock">
                <input type="hidden" name="tampilkan_hasil" value="1">
                <button type="submit" class="btn btn-primary">Buka Grafik Perhitungan Sekarang</button>
              </form>
            </div>
          </div>
        </div>
      <?php else: ?>
        <!-- Grafik Perolehan Suara -->
        <div class="panel">
          <div class="panel-header">
            <span>Grafik Perolehan Suara Kandidat</span>
            <span class="mono" style="font-size: 12px; font-weight: 400;">Dicetak/Diakses: <?= date('d/m/Y H:i') ?> WIB</span>
          </div>
          <div class="panel-body">
            <?php if (empty($kandidatList)): ?>
              <p class="text-muted">Belum ada kandidat terdaftar.</p>
            <?php else: ?>
              <div class="chart-list">
                <?php foreach ($kandidatList as $k): ?>
                  <?php
                    $suara   = (int)$k['jumlah_suara'];
                    $persen  = $totalSuaraSah > 0 ? round(($suara / $totalSuaraSah) * 100, 2) : 0;
                    $foto    = !empty($k['nama_file_foto']) ? basename($k['nama_file_foto']) : 'default-candidate.png';
                    $fotoUrl = '../assets/uploads/' . $foto;
                    if (!file_exists(dirname(__DIR__) . '/assets/uploads/' . $foto)) {
                        $fotoUrl = '../assets/uploads/default-candidate.png';
                    }
                  ?>
                  <div class="chart-item">
                    <div class="chart-head">
                      <div class="chart-candidate">
                        <img src="<?= e($fotoUrl) ?>" alt="Foto Kandidat">
                        <div>
                          <div class="chart-title">
                            No. Urut <?= str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT) ?> &mdash; <?= e($k['nama_ketua']) ?>
                          </div>
                          <div class="chart-subtitle">
                            <?= !empty($k['nama_wakil']) ? 'Wakil: ' . e($k['nama_wakil']) : 'Calon Ketua OSIS' ?>
                          </div>
                        </div>
                      </div>
                      <div class="chart-numbers">
                        <div class="chart-votes"><?= number_format($suara, 0, ',', '.') ?> Suara</div>
                        <div class="chart-percent"><?= e((string)$persen) ?>%</div>
                      </div>
                    </div>
                    <div class="chart-track">
                      <div class="chart-fill" style="width: <?= e((string)$persen) ?>%;"></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tabel Rekapitulasi Suara -->
        <div class="panel">
          <div class="panel-header">
            <span>Tabel Rekapitulasi Suara Sah</span>
          </div>
          <div class="panel-body" style="padding: 0;">
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th style="width: 90px;">No. Urut</th>
                    <th>Nama Calon Ketua</th>
                    <th>Nama Calon Wakil</th>
                    <th style="text-align: right;">Jumlah Suara</th>
                    <th style="text-align: right;">Persentase</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($kandidatList as $k): ?>
                    <?php
                      $suara  = (int)$k['jumlah_suara'];
                      $persen = $totalSuaraSah > 0 ? round(($suara / $totalSuaraSah) * 100, 2) : 0;
                    ?>
                    <tr>
                      <td class="mono" style="font-weight: 700;"><?= str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT) ?></td>
                      <td style="font-weight: 600;"><?= e($k['nama_ketua']) ?></td>
                      <td><?= !empty($k['nama_wakil']) ? e($k['nama_wakil']) : '<span class="text-muted">&mdash;</span>' ?></td>
                      <td class="mono" style="text-align: right; font-weight: 700;"><?= number_format($suara, 0, ',', '.') ?></td>
                      <td class="mono" style="text-align: right;"><?= e((string)$persen) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
