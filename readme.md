# CSIT314 T04: Seven - Interior Designer Matching Platform

An online platform designed to match renovation customers to interior designers, built for the **CSIT314 Software Development Methodologies** group project at the University of Wollongong.

## 🏗️ Architecture & Methodologies

This project strictly follows the **Boundary-Control-Entity (BCE)** architectural framework and was developed using **Agile (Scrum)** methodologies.

*   **Backend:** Node.js, Express.js
*   **Database:** PostgreSQL
*   **Frontend:** Vanilla HTML, CSS, JavaScript (Single Page Application)
*   **Testing:** Jest, Supertest (Test-Driven Development)
*   **CI/CD:** GitHub Actions (Automated cloud testing pipeline)

### BCE Layer Structure
*   **Boundary (`bce/routes.js`):** Express routers that handle HTTP requests and route them to controllers.
*   **Control (`bce/controllers.js`):** Contains the core business logic, validation, and role-based access control.
*   **Entity (`bce/entities.js`):** Handles all direct PostgreSQL database interactions (SQL queries).

---

## 🚀 Getting Started

### Prerequisites
Make sure you have the following installed on your machine:
*   [Node.js](https://nodejs.org/) (v20 or higher)
*   [PostgreSQL](https://www.postgresql.org/) (v14 or higher)

### 1. Installation
Clone the repository and install the dependencies:
```bash
git clone https://github.com/YOUR-USERNAME/YOUR-REPO-NAME.git
cd csit314-seven
npm install