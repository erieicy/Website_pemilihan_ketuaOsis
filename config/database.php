<?php
/**
 * Koneksi Database & Fungsi Utilitas Dasar
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

$configFile = __DIR__ . '/config.php';
$lockFile   = dirname(__DIR__) . '/installed.lock';

// Jika belum terinstal (installed.lock tidak ada), arahkan langsung ke install.php
if (!file_exists($lockFile)) {
    $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
    header('Location: ' . ($inAdmin ? '../install.php' : 'install.php'));
    exit;
}

if (file_exists($configFile)) {
    require_once $configFile;
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'db_evoting_osis');
}

/**
 * Helper sanitasi output HTML (XSS Protection)
 */
function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Membuat / mengambil token CSRF sesi aktif
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validasi token CSRF
 */
function verify_csrf(?string $token): bool {
    return !empty($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Proteksi halaman Administrator
 */
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Menghapus seluruh data sistem kembali ke kondisi awal (Factory Reset ke install.php):
 * - Menghapus seluruh foto unggahan di assets/uploads/ (kecuali default-candidate.png & .gitkeep)
 * - Menghapus seluruh tabel database (tokens, kandidat, admin, pengaturan)
 * - Menghapus config/config.php dan installed.lock
 * - Menghapus seluruh sesi login aktif
 */
function reset_system_to_initial(PDO $pdo, string $rootDir): void {
    // 1. Bersihkan file foto unggahan kandidat
    $uploadDir = rtrim($rootDir, '/\\') . '/assets/uploads';
    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) as $item) {
            if ($item === '.' || $item === '..' || $item === '.gitkeep' || $item === 'default-candidate.png') {
                continue;
            }
            $filePath = $uploadDir . '/' . $item;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    // 2. Hapus seluruh tabel di database agar benar-benar kembali dari nol
    $pdo->exec('DROP TABLE IF EXISTS `tokens`, `kandidat`, `admin`, `pengaturan`');

    // 3. Hapus file konfigurasi & file pengunci instalasi
    $cfgPath  = rtrim($rootDir, '/\\') . '/config/config.php';
    $lockPath = rtrim($rootDir, '/\\') . '/installed.lock';
    if (file_exists($cfgPath)) {
        @unlink($cfgPath);
    }
    if (file_exists($lockPath)) {
        @unlink($lockPath);
    }

    // 4. Hentikan seluruh sesi login Admin maupun Pemilih
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $ex) {
    if (!file_exists($lockFile)) {
        $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
        header('Location: ' . ($inAdmin ? '../install.php' : 'install.php'));
        exit;
    }
    http_response_code(500);
    die('Gagal terhubung ke database. Periksa layanan MySQL/MariaDB dan konfigurasi di config/config.php.');
}

/**
 * Mengambil konfigurasi sistem dari tabel `pengaturan`
 */
function get_pengaturan(PDO $pdo): array {
    $defaults = [
        'id'              => 1,
        'nama_sekolah'    => '',
        'periode'         => '2026/2027',
        'status_voting'   => 'buka',
        'tampilkan_hasil' => 0,
        'tema'            => 'light',
    ];

    try {
        $stmt = $pdo->query('SELECT * FROM pengaturan WHERE id = 1 LIMIT 1');
        $row  = $stmt->fetch();
        if ($row) {
            if (!array_key_exists('tema', $row)) {
                $pdo->exec("ALTER TABLE pengaturan ADD COLUMN tema ENUM('light','dark') NOT NULL DEFAULT 'light'");
                $row['tema'] = 'light';
            }
            return array_merge($defaults, $row);
        }
    } catch (PDOException $e) {
        $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
        header('Location: ' . ($inAdmin ? '../install.php' : 'install.php'));
        exit;
    }

    return $defaults;
}
