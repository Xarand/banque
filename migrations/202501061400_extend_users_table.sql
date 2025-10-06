-- Migration: étendre la table users pour ajouter les colonnes attendues par le code
-- Ajoute display_name, failed_logins, last_login_at, updated_at si elles n'existent pas encore.
-- NOTE : SQLite ne supporte pas IF NOT EXISTS sur ADD COLUMN. Si une colonne existe déjà, supprime la ligne correspondante avant d'exécuter.

ALTER TABLE users ADD COLUMN display_name TEXT;
ALTER TABLE users ADD COLUMN failed_logins INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN last_login_at TEXT;
ALTER TABLE users ADD COLUMN updated_at TEXT;

-- Initialiser display_name si vide
UPDATE users SET display_name = email WHERE display_name IS NULL OR display_name = '';

-- (Optionnel) Initialiser updated_at maintenant
UPDATE users SET updated_at = created_at WHERE updated_at IS NULL;