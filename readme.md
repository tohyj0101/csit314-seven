# CSIT314 T04: Seven — Interior Designer Matching Platform

[![CI](https://github.com/tohyj0101/csit314-seven/actions/workflows/ci.yml/badge.svg)](https://github.com/tohyj0101/csit314-seven/actions/workflows/ci.yml)

A full-stack web platform that connects renovation customers with interior designers. Built with PHP, PostgreSQL and a vanilla JavaScript front end on a Boundary–Control–Entity (BCE) architecture, following Agile/Scrum with CI/CD and integration testing.

**Status:** all 48 user stories implemented. 21 PHPUnit integration tests (103 assertions) and a 48-story Node.js smoke test (`demo.js`) all pass.

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Overview](#overview)
3. [Features by Role](#features-by-role)
4. [Tech Stack](#tech-stack)
5. [Architecture](#architecture)
6. [Prerequisites](#prerequisites)
7. [Full Setup Guide](#full-setup-guide)
8. [Test Credentials](#test-credentials)
9. [Testing](#testing)
10. [Project Structure](#project-structure)
11. [Database](#database)
12. [API Endpoints](#api-endpoints)
13. [Manual Demo Script](#manual-demo-script)
14. [Troubleshooting](#troubleshooting)
15. [Known Limitations](#known-limitations)
16. [CI/CD](#cicd)
17. [Team](#team)
18. [Academic Context](#academic-context)

---

## Quick Start

For experienced developers. Run these in order.

**macOS / Linux**

```bash
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
composer install
cp .env.example .env            # then edit DB_PASSWORD
psql -U postgres -c "CREATE DATABASE csit314_seven;"
psql -U postgres -d csit314_seven -f db/schema.sql
php db/seed.php
php -S localhost:8000 router.php
```

**Windows (PowerShell)**

```powershell
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
composer install
copy .env.example .env          # then edit DB_PASSWORD
psql -U postgres -c "CREATE DATABASE csit314_seven;"
psql -U postgres -d csit314_seven -f db/schema.sql
php db/seed.php
php -S localhost:8000 router.php
```

Open <http://localhost:8000> and log in as `designer@example.test` / `Password123!`.

> Edit `.env` with your real PostgreSQL password **before** running the `psql` and `php db/seed.php` commands.

---

## Overview

The platform serves four user roles, each with its own permissions and workflow:

| Role | What they do |
|---|---|
| **User Admin** | Manages user accounts and user profiles (roles and permissions) |
| **Interior Designer** | Manages companies and project portfolios; views engagement analytics |
| **Customer** | Browses and searches public projects; saves favourites |
| **Platform Manager** | Maintains IDP categories; generates platform-wide reports |

Access control is enforced at two levels:

- **Route level** — the boundary layer rejects requests from the wrong role (`403 Forbidden`). Each route is wrapped in `auth_guard()` (must be logged in) and `role_guard('<Role>')` (must hold the required profile).
- **Resource level** — controllers verify ownership before changing anything. For example, a designer cannot edit another designer's project, and cannot read another designer's company's project list.

### Role → route matrix

| Route group | User Admin | Designer | Customer | Manager |
|---|:-:|:-:|:-:|:-:|
| `/api/auth/*` | ✅ | ✅ | ✅ | ✅ |
| `/api/users`, `/api/profiles` | ✅ | — | — | — |
| `/api/companies` | — | ✅ | — | — |
| `/api/projects` (incl. `/company/...`, `/search`) | — | ✅ | — | — |
| `/api/projects/public/*`, `/api/favourites` | — | — | ✅ | — |
| `/api/categories`, `/api/reports/*` | — | — | — | ✅ |

---

## Features by Role

### User Admin (US #1–#12)
- Login / logout (US #1, #2)
- Create / view / update / suspend / search user accounts (US #3–#7)
- Create / view / update / suspend / search user profiles (US #8–#12)

### Interior Designer (US #13–#29, #37–#38)
- Login / logout (US #13, #14)
- Create / view / update / delete / search companies (US #15–#19)
- Create / view / update / delete / search ongoing projects (US #20–#24)
- Create / view / update / delete / search completed projects (US #25–#29)
- View view-count and shortlist-count metrics per project (US #37, #38)

### Customer (US #30–#36)
- Login / logout (US #35, #36)
- Search and view public projects (US #30, #31)
- Save to favourites; search and view the favourites list; remove favourites (US #32–#34)

### Platform Manager (US #39–#48)
- Login / logout (US #39, #40)
- Create / view / update / delete / search IDP categories (US #41–#45)
- Generate daily / weekly / monthly reports (US #46–#48)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | Vanilla JavaScript single-page app (hash routing), HTML5, CSS3 |
| Backend | PHP 8.2+ with no framework: custom `Router` plus BCE controllers |
| Database | PostgreSQL 15 (14+ also works), accessed through PDO |
| Auth | PHP session cookies (`HttpOnly`, `SameSite=Lax`) and bcrypt password hashing (cost 10) |
| Unit/integration tests | PHPUnit 10.5 — HTTP requests against a live server |
| Smoke test | Node.js 18+ (`demo.js`, uses the built-in `fetch`) — **optional**, not needed to run the app |
| CI/CD | GitHub Actions — full suite against a fresh PostgreSQL service container |

The application itself needs only PHP, PostgreSQL and a browser. There is no build step. Node.js is only needed if you want to run `demo.js`.

---

## Architecture

Strict Boundary–Control–Entity separation:

```
Browser  ───────►  Boundary Layer   public/js/app.js
                                    src/bce/boundary.php
                          │
                          ▼
                    Control Layer   src/bce/controllers.php
                    41 controllers (+ ProjectAuth helper)
                          │
                          ▼
                    Entity Layer    src/bce/entities.php
                    7 entity classes
                          │
                          ▼
                    PostgreSQL      8 tables
```

- **Boundary** (`src/bce/boundary.php`) — maps 50 HTTP routes to controllers and attaches the middleware (`auth_guard()`, `role_guard()`).
- **Control** (`src/bce/controllers.php`) — validates input, enforces ownership, picks the HTTP status, and delegates to entities.
- **Entity** (`src/bce/entities.php`) — `UserAcc`, `UserProfile`, `CompanyInfo`, `Project`, `Favourite`, `Category`, `Report`. All SQL lives here.
- **Frontend** (`public/js/app.js`) — single-page app that treats the backend as a REST API.

### Request lifecycle

1. The browser (or `demo.js`) sends a request to `/api/...`.
2. `router.php` loads `src/bootstrap.php`, which loads `.env`, starts the session and registers the routes.
3. `Router::dispatch()` matches the method and path (e.g. `/projects/company/:companyId`) and extracts named parameters.
4. Middleware runs in order. `auth_guard()` returns `401` if there is no session. `role_guard()` returns `403` for the wrong role.
5. The controller validates input and checks ownership, then calls an entity method.
6. The entity runs a parameterised query through `q()`, `q1()` or `ex()` in `src/config/db.php`.
7. The controller returns JSON through `json_out()` or `display_error()`.

### Design notes

- **Custom router.** Express-style paths (`/users/:id`) are converted to regular expressions. Routes are matched in the order they are registered, so fixed paths such as `/projects/search` must be registered **before** `/projects/:id`.
- **`$1, $2` placeholders.** SQL is written with PostgreSQL-style `$n` placeholders. `db_prepare()` translates them to PDO `?` placeholders and reorders the parameters, so a placeholder can be reused in one query. Every query is a prepared statement.
- **Structured errors.** Controllers report problems through `display_error()` so the frontend can show consistent banners (see [Error Responses](#error-responses)).
- **Ownership before search.** The per-company search checks company ownership first, so a caller cannot tell "no results" from "not yours".

---

## Prerequisites

**Required**

- **PHP 8.2+** with extensions `pdo_pgsql`, `pgsql`, `openssl`, `mbstring`
- **PostgreSQL 14+**, running as a service
- **Composer**

**Optional**

- **Node.js 18+** — only for `demo.js`
- **Git** — to clone the repository
- **pgAdmin** — to inspect the database visually

**Verify your environment**

```powershell
php -v
php -m | Select-String "pdo_pgsql|pgsql|openssl|mbstring"     # Windows
composer --version
psql --version
node -v                                                       # optional
```

```bash
php -m | grep -E "pdo_pgsql|pgsql|openssl|mbstring"           # macOS / Linux
```

If an extension is missing, open your `php.ini` (for XAMPP on Windows: `C:\xampp\php\php.ini`; on Linux: `/etc/php/8.2/cli/php.ini`) and remove the `;` in front of the matching `extension=` line.

---

## Full Setup Guide

### 1. Clone the repository

```bash
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
```

All following commands are run **from inside this folder**.

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure the environment

```bash
cp .env.example .env        # macOS / Linux
copy .env.example .env      # Windows
```

Edit `.env`:

```ini
PORT=8000
DB_HOST=localhost
DB_PORT=5432
DB_USER=postgres
DB_PASSWORD=your_database_password
DB_NAME=csit314_seven
```

Real environment variables override `.env`, which is how CI points the tests at a different database. `.env` is git-ignored — never commit it.

### 4. Create the database

Using `psql`:

```bash
psql -U postgres -c "CREATE DATABASE csit314_seven;"
```

Or in pgAdmin: right-click **Databases → Create → Database…**, name it `csit314_seven`, **Save**.

### 5. Apply the schema and seed data

```bash
psql -U postgres -d csit314_seven -f db/schema.sql     # create tables
php db/seed.php                                        # base demo data
php db/generate_test_data.php                          # optional: 100+ synthetic records for Reports
```

> ⚠️ `db/schema.sql` begins with `DROP TABLE ... CASCADE`. Running it again **erases all data**. That is useful for resetting, but do not run it on data you want to keep.

### 6. Start the server

```bash
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. Leave this terminal open while you use the app.

For access from another device on the same Wi-Fi:

```bash
php -S 0.0.0.0:8000 router.php
```

then browse to `http://<your-ip>:8000`.

### Composer shortcuts

| Command | What it runs |
|---|---|
| `composer serve` | `php -S localhost:8000 router.php` |
| `composer db:init` | `psql -U postgres -d csit314_seven -f db/schema.sql` |
| `composer db:seed` | `php db/seed.php` |
| `composer test` | `phpunit` |

---

## Test Credentials

All seeded accounts share the password **`Password123!`**.

| Role | Email | Demo purpose |
|---|---|---|
| User Admin | `admin@example.test` | User accounts, profiles, RBAC |
| Interior Designer | `designer@example.test` | Companies, projects, analytics |
| Customer | `customer@example.test` | Browse, search, favourites |
| Platform Manager | `manager@example.test` | Categories, reports |
| Suspended | `suspended@example.test` | Login is refused with 403 (US #6, #11) |

Logging in as the suspended account is *meant* to fail with HTTP 403. That shows suspension is enforced.

---

## Testing

There are two independent test layers. Both need the PostgreSQL database to be running and seeded.

### 1. PHPUnit integration tests

Exercise the full Boundary → Control → Entity stack through real HTTP requests.

```bash
vendor/bin/phpunit --testdox          # macOS / Linux
php vendor/bin/phpunit --testdox      # Windows
# or:  composer test
```

Expected: `OK (21 tests, 103 assertions)`.

`tests/bootstrap.php` starts its own PHP server on port 8000 if none is already running, and stops it afterwards.

**Coverage**

- Login / logout and session handling
- User account and profile CRUD, duplicates, suspension
- Company and project CRUD, search, status filter, ownership checks
- Favourites (save is idempotent, 404 for unknown project, search)
- Category CRUD
- Report generation (daily / weekly / monthly)
- RBAC (403 on cross-role access)
- Cascade rules (400 when deleting a company that still has projects)
- **TDD evidence:** `test_37_view_project_views` isolates US #37 (view counter) and documents the Red–Green–Refactor cycle from the report

### 2. Node.js smoke test — `demo.js`

Walks through **all 48 user stories** over HTTP as each of the four roles and prints one line per story.

1. Start the server in one terminal (from the project folder):

   ```bash
   php -S localhost:8000 router.php
   ```

2. In a **second** terminal, also from the project folder:

   ```bash
   node demo.js
   ```

Expected output (abridged):

```
🚀 Starting Comprehensive 48-Story Demo Tests...

--- USER ADMIN ---
[US #1] ✅ Login as Admin
...
[US #24] ✅ Search Ongoing Projects
...
🏁 All 48 User Stories tested.
```

Any `❌` line names the failing story. The script talks to `http://localhost:8000/api`, so the server must be on port 8000.

> **Both test layers write to the database.** They clean up after themselves for the most part, but leave some extra rows (e.g. test users and renamed profiles). Run them against a scratch database, or reset with `schema.sql` + `seed.php` before a live demo. They are safe to run repeatedly without resetting.

---

## Project Structure

```
csit314-seven/
├── router.php                  # Entry point for the PHP built-in server (serves API + static files)
├── demo.js                     # Node.js 48-story smoke test
├── composer.json
├── .env.example                # Template (real .env is git-ignored)
├── phpunit.xml
│
├── db/
│   ├── schema.sql              # 8 table definitions (DROPs and recreates)
│   ├── seed.php                # Base demo data
│   └── generate_test_data.php  # Optional: 100+ synthetic records for reports
│
├── src/
│   ├── bootstrap.php           # Loads config, classes, .env, session
│   ├── Router.php              # Route matching + helpers (json_out, req_body, session_user)
│   ├── config/
│   │   ├── auth.php            # hash_password / verify_password (bcrypt)
│   │   ├── db.php              # PDO singleton + q(), q1(), ex()
│   │   └── env.php             # .env loader
│   ├── middleware/
│   │   ├── authGuard.php       # auth_guard()  → 401
│   │   └── roleGuard.php       # role_guard()  → 403
│   └── bce/
│       ├── boundary.php        # All 50 routes
│       ├── controllers.php     # 41 controllers + ProjectAuth helper
│       └── entities.php        # 7 entity classes
│
├── public/
│   ├── index.html              # SPA shell
│   ├── js/app.js               # All front-end logic (~1,480 lines)
│   └── css/style.css
│
├── tests/
│   ├── AllTest.php             # 21 integration tests
│   └── bootstrap.php           # Auto-starts the test server
│
├── .github/workflows/ci.yml    # GitHub Actions pipeline
└── readme.md                   # This file
```

---

## Database

PostgreSQL, 8 tables.

| Table | Purpose | Notable rules |
|---|---|---|
| `user_profile` | Role definition (name, description, permissions array, status) | `profile_name` unique; status `Active`/`Suspended` |
| `user_account` | Login accounts | `username` and `email` unique; bcrypt hash; status `Active`/`Suspended` |
| `company` | A designer's business | Owned by an account (`ON DELETE CASCADE`) |
| `category` | IDP categories | `category_name` unique |
| `project` | Ongoing or completed project | `status` is `Ongoing` or `Completed`; **`ON DELETE RESTRICT`** from `company` |
| `project_category` | Project ↔ category link | Composite primary key |
| `project_view` | One row per customer view | Feeds the view counter; customer id is never returned by the API |
| `favourite` | Customer shortlist | `UNIQUE(customer_id, project_id)` makes saving idempotent |

**Delete behaviour**

- Deleting a **company that has projects** is blocked → HTTP 400 (`ON DELETE RESTRICT`).
- Deleting a **project** removes its views, favourites and category links (`CASCADE`).
- Deleting a **customer account** removes their favourites and anonymises their views (`SET NULL`).

**Seed data (`php db/seed.php`)** — 5 users (admin, designer, customer, manager, suspended) with 4 role profiles, 2 companies, 3 categories and 4 projects.

**Resetting to a clean state**

```bash
psql -U postgres -d csit314_seven -f db/schema.sql
php db/seed.php
```

---

## API Endpoints

All endpoints live under `/api/`. Authentication uses a session cookie: log in first, and later requests reuse the cookie. Request and response bodies are JSON.

### Authentication

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/auth/login` | public | Log in (`{"email","password"}`) |
| POST | `/api/auth/logout` | any | Log out |
| GET | `/api/auth/me` | any | Current user |

### User Accounts (User Admin)

| Method | Path | Description |
|---|---|---|
| GET | `/api/users` | List all |
| GET | `/api/users/search?q=` | Search by username/email |
| POST | `/api/users` | Create |
| GET | `/api/users/:id` | View |
| PUT | `/api/users/:id` | Update |
| PATCH | `/api/users/:id/suspend` | Suspend |
| PATCH | `/api/users/:id/activate` | Activate |

### User Profiles (User Admin)

| Method | Path | Description |
|---|---|---|
| GET | `/api/profiles` | List all |
| GET | `/api/profiles/search?q=` | Search by name |
| POST | `/api/profiles` | Create |
| GET | `/api/profiles/:id` | View |
| PUT | `/api/profiles/:id` | Update |
| PATCH | `/api/profiles/:id/suspend` | Suspend |
| PATCH | `/api/profiles/:id/activate` | Activate |

### Companies (Interior Designer)

| Method | Path | Description |
|---|---|---|
| GET | `/api/companies` | List own companies |
| GET | `/api/companies/search?q=` | Search own companies |
| POST | `/api/companies` | Create (name, address and contact number are all required) |
| GET | `/api/companies/:id` | View |
| PUT | `/api/companies/:id` | Update |
| DELETE | `/api/companies/:id` | Delete (400 if it still has projects) |

### Projects (Interior Designer)

| Method | Path | Description |
|---|---|---|
| POST | `/api/projects` | Create ongoing project |
| POST | `/api/projects/completed` | Create completed project |
| GET | `/api/projects/search?q=&status=` | **US #24** — search own projects across all own companies |
| GET | `/api/projects/search/interest` | View/save metrics for all own projects |
| GET | `/api/projects/:id` | View |
| GET | `/api/projects/:id/completed` | View completed |
| PUT | `/api/projects/:id` | Update |
| PUT | `/api/projects/:id/completed` | Update completed |
| DELETE | `/api/projects/:id` | Delete |
| DELETE | `/api/projects/:id/completed` | Delete completed |
| GET | `/api/projects/company/:companyId` | List by company (`?status=` optional) |
| GET | `/api/projects/company/:companyId/search?q=&status=` | **US #24** — search within one company |
| GET | `/api/projects/company/:companyId/completed/search?q=` | **US #29** — search completed projects |
| GET | `/api/projects/company/:companyId/interest` | Views/saves per project (US #37, #38) |

A search with no matches returns **200** with `"projects": []` and a `message`, not an error.

### Public Projects (Customer)

| Method | Path | Description |
|---|---|---|
| GET | `/api/projects/public/search?q=` | Search all public projects |
| GET | `/api/projects/public/:id` | View and increment the view count |

### Favourites (Customer)

| Method | Path | Description |
|---|---|---|
| GET | `/api/favourites` | List own favourites |
| GET | `/api/favourites/search?q=` | Search own favourites |
| POST | `/api/favourites` | Save (`{"project_id": N}`; idempotent, 404 if project is missing) |
| DELETE | `/api/favourites/:projectId` | Remove |

### Categories (Platform Manager)

| Method | Path | Description |
|---|---|---|
| GET | `/api/categories` | List all |
| GET | `/api/categories/search?q=` | Search by name |
| GET | `/api/categories/:id` | View |
| POST | `/api/categories` | Create |
| PUT | `/api/categories/:id` | Update |
| DELETE | `/api/categories/:id` | Delete |

### Reports (Platform Manager)

| Method | Path | Description |
|---|---|---|
| GET | `/api/reports/daily` | Last 24 hours summary |
| GET | `/api/reports/weekly` | Last 7 days summary |
| GET | `/api/reports/monthly` | Last 30 days summary |

Any other report type is rejected.

### Trying the API with curl

```bash
# log in and keep the session cookie
curl -c cookies.txt -H "Content-Type: application/json" \
     -d '{"email":"designer@example.test","password":"Password123!"}' \
     http://localhost:8000/api/auth/login

# search own projects
curl -b cookies.txt "http://localhost:8000/api/projects/search?q=Revamp&status=Ongoing"
```

(On Windows PowerShell use `curl.exe` instead of `curl`.)

### Error Responses

Every error returns a structured JSON payload:

```json
{
  "error":    "Company Name is a mandatory field.",
  "message":  "Company Name is a mandatory field.",
  "title":    "Invalid Input",
  "severity": "warning",
  "icon":     "⚠️",
  "status":   400,
  "display":  true,
  "html":     "<div class=\"error-alert\" ...>...</div>"
}
```

The frontend renders these as styled toasts.

| Status | Meaning |
|---|---|
| 400 | Missing or invalid input; record still in use |
| 401 | Not logged in, or wrong credentials |
| 403 | Wrong role, not the owner, or suspended account/profile |
| 404 | Record or endpoint not found |
| 409 | Duplicate (email, username, name) or already suspended |
| 500 | Unexpected server error |

---

## Manual Demo Script

A suggested ~5-minute live walkthrough.

*To show the Reports page with realistic data, run `php db/generate_test_data.php` beforehand.*

1. **Log in as admin** — the nav bar shows only admin modules (RBAC).
2. **Log out, log in as `suspended@example.test`** — show the 403 error toast.
3. **Log in as designer** — create a company, create a project, search projects (US #24), open the analytics (views and saves).
4. **Log out, log in as customer** — browse the gallery, save a project to favourites, view the list, remove one.
5. **Log out, log in as manager** — create a category, generate a daily and a weekly report.
6. **Log in as designer, delete a company that has projects** — show the 400 error (`ON DELETE RESTRICT` protecting data).
7. *(Optional)* Run `node demo.js` to show all 48 stories passing from the command line.

---

## Troubleshooting

### First-run errors

| Symptom | Cause | Fix |
|---|---|---|
| `php` not recognised | PHP not on PATH | Add the PHP folder (e.g. `C:\xampp\php`) to the PATH via `sysdm.cpl` → Environment Variables. Reopen the shell. |
| `could not find driver` | `pdo_pgsql` not enabled | In `php.ini` remove the `;` before `extension=pdo_pgsql`. Reopen the shell. |
| `Class "PDO" not found` | `pdo` not enabled | Enable `extension=pdo` in `php.ini`. |
| `SQLSTATE[08006] connection refused` | PostgreSQL not running | Start the service (Windows: `services.msc` → `postgresql-x64-15`; Linux: `sudo service postgresql start`). |
| `password authentication failed` | Wrong password in `.env` | Set `DB_PASSWORD` to your real `postgres` password. |
| `database "csit314_seven" does not exist` | Database not created | `psql -U postgres -c "CREATE DATABASE csit314_seven;"` |
| `Port 8000 already in use` | Another process holds the port | Use another port: `php -S localhost:8001 router.php` (note: `demo.js` and the test bootstrap expect 8000). |
| The lock file is not up to date with `composer.json` | `composer.lock` out of date | Harmless. Fix with `composer update`, then commit the new `composer.lock`. |

### Runtime errors

| Symptom | Cause | Fix |
|---|---|---|
| Stale behaviour after editing JS/CSS | Browser cache | Hard refresh: Ctrl + Shift + R |
| Seeded login says "Invalid email or password" | Seed not run, or data wiped | `php db/seed.php` |
| Lists are empty when they shouldn't be | Seed not run | `php db/seed.php` |
| 403 on a page you should access | Wrong user logged in | Log out and log in with the correct role |
| 500 on any page | Server-side exception | Look at the terminal running `php -S`; the error log names the file and line |

### Testing and `demo.js`

| Symptom | Fix |
|---|---|
| `Error: Cannot find module '...\demo.js'` | You are in the wrong folder. `cd` into the project folder (the one containing `demo.js`) first. Check with `dir` / `ls`. |
| `demo.js`: `fetch failed` / `ECONNREFUSED` | The server is not running. Start `php -S localhost:8000 router.php` in another terminal. |
| `demo.js`: nearly every story shows ❌ | Database is down or not seeded. Check PostgreSQL is running, then `php db/seed.php`. |
| One story shows ❌ | Run that endpoint with `curl`, and check the `php -S` terminal for the error line. |
| `vendor\bin\phpunit` not found | Run `composer install`; PHPUnit lives in `vendor/`. |
| `Could not connect to localhost:8000` in tests | The bootstrap normally starts a server. If it fails, start one manually in another window. |
| Tests fail on the `ON DELETE RESTRICT` assertion | The schema is old. Re-run `psql -U postgres -d csit314_seven -f db/schema.sql` and re-seed. |
| `node` not recognised | Install Node.js 18+ (only needed for `demo.js`). |

---

## Known Limitations

Documented honestly so they are not mistaken for oversights:

- **Suspension takes effect at the next login.** Suspending an account blocks new logins immediately, but an already-logged-in session stays valid until it is logged out or expires.
- **No login rate limiting.** Repeated wrong passwords are not throttled.
- **Search treats `%` and `_` as wildcards** (they are not escaped before `ILIKE`).
- **Session cookie lacks the `Secure` flag.** It should be enabled when deployed over HTTPS.
- **Built-in PHP server.** `php -S` is for development and demos, not production.

---

## CI/CD

GitHub Actions runs the full PHPUnit suite on every push to `main` or `sprint-*` branches, and on every pull request into `main`.

Pipeline:

1. Start a `postgres:15` service container
2. Set up PHP 8.2, Composer and the required extensions
3. `composer install`
4. Apply `db/schema.sql`
5. Run `db/seed.php`
6. Run `vendor/bin/phpunit --testdox`

If the pipeline is red, do not merge. A green run is the entry criterion for review.

---

## Team

| Name | Student ID |
|---|---|
| Sharan Saravana | 1104809 |
| Toh Yong Jie | 1105243 |
| Lee Hongyi, Ryan | 1124535 |
| Marvin Fong Jun Lin | 1124596 |
| Chiw Jia Long | 1137438 |

---

## Academic Context

Developed for **CSIT314 Software Development Methodologies** at the **University of Wollongong (Singapore)** under group name **T04: Seven**.

Deliverables:

- Technical report with 48 user stories, UML diagrams, BCE diagrams and wireframes
- Taiga sprint boards (4 sprints over 7 weeks)
- This repository (source, tests, CI)
- Deployment evidence and integration-test evidence

The report and repository are meant to be read together. Where they disagree, the repository is authoritative, because the report may lag behind the implementation.

This is academic coursework; no open-source licence has been specified.