-- Ajouter colonne micro_code dans transactions (si absente)
ALTER TABLE transactions ADD COLUMN micro_code TEXT NULL;

-- Étendre micro_entreprises pour les taxes consulaires et région
ALTER TABLE micro_entreprises ADD COLUMN consular_type TEXT NOT NULL DEFAULT 'NONE' CHECK(consular_type IN ('CMA','CCI','DOUBLE','NONE'));
ALTER TABLE micro_entreprises ADD COLUMN region_code  TEXT NOT NULL DEFAULT 'FR'   CHECK(region_code IN ('FR','AL','MO'));

-- Référentiel des codes activités avec multi-taux
CREATE TABLE IF NOT EXISTS micro_activity_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  year INTEGER NOT NULL,
  code TEXT NOT NULL,                               -- ex: '508','518','781','781_SSI','MEUBLE_TOURISME'
  label TEXT NOT NULL,
  family TEXT NOT NULL CHECK(family IN ('VENTE','SERVICE_BIC','LIB_CIPAV','LIB_SSI','MEUBLE_TOURISME','AUTRE')),
  social_rate REAL NOT NULL,                        -- taux micro-social
  ir_rate REAL NOT NULL,                            -- taux versement libératoire (0 si n/a)
  cfp_family TEXT NOT NULL CHECK(cfp_family IN ('ARTISAN','COMMERCANT','LIBERAL')),
  cfp_rate REAL NOT NULL,                           -- taux CFP appliqué au CA de la famille
  -- Taxes CMA (appliquées si consular_type = CMA ou DOUBLE)
  cma_rate_vente_std REAL DEFAULT 0,
  cma_rate_vente_al  REAL DEFAULT 0,
  cma_rate_vente_mo  REAL DEFAULT 0,
  cma_rate_service_std REAL DEFAULT 0,
  cma_rate_service_al  REAL DEFAULT 0,
  cma_rate_service_mo  REAL DEFAULT 0,
  -- Taxes CCI (appliquées si consular_type = CCI ou DOUBLE)
  cci_rate_vente REAL DEFAULT 0,
  cci_rate_service REAL DEFAULT 0,
  cci_rate_double  REAL DEFAULT 0,  -- part additionnelle double immatriculation
  UNIQUE(year, code)
);

-- Ajout d'une colonne JSON (optionnel) pour stocker la ventilation calculée sur les échéances
ALTER TABLE micro_contribution_deadlines ADD COLUMN breakdown_json TEXT NULL;