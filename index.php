<?php
/**
 * index.php - Halaman Depan Bilik Suara (Validasi One-Time Token)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once __DIR__ . '/config/database.php';

// Keluar bilik suara menggunakan metode POST bertoken CSRF (BUG-SEC-03)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'keluar') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        unset($_SESSION['voter_token']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    header('Location: index.php');
    exit;
}

$pengaturan = get_pengaturan($pdo);
$isOpen     = ($pengaturan['status_voting'] === 'buka');

// Endpoint ringan status bilik suara agar layar pemilih otomatis sinkron saat voting dibuka/ditutup
if (isset($_GET['status_check'])) {
    $voterToken = !empty($_SESSION['voter_token']) ? (string)$_SESSION['voter_token'] : '';
    session_write_close();

    $tokenValid = false;
    if ($voterToken !== '') {
        $stmtTok = $pdo->prepare('SELECT sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
        $stmtTok->execute([$voterToken]);
        $tRow       = $stmtTok->fetch();
        $tokenValid = (bool)($tRow && (int)$tRow['sudah_dipakai'] === 0);
    }

    $stmtKandidat = $pdo->query('SELECT id, no_urut, nama_ketua, nama_wakil, nama_file_foto, visi, misi FROM kandidat ORDER BY no_urut ASC, id ASC');
    $kandidatList = $stmtKandidat->fetchAll();
    $kSigParts    = [];
    foreach ($kandidatList as $k) {
        $fotoName = !empty($k['nama_file_foto']) ? basename((string)$k['nama_file_foto']) : 'default-candidate.png';
        $fotoFile = __DIR__ . '/assets/uploads/' . $fotoName;
        if (!is_file($fotoFile)) {
            $fotoFile = __DIR__ . '/assets/uploads/default-candidate.png';
        }
        $fotoMtime = is_file($fotoFile) ? (int)@filemtime($fotoFile) : 0;

        $kSigParts[] = implode(':', [
            (int)$k['id'],
            (int)$k['no_urut'],
            (string)($k['nama_ketua'] ?? ''),
            (string)($k['nama_wakil'] ?? ''),
            (string)($k['nama_file_foto'] ?? ''),
            $fotoMtime,
            md5((string)($k['visi'] ?? '')),
            md5((string)($k['misi'] ?? '')),
        ]);
    }
    $kandidatSig = md5(implode('|', $kSigParts));

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'status_voting' => $pengaturan['status_voting'],
        'tema'          => $pengaturan['tema'],
        'token_valid'   => $tokenValid,
        'kandidat_sig'  => $kandidatSig,
    ]);
    exit;
}

unset($_SESSION['vote_recorded_at']);

// Jika pemilih sudah memiliki sesi token aktif yang belum dipakai, arahkan ke bilik suara
if ($isOpen && !empty($_SESSION['voter_token'])) {
    $stmtCheck = $pdo->prepare('SELECT sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
    $stmtCheck->execute([$_SESSION['voter_token']]);
    $tokenData = $stmtCheck->fetch();
    if ($tokenData && (int)$tokenData['sudah_dipakai'] === 0) {
        header('Location: bilik_suara.php');
        exit;
    }
    unset($_SESSION['voter_token']);
}

$error = $_SESSION['voter_error'] ?? '';
unset($_SESSION['voter_error']);

$inputToken = '';
$waitSec    = $isOpen ? check_rate_limit('voter_token', 5, 5) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOpen && ($_POST['action'] ?? '') !== 'keluar') {
    if ($waitSec > 0) {
        $error = "Terlalu banyak percobaan kode token yang gagal. Silakan tunggu {$waitSec} detik.";
    } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi formulir tidak valid. Silakan coba kembali.';
    } else {
        $inputToken = strtoupper(trim($_POST['kode_token'] ?? ''));
        $inputToken = substr(preg_replace('/[^A-Z0-9]/', '', $inputToken), 0, 6);

        if ($inputToken === '') {
            $error = 'Masukkan kode token terlebih dahulu.';
        } else {
            $stmt = $pdo->prepare('SELECT kode_token, sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
            $stmt->execute([$inputToken]);
            $row = $stmt->fetch();

            if (!$row) {
                // Kondisi A: Token tidak ditemukan
                $waitSec = record_failed_attempt('voter_token', 5, 5);
                if ($waitSec > 0) {
                    $error = "Terlalu banyak percobaan kode token yang gagal. Silakan tunggu {$waitSec} detik.";
                } else {
                    $error = 'Kode tidak terdaftar! Periksa kembali ketikan Anda.';
                }
            } elseif ((int)$row['sudah_dipakai'] === 1) {
                // Kondisi B: Token sudah pernah dipakai
                $waitSec = record_failed_attempt('voter_token', 5, 5);
                if ($waitSec > 0) {
                    $error = "Terlalu banyak percobaan kode token yang gagal. Silakan tunggu {$waitSec} detik.";
                } else {
                    $error = 'Kode ini sudah pernah digunakan dan tidak berlaku lagi!';
                }
            } else {
                // Kondisi C: Token sah dan belum dipakai (Reset Rate Limit & Regenerasi ID Sesi)
                clear_rate_limit('voter_token');
                session_regenerate_id(true);
                $_SESSION['voter_token'] = $row['kode_token'];
                header('Location: bilik_suara.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bilik Suara &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="assets/css/base.css">
  <link rel="stylesheet" href="assets/css/voter-login.css">
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <span class="auth-tag">Bilik Suara E-Voting OSIS</span>
        <h1 class="auth-title"><?= e($pengaturan['nama_sekolah']) ?></h1>
        <p class="auth-subtitle">Periode Pemilihan <?= e($pengaturan['periode']) ?></p>
      </div>

      <?php if ($waitSec > 0): ?>
        <div class="alert alert-error" id="rl-alert">
          Terlalu banyak percobaan kode token yang gagal. Silakan tunggu <strong id="rl-countdown" class="mono" data-wait="<?= (int)$waitSec ?>"><?= (int)$waitSec ?></strong> detik.
        </div>
      <?php elseif ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if (!$isOpen): ?>
        <div class="closed-box">
          <h3>Pemungutan Suara Ditutup</h3>
          <p class="text-muted" style="font-size: 13px;">
            Bilik suara saat ini sedang ditutup oleh panitia pemilihan.
          </p>
        </div>
      <?php else: ?>
        <form method="post" action="index.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="form-group">
            <label class="form-label" for="kode_token" style="text-align: center;">Masukkan Kode Token Suara</label>
            <input
              type="text"
              id="kode_token"
              name="kode_token"
              class="token-input"
              maxlength="6"
              placeholder="XXXXXX"
              value="<?= e($inputToken) ?>"
              <?= $waitSec > 0 ? 'disabled' : 'autofocus' ?>
              required
            >
            <span class="form-hint" style="text-align: center;">
              Ketik 6 karakter kode pada kertas token dari panitia.
            </span>
          </div>
          <button type="submit" id="btn-submit-token" class="btn btn-primary btn-block" <?= $waitSec > 0 ? 'disabled' : '' ?>>
            <?= $waitSec > 0 ? 'Tunggu ' . (int)$waitSec . ' Detik...' : 'Masuk Bilik Suara' ?>
          </button>
        </form>
      <?php endif; ?>

      <div class="auth-footer">
        <span>Jaringan Lokal (LAN)</span>
        <a href="admin/login.php">Panel Panitia &rarr;</a>
      </div>  
    </div>
  </div>
  <script>
    (function () {
      var input       = document.getElementById('kode_token');
      var submitBtn   = document.getElementById('btn-submit-token');
      var rlCountdown = document.getElementById('rl-countdown');
      var rlAlert     = document.getElementById('rl-alert');

      if (rlCountdown) {
        var rem = parseInt(rlCountdown.getAttribute('data-wait') || rlCountdown.textContent, 10) || 0;
        if (rem > 0) {
          if (input) input.disabled = true;
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Tunggu ' + rem + ' Detik...';
          }
          var rlTimer = setInterval(function () {
            rem -= 1;
            if (rem <= 0) {
              clearInterval(rlTimer);
              if (rlAlert) rlAlert.style.display = 'none';
              if (input) {
                input.disabled = false;
                input.focus();
              }
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Masuk Bilik Suara';
              }
            } else {
              rlCountdown.textContent = String(rem);
              if (submitBtn) {
                submitBtn.textContent = 'Tunggu ' + rem + ' Detik...';
              }
            }
          }, 1000);
        }
      }

      // Sinkronisasi otomatis status bilik suara (buka/tutup) & tema tanpa perlu refresh manual
      (function () {
        var initialStatus = <?= json_encode($pengaturan['status_voting']) ?>;
        var timer = null;
        var inFlight = false;

        function checkBoothStatus() {
          if (document.hidden || inFlight) return;
          inFlight = true;
          fetch('index.php?status_check=1&_t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
              if (!d) return;
              if (d.tema) {
                document.documentElement.setAttribute('data-theme', d.tema);
              }
              if (d.status_voting && d.status_voting !== initialStatus) {
                window.location.href = 'index.php';
              }
            })
            .catch(function () {})
            .finally(function () {
              inFlight = false;
              if (!document.hidden) {
                clearTimeout(timer);
                timer = setTimeout(checkBoothStatus, 3000);
              }
            });
        }

        document.addEventListener('visibilitychange', function () {
          if (!document.hidden) {
            clearTimeout(timer);
            checkBoothStatus();
          }
        });
        try {
          if (window.BroadcastChannel) {
            var ch = new BroadcastChannel('evoting_updates');
            ch.addEventListener('message', function () {
              clearTimeout(timer);
              checkBoothStatus();
            });
          }
        } catch (_) {}
        window.addEventListener('storage', function (e) {
          if (e.key === 'evoting_last_update') {
            clearTimeout(timer);
            checkBoothStatus();
          }
        });

        timer = setTimeout(checkBoothStatus, 3000);
      })();

      if (!input) return;

      input.addEventListener('paste', function (e) {
        var clipboard = e.clipboardData || window.clipboardData;
        if (!clipboard) return;
        e.preventDefault();
        var pasted = (clipboard.getData('text') || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
        this.value = pasted;
        this.setSelectionRange(pasted.length, pasted.length);
      });

      input.addEventListener('input', function () {
        var rawPos = this.selectionStart || 0;
        var cleanBefore = this.value.slice(0, rawPos).toUpperCase().replace(/[^A-Z0-9]/g, '');
        var cleanFull   = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
        var newPos      = Math.min(cleanFull.length, cleanBefore.length);
        this.value = cleanFull;
        this.setSelectionRange(newPos, newPos);
      });
    })();
  </script>
</body>
</html>
