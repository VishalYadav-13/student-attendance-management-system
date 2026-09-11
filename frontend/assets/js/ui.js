/**
 * SAMS - UI Components, Theme Engine & Interactivity
 */

const UI = {
  init() {
    this.initTheme();
    this.initMobileSidebar();
  },

  /* -------------------------------------------------- */
  /* Toast Notification System                          */
  /* -------------------------------------------------- */
  toast(message, type = 'info', duration = 3500) {
    let container = document.getElementById('sams-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'sams-toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };

    toast.innerHTML = `
      <span style="font-weight: bold; font-size: 1.1rem;">${icons[type] || 'ℹ'}</span>
      <span style="flex: 1;">${message}</span>
    `;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(15px)';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  },

  /* -------------------------------------------------- */
  /* Theme Toggle (Light / Dark)                        */
  /* -------------------------------------------------- */
  initTheme() {
    const savedTheme = localStorage.getItem('sams_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    this.updateThemeButtonIcon(savedTheme);

    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
      btn.addEventListener('click', () => this.toggleTheme());
    });
  },

  toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('sams_theme', next);
    this.updateThemeButtonIcon(next);
  },

  updateThemeButtonIcon(theme) {
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
      btn.innerHTML = theme === 'dark' 
        ? `<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>`
        : `<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
    });
  },

  /* -------------------------------------------------- */
  /* Responsive Mobile Sidebar Navigation               */
  /* -------------------------------------------------- */
  initMobileSidebar() {
    const mobileBtn = document.querySelector('.mobile-menu-btn');
    const sidebar = document.querySelector('.sidebar');

    if (mobileBtn && sidebar) {
      mobileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        sidebar.classList.toggle('open');
      });

      document.addEventListener('click', (e) => {
        if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !mobileBtn.contains(e.target)) {
          sidebar.classList.remove('open');
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
          sidebar.classList.remove('open');
        }
      });
    }
  },

  /* -------------------------------------------------- */
  /* Modal Helpers                                      */
  /* -------------------------------------------------- */
  openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('show');
    }
  },

  closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.remove('show');
    }
  },

  confirm(options = {}) {
    const title = options.title || 'Confirm Action';
    const message = options.message || 'Are you sure you want to proceed?';
    const confirmText = options.confirmText || 'Confirm';
    const isDanger = options.danger !== false;

    let modal = document.getElementById('sams-confirm-dialog');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'sams-confirm-dialog';
      modal.className = 'modal-backdrop';
      modal.innerHTML = `
        <div class="modal-card" style="max-width: 440px;">
          <div class="modal-header">
            <h3 id="confirm-dialog-title">${title}</h3>
            <button class="modal-close-btn" onclick="UI.closeModal('sams-confirm-dialog')">&times;</button>
          </div>
          <div class="modal-body">
            <p id="confirm-dialog-msg" style="color: var(--text-secondary); font-size: 0.95rem;">${message}</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" onclick="UI.closeModal('sams-confirm-dialog')">Cancel</button>
            <button id="confirm-dialog-btn" class="btn ${isDanger ? 'btn-danger' : 'btn-primary'}">${confirmText}</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    } else {
      document.getElementById('confirm-dialog-title').textContent = title;
      document.getElementById('confirm-dialog-msg').textContent = message;
      const btn = document.getElementById('confirm-dialog-btn');
      btn.textContent = confirmText;
      btn.className = `btn ${isDanger ? 'btn-danger' : 'btn-primary'}`;
    }

    const btn = document.getElementById('confirm-dialog-btn');
    btn.onclick = () => {
      UI.closeModal('sams-confirm-dialog');
      if (typeof options.onConfirm === 'function') {
        options.onConfirm();
      }
    };

    this.openModal('sams-confirm-dialog');
  },

  /* -------------------------------------------------- */
  /* Animated Number Counter                            */
  /* -------------------------------------------------- */
  animateCounter(el, target, duration = 800, suffix = '') {
    if (!el) return;
    const start = 0;
    const startTime = performance.now();

    function update(time) {
      const elapsed = time - startTime;
      const progress = Math.min(elapsed / duration, 1);
      // Ease-out expo
      const ease = 1 - Math.pow(1 - progress, 3);
      const current = Math.floor(start + (target - start) * ease);
      el.textContent = current + suffix;

      if (progress < 1) {
        requestAnimationFrame(update);
      } else {
        el.textContent = target + suffix;
      }
    }

    requestAnimationFrame(update);
  }
};

// Initialize UI behaviors on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => UI.init());
