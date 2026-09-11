/**
 * SAMS - Centralized HTTP REST API Client
 */

const API = {
  getBaseUrl() {
    return (window.SAMS_CONFIG && window.SAMS_CONFIG.API_BASE_URL) || "";
  },

  getToken() {
    return localStorage.getItem('sams_token') || sessionStorage.getItem('sams_token');
  },

  setToken(token, remember = false) {
    if (remember) {
      localStorage.setItem('sams_token', token);
    } else {
      sessionStorage.setItem('sams_token', token);
    }
  },

  clearToken() {
    localStorage.removeItem('sams_token');
    sessionStorage.removeItem('sams_token');
    localStorage.removeItem('sams_user');
    sessionStorage.removeItem('sams_user');
  },

  async request(endpoint, options = {}) {
    const url = endpoint.startsWith('http') ? endpoint : `${this.getBaseUrl()}${endpoint}`;
    
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(options.headers || {})
    };

    const token = this.getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const config = {
      ...options,
      headers
    };

    if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
      config.body = JSON.stringify(config.body);
    }

    try {
      const response = await fetch(url, config);
      const contentType = response.headers.get('content-type') || '';
      
      let data = null;
      if (contentType.includes('application/json')) {
        data = await response.json();
      } else {
        data = await response.text();
      }

      if (!response.ok) {
        // Handle 401 Unauthorized - redirect to login
        if (response.status === 401 && !endpoint.includes('/api/auth/login')) {
          this.clearToken();
          if (!window.location.pathname.includes('login.html')) {
            window.location.href = '/frontend/login.html';
          }
        }

        const errMsg = (data && data.message) || (data && data.error && data.error.message) || `HTTP Error ${response.status}`;
        const err = new Error(errMsg);
        err.status = response.status;
        err.data = data;
        throw err;
      }

      return data;
    } catch (err) {
      if (err.name === 'TypeError' && err.message.includes('fetch')) {
        console.error('[SAMS Network Error]', err);
        throw new Error('Unable to connect to backend server. Please verify your connection.');
      }
      throw err;
    }
  },

  get(endpoint, params = {}) {
    const query = new URLSearchParams(params).toString();
    const fullEndpoint = query ? `${endpoint}?${query}` : endpoint;
    return this.request(fullEndpoint, { method: 'GET' });
  },

  post(endpoint, body = {}) {
    return this.request(endpoint, { method: 'POST', body });
  },

  put(endpoint, body = {}) {
    return this.request(endpoint, { method: 'PUT', body });
  },

  delete(endpoint) {
    return this.request(endpoint, { method: 'DELETE' });
  }
};
