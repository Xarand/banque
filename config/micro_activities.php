<?php
declare(strict_types=1);

/*
  Fichier interne des activités Micro et de leurs plafonds/taux.
  Mettez à jour ici en cas de changement de barèmes.
  Tous les pourcentages sont exprimés en décimaux (ex: 12,3% => 0.123).
*/

return [
    'vente' => [
        'label' => 'Vente de marchandises',
        'ceilings' => [
            'ca'         => 188700.0,  // Plafond CA
            'vat'        => 91900.0,   // Plafond TVA
            'vat_major'  => 101000.0,  // Plafond majoré TVA
        ],
        'rates' => [
            'social'       => 0.123,   // Cotisations sociales
            'income_tax'   => 0.01,    // Impôt forfaitaire (versement libératoire)
            'cfp'          => 0.001,   // Contribution à la formation professionnelle
            'cma'          => 0.0022, // CMA
        ],
    ],
    'service' => [
        'label' => 'Prestations de services',
        'ceilings' => [
            'ca'         => 77700.0,
            'vat'        => 36800.0,
            'vat_major'  => 39100.0,
        ],
        'rates' => [
            'social'       => 0.212,
            'income_tax'   => 0.017,
            'cfp'          => 0.003,
            'cma'          => 0.0048,
        ],
    ],
    'liberal_cipav' => [
        'label' => 'Professions libérales CIPAV',
        'ceilings' => [
            'ca'         => 77700.0,
            'vat'        => 36800.0,
            'vat_major'  => 39100.0,
        ],
        'rates' => [
            'social'       => 0.232,
            'income_tax'   => 0.022,
            'cfp'          => 0.002,
            'cma'          => 0.0,
        ],
    ],
    'liberal_ssi' => [
        'label' => 'Professions libérales SSI',
        'ceilings' => [
            'ca'         => 77700.0,
            'vat'        => 36800.0,
            'vat_major'  => 39100.0,
        ],
        'rates' => [
            'social'       => 0.246,
            'income_tax'   => 0.022,
            'cfp'          => 0.002,
            'cma'          => 0.0,
        ],
    ],
    'meuble_classe' => [
        'label' => 'Meublé tourisme classé',
        'ceilings' => [
            'ca'         => 77700.0,
            'vat'        => 36800.0,
            'vat_major'  => 39100.0,
        ],
        'rates' => [
            'social'       => 0.06,
            'income_tax'   => 0.01,
            'cfp'          => 0.01,
            'cma'          => 0.00015,
        ],
    ],
];