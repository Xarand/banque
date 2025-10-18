<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/bootstrap.php';

use App\{Util, Database};

Util::startSession();
Util::requireAuth();

function h(string $s): string { return App\Util::h($s); }
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Aide — Guide utilisateur</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php if (is_file(__DIR__.'/_head_assets.php')) include __DIR__.'/_head_assets.php'; ?>
<style>
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}
  .toc a{ text-decoration:none }
  .toc li{ margin-bottom:.25rem }
  h2{ scroll-margin-top: 80px; }
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/_nav.php')) include __DIR__.'/_nav.php'; ?>

<div class="container py-3">
  <div class="mb-3">
    <h1 class="h3 mb-1">Aide — Guide utilisateur</h1>
    <div class="text-muted">Guide d’utilisation rapide de l’application</div>
  </div>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header py-2"><strong>Sommaire</strong></div>
        <div class="card-body">
          <ol class="toc ps-3 mb-0">
            <li><a href="#comptes"> Onglet Comptes</a></li>
            <li><a href="#reglages"> Onglet Réglage</a></li>
            <li><a href="#transactions"> Onglet Transactions</a></li>
            <li><a href="#cotisations"> Onglet Cotisations</a></li>
            <li><a href="#rapport"> Onglet Rapport</a></li>
            <li><a href="#theme"> Onglet Thème</a></li>
            <li><a href="#faq"> Onglet FAQ</a></li>
          </ol>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body">

          <h2 id="comptes" class="h4">1. Onglet Comptes</h2>
          <p>
            Dans « nouveau compte », saisissez votre nom de compte. Indiquez si c’est un compte personnel ou un compte Micro.
            Vous pouvez créer autant de comptes personnels que vous souhaitez, mais un seul compte Micro est possible.
          </p>
          <p>Si c’est un compte Micro, entrez :</p>
          <ul>
            <li>La date de création de votre Micro</li>
            <li>Le choix de la périodicité de la déclaration de chiffre d’affaires à l’Urssaf (Mensuel ou Trimestriel)</li>
            <li>Le type d’activité de votre entreprise (quatre choix vous sont proposés)</li>
            <li>Le choix du paiement de l’impôt</li>
          </ul>
          <p>
            Si vous cochez « Impôt libératoire », les impôts seront prélevés lors du paiement de vos cotisations.
            Si vous ne cochez pas « Impôt libératoire », vos impôts seront intégrés dans votre déclaration annuelle de revenus.
          </p>

          <h2 id="reglages" class="h4 mt-4">2. Onglet Réglage</h2>
          <p>
            Ajoutez des catégories de dépenses ou d’encaissements (ex. : Chiffre d’affaires, Apport, Fournitures, Cotisations sociales, Outillage, Carburant, Assurance, etc.).
            N'oubliez pas d'indiquer si la catégorie est un débit ou un crédit : lors de vos saisies les montants s’enregistreront automatiquement en positif ou négatif.
          </p>

          <h2 id="transactions" class="h4 mt-4">3. Onglet Transactions</h2>

          <h3 class="h6 mt-2">3.1 Transactions</h3>
          <ol>
            <li>Cliquez sur « Nouvelle transaction » pour ouvrir la page d’enregistrement.</li>
            <li>Datez la ligne (utilisez la date effective de la transaction).</li>
            <li>Choisissez le compte (Micro ou compte personnel) que vous avez créé.</li>
            <li>Sélectionnez la catégorie que vous avez préalablement enregistrée.</li>
            <li>Inscrivez le montant : il se positionnera automatiquement en débit ou en crédit selon la catégorie.</li>
          </ol>
          <p>
            La description est facultative mais permet de regrouper moins de catégories (ex. : catégorie « abonnement » + description « Électricité »).
            Les notes sont facultatives mais utiles pour retrouver une opération plusieurs mois ou années plus tard.
          </p>
          <p>
            Le choix « transaction récurrente » permet d’enregistrer un débit ou un crédit qui se produit toujours à la même date (options : mensuel, trimestriel, annuel).
            Vous la saisissez une seule fois ; l’opération apparaîtra le jour J et aux prochaines échéances.
          </p>
          <p>
            Si la saisie concerne un compte Micro, la case « Exclure du chiffre d’affaires » peut apparaître. Si vous cochez cette case, le crédit ne sera pas pris en compte dans le calcul des cotisations sociales.
            Exemple : un apport personnel pour trésorerie sera enregistré dans les transactions mais ne comptera pas pour le calcul des cotisations. Idem pour certains frais et débours (voir FAQ).
          </p>
          <p>
            Dans cette fenêtre (Transactions), vous pouvez modifier une transaction via le bouton « Éditer » ou la supprimer.
          </p>

          <h3 class="h6 mt-3">3.2 Filtres</h3>
          <p>
            Vous pouvez rechercher les mouvements par compte, catégorie, récurrence, date, débit/crédit, description ou note (tapez un mot‑clé).
            Cliquez sur « Appliquer » pour lancer la recherche.
          </p>
          <p>
            Le résultat peut être exporté au format CSV en cliquant sur « Exporter CSV » pour traitement dans un tableur.
          </p>

          <h3 class="h6 mt-3">3.3 Comptes (vue synthèse)</h3>
          <p>
            Cette zone affiche la position de chacun de vos comptes et la trésorerie totale de l’ensemble de vos comptes.
          </p>

          <h2 id="cotisations" class="h4 mt-4">4. Onglet Cotisations</h2>
          <p>
            La fenêtre principale affiche le montant prévisionnel de vos cotisations à régler, calculé selon la fréquence de déclaration que vous avez choisie et selon le chiffre d’affaires que vous avez enregistré.
          </p>
          <p>
            La vue Micro‑entreprise indique les plafonds de chiffre d’affaires à ne pas dépasser pour conserver votre statut, ainsi que les plafonds de TVA à respecter.
            Si vous dépassez ces montants, vous serez redevable de la TVA auprès de l’administration fiscale — consultez l’onglet « FAQ » pour plus de détails.
          </p>

          <h2 id="rapport" class="h4 mt-4">5. Onglet Rapport</h2>
          <p>
            Cette page présente la tendance de vos crédits et débits selon les critères que vous sélectionnez.
            Vous pouvez filtrer par plage de dates, ou utiliser les pré‑filtres rapides (1 mois, 3 mois, 6 mois, 1 an).
            Cliquez sur « Appliquer » pour obtenir le résultat.
          </p>
          <p>
            Utilisez les boutons « Export crédit » et « Export dépenses » pour obtenir un affichage exploitable dans un tableur.
          </p>

          <h2 id="theme" class="h4 mt-4">6. Onglet Thème</h2>
          <p>
            Cette page permet de personnaliser l’apparence de l’application (couleurs de fond et des textes).
            Lorsqu’une couleur est modifiée, l’aperçu s’applique immédiatement sur la page en cours ; les changements sont enregistrés uniquement après avoir cliqué sur « Enregistrer ».
            Le bouton « Réinitialiser » restaure les couleurs d’origine.
          </p>

          <h2 id="faq" class="h4 mt-4">7. Onglet FAQ</h2>
          <p>
            Vous trouverez dans cette section les réponses aux questions fréquentes.
            Si votre question n’y figure pas, vous pouvez nous envoyer un message avec vos interrogations afin que nous complétions la FAQ.
          </p>

        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>