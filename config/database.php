<?php
/**
 * Koneksi Database, Keamanan Sesi, Rate Limiting, & Fungsi Utilitas Dasar
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

// Kirim HTTP Security Headers & Anti-Cache untuk keamanan perangkat publik (BUG-SEC-05)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

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
 * Rate Limiting berbasis IP + Sesi untuk mencegah Brute-Force & Enumerasi (BUG-SEC-02)
 * Mengembalikan sisa detik tunggu (0 jika diizinkan)
 */
function check_rate_limit(string $bucket, int $maxAttempts = 5, int $windowSeconds = 60): int {
    $now  = time();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';

    $data = ['count' => 0, 'expires' => $now + $windowSeconds];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $dec = $raw ? @json_decode($raw, true) : null;
        if (is_array($dec) && isset($dec['count'], $dec['expires']) && $dec['expires'] > $now) {
            $data = $dec;
        }
    }

    $sessKey = 'rl_' . $bucket;
    if (!empty($_SESSION[$sessKey]) && $_SESSION[$sessKey]['expires'] > $now) {
        if ($_SESSION[$sessKey]['count'] > $data['count']) {
            $data = $_SESSION[$sessKey];
        }
    }

    if ($data['count'] >= $maxAttempts) {
        return max(1, (int)($data['expires'] - $now));
    }
    return 0;
}

/**
 * Mencatat 1 percobaan gagal ke dalam ember Rate Limit
 */
function record_failed_attempt(string $bucket, int $windowSeconds = 60): void {
    $now  = time();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';

    $data = ['count' => 0, 'expires' => $now + $windowSeconds];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $dec = $raw ? @json_decode($raw, true) : null;
        if (is_array($dec) && isset($dec['count'], $dec['expires']) && $dec['expires'] > $now) {
            $data = $dec;
        }
    }

    $data['count']++;
    if ($data['count'] === 1) {
        $data['expires'] = $now + $windowSeconds;
    }

    @file_put_contents($file, json_encode($data), LOCK_EX);
    $_SESSION['rl_' . $bucket] = $data;
}

/**
 * Mereset catatan percobaan gagal setelah autentikasi berhasil
 */
function clear_rate_limit(string $bucket): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
    unset($_SESSION['rl_' . $bucket]);
}

/**
 * Menghapus seluruh data sistem kembali ke kondisi awal (Factory Reset ke install.php)
 */
function reset_system_to_initial(PDO $pdo, string $rootDir): void {
    $uploadDir = rtrim($rootDir, '/\\') . '/assets/uploads';
    if (is_dir($uploadDir)) {
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

    $pdo->exec('DROP TABLE IF EXISTS `tokens`, `kandidat`, `admin`, `pengaturan`');

    $cfgPath  = rtrim($rootDir, '/\\') . '/config/config.php';
    $lockPath = rtrim($rootDir, '/\\') . '/installed.lock';
    if (file_exists($cfgPath)) {
        @unlink($cfgPath);
    }
    if (file_exists($lockPath)) {
        @unlink($lockPath);
    }

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
    error_log('Database connection error: ' . $ex->getMessage());
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
        error_log('Pengaturan query error: ' . $e->getMessage());
        $inAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin');
        header('Location: ' . ($inAdmin ? '../install.php' : 'install.php'));
        exit;
    }

    return $defaults;
}
