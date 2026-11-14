/**
 * bilik-suara.js - Interaktivitas Modal Visi-Misi, Konfirmasi Pilih, & Auto-Redirect
 */

(function () {
  'use strict';

  const modalVisiMisi = document.getElementById('modal-visi-misi');
  const modalConfirm  = document.getElementById('modal-confirm');

  let pendingReload = false;
  let isSubmitting  = false;
  let redirecting   = false;

  function applyPendingReloadIfIdle() {
    if (pendingReload && !isSubmitting && !redirecting && !document.querySelector('.modal-backdrop.is-open')) {
      redirecting = true;
      window.location.reload();
    }
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    applyPendingReloadIfIdle();
  }

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
      closeModal(btn.closest('.modal-backdrop'));
    });
  });

  document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal(backdrop);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.is-open').forEach(function (m) {
        closeModal(m);
      });
    }
  });

  // Cegah klik ganda saat submit suara
  const formVote = document.getElementById('form-vote');
  if (formVote) {
    formVote.addEventListener('submit', function () {
      isSubmitting = true;
      const submitBtn = document.getElementById('btn-submit-vote');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses...';
      }
    });
  }

  // Sinkronisasi Real-Time Bilik Suara (tema, status voting, validitas token, & perubahan kandidat)
  const isBoothPage = document.body.hasAttribute('data-kandidat-sig') || Boolean(document.querySelector('.candidate-grid'));
  if (isBoothPage) {
    const initialKandidatSig = document.body.getAttribute('data-kandidat-sig') || '';
    let pollTimer      = null;
    let inFlight       = false;
    let queuedCheck    = false;
    let fetchStartedAt = 0;

    function scheduleNextPoll() {
      if (document.hidden || isSubmitting || redirecting) return;
      clearTimeout(pollTimer);
      pollTimer = setTimeout(checkBoothStatus, 3000);
    }

    function checkBoothStatus() {
      if (document.hidden || isSubmitting || redirecting) return;
      if (inFlight) {
        if (Date.now() - fetchStartedAt > 100) {
          queuedCheck = true;
        }
        return;
      }
      inFlight       = true;
      fetchStartedAt = Date.now();
      fetch('index.php?status_check=1&_t=' + fetchStartedAt, { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) {
          if (r.status === 503) {
            return r.json().catch(function () { return null; }).then(function (errData) {
              if (errData && errData.error === 'uninstalled') {
                redirecting = true;
                window.location.href = 'index.php';
              }
              return null;
            });
          }
          return r.ok ? r.json() : null;
        })
        .then(function (d) {
          if (!d || isSubmitting || redirecting) return;
          if (d.tema) {
            document.documentElement.setAttribute('data-theme', d.tema);
          }
          if ((d.status_voting && d.status_voting !== 'buka') || d.token_valid === false) {
            redirecting = true;
            window.location.href = 'index.php';
            return;
          }
          if (d.kandidat_sig && d.kandidat_sig !== initialKandidatSig) {
            if (!document.querySelector('.modal-backdrop.is-open')) {
              redirecting = true;
              window.location.reload();
            } else {
              pendingReload = true;
            }
          } else if (d.kandidat_sig && d.kandidat_sig === initialKandidatSig) {
            pendingReload = false;
          }
        })
        .catch(function () {})
        .finally(function () {
          inFlight = false;
          if (isSubmitting || redirecting) return;
          if (queuedCheck && !document.hidden) {
            queuedCheck = false;
            checkBoothStatus();
          } else {
            scheduleNextPoll();
          }
        });
    }

    function triggerImmediateCheck() {
      if (document.hidden || isSubmitting || redirecting) return;
      clearTimeout(pollTimer);
      checkBoothStatus();
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        triggerImmediateCheck();
      }
    });

    try {
      if (window.BroadcastChannel) {
        const ch = new BroadcastChannel('evoting_updates');
        ch.addEventListener('message', triggerImmediateCheck);
      }
    } catch (_) {}

    window.addEventListener('storage', function (e) {
      if (e.key === 'evoting_last_update') {
        triggerImmediateCheck();
      }
    });

    scheduleNextPoll();
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
