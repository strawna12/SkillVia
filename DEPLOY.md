# SkillVia — Railway Deployment Guide

## What's in this repo

```
/
├── index.html              ← Landing page
├── signup.html             ← Facility onboarding
├── pending.html            ← Post-signup waiting screen
├── supervisor.html         ← Supervisor login
├── education_portal.html   ← Main supervisor dashboard
├── staff.html              ← Staff PIN portal
├── api.php                 ← Full backend API (auto-creates DB tables)
├── Dockerfile              ← Tells Railway how to run the app
├── docker-entrypoint.sh    ← Handles Railway's dynamic port
└── .htaccess               ← Routes /api/* calls to api.php
```

---

## Step 1 — Push to GitHub

Make sure all files above are in your GitHub repo (the public "SkillVia" one).

---

## Step 2 — Add MySQL on Railway

1. Go to [railway.app](https://railway.app) and open your project
2. Click **+ New** → **Database** → **MySQL**
3. Railway will create the database and show you these variables:
   - `MYSQLHOST`
   - `MYSQLDATABASE`
   - `MYSQLUSER`
   - `MYSQLPASSWORD`
   - `MYSQLPORT`

The api.php already reads these automatically — no code changes needed.

---

## Step 3 — Deploy the app on Railway

1. In your Railway project, click **+ New** → **GitHub Repo**
2. Select your SkillVia repo
3. Railway will detect the Dockerfile and build automatically

---

## Step 4 — Set environment variables

In your Railway app service, go to **Variables** and add:

| Variable | Value |
|---|---|
| `JWT_SECRET` | Any random string, 32+ characters (e.g. generate one at passwordsgenerator.net) |

Railway auto-injects the MySQL variables from Step 2 — you don't need to add those manually.

---

## Step 5 — Point skillviaai.com to Railway

1. In Railway, go to your app service → **Settings** → **Networking** → **Custom Domain**
2. Add `skillviaai.com` and `www.skillviaai.com`
3. Railway will show you a CNAME value (looks like `something.up.railway.app`)
4. Go to **Namecheap** → your domain → **Advanced DNS**
5. Update or add these records:

| Type | Host | Value |
|---|---|---|
| CNAME | www | `your-app.up.railway.app` |
| CNAME | @ | `your-app.up.railway.app` |

DNS changes take 10–30 minutes to propagate.

---

## Step 6 — Test everything

Once live, walk through the full flow:

- [ ] `skillviaai.com` loads the landing page
- [ ] "Try Free" button goes to `/signup.html`
- [ ] Submitting signup goes to `/pending.html`
- [ ] `/supervisor.html` login form appears
- [ ] `/staff.html` PIN entry works
- [ ] `/education_portal.html` loads after supervisor login

The database tables are created automatically on first API call — no setup needed.

---

## Your first login (for yourself)

After deploying, you'll need to create your own super-admin account directly in the database:

1. In Railway, open your MySQL service → **Data** tab (or connect via TablePlus/MySQL Workbench)
2. Run this SQL to create your facility and account:

```sql
-- Insert your facility (status = 'approved' skips the pending review)
INSERT INTO facilities (name, status) VALUES ('SkillVia Admin', 'approved');

-- Insert your supervisor account (change the email and password hash)
-- To generate a password hash, visit: https://bcrypt-generator.com
INSERT INTO supervisors (facility_id, first_name, last_name, email, password_hash, role)
VALUES (1, 'Aysha', '', 'aysha@skillviaai.com', 'YOUR_BCRYPT_HASH_HERE', 'superadmin');
```

---

## Need help?

If anything breaks after deploying, check Railway's **Logs** tab in your app service — it will show exactly what's going wrong.
