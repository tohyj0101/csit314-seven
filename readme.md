# CSIT314 T04: Seven — Interior Designer Matching Platform (PHP)

An online platform designed to match renovation customers to interior designers, built for the
**CSIT314 Software Development Methodologies** group project at the University of Wollongong.


## 🏗️ Architecture & Methodologies

Follows the **Boundary-Control-Entity (BCE)** framework. Agile (Scrum) process.

### Tech Stack
* **Backend:** PHP 8.1+, PDO (PostgreSQL), built-in session handling
* **Database:** PostgreSQL 14+
* **Frontend:** HTML5, CSS3, Vanilla JavaScript (unchanged)
* **Testing:** PHPUnit (integration tests via HTTP + cURL)
* **CI/CD:** GitHub Actions

### BCE Layer Structure
* **Boundary (`src/bce/boundary.php`)** — route table + RBAC middleware
* **Control  (`src/bce/controllers.php`)** — validation, business rules, session/ownership checks
* **Entity   (`src/bce/entities.php`)** — parameterized SQL via PDO (no SQL injection)

## 🚀 Getting Started

### Prerequisites
* PHP ≥ 8.1 with `pdo_pgsql` and `pgsql` extensions
* [Composer](https://getcomposer.org/)
* PostgreSQL ≥ 14

### 1. Install

```bash
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
composer install
cp .env.example .env  # then edit DB_* if needed