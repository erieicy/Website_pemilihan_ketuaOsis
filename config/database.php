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
 * Proteksi halaman Administrator (mengembalikan HTTP 401 JSON untuk request AJAX)
 */
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) {
        $isAjax = isset($_GET['ajax']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'unauthorized', 'login_url' => 'login.php']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * Membaca data rate limit yang masih aktif (belum melewati masa berlaku)
 */
function get_rate_limit_data(string $bucket, int $maxAttempts, int $now): array {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';

    $default    = ['count' => 0, 'expires' => 0, 'window_expires' => 0];
    $candidates = [];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $dec = $raw ? @json_decode($raw, true) : null;
        if (is_array($dec) && isset($dec['count'], $dec['expires'])) {
            $candidates[] = $dec;
        }
    }

    $sessKey = 'rl_' . $bucket;
    if (!empty($_SESSION[$sessKey]) && is_array($_SESSION[$sessKey]) && isset($_SESSION[$sessKey]['count'], $_SESSION[$sessKey]['expires'])) {
        $candidates[] = $_SESSION[$sessKey];
    }

    $best = $default;
    foreach ($candidates as $item) {
        $count   = (int)($item['count'] ?? 0);
        $expires = (int)($item['expires'] ?? 0);
        $winExp  = (int)($item['window_expires'] ?? $expires);

        $isActive = ($count >= $maxAttempts) ? ($expires > $now) : ($winExp > $now);
        if ($isActive && $count > $best['count']) {
            $best = [
                'count'          => $count,
                'expires'        => $expires,
                'window_expires' => $winExp,
            ];
        }
    }

    return $best;
}

/**
 * Rate Limiting berbasis IP + Sesi untuk mencegah Brute-Force & Enumerasi (BUG-SEC-02)
 * Mengembalikan sisa detik tunggu (0 jika diizinkan)
 */
function check_rate_limit(string $bucket, int $maxAttempts = 5, int $lockoutSeconds = 30): int {
    $now  = time();
    $data = get_rate_limit_data($bucket, $maxAttempts, $now);

    if ($data['count'] >= $maxAttempts && $data['expires'] > $now) {
        return max(1, (int)($data['expires'] - $now));
    }
    return 0;
}

/**
 * Mencatat 1 percobaan gagal ke dalam ember Rate Limit.
 * Mengembalikan sisa detik tunggu jika batas percobaan telah tercapai (0 jika belum terkunci).
 */
function record_failed_attempt(string $bucket, int $lockoutSeconds = 5, int $maxAttempts = 5): int {
    $now  = time();
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';

    $data = get_rate_limit_data($bucket, $maxAttempts, $now);
    $data['count']++;
    $data['window_expires'] = $now + max(60, $lockoutSeconds);
    $data['expires']        = $now + $lockoutSeconds;

    @file_put_contents($file, json_encode($data), LOCK_EX);
    $_SESSION['rl_' . $bucket] = $data;

    if ($data['count'] >= $maxAttempts) {
        return $lockoutSeconds;
    }
    return 0;
}

/**
 * Mereset catatan percobaan gagal setelah kode / autentikasi berhasil
 */
function clear_rate_limit(string $bucket): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/evoting_rl_' . md5($bucket . '|' . $ip) . '.json';
    if (is_file($file)) {
        @file_put_contents($file, json_encode(['count' => 0, 'expires' => 0, 'window_expires' => 0]), LOCK_EX);
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
        'nama_sekolah'    => '',
        'periode'         => '',
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

/**
 * Mengambil ringkasan statistik token (total, terpakai, sisa, persentase partisipasi)
 */
function get_token_stats(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT
          COUNT(*) AS total_token,
          COALESCE(SUM(CASE WHEN sudah_dipakai = 1 THEN 1 ELSE 0 END), 0) AS token_terpakai,
          COALESCE(SUM(CASE WHEN sudah_dipakai = 0 THEN 1 ELSE 0 END), 0) AS token_sisa
        FROM tokens
    ");
    $row      = $stmt->fetch() ?: [];
    $total    = (int)($row['total_token'] ?? 0);
    $terpakai = (int)($row['token_terpakai'] ?? 0);
    $sisa     = (int)($row['token_sisa'] ?? 0);

    return [
        'total'       => $total,
        'terpakai'    => $terpakai,
        'sisa'        => $sisa,
        'partisipasi' => $total > 0 ? round(($terpakai / $total) * 100, 1) : 0,
    ];
}

/**
 * Menentukan URL foto kandidat yang valid dengan fallback default-candidate.png
 */
function resolve_candidate_photo_url(?string $filename, string $prefix = '../'): string {
    $foto      = !empty($filename) ? basename($filename) : 'default-candidate.png';
    $uploadDir = dirname(__DIR__) . '/assets/uploads/';
    if (!is_file($uploadDir . $foto)) {
        $foto = 'default-candidate.png';
    }
    return $prefix . 'assets/uploads/' . $foto;
}

/**
 * Merender daftar bar grafik perolehan suara kandidat (digunakan oleh Dashboard & Hasil)
 */
function render_candidate_chart_items(array $kandidatList, int $totalSuara, string $titlePrefix = 'No. '): string {
    ob_start();
    echo '<div class="chart-list">';
    foreach ($kandidatList as $k) {
        $suara   = (int)$k['jumlah_suara'];
        $persen  = $totalSuara > 0 ? round(($suara / $totalSuara) * 100, 1) : 0;
        $fotoUrl = resolve_candidate_photo_url($k['nama_file_foto'] ?? null, '../');
        $noPad   = str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT);
        $sub     = !empty($k['nama_wakil']) ? 'Wakil: ' . e($k['nama_wakil']) : 'Calon Ketua OSIS';
        ?>
        <div class="chart-item" data-kandidat-id="<?= (int)$k['id'] ?>">
          <div class="chart-head">
            <div class="chart-candidate">
              <img src="<?= e($fotoUrl) ?>" alt="Foto Kandidat">
              <div>
                <div class="chart-title"><?= e($titlePrefix) ?><?= e($noPad) ?> &mdash; <?= e($k['nama_ketua']) ?></div>
                <div class="chart-subtitle"><?= $sub ?></div>
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
        <?php
    }
    echo '</div>';
    return (string)ob_get_clean();
}

/**
 * Menyusun payload angka suara kandidat untuk respons JSON real-time
 */
function build_candidate_ajax_payload(array $kandidatList, int $totalSuara): array {
    $items = [];
    foreach ($kandidatList as $k) {
        $suara   = (int)$k['jumlah_suara'];
        $persen  = $totalSuara > 0 ? round(($suara / $totalSuara) * 100, 1) : 0;
        $items[] = [
            'id'         => (int)$k['id'],
            'suara_fmt'  => number_format($suara, 0, ',', '.'),
            'votes_lbl'  => number_format($suara, 0, ',', '.') . ' Suara',
            'persen_fmt' => $persen . '%',
            'persen_val' => $persen,
        ];
    }
    return $items;
}
