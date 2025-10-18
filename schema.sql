-- Schéma principal (ne mettre que du SQL ici)

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  type TEXT,
  currency TEXT,
  initial_balance REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL,
  description TEXT,
  amount REAL NOT NULL,
  account_id INTEGER NOT NULL,
  category_id INTEGER NULL,
  budget_id INTEGER NULL,
  notes TEXT NULL,
  cleared INTEGER NOT NULL DEFAULT 0,
  include_in_turnover INTEGER NOT NULL DEFAULT 1,
  micro_code TEXT NULL,
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS presets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK (type IN ('credit','debit')),
  label TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  UNIQUE(type,label)
);

CREATE TABLE IF NOT EXISTS app_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS recurrents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  amount REAL NOT NULL,
  account_id INTEGER NOT NULL,
  day_of_month INTEGER NOT NULL CHECK(day_of_month BETWEEN 1 AND 31),
  start_date TEXT NOT NULL,
  end_date TEXT NULL,
  next_run TEXT NOT NULL,
  last_run TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TEXT NOT NULL DEFAULT (date('now')),
  updated_at TEXT NOT NULL DEFAULT (date('now')),
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS micro_entreprises (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL UNIQUE,
  business_name TEXT NOT NULL,
  creation_date TEXT NOT NULL,
  activity_type TEXT NOT NULL CHECK(activity_type IN ('vente','service','liberale')),
  income_tax_flat INTEGER NOT NULL CHECK(income_tax_flat IN (0,1)),
  contribution_period TEXT NOT NULL CHECK(contribution_period IN ('mensuelle','trimestrielle')),
  consular_type TEXT NOT NULL DEFAULT 'NONE' CHECK(consular_type IN ('CMA','CCI','DOUBLE','NONE')),
  region_code TEXT NOT NULL DEFAULT 'FR' CHECK(region_code IN ('FR','AL','MO')),
  created_at TEXT NOT NULL DEFAULT (date('now')),
  updated_at TEXT NOT NULL DEFAULT (date('now')),
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS micro_contribution_deadlines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  micro_id INTEGER NOT NULL,
  period_label TEXT NOT NULL,
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  due_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','skipped')),
  turnover REAL NOT NULL DEFAULT 0,
  social_due REAL NULL,
  income_tax_due REAL NULL,
  breakdown_json TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(micro_id) REFERENCES micro_entreprises(id) ON DELETE CASCADE,
  UNIQUE(micro_id, period_label)
);

CREATE TABLE IF NOT EXISTS micro_rates (
  year INTEGER NOT NULL,
  activity_type TEXT NOT NULL CHECK(activity_type IN ('vente','service','liberale')),
  social_rate REAL NOT NULL,
  income_tax_rate REAL NOT NULL,
  PRIMARY KEY(year, activity_type)
);

CREATE TABLE IF NOT EXISTS micro_activity_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  year INTEGER NOT NULL,
  code TEXT NOT NULL,
  label TEXT NOT NULL,
  family TEXT NOT NULL CHECK(family IN ('VENTE','SERVICE_BIC','LIB_CIPAV','LIB_SSI','MEUBLE_TOURISME','AUTRE')),
  social_rate REAL NOT NULL,
  ir_rate REAL NOT NULL,
  cfp_family TEXT NOT NULL CHECK(cfp_family IN ('ARTISAN','COMMERCANT','LIBERAL')),
  cfp_rate REAL NOT NULL,
  cma_rate_vente_std REAL DEFAULT 0,
  cma_rate_vente_al  REAL DEFAULT 0,
  cma_rate_vente_mo  REAL DEFAULT 0,
  cma_rate_service_std REAL DEFAULT 0,
  cma_rate_service_al REAL DEFAULT 0,
  cma_rate_service_mo REAL DEFAULT 0,
  cci_rate_vente REAL DEFAULT 0,
  cci_rate_service REAL DEFAULT 0,
  cci_rate_double REAL DEFAULT 0,
  UNIQUE(year, code)
);

-- Seed des codes (adapter l'année si besoin)
INSERT OR IGNORE INTO micro_activity_codes
(year,code,label,family,social_rate,ir_rate,cfp_family,cfp_rate,
 cma_rate_vente_std,cma_rate_vente_al,cma_rate_vente_mo,
 cma_rate_service_std,cma_rate_service_al,cma_rate_service_mo,
 cci_rate_vente,cci_rate_service,cci_rate_double)
VALUES
(2025,'508','Vente / Fourniture logement (BIC vente)','VENTE',0.1230,0.0100,'COMMERCANT',0.0010,
 0.00220,0.00290,0.00370,
 0.00480,0.00650,0.00830,
 0.00015,0.00044,0.00007),
(2025,'518','Prestations commerciales / artisanales (BIC)','SERVICE_BIC',0.2120,0.0170,'COMMERCANT',0.0010,
 0.00220,0.00290,0.00370,
 0.00480,0.00650,0.00830,
 0.00015,0.00044,0.00007),
(2025,'781_CIPAV','Professions libérales CIPAV (BNC)','LIB_CIPAV',0.2120,0.0220,'LIBERAL',0.0020,
 0,0,0,0,0,0,
 0,0,0),
(2025,'781_SSI','Autres professions libérales SSI (BNC)','LIB_SSI',0.2110,0.0220,'LIBERAL',0.0020,
 0,0,0,0,0,0,
 0,0,0),
(2025,'MEUBLE_TOURISME','Meublé tourisme classé / Chambres d’hôtes','MEUBLE_TOURISME',0.0600,0.0170,'COMMERCANT',0.0010,
 0.00220,0.00290,0.00370,
 0.00480,0.00650,0.00830,
 0.00015,0.00044,0.00007);