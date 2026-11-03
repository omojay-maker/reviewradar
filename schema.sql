-- ---------------------------------------------------------------------------
-- ReviewRadar - Multi-Tenant VoC / Sentiment Platform
-- MySQL 8.0+ (JSON_TABLE used by analytics queries requires 8.0.4+)
-- ---------------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS reviewradar
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE reviewradar;

-- ---------------------------------------------------------------------------
-- companies: one row per SaaS tenant (multi-tenant boundary = company_id)
-- ---------------------------------------------------------------------------
CREATE TABLE companies (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(191) NOT NULL,
    email         VARCHAR(191) NOT NULL COMMENT 'login credential',
    password_hash VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
    api_key       CHAR(64)     NOT NULL COMMENT 'bin2hex(random_bytes(32)) - sent as X-API-Key',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_slug (slug),
    UNIQUE KEY uq_companies_email (email),
    UNIQUE KEY uq_companies_api_key (api_key),
    KEY idx_companies_is_active (is_active)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- reviews: raw review text + Groq structured analysis, scoped to one tenant
-- ---------------------------------------------------------------------------
CREATE TABLE reviews (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    company_id      INT UNSIGNED  NOT NULL,
    source          VARCHAR(32)   NOT NULL DEFAULT 'api' COMMENT 'api | webhook | csv | url | manual',
    external_id     VARCHAR(191)  DEFAULT NULL COMMENT 'id at the review source, unique per company',
    author_name     VARCHAR(255)  DEFAULT NULL,
    rating          DECIMAL(3, 1) DEFAULT NULL COMMENT 'original source rating, 1.0 - 5.0',
    source_url      VARCHAR(500)  DEFAULT NULL,
    raw_text        TEXT          NOT NULL,
    sentiment       ENUM('positive', 'neutral', 'negative') NOT NULL,
    score           DECIMAL(4, 3) NOT NULL COMMENT '-1.000 to 1.000',
    categories      JSON          NOT NULL COMMENT 'string[] of lowercase topic tags',
    summary         VARCHAR(512)  NOT NULL COMMENT 'one-sentence AI summary',
    requires_action TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'urgent follow-up flag',
    model           VARCHAR(64)   NOT NULL COMMENT 'Groq model used for analysis',
    analyzed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reviews_company_external (company_id, external_id),
    KEY idx_reviews_company_created (company_id, created_at),
    KEY idx_reviews_company_sentiment (company_id, sentiment),
    KEY idx_reviews_company_urgent (company_id, requires_action, created_at),
    CONSTRAINT fk_reviews_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Development seed (uncomment for local testing)
-- api_key is 64 hex chars (bin2hex(random_bytes(32)))
-- ---------------------------------------------------------------------------
-- INSERT INTO companies (name, slug, email, password_hash, api_key)
-- VALUES (
--     'Acme Corp',
--     'acme-corp',
--     'founder@acme.example.com',
--     '$2y$10$REPLACE_WITH_password_hash_OUTPUT',
--     '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'
-- );
