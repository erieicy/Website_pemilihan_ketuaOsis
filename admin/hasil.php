<?php
/**
 * admin/hasil.php - Grafik Perolehan Suara & Mode Kunci Hasil (FR-11)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$message = $_SESSION['flash_hasil_msg'] ?? '';
$error   = $_SESSION['flash_hasil_err'] ?? '';
unset($_SESSION['flash_hasil_msg'], $_SESSION['flash_hasil_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_lock') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_hasil_err'] = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $newState = (int)($_POST['tampilkan_hasil'] ?? 0) === 1 ? 1 : 0;
        $stmtUpd  = $pdo->prepare('UPDATE pengaturan SET tampilkan_hasil = ? WHERE id = 1');
        $stmtUpd->execute([$newState]);
        $_SESSION['flash_hasil_msg'] = $newState === 1
            ? 'Hasil perhitungan suara dibuka dan ditampilkan di layar.'
            : 'Hasil perhitungan suara berhasil dikunci (disembunyikan).';
    }
    header('Location: hasil.php');
    exit;
}

$pengaturan = get_pengaturan($pdo);
$isUnlocked = ((int)$pengaturan['tampilkan_hasil'] === 1);

$tokenStats    = get_token_stats($pdo);
$totalToken    = $tokenStats['total'];
$tokenTerpakai = $tokenStats['terpakai'];
$tokenSisa     = $tokenStats['sisa'];

$stmtKandidat = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtKandidat->fetchAll();

$totalSuaraSah = 0;
$sigParts      = [$isUnlocked ? '1' : '0'];
foreach ($kandidatList as $k) {
    $totalSuaraSah += (int)$k['jumlah_suara'];
    $sigParts[]     = $k['id'] . ':' . $k['no_urut'] . ':' . $k['nama_ketua'] . ':' . ($k['nama_wakil'] ?? '') . ':' . ($k['nama_file_foto'] ?? '');
}
$structSig  = md5(implode('|', $sigParts));
$activeCsrf = csrf_token();

/**
 * Merender tombol kontrol lock/unlock & cetak di header Halaman Hasil
 */
function render_hasil_header_controls(bool $isUnlocked, string $csrfToken): string {
    ob_start(); ?>
    <form method="post" action="hasil.php" style="display: inline;">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="action" value="toggle_lock">
      <input type="hidden" name="tampilkan_hasil" value="<?= $isUnlocked ? '0' : '1' ?>">
      <button type="submit" class="btn <?= $isUnlocked ? '' : 'btn-primary' ?>">
        <?= $isUnlocked ? 'Kunci / Sembunyikan Hasil' : 'Buka Hasil Perhitungan Suara' ?>
      </button>
    </form>
    <?php if ($isUnlocked): ?>
      <button type="button" class="btn btn-primary" onclick="window.print()">Cetak Berita Acara / Hasil</button>
    <?php endif;
    return (string)ob_get_clean();
}

/**
 * Merender isi panel grafik & tabel rekapitulasi suara sah
 */
function render_hasil_results_section(bool $isUnlocked, array $kandidatList, int $totalSuaraSah, string $csrfToken): string {
    ob_start();
    if (!$isUnlocked): ?>
      <!-- Tampilan Terkunci (FR-11) -->
      <div class="panel">
        <div class="panel-body">
          <div class="locked-panel">
            <h3>Grafik Perolehan Suara Sedang Dikunci</h3>
            <p>
              Fitur <strong>Lock Quick Count</strong> aktif untuk mencegah kebocoran tren suara selama proses pemilihan berlangsung. Klik tombol di bawah apabila pemungutan suara telah selesai dan perhitungan resmi dimulai.
            </p>
            <form method="post" action="hasil.php">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
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
          <span id="hasil-updated-at" class="mono" style="font-size: 12px; font-weight: 400;">Dicetak/Diakses: <?= date('d/m/Y H:i:s') ?> WIB</span>
        </div>
        <div class="panel-body" id="hasil-chart-body">
          <?php if (empty($kandidatList)): ?>
            <p class="text-muted">Belum ada kandidat terdaftar.</p>
          <?php else: ?>
            <?= render_candidate_chart_items($kandidatList, $totalSuaraSah, 'No. Urut ') ?>
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
              <tbody id="rekap-table-body">
                <?php if (empty($kandidatList)): ?>
                  <tr>
                    <td colspan="5" class="text-muted" style="text-align: center; padding: 20px;">Belum ada kandidat terdaftar.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($kandidatList as $k): ?>
                    <?php
                      $suara  = (int)$k['jumlah_suara'];
                      $persen = $totalSuaraSah > 0 ? round(($suara / $totalSuaraSah) * 100, 1) : 0;
                    ?>
                    <tr data-kandidat-id="<?= (int)$k['id'] ?>">
                      <td class="mono" style="font-weight: 700;"><?= str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT) ?></td>
                      <td style="font-weight: 600;"><?= e($k['nama_ketua']) ?></td>
                      <td><?= !empty($k['nama_wakil']) ? e($k['nama_wakil']) : '<span class="text-muted">&mdash;</span>' ?></td>
                      <td class="mono rekap-suara" style="text-align: right; font-weight: 700;"><?= number_format($suara, 0, ',', '.') ?></td>
                      <td class="mono rekap-persen" style="text-align: right;"><?= e((string)$persen) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif;
    return (string)ob_get_clean();
}

// Endpoint AJAX Real-Time untuk pembaruan otomatis tanpa refresh
if (isset($_GET['ajax'])) {
    session_write_close();
    $clientSig = (string)($_GET['sig'] ?? '');
    $structChanged = ($clientSig !== $structSig);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'total_suara_sah_fmt' => number_format($totalSuaraSah, 0, ',', '.'),
        'token_terpakai_fmt'  => number_format($tokenTerpakai, 0, ',', '.'),
        'token_meta_fmt'      => 'Dari total ' . number_format($totalToken, 0, ',', '.') . ' token',
        'token_sisa_fmt'      => number_format($tokenSisa, 0, ',', '.'),
        'updated_at_fmt'      => 'Dicetak/Diakses: ' . date('d/m/Y H:i:s') . ' WIB',
        'tema'                => $pengaturan['tema'],
        'struct_sig'          => $structSig,
        'header_html'         => $structChanged ? render_hasil_header_controls($isUnlocked, $activeCsrf) : null,
        'section_html'        => $structChanged ? render_hasil_results_section($isUnlocked, $kandidatList, $totalSuaraSah, $activeCsrf) : null,
        'kandidat'            => build_candidate_ajax_payload($kandidatList, $totalSuaraSah),
    ]);
    exit;
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
          <!-- Tombol Toggle Kunci Hasil & Cetak (FR-11) -->
          <div id="hasil-header-controls" class="filter-bar">
            <?= render_hasil_header_controls($isUnlocked, $activeCsrf) ?>
          </div>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success no-print" data-broadcast-update="1"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error no-print"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Total Suara Sah Masuk</div>
          <div class="stat-value" id="hasil-total-suara"><?= number_format($totalSuaraSah, 0, ',', '.') ?></div>
          <div class="stat-meta">Akumulasi seluruh kandidat</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Token Terpakai</div>
          <div class="stat-value" id="hasil-token-terpakai"><?= number_format($tokenTerpakai, 0, ',', '.') ?></div>
          <div class="stat-meta" id="hasil-token-meta">Dari total <?= number_format($totalToken, 0, ',', '.') ?> token</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Token Belum Dipakai</div>
          <div class="stat-value" id="hasil-token-sisa"><?= number_format($tokenSisa, 0, ',', '.') ?></div>
          <div class="stat-meta">Sisa kertas token</div>
        </div>
      </div>

      <div id="hasil-results-section" data-struct-sig="<?= e($structSig) ?>">
        <?= render_hasil_results_section($isUnlocked, $kandidatList, $totalSuaraSah, $activeCsrf) ?>
      </div>
    </main>
  </div>
  <script src="../assets/js/admin.js"></script>
  <script>
    (function () {
      var elTotalSuara   = document.getElementById('hasil-total-suara');
      var elTokenDipakai = document.getElementById('hasil-token-terpakai');
      var elTokenMeta    = document.getElementById('hasil-token-meta');
      var elTokenSisa    = document.getElementById('hasil-token-sisa');
      var headerControls = document.getElementById('hasil-header-controls');
      var resultsSection = document.getElementById('hasil-results-section');

      if (typeof window.evotingStartLivePolling === 'function' && resultsSection) {
        window.evotingStartLivePolling({
          getUrl: function () {
            return 'hasil.php?ajax=1&sig=' + encodeURIComponent(resultsSection.dataset.structSig || '');
          },
          intervalMs: 1500,
          onData: function (data) {
            if (elTotalSuara) elTotalSuara.textContent = data.total_suara_sah_fmt;
            if (elTokenDipakai) elTokenDipakai.textContent = data.token_terpakai_fmt;
            if (elTokenMeta) elTokenMeta.textContent = data.token_meta_fmt;
            if (elTokenSisa) elTokenSisa.textContent = data.token_sisa_fmt;

            if (data.tema) {
              document.documentElement.setAttribute('data-theme', data.tema);
            }

            if (data.section_html !== null && data.struct_sig !== resultsSection.dataset.structSig) {
              resultsSection.innerHTML = data.section_html;
              resultsSection.dataset.structSig = data.struct_sig;
              if (headerControls && data.header_html !== null) {
                headerControls.innerHTML = data.header_html;
              }
            } else {
              var updatedAt = document.getElementById('hasil-updated-at');
              if (updatedAt && data.updated_at_fmt) {
                updatedAt.textContent = data.updated_at_fmt;
              }
              var chartBody = document.getElementById('hasil-chart-body');
              if (chartBody) {
                window.evotingUpdateChartMetrics(chartBody, data.kandidat);
              }
              var rekapBody = document.getElementById('rekap-table-body');
              if (rekapBody && Array.isArray(data.kandidat)) {
                data.kandidat.forEach(function (item) {
                  var row = rekapBody.querySelector('tr[data-kandidat-id="' + item.id + '"]');
                  if (!row) return;
                  var tdSuara = row.querySelector('.rekap-suara');
                  var tdPct   = row.querySelector('.rekap-persen');
                  if (tdSuara) tdSuara.textContent = item.suara_fmt;
                  if (tdPct) tdPct.textContent = item.persen_fmt;
                });
              }
            }
          }
        });
      }
    })();
  </script>
</body>
</html>
