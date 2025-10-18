-- Étendre / modifier les valeurs possibles d'activité micro.
-- SQLite ne permet pas de modifier directement le CHECK facilement, donc solution :
-- 1. Créer une table temporaire, 2. copier, 3. remplacer. (SEULEMENT si nécessaire)
-- Si tu veux éviter la recréation, tu peux laisser l'ancien CHECK et stocker des codes internes
-- et juste afficher les libellés. Ci-dessous version complète "rebuild".

PRAGMA foreign_keys = OFF;

CREATE TABLE micro_entreprises_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL UNIQUE,
  business_name TEXT NOT NULL,
  creation_date TEXT NOT NULL,
  -- NOUVEAU JEU DE VALEURS
  activity_type TEXT NOT NULL CHECK(activity_type IN (
    'vente_logement',
    'service_bic',
    'liberale_cipav',
    'liberale_ssi',
    'meuble_tourisme'
  )),
  income_tax_flat INTEGER NOT NULL CHECK(income_tax_flat IN (0,1)),
  contribution_period TEXT NOT NULL CHECK(contribution_period IN ('mensuelle','trimestrielle')),
  consular_type TEXT NOT NULL DEFAULT 'NONE' CHECK(consular_type IN ('CMA','CCI','DOUBLE','NONE')),
  region_code TEXT NOT NULL DEFAULT 'FR' CHECK(region_code IN ('FR','AL','MO')),
  created_at TEXT NOT NULL DEFAULT (date('now')),
  updated_at TEXT NOT NULL DEFAULT (date('now')),
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

INSERT INTO micro_entreprises_new
(id,account_id,business_name,creation_date,activity_type,income_tax_flat,contribution_period,consular_type,region_code,created_at,updated_at)
SELECT id,account_id,business_name,creation_date,
       CASE activity_type
            WHEN 'vente'    THEN 'vente_logement'
            WHEN 'service'  THEN 'service_bic'
            WHEN 'liberale' THEN 'liberale_ssi'  -- par défaut : bascule vers libérale SSI
            ELSE 'vente_logement'
       END,
       income_tax_flat,contribution_period,consular_type,region_code,created_at,updated_at
FROM micro_entreprises;

DROP TABLE micro_entreprises;
ALTER TABLE micro_entreprises_new RENAME TO micro_entreprises;

PRAGMA foreign_keys = ON;

-- Aucune modification sur les statuts d'échéances (on garde pending/paid/skipped internes)