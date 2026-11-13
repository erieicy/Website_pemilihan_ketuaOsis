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

  // 4. Konfirmasi Aksi Hapus Umum (Event Delegation untuk mendukung elemen dinamis real-time)
  document.addEventListener('click', function (e) {
    const el = e.target.closest ? e.target.closest('.js-confirm') : null;
    if (!el) return;
    const msg = el.dataset.confirm || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
    if (!window.confirm(msg)) {
      e.preventDefault();
    }
  });

  // 5. Sinkronisasi Real-Time (Polling + BroadcastChannel antar tab browser)
  window.evotingBroadcastUpdate = function (type) {
    try {
      if (window.BroadcastChannel) {
        const ch = new BroadcastChannel('evoting_updates');
        ch.postMessage({ type: type || 'data_changed', ts: Date.now() });
        ch.close();
      }
      localStorage.setItem('evoting_last_update', String(Date.now()));
    } catch (_) {}
  };

  window.evotingUpdateChartMetrics = function (container, items) {
    if (!container || !Array.isArray(items)) return;
    items.forEach(function (item) {
      const card = container.querySelector('.chart-item[data-kandidat-id="' + item.id + '"]');
      if (!card) return;
      const votesEl = card.querySelector('.chart-votes');
      const pctEl   = card.querySelector('.chart-percent');
      const fillEl  = card.querySelector('.chart-fill');
      if (votesEl) votesEl.textContent = item.votes_lbl || (item.suara_fmt + ' Suara');
      if (pctEl) pctEl.textContent = item.persen_fmt;
      if (fillEl) fillEl.style.width = item.persen_val + '%';
    });
  };

  window.evotingStartLivePolling = function (options) {
    if (!options || typeof options.onData !== 'function') return;
    const intervalMs = options.intervalMs || 1500;
    const statusEl   = document.getElementById(options.statusElId || 'live-status');
    let timerId      = null;
    let inFlight     = false;
    let queuedRun    = false;
    let stopped      = false;

    function setLiveStatus(online) {
      if (!statusEl) return;
      statusEl.classList.toggle('is-offline', !online);
      const txtEl = statusEl.querySelector('.live-label');
      if (txtEl) {
        txtEl.textContent = online ? 'Live Otomatis' : 'Menyambung...';
      }
    }

    function scheduleNext() {
      if (stopped || document.hidden) return;
      clearTimeout(timerId);
      timerId = setTimeout(pollNow, intervalMs);
    }

    function buildUrl() {
      const base = typeof options.getUrl === 'function' ? options.getUrl() : options.url;
      const sep  = base.indexOf('?') === -1 ? '?' : '&';
      return base + sep + '_t=' + Date.now();
    }

    function pollNow() {
      if (stopped || document.hidden) return;
      if (inFlight) {
        queuedRun = true;
        return;
      }
      inFlight = true;
      fetch(buildUrl(), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) {
          if (res.status === 401) {
            stopped = true;
            window.location.href = 'login.php';
            return null;
          }
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const ct = res.headers.get('content-type') || '';
          if (ct.indexOf('application/json') === -1) throw new Error('Invalid JSON');
          return res.json();
        })
        .then(function (data) {
          if (!data || stopped) return;
          setLiveStatus(true);
          options.onData(data);
        })
        .catch(function () {
          if (!stopped) setLiveStatus(false);
        })
        .finally(function () {
          inFlight = false;
          if (stopped) return;
          if (queuedRun && !document.hidden) {
            queuedRun = false;
            pollNow();
          } else {
            scheduleNext();
          }
        });
    }

    function triggerImmediate() {
      if (stopped || document.hidden) return;
      clearTimeout(timerId);
      pollNow();
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) triggerImmediate();
    });
    window.addEventListener('focus', triggerImmediate);

    try {
      if (window.BroadcastChannel) {
        const ch = new BroadcastChannel('evoting_updates');
        ch.addEventListener('message', triggerImmediate);
      }
    } catch (_) {}

    window.addEventListener('storage', function (e) {
      if (e.key === 'evoting_last_update') triggerImmediate();
    });

    scheduleNext();
  };

  if (document.querySelector('[data-broadcast-update="1"]')) {
    window.evotingBroadcastUpdate('admin_action');
  }
})();
