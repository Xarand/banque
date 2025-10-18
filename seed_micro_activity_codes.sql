-- Année courante (adapter : remplacer 2025 si nécessaire)
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
 0,0,0, 0,0,0,
 0,0,0),

(2025,'781_SSI','Autres prof. libérales SSI (BNC)','LIB_SSI',0.2110,0.0220,'LIBERAL',0.0020,
 0,0,0, 0,0,0,
 0,0,0),

(2025,'MEUBLE_TOURISME','Meublé tourisme classé / chambres d’hôtes','MEUBLE_TOURISME',0.0600,0.0170,'COMMERCANT',0.0010,
 0.00220,0.00290,0.00370,
 0.00480,0.00650,0.00830,
 0.00015,0.00044,0.00007);