-- Unternehmen Plus – Datenbankschema (MariaDB / MySQL)
-- Wird von install.php eingespielt. Idempotent (CREATE TABLE IF NOT EXISTS).
-- Zeichensatz durchgaengig utf8mb4.

SET NAMES utf8mb4;
SET foreign_key_checks = 1;

-- ---------------------------------------------------------------------------
-- Schulen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schools (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(190) NOT NULL,
    short_name  VARCHAR(60)  NULL,
    city        VARCHAR(120) NULL,
    logo_path   VARCHAR(255) NULL,
    note        TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schools_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Benutzer (Rollen: admin = Projektleitung, teacher = Lehrkraft, juror = Jury)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role          ENUM('admin','teacher','juror') NOT NULL,
    name          VARCHAR(190) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    school_id     INT UNSIGNED NULL,               -- fuer Lehrkraefte
    specialty     VARCHAR(190) NULL,               -- Spezialgebiet (Juroren)
    phone         VARCHAR(60)  NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_school (school_id),
    CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Teams (= Projekte / Geschaeftsideen)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(190) NOT NULL,             -- Team-/Projektname
    idea_name   VARCHAR(190) NULL,                 -- Name der Geschaeftsidee
    idea_pitch  TEXT NULL,                         -- Kurzbeschreibung
    -- draft: in Arbeit | submitted: Businessplan eingereicht
    -- nominated: fuer Pitch nominiert | fallback: Nachrueck | eliminated: raus
    status      ENUM('draft','submitted','nominated','fallback','eliminated') NOT NULL DEFAULT 'draft',
    pitch_order INT UNSIGNED NULL,                 -- Reihenfolge am Pitch-Day
    final_rank  INT UNSIGNED NULL,                 -- Endplatzierung
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_teams_school (school_id),
    KEY idx_teams_status (status),
    CONSTRAINT fk_teams_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Schueler:innen (Teammitglieder)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(190) NOT NULL,
    role_color  VARCHAR(30) NULL,                  -- Persoenlichkeitstyp/Farbe aus Teambuilding
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_students_team (team_id),
    CONSTRAINT fk_students_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Businesspläne (hochgeladene PDFs, versioniert je Team)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_plans (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id       INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,           -- Dateiname in storage/uploads
    mime          VARCHAR(120) NULL,
    size_bytes    INT UNSIGNED NULL,
    version       INT UNSIGNED NOT NULL DEFAULT 1,
    is_current    TINYINT(1) NOT NULL DEFAULT 1,
    uploaded_by   INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bp_team (team_id),
    KEY idx_bp_current (team_id, is_current),
    CONSTRAINT fk_bp_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_bp_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- KI-Vorbewertung eines Businessplans (Kopf)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_evaluations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_plan_id INT UNSIGNED NOT NULL,
    model            VARCHAR(80) NULL,
    status           ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    total_score      DECIMAL(5,1) NULL,            -- Summe der 5 BP-Kriterien (max 50)
    meets_minimum    TINYINT(1) NULL,              -- Mindeststandard-Gate (1=erfuellt, 0=nicht)
    min_reason       TEXT NULL,                    -- Begruendung zum Gate
    summary          TEXT NULL,                    -- Gesamteinschaetzung
    strengths        TEXT NULL,
    weaknesses       TEXT NULL,
    raw_json         LONGTEXT NULL,                -- vollstaendige KI-Antwort
    error_message    TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aieval_bp (business_plan_id),
    CONSTRAINT fk_aieval_bp FOREIGN KEY (business_plan_id) REFERENCES business_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KI-Vorbewertung: Einzelkriterien (0-10 + Begruendung)
CREATE TABLE IF NOT EXISTS ai_evaluation_scores (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ai_evaluation_id  INT UNSIGNED NOT NULL,
    criterion_key     VARCHAR(30) NOT NULL,
    score             DECIMAL(4,1) NOT NULL,
    rationale         TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aiscore (ai_evaluation_id, criterion_key),
    CONSTRAINT fk_aiscore_eval FOREIGN KEY (ai_evaluation_id) REFERENCES ai_evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Struktur-/Mindeststandard-Check (eigener, günstiger KI-Pass gegen die Vorlage)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS structure_checks (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_plan_id INT UNSIGNED NOT NULL,
    model            VARCHAR(80) NULL,
    status           ENUM('running','done','error') NOT NULL DEFAULT 'running',
    meets_minimum    TINYINT(1) NULL,
    reason           TEXT NULL,
    sections_json    LONGTEXT NULL,
    error_message    TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sc_bp (business_plan_id),
    CONSTRAINT fk_sc_bp FOREIGN KEY (business_plan_id) REFERENCES business_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Jury-Bewertung: eine Bewertung je Juror:in und Team (Kopf)
-- Enthaelt Businessplan-Phase (5 Kriterien) und optional Pitch-Phase (4 Kriterien)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    juror_id       INT UNSIGNED NOT NULL,
    team_id        INT UNSIGNED NOT NULL,
    bp_submitted   TINYINT(1) NOT NULL DEFAULT 0,  -- Businessplan-Bewertung abgegeben
    pitch_submitted TINYINT(1) NOT NULL DEFAULT 0, -- Pitch-Bewertung abgegeben
    bp_total       DECIMAL(5,1) NULL,              -- Summe 5 BP-Kriterien (max 50)
    pitch_total    DECIMAL(5,1) NULL,              -- Summe 4 Pitch-Kriterien (max 40)
    grand_total    DECIMAL(6,1) NULL,              -- 2*bp_total + 1*pitch_total (max 140)
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eval_juror_team (juror_id, team_id),
    KEY idx_eval_team (team_id),
    CONSTRAINT fk_eval_juror FOREIGN KEY (juror_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_eval_team  FOREIGN KEY (team_id)  REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jury-Bewertung: Einzelkriterien (0-10 + Notizen)
CREATE TABLE IF NOT EXISTS evaluation_scores (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    evaluation_id  INT UNSIGNED NOT NULL,
    criterion_key  VARCHAR(30) NOT NULL,
    phase          ENUM('businessplan','pitch') NOT NULL,
    points         TINYINT NOT NULL,               -- 0-10
    notes          TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evalscore (evaluation_id, criterion_key),
    CONSTRAINT fk_evalscore_eval FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Frühes Jury-Feedback zur Geschaeftsidee (Ampel grün/gelb/rot je Kategorie)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jury_feedback (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id       INT UNSIGNED NOT NULL,
    juror_id      INT UNSIGNED NULL,
    category_key  VARCHAR(30) NOT NULL,
    rating        ENUM('green','yellow','red') NULL,
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_feedback_team (team_id),
    CONSTRAINT fk_feedback_team  FOREIGN KEY (team_id)  REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_juror FOREIGN KEY (juror_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Material / Vorlagen (Downloads) – Sichtbarkeit je Rolle
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS materials (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(190) NOT NULL,
    description   TEXT NULL,
    original_name VARCHAR(255) NULL,
    stored_name   VARCHAR(255) NULL,
    link_url      VARCHAR(500) NULL,               -- alternativ externer Link
    visibility    ENUM('all','teacher','juror','admin') NOT NULL DEFAULT 'all',
    sort_order    INT NOT NULL DEFAULT 0,
    uploaded_by   INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_materials_vis (visibility),
    CONSTRAINT fk_materials_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Einstellungen (Key/Value) – z.B. Termine, Phase, Anzahl Pitch-Plaetze
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    k          VARCHAR(80) NOT NULL,
    v          TEXT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Sponsoren + Beiträge je Wettbewerbsjahr (Geld- oder Sachleistung)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sponsors (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(190) NOT NULL,
    logo_path    VARCHAR(255) NULL,
    address      TEXT NULL,
    contact_name VARCHAR(190) NULL,
    email        VARCHAR(190) NULL,
    website      VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sponsors_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsor_contributions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sponsor_id  INT UNSIGNED NOT NULL,
    year        SMALLINT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NULL,
    description VARCHAR(190) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contrib_sponsor (sponsor_id),
    KEY idx_contrib_year (year),
    CONSTRAINT fk_contrib_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
