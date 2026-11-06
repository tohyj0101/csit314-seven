# CSIT314 T04: Seven — Interior Designer Matching Platform (PHP)

An online platform designed to match renovation customers to interior designers, built for the
**CSIT314 Software Development Methodologies** group project at the University of Wollongong.

## 🏗️ Architecture & Methodologies

Follows the **Boundary-Control-Entity (BCE)** framework. Agile (Scrum) process.

### Tech Stack
* **Backend:** PHP 8.1+, PDO (PostgreSQL), built-in session handling
* **Database:** PostgreSQL 14+
* **Frontend:** HTML5, CSS3, Vanilla JavaScript (no external frameworks)
* **Testing:** PHPUnit (integration tests via HTTP + cURL)
* **CI/CD:** GitHub Actions

### BCE Layer Structure
* **Boundary (`src/bce/boundary.php`)** — Route table + RBAC (Role-Based Access Control) middleware.
* **Control  (`src/bce/controllers.php`)** — 42 distinct controllers handling validation, business rules, session, and ownership checks.
* **Entity   (`src/bce/entities.php`)** — Parameterized SQL via PDO (prevents SQL injection).
* **Error Handling** — Unified `display_error()` pipeline ensuring backend exceptions map to a rich, accessible frontend UI (toasts, inline banners, and page-level alerts).

## 🚀 Getting Started

### Prerequisites
* PHP ≥ 8.1 with `pdo_pgsql` and `pgsql` extensions enabled in `php.ini`
* [Composer](https://getcomposer.org/)
* PostgreSQL ≥ 14

### 1. Install Dependencies

```bash
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
composer install

### Quick start (Windows)

```powershell
.\demo.ps1