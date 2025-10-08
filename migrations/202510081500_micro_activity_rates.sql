-- Recrée la table des barèmes d'activités micro avec plafonds + TVA majorée + taux divers.
DROP TABLE IF EXISTS micro_activity_rates;

CREATE TABLE micro_activity_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,                       -- Code interne (ex: 508, 518, 781, 781_SSI, LM_TCL)
  label TEXT NOT NULL,                             -- Libellé
  family TEXT NOT NULL,                            -- Famille fonctionnelle (VENTE, SERVICE, LIBERAL_CIPAV, LIBERAL_SSI, LOCATION_CLASSEE)
  social_rate REAL NOT NULL,                      -- Taux cotisations sociales micro-social
  ir_rate REAL,                                   -- Taux versement libératoire applicable si option IR (sinon NULL)
  cfp_rate REAL,                                  -- Contribution formation professionnelle
  chamber_type TEXT,                              -- 'CCI', 'CMA', NULL …
  chamber_rate_default REAL,                      -- Taux chambre par défaut (commerce / CCI ou base artisan si besoin)
  chamber_rate_alsace REAL,
  chamber_rate_moselle REAL,
  ca_ceiling REAL NOT NULL,                       -- Plafond CA principal
  tva_ceiling REAL NOT NULL,                      -- Seuil déclenchement TVA (normal)
  tva_ceiling_major REAL NOT NULL,                -- Seuil majoré (plafond supérieur)
  tva_alert_threshold REAL NOT NULL DEFAULT 0.50, -- Ratio d’alerte (ex: 0.5 = 50%)
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Données basées sur ton fichier récapitulatif.
-- Remarques :
--  - Pour 508 (vente/logement) : CCI (commerce), chambre_rate_default = 0.00015 (0,015 %)
--  - Pour 518 (prestations BIC) : on met CCI (prestations commerciales) = 0.00044
--  - Pour 781 / 781_SSI : libérales => pas de chambre (NULL)
--  - LM_TCL : assimilé prestations BIC pour IR (1,70%), chambre CCI possible -> tu peux mettre 0.00044 ou laisser NULL si tu ne veux pas taxer.
-- Ajuste selon ta logique métier exacte ; ici on suit la structure la plus simple.

INSERT INTO micro_activity_rates
(code,label,family,social_rate,ir_rate,cfp_rate,chamber_type,chamber_rate_default,chamber_rate_alsace,chamber_rate_moselle,ca_ceiling,tva_ceiling,tva_ceiling_major,tva_alert_threshold)
VALUES
-- 508 : Vente / logement (micro-BIC vente)
('508','Vente / logement (BIC)','VENTE',
 0.1230, 0.0100, 0.0010,
 'CCI', 0.00015, NULL, NULL,
 188700, 91900, 101000, 0.50),

-- 518 : Prestations BIC (commercial / artisan) -> ici choisi version commerciale (CCI)
('518','Prestations de services BIC','SERVICE',
 0.2120, 0.0170, 0.0010,
 'CCI', 0.00044, NULL, NULL,
 77700, 36800, 39100, 0.50),

-- 781 : Professions libérales CIPAV
('781','Prof. libérales CIPAV (BNC)','LIBERAL_CIPAV',
 0.2120, 0.0220, 0.0020,
 NULL, NULL, NULL, NULL,
 77700, 36800, 39100, 0.50),

-- 781_SSI : Professions libérales SSI
('781_SSI','Prof. libérales SSI (BNC)','LIBERAL_SSI',
 0.2110, 0.0220, 0.0020,
 NULL, NULL, NULL, NULL,
 77700, 36800, 39100, 0.50),

-- LM_TCL : Meublé tourisme classé (micro-BIC) - social 6%, IR 1.70% (même que presta BIC)
('LM_TCL','Meublé tourisme classé / chambres d\'hôtes','LOCATION_CLASSEE',
 0.0600, 0.0170, 0.0010,
 'CCI', 0.00015, NULL, NULL,
 77700, 36800, 39100, 0.50);