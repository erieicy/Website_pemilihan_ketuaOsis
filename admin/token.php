<?php
/**
 * admin/token.php - Bulk Token Generator & Pencarian/Manajemen Token (FR-09 & FR-10)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$pengaturan = get_pengaturan($pdo);
$message    = $_SESSION['flash_token_msg'] ?? '';
$error      = $_SESSION['flash_token_err'] ?? '';
unset($_SESSION['flash_token_msg'], $_SESSION['flash_token_err']);

/**
 * Menghasilkan 1 kode token 6 karakter alfanumerik kapital tanpa O, 0, I, 1
 */
function generate_single_token(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $token = '';
    for ($i = 0; $i < 6; $i++) {
        $token .= $chars[random_int(0, $max)];
    }
    return $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postMsg = '';
    $postErr = '';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $postErr = 'Token keamanan tidak valid.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Bulk Generate Token (FR-09)
        if ($action === 'generate') {
            $jumlah = (int)($_POST['jumlah_token'] ?? 0);
            if ($jumlah < 1 || $jumlah > 1000) {
                $postErr = 'Jumlah pembuatan token harus antara 1 sampai 1.000 per eksekusi.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmtIns  = $pdo->prepare('INSERT IGNORE INTO tokens (kode_token, sudah_dipakai, created_at) VALUES (?, 0, NOW())');
                    $inserted = 0;
                    $attempts = 0;
                    $maxTries = $jumlah * 5;

                    while ($inserted < $jumlah && $attempts < $maxTries) {
                        $attempts++;
                        $kode = generate_single_token();
                        $stmtIns->execute([$kode]);
                        if ($stmtIns->rowCount() === 1) {
                            $inserted++;
                        }
                    }

                    $pdo->commit();
                    $postMsg = "Berhasil membuat {$inserted} kode token unik baru.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $postErr = 'Gagal membuat token: ' . $e->getMessage();
                }
            }
        }

        // 2. Hapus 1 Token (Hanya yang belum dipakai)
        elseif ($action === 'delete_one') {
            $idToken = (int)($_POST['id'] ?? 0);
            $stmtDel = $pdo->prepare('DELETE FROM tokens WHERE id = ? AND sudah_dipakai = 0');
            $stmtDel->execute([$idToken]);
            if ($stmtDel->rowCount() === 1) {
                $postMsg = 'Kode token yang belum terpakai berhasil dihapus.';
            } else {
                $postErr = 'Token yang sudah dipakai memilih tidak dapat dihapus secara satuan.';
            }
        }

        // 3. Hapus Seluruh Token yang Belum Dipakai
        elseif ($action === 'delete_unused') {
            $stmtDel = $pdo->exec('DELETE FROM tokens WHERE sudah_dipakai = 0');
            $postMsg = "Berhasil menghapus {$stmtDel} token yang belum terpakai.";
        }
    }

    if ($postMsg !== '') {
        $_SESSION['flash_token_msg'] = $postMsg;
    }
    if ($postErr !== '') {
        $_SESSION['flash_token_err'] = $postErr;
    }

    // Redirect (Post/Redirect/Get) agar saat halaman di-refresh (F5) tidak menambah token otomatis
    $redirectUrl = 'token.php';
    if (isset($_POST['return_status']) || isset($_POST['return_q']) || isset($_POST['return_page'])) {
        $retStatus = $_POST['return_status'] ?? 'all';
        if (!in_array($retStatus, ['all', '0', '1'], true)) {
            $retStatus = 'all';
        }
        $retQ    = strtoupper(trim((string)($_POST['return_q'] ?? '')));
        $retPage = max(1, (int)($_POST['return_page'] ?? 1));
        $redirectUrl .= '?' . http_build_query([
            'status' => $retStatus,
            'q'      => $retQ,
            'page'   => $retPage,
        ]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Statistik Ringkas Token
$tokenStats = get_token_stats($pdo);
$countAll   = $tokenStats['total'];
$countBelum = $tokenStats['sisa'];
$countSudah = $tokenStats['terpakai'];

// Filter Status & Pencarian Kode Token (FR-10)
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', '0', '1'], true)) {
    $statusFilter = 'all';
}
$searchQuery = strtoupper(trim($_GET['q'] ?? ''));

$whereClauses = [];
$params       = [];

if ($statusFilter === '0' || $statusFilter === '1') {
    $whereClauses[] = 'sudah_dipakai = ?';
    $params[]       = (int)$statusFilter;
}
if ($searchQuery !== '') {
    $escapedSearch  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQuery);
    $whereClauses[] = "kode_token LIKE ? ESCAPE '\\\\'";
    $params[]       = '%' . $escapedSearch . '%';
}

$whereSql = !empty($whereClauses) ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

// Pagination
$perPage     = 100;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

$stmtFilteredCount = $pdo->prepare("SELECT COUNT(*) FROM tokens {$whereSql}");
$stmtFilteredCount->execute($params);
$totalFiltered = (int)$stmtFilteredCount->fetchColumn();
$totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;

$stmtTokens = $pdo->prepare("SELECT * FROM tokens {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmtTokens->execute($params);
$tokenList = $stmtTokens->fetchAll();

// Hitung signature state tabel agar AJAX tidak menyentuh DOM jika data belum berubah
$rowSigs = [];
foreach ($tokenList as $t) {
    $rowSigs[] = $t['id'] . ':' . $t['kode_token'] . ':' . $t['sudah_dipakai'] . ':' . ($t['used_at'] ?? '');
}
$tokenSig = md5(json_encode([$countAll, $countBelum, $countSudah, $totalFiltered, $currentPage, $statusFilter, $searchQuery, $rowSigs]));

/**
 * Merender input tersembunyi untuk mempertahankan filter dan halaman setelah aksi POST
 */
function render_token_return_inputs(string $statusFilter, string $searchQuery, int $currentPage): string {
    return '<input type="hidden" name="return_status" value="' . e($statusFilter) . '">' .
           '<input type="hidden" name="return_q" value="' . e($searchQuery) . '">' .
           '<input type="hidden" name="return_page" value="' . (int)$currentPage . '">';
}

/**
 * Merender baris-baris <tr> tabel token
 */
function render_token_rows(
    array $tokenList,
    int $offset,
    string $csrfToken,
    string $statusFilter = 'all',
    string $searchQuery = '',
    int $currentPage = 1
): string {
    ob_start();
    if (empty($tokenList)): ?>
      <tr>
        <td colspan="6" class="text-muted" style="text-align: center; padding: 24px;">
          Tidak ada data token yang sesuai dengan filter pencarian.
        </td>
      </tr>
    <?php else:
      foreach ($tokenList as $idx => $t): ?>
        <tr>
          <td class="mono text-muted"><?= $offset + $idx + 1 ?></td>
          <td>
            <strong class="mono" style="font-size: 15px; letter-spacing: 1px;"><?= e($t['kode_token']) ?></strong>
          </td>
          <td>
            <?php if ((int)$t['sudah_dipakai'] === 1): ?>
              <span class="badge badge-danger">Sudah Dipakai</span>
            <?php else: ?>
              <span class="badge badge-success">Belum Dipakai (Sah)</span>
            <?php endif; ?>
          </td>
          <td class="mono" style="font-size: 12px;"><?= e($t['created_at']) ?></td>
          <td class="mono" style="font-size: 12px;">
            <?= !empty($t['used_at']) ? e($t['used_at']) : '<span class="text-muted">&mdash;</span>' ?>
          </td>
          <td>
            <?php if ((int)$t['sudah_dipakai'] === 0): ?>
              <form method="post" action="token.php" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_one">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <?= render_token_return_inputs($statusFilter, $searchQuery, $currentPage) ?>
                <button
                  type="submit"
                  class="btn btn-sm js-confirm"
                  data-confirm="Hapus kode token <?= e($t['kode_token']) ?>?"
                >
                  Hapus
                </button>
              </form>
            <?php else: ?>
              <span class="text-muted" style="font-size: 12px;">Terkunci</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach;
    endif;
    return (string)ob_get_clean();
}

/**
 * Merender footer pagination & tombol hapus massal token belum dipakai
 */
function render_token_footer(
    int $currentPage,
    int $totalPages,
    int $countBelum,
    string $statusFilter,
    string $searchQuery,
    string $csrfToken
): string {
    if ($totalPages <= 1 && $countBelum <= 0) {
        return '';
    }
    ob_start(); ?>
    <div class="panel-header" style="border-top: 1px solid var(--border-color); border-bottom: none;">
      <div class="filter-bar">
        <?php if ($totalPages > 1): ?>
          <span class="text-muted" style="font-size: 12px;">
            Halaman <?= $currentPage ?> dari <?= $totalPages ?>
          </span>
          <?php if ($currentPage > 1): ?>
            <a href="token.php?status=<?= e($statusFilter) ?>&amp;q=<?= urlencode($searchQuery) ?>&amp;page=<?= $currentPage - 1 ?>" class="btn btn-sm">&larr; Sebelumnya</a>
          <?php endif; ?>
          <?php if ($currentPage < $totalPages): ?>
            <a href="token.php?status=<?= e($statusFilter) ?>&amp;q=<?= urlencode($searchQuery) ?>&amp;page=<?= $currentPage + 1 ?>" class="btn btn-sm">Berikutnya &rarr;</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($countBelum > 0): ?>
        <form method="post" action="token.php">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="delete_unused">
          <?= render_token_return_inputs($statusFilter, $searchQuery, $currentPage) ?>
          <button
            type="submit"
            class="btn btn-sm btn-danger js-confirm"
            data-confirm="Hapus seluruh <?= $countBelum ?> token yang belum terpakai?"
          >
            Hapus Semua Token Belum Dipakai
          </button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

$activeCsrf = csrf_token();

// Endpoint AJAX Real-Time untuk memantau status penggunaan token otomatis
if (isset($_GET['ajax'])) {
    session_write_close();
    $clientSig = (string)($_GET['sig'] ?? '');

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($clientSig !== '' && $clientSig === $tokenSig) {
        echo json_encode([
            'changed' => false,
            'sig'     => $tokenSig,
            'tema'    => $pengaturan['tema'],
        ]);
        exit;
    }

    echo json_encode([
        'changed'         => true,
        'sig'             => $tokenSig,
        'tema'            => $pengaturan['tema'],
        'current_page'    => $currentPage,
        'count_all_fmt'   => number_format($countAll, 0, ',', '.'),
        'count_belum_fmt' => number_format($countBelum, 0, ',', '.'),
        'count_sudah_fmt' => number_format($countSudah, 0, ',', '.'),
        'rows_html'       => render_token_rows($tokenList, $offset, $activeCsrf, $statusFilter, $searchQuery, $currentPage),
        'footer_html'     => render_token_footer($currentPage, $totalPages, $countBelum, $statusFilter, $searchQuery, $activeCsrf),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manajemen Token &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="../assets/css/base.css">
  <link rel="stylesheet" href="../assets/css/admin-layout.css">
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
          <a href="token.php" class="nav-link active">Manajemen Token</a>
          <a href="hasil.php" class="nav-link">Hasil Perhitungan</a>
          <a href="pengaturan.php" class="nav-link">Pengaturan</a>
        </nav>
      </div>
      <div class="sidebar-footer">
        <div class="sidebar-user">Login: <strong><?= e($_SESSION['admin_nama'] ?? 'Admin') ?></strong></div>
        <div class="sidebar-actions">
          <a href="../index.php" class="btn btn-sm">Bilik Suara</a>
          <form method="post" action="logout.php" style="display: contents;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="btn btn-sm btn-danger">Keluar</button>
          </form>
        </div>
      </div>
    </aside>

    <main class="admin-main">
      <div class="page-header">
        <div>
          <h1>Manajemen &amp; Distribusi Token Suara</h1>
          <p>Buat kode acak sekali pakai (One-Time Token) dan cetak lembar token siap gunting</p>
        </div>
        <div class="filter-bar">
          <a href="cetak_token.php?status=0" target="_blank" class="btn btn-primary">
            <span>Cetak Token Belum Dipakai (<span id="token-print-count"><?= number_format($countBelum, 0, ',', '.') ?></span>)</span>
          </a>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success" data-broadcast-update="1"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <!-- Panel Pembuatan Token Massal (FR-09) -->
      <div class="panel">
        <div class="panel-header">
          <span>Generator Token Massal (Bulk Token Generator)</span>
          <span class="text-muted" style="font-weight: 400; font-size: 12px;">6 Karakter Alfanumerik (Tanpa huruf O/0 dan I/1)</span>
        </div>
        <div class="panel-body">
          <form method="post" action="token.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <input type="hidden" name="csrf_token" value="<?= e($activeCsrf) ?>">
            <input type="hidden" name="action" value="generate">
            <div style="flex: 1; min-width: 220px;">
              <label class="form-label" for="jumlah_token">Jumlah Token yang Ingin Dibuat (Maks. 1.000 per eksekusi)</label>
              <input
                type="number"
                id="jumlah_token"
                name="jumlah_token"
                class="form-control mono"
                min="1"
                max="1000"
                value="100"
                required
              >
            </div>
            <button type="submit" class="btn btn-primary">Generate Token Sekarang</button>
          </form>
        </div>
      </div>

      <!-- Tabel Daftar & Pencarian Token (FR-10) -->
      <div class="panel">
        <div class="panel-header">
          <div class="filter-bar">
            <a href="token.php?status=all&amp;q=<?= urlencode($searchQuery) ?>" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : '' ?>">
              <span>Semua (<span id="count-all"><?= number_format($countAll, 0, ',', '.') ?></span>)</span>
            </a>
            <a href="token.php?status=0&amp;q=<?= urlencode($searchQuery) ?>" class="btn btn-sm <?= $statusFilter === '0' ? 'btn-primary' : '' ?>">
              <span>Belum Dipakai (<span id="count-belum"><?= number_format($countBelum, 0, ',', '.') ?></span>)</span>
            </a>
            <a href="token.php?status=1&amp;q=<?= urlencode($searchQuery) ?>" class="btn btn-sm <?= $statusFilter === '1' ? 'btn-primary' : '' ?>">
              <span>Sudah Dipakai (<span id="count-sudah"><?= number_format($countSudah, 0, ',', '.') ?></span>)</span>
            </a>
          </div>

          <form method="get" action="token.php" class="filter-bar">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input
              type="text"
              name="q"
              class="form-control mono"
              style="width: 190px; padding: 5px 10px; font-size: 13px; text-transform: uppercase;"
              placeholder="Cari kode token..."
              value="<?= e($searchQuery) ?>"
            >
            <button type="submit" class="btn btn-sm">Cari</button>
            <?php if ($searchQuery !== ''): ?>
              <a href="token.php?status=<?= e($statusFilter) ?>" class="btn btn-sm">Reset</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="panel-body" style="padding: 0;">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th style="width: 70px;">No.</th>
                  <th>Kode Token</th>
                  <th>Status</th>
                  <th>Waktu Dibuat</th>
                  <th>Waktu Digunakan</th>
                  <th style="width: 100px;">Aksi</th>
                </tr>
              </thead>
              <tbody id="token-table-body" data-token-sig="<?= e($tokenSig) ?>">
                <?= render_token_rows($tokenList, $offset, $activeCsrf, $statusFilter, $searchQuery, $currentPage) ?>
              </tbody>
            </table>
          </div>
        </div>

        <div id="token-table-footer-wrap">
          <?= render_token_footer($currentPage, $totalPages, $countBelum, $statusFilter, $searchQuery, $activeCsrf) ?>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/admin.js"></script>
  <script>
    (function () {
      var tbody      = document.getElementById('token-table-body');
      var footerWrap = document.getElementById('token-table-footer-wrap');
      var printCount = document.getElementById('token-print-count');
      var countAll   = document.getElementById('count-all');
      var countBelum = document.getElementById('count-belum');
      var countSudah = document.getElementById('count-sudah');

      var filterStatus = <?= json_encode($statusFilter) ?>;
      var filterQuery  = <?= json_encode($searchQuery) ?>;
      var pageNum      = <?= (int)$currentPage ?>;

      if (typeof window.evotingStartLivePolling === 'function' && tbody) {
        window.evotingStartLivePolling({
          getUrl: function () {
            var sig = tbody.dataset.tokenSig || '';
            return 'token.php?ajax=1&status=' + encodeURIComponent(filterStatus) +
                   '&q=' + encodeURIComponent(filterQuery) +
                   '&page=' + encodeURIComponent(String(pageNum)) +
                   '&sig=' + encodeURIComponent(sig);
          },
          intervalMs: 1500,
          onData: function (data) {
            if (data.tema) {
              document.documentElement.setAttribute('data-theme', data.tema);
            }
            if (!data.changed) return;
            if (data.current_page) {
              pageNum = data.current_page;
            }
            tbody.dataset.tokenSig = data.sig;
            tbody.innerHTML = data.rows_html;
            if (footerWrap) footerWrap.innerHTML = data.footer_html;
            if (printCount) printCount.textContent = data.count_belum_fmt;
            if (countAll) countAll.textContent = data.count_all_fmt;
            if (countBelum) countBelum.textContent = data.count_belum_fmt;
            if (countSudah) countSudah.textContent = data.count_sudah_fmt;
          }
        });
      }
    })();
  </script>
</body>
</html>
