# 🎮 LevelUp Life — Multi-User Gamified Daily Habit Tracker (PHP + SQL Database)

🌐 **Live Public Website:** [https://sibanandabhuyan157-ctrl.github.io/gamified-daily-habit-tracker/](https://sibanandabhuyan157-ctrl.github.io/gamified-daily-habit-tracker/)  
📦 **GitHub Source Repository:** [https://github.com/sibanandabhuyan157-ctrl/gamified-daily-habit-tracker](https://github.com/sibanandabhuyan157-ctrl/gamified-daily-habit-tracker)

**LevelUp Life** is a full-stack, multi-user gamified daily habit tracker and growth engine built with **PHP 8+**, **PDO SQL (MySQL / MariaDB + zero-config SQLite fallback)**, **Tailwind CSS**, and **Chart.js**.

Multiple users can freely **Register**, **Log In**, track both their **Present (Today)** and **Historical** daily habits, level up through RPG ranks, analyze 7-day & 30-day consistency, and compete on the **Public Adventurer Leaderboard**.

---

## ✨ Key Features

1. **Multi-User Registration & Login with Database (`users` table)**
   - Secure password hashing (`password_hash` / `PASSWORD_DEFAULT` in PHP, SHA-256 fallback in browser mode).
   - Persistent PHP sessions + option to import existing guest habits & history upon registration.
   - Customizable display name, username, and RPG avatar color theme.
2. **Complete Present & Historical Habit Tracking (`habits` & `habit_logs` tables)**
   - **Present Day View**: Real-time daily tracking with boolean checkboxes and measurable numeric targets (hours, pages, minutes), category filtering, and date picker.
   - **History & Present Log Tab**:
     - Present Day live summary card + All-Time database stats (Active Days in DB, Total Check-ins, Perfect 100% Days).
     - Interactive **30-Day Consistency Heatmap Matrix** (click any date to inspect or edit).
     - Full per-day timeline showing every habit completed or missed on each date, with one-click inline toggling and JSON history export.
3. **High-Performance PHP Backend (`index.php`, `api.php`, `config.php`)**
   - Server-side initial state pre-hydration (`window.__INITIAL_SERVER_STATE__`) for instant first-paint performance.
   - Gzip output compression (`ob_gzhandler`), prepared statements (`PDO`), and atomic database transactions.
   - Dual Database Driver (`DB_DRIVER=auto`): Automatically connects to **MySQL/MariaDB** when available or falls back to **SQLite WAL mode** (`database/levelup.sqlite`) with zero configuration required.
4. **Public Multi-User Leaderboard**
   - Ranks registered adventurers by Total XP and Best Daily Streak.

---

## 🗂️ Project Structure

| File | Description |
| :--- | :--- |
| `index.php` | High-performance PHP entry point with server-side session & database state pre-hydration |
| `api.php` | RESTful JSON API (`register`, `login`, `logout`, `session`, `save_habit`, `delete_habit`, `log_habit`, `sync_state`, `leaderboard`) |
| `config.php` | PDO database connection manager (MySQL/MariaDB + automatic SQLite WAL fallback) & schema initializer |
| `schema.sql` | Full MySQL / MariaDB relational schema (`users`, `habits`, `habit_logs`) |
| `gamified_daily_habit_tracker.html` | Main application UI & hybrid database client |
| `index.html` | Static entry point for GitHub Pages / CDN hosting |
| `Dockerfile` | Production Docker image (`php:8.2-apache` with PDO MySQL & SQLite) |
| `.htaccess` | Apache compression, security headers, and database file protection |

---

## 🚀 Running Locally with PHP

### Option 1: Built-in PHP Server (Zero-Config SQLite or MySQL)
```bash
php -S localhost:8000
```
Then open `http://localhost:8000` in your browser. The SQLite database (`database/levelup.sqlite`) or MySQL database (`levelup_habit_tracker`) is created automatically on first load!

### Option 2: XAMPP / WAMP / Laragon (MySQL)
1. Copy this folder into `htdocs/levelup-habit-tracker`.
2. (Optional) Import `schema.sql` in phpMyAdmin, or let `config.php` auto-create the `levelup_habit_tracker` database and tables.
3. Visit `http://localhost/levelup-habit-tracker/`.
