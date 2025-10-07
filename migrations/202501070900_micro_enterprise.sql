-- Création tables micro-entreprise
CREATE TABLE micro_enterprises (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  regime TEXT,
  ca_ceiling REAL,
  tva_ceiling REAL,
  primary_color TEXT,
  secondary_color TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT
);
CREATE INDEX micro_enterprises_user ON micro_enterprises(user_id);

CREATE TABLE micro_enterprise_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  micro_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  type TEXT CHECK(type IN ('income','expense')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(micro_id, name COLLATE NOCASE)
);
CREATE INDEX micro_enterprise_categories_micro ON micro_enterprise_categories(micro_id);

-- Ajout référence sur accounts
ALTER TABLE accounts ADD COLUMN micro_enterprise_id INTEGER REFERENCES micro_enterprises(id);

-- (Option) Pré-remplir rien ici. L'utilisateur créera ensuite.