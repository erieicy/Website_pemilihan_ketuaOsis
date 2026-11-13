<?php
/**
 * bilik_suara.php - Halaman Daftar Kandidat & Pemilihan Suara
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once __DIR__ . '/config/database.php';

$pengaturan = get_pengaturan($pdo);

if ($pengaturan['status_voting'] !== 'buka') {
    unset($_SESSION['voter_token']);
    $_SESSION['voter_error'] = 'Pemungutan suara sedang ditutup oleh panitia.';
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['voter_token'])) {
    header('Location: index.php');
    exit;
}

$kodeToken = $_SESSION['voter_token'];
$stmtToken = $pdo->prepare('SELECT kode_token, sudah_dipakai FROM tokens WHERE kode_token = ? LIMIT 1');
$stmtToken->execute([$kodeToken]);
$tokenRow = $stmtToken->fetch();

if (!$tokenRow || (int)$tokenRow['sudah_dipakai'] === 1) {
    unset($_SESSION['voter_token']);
    $_SESSION['voter_error'] = 'Kode ini sudah pernah digunakan dan tidak berlaku lagi!';
    header('Location: index.php');
    exit;
}

// Ambil dan bersihkan pesan error dari proses_vote.php (BUG-FUN-02)
$voterError = $_SESSION['voter_error'] ?? '';
unset($_SESSION['voter_error']);

$stmtKandidat = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtKandidat->fetchAll();
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pilih Kandidat &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
  <link rel="stylesheet" href="assets/css/base.css">
  <link rel="stylesheet" href="assets/css/bilik-suara.css">
</head>
<body>
  <header class="booth-topbar">
    <div class="booth-brand">
      <?= e($pengaturan['nama_sekolah']) ?> &mdash; <span class="text-muted">Periode <?= e($pengaturan['periode']) ?></span>
    </div>
    <div class="booth-meta">
      <span>Token Aktif: <strong class="mono"><?= e($kodeToken) ?></strong></span>
      <form method="post" action="index.php" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="keluar">
        <button type="submit" class="btn btn-sm">Batal / Keluar</button>
      </form>
    </div>
  </header>

  <main class="booth-container">
    <div class="booth-heading">
      <h1>Surat Suara Pemilihan Ketua OSIS</h1>
      <p>Silakan pelajari Visi &amp; Misi kandidat, lalu klik tombol <strong>Pilih</strong> pada kandidat pilihan Anda.</p>
    </div>

    <?php if ($voterError !== ''): ?>
      <div class="alert alert-error" style="max-width: 560px; margin: 0 auto 24px; text-align: center;">
        <?= e($voterError) ?>
      </div>
    <?php endif; ?>

    <?php if (empty($kandidatList)): ?>
      <div class="alert alert-warning" style="max-width: 520px; margin: 0 auto; text-align: center;">
        Belum ada data kandidat yang terdaftar. Silakan hubungi panitia pemilihan.
      </div>
    <?php else: ?>
      <div class="candidate-grid">
        <?php foreach ($kandidatList as $k): ?>
          <?php
            $noUrutPad = str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT);
            $fotoName  = !empty($k['nama_file_foto']) ? basename($k['nama_file_foto']) : 'default-candidate.png';
            $fotoPath  = 'assets/uploads/' . $fotoName;
            if (!file_exists(__DIR__ . '/' . $fotoPath)) {
                $fotoPath = 'assets/uploads/default-candidate.png';
            }
            $namaPaslon = $k['nama_ketua'] . (!empty($k['nama_wakil']) ? ' & ' . $k['nama_wakil'] : '');
          ?>
          <article class="candidate-card">
            <div class="candidate-num-bar">
              <span>KANDIDAT</span>
              <span class="mono">NO. <?= e($noUrutPad) ?></span>
            </div>
            <div class="candidate-photo-wrap">
              <img src="<?= e($fotoPath) ?>" alt="Foto <?= e($namaPaslon) ?>">
            </div>
            <div class="candidate-body">
              <h2 class="candidate-name"><?= e($k['nama_ketua']) ?></h2>
              <?php if (!empty($k['nama_wakil'])): ?>
                <p class="candidate-deputy">Wakil: <?= e($k['nama_wakil']) ?></p>
              <?php else: ?>
                <p class="candidate-deputy">Calon Ketua OSIS</p>
              <?php endif; ?>
            </div>
            <div class="candidate-actions">
              <button
                type="button"
                class="btn btn-block js-open-visimisi"
                data-no-urut="<?= e($noUrutPad) ?>"
                data-nama="<?= e($namaPaslon) ?>"
                data-visi="<?= e($k['visi']) ?>"
                data-misi="<?= e($k['misi']) ?>"
              >
                Visi &amp; Misi
              </button>
              <button
                type="button"
                class="btn btn-primary btn-block js-open-confirm"
                data-id="<?= (int)$k['id'] ?>"
                data-no-urut="<?= e((string)$k['no_urut']) ?>"
                data-nama="<?= e($namaPaslon) ?>"
              >
                Pilih
              </button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Modal Visi & Misi (FR-04) -->
  <div id="modal-visi-misi" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-box">
      <div class="modal-header">
        <span>Kandidat No. <span id="vm-nomor" class="mono"></span> &mdash; <span id="vm-nama"></span></span>
        <button type="button" class="btn btn-sm js-close-modal">Tutup</button>
      </div>
      <div class="modal-body">
        <div style="margin-bottom: 16px;">
          <div class="modal-section-title">Visi</div>
          <div id="vm-visi"></div>
        </div>
        <div>
          <div class="modal-section-title">Misi</div>
          <div id="vm-misi"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary js-close-modal">Mengerti</button>
      </div>
    </div>
  </div>

  <!-- Modal Konfirmasi Pilihan (FR-05) -->
  <div id="modal-confirm" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width: 430px;">
      <div class="modal-header">
        <span>Konfirmasi Pilihan Suara</span>
      </div>
      <div class="modal-body" style="white-space: normal;">
        <p style="margin-bottom: 10px;">
          Apakah Anda yakin memilih <strong>Kandidat No. <span id="conf-nomor"></span></strong>?
        </p>
        <p class="text-muted" style="font-size: 13px;">
          Pasangan / Kandidat: <strong id="conf-nama" style="color: var(--text-main);"></strong>
        </p>
        <p class="text-muted" style="font-size: 12px; margin-top: 12px;">
          Pilihan yang sudah dikirim bersifat rahasia dan tidak dapat diubah kembali.
        </p>
      </div>
      <form id="form-vote" method="post" action="proses_vote.php" class="modal-footer">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" id="conf-id-kandidat" name="id_kandidat" value="">
        <button type="button" class="btn js-close-modal">Batal</button>
        <button type="submit" id="btn-submit-vote" class="btn btn-primary">Ya, Kirim Suara</button>
      </form>
    </div>
  </div>

  <script src="assets/js/bilik-suara.js"></script>
</body>
</html>
