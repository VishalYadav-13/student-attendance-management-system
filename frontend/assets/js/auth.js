/**
 * SAMS - Authentication State & Route Guard Manager
 */

const Auth = {
  getUser() {
    const raw = localStorage.getItem('sams_user') || sessionStorage.getItem('sams_user');
    return raw ? JSON.parse(raw) : null;
  },

  setUser(user, remember = false) {
    const serialized = JSON.stringify(user);
    if (remember) {
      localStorage.setItem('sams_user', serialized);
    } else {
      sessionStorage.setItem('sams_user', serialized);
    }
  },

  isAuthenticated() {
    return !!API.getToken() && !!this.getUser();
  },

  /**
   * Enforce Role Guard on Protected Dashboard Pages
   * @param {string|string[]} allowedRoles
   */
  requireRole(allowedRoles) {
    if (!Array.isArray(allowedRoles)) {
      allowedRoles = [allowedRoles];
    }

    if (!this.isAuthenticated()) {
      window.location.href = '/frontend/login.html';
      return null;
    }

    const user = this.getUser();
    const userRole = (user && user.role) ? user.role.toUpperCase() : '';
    const upperAllowed = allowedRoles.map(r => r.toUpperCase());

    if (!upperAllowed.includes(userRole)) {
      // Role Mismatch: Redirect to user's legitimate dashboard
      console.warn(`[SAMS Security] Role mismatch: User is ${userRole}, route requires ${upperAllowed.join('/')}`);
      if (userRole === 'ADMIN') {
        window.location.href = '/frontend/admin/dashboard.html';
      } else if (userRole === 'TEACHER') {
        window.location.href = '/frontend/teacher/dashboard.html';
      } else {
        window.location.href = '/frontend/student/dashboard.html';
      }
      return null;
    }

    return user;
  },

  async logout() {
    try {
      await API.post('/api/auth/logout');
    } catch (e) {
      // ignore network errors on logout
    } finally {
      API.clearToken();
      window.location.href = '/frontend/login.html';
    }
  },

  /**
   * Automatically populate sidebar user profile info
   */
  renderUserProfile(user) {
    if (!user) user = this.getUser();
    if (!user) return;

    const nameEl = document.getElementById('sidebar-user-name');
    const roleEl = document.getElementById('sidebar-user-role');
    const avatarEl = document.getElementById('sidebar-avatar');

    const displayName = user.full_name || user.email.split('@')[0];
    if (nameEl) nameEl.textContent = displayName;
    if (roleEl) roleEl.textContent = user.role || 'User';
    if (avatarEl) {
      const initials = displayName.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase();
      avatarEl.textContent = initials || 'U';
    }
  }
};
