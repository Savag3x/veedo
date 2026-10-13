/* ============================================
 * VEEDO — Video Upload Progress (XHR + modal real-time)
 * ============================================
 * Auto-detectează formularele cu <input type="file" name="video">
 * și înlocuiește submit-ul nativ cu XHR + UI cu procentaj real.
 *
 * Fără dependențe. Self-contained (CSS inject la prima folosire).
 * ============================================ */
(function () {
    'use strict';

    // Constante
    const MIN_VIDEO_SIZE = 1024 * 1024; // 1 MB — sub asta facem submit normal (nu merită modal)

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        // Toate formularele care au upload de video
        document.querySelectorAll('form').forEach(form => {
            const videoInput = form.querySelector('input[type="file"][name="video"]');
            if (videoInput) {
                form.addEventListener('submit', handleSubmit);
            }
        });
    }

    function handleSubmit(e) {
        const form = e.target;
        const videoInput = form.querySelector('input[type="file"][name="video"]');
        if (!videoInput || !videoInput.files || videoInput.files.length === 0) return; // fără video → submit normal
        const file = videoInput.files[0];
        if (file.size < MIN_VIDEO_SIZE) return; // sub 1 MB → submit normal

        e.preventDefault();
        injectCssOnce();
        const modal = showModal(file);

        const xhr = new XMLHttpRequest();
        const fd  = new FormData(form);

        // Progress real pe upload (browser → server)
        xhr.upload.addEventListener('progress', evt => {
            if (!evt.lengthComputable) return;
            const pct = Math.min(100, Math.round(evt.loaded / evt.total * 100));
            updateProgress(modal, pct, evt.loaded, evt.total);
            if (pct >= 100) setProcessingMode(modal);
        });

        xhr.upload.addEventListener('error', () => onError(modal, 'Eroare upload. Verifică conexiunea.'));
        xhr.upload.addEventListener('abort', () => closeModal(modal));

        // Server a răspuns (compresia s-a terminat) — navigăm la URL-ul final
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 400) {
                // responseURL conține URL-ul după redirect (302 → /anunt/{slug})
                window.location.href = xhr.responseURL || form.action;
            } else {
                onError(modal, 'Eroare server (' + xhr.status + '). Încearcă din nou.');
            }
        });
        xhr.addEventListener('error', () => onError(modal, 'Eroare rețea. Încearcă din nou.'));
        xhr.addEventListener('timeout', () => onError(modal, 'Timeout — uploadul a durat prea mult.'));

        xhr.open(form.method || 'POST', form.action || window.location.href);
        xhr.timeout = 600000; // 10 min — pentru fișiere mari + compresie server
        xhr.send(fd);
    }

    // ============== UI =================

    function showModal(file) {
        const modal = document.createElement('div');
        modal.className = 'vup-modal';
        modal.innerHTML =
            '<div class="vup-backdrop"></div>' +
            '<div class="vup-card">' +
                '<div class="vup-icon">🎬</div>' +
                '<h3 class="vup-title">Se încarcă videoul...</h3>' +
                '<div class="vup-file"></div>' +
                '<div class="vup-bar"><div class="vup-bar-fill" style="width:0%"></div></div>' +
                '<div class="vup-stats">' +
                    '<span class="vup-pct">0%</span>' +
                    '<span class="vup-mb">0 KB / ' + formatSize(file.size) + '</span>' +
                '</div>' +
                '<div class="vup-hint">Nu închide pagina. După upload, compresia durează 30-90 secunde.</div>' +
            '</div>';
        modal.querySelector('.vup-file').textContent = file.name + ' · ' + formatSize(file.size);
        document.body.appendChild(modal);
        // Blochăm scroll pe body cât e deschis modal-ul
        document.body.style.overflow = 'hidden';
        return modal;
    }

    function updateProgress(modal, pct, loaded, total) {
        modal.querySelector('.vup-bar-fill').style.width = pct + '%';
        modal.querySelector('.vup-pct').textContent = pct + '%';
        modal.querySelector('.vup-mb').textContent = formatSize(loaded) + ' / ' + formatSize(total);
    }

    function setProcessingMode(modal) {
        if (modal.dataset.processing === '1') return; // o singură dată
        modal.dataset.processing = '1';
        modal.querySelector('.vup-icon').textContent = '⚙️';
        modal.querySelector('.vup-title').textContent = 'Compresie în curs...';
        modal.querySelector('.vup-hint').textContent =
            'Serverul comprimă videoul (poate dura 30-90 secunde, în funcție de mărime). Te rugăm așteaptă.';
        modal.querySelector('.vup-bar-fill').classList.add('indeterminate');
        modal.querySelector('.vup-stats').style.display = 'none';
    }

    function onError(modal, msg) {
        modal.querySelector('.vup-icon').textContent = '⚠️';
        modal.querySelector('.vup-title').textContent = 'A apărut o problemă';
        modal.querySelector('.vup-hint').textContent = msg;
        modal.querySelector('.vup-bar-fill').classList.remove('indeterminate');
        modal.querySelector('.vup-bar-fill').style.background = 'var(--danger, #ef4444)';
        modal.querySelector('.vup-stats').style.display = 'none';
        // Buton închidere
        const card = modal.querySelector('.vup-card');
        if (!card.querySelector('.vup-close')) {
            const btn = document.createElement('button');
            btn.className = 'vup-close';
            btn.textContent = 'Închide';
            btn.onclick = () => closeModal(modal);
            card.appendChild(btn);
        }
    }

    function closeModal(modal) {
        modal.remove();
        document.body.style.overflow = '';
    }

    // ============== Helpers =================

    function formatSize(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576)    return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024)       return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    let cssInjected = false;
    function injectCssOnce() {
        if (cssInjected) return;
        cssInjected = true;
        const css = `
.vup-modal {
    position: fixed; inset: 0; z-index: 100000;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.vup-backdrop {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.75);
    -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
}
.vup-card {
    position: relative;
    background: var(--bg-2, #ffffff);
    color: var(--text, #111);
    border-radius: 20px;
    padding: 32px 28px;
    max-width: 440px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
    border: 1px solid var(--border, rgba(0,0,0,0.1));
}
.vup-icon { font-size: 48px; margin-bottom: 8px; line-height: 1; }
.vup-title { font-size: 20px; margin: 0 0 6px; font-weight: 800; }
.vup-file { font-size: 13px; color: var(--text-3, #666); margin-bottom: 20px; word-break: break-all; line-height: 1.4; }
.vup-bar {
    height: 14px;
    background: var(--bg-3, #f0f0f0);
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 10px;
}
.vup-bar-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #FF8A4C, #E8380D, #C42409);
    border-radius: 8px;
    transition: width 0.2s ease-out;
}
.vup-bar-fill.indeterminate {
    width: 100% !important;
    animation: vup-pulse 1.4s ease-in-out infinite;
}
@keyframes vup-pulse {
    0%, 100% { opacity: 0.55; transform: scaleX(0.97); transform-origin: left; }
    50%      { opacity: 1.0;  transform: scaleX(1); }
}
.vup-stats {
    display: flex; justify-content: space-between;
    font-size: 13px; color: var(--text-3, #666); margin-bottom: 16px;
}
.vup-pct { font-weight: 800; color: var(--text, #111); font-size: 15px; }
.vup-hint {
    font-size: 12px; color: var(--text-3, #666);
    margin-top: 16px;
    padding: 10px 12px;
    background: var(--bg-3, #f0f0f0);
    border-radius: 10px;
    line-height: 1.5;
}
.vup-close {
    margin-top: 16px;
    padding: 10px 20px;
    background: var(--brand, #E8380D);
    color: #fff; border: none; border-radius: 10px;
    font-weight: 600; cursor: pointer; font-size: 14px;
}
.vup-close:hover { filter: brightness(1.1); }
`;
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }
})();
