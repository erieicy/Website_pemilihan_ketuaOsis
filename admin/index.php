<?php
/**
 * admin/index.php - Dashboard & Statistik Real-Time Panitia
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$pengaturan = get_pengaturan($pdo);

// Statistik Token
$tokenStats    = get_token_stats($pdo);
$totalToken    = $tokenStats['total'];
$tokenTerpakai = $tokenStats['terpakai'];
$tokenSisa     = $tokenStats['sisa'];
$partisipasi   = $tokenStats['partisipasi'];

// Daftar Kandidat & Total Suara
$stmtKandidat = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtKandidat->fetchAll();

$totalSuaraKandidat = 0;
$sigParts           = [(int)$pengaturan['tampilkan_hasil']];

foreach ($kandidatList as $k) {
    $totalSuaraKandidat += (int)$k['jumlah_suara'];
    $sigParts[] = $k['id'] . ':' . $k['no_urut'] . ':' . $k['nama_ketua'] . ':' . ($k['nama_wakil'] ?? '') . ':' . ($k['nama_file_foto'] ?? '');
}
$chartSig = md5(implode('|', $sigParts));

/**
 * Merender isi panel grafik perolehan suara pada Dashboard
 */
function render_dashboard_chart(array $pengaturan, array $kandidatList, int $totalSuaraKandidat): string {
    ob_start();
    if ((int)$pengaturan['tampilkan_hasil'] === 0): ?>
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
      <?= render_candidate_chart_items($kandidatList, $totalSuaraKandidat, 'No. ') ?>
    <?php endif;
    return (string)ob_get_clean();
}

// Endpoint AJAX Real-Time untuk pembaruan otomatis tanpa refresh
if (isset($_GET['ajax'])) {
    session_write_close();
    $clientSig = (string)($_GET['sig'] ?? '');

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'total_token_fmt'     => number_format($totalToken, 0, ',', '.'),
        'token_terpakai_fmt'  => number_format($tokenTerpakai, 0, ',', '.'),
        'partisipasi_fmt'     => 'Partisipasi: ' . $partisipasi . '%',
        'token_sisa_fmt'      => number_format($tokenSisa, 0, ',', '.'),
        'status_voting'       => $pengaturan['status_voting'],
        'tema'                => $pengaturan['tema'],
        'jumlah_kandidat_fmt' => 'Jumlah Kandidat: ' . count($kandidatList) . ' Paslon',
        'chart_sig'           => $chartSig,
        'chart_html'          => ($clientSig !== $chartSig) ? render_dashboard_chart($pengaturan, $kandidatList, $totalSuaraKandidat) : null,
        'kandidat'            => build_candidate_ajax_payload($kandidatList, $totalSuaraKandidat),
    ]);
    exit;
}

/**
 * Mendeteksi daftar alamat IPv4 LAN / Wi-Fi pada komputer server
 */
function detect_lan_ips(): array {
    $ips = [];

    // 1. Deteksi IP interface jaringan utama yang aktif melalui tabel routing OS (UDP connectionless)
    if (function_exists('stream_socket_client') && function_exists('stream_socket_get_name')) {
        $sock = @stream_socket_client('udp://10.254.254.254:1', $errno, $errstr, 1);
        if ($sock) {
            $name = @stream_socket_get_name($sock, false);
            fclose($sock);
            if ($name && strpos($name, ':') !== false) {
                $ip = explode(':', $name)[0];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                    $ips[] = $ip;
                }
            }
        }
    }

    // 2. Ambil seluruh IPv4 dari adapter lokal
    $hostIps = @gethostbynamel(gethostname());
    if (is_array($hostIps)) {
        foreach ($hostIps as $ip) {
            if (
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                $ip !== '127.0.0.1' &&
                strpos($ip, '169.254.') !== 0 &&
                !in_array($ip, $ips, true)
            ) {
                $ips[] = $ip;
            }
        }
    }

    // 3. Jika diakses menggunakan IP lokal dari browser, pastikan IP tersebut masuk daftar utama
    $srvAddr = $_SERVER['SERVER_ADDR'] ?? '';
    if (
        filter_var($srvAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        $srvAddr !== '127.0.0.1' &&
        !in_array($srvAddr, $ips, true)
    ) {
        array_unshift($ips, $srvAddr);
    }

    // Prioritaskan IP Wi-Fi/Hotspot dibanding adapter virtual (VirtualBox 192.168.56.1)
    usort($ips, function ($a, $b) {
        $aVirt = ($a === '192.168.56.1') ? 1 : 0;
        $bVirt = ($b === '192.168.56.1') ? 1 : 0;
        return $aVirt <=> $bVirt;
    });

    if (empty($ips)) {
        $ips[] = '127.0.0.1';
    }

    return $ips;
}

$lanIps    = detect_lan_ips();
$primaryIp = $lanIps[0];

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$port     = (int)($_SERVER['SERVER_PORT'] ?? 80);
$portPart = ($port !== 80 && $port !== 443) ? ':' . $port : '';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

$voterLanUrl = $scheme . '://' . $primaryIp . $portPart . $basePath . '/';
$adminLanUrl = $scheme . '://' . $primaryIp . $portPart . $basePath . '/admin/';
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
          </span>
          <a href="index.php" class="btn btn-sm">Muat Ulang Data</a>
          <a href="token.php" class="btn btn-sm btn-primary">+ Buat Token</a>
        </div>
      </div>

      <!-- Kartu Statistik (FR-07) -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Total Token Dibuat</div>
          <div class="stat-value" id="stat-total-token"><?= number_format($totalToken, 0, ',', '.') ?></div>
          <div class="stat-meta">Kertas token terdaftar</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Suara Masuk (Terpakai)</div>
          <div class="stat-value" id="stat-token-terpakai"><?= number_format($tokenTerpakai, 0, ',', '.') ?></div>
          <div class="stat-meta" id="stat-partisipasi">Partisipasi: <?= e((string)$partisipasi) ?>%</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sisa Token Belum Dipakai</div>
          <div class="stat-value" id="stat-token-sisa"><?= number_format($tokenSisa, 0, ',', '.') ?></div>
          <div class="stat-meta">Masih sah untuk memilih</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Status Bilik Suara</div>
          <div class="stat-value" id="stat-status-voting" style="font-size: 22px;">
            <?php if ($pengaturan['status_voting'] === 'buka'): ?>
              <span class="badge badge-success" style="font-size: 14px; padding: 4px 10px;">DIBUKA</span>
            <?php else: ?>
              <span class="badge badge-danger" style="font-size: 14px; padding: 4px 10px;">DITUTUP</span>
            <?php endif; ?>
          </div>
          <div class="stat-meta" id="stat-jumlah-kandidat">Jumlah Kandidat: <?= count($kandidatList) ?> Paslon</div>
        </div>
      </div>

      <!-- Panel Ringkasan Perolehan Suara -->
      <div class="panel">
        <div class="panel-header">
          <span>Grafik Perolehan Suara Sementara</span>
          <a href="hasil.php" class="btn btn-sm">Kelola di Halaman Hasil &rarr;</a>
        </div>
        <div class="panel-body" id="dashboard-chart-body" data-chart-sig="<?= e($chartSig) ?>">
          <?= render_dashboard_chart($pengaturan, $kandidatList, $totalSuaraKandidat) ?>
        </div>
      </div>

      <!-- Informasi Sistem & Akses Jaringan Lokal (LAN / HP via Wi-Fi) -->
      <div class="panel">
        <div class="panel-header">
          <span>Informasi Sistem &amp; Akses Jaringan Lokal (LAN / Wi-Fi HP)</span>
          <a href="pengaturan.php" class="btn btn-sm">Ubah Pengaturan / Tema</a>
        </div>
        <div class="panel-body">
          <?php if ($primaryIp === '127.0.0.1'): ?>
            <div class="alert alert-warning">
              Komputer server saat ini belum terdeteksi terhubung ke jaringan Wi-Fi / Hotspot LAN (IP masih <code>127.0.0.1</code>). Hubungkan laptop ke Wi-Fi atau nyalakan Hotspot agar dapat diakses melalui HP.
            </div>
          <?php endif; ?>

          <div class="grid-2" style="margin-bottom: 18px;">
            <!-- Kartu Link Bilik Suara untuk HP / Perangkat Pemilih -->
            <div style="padding: 16px; border: 1px solid var(--border-color); border-radius: var(--radius); background-color: var(--bg-page);">
              <div class="stat-label" style="margin-bottom: 6px;">Link Bilik Suara (Untuk HP / Perangkat Pemilih)</div>
              <p class="text-muted" style="font-size: 12px; margin-bottom: 10px;">
                Buka link ini di browser HP/Tablet pemilih yang tersambung ke Wi-Fi yang sama:
              </p>
              <div style="padding: 10px 12px; background-color: var(--bg-surface); border: 1px solid var(--border-strong); border-radius: var(--radius); margin-bottom: 10px; word-break: break-all;">
                <a href="<?= e($voterLanUrl) ?>" target="_blank" id="link-voter-lan" class="mono" style="font-size: 14px; font-weight: 700;">
                  <?= e($voterLanUrl) ?>
                </a>
              </div>
              <div class="filter-bar">
                <button type="button" class="btn btn-sm btn-primary js-copy-link" data-copy="<?= e($voterLanUrl) ?>">
                  Salin Link Bilik Suara
                </button>
                <a href="<?= e($voterLanUrl) ?>" target="_blank" class="btn btn-sm">
                  Buka Bilik Suara &nearr;
                </a>
              </div>
            </div>

            <!-- Kartu Link Panel Admin untuk HP / Laptop Panitia -->
            <div style="padding: 16px; border: 1px solid var(--border-color); border-radius: var(--radius); background-color: var(--bg-page);">
              <div class="stat-label" style="margin-bottom: 6px;">Link Panel Admin (Untuk HP / Laptop Panitia)</div>
              <p class="text-muted" style="font-size: 12px; margin-bottom: 10px;">
                Buka link ini di HP panitia untuk memantau token dan suara dari jarak jauh:
              </p>
              <div style="padding: 10px 12px; background-color: var(--bg-surface); border: 1px solid var(--border-strong); border-radius: var(--radius); margin-bottom: 10px; word-break: break-all;">
                <a href="<?= e($adminLanUrl) ?>" target="_blank" id="link-admin-lan" class="mono" style="font-size: 14px; font-weight: 700;">
                  <?= e($adminLanUrl) ?>
                </a>
              </div>
              <div class="filter-bar">
                <button type="button" class="btn btn-sm js-copy-link" data-copy="<?= e($adminLanUrl) ?>">
                  Salin Link Admin
                </button>
                <a href="<?= e($adminLanUrl) ?>" target="_blank" class="btn btn-sm">
                  Buka Panel Admin &nearr;
                </a>
              </div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="table">
              <tbody>
                <tr>
                  <th style="width: 240px;">Alamat IPv4 Server (Wi-Fi / LAN)</th>
                  <td>
                    <strong class="mono"><?= e($primaryIp) ?></strong>
                    <?php if (count($lanIps) > 1): ?>
                      <div style="margin-top: 6px; font-size: 12px;" class="text-muted">
                        Alternatif IP Jaringan Lain yang Terdeteksi:
                        <?php foreach (array_slice($lanIps, 1) as $altIp): ?>
                          <?php $altUrl = $scheme . '://' . $altIp . $portPart . $basePath . '/'; ?>
                          <div style="margin-top: 4px;">
                            &bull; <a href="<?= e($altUrl) ?>" target="_blank" class="mono"><?= e($altUrl) ?></a>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr>
                  <th>Panduan Akses di HP</th>
                  <td style="font-size: 13px;">
                    1. Sambungkan HP ke <strong>Wi-Fi atau Hotspot yang sama</strong> dengan laptop server ini.<br>
                    2. Ketik atau tempel link <strong><?= e($voterLanUrl) ?></strong> pada browser HP (Chrome/Safari).<br>
                    3. <span class="text-muted">Jika halaman tidak merespons di HP, pastikan <em>Apache HTTP Server</em> diizinkan pada Windows Firewall (Private &amp; Public Network).</span>
                  </td>
                </tr>
                <tr>
                  <th>Tema Antarmuka Aktif</th>
                  <td>
                    <span class="badge" id="info-tema-badge"><?= $pengaturan['tema'] === 'dark' ? 'Dark (Gelap)' : 'White (Terang)' ?></span>
                    <span class="text-muted" style="font-size: 12px; margin-left: 8px;">Dapat diubah di menu <a href="pengaturan.php">Pengaturan</a></span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/admin.js"></script>
  <script>
    (function () {
      function fallbackCopy(url, btn, originalText) {
        var temp = document.createElement('input');
        document.body.appendChild(temp);
        temp.value = url;
        temp.select();
        try { document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(temp);
        btn.textContent = 'Link Tersalin!';
        setTimeout(function () { btn.textContent = originalText; }, 1800);
      }

      document.querySelectorAll('.js-copy-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var url = btn.getAttribute('data-copy') || '';
          var originalText = btn.textContent;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
              btn.textContent = 'Link Tersalin!';
              setTimeout(function () { btn.textContent = originalText; }, 1800);
            }).catch(function () {
              fallbackCopy(url, btn, originalText);
            });
          } else {
            fallbackCopy(url, btn, originalText);
          }
        });
      });

      var elTotalToken   = document.getElementById('stat-total-token');
      var elTokenDipakai = document.getElementById('stat-token-terpakai');
      var elPartisipasi  = document.getElementById('stat-partisipasi');
      var elTokenSisa    = document.getElementById('stat-token-sisa');
      var elStatusVoting = document.getElementById('stat-status-voting');
      var elJmlKandidat  = document.getElementById('stat-jumlah-kandidat');
      var elTemaBadge    = document.getElementById('info-tema-badge');
      var chartBody      = document.getElementById('dashboard-chart-body');
      var lastStatus     = <?= json_encode($pengaturan['status_voting']) ?>;

      if (typeof window.evotingStartLivePolling === 'function') {
        window.evotingStartLivePolling({
          getUrl: function () {
            var sig = chartBody ? (chartBody.dataset.chartSig || '') : '';
            return 'index.php?ajax=1&sig=' + encodeURIComponent(sig);
          },
          intervalMs: 1500,
          onData: function (data) {
            if (elTotalToken) elTotalToken.textContent = data.total_token_fmt;
            if (elTokenDipakai) elTokenDipakai.textContent = data.token_terpakai_fmt;
            if (elPartisipasi) elPartisipasi.textContent = data.partisipasi_fmt;
            if (elTokenSisa) elTokenSisa.textContent = data.token_sisa_fmt;
            if (elJmlKandidat) elJmlKandidat.textContent = data.jumlah_kandidat_fmt;

            if (elStatusVoting && data.status_voting !== lastStatus) {
              lastStatus = data.status_voting;
              var badge = document.createElement('span');
              badge.className = 'badge ' + (data.status_voting === 'buka' ? 'badge-success' : 'badge-danger');
              badge.style.fontSize = '14px';
              badge.style.padding = '4px 10px';
              badge.textContent = data.status_voting === 'buka' ? 'DIBUKA' : 'DITUTUP';
              elStatusVoting.innerHTML = '';
              elStatusVoting.appendChild(badge);
            }

            if (data.tema) {
              document.documentElement.setAttribute('data-theme', data.tema);
              if (elTemaBadge) {
                elTemaBadge.textContent = data.tema === 'dark' ? 'Dark (Gelap)' : 'White (Terang)';
              }
            }

            if (chartBody) {
              if (data.chart_html !== null && data.chart_sig !== chartBody.dataset.chartSig) {
                chartBody.innerHTML = data.chart_html;
                chartBody.dataset.chartSig = data.chart_sig;
              } else {
                window.evotingUpdateChartMetrics(chartBody, data.kandidat);
              }
            }
          }
        });
      }
    })();
  </script>
</body>
</html>
