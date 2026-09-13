# Deploying SAMS Frontend to Vercel

This guide explains how to deploy the SAMS client interface to Vercel as a high-performance, globally distributed edge web application.

---

## 1. Project Architecture on Vercel

- **Frontend**: Pure Vanilla HTML5, CSS3, and JavaScript located in the `/frontend` directory.
- **Backend**: Hosted on a PHP-capable runtime (Railway, Render, AWS EC2/ECS, DigitalOcean, or cPanel).
- **Configuration**: Managed via `vercel.json` in the root repository.

---

## 2. Pre-Deployment Configuration

### Step A: Configure Backend URL
In `frontend/assets/js/config.js`, configure your backend API location:

```javascript
// Option 1: Hardcode your production backend URL
API_BASE_URL: window.location.hostname.includes('vercel.app') 
  ? "https://api-sams.up.railway.app" 
  : "",
```

Alternatively, inject `window.__SAMS_API_URL__` in an inline `<script>` tag inside your HTML `<head>`:
```html
<script>
  window.__SAMS_API_URL__ = "https://your-api-domain.com";
</script>
```

### Step B: Check `vercel.json`
The root `vercel.json` already defines clean URL routing, caching headers, and rewrites:

```json
{
  "version": 2,
  "public": false,
  "cleanUrls": true,
  "trailingSlash": false,
  "routes": [
    {
      "src": "^/(admin|teacher|student)/(.*)$",
      "dest": "/frontend/$1/$2"
    },
    {
      "src": "^/assets/(.*)$",
      "headers": {
        "cache-control": "public, max-age=31536000, immutable"
      },
      "dest": "/frontend/assets/$1"
    },
    {
      "src": "^/$",
      "dest": "/frontend/login.html"
    },
    {
      "src": "^/login$",
      "dest": "/frontend/login.html"
    }
  ]
}
```

---

## 3. Deploy via Vercel CLI or Web Dashboard

### Method 1: Vercel Web Dashboard (Recommended)
1. Push your repository to GitHub / GitLab.
2. Log into [vercel.com](https://vercel.com) and click **"Add New Project"**.
3. Import the `student-attendance-management-system` repository.
4. Keep the **Root Directory** as `./` (default).
5. Framework Preset: **Other**.
6. Click **Deploy**.

### Method 2: Vercel CLI
```bash
# Install Vercel CLI globally
npm i -g vercel

# Deploy preview
vercel

# Deploy to production
vercel --prod
```

---

## 4. Backend CORS Configuration

Ensure your PHP backend `.env` includes your Vercel production domain:

```env
CORS_ALLOWED_ORIGINS=https://your-sams-app.vercel.app,http://localhost:8000
```

---

## 5. Verification Checklist

- [ ] Visit `https://your-sams-app.vercel.app/` — should redirect smoothly to the login portal.
- [ ] Log in with demo credentials or institutional account.
- [ ] Open Browser DevTools Network tab — ensure API requests point to your remote backend and succeed without CORS preflight failures.
- [ ] Test face verification or manual attendance marking from the teacher portal.
