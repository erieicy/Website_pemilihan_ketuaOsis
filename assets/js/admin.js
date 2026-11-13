/**
 * admin.js - Toggle Sidebar Responsif, Live Preview Upload Foto, & Interaktivitas Admin
 */

(function () {
  'use strict';

  // 0. Buka/Tutup Sidebar (Laptop: Kiri ke Kanan | HP/Tablet: Atas ke Bawah)
  const sidebarBrand = document.querySelector('.sidebar-brand');
  const pageHeader   = document.querySelector('.page-header');

  if (sidebarBrand) {
    // Bungkus teks judul brand jika belum dibungkus agar rapi sejajar dengan tombol toggle
    if (!sidebarBrand.querySelector('.sidebar-brand-text')) {
      const textWrap = document.createElement('div');
      textWrap.className = 'sidebar-brand-text';
      while (sidebarBrand.firstChild) {
        textWrap.appendChild(sidebarBrand.firstChild);
      }
      sidebarBrand.appendChild(textWrap);
    }

    // Buat tombol tutup/toggle di dalam header sidebar
    let toggleBtn = document.getElementById('btn-sidebar-toggle');
    if (!toggleBtn) {
      toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.id = 'btn-sidebar-toggle';
      toggleBtn.className = 'btn sidebar-toggle-btn';
      sidebarBrand.appendChild(toggleBtn);
    }

    // Buat tombol buka sidebar di halaman utama (untuk layar Laptop saat sidebar ditutup)
    let openBtn = document.getElementById('btn-sidebar-open');
    if (!openBtn && pageHeader) {
      openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.id = 'btn-sidebar-open';
      openBtn.className = 'btn sidebar-open-btn';
      openBtn.innerHTML = '&#9776;';
      openBtn.title = 'Buka Menu';

      const firstDiv = pageHeader.querySelector('div');
      if (firstDiv) {
        const leftWrap = document.createElement('div');
        leftWrap.className = 'page-header-left';
        pageHeader.insertBefore(leftWrap, firstDiv);
        leftWrap.appendChild(openBtn);
        leftWrap.appendChild(firstDiv);
      } else {
        pageHeader.prepend(openBtn);
      }
    }

    function isMobileView() {
      return window.innerWidth <= 820;
    }

    function syncButtonLabel() {
      if (isMobileView()) {
        const isOpen = document.body.classList.contains('mobile-nav-open');
        toggleBtn.innerHTML = isOpen ? '&#10005;' : '&#9776;';
        toggleBtn.title = isOpen ? 'Tutup Menu' : 'Buka Menu';
      } else {
        toggleBtn.innerHTML = '&#10005;';
        toggleBtn.title = 'Tutup Menu';
      }
    }

    // Pulihkan status buka/tutup sidebar di Laptop dari penyimpanan browser
    try {
      if (!isMobileView() && localStorage.getItem('evoting_sidebar_closed') === '1') {
        document.body.classList.add('sidebar-closed');
      }
    } catch (_) {}

    syncButtonLabel();

    toggleBtn.addEventListener('click', function () {
      if (isMobileView()) {
        // HP / Tablet: Buka & tutup dari atas ke bawah
        document.body.classList.toggle('mobile-nav-open');
      } else {
        // Laptop: Tutup ke kiri
        document.body.classList.add('sidebar-closed');
        try { localStorage.setItem('evoting_sidebar_closed', '1'); } catch (_) {}
      }
      syncButtonLabel();
    });

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        // Laptop: Buka dari kiri ke kanan
        document.body.classList.remove('sidebar-closed');
        try { localStorage.setItem('evoting_sidebar_closed', '0'); } catch (_) {}
        syncButtonLabel();
      });
    }

    window.addEventListener('resize', syncButtonLabel);
  }

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
