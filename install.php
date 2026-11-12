<?php
/**
 * install.php - Wizard Instalasi Mandiri (Web Installer)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

session_start();
date_default_timezone_set('Asia/Jakarta');

$lockFile   = __DIR__ . '/installed.lock';
$configFile = __DIR__ . '/config/config.php';
$isLocked   = file_exists($lockFile);

$error   = '';
$success = false;

$form = [
    'db_host'      => $_POST['db_host'] ?? 'localhost',
    'db_user'      => $_POST['db_user'] ?? 'root',
    'db_pass'      => $_POST['db_pass'] ?? '',
    'db_name'      => $_POST['db_name'] ?? 'db_evoting_osis',
    'nama_sekolah' => trim($_POST['nama_sekolah'] ?? 'SMA Negeri 1 Harapan'),
    'periode'      => trim($_POST['periode'] ?? '2026/2027'),
    'tema'         => ($_POST['tema'] ?? 'light') === 'dark' ? 'dark' : 'light',
    'admin_nama'   => trim($_POST['admin_nama'] ?? 'Panitia Pemilihan (MPK)'),
    'admin_user'   => trim($_POST['admin_user'] ?? 'admin'),
    'admin_pass'   => $_POST['admin_pass'] ?? '',
];

// Proses Buka Kunci install.php menggunakan Password Admin (jika installed.lock ada)
if ($isLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_install') {
    $unlockPass = $_POST['unlock_password'] ?? '';
    if ($unlockPass === '') {
        $error = 'Masukkan password Administrator untuk membuka kunci instalasi.';
    } else {
        try {
            if (file_exists($configFile)) {
                require_once $configFile;
            }
            $uHost = defined('DB_HOST') ? DB_HOST : 'localhost';
            $uUser = defined('DB_USER') ? DB_USER : 'root';
            $uPass = defined('DB_PASS') ? DB_PASS : '';
            $uName = defined('DB_NAME') ? DB_NAME : 'db_evoting_osis';

            $pdoCheck = new PDO("mysql:host={$uHost};dbname={$uName};charset=utf8mb4", $uUser, $uPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $stmtAdmins = $pdoCheck->query('SELECT password FROM admin');
            $validAdmin = false;
            foreach ($stmtAdmins->fetchAll() as $adm) {
                if (password_verify($unlockPass, $adm['password'])) {
                    $validAdmin = true;
                    break;
                }
            }

            if ($validAdmin) {
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $isLocked = false;
            } else {
                $error = 'Password Administrator salah! File kunci tidak dapat dihapus.';
            }
        } catch (Exception $e) {
            $error = 'Gagal memverifikasi password ke database: ' . $e->getMessage();
        }
    }
}

if (!$isLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'unlock_install') {
    $dbNameClean = preg_replace('/[^a-zA-Z0-9_]/', '', $form['db_name']);

    if ($dbNameClean === '' || $form['nama_sekolah'] === '' || $form['admin_user'] === '' || strlen($form['admin_pass']) < 5) {
        $error = 'Mohon lengkapi seluruh kolom wajib. Password admin minimal 5 karakter.';
    } else {
        try {
            $pdo = new PDO(
                'mysql:host=' . $form['db_host'] . ';charset=utf8mb4',
                $form['db_user'],
                $form['db_pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameClean}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbNameClean}`");

            // 1. Tabel pengaturan
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `pengaturan` (
                  `id` int(11) NOT NULL,
                  `nama_sekolah` varchar(150) NOT NULL DEFAULT 'SMA Negeri 1 Harapan',
                  `periode` varchar(20) NOT NULL DEFAULT '2026/2027',
                  `status_voting` enum('buka','tutup') NOT NULL DEFAULT 'buka',
                  `tampilkan_hasil` tinyint(1) NOT NULL DEFAULT 0,
                  `tema` enum('light','dark') NOT NULL DEFAULT 'light',
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 2. Tabel admin
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `admin` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `username` varchar(50) NOT NULL,
                  `password` varchar(255) NOT NULL,
                  `nama_lengkap` varchar(100) NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `idx_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 3. Tabel kandidat
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `kandidat` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `no_urut` int(3) NOT NULL,
                  `nama_ketua` varchar(100) NOT NULL,
                  `nama_wakil` varchar(100) DEFAULT '',
                  `nama_file_foto` varchar(255) NOT NULL DEFAULT 'default-candidate.png',
                  `visi` text NOT NULL,
                  `misi` text NOT NULL,
                  `jumlah_suara` int(11) NOT NULL DEFAULT 0,
                  PRIMARY KEY (`id`),
                  KEY `idx_no_urut` (`no_urut`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 4. Tabel tokens (Tanpa Foreign Key ke kandidat demi kerahasiaan suara)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `tokens` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `kode_token` varchar(10) NOT NULL,
                  `sudah_dipakai` tinyint(1) NOT NULL DEFAULT 0,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `used_at` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `idx_kode_token` (`kode_token`),
                  KEY `idx_sudah_dipakai` (`sudah_dipakai`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Simpan pengaturan awal
            $stmtSet = $pdo->prepare("
                INSERT INTO pengaturan (id, nama_sekolah, periode, status_voting, tampilkan_hasil, tema)
                VALUES (1, ?, ?, 'buka', 0, ?)
                ON DUPLICATE KEY UPDATE
                  nama_sekolah = VALUES(nama_sekolah),
                  periode = VALUES(periode),
                  tema = VALUES(tema)
            ");
            $stmtSet->execute([$form['nama_sekolah'], $form['periode'], $form['tema']]);

            // Simpan akun admin pertama
            $hash = password_hash($form['admin_pass'], PASSWORD_BCRYPT);
            $stmtAdm = $pdo->prepare("
                INSERT INTO admin (username, password, nama_lengkap)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  password = VALUES(password),
                  nama_lengkap = VALUES(nama_lengkap)
            ");
            $stmtAdm->execute([$form['admin_user'], $hash, $form['admin_nama']]);

            // Tulis config/config.php
            $configContent = "<?php\n"
                . "/**\n * Konfigurasi Database E-Voting OSIS\n * Dibuat otomatis oleh install.php\n */\n\n"
                . "define('DB_HOST', " . var_export($form['db_host'], true) . ");\n"
                . "define('DB_USER', " . var_export($form['db_user'], true) . ");\n"
                . "define('DB_PASS', " . var_export($form['db_pass'], true) . ");\n"
                . "define('DB_NAME', " . var_export($dbNameClean, true) . ");\n";

            file_put_contents($configFile, $configContent);
            file_put_contents($lockFile, 'Installed at ' . date('Y-m-d H:i:s') . "\n");

            $success  = true;
            $isLocked = true;
        } catch ( Exception $e ) {
            $error = 'Gagal melakukan instalasi database: ' . $e->getMessage();
        }
    }
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= h($form['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalasi Sistem E-Voting OSIS</title>
  <link rel="stylesheet" href="assets/css/base.css">
  <link rel="stylesheet" href="assets/css/installer.css">
</head>
<body>
  <div class="install-wrapper">
    <div class="install-card">
      <div class="install-header">
        <div>
          <h1>Instalasi Sistem E-Voting OSIS</h1>
          <p>Konfigurasi mandiri database lokal dan akun panitia pemilihan</p>
        </div>
        <span class="badge">LAN Offline-First</span>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <strong>Instalasi Berhasil.</strong> Database, tabel sistem, dan file kunci <code>installed.lock</code> telah dibuat.
        </div>
        <p style="font-size: 14px; margin-bottom: 20px;">
          Halaman instalasi ini telah dikunci secara otomatis demi keamanan. Silakan masuk ke halaman Administrator untuk menambahkan kandidat dan mencetak token suara.
        </p>
        <div class="install-grid">
          <a href="admin/login.php" class="btn btn-primary btn-block">Masuk Panel Admin</a>
          <a href="index.php" class="btn btn-block">Buka Bilik Suara</a>
        </div>

      <?php elseif ($isLocked): ?>
        <?php if ($error !== ''): ?>
          <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="alert alert-warning">
          <strong>Instalasi Terkunci.</strong> Sistem sudah terinstal sebelumnya (file <code>installed.lock</code> ditemukan).
        </div>

        <div class="install-grid" style="margin-bottom: 22px;">
          <a href="index.php" class="btn btn-primary btn-block">Ke Halaman Bilik Suara</a>
          <a href="admin/login.php" class="btn btn-block">Login Administrator</a>
        </div>

        <form method="post" action="install.php" class="install-section" style="padding-top: 18px; border-top: 1px solid var(--border-color); margin-bottom: 0;">
          <input type="hidden" name="action" value="unlock_install">
          <div class="install-section-title">Buka Kunci Instalasi (Wajib Password Admin)</div>
          <p class="text-muted" style="font-size: 13px; margin-bottom: 12px;">
            Masukkan password Administrator untuk menghapus file <code>installed.lock</code> dan membuka kembali halaman konfigurasi <code>install.php</code>.
          </p>
          <div class="form-group">
            <label class="form-label" for="unlock_password">Password Administrator *</label>
            <input
              type="password"
              id="unlock_password"
              name="unlock_password"
              class="form-control"
              placeholder="Masukkan password Admin"
              required
            >
          </div>
          <button type="submit" class="btn btn-danger btn-block">Hapus Kunci &amp; Buka Wizard install.php</button>
        </form>

      <?php else: ?>
        <?php if ($error !== ''): ?>
          <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="install.php" autocomplete="off">
          <div class="install-section">
            <div class="install-section-title">1. Koneksi Database MySQL / MariaDB</div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="db_host">Database Host</label>
                <input type="text" id="db_host" name="db_host" class="form-control" value="<?= h($form['db_host']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_name">Nama Database</label>
                <input type="text" id="db_name" name="db_name" class="form-control mono" value="<?= h($form['db_name']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_user">Database Username</label>
                <input type="text" id="db_user" name="db_user" class="form-control" value="<?= h($form['db_user']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_pass">Database Password</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" value="<?= h($form['db_pass']) ?>" placeholder="Kosongkan jika standar XAMPP/Laragon">
              </div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-title">2. Identitas Sekolah & Tema UI</div>
            <div class="form-group">
              <label class="form-label" for="nama_sekolah">Nama Sekolah</label>
              <input type="text" id="nama_sekolah" name="nama_sekolah" class="form-control" value="<?= h($form['nama_sekolah']) ?>" required>
            </div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="periode">Periode Kepengurusan</label>
                <input type="text" id="periode" name="periode" class="form-control" value="<?= h($form['periode']) ?>" placeholder="2026/2027" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="tema">Tema Tampilan Awal</label>
                <select id="tema" name="tema" class="form-control">
                  <option value="light" <?= $form['tema'] === 'light' ? 'selected' : '' ?>>White (Terang)</option>
                  <option value="dark" <?= $form['tema'] === 'dark' ? 'selected' : '' ?>>Dark (Gelap)</option>
                </select>
                <span class="form-hint">Dapat diubah kapan saja di menu Pengaturan.</span>
              </div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-title">3. Akun Administrator Pertama</div>
            <div class="form-group">
              <label class="form-label" for="admin_nama">Nama Lengkap Panitia / Operator</label>
              <input type="text" id="admin_nama" name="admin_nama" class="form-control" value="<?= h($form['admin_nama']) ?>" required>
            </div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="admin_user">Username Login</label>
                <input type="text" id="admin_user" name="admin_user" class="form-control mono" value="<?= h($form['admin_user']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="admin_pass">Password Login</label>
                <input type="password" id="admin_pass" name="admin_pass" class="form-control" placeholder="Minimal 5 karakter" required>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Jalankan Instalasi Sekarang</button>
        </form>
      <?php endif; ?>

      <div class="install-footer">
        <span>Sistem E-Voting Ketua OSIS</span>
        <span>PHP Native &bull; MySQL</span>
      </div>
    </div>
  </div>
  <script src="assets/js/admin.js"></script>
</body>
</html>
