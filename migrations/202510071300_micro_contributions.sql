-- MISE À JOUR STRUCTURE MICRO & CONTRIBUTIONS

-- 1. Colonne exclusion CA sur transactions
ALTER TABLE transactions ADD COLUMN exclude_from_ca INTEGER NOT NULL DEFAULT 0;

-- 2. Extension micro_enterprises
ALTER TABLE micro_enterprises ADD COLUMN activity_code TEXT;
ALTER TABLE micro_enterprises ADD COLUMN contributions_frequency TEXT; -- 'monthly'|'quarterly'
ALTER TABLE micro_enterprises ADD COLUMN ir_liberatoire INTEGER;        -- 0/1
ALTER TABLE micro_enterprises ADD COLUMN creation_date TEXT;
ALTER TABLE micro_enterprises ADD COLUMN region TEXT;                  -- 'default'|'alsace'|'moselle'
ALTER TABLE micro_enterprises ADD COLUMN acre_reduction_rate REAL;     -- ex: 0.5 si ACRE (optionnel)

-- 3. Table barèmes d'activités
CREATE TABLE micro_activity_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  family TEXT NOT NULL,                -- VENTE | SERVICE | LIBERAL_CIPAV | LIBERAL_SSI | LOCATION_CLASSEE | AUTRE
  social_rate REAL NOT NULL,           -- taux micro-social
  ir_rate REAL,                        -- taux versement libératoire (nullable si pas d'option)
  cfp_rate REAL,                       -- contribution formation pro (nullable)
  chamber_type TEXT,                   -- NULL | CCI | CMA
  ca_ceiling REAL NOT NULL,            -- plafond CA principal (ex: 188700 / 77700)
  tva_ceiling REAL NOT NULL,           -- seuil franchise TVA (ex: 91900 / 39100)
  tva_alert_threshold REAL NOT NULL DEFAULT 0.5, -- pourcentage déclenchant l'alerte visuelle
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 4. Seed de base (ajuste si nécessaire pour 2025)
INSERT INTO micro_activity_rates(code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,ca_ceiling,tva_ceiling,tva_alert_threshold)
VALUES
 ('508','Vente / logement (BIC)', 'VENTE',        0.1230, 0.0100, 0.0010, 'CCI', 188700, 91900, 0.50),
 ('518','Prestations BIC',        'SERVICE',      0.2120, 0.0170, 0.0010, 'CCI',  77700, 39100, 0.50),
 ('781','Prof. libérales CIPAV',  'LIBERAL_CIPAV',0.2120, 0.0220, 0.0020, NULL,   77700, 39100, 0.50),
 ('781_SSI','Prof. libérales SSI','LIBERAL_SSI',  0.2110, 0.0220, 0.0020, NULL,   77700, 39100, 0.50),
 ('LM_TCL','Meublé tourisme classé','LOCATION_CLASSEE',0.0600,0.0170,0.0010,'CCI',188700,91900,0.50);

-- 5. Table des périodes de contributions
CREATE TABLE micro_contribution_periods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  micro_id INTEGER NOT NULL,
  period_key TEXT NOT NULL,       -- ex: 2025Q4 ou 2025M10
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  due_date TEXT NOT NULL,
  ca_amount REAL NOT NULL DEFAULT 0,
  social_rate_used REAL,
  social_due REAL,
  ir_rate_used REAL,
  ir_due REAL,
  cfp_rate_used REAL,
  cfp_due REAL,
  chamber_type TEXT,
  chamber_rate_used REAL,
  chamber_due REAL,
  total_due REAL,
  status TEXT NOT NULL DEFAULT 'pending', -- pending|paid|skipped
  paid_at TEXT,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT,
  UNIQUE(micro_id, period_key)
);
CREATE INDEX micro_contrib_micro ON micro_contribution_periods(micro_id);
CREATE INDEX micro_contrib_status ON micro_contribution_periods(status);