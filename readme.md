# CSIT314 T04: Seven - Interior Designer Matching Platform

An online platform designed to match renovation customers to interior designers, built for the **CSIT314 Software Development Methodologies** group project at the University of Wollongong.

## 🏗️ Architecture & Methodologies

This project strictly follows the **Boundary-Control-Entity (BCE)** architectural framework and was developed using **Agile (Scrum)** methodologies. The frontend is a responsive, vanilla JavaScript Single Page Application (SPA).

### Tech Stack
*   **Backend:** Node.js, Express.js
*   **Database:** PostgreSQL
*   **Frontend:** HTML5, CSS3, Vanilla JavaScript
*   **Testing:** Jest, Supertest
*   **CI/CD:** GitHub Actions

### BCE Layer Structure
*   **Boundary (`bce/routes.js`):** Express routers that intercept HTTP requests, handle role-based access control (RBAC) middleware, and delegate to controllers.
*   **Control (`bce/controllers.js`):** Contains the core business logic, data validation, and state management.
*   **Entity (`bce/entities.js`):** Handles all direct PostgreSQL database interactions using parameterized SQL queries to prevent SQL injection.

---

## 🚀 Getting Started

### Prerequisites
Make sure you have the following installed on your machine:
*   [Node.js](https://nodejs.org/) (v20 or higher)
*   [PostgreSQL](https://www.postgresql.org/) (v14 or higher)

### 1. Installation
Clone the repository and install the dependencies:
```bash
git clone https://github.com/tohyj0101/csit314-seven.git
cd csit314-seven
npm install
