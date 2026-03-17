# BRIGHTPATH Loogistics — Railway Deployment

A full logistics management system (PHP 8.2 + MySQL) ready to deploy on [Railway](https://railway.app).

---

## 🚀 Deploy to Railway in 5 Steps

### 1. Push to GitHub
```bash
git init
git add .
git commit -m "initial commit"
git remote add origin https://github.com/YOUR_USER/loogistics.git
git push -u origin main
```

### 2. Create a Railway Project
- Go to [railway.app](https://railway.app) → **New Project**
- Select **Deploy from GitHub repo** → pick your repo

### 3. Add a MySQL Database
- Inside your project, click **+ New** → **Database** → **MySQL**
- Railway automatically injects these env vars into your app:
  - `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`

### 4. Set App Environment Variable (optional)
| Variable     | Value         | Notes                        |
|-------------|---------------|------------------------------|
| `APP_ENV`   | `production`  | Default is already production |
| `APP_SECRET`| `your-secret` | Used for session security     |

### 5. Deploy
Railway will build the Docker image, wait for MySQL, import the schema automatically on first boot, and go live.

> Your app will be at `https://your-app.up.railway.app`

---

## 🏗️ Architecture

```
BRIGHTPATH Loogistics
├── Dockerfile              ← PHP 8.2 + Apache image
├── docker-entrypoint.sh    ← Waits for DB, imports schema, starts Apache
├── railway.toml            ← Railway build + deploy config
├── .htaccess               ← HTTPS redirect, security headers, caching
├── config.php              ← DB config reads from Railway env vars
│
├── login.php               ← Entry point
├── signup.php              ← Staff application
├── applicant_status.php    ← Application status checker
│
├── admin/                  ← Admin panel (role: admin)
│   ├── dashboard.php
│   ├── manage_users.php
│   ├── inventory_management.php
│   ├── asset_management.php
│   ├── supplier_management.php
│   ├── project_management.php
│   ├── document_management.php
│   ├── procurement_management.php
│   ├── compliance.php
│   ├── workflows.php
│   ├── reports.php
│   └── settings.php
│
└── api/                    ← JSON API endpoints
    ├── alms_api.php        ← Asset Lifecycle Management
    ├── get_assets.php
    ├── add_assets.php
    └── ...
```

---

## 💻 Local Development

### With Docker Compose
```bash
docker compose up
```
App runs at `http://localhost:8080`

### Without Docker (XAMPP / WAMP / Laragon)
1. Copy files to `htdocs/loogistics`
2. Import `loogistics.sql` into MySQL
3. Open `http://localhost/loogistics/login.php`

No `.env` file needed locally — the app falls back to `localhost/root/loogistics`.

---

## 🔐 Default Login

After first deploy, create an admin user directly in the DB:

```sql
INSERT INTO users (first_name, last_name, email, password, role, status, application_status)
VALUES ('Admin', 'User', 'admin@brightpath.com', 'Admin@1234', 'admin', 'active', 'approved');
```

> ⚠️ Passwords are stored as plain text in the current schema. Hash them with `password_hash()` in production when ready.

---

## 🌍 Environment Variables Reference

| Variable        | Railway Auto-injected | Description              |
|----------------|----------------------|--------------------------|
| `MYSQLHOST`     | ✅ Yes               | Database host             |
| `MYSQLPORT`     | ✅ Yes               | Database port (3306)      |
| `MYSQLUSER`     | ✅ Yes               | Database username         |
| `MYSQLPASSWORD` | ✅ Yes               | Database password         |
| `MYSQLDATABASE` | ✅ Yes               | Database name             |
| `APP_ENV`       | ❌ Set manually      | `production` / `development` |
| `APP_SECRET`    | ❌ Set manually      | Session secret key        |

---

## 📁 File Uploads

Resumes uploaded via the signup form are stored in `uploads/resumes/`.

On Railway, the filesystem is **ephemeral** — uploads will reset on redeploy. For production, integrate with an S3-compatible object store (e.g. Railway's object storage, Cloudflare R2, or AWS S3) and update `signup.php` accordingly.
