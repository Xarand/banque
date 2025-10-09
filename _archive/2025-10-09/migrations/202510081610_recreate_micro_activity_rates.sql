-- Recréation (si besoin) des barèmes activités avec valeurs initiales.
DROP TABLE IF EXISTS micro_activity_rates;

CREATE TABLE micro_activity_rates (
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

INSERT INTO micro_activity_rates
(code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold)
VALUES
('508','Vente / logement (BIC)','VENTE',0.1230,0.0100,0.0010,'CCI',0.00015,NULL,NULL,188700,91900,101000,0.50),
('518','Prestations de services BIC','SERVICE',0.2120,0.0170,0.0010,'CCI',0.00044,NULL,NULL,77700,36800,39100,0.50),
('781','Prof. libérales CIPAV (BNC)','LIBERAL_CIPAV',0.2120,0.0220,0.0020,NULL,NULL,NULL,NULL,77700,36800,39100,0.50),
('781_SSI','Prof. libérales SSI (BNC)','LIBERAL_SSI',0.2110,0.0220,0.0020,NULL,NULL,NULL,NULL,77700,36800,39100,0.50),
('LM_TCL','Meublé tourisme classé / chambres d\'hôtes','LOCATION_CLASSEE',0.0600,0.0170,0.0010,'CCI',0.00015,NULL,NULL,77700,36800,39100,0.50);