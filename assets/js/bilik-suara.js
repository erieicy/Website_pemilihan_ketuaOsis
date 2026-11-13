/**
 * bilik-suara.js - Interaktivitas Modal Visi-Misi, Konfirmasi Pilih, & Auto-Redirect
 */

(function () {
  'use strict';

  const modalVisiMisi = document.getElementById('modal-visi-misi');
  const modalConfirm  = document.getElementById('modal-confirm');

  // Buka Modal Visi & Misi
  document.querySelectorAll('.js-open-visimisi').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!modalVisiMisi) return;
      document.getElementById('vm-nomor').textContent = btn.dataset.noUrut || '';
      document.getElementById('vm-nama').textContent  = btn.dataset.nama || '';
      document.getElementById('vm-visi').textContent  = btn.dataset.visi || '-';
      document.getElementById('vm-misi').textContent  = btn.dataset.misi || '-';
      modalVisiMisi.classList.add('is-open');
    });
  });

  // Buka Modal Konfirmasi Pilihan
  document.querySelectorAll('.js-open-confirm').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!modalConfirm) return;
      document.getElementById('conf-nomor').textContent = btn.dataset.noUrut || '';
      document.getElementById('conf-nama').textContent  = btn.dataset.nama || '';
      document.getElementById('conf-id-kandidat').value = btn.dataset.id || '';
      modalConfirm.classList.add('is-open');
    });
  });

  // Tutup Modal
  document.querySelectorAll('.js-close-modal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const target = btn.closest('.modal-backdrop');
      if (target) target.classList.remove('is-open');
    });
  });

  document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) backdrop.classList.remove('is-open');
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.is-open').forEach(function (m) {
        m.classList.remove('is-open');
      });
    }
  });

  // Cegah klik ganda saat submit suara
  const formVote = document.getElementById('form-vote');
  if (formVote) {
    formVote.addEventListener('submit', function () {
      const submitBtn = document.getElementById('btn-submit-vote');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses...';
      }
    });
  }

  // Timer Auto-Redirect 3 Detik, Notifikasi Real-Time Admin, & Nada Singkat setelah memilih
  const countdownEl = document.getElementById('redirect-countdown');
  if (countdownEl) {
    try {
      if (window.BroadcastChannel) {
        const ch = new BroadcastChannel('evoting_updates');
        ch.postMessage({ type: 'vote_recorded', ts: Date.now() });
        ch.close();
      }
      localStorage.setItem('evoting_last_update', String(Date.now()));
    } catch (_) {}

    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (AudioCtx) {
        const ctx  = new AudioCtx();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(660, ctx.currentTime);
        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.14);
      }
    } catch (_) {}

    let seconds = parseInt(countdownEl.textContent, 10) || 3;
    const timer = setInterval(function () {
      seconds -= 1;
      if (seconds <= 0) {
        clearInterval(timer);
        window.location.href = countdownEl.dataset.target || 'index.php';
      } else {
        countdownEl.textContent = String(seconds);
      }
    }, 1000);
  }
})();
