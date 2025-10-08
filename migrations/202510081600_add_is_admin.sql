-- Ajoute une colonne is_admin aux utilisateurs si absente.
-- SQLite ne supporte pas IF NOT EXISTS sur ADD COLUMN ; on tente puis on ignore l'erreur en migration.
ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0;