-- Ajout colonne tva_ceiling_major si elle n'existe pas (procédure manuelle possible).
ALTER TABLE micro_enterprises ADD COLUMN tva_ceiling_major REAL;