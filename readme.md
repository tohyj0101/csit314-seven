# CSIT314 T04: Seven — Interior Designer Matching Platform

A full-stack web platform that connects renovation customers with interior designers. Built with PHP, PostgreSQL, and vanilla JavaScript on a Boundary–Control–Entity (BCE) architecture, following Agile/Scrum with CI/CD and integration testing.

**Status:** All 48 user stories implemented. 20 integration tests, 86 assertions, 100% pass rate.

---

## Table of Contents

- Quick Start
- Overview
- Features by Role
- Tech Stack
- Architecture
- Prerequisites
- Full Setup Guide
- Test Credentials
- Running Tests
- Project Structure
- API Endpoints
- Demo Script
- Troubleshooting
- Team
- Academic Context

---

## Quick Start

For experienced developers, run these commands in order:

    git clone https://github.com/tohyj0101/csit314-seven.git
    cd csit314-seven
    composer install
    cp .env.example .env
    psql -U postgres -c "CREATE DATABASE csit314_seven;"
    psql -U postgres -d csit314_seven -f db/schema.sql
    php db/seed.php
    php -S localhost:8000 router.php

Then open http://localhost:8000 and log in as `designer@example.test` / `Password123!`.

Edit `.env` with your actual PostgreSQL password before running `psql`.

---

## Overview

The platform serves four distinct user roles, each with its own permissions and workflow:

- **User Admin** — manages user accounts and user profiles (roles + permissions)
- **Interior Designer** — manages companies and project portfolios; views engagement analytics
- **Customer** — browses and searches public projects; saves favourites
- **Platform Manager** — maintains IDP categories and generates platform-wide reports

Every route is protected by Role-Based Access Control (RBAC) at two levels:

- **Route level** — the boundary layer rejects requests from the wrong role (403 Forbidden)
- **Resource level** — controllers verify ownership before mutating anything (e.g., a designer cannot edit another designer's project)

---

## Features by Role

### User Admin (US #1–#12)

- Login / logout (US #1, #2)
- Create / view / update / suspend / search user accounts (US #3–#7)
- Create / view / update / suspend / search user profiles (US #8–#12)

### Interior Designer (US #13–#14, #15–#29, #37–#38)

- Login / logout (US #13, #14)
- Create / view / update / delete / search companies (US #15–#19)
- Create / view / update / delete / search ongoing projects (US #20–#24)
- Create / view / update / delete / search completed projects (US #25–#29)
- View view-count and shortlist-count metrics per project (US #37, #38)

### Customer (US #30–#36)

- Login / logout (US #35, #36)
- Search and view public projects (US #30, #31)
- Save to favourites; search and view the favourites list (US #32–#34)

### Platform Manager (US #39–#48)

- Login / logout (US #39, #40)
- Create / view / update / delete / search IDP categories (US #41–#45)
- Generate daily / weekly / monthly reports (US #46–#48)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | Vanilla JavaScript SPA (hash routing), HTML5, CSS3 |
| Backend | PHP 8.2 (tested on 8.2.12), no framework — custom Router + BCE controllers |
| Database | PostgreSQL 15 (also works on 14+) |
| Auth | Session cookies + bcrypt password hashing (cost 10) |
| Testing | PHPUnit 10.5 — integration tests via cURL against the live server |
| CI/CD | GitHub Actions — every push runs the full suite against a fresh PostgreSQL service container |

No Node.js, no Composer-managed PHP frameworks, no build step. Just PHP + a browser.

---

## Architecture

Strict Boundary–Control–Entity (BCE) separation:

    Browser  ──────►  Boundary Layer   public/js/app.js
                                       src/bce/boundary.php
                             │
                             ▼
                       Control Layer   src/bce/controllers.php
                       42 controllers  (one per user story or story group)
                             │
                             ▼
                       Entity Layer    src/bce/entities.php
                       7 entity classes
                             │
                             ▼
                       PostgreSQL 15   8 tables

- **Boundary** (`src/bce/boundary.php`) — maps HTTP routes to controllers, applies middleware (`auth_guard()`, `role_guard()`)
- **Control** (`src/bce/controllers.php`) — 42 controllers, one per user story or story group; validates input, enforces ownership, delegates to entities
- **Entity** (`src/bce/entities.php`) — `UserAcc`, `UserProfile`, `CompanyInfo`, `Project`, `Favourite`, `Category`, `Report`; all SQL lives here
- **Frontend** (`public/js/app.js`) — single-page app; treats the backend as a REST API

---

## Prerequisites

**Required:**

- **PHP 8.1+** with extensions: `pdo_pgsql`, `pgsql`, `openssl`, `mbstring`
- **PostgreSQL 14+** (running as a service)
- **Composer** (for `composer install`)

**Optional but recommended:**

- **Git** (to clone the repo)
- **pgAdmin** (to inspect the database visually)

**Verify your environment:**

    php -v
    php -m | Select-String "pdo_pgsql|pgsql|openssl|mbstring"    # Windows
    php -m | grep -E "pdo_pgsql|pgsql|openssl|mbstring"          # macOS / Linux
    composer --version
    psql --version

All four commands must succeed. If any extension is missing from `php -m` output, open `C:\xampp\php\php.ini` (Windows) or `/etc/php/8.2/cli/php.ini` (Linux) and uncomment the corresponding `;extension=...` line.

---

## Full Setup Guide

### 1. Clone the repository

    git clone https://github.com/tohyj0101/csit314-seven.git
    cd csit314-seven

### 2. Install PHP dependencies

    composer install

### 3. Configure environment

Copy the template:

    cp .env.example .env      # macOS / Linux
    copy .env.example .env    # Windows

Edit `.env`:

    DB_HOST=localhost
    DB_PORT=5432
    DB_USER=postgres
    DB_PASSWORD=your_database_password
    DB_NAME=csit314_seven

### 4. Create the database

Option A — via psql:

    psql -U postgres -c "CREATE DATABASE csit314_seven;"

Option B — via pgAdmin:

Right-click **Databases** → **Create** → **Database…** → name it `csit314_seven` → **Save**.

### 5. Apply schema + seed data

    # Build the tables
    psql -U postgres -d csit314_seven -f db/schema.sql

    # Seed base accounts (5 users, 2 companies, 3 categories, 4 projects)
    php db/seed.php

    # Optional: generate 100+ synthetic records for Reports/analytics demo
    php db/generate_test_data.php

### 6. Start the server

    php -S localhost:8000 router.php

Open http://localhost:8000.

For LAN access (e.g., from a phone on the same Wi-Fi):

    php -S 0.0.0.0:8000 router.php

Then access via `http://<your-ip>:8000` from another device.

---

## Test Credentials

All seeded accounts share the password **`Password123!`**.

| Role | Email | Demo Purpose |
|---|---|---|
| User Admin | admin@example.test | User accounts, profiles, RBAC |
| Interior Designer | designer@example.test | Companies, projects, analytics |
| Customer | customer@example.test | Browse, search, favourites |
| Platform Manager | manager@example.test | Categories, reports |
| Suspended | suspended@example.test | Demonstrates 403 on login (US #6, #11) |

Logging in with the suspended account intentionally fails with an HTTP 403 — this proves account-suspension enforcement works end-to-end.

---

## Running Tests

The integration test suite exercises the full Boundary → Control → Entity stack against a live PostgreSQL database.

    php vendor/bin/phpunit --testdox

Expected output:

    OK (20 tests, 86 assertions)

**Coverage:** all 48 user stories across:

- Login / logout flows
- User account and profile CRUD
- Company and project CRUD
- Project search, filter, ownership checks
- Favourites save/search/remove
- Category CRUD
- Report generation (daily / weekly / monthly)
- RBAC enforcement (403 on cross-role access)
- Cascade rules (400 on delete of company with projects)

**Prerequisites:** the local server must be reachable at `http://localhost:8000` (the test bootstrap auto-launches one via `tests/bootstrap.php` if none is running).

---

## Project Structure

    csit314-seven/
    ├── router.php                     # Entry point for PHP built-in server
    ├── composer.json
    ├── .env.example                   # Template (real .env is gitignored)
    │
    ├── db/
    │   ├── schema.sql                 # All 8 table definitions
    │   ├── seed.php                   # Base demo data (5 users, etc.)
    │   └── generate_test_data.php     # Optional: 100+ synthetic records
    │
    ├── src/
    │   ├── bootstrap.php              # Autoloader + .env loader
    │   ├── auth.php                   # Password hashing helpers
    │   ├── db.php                     # PDO wrapper + query helpers (q, q1, ex)
    │   ├── env.php                    # .env file loader
    │   ├── authGuard.php              # auth_guard() middleware
    │   ├── roleGuard.php              # role_guard() middleware
    │   └── bce/
    │       ├── boundary.php           # All 60+ routes
    │       ├── controllers.php        # All 42 controllers
    │       └── entities.php           # All 7 entity classes
    │
    ├── public/
    │   ├── index.html                 # SPA shell
    │   ├── js/
    │   │   └── app.js                 # All frontend logic (~1000 lines)
    │   └── css/
    │       └── style.css
    │
    ├── tests/
    │   ├── AllTest.php                # 20 integration test methods
    │   └── bootstrap.php              # Auto-launches server for tests
    │
    ├── .github/
    │   └── workflows/
    │       └── ci.yml                 # GitHub Actions CI pipeline
    │
    └── README.md                      # This file

---

## API Endpoints

All endpoints under `/api/`. Auth via session cookie — log in first, subsequent requests reuse the session.

### Authentication

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | /api/auth/login | public | Log in |
| POST | /api/auth/logout | any | Log out |
| GET | /api/auth/me | any | Get current user |

### User Accounts (Admin only)

| Method | Path | Description |
|---|---|---|
| GET | /api/users | List all |
| GET | /api/users/search?q= | Search by username/email |
| POST | /api/users | Create |
| GET | /api/users/:id | View |
| PUT | /api/users/:id | Update |
| PATCH | /api/users/:id/suspend | Suspend |
| PATCH | /api/users/:id/activate | Activate |

### User Profiles (Admin only)

| Method | Path | Description |
|---|---|---|
| GET | /api/profiles | List all |
| GET | /api/profiles/search?q= | Search by name |
| POST | /api/profiles | Create |
| GET | /api/profiles/:id | View |
| PUT | /api/profiles/:id | Update |
| PATCH | /api/profiles/:id/suspend | Suspend |
| PATCH | /api/profiles/:id/activate | Activate |

### Companies (Designer only)

| Method | Path | Description |
|---|---|---|
| GET | /api/companies | List own companies |
| GET | /api/companies/search?q= | Search own companies |
| POST | /api/companies | Create |
| GET | /api/companies/:id | View |
| PUT | /api/companies/:id | Update |
| DELETE | /api/companies/:id | Delete (blocked if has projects — 400) |

### Projects (Designer only)

| Method | Path | Description |
|---|---|---|
| POST | /api/projects | Create ongoing |
| POST | /api/projects/completed | Create completed |
| GET | /api/projects/search?q=&status= | Search own projects |
| GET | /api/projects/search/interest | View/save metrics for all own projects |
| GET | /api/projects/:id | View |
| GET | /api/projects/:id/completed | View completed |
| PUT | /api/projects/:id | Update |
| PUT | /api/projects/:id/completed | Update completed |
| DELETE | /api/projects/:id | Delete |
| DELETE | /api/projects/:id/completed | Delete completed |
| GET | /api/projects/company/:companyId | List by company |
| GET | /api/projects/company/:companyId/search | Search within company |
| GET | /api/projects/company/:companyId/completed/search | Search completed |
| GET | /api/projects/company/:companyId/interest | Metrics per project |

### Public Projects (Customer only)

| Method | Path | Description |
|---|---|---|
| GET | /api/projects/public/search?q= | Search all public projects |
| GET | /api/projects/public/:id | View + increment view count |

### Favourites (Customer only)

| Method | Path | Description |
|---|---|---|
| GET | /api/favourites | List own favourites |
| GET | /api/favourites/search?q= | Search own favourites |
| POST | /api/favourites | Save (body: {"project_id": N}) |
| DELETE | /api/favourites/:projectId | Remove |

### Categories (Platform Manager only)

| Method | Path | Description |
|---|---|---|
| GET | /api/categories | List all |
| GET | /api/categories/search?q= | Search by name |
| GET | /api/categories/:id | View |
| POST | /api/categories | Create |
| PUT | /api/categories/:id | Update |
| DELETE | /api/categories/:id | Delete |

### Reports (Platform Manager only)

| Method | Path | Description |
|---|---|---|
| GET | /api/reports/daily | Last 24h summary |
| GET | /api/reports/weekly | Last 7d summary |
| GET | /api/reports/monthly | Last 30d summary |

### Error Responses

Every error returns a rich JSON payload:

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

The frontend renders these as styled error toasts.

---

## Demo Script

For a live demo, here is a suggested flow that exercises the platform end-to-end in about 5 minutes:

1. **Login as admin** — show that the nav bar only contains admin modules (RBAC working).
2. **Logout. Login as `suspended@example.test`** — show the 403 error toast.
3. **Login as designer** — create a new company, create a new project, view the project analytics (views + saves).
4. **Logout. Login as customer** — browse the Discovery Gallery, save a project to favourites, view the favourites list, remove a favourite.
5. **Logout. Login as manager** — create a new category, generate a daily report, generate a weekly report.
6. **Login as designer, try to delete a company that has projects** — show the 400 error (proves ON DELETE RESTRICT is protecting data).

---

## Troubleshooting

### First-run errors

| Symptom | Cause | Fix |
|---|---|---|
| `php` not recognised | PHP not on PATH | Add C:\xampp\php to User PATH via sysdm.cpl → Environment Variables → Path → New. Reopen the shell. |
| `could not find driver` | `pdo_pgsql` not enabled | Open php.ini, uncomment `;extension=pdo_pgsql`. Restart shell. |
| `Class "PDO" not found` | `pdo` extension not enabled | Uncomment `;extension=pdo` in php.ini. |
| `SQLSTATE[08006] connection refused` | PostgreSQL not running | Services (Win+R → services.msc) → start `postgresql-x64-15`. |
| `password authentication failed` | `.env` password mismatch | Set DB_PASSWORD in .env to your actual postgres user password. |
| `database "csit314_seven" does not exist` | Database not created | psql -U postgres -c "CREATE DATABASE csit314_seven;" |
| `Port 8000 already in use` | Another process holds the port | php -S localhost:8001 router.php |

### Runtime errors

| Symptom | Cause | Fix |
|---|---|---|
| Stale behaviour after editing code | Browser cache | Hard refresh: Ctrl + Shift + R |
| Login says "Invalid email or password" for seeded account | Seed was not run, or was wiped | php db/seed.php |
| Lists show empty when they shouldn't | Seed was not run | php db/seed.php |
| 403 on a page you should be able to access | Wrong user logged in | Check role at top of dashboard; log out and log in as the correct role |
| 500 error on any page | Server-side exception | Check the PHP server window — the error log names the failing file and line |

### Testing

| Symptom | Fix |
|---|---|
| `vendor\bin\phpunit` not found | Run composer install — PHPUnit lives in vendor/ |
| `Could not connect to localhost:8000` | The test bootstrap auto-launches a server, but if it fails, run php -S localhost:8000 router.php in a separate window first |
| Tests fail on ON DELETE RESTRICT assertion | Schema wasn't updated — re-run psql -U postgres -d csit314_seven -f db/schema.sql |

---

## CI/CD

GitHub Actions runs the full test suite on every push to `main` or `sprint-*` branches, and on every pull request targeting `main`.

The pipeline:

1. Spins up a `postgres:15` service container
2. Installs PHP 8.2 + Composer + required extensions
3. Runs `composer install`
4. Applies `db/schema.sql`
5. Runs `db/seed.php`
6. Runs `vendor/bin/phpunit --testdox`

If the pipeline is red, do not merge. Green CI is the entry criterion for review.

---

## Team

| Name | Student ID | Role |
|---|---|---|
| Sharan Saravana | 1104809 | Team Lead / System Designer |
| Toh Yong Jie | 1105243 | Backend Dev / DevOps |
| Lee Hongyi, Ryan | 1124535 | System Designer |
| Marvin Fong Jun Lin | 1124596 | System Designer |
| Chiw Jia Long | 1137438 | System Designer / UI/UX |

---

## Academic Context

This project was developed for **CSIT314 Software Development Methodologies** at the **University of Wollongong (Singapore)** under the group name **T04: Seven**.

Deliverables include:

- Full technical report with 48 user stories, UML diagrams, BCE diagrams, and wireframes
- Taiga sprint boards (4 sprints over 7 weeks)
- This repository (source + tests + CI)
- Deployment evidence and integration test evidence

The report and repository are intended to be read together. Where they conflict, the repository is authoritative — the report may lag behind implementation.

---

## License

Academic project for CSIT314. Not licensed for commercial use. If you wish to reuse any code, contact the team first.