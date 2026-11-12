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
 * Menghapus file foto lama secara aman (kecuali default-candidate.png)
 */
function delete_old_photo(string $uploadDir, ?string $filename): void {
    if (empty($filename)) {
        return;
    }
    $base = basename($filename);
    if ($base === 'default-candidate.png' || $base === '.gitkeep') {
        return;
    }
    $fullPath = $uploadDir . $base;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// Proses Hapus Kandidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $idHapus = (int)($_POST['id'] ?? 0);
        $stmtOld = $pdo->prepare('SELECT nama_file_foto FROM kandidat WHERE id = ? LIMIT 1');
        $stmtOld->execute([$idHapus]);
        $rowOld = $stmtOld->fetch();

        if ($rowOld) {
            delete_old_photo($uploadDir, $rowOld['nama_file_foto']);
            $stmtDel = $pdo->prepare('DELETE FROM kandidat WHERE id = ?');
            $stmtDel->execute([$idHapus]);
            $_SESSION['flash_kandidat_msg'] = 'Data kandidat dan file foto berhasil dihapus.';
            header('Location: kandidat.php');
            exit;
        }
    }
}

// Proses Tambah / Edit Kandidat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $idEdit     = (int)($_POST['id'] ?? 0);
        $noUrut     = (int)($_POST['no_urut'] ?? 1);
        $namaKetua  = trim($_POST['nama_ketua'] ?? '');
        $namaWakil  = trim($_POST['nama_wakil'] ?? '');
        $namaCustom = trim($_POST['nama_file_custom'] ?? '');
        $visi       = trim($_POST['visi'] ?? '');
        $misi       = trim($_POST['misi'] ?? '');

        if ($noUrut <= 0 || $namaKetua === '' || $visi === '' || $misi === '') {
            $error = 'Nomor urut, Nama Calon Ketua, Visi, dan Misi wajib diisi.';
        } else {
            // Ambil data lama jika mode edit
            $existing = null;
            if ($idEdit > 0) {
                $stmtEx = $pdo->prepare('SELECT * FROM kandidat WHERE id = ? LIMIT 1');
                $stmtEx->execute([$idEdit]);
                $existing = $stmtEx->fetch();
                if (!$existing) {
                    $error = 'Data kandidat yang akan diedit tidak ditemukan.';
                }
            }

            $finalFileName = $existing ? $existing['nama_file_foto'] : 'default-candidate.png';

            // Tentukan basis nama file (dari input custom atau auto-generate no_urut + nama_ketua)
            $defaultSlug = 'paslon-' . str_pad((string)$noUrut, 2, '0', STR_PAD_LEFT) . '-' . sanitize_filename_slug($namaKetua);
            $baseSlug    = $namaCustom !== '' ? sanitize_filename_slug($namaCustom) : $defaultSlug;
            if ($baseSlug === '') {
                $baseSlug = 'paslon-' . str_pad((string)$noUrut, 2, '0', STR_PAD_LEFT);
            }

            // Cek apakah ada unggahan file foto baru
            if ($error === '' && !empty($_FILES['foto']['name']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['foto'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Terjadi kesalahan saat mengunggah file foto.';
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
                        $safeExt = ($ext === 'jpeg') ? 'jpeg' : $allowedMimes[$mimeType];
                        $targetName = $baseSlug . '.' . $safeExt;

                        // Hindari tabrakan nama file dengan kandidat lain
                        if (file_exists($uploadDir . $targetName) && (!$existing || $existing['nama_file_foto'] !== $targetName)) {
                            $targetName = $baseSlug . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.' . $safeExt;
                        }

                        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $targetName)) {
                            $error = 'Gagal menyimpan file foto ke folder assets/uploads/.';
                        } else {
                            // Hapus file foto lama jika berbeda
                            if ($existing && $existing['nama_file_foto'] !== $targetName) {
                                delete_old_photo($uploadDir, $existing['nama_file_foto']);
                            }
                            $finalFileName = $targetName;
                        }
                    }
                }
            } elseif ($error === '' && $existing && $namaCustom !== '' && $existing['nama_file_foto'] !== 'default-candidate.png') {
                // Jika mode edit tanpa upload foto baru, tapi mengganti nama file kustom
                $currentFile = basename($existing['nama_file_foto']);
                $currentExt  = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
                $newTarget   = $baseSlug . '.' . $currentExt;
                if ($newTarget !== $currentFile && is_file($uploadDir . $currentFile) && !file_exists($uploadDir . $newTarget)) {
                    if (@rename($uploadDir . $currentFile, $uploadDir . $newTarget)) {
                        $finalFileName = $newTarget;
                    }
                }
            }

            if ($error === '') {
                if ($idEdit > 0 && $existing) {
                    $stmtUpd = $pdo->prepare('
                        UPDATE kandidat
                        SET no_urut = ?, nama_ketua = ?, nama_wakil = ?, nama_file_foto = ?, visi = ?, misi = ?
                        WHERE id = ?
                    ');
                    $stmtUpd->execute([$noUrut, $namaKetua, $namaWakil, $finalFileName, $visi, $misi, $idEdit]);
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
            }
        }
    }
}

// Ambil kandidat untuk mode edit jika ada parameter ?edit=ID
$editData = null;
if (isset($_GET['edit'])) {
    $stmtEdit = $pdo->prepare('SELECT * FROM kandidat WHERE id = ? LIMIT 1');
    $stmtEdit->execute([(int)$_GET['edit']]);
    $editData = $stmtEdit->fetch() ?: null;
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

$previewFotoUrl = '../assets/uploads/default-candidate.png';
$customNameVal  = '';
if ($editData) {
    $f = basename($editData['nama_file_foto']);
    if (is_file($uploadDir . $f)) {
        $previewFotoUrl = '../assets/uploads/' . $f;
    }
    if ($f !== 'default-candidate.png') {
        $customNameVal = pathinfo($f, PATHINFO_FILENAME);
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
          <a href="logout.php" class="btn btn-sm btn-danger">Keluar</a>
        </div>
      </div>
    </aside>

    <main class="admin-main">
      <div class="page-header">
        <div>
          <h1>Manajemen Kandidat Ketua OSIS</h1>
          <p>Kelola biodata pasangan calon, visi-misi, dan unggah foto langsung melalui browser</p>
        </div>
        <?php if ($editData): ?>
          <a href="kandidat.php" class="btn btn-sm">+ Mode Tambah Kandidat Baru</a>
        <?php endif; ?>
      </div>

      <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <!-- Form Tambah / Edit Kandidat (FR-08) -->
      <div class="panel">
        <div class="panel-header">
          <span><?= $editData ? 'Edit Kandidat No. Urut ' . (int)$editData['no_urut'] : 'Tambah Kandidat Baru' ?></span>
          <span class="text-muted" style="font-weight: 400; font-size: 12px;">Maksimal foto 2 MB (JPG, PNG, WEBP)</span>
        </div>
        <div class="panel-body">
          <form method="post" action="kandidat.php" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editData ? (int)$editData['id'] : 0 ?>">

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
              <?php if ($editData): ?>
                <a href="kandidat.php" class="btn">Batal Edit</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary">
                <?= $editData ? 'Simpan Perubahan Kandidat' : 'Simpan Kandidat Baru' ?>
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
                      $fName = !empty($k['nama_file_foto']) ? basename($k['nama_file_foto']) : 'default-candidate.png';
                      $fUrl  = '../assets/uploads/' . $fName;
                      if (!is_file($uploadDir . $fName)) {
                          $fUrl = '../assets/uploads/default-candidate.png';
                      }
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
                        <div style="display: flex; gap: 6px;">
                          <a href="kandidat.php?edit=<?= (int)$k['id'] ?>" class="btn btn-sm">Edit</a>
                          <form method="post" action="kandidat.php" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                            <button
                              type="submit"
                              class="btn btn-sm btn-danger js-confirm"
                              data-confirm="Hapus kandidat <?= e($k['nama_ketua']) ?> beserta file fotonya?"
                            >
                              Hapus
                            </button>
                          </form>
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
