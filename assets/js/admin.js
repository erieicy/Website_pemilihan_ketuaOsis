/**
 * admin.js - Live Preview Upload Foto, Slug Otomatis, & Interaktivitas Admin
 */

(function () {
  'use strict';

  // 1. Live Image Preview untuk Upload Foto Kandidat
  const fileInput   = document.getElementById('input-foto');
  const previewImg  = document.getElementById('preview-image');
  const previewMeta = document.getElementById('preview-meta');

  if (fileInput && previewImg) {
    fileInput.addEventListener('change', function () {
      const file = fileInput.files && fileInput.files[0];
      if (!file) return;

      const maxBytes = 2 * 1024 * 1024;
      if (file.size > maxBytes) {
        alert('Ukuran file melebihi batas maksimal 2 MB.');
        fileInput.value = '';
        return;
      }

      const reader = new FileReader();
      reader.onload = function (e) {
        previewImg.src = e.target.result;
        if (previewMeta) {
          const kb = Math.round(file.size / 1024);
          previewMeta.textContent = file.name + ' (' + kb + ' KB)';
        }
      };
      reader.readAsDataURL(file);
    });
  }

  // 2. Placeholder Slug Nama File Otomatis Berdasarkan Nomor Urut & Nama Ketua
  const inputNoUrut = document.getElementById('no_urut');
  const inputKetua  = document.getElementById('nama_ketua');
  const inputCustom = document.getElementById('nama_file_custom');

  function updateSlugPlaceholder() {
    if (!inputNoUrut || !inputKetua || !inputCustom) return;
    const no   = String(inputNoUrut.value || '1').padStart(2, '0');
    const slug = (inputKetua.value || 'nama-kandidat')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
    inputCustom.placeholder = 'Contoh: paslon-' + no + '-' + (slug || 'kandidat');
  }

  if (inputNoUrut && inputKetua && inputCustom) {
    inputNoUrut.addEventListener('input', updateSlugPlaceholder);
    inputKetua.addEventListener('input', updateSlugPlaceholder);
    updateSlugPlaceholder();
  }

  // 3. Pratinjau Instan Perubahan Tema (White / Dark) di Halaman Pengaturan
  const themeInputs = document.querySelectorAll('input[name="tema"], select[name="tema"]');
  themeInputs.forEach(function (el) {
    el.addEventListener('change', function () {
      if (el.value === 'dark' || el.value === 'light') {
        document.documentElement.setAttribute('data-theme', el.value);
      }
    });
  });

  // 4. Konfirmasi Aksi Hapus Umum
  document.querySelectorAll('.js-confirm').forEach(function (el) {
    el.addEventListener('click', function (e) {
      const msg = el.dataset.confirm || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  });
})();
