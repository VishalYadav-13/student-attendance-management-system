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
  // When frontend and backend are hosted on same domain (or dev server), use "" (empty string) for relative paths.
  // When deploying frontend on Vercel with backend on separate host, set to backend URL, e.g. "https://api-sams.up.railway.app"
  API_BASE_URL: window.location.origin.includes(':8000') || window.location.origin.includes('localhost') ? "" : "",
  
  DEFAULT_THRESHOLD: 75.0,
  AUTO_LOGOUT_MINUTES: 120
};
