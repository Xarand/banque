-- Sauvegarde éventuelle de l’ancienne table si elle existe encore
ALTER TABLE micro_activity_rates RENAME TO micro_activity_rates_active;

-- Table draft (même structure)
CREATE TABLE IF NOT EXISTS micro_activity_rates_draft (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  family TEXT NOT NULL,
  social_rate REAL NOT NULL,
  ir_rate REAL,
  cfp_rate REAL,
  chamber_type TEXT,
  chamber_rate_default REAL,
  chamber_rate_alsace REAL,
  chamber_rate_moselle REAL,
  ca_ceiling REAL NOT NULL,
  tva_ceiling REAL NOT NULL,
  tva_ceiling_major REAL NOT NULL,
  tva_alert_threshold REAL NOT NULL DEFAULT 0.50,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT
);

-- Historique des jeux appliqués
CREATE TABLE IF NOT EXISTS activity_rate_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  applied_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT,
  snapshot_json TEXT NOT NULL -- contient la liste complète des barèmes actifs au moment de l'application
);

-- Colonnes override dans micro_enterprises (ignorer l'erreur duplicate column si rejoué)
ALTER TABLE micro_enterprises ADD COLUMN override_ca_ceiling INTEGER NOT NULL DEFAULT 0;
ALTER TABLE micro_enterprises ADD COLUMN override_tva_ceiling INTEGER NOT NULL DEFAULT 0;
ALTER TABLE micro_enterprises ADD COLUMN override_tva_ceiling_major INTEGER NOT NULL DEFAULT 0;

-- Si la draft est vide, initialiser à partir de l’active
INSERT OR IGNORE INTO micro_activity_rates_draft
(code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold,created_at,updated_at)
SELECT code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold,created_at,updated_at
FROM micro_activity_rates_active;