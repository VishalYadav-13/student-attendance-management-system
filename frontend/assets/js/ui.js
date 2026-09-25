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
    window.dispatchEvent(new CustomEvent('sams-theme-changed', { detail: { theme: next } }));
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

    if (!sidebar) return;

    // Create backdrop element if missing
    let backdrop = document.querySelector('.sidebar-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'sidebar-backdrop';
      document.body.appendChild(backdrop);
    }

    const openDrawer = () => {
      sidebar.classList.add('open');
      backdrop.classList.add('show');
      document.body.style.overflow = 'hidden';
    };

    const closeDrawer = () => {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      document.body.style.overflow = '';
    };

    if (mobileBtn) {
      mobileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (sidebar.classList.contains('open')) {
          closeDrawer();
        } else {
          openDrawer();
        }
      });
    }

    backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) {
        closeDrawer();
      }
    });
  },

  /* -------------------------------------------------- */
  /* Authenticated CSV File Downloader                  */
  /* -------------------------------------------------- */
  async downloadCsv(endpoint = '/api/reports/export-csv', defaultFilename = 'sams_report.csv') {
    try {
      this.toast('Preparing CSV download...', 'info', 2000);
      const token = (typeof API !== 'undefined' && API.getToken()) || localStorage.getItem('sams_token') || sessionStorage.getItem('sams_token');
      const baseUrl = (typeof API !== 'undefined' && API.getBaseUrl()) || (window.SAMS_CONFIG && window.SAMS_CONFIG.API_BASE_URL) || '';
      const fullUrl = endpoint.startsWith('http') ? endpoint : `${baseUrl}${endpoint}`;

      const headers = {};
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }

      const response = await fetch(fullUrl, { headers });
      if (!response.ok) {
        throw new Error(`Server returned HTTP ${response.status}`);
      }

      const blob = await response.blob();
      const downloadUrl = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = downloadUrl;
      a.download = defaultFilename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(downloadUrl);

      this.toast('CSV downloaded successfully!', 'success');
    } catch (err) {
      console.error('[SAMS CSV Export Error]', err);
      this.toast('Failed to download CSV: ' + err.message, 'error');
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
  /* Animated Number Counter (supports ints & decimals) */
  /* -------------------------------------------------- */
  animateCounter(el, target, duration = 800, suffix = '') {
    if (!el) return;
    const num = parseFloat(target);
    if (isNaN(num)) {
      el.textContent = target + suffix;
      return;
    }
    const isDecimal = String(target).includes('.');
    const decimalPlaces = isDecimal ? (String(target).split('.')[1]?.length || 1) : 0;

    const start = 0;
    const startTime = performance.now();

    function update(time) {
      const elapsed = time - startTime;
      const progress = Math.min(elapsed / duration, 1);
      // Ease-out cubic
      const ease = 1 - Math.pow(1 - progress, 3);
      const current = start + (num - start) * ease;
      el.textContent = (isDecimal ? current.toFixed(decimalPlaces) : Math.floor(current)) + suffix;

      if (progress < 1) {
        requestAnimationFrame(update);
      } else {
        el.textContent = (isDecimal ? num.toFixed(decimalPlaces) : num) + suffix;
      }
    }

    requestAnimationFrame(update);
  },

  /* -------------------------------------------------- */
  /* Button Loading State Helper                        */
  /* -------------------------------------------------- */
  setButtonLoading(btn, isLoading, loadingText = 'Processing...') {
    if (!btn) return;
    if (isLoading) {
      btn.dataset.originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.classList.add('btn-loading');
      btn.textContent = loadingText;
    } else {
      btn.disabled = false;
      btn.classList.remove('btn-loading');
      if (loadingText && loadingText !== 'Processing...') {
        btn.innerHTML = loadingText;
      } else if (btn.dataset.originalHtml) {
        btn.innerHTML = btn.dataset.originalHtml;
      }
    }
  }
};

// Initialize UI behaviors on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => UI.init());
