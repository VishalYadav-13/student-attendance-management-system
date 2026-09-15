/**
 * SAMS - Frontend Configuration
 * Safely defines public API base URL and institutional branding.
 * NEVER stores API secrets, database credentials, or Gemini keys here.
 */

window.SAMS_CONFIG = {
  APP_NAME: "Student Attendance Management System",
  SHORT_NAME: "SAMS",
  TAGLINE: "Smart Attendance. Better Academics.",
  INSTITUTION_NAME: "Demo Polytechnic Institute",
  
  // API Base URL Configuration:
  // - Priority 1: Injected runtime variable window.__SAMS_API_URL__
  // - Priority 2: Browser stored override localStorage.getItem('sams_api_url')
  // - Priority 3: Configured Render production backend URL below
  // - Priority 4: Empty string "" for unified host / local development (localhost / 127.0.0.1:8000)
  API_BASE_URL: (
    window.__SAMS_API_URL__ ||
    localStorage.getItem('sams_api_url') ||
    (function() {
      const isLocal = window.location.hostname === 'localhost' || 
                      window.location.hostname === '127.0.0.1' || 
                      window.location.port === '8000';
      if (isLocal) {
        return "";
      }
      // Production Render Backend URL:
      // Matches the service name "sams-backend" defined in render.yaml.
      // Update this if your Render service URL differs after first deploy.
      const RENDER_BACKEND_URL = "https://sams-backend.onrender.com";
      return RENDER_BACKEND_URL || "";
    })()
  ),
  
  DEFAULT_THRESHOLD: 75.0,
  AUTO_LOGOUT_MINUTES: 120
};

