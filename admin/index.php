<?php
/**
 * admin/index.php - Dashboard & Statistik Real-Time Panitia
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$pengaturan = get_pengaturan($pdo);

// Statistik Token
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
$partisipasi   = $totalToken > 0 ? round(($tokenTerpakai / $totalToken) * 100, 1) : 0;

// Daftar Kandidat & Total Suara
$stmtKandidat = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtKandidat->fetchAll();

$totalSuaraKandidat = 0;
foreach ($kandidatList as $k) {
    $totalSuaraKandidat += (int)$k['jumlah_suara'];
}

$serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Admin &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
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
          <a href="index.php" class="nav-link active">Dashboard</a>
          <a href="kandidat.php" class="nav-link">Data Kandidat</a>
          <a href="token.php" class="nav-link">Manajemen Token</a>
          <a href="hasil.php" class="nav-link">Hasil Perhitungan</a>
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
          <h1>Dashboard Statistik Pemilihan</h1>
          <p>Periode <?= e($pengaturan['periode']) ?> &mdash; Pantauan suara dan distribusi token jaringan lokal</p>
        </div>
        <div class="filter-bar">
          <a href="index.php" class="btn btn-sm">Muat Ulang Data</a>
          <a href="token.php" class="btn btn-sm btn-primary">+ Buat Token</a>
        </div>
      </div>

      <!-- Kartu Statistik (FR-07) -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Total Token Dibuat</div>
          <div class="stat-value"><?= number_format($totalToken, 0, ',', '.') ?></div>
          <div class="stat-meta">Kertas token terdaftar</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Suara Masuk (Terpakai)</div>
          <div class="stat-value"><?= number_format($tokenTerpakai, 0, ',', '.') ?></div>
          <div class="stat-meta">Partisipasi: <?= e((string)$partisipasi) ?>%</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sisa Token Belum Dipakai</div>
          <div class="stat-value"><?= number_format($tokenSisa, 0, ',', '.') ?></div>
          <div class="stat-meta">Masih sah untuk memilih</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Status Bilik Suara</div>
          <div class="stat-value" style="font-size: 22px;">
            <?php if ($pengaturan['status_voting'] === 'buka'): ?>
              <span class="badge badge-success" style="font-size: 14px; padding: 4px 10px;">DIBUKA</span>
            <?php else: ?>
              <span class="badge badge-danger" style="font-size: 14px; padding: 4px 10px;">DITUTUP</span>
            <?php endif; ?>
          </div>
          <div class="stat-meta">Jumlah Kandidat: <?= count($kandidatList) ?> Paslon</div>
        </div>
      </div>

      <!-- Panel Ringkasan Perolehan Suara -->
      <div class="panel">
        <div class="panel-header">
          <span>Grafik Perolehan Suara Sementara</span>
          <a href="hasil.php" class="btn btn-sm">Kelola di Halaman Hasil &rarr;</a>
        </div>
        <div class="panel-body">
          <?php if ((int)$pengaturan['tampilkan_hasil'] === 0): ?>
            <div class="locked-panel">
              <h3>Mode Kunci Hasil (Lock Quick Count) Aktif</h3>
              <p>
                Grafik perolehan suara disembunyikan selama pemungutan suara berlangsung untuk menjaga netralitas di ruang panitia.
              </p>
              <a href="hasil.php" class="btn btn-primary btn-sm">Buka Halaman Hasil Suara</a>
            </div>
          <?php elseif (empty($kandidatList)): ?>
            <p class="text-muted">Belum ada kandidat yang ditambahkan. <a href="kandidat.php">Tambah kandidat sekarang</a>.</p>
          <?php else: ?>
            <div class="chart-list">
              <?php foreach ($kandidatList as $k): ?>
                <?php
                  $suara   = (int)$k['jumlah_suara'];
                  $persen  = $totalSuaraKandidat > 0 ? round(($suara / $totalSuaraKandidat) * 100, 1) : 0;
                  $foto    = !empty($k['nama_file_foto']) ? basename($k['nama_file_foto']) : 'default-candidate.png';
                  $fotoUrl = '../assets/uploads/' . $foto;
                  if (!file_exists(dirname(__DIR__) . '/assets/uploads/' . $foto)) {
                      $fotoUrl = '../assets/uploads/default-candidate.png';
                  }
                ?>
                <div class="chart-item">
                  <div class="chart-head">
                    <div class="chart-candidate">
                      <img src="<?= e($fotoUrl) ?>" alt="Kandidat">
                      <div>
                        <div class="chart-title">No. <?= str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT) ?> &mdash; <?= e($k['nama_ketua']) ?></div>
                        <div class="chart-subtitle"><?= !empty($k['nama_wakil']) ? 'Wakil: ' . e($k['nama_wakil']) : 'Calon Ketua OSIS' ?></div>
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

      <!-- Informasi Akses Jaringan Lokal (LAN) -->
      <div class="panel">
        <div class="panel-header">
          <span>Informasi Sistem &amp; Akses Jaringan Lokal (LAN)</span>
          <a href="pengaturan.php" class="btn btn-sm">Ubah Pengaturan / Tema</a>
        </div>
        <div class="panel-body">
          <div class="table-wrap">
            <table class="table">
              <tbody>
                <tr>
                  <th style="width: 240px;">Alamat IP Server Lokal</th>
                  <td class="mono"><?= e($serverIp) ?></td>
                </tr>
                <tr>
                  <th>Tema Antarmuka Aktif</th>
                  <td>
                    <span class="badge"><?= $pengaturan['tema'] === 'dark' ? 'Dark (Gelap)' : 'White (Terang)' ?></span>
                    <span class="text-muted" style="font-size: 12px; margin-left: 8px;">Dapat diubah di menu <a href="pengaturan.php">Pengaturan</a></span>
                  </td>
                </tr>
                <tr>
                  <th>Kerahasiaan Suara</th>
                  <td>Aktif (Tanpa relasi token ke kandidat / LUBER JURDIL)</td>
                </tr>
                <tr>
                  <th>Wizard Instalasi (<code>install.php</code>)</th>
                  <td>
                    <span class="badge">Terkunci (<code>installed.lock</code>)</span>
                    <a href="pengaturan.php#panel-installer" class="btn btn-sm" style="margin-left: 8px;">Buka Kunci / Instal Ulang</a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
