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
        'nama_sekolah'    => 'SMA Negeri 1 Harapan',
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
        // Jika tabel belum dibuat dan belum terkunci, arahkan ke installer
        if (!file_exists(dirname(__DIR__) . '/installed.lock')) {
            $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
            header('Location: ' . ($inAdmin ? '../install.php' : 'install.php'));
            exit;
        }
    }

    return $defaults;
}
