<?php
/**
 * admin/pengaturan.php - Pengaturan Sistem, Tema (White/Dark), & Reset Data Simulasi (FR-12)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Simpan Pengaturan Umum & Tema UI (White / Dark)
        if ($action === 'save_general') {
            $namaSekolah    = trim($_POST['nama_sekolah'] ?? '');
            $periode        = trim($_POST['periode'] ?? '');
            $statusVoting   = ($_POST['status_voting'] ?? 'buka') === 'tutup' ? 'tutup' : 'buka';
            $tampilkanHasil = (int)($_POST['tampilkan_hasil'] ?? 0) === 1 ? 1 : 0;
            $tema           = ($_POST['tema'] ?? 'light') === 'dark' ? 'dark' : 'light';

            if ($namaSekolah === '' || $periode === '') {
                $error = 'Nama sekolah dan periode pemilihan wajib diisi.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE pengaturan
                    SET nama_sekolah = ?, periode = ?, status_voting = ?, tampilkan_hasil = ?, tema = ?
                    WHERE id = 1
                ');
                $stmt->execute([$namaSekolah, $periode, $statusVoting, $tampilkanHasil, $tema]);
                $message = 'Pengaturan sistem dan tema tampilan berhasil disimpan.';
            }
        }

        // 2. Perbarui Profil / Password Admin
        elseif ($action === 'save_account') {
            $namaLengkap  = trim($_POST['nama_lengkap'] ?? '');
            $usernameBaru = trim($_POST['username'] ?? '');
            $passLama     = $_POST['password_lama'] ?? '';
            $passBaru     = $_POST['password_baru'] ?? '';

            $stmtAdm = $pdo->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
            $stmtAdm->execute([(int)$_SESSION['admin_id']]);
            $adminRow = $stmtAdm->fetch();

            if (!$adminRow || !password_verify($passLama, $adminRow['password'])) {
                $error = 'Password saat ini salah. Perubahan akun dibatalkan.';
            } elseif ($namaLengkap === '' || $usernameBaru === '') {
                $error = 'Nama lengkap dan username tidak boleh kosong.';
            } else {
                if ($passBaru !== '') {
                    if (strlen($passBaru) < 5) {
                        $error = 'Password baru minimal terdiri dari 5 karakter.';
                    } else {
                        $hash = password_hash($passBaru, PASSWORD_BCRYPT);
                        $stmtUpd = $pdo->prepare('UPDATE admin SET nama_lengkap = ?, username = ?, password = ? WHERE id = ?');
                        $stmtUpd->execute([$namaLengkap, $usernameBaru, $hash, (int)$adminRow['id']]);
                    }
                } else {
                    $stmtUpd = $pdo->prepare('UPDATE admin SET nama_lengkap = ?, username = ? WHERE id = ?');
                    $stmtUpd->execute([$namaLengkap, $usernameBaru, (int)$adminRow['id']]);
                }

                if ($error === '') {
                    $_SESSION['admin_nama']     = $namaLengkap;
                    $_SESSION['admin_username'] = $usernameBaru;
                    $message = 'Informasi akun administrator berhasil diperbarui.';
                }
            }
        }

        // 3. Reset Data Simulasi (FR-12)
        elseif ($action === 'reset_simulasi') {
            $passConfirm = $_POST['password_konfirmasi'] ?? '';

            $stmtAdm = $pdo->prepare('SELECT password FROM admin WHERE id = ? LIMIT 1');
            $stmtAdm->execute([(int)$_SESSION['admin_id']]);
            $adminRow = $stmtAdm->fetch();

            if (!$adminRow || !password_verify($passConfirm, $adminRow['password'])) {
                $error = 'Password konfirmasi salah! Reset data simulasi dibatalkan.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->exec('DELETE FROM tokens');
                    $pdo->exec('UPDATE kandidat SET jumlah_suara = 0');
                    $pdo->commit();
                    $message = 'Reset Data Simulasi berhasil. Seluruh token telah dihapus dan perolehan suara kandidat kembali ke 0.';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Gagal mereset data simulasi: ' . $e->getMessage();
                }
            }
        }

        // 4. Buka Kunci install.php (Hapus installed.lock dengan Konfirmasi Password Admin)
        elseif ($action === 'unlock_installer') {
            $passInstall = $_POST['password_install'] ?? '';

            $stmtAdm = $pdo->prepare('SELECT password FROM admin WHERE id = ? LIMIT 1');
            $stmtAdm->execute([(int)$_SESSION['admin_id']]);
            $adminRow = $stmtAdm->fetch();

            if (!$adminRow || !password_verify($passInstall, $adminRow['password'])) {
                $error = 'Password konfirmasi salah! Pembukaan kunci install.php dibatalkan.';
            } else {
                $lockFilePath = dirname(__DIR__) . '/installed.lock';
                if (file_exists($lockFilePath)) {
                    @unlink($lockFilePath);
                }
                header('Location: ../install.php');
                exit;
            }
        }
    }
}

$pengaturan = get_pengaturan($pdo);
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pengaturan Sistem &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
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
          <a href="token.php" class="nav-link">Manajemen Token</a>
          <a href="hasil.php" class="nav-link">Hasil Perhitungan</a>
          <a href="pengaturan.php" class="nav-link active">Pengaturan</a>
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
          <h1>Pengaturan Sistem &amp; Tampilan</h1>
          <p>Atur identitas sekolah, status bilik suara, tema antarmuka (White / Dark), dan reset simulasi</p>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="grid-2">
        <!-- 1. Pengaturan Umum & Buka Kunci Installer -->
        <div>
          <div class="panel">
            <div class="panel-header">
              <span>Konfigurasi Pemilihan &amp; Tema Tampilan</span>
            </div>
            <div class="panel-body">
              <form method="post" action="pengaturan.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_general">

                <div class="form-group">
                  <label class="form-label" for="tema">Tema Tampilan Antarmuka (UI)</label>
                  <select id="tema" name="tema" class="form-control">
                    <option value="light" <?= $pengaturan['tema'] === 'light' ? 'selected' : '' ?>>White (Terang / Bersih)</option>
                    <option value="dark" <?= $pengaturan['tema'] === 'dark' ? 'selected' : '' ?>>Dark (Gelap / Kontras Rendah Cahaya)</option>
                  </select>
                  <span class="form-hint">Berlaku untuk halaman Administrator dan seluruh perangkat Bilik Suara.</span>
                </div>

                <div class="form-group">
                  <label class="form-label" for="nama_sekolah">Nama Sekolah</label>
                  <input
                    type="text"
                    id="nama_sekolah"
                    name="nama_sekolah"
                    class="form-control"
                    value="<?= e($pengaturan['nama_sekolah']) ?>"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="periode">Periode Kepengurusan</label>
                  <input
                    type="text"
                    id="periode"
                    name="periode"
                    class="form-control"
                    value="<?= e($pengaturan['periode']) ?>"
                    placeholder="Contoh: 2026/2027"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="status_voting">Status Akses Bilik Suara</label>
                  <select id="status_voting" name="status_voting" class="form-control">
                    <option value="buka" <?= $pengaturan['status_voting'] === 'buka' ? 'selected' : '' ?>>Buka (Pemilih Dapat Memasukkan Token)</option>
                    <option value="tutup" <?= $pengaturan['status_voting'] === 'tutup' ? 'selected' : '' ?>>Tutup (Kunci Akses Bilik Suara)</option>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label" for="tampilkan_hasil">Visibilitas Grafik Perhitungan Suara</label>
                  <select id="tampilkan_hasil" name="tampilkan_hasil" class="form-control">
                    <option value="0" <?= (int)$pengaturan['tampilkan_hasil'] === 0 ? 'selected' : '' ?>>Sembunyikan / Kunci Selama Pemilihan</option>
                    <option value="1" <?= (int)$pengaturan['tampilkan_hasil'] === 1 ? 'selected' : '' ?>>Tampilkan Grafik Perolehan Suara</option>
                  </select>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Simpan Pengaturan</button>
              </form>
            </div>
          </div>

          <!-- Panel Buka Kunci install.php (Hapus installed.lock dengan Password Admin) -->
          <div id="panel-installer" class="panel">
            <div class="panel-header">
              <span>Buka Kunci &amp; Akses Halaman <code>install.php</code></span>
            </div>
            <div class="panel-body">
              <p class="text-muted" style="font-size: 13px; margin-bottom: 14px;">
                Menghapus file pengunci <code>installed.lock</code> secara otomatis dan membuka kembali halaman wizard <code>install.php</code>. Wajib memasukkan password Admin untuk mencegah penghapusan sembarangan.
              </p>
              <form method="post" action="pengaturan.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlock_installer">
                <div class="form-group">
                  <label class="form-label" for="password_install">Konfirmasi Password Admin *</label>
                  <input
                    type="password"
                    id="password_install"
                    name="password_install"
                    class="form-control"
                    placeholder="Masukkan password Administrator"
                    required
                  >
                </div>
                <button
                  type="submit"
                  class="btn btn-block js-confirm"
                  data-confirm="File installed.lock akan dihapus dan Anda akan diarahkan ke halaman install.php. Lanjutkan?"
                >
                  Hapus Kunci &amp; Buka Halaman install.php
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- 2. Pengaturan Akun Admin & Reset Simulasi -->
        <div>
          <div class="panel">
            <div class="panel-header">
              <span>Akun Administrator</span>
            </div>
            <div class="panel-body">
              <form method="post" action="pengaturan.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_account">

                <div class="form-group">
                  <label class="form-label" for="nama_lengkap">Nama Lengkap Panitia</label>
                  <input
                    type="text"
                    id="nama_lengkap"
                    name="nama_lengkap"
                    class="form-control"
                    value="<?= e($_SESSION['admin_nama'] ?? '') ?>"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="username">Username Login</label>
                  <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control mono"
                    value="<?= e($_SESSION['admin_username'] ?? '') ?>"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="password_baru">Password Baru (Opsional)</label>
                  <input
                    type="password"
                    id="password_baru"
                    name="password_baru"
                    class="form-control"
                    placeholder="Kosongkan jika tidak ingin mengubah password"
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="password_lama">Konfirmasi Password Saat Ini *</label>
                  <input
                    type="password"
                    id="password_lama"
                    name="password_lama"
                    class="form-control"
                    placeholder="Wajib diisi untuk menyimpan perubahan akun"
                    required
                  >
                </div>

                <button type="submit" class="btn btn-block">Perbarui Akun Admin</button>
              </form>
            </div>
          </div>

          <!-- 3. Reset Data Simulasi (FR-12) -->
          <div class="panel" style="border-color: var(--danger-border);">
            <div class="panel-header" style="background-color: var(--danger-bg); color: var(--danger);">
              <span>Reset Data Simulasi / Uji Coba</span>
            </div>
            <div class="panel-body">
              <p class="text-muted" style="font-size: 13px; margin-bottom: 14px;">
                Tindakan ini akan <strong>menghapus seluruh kode token</strong> dan mengatur ulang <strong>jumlah suara semua kandidat kembali ke 0</strong>. Data biodata dan foto kandidat tidak akan terhapus.
              </p>
              <form method="post" action="pengaturan.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reset_simulasi">
                <div class="form-group">
                  <label class="form-label" for="password_konfirmasi">Masukkan Password Admin untuk Konfirmasi *</label>
                  <input
                    type="password"
                    id="password_konfirmasi"
                    name="password_konfirmasi"
                    class="form-control"
                    placeholder="Password Administrator"
                    required
                  >
                </div>
                <button
                  type="submit"
                  class="btn btn-danger btn-block js-confirm"
                  data-confirm="PERINGATAN: Seluruh token dan perolehan suara akan direset ke 0. Lanjutkan?"
                >
                  Hapus Seluruh Token &amp; Reset Suara ke 0
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/admin.js"></script>
</body>
</html>
