<?php
/**
 * admin/kandidat.php - CRUD Kandidat & Upload Foto dengan Penamaan Kustom (FR-08)
 * Sistem E-Voting Pemilihan Ketua OSIS (LAN)
 */

require_once dirname(__DIR__) . '/config/database.php';
require_admin();

$pengaturan = get_pengaturan($pdo);
$uploadDir  = dirname(__DIR__) . '/assets/uploads/';

$message = $_SESSION['flash_kandidat_msg'] ?? '';
$error   = $_SESSION['flash_kandidat_err'] ?? '';
unset($_SESSION['flash_kandidat_msg'], $_SESSION['flash_kandidat_err']);

$failedFormData   = null;
$failedCustomName = null;

/**
 * Membersihkan string menjadi slug nama file yang aman (tanpa ekstensi)
 */
function sanitize_filename_slug(string $raw): string {
    $clean = strtolower(trim($raw));
    $clean = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $clean);
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '-', $clean);
    $clean = preg_replace('/-+/', '-', $clean);
    return trim($clean, '-_');
}

/**
 * Menyusun slug nama file otomatis dari nomor urut dan nama ketua
 */
function build_candidate_auto_slug(int $noUrut, string $namaKetua): string {
    $noPad     = str_pad((string)$noUrut, 2, '0', STR_PAD_LEFT);
    $ketuaSlug = sanitize_filename_slug($namaKetua);
    $slug      = $ketuaSlug !== '' ? ('paslon-' . $noPad . '-' . $ketuaSlug) : ('paslon-' . $noPad);
    if ($slug === '' || $slug === 'default-candidate' || $slug === 'paslon-' . $noPad . '-') {
        return 'paslon-' . $noPad;
    }
    return $slug;
}

/**
 * Menghapus file foto lama secara aman (kecuali default-candidate.png, .gitkeep, .htaccess)
 */
function delete_old_photo(string $uploadDir, ?string $filename): void {
    if (empty($filename)) {
        return;
    }
    $base = basename($filename);
    if (in_array($base, ['default-candidate.png', '.gitkeep', '.htaccess'], true)) {
        return;
    }
    $fullPath = $uploadDir . $base;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// Tangani kondisi jika ukuran upload melampaui batas post_max_size PHP (BUG-FUN-10.5)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'Ukuran file yang diunggah terlalu besar dan melebihi batas server (maksimal 2 MB).';
}

// Proses Hapus Kandidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_kandidat_err'] = 'Token keamanan tidak valid.';
    } else {
        $idHapus = (int)($_POST['id'] ?? 0);
        $stmtOld = $pdo->prepare('SELECT nama_file_foto, jumlah_suara, nama_ketua FROM kandidat WHERE id = ? LIMIT 1');
        $stmtOld->execute([$idHapus]);
        $rowOld = $stmtOld->fetch();

        if ($rowOld) {
            if ((int)$rowOld['jumlah_suara'] > 0) {
                $_SESSION['flash_kandidat_err'] = 'Kandidat "' . $rowOld['nama_ketua'] . '" tidak dapat dihapus karena sudah memiliki ' . (int)$rowOld['jumlah_suara'] . ' suara sah. Gunakan Reset Data Simulasi di menu Pengaturan jika ingin mengulang pemilihan.';
            } else {
                delete_old_photo($uploadDir, $rowOld['nama_file_foto']);
                $stmtDel = $pdo->prepare('DELETE FROM kandidat WHERE id = ?');
                $stmtDel->execute([$idHapus]);
                $_SESSION['flash_kandidat_msg'] = 'Data kandidat "' . $rowOld['nama_ketua'] . '" beserta file fotonya berhasil dihapus.';
            }
        }
    }
    header('Location: kandidat.php');
    exit;
}

// Proses Tambah / Edit Kandidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $idEdit     = (int)($_POST['id'] ?? 0);
    $noUrut     = (int)($_POST['no_urut'] ?? 1);
    $namaKetua  = trim($_POST['nama_ketua'] ?? '');
    $namaWakil  = trim($_POST['nama_wakil'] ?? '');
    $namaCustom = trim($_POST['nama_file_custom'] ?? '');
    $visi       = trim($_POST['visi'] ?? '');
    $misi       = trim($_POST['misi'] ?? '');

    // Ambil data lama jika mode edit
    $existing = null;
    if ($idEdit > 0) {
        $stmtEx = $pdo->prepare('SELECT * FROM kandidat WHERE id = ? LIMIT 1');
        $stmtEx->execute([$idEdit]);
        $existing = $stmtEx->fetch() ?: null;
    }

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } elseif ($idEdit > 0 && !$existing) {
        $error = 'Data kandidat yang akan diedit tidak ditemukan.';
    } elseif ($noUrut <= 0 || $noUrut > 99 || $namaKetua === '' || $visi === '' || $misi === '') {
        $error = 'Nomor urut (1–99), Nama Calon Ketua, Visi, dan Misi wajib diisi.';
    } elseif (mb_strlen($namaKetua) > 100 || mb_strlen($namaWakil) > 100 || mb_strlen($namaCustom) > 100) {
        // Cegah silent truncation / HTTP 500 (BUG-FUN-05)
        $error = 'Nama Calon Ketua, Wakil, dan Nama File Kustom maksimal 100 karakter.';
    } elseif (mb_strlen($visi) > 10000 || mb_strlen($misi) > 10000) {
        $error = 'Visi dan Misi masing-masing maksimal 10.000 karakter.';
    } else {
        // Pastikan Nomor Urut Kandidat Unik (BUG-FUN-03)
        $stmtDup = $pdo->prepare('SELECT id FROM kandidat WHERE no_urut = ? AND id != ? LIMIT 1');
        $stmtDup->execute([$noUrut, $idEdit]);
        if ($stmtDup->fetch()) {
            $error = "Nomor urut {$noUrut} sudah digunakan oleh kandidat lain. Gunakan nomor urut yang berbeda.";
        }
    }

    if ($error === '') {
        $finalFileName   = $existing ? $existing['nama_file_foto'] : 'default-candidate.png';
        $uploadedNewFile = null;
        $renamedFromFile = null;
        $renamedToFile   = null;

        // Tentukan basis nama file (dari input custom atau auto-generate no_urut + nama_ketua)
        $defaultSlug = build_candidate_auto_slug($noUrut, $namaKetua);
        $baseSlug    = $namaCustom !== '' ? sanitize_filename_slug($namaCustom) : $defaultSlug;
        // Cegah penamaan yang menimpa default-candidate.png (BUG-FUN-04)
        if ($baseSlug === '' || $baseSlug === 'default-candidate') {
            $baseSlug = 'paslon-' . str_pad((string)$noUrut, 2, '0', STR_PAD_LEFT);
        }

        // Cek apakah ada unggahan file foto baru
        if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['foto'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $error = 'Ukuran file foto melebihi batas maksimal 2 MB.';
                } else {
                    $error = 'Terjadi kesalahan saat mengunggah file foto.';
                }
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'Ukuran file foto melebihi batas maksimal 2 MB.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);
                $imgInfo  = @getimagesize($file['tmp_name']);

                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];

                if (!in_array($ext, $allowedExts, true) || !isset($allowedMimes[$mimeType]) || $imgInfo === false) {
                    $error = 'Format file tidak valid. Gunakan gambar asli berformat JPG, JPEG, PNG, atau WEBP.';
                } else {
                    // Pastikan ekstensi mengikuti MIME asli gambar (BUG-FUN-04)
                    $safeExt    = ($allowedMimes[$mimeType] === 'jpg' && $ext === 'jpeg') ? 'jpeg' : $allowedMimes[$mimeType];
                    $targetName = $baseSlug . '.' . $safeExt;

                    // Hindari tabrakan nama file dengan kandidat lain atau file bawaan
                    if (
                        $targetName === 'default-candidate.png' ||
                        (file_exists($uploadDir . $targetName) && (!$existing || $existing['nama_file_foto'] !== $targetName))
                    ) {
                        $targetName = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $safeExt;
                    }

                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $targetName)) {
                        $error = 'Gagal menyimpan file foto ke folder assets/uploads/.';
                    } else {
                        $uploadedNewFile = $targetName;
                        $finalFileName   = $targetName;
                    }
                }
            }
        } elseif ($existing && $existing['nama_file_foto'] !== 'default-candidate.png') {
            // Jika mode edit tanpa upload foto baru, perbarui nama file foto mengikuti nama kustom atau slug otomatis
            $currentFile = basename($existing['nama_file_foto']);
            $currentExt  = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
            $newTarget   = $baseSlug . '.' . $currentExt;
            if ($newTarget !== $currentFile && is_file($uploadDir . $currentFile)) {
                if ($newTarget === 'default-candidate.png' || file_exists($uploadDir . $newTarget)) {
                    $newTarget = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $currentExt;
                }
                if (@rename($uploadDir . $currentFile, $uploadDir . $newTarget)) {
                    $renamedFromFile = $currentFile;
                    $renamedToFile   = $newTarget;
                    $finalFileName   = $newTarget;
                }
            }
        }

        if ($error === '') {
            try {
                if ($idEdit > 0 && $existing) {
                    $stmtUpd = $pdo->prepare('
                        UPDATE kandidat
                        SET no_urut = ?, nama_ketua = ?, nama_wakil = ?, nama_file_foto = ?, visi = ?, misi = ?
                        WHERE id = ?
                    ');
                    $stmtUpd->execute([$noUrut, $namaKetua, $namaWakil, $finalFileName, $visi, $misi, $idEdit]);
                    if ($uploadedNewFile !== null && $existing['nama_file_foto'] !== $uploadedNewFile) {
                        delete_old_photo($uploadDir, $existing['nama_file_foto']);
                    }
                    $_SESSION['flash_kandidat_msg'] = 'Data kandidat berhasil diperbarui.';
                } else {
                    $stmtIns = $pdo->prepare('
                        INSERT INTO kandidat (no_urut, nama_ketua, nama_wakil, nama_file_foto, visi, misi, jumlah_suara)
                        VALUES (?, ?, ?, ?, ?, ?, 0)
                    ');
                    $stmtIns->execute([$noUrut, $namaKetua, $namaWakil, $finalFileName, $visi, $misi]);
                    $_SESSION['flash_kandidat_msg'] = 'Kandidat baru berhasil ditambahkan.';
                }
                header('Location: kandidat.php');
                exit;
            } catch (PDOException $e) {
                error_log('Kandidat save error: ' . $e->getMessage());
                if ($uploadedNewFile !== null && (!$existing || $existing['nama_file_foto'] !== $uploadedNewFile)) {
                    delete_old_photo($uploadDir, $uploadedNewFile);
                }
                if ($renamedFromFile !== null && $renamedToFile !== null && is_file($uploadDir . $renamedToFile)) {
                    @rename($uploadDir . $renamedToFile, $uploadDir . $renamedFromFile);
                }
                $error = ((string)$e->getCode() === '23000')
                    ? "Nomor urut {$noUrut} sudah digunakan oleh kandidat lain. Gunakan nomor urut yang berbeda."
                    : 'Gagal menyimpan data kandidat ke database. Silakan coba lagi.';
            }
        }
    }

    // Pertahankan nilai form & status mode edit jika validasi gagal (BUG-FUN-01)
    if ($error !== '') {
        $failedFormData = [
            'id'             => $idEdit,
            'no_urut'        => $noUrut,
            'nama_ketua'     => $namaKetua,
            'nama_wakil'     => $namaWakil,
            'nama_file_foto' => $existing['nama_file_foto'] ?? 'default-candidate.png',
            'visi'           => $visi,
            'misi'           => $misi,
        ];
        $failedCustomName = $namaCustom;
    }
}

// Ambil kandidat untuk mode edit (dari $_GET['edit'] atau pertahankan dari validasi gagal)
$editData   = null;
$isEditMode = false;

if ($failedFormData !== null) {
    $editData   = $failedFormData;
    $isEditMode = ((int)$failedFormData['id'] > 0);
} elseif (isset($_GET['edit'])) {
    $stmtEdit = $pdo->prepare('SELECT * FROM kandidat WHERE id = ? LIMIT 1');
    $stmtEdit->execute([(int)$_GET['edit']]);
    $editData   = $stmtEdit->fetch() ?: null;
    $isEditMode = ($editData !== null);
}

// Daftar seluruh kandidat
$stmtList     = $pdo->query('SELECT * FROM kandidat ORDER BY no_urut ASC, id ASC');
$kandidatList = $stmtList->fetchAll();

// Hitung nomor urut berikutnya secara otomatis untuk form tambah
$nextNoUrut = 1;
foreach ($kandidatList as $item) {
    if ((int)$item['no_urut'] >= $nextNoUrut) {
        $nextNoUrut = (int)$item['no_urut'] + 1;
    }
}

$previewFotoUrl = resolve_candidate_photo_url($editData['nama_file_foto'] ?? null, '../');
$customNameVal  = '';
if ($editData) {
    $f = !empty($editData['nama_file_foto']) ? basename($editData['nama_file_foto']) : 'default-candidate.png';
    if ($failedCustomName !== null) {
        $customNameVal = $failedCustomName;
    } elseif ($f !== 'default-candidate.png') {
        $autoSlug    = build_candidate_auto_slug((int)$editData['no_urut'], (string)$editData['nama_ketua']);
        $currentBase = pathinfo($f, PATHINFO_FILENAME);
        if ($currentBase !== $autoSlug) {
            $customNameVal = $currentBase;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= e($pengaturan['tema']) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Data Kandidat &mdash; <?= e($pengaturan['nama_sekolah']) ?></title>
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
          <a href="kandidat.php" class="nav-link active">Data Kandidat</a>
          <a href="token.php" class="nav-link">Manajemen Token</a>
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
          <h1>Manajemen Kandidat Ketua OSIS</h1>
          <p>Kelola biodata pasangan calon, visi-misi, dan unggah foto langsung melalui browser</p>
        </div>
        <?php if ($isEditMode): ?>
          <a href="kandidat.php" class="btn btn-sm">+ Tambah Kandidat Baru</a>
        <?php endif; ?>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success" data-broadcast-update="1"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <!-- Form Tambah / Edit Kandidat (FR-08) -->
      <div class="panel">
        <div class="panel-header">
          <span><?= $isEditMode ? 'Edit Kandidat No. Urut ' . (int)$editData['no_urut'] : 'Tambah Kandidat Baru' ?></span>
          <span class="text-muted" style="font-weight: 400; font-size: 12px;">Maksimal foto 2 MB (JPG, PNG, WEBP)</span>
        </div>
        <div class="panel-body">
          <form method="post" action="kandidat.php<?= $isEditMode ? '?edit=' . (int)$editData['id'] : '' ?>" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $isEditMode ? (int)$editData['id'] : 0 ?>">

            <div class="grid-2">
              <div>
                <div class="form-group">
                  <label class="form-label" for="no_urut">Nomor Urut Kandidat *</label>
                  <input
                    type="number"
                    id="no_urut"
                    name="no_urut"
                    class="form-control mono"
                    min="1"
                    max="99"
                    value="<?= e((string)($editData['no_urut'] ?? $nextNoUrut)) ?>"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="nama_ketua">Nama Calon Ketua OSIS *</label>
                  <input
                    type="text"
                    id="nama_ketua"
                    name="nama_ketua"
                    class="form-control"
                    maxlength="100"
                    placeholder="Contoh: Budi Santoso"
                    value="<?= e($editData['nama_ketua'] ?? '') ?>"
                    required
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="nama_wakil">Nama Calon Wakil Ketua (Opsional)</label>
                  <input
                    type="text"
                    id="nama_wakil"
                    name="nama_wakil"
                    class="form-control"
                    maxlength="100"
                    placeholder="Kosongkan jika pemilihan tunggal"
                    value="<?= e($editData['nama_wakil'] ?? '') ?>"
                  >
                </div>

                <div class="form-group">
                  <label class="form-label" for="input-foto">Unggah Foto Kandidat</label>
                  <input
                    type="file"
                    id="input-foto"
                    name="foto"
                    class="form-control"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                  >
                  <span class="form-hint">Jika dikosongkan saat tambah baru, otomatis memakai <code>default-candidate.png</code>.</span>
                </div>

                <div class="form-group">
                  <label class="form-label" for="nama_file_custom">Nama File Foto (Opsional / Kustom)</label>
                  <input
                    type="text"
                    id="nama_file_custom"
                    name="nama_file_custom"
                    class="form-control mono"
                    maxlength="100"
                    value="<?= e($customNameVal) ?>"
                    placeholder="Contoh: paslon-01-budi-santoso"
                  >
                  <span class="form-hint">Tanpa ekstensi. Jika dikosongkan, sistem otomatis menamai dari nomor urut &amp; nama ketua.</span>
                </div>
              </div>

              <div>
                <div class="form-group">
                  <label class="form-label">Pratinjau Foto (Live Preview)</label>
                  <div class="upload-preview-box">
                    <img id="preview-image" src="<?= e($previewFotoUrl) ?>" alt="Preview Foto">
                    <div>
                      <div id="preview-meta" class="mono" style="font-size: 13px; font-weight: 600;">
                        <?= e($editData['nama_file_foto'] ?? 'default-candidate.png') ?>
                      </div>
                      <span class="form-hint">Disimpan di <code>assets/uploads/</code></span>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="visi">Visi Kandidat *</label>
                  <textarea
                    id="visi"
                    name="visi"
                    class="form-control"
                    placeholder="Tuliskan visi kandidat..."
                    required
                  ><?= e($editData['visi'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                  <label class="form-label" for="misi">Misi Kandidat *</label>
                  <textarea
                    id="misi"
                    name="misi"
                    class="form-control"
                    placeholder="Tuliskan poin-poin misi kandidat..."
                    required
                  ><?= e($editData['misi'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px;">
              <?php if ($isEditMode): ?>
                <a href="kandidat.php" class="btn">Batal Edit</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary">
                <?= $isEditMode ? 'Simpan Perubahan Kandidat' : 'Simpan Kandidat Baru' ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Tabel Daftar Kandidat -->
      <div class="panel">
        <div class="panel-header">
          <span>Daftar Kandidat Terdaftar (<?= count($kandidatList) ?>)</span>
        </div>
        <div class="panel-body" style="padding: 0;">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th style="width: 70px;">No.</th>
                  <th style="width: 70px;">Foto</th>
                  <th>Pasangan Kandidat</th>
                  <th>File Foto</th>
                  <th>Visi &amp; Misi</th>
                  <th style="width: 150px;">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($kandidatList)): ?>
                  <tr>
                    <td colspan="6" class="text-muted" style="text-align: center; padding: 24px;">
                      Belum ada kandidat yang ditambahkan.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($kandidatList as $k): ?>
                    <?php
                      $fName    = !empty($k['nama_file_foto']) ? basename($k['nama_file_foto']) : 'default-candidate.png';
                      $fUrl     = resolve_candidate_photo_url($k['nama_file_foto'] ?? null, '../');
                      $hasVotes = ((int)$k['jumlah_suara'] > 0);
                    ?>
                    <tr>
                      <td class="mono" style="font-weight: 700;">
                        <?= str_pad((string)$k['no_urut'], 2, '0', STR_PAD_LEFT) ?>
                      </td>
                      <td>
                        <img src="<?= e($fUrl) ?>" alt="Foto" class="table-thumb">
                      </td>
                      <td>
                        <div style="font-weight: 700;"><?= e($k['nama_ketua']) ?></div>
                        <?php if (!empty($k['nama_wakil'])): ?>
                          <div class="text-muted" style="font-size: 12px;">Wakil: <?= e($k['nama_wakil']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="mono" style="font-size: 12px;"><?= e($fName) ?></td>
                      <td style="font-size: 13px; max-width: 300px;">
                        <div style="margin-bottom: 4px;"><strong>Visi:</strong> <?= e(mb_strimwidth($k['visi'], 0, 80, '...')) ?></div>
                        <div class="text-muted"><strong>Misi:</strong> <?= e(mb_strimwidth($k['misi'], 0, 80, '...')) ?></div>
                      </td>
                      <td>
                        <div style="display: flex; gap: 6px; align-items: center;">
                          <a href="kandidat.php?edit=<?= (int)$k['id'] ?>" class="btn btn-sm">Edit</a>
                          <?php if ($hasVotes): ?>
                            <button
                              type="button"
                              class="btn btn-sm btn-danger"
                              disabled
                              title="Tidak dapat dihapus karena sudah memiliki suara sah"
                            >
                              Hapus
                            </button>
                          <?php else: ?>
                            <form method="post" action="kandidat.php" style="display: inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                              <button
                                type="submit"
                                class="btn btn-sm btn-danger js-confirm"
                                data-confirm="<?= e('Hapus kandidat ' . $k['nama_ketua'] . ' beserta file fotonya?') ?>"
                              >
                                Hapus
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/admin.js"></script>
</body>
</html>
