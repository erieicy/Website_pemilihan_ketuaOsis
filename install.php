<?php
/**
 * install.php - Wizard Instalasi Mandiri (Web Installer)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$lockFile   = __DIR__ . '/installed.lock';
$configFile = __DIR__ . '/config/config.php';
$isLocked   = file_exists($lockFile);

$error   = '';
$success = false;

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function install_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function install_verify_csrf(?string $token): bool {
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function install_get_rate_limit_data(string $bucket, int $maxAttempts, int $now): array {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';
    $default = ['count' => 0, 'expires' => 0, 'window_expires' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $dec = $raw ? @json_decode($raw, true) : null;
        if (is_array($dec) && isset($dec['count'], $dec['expires'])) {
            $count   = (int)($dec['count'] ?? 0);
            $expires = (int)($dec['expires'] ?? 0);
            $winExp  = (int)($dec['window_expires'] ?? $expires);
            $isActive = ($count >= $maxAttempts) ? ($expires > $now) : ($winExp > $now);
            if ($isActive) {
                return ['count' => $count, 'expires' => $expires, 'window_expires' => $winExp];
            }
        }
    }
    return $default;
}

function install_check_rate_limit(string $bucket, int $maxAttempts = 5, int $lockoutSeconds = 30): int {
    $now  = time();
    $data = install_get_rate_limit_data($bucket, $maxAttempts, $now);
    if ($data['count'] >= $maxAttempts && $data['expires'] > $now) {
        return max(1, (int)($data['expires'] - $now));
    }
    return 0;
}

function install_record_failed(string $bucket, int $lockoutSeconds = 30, int $maxAttempts = 5): int {
    $now  = time();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';
    $data = install_get_rate_limit_data($bucket, $maxAttempts, $now);
    $data['count']++;
    $data['window_expires'] = $now + max(60, $lockoutSeconds);
    $data['expires']        = $now + $lockoutSeconds;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] >= $maxAttempts) {
        return $lockoutSeconds;
    }
    return 0;
}

function install_clear_rate_limit(string $bucket): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';
    if (is_file($file)) {
        @file_put_contents($file, json_encode(['count' => 0, 'expires' => 0, 'window_expires' => 0]), LOCK_EX);
        @unlink($file);
    }
    unset($_SESSION['rl_' . $bucket]);
}

/**
 * Menghapus seluruh file foto kandidat yang pernah diunggah (kecuali file bawaan)
 */
function clean_uploaded_photos(string $rootDir): void {
    $uploadDir = rtrim($rootDir, '/\\') . '/assets/uploads';
    if (!is_dir($uploadDir)) {
        return;
    }
    foreach (scandir($uploadDir) as $item) {
        if (in_array($item, ['.', '..', '.gitkeep', '.htaccess', 'default-candidate.png'], true)) {
            continue;
        }
        $filePath = $uploadDir . '/' . $item;
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }
}

$form = [
    'db_host'      => trim($_POST['db_host'] ?? 'localhost'),
    'db_user'      => trim($_POST['db_user'] ?? 'root'),
    'db_pass'      => $_POST['db_pass'] ?? '',
    'db_name'      => trim($_POST['db_name'] ?? 'db_evoting_osis'),
    'nama_sekolah' => trim($_POST['nama_sekolah'] ?? ''),
    'periode'      => trim($_POST['periode'] ?? ''),
    'tema'         => ($_POST['tema'] ?? 'light') === 'dark' ? 'dark' : 'light',
    'admin_nama'   => trim($_POST['admin_nama'] ?? 'Majelis Perwakilan Kelas (MPK)'),
    'admin_user'   => trim($_POST['admin_user'] ?? ''),
    'admin_pass'   => $_POST['admin_pass'] ?? '',
];

$waitSec = $isLocked ? install_check_rate_limit('install_unlock', 5, 30) : 0;

// Proses Buka Kunci & Reset Total Sistem menggunakan Username + Password Admin + CSRF + Rate Limit (BUG-SEC-01)
if ($isLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_install') {
    if ($waitSec > 0) {
        $error = "Terlalu banyak percobaan gagal. Silakan tunggu {$waitSec} detik.";
    } elseif (!install_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan formulir tidak valid. Silakan muat ulang halaman.';
    } else {
        $unlockUser = trim($_POST['unlock_username'] ?? '');
        $unlockPass = $_POST['unlock_password'] ?? '';

        if ($unlockUser === '' || $unlockPass === '') {
            $error = 'Masukkan username dan password Administrator untuk mereset instalasi.';
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
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                $stmtAdm = $pdoCheck->prepare('SELECT id, password FROM admin WHERE username = ? LIMIT 1');
                $stmtAdm->execute([$unlockUser]);
                $adm = $stmtAdm->fetch();

                if ($adm && password_verify($unlockPass, $adm['password'])) {
                    install_clear_rate_limit('install_unlock');
                    clean_uploaded_photos(__DIR__);
                    $pdoCheck->exec('DROP TABLE IF EXISTS `tokens`, `kandidat`, `admin`, `pengaturan`');

                    if (file_exists($configFile)) {
                        @unlink($configFile);
                    }
                    if (file_exists($lockFile)) {
                        @unlink($lockFile);
                    }

                    $_SESSION = [];
                    session_regenerate_id(true);
                    $isLocked = false;
                    $waitSec  = 0;
                } else {
                    $waitSec = install_record_failed('install_unlock', 30, 5);
                    if ($waitSec > 0) {
                        $error = "Terlalu banyak percobaan gagal. Silakan tunggu {$waitSec} detik.";
                    } else {
                        $error = 'Username atau password Administrator salah! Sistem tidak dapat direset.';
                    }
                }
            } catch (Exception $e) {
                error_log('Install unlock error: ' . $e->getMessage());
                $error = 'Gagal memverifikasi kredensial ke database. Periksa koneksi MySQL.';
            }
        }
    }
}

// Proses Instalasi Baru dari Awal (Clean Install)
if (!$isLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'unlock_install') {
    if (!install_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan formulir tidak valid. Silakan coba lagi.';
    } else {
        $dbNameClean = preg_replace('/[^a-zA-Z0-9_]/', '', $form['db_name']);

        if (
            $dbNameClean === '' ||
            $form['nama_sekolah'] === '' ||
            $form['periode'] === '' ||
            $form['admin_nama'] === '' ||
            $form['admin_user'] === '' ||
            strlen($form['admin_pass']) < 5
        ) {
            $error = 'Mohon lengkapi seluruh kolom wajib (termasuk periode). Password admin minimal 5 karakter.';
        } elseif (
            mb_strlen($form['nama_sekolah']) > 150 ||
            mb_strlen($form['periode']) > 20 ||
            mb_strlen($form['admin_nama']) > 100 ||
            mb_strlen($form['admin_user']) > 50 ||
            strlen($dbNameClean) > 64
        ) {
            $error = 'Panjang teks melebihi batas maksimal kolom (Nama Sekolah maks. 150, Periode maks. 20, Username maks. 50 karakter).';
        } else {
            try {
                $pdo = new PDO(
                    'mysql:host=' . $form['db_host'] . ';charset=utf8mb4',
                    $form['db_user'],
                    $form['db_pass'],
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );

                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameClean}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbNameClean}`");

                // Bersihkan foto lama & hapus tabel lama agar 100% bersih dari awal
                clean_uploaded_photos(__DIR__);
                $pdo->exec("DROP TABLE IF EXISTS `tokens`, `kandidat`, `admin`, `pengaturan`");

                // 1. Buat ulang tabel pengaturan
                $pdo->exec("
                    CREATE TABLE `pengaturan` (
                      `id` int(11) NOT NULL,
                      `nama_sekolah` varchar(150) NOT NULL DEFAULT 'SMA Negeri 1',
                      `periode` varchar(20) NOT NULL DEFAULT '',
                      `status_voting` enum('buka','tutup') NOT NULL DEFAULT 'buka',
                      `tampilkan_hasil` tinyint(1) NOT NULL DEFAULT 0,
                      `tema` enum('light','dark') NOT NULL DEFAULT 'light',
                      PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                // 2. Buat ulang tabel admin
                $pdo->exec("
                    CREATE TABLE `admin` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `username` varchar(50) NOT NULL,
                      `password` varchar(255) NOT NULL,
                      `nama_lengkap` varchar(100) NOT NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `idx_username` (`username`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                // 3. Buat ulang tabel kandidat (dengan UNIQUE KEY pada no_urut - BUG-FUN-03)
                $pdo->exec("
                    CREATE TABLE `kandidat` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `no_urut` int(3) NOT NULL,
                      `nama_ketua` varchar(100) NOT NULL,
                      `nama_wakil` varchar(100) DEFAULT '',
                      `nama_file_foto` varchar(255) NOT NULL DEFAULT 'default-candidate.png',
                      `visi` text NOT NULL,
                      `misi` text NOT NULL,
                      `jumlah_suara` int(11) NOT NULL DEFAULT 0,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `idx_no_urut` (`no_urut`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                // 4. Buat ulang tabel tokens (Tanpa Foreign Key ke kandidat demi kerahasiaan suara)
                $pdo->exec("
                    CREATE TABLE `tokens` (
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
                ");
                $stmtSet->execute([$form['nama_sekolah'], $form['periode'], $form['tema']]);

                // Simpan akun admin pertama
                $hash = password_hash($form['admin_pass'], PASSWORD_BCRYPT);
                $stmtAdm = $pdo->prepare("
                    INSERT INTO admin (username, password, nama_lengkap)
                    VALUES (?, ?, ?)
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

                $_SESSION = [];
                session_regenerate_id(true);

                $success  = true;
                $isLocked = true;
            } catch (Exception $e) {
                error_log('Install execution error: ' . $e->getMessage());
                $error = 'Gagal melakukan instalasi database. Pastikan layanan MySQL aktif dan kredensial database benar.';
            }
        }
    }
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
          <strong>Instalasi Berhasil.</strong> Seluruh database, tabel sistem, dan file kunci <code>installed.lock</code> telah diatur ulang dari awal.
        </div>
        <p style="font-size: 14px; margin-bottom: 20px;">
          Halaman instalasi ini telah dikunci secara otomatis demi keamanan. Silakan masuk ke halaman Administrator menggunakan akun yang baru Anda buat.
        </p>
        <div class="install-grid">
          <a href="admin/login.php" class="btn btn-primary btn-block">Masuk Panel Admin</a>
          <a href="index.php" class="btn btn-block">Buka Bilik Suara</a>
        </div>

      <?php elseif ($isLocked): ?>
        <?php if ($waitSec > 0): ?>
          <div class="alert alert-error" id="rl-alert">
            Terlalu banyak percobaan gagal. Silakan tunggu <strong id="rl-countdown" class="mono" data-wait="<?= (int)$waitSec ?>"><?= (int)$waitSec ?></strong> detik.
          </div>
        <?php elseif ($error !== ''): ?>
          <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="alert alert-warning">
          <strong>Instalasi Terkunci.</strong> Sistem sudah terinstal sebelumnya (file <code>installed.lock</code> ditemukan).
        </div>

        <div class="install-grid" style="margin-bottom: 22px;">
          <a href="index.php" class="btn btn-primary btn-block">Ke Halaman Bilik Suara</a>
          <a href="admin/login.php" class="btn btn-block">Login Administrator</a>
        </div>

        <form method="post" action="install.php" autocomplete="off" class="install-section" style="padding-top: 18px; border-top: 1px solid var(--border-color); margin-bottom: 0;">
          <input type="hidden" name="csrf_token" value="<?= h(install_csrf_token()) ?>">
          <input type="hidden" name="action" value="unlock_install">
          <div class="install-section-title">Reset Total &amp; Instal Ulang dari Awal (Wajib Kredensial Admin)</div>
          <p class="text-muted" style="font-size: 13px; margin-bottom: 12px;">
            Masukkan username dan password Administrator untuk <strong>menghapus seluruh data lama (tabel kandidat, foto, token, akun admin, pengaturan, dan file kunci)</strong> serta membuka kembali wizard <code>install.php</code> dari nol.
          </p>
          <div class="install-grid">
            <div class="form-group">
              <label class="form-label" for="unlock_username">Username Administrator *</label>
              <input
                type="text"
                id="unlock_username"
                name="unlock_username"
                class="form-control mono"
                maxlength="50"
                placeholder="Username Admin"
                <?= $waitSec > 0 ? 'disabled' : '' ?>
                required
              >
            </div>
            <div class="form-group">
              <label class="form-label" for="unlock_password">Password Administrator *</label>
              <input
                type="password"
                id="unlock_password"
                name="unlock_password"
                class="form-control"
                placeholder="Password Admin"
                <?= $waitSec > 0 ? 'disabled' : '' ?>
                required
              >
            </div>
          </div>
          <button
            type="submit"
            id="btn-unlock-install"
            class="btn btn-danger btn-block js-confirm"
            data-confirm="PERINGATAN KERAS: Seluruh data lama (kandidat, foto, token, admin, dan pengaturan) akan dihapus total dari awal. Lanjutkan?"
            <?= $waitSec > 0 ? 'disabled' : '' ?>
          >
            <?= $waitSec > 0 ? 'Tunggu ' . (int)$waitSec . ' Detik...' : 'Reset Total &amp; Buka Wizard install.php' ?>
          </button>
        </form>

      <?php else: ?>
        <?php if ($error !== ''): ?>
          <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="install.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= h(install_csrf_token()) ?>">
          <div class="install-section">
            <div class="install-section-title">1. Koneksi Database MySQL / MariaDB</div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="db_host">Database Host</label>
                <input type="text" id="db_host" name="db_host" class="form-control" maxlength="100" value="<?= h($form['db_host']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_name">Nama Database</label>
                <input type="text" id="db_name" name="db_name" class="form-control mono" maxlength="64" value="<?= h($form['db_name']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_user">Database Username</label>
                <input type="text" id="db_user" name="db_user" class="form-control" maxlength="64" value="<?= h($form['db_user']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="db_pass">Database Password</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" value="<?= h($form['db_pass']) ?>" placeholder="Kosongkan jika standar XAMPP/Laragon">
              </div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-title">2. Identitas Sekolah &amp; Tema UI</div>
            <div class="form-group">
              <label class="form-label" for="nama_sekolah">Nama Sekolah</label>
              <input type="text" id="nama_sekolah" name="nama_sekolah" class="form-control" maxlength="150" value="<?= h($form['nama_sekolah']) ?>" placeholder="Masukkan Nama Sekolah" required>
            </div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="periode">Periode Kepengurusan</label>
                <input type="text" id="periode" name="periode" class="form-control" maxlength="20" value="<?= h($form['periode']) ?>" placeholder="Masukkan Tahun" required>
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
              <input type="text" id="admin_nama" name="admin_nama" class="form-control" maxlength="100" value="<?= h($form['admin_nama']) ?>" required>
            </div>
            <div class="install-grid">
              <div class="form-group">
                <label class="form-label" for="admin_user">Username Login</label>
                <input type="text" id="admin_user" name="admin_user" class="form-control mono" maxlength="50" value="<?= h($form['admin_user']) ?>" placeholder="Masukkan Username" required>
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
  <script>
    (function () {
      var rlCountdown = document.getElementById('rl-countdown');
      var rlAlert     = document.getElementById('rl-alert');
      var userInp     = document.getElementById('unlock_username');
      var passInp     = document.getElementById('unlock_password');
      var unlockBtn   = document.getElementById('btn-unlock-install');

      if (!rlCountdown) return;
      var rem = parseInt(rlCountdown.getAttribute('data-wait') || rlCountdown.textContent, 10) || 0;
      if (rem <= 0) return;

      var rlTimer = setInterval(function () {
        rem -= 1;
        if (rem <= 0) {
          clearInterval(rlTimer);
          if (rlAlert) rlAlert.style.display = 'none';
          if (userInp) userInp.disabled = false;
          if (passInp) passInp.disabled = false;
          if (unlockBtn) {
            unlockBtn.disabled = false;
            unlockBtn.textContent = 'Reset Total & Buka Wizard install.php';
          }
        } else {
          rlCountdown.textContent = String(rem);
          if (unlockBtn) {
            unlockBtn.textContent = 'Tunggu ' + rem + ' Detik...';
          }
        }
      }, 1000);
    })();
  </script>
</body>
</html>
