-- =============================================================================
-- CSIT314 T04: Seven - PostgreSQL Schema
-- =============================================================================
-- Purpose: Implements the 8-table data model described in the report
--          (Sprints 1-4, Ethical Considerations).
-- Correspondence to report:
--   user_profile     -> RBAC layer described on p.195
--   user_account     -> Login/logout (US #1-#7)
--   company          -> US #15-#19
--   category         -> US #41-#45
--   project          -> US #20-#31, #37-#38 (with `status` for ongoing vs completed)
--   project_category -> Many-to-many link (design categorisation)
--   project_view     -> Aggregated metrics (US #37) - customer_id kept for
--                       de-duplication but NEVER returned by the API (privacy, p.195)
--   favourite        -> US #32-#34
--
-- Design notes:
--   * ON DELETE RESTRICT on project.company_id is INTENTIONAL: it backs the
--     report claim (Sprint 2 meeting) that a company with projects cannot be
--     deleted - surfaced as HTTP 400 in DeleteCompanyController.
--   * ON DELETE CASCADE on project_view.customer_id and favourite.customer_id
--     ensures GDPR/ethics clean-up when a customer account is removed.
--   * NULLABLE customer_id on project_view supports "anonymous view" if the
--     policy ever changes; currently unused because ViewPublicProjectController
--     runs behind auth_guard.
-- =============================================================================

DROP TABLE IF EXISTS favourite, project_view, project_category, category, project, company, user_account, user_profile CASCADE;

CREATE TABLE user_profile (
  profile_id  SERIAL PRIMARY KEY,
  profile_name VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,
  permissions TEXT[] NOT NULL DEFAULT '{}',      -- e.g. '{user:manage,profile:manage}'
  status      VARCHAR(20) NOT NULL DEFAULT 'Active'
              CHECK (status IN ('Active','Suspended'))
);

CREATE TABLE user_account (
  account_id    SERIAL PRIMARY KEY,
  profile_id    INT NOT NULL REFERENCES user_profile(profile_id),
  username      VARCHAR(100) UNIQUE NOT NULL,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,           -- Bcrypt cost 10 (see src/config/auth.php)
  status        VARCHAR(20) NOT NULL DEFAULT 'Active'
                CHECK (status IN ('Active','Suspended')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE company (
  company_id   SERIAL PRIMARY KEY,
  owner_id     INT NOT NULL REFERENCES user_account(account_id) ON DELETE CASCADE,
  company_name VARCHAR(200) NOT NULL,
  address      TEXT,
  contact_no   VARCHAR(50),                      -- Validated to 8 digits in controllers.php
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE category (
  category_id   SERIAL PRIMARY KEY,
  category_name VARCHAR(100) UNIQUE NOT NULL,
  description   TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE project (
  project_id  SERIAL PRIMARY KEY,
  company_id  INT NOT NULL REFERENCES company(company_id) ON DELETE RESTRICT, -- protects data (Sprint 2)
  title       VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'Ongoing'
              CHECK (status IN ('Ongoing','Completed')),
  date        DATE,                              -- Populated by generate_test_data.php for report analytics
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE project_category (
  project_id  INT NOT NULL REFERENCES project(project_id) ON DELETE CASCADE,
  category_id INT NOT NULL REFERENCES category(category_id) ON DELETE CASCADE,
  PRIMARY KEY (project_id, category_id)
);

CREATE TABLE project_view (
  view_id     SERIAL PRIMARY KEY,
  project_id  INT NOT NULL REFERENCES project(project_id) ON DELETE CASCADE,
  customer_id INT REFERENCES user_account(account_id) ON DELETE SET NULL,
  viewed_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE favourite (
  favourite_id SERIAL PRIMARY KEY,
  customer_id  INT NOT NULL REFERENCES user_account(account_id) ON DELETE CASCADE,
  project_id   INT NOT NULL REFERENCES project(project_id) ON DELETE CASCADE,
  saved_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(customer_id, project_id)                -- enforces idempotent POST /favourites
);