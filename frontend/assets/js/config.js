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
  
  // API Base URL:
  // - Local dev / Unified server: "" (empty string sends requests to /api/... on same host)
  // - Split deployment (e.g. Vercel frontend + Railway/Render/AWS backend):
  //   Set window.__SAMS_API_URL__ = "https://your-backend-api.com" in head, or configure below:
  API_BASE_URL: window.__SAMS_API_URL__ || (
    (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.port === '8000')
      ? ""
      : "" // Replace with your production API URL e.g. "https://api.your-sams-domain.com" if hosted separately
  ),
  
  DEFAULT_THRESHOLD: 75.0,
  AUTO_LOGOUT_MINUTES: 120
};
