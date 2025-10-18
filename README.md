# Application PHP de gestion de comptes bancaires

Fonctionnalités:
- Comptes (création/suppression, solde courant)
- Catégories (revenu/dépense, hiérarchie simple)
- Transactions (CRUD partiel: ajout/suppression, filtres, pointage)
- Virements entre comptes (double écriture liée)
- Import CSV/XLSX (PhpSpreadsheet), auto-création des comptes/catégories si besoin
- Export Excel (3 feuilles: accounts, categories, transactions)
- SQLite (fichier `data/finance.db`), PDO

## Prérequis
- PHP 8.0+
- Composer

## Installation
```bash
composer install
```

## Lancement
Serveur PHP intégré:
```bash
php -S localhost:8000 -t public
```
Ouvrez http://localhost:8000

Au premier lancement, le schéma SQLite est appliqué automatiquement depuis `schema.sql`.

## Import
- CSV: séparateur `;` recommandé, 1ère ligne = en-têtes
- Colonnes prises en charge: `date, description, amount, account, category, payee, notes, cleared`
- Si `account` n’est pas fourni, sélectionnez un compte par défaut dans le formulaire.
- `cleared`: 1/0, true/false, yes/no.

## Sécurité
- CSRF basique intégré. Pas d’authentification (à ajouter si déployé).

## Personnalisation
- Ajouter une authentification (ex: Symfony Security, Laravel, ou simple middleware)
- Gérer multi-devises avec conversions
- Ajout d’édition inline, rapprochement bancaire, budgets, rapports graphiques