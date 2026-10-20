-- ============================================================
--  Plateforme de formation en ligne (grammaire anglais / français)
--  Schéma MySQL 8 / MariaDB 10.6+  -  utf8mb4
--  Usage : mysql -u root -p < database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS formation_en_ligne
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE formation_en_ligne;

-- ------------------------------------------------------------
-- Utilisateurs : étudiants et administrateurs (champ "role")
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilisateur (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom            VARCHAR(100) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  mot_de_passe   VARCHAR(255) NOT NULL,              -- hash (jamais le mot de passe en clair)
  role           ENUM('etudiant','admin') NOT NULL DEFAULT 'etudiant',
  actif          TINYINT(1) NOT NULL DEFAULT 1,      -- 0 = compte désactivé
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_utilisateur_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Cours (gratuits) -> Modules -> Leçons -> Exercices
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cours (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titre        VARCHAR(200) NOT NULL,
  description  TEXT NULL,
  langue       ENUM('fr','en') NOT NULL,
  publie       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_cours_langue (langue)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS module (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cours  INT UNSIGNED NOT NULL,
  titre     VARCHAR(200) NOT NULL,
  ordre     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_module_ordre (id_cours, ordre),
  CONSTRAINT fk_module_cours FOREIGN KEY (id_cours)
    REFERENCES cours (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lecon (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_module    INT UNSIGNED NOT NULL,
  titre        VARCHAR(200) NOT NULL,
  url_contenu  VARCHAR(500) NULL,
  duree        SMALLINT UNSIGNED NULL,               -- minutes
  PRIMARY KEY (id),
  KEY idx_lecon_module (id_module),
  CONSTRAINT fk_lecon_module FOREIGN KEY (id_module)
    REFERENCES module (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exercice (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_lecon        INT UNSIGNED NOT NULL,
  question        TEXT NOT NULL,
  type            ENUM('qcm','texte_a_trous','reponse_courte') NOT NULL,
  options         TEXT NULL,                          -- JSON : choix possibles (QCM)
  bonne_reponse   VARCHAR(255) NOT NULL,
  explication     TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_exercice_lecon (id_lecon),
  CONSTRAINT fk_exercice_lecon FOREIGN KEY (id_lecon)
    REFERENCES lecon (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Inscription : table associative Utilisateur <-> Cours
-- Une demande (en_attente) est validée ou refusée par un admin.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inscription (
  id_user          INT UNSIGNED NOT NULL,
  id_cours         INT UNSIGNED NOT NULL,
  id_admin         INT UNSIGNED NULL,                 -- admin qui a traité la demande
  `date`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_validation  DATETIME NULL,
  statut           ENUM('en_attente','validee','refusee','annulee') NOT NULL DEFAULT 'en_attente',
  PRIMARY KEY (id_user, id_cours),
  KEY idx_inscription_statut (statut),
  CONSTRAINT fk_insc_user  FOREIGN KEY (id_user)  REFERENCES utilisateur (id) ON DELETE CASCADE,
  CONSTRAINT fk_insc_cours FOREIGN KEY (id_cours) REFERENCES cours (id)       ON DELETE CASCADE,
  CONSTRAINT fk_insc_admin FOREIGN KEY (id_admin) REFERENCES utilisateur (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Progression : table associative Utilisateur <-> Leçon
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS progression (
  id_user      INT UNSIGNED NOT NULL,
  id_lecon     INT UNSIGNED NOT NULL,
  pourcentage  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  terminee     TINYINT(1) NOT NULL DEFAULT 0,
  date_maj     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user, id_lecon),
  CONSTRAINT chk_progression_pct CHECK (pourcentage <= 100),
  CONSTRAINT fk_prog_user  FOREIGN KEY (id_user)  REFERENCES utilisateur (id) ON DELETE CASCADE,
  CONSTRAINT fk_prog_lecon FOREIGN KEY (id_lecon) REFERENCES lecon (id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tentative : résultats des exercices de chaque étudiant
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tentative (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user      INT UNSIGNED NOT NULL,
  id_exercice  INT UNSIGNED NOT NULL,
  reponse      VARCHAR(255) NOT NULL,
  correct      TINYINT(1) NOT NULL,
  `date`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tentative_user (id_user, id_exercice),
  CONSTRAINT fk_tent_user FOREIGN KEY (id_user)     REFERENCES utilisateur (id) ON DELETE CASCADE,
  CONSTRAINT fk_tent_exo  FOREIGN KEY (id_exercice) REFERENCES exercice (id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Sécurité : sessions stockées en base (révocables côté serveur)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id             VARCHAR(128) NOT NULL,
  user_id        INT UNSIGNED NULL,
  data           MEDIUMBLOB NOT NULL,
  last_activity  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_activity (last_activity),
  KEY idx_sessions_user (user_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Sécurité : limitation des tentatives de connexion (anti force brute)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_email (email, created_at),
  KEY idx_attempts_ip (ip, created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Compte applicatif à privilèges limités (jamais "root" dans l'appli).
-- CHANGEZ le mot de passe, puis reportez-le dans backend/.env
-- ------------------------------------------------------------
-- CREATE USER 'formation_app'@'localhost' IDENTIFIED BY 'CHANGEZ_MOI_mot_de_passe_long';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON formation_en_ligne.* TO 'formation_app'@'localhost';
-- FLUSH PRIVILEGES;
