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
<title>FAQ — Micro‑Pilote</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php if (is_file(__DIR__.'/_head_assets.php')) include __DIR__.'/_head_assets.php'; ?>
<style>
  h1,h2{margin-bottom:.5rem}
  .faq-q { font-weight:600; }
  .faq-mark { font-size: .95rem; margin-left:.5rem; color:#0d6efd; }
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Courier New",monospace}
  .accordion-button::after { margin-left: .5rem; }
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/_nav.php')) include __DIR__.'/_nav.php'; ?>

<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h3 me-3">FAQ — Foire aux questions</h1>
    <div class="text-muted">Guide pratique de l’autoentrepreneur</div>
  </div>

  <div class="accordion" id="faqAccordion">

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading1">
        </button>
      </h2>
      <div id="faq1" class="accordion-collapse collapse" aria-labelledby="faqHeading1" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Ce guide couvre les étapes principales : avant de se lancer, l’inscription, la déclaration de chiffre d’affaires, les cotisations, les obligations comptables, la modification ou la cessation d’activité, et les plafonds à surveiller.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading2">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
          Avant de se lancer — Qu'est‑ce qu'un autoentrepreneur ?
        </button>
      </h2>
      <div id="faq2" class="accordion-collapse collapse" aria-labelledby="faqHeading2" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Le régime de l’autoentrepreneur (micro‑entrepreneur) est un régime simplifié permettant d’exercer une activité indépendante (commerciale, artisanale ou libérale) avec des démarches et une comptabilité allégées.</p>
          <p><strong>Conditions d’accès :</strong></p>
          <ul>
            <li>Être majeur ou mineur émancipé</li>
            <li>Résider en France</li>
            <li>Exercer une activité autorisée</li>
            <li>Ne pas être sous tutelle ou curatelle</li>
          </ul>
          <p><strong>Avantages :</strong></p>
          <ul>
            <li>Création rapide</li>
            <li>Charges sociales proportionnelles au chiffre d’affaires</li>
            <li>Franchise de TVA (selon seuils)</li>
            <li>Comptabilité simplifiée</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading3">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
          S'inscrire comme autoentrepreneur — Où s'inscrire ?
        </button>
      </h2>
      <div id="faq3" class="accordion-collapse collapse" aria-labelledby="faqHeading3" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Inscription via le guichet unique (autoentrepreneur.urssaf.fr / Guichet unique / INPI selon activité).</p>
          <p><strong>Documents usuels :</strong></p>
          <ul>
            <li>Pièce d’identité</li>
            <li>Justificatif de domicile</li>
            <li>Déclaration d’activité</li>
            <li>Diplômes ou justificatifs (selon l’activité)</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading4">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
          Puis‑je cumuler mon activité micro avec un emploi salarié ?
        </button>
      </h2>
      <div id="faq4" class="accordion-collapse collapse" aria-labelledby="faqHeading4" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Oui, en respectant les clauses de votre contrat de travail (non‑concurrence, exclusivité, etc.). Vérifiez votre contrat et informez votre employeur si nécessaire.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading5">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
          Où et quand déclarer mon chiffre d’affaires ?
        </button>
      </h2>
      <div id="faq5" class="accordion-collapse collapse" aria-labelledby="faqHeading5" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Déclaration sur autoentrepreneur.urssaf.fr, selon la périodicité choisie : mensuelle ou trimestrielle. Respectez les dates limites (dernier jour du mois suivant la période).</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading6">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
          Quelles sont les cotisations à prévoir ?
        </button>
      </h2>
      <div id="faq6" class="accordion-collapse collapse" aria-labelledby="faqHeading6" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <ul>
            <li>Cotisations sociales (déclarées mensuellement ou trimestriellement)</li>
            <li>Cotisation foncière des entreprises (CFE)</li>
            <li>Impôt sur le revenu (option prélèvement libératoire ou régime classique)</li>
            <li>Chambre consulaire (selon activité)</li>
            <li>Contribution à la formation professionnelle</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading7">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7" aria-expanded="false" aria-controls="faq7">
          Quelles sont mes obligations comptables ?
        </button>
      </h2>
      <div id="faq7" class="accordion-collapse collapse" aria-labelledby="faqHeading7" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <ul>
            <li>Tenue d’un livre des recettes</li>
            <li>Registre des achats (si activité commerciale)</li>
            <li>Factures numérotées et conservées</li>
          </ul>
          <p>Aucune obligation spécifique d’avoir un comptable, bien que cela puisse aider pour certaines activités.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading8">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq8" aria-expanded="false" aria-controls="faq8">
          Modifier ou cesser son activité — Comment faire ?
        </button>
      </h2>
      <div id="faq8" class="accordion-collapse collapse" aria-labelledby="faqHeading8" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Modification : via le Guichet unique ou l’URSSAF selon le type de changement.</p>
          <p>Fermeture : déclarer la cessation sur le Guichet unique / INPI, fournir la dernière déclaration de CA, être à jour des cotisations et informer vos clients/partenaires.</p>
          <p>Vous pouvez reprendre une activité ultérieurement, même dans un autre domaine.</p>
        </div>
      </div>
    </div>

    <!-- Plafonds et TVA -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading9">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq9" aria-expanded="false" aria-controls="faq9">
          Quels sont les plafonds à ne pas dépasser ? (CA et TVA)
        </button>
      </h2>
      <div id="faq9" class="accordion-collapse collapse" aria-labelledby="faqHeading9" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Les plafonds (CA & seuils TVA) sont affichés dans l’onglet « Cotisations » avec des alertes. En cas de dépassement de CA vous perdez le statut micro et basculez vers le régime réel. En cas de dépassement TVA, vous devenez assujetti à la TVA et devrez la facturer et la déclarer.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading10">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq10" aria-expanded="false" aria-controls="faq10">
          Mentions obligatoires sur les factures
        </button>
      </h2>
      <div id="faq10" class="accordion-collapse collapse" aria-labelledby="faqHeading10" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Si non assujetti à la TVA : mentionner « TVA non applicable, article 293 B du CGI ».</p>
          <p>En cas d’autoliquidation (client professionnel) : mention « Autoliquidation de la TVA – article 283-2 du CGI ».</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading11">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq11" aria-expanded="false" aria-controls="faq11">
          Éléments à ne pas déclarer dans le chiffre d’affaires
        </button>
      </h2>
      <div id="faq11" class="accordion-collapse collapse" aria-labelledby="faqHeading11" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Ne déclarez pas : l’attention des transferts entre vos comptes, et les <strong>frais et débours</strong> facturés au client et remboursés au réel (vous devez les détailler et conserver les factures d’achat).</p>
          <p>Exemple : si vous facturez 10 000 € TTC comprenant 7 000 € de fournitures, vous ne déclarez que 3 000 € de prestation si vous identifiez 7 000 € en frais et débours.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading12">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq12" aria-expanded="false" aria-controls="faq12">
          Plafond pour bénéficier du prélèvement libératoire (2026)
        </button>
      </h2>
      <div id="faq12" class="accordion-collapse collapse" aria-labelledby="faqHeading12" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Pour 2026 (se basant sur les revenus 2024), le plafond est de <strong>29 315 €</strong> par part de quotient familial. Ce plafond est majoré selon la composition du foyer (+50% par demi‑part, +25% par quart de part).</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading13">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq13" aria-expanded="false" aria-controls="faq13">
          Exonérations et aides possibles
        </button>
      </h2>
      <div id="faq13" class="accordion-collapse collapse" aria-labelledby="faqHeading13" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Vous pouvez être éligible à :</p>
          <ul>
            <li><strong>ARE</strong> (allocation chômage cumulable sous conditions)</li>
            <li><strong>ARCE</strong> (aide à la reprise/création, versement partiel des allocations)</li>
            <li><strong>NACRE</strong> (accompagnement possible)</li>
            <li><strong>ACRE</strong> (exonération partielle de charges la 1ère année sous conditions)</li>
          </ul>
          <p>Exonération de la CFE la 1ère année ; possible exonération si CA annuel < 5 000 €.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading14">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq14" aria-expanded="false" aria-controls="faq14">
          Puis‑je embaucher du personnel ?
        </button>
      </h2>
      <div id="faq14" class="accordion-collapse collapse" aria-labelledby="faqHeading14" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Oui, l’autoentrepreneur peut embaucher. Vous devrez déclarer le salarié et régler les cotisations salariales et patronales correspondantes.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading15">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq15" aria-expanded="false" aria-controls="faq15">
          Dois‑je m'assurer ?
        </button>
      </h2>
      <div id="faq15" class="accordion-collapse collapse" aria-labelledby="faqHeading15" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Selon l’activité, l’assurance peut être obligatoire (ex. : décennale pour BTP, RC pro pour professions réglementées). Même lorsque non obligatoire, une responsabilité civile professionnelle est souvent recommandée.</p>
          <p>Mentionnez l’assurance sur devis/factures si elle est obligatoire.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading16">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq16" aria-expanded="false" aria-controls="faq16">
          Je n'ai pas de chiffre d'affaires : dois‑je déclarer ?
        </button>
      </h2>
      <div id="faq16" class="accordion-collapse collapse" aria-labelledby="faqHeading16" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Oui : vous devez déclarer 0 € selon la périodicité choisie. Cette déclaration maintient votre statut et évite des pénalités. En cas d’oubli, des pénalités ou la perte du régime micro peuvent survenir.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading17">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq17" aria-expanded="false" aria-controls="faq17">
          En cas de défaillance, mes biens personnels sont‑ils saisissables ?
        </button>
      </h2>
      <div id="faq17" class="accordion-collapse collapse" aria-labelledby="faqHeading17" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>La résidence principale est devenue insaisissable pour les dettes professionnelles (loi Macron). D’autres biens peuvent être protégés via une déclaration d’insaisissabilité devant notaire.</p>
          <p>Vos biens peuvent être saisis si vous avez donné une caution, en cas de fraude/fauté lourde, ou si vous avez mélangé comptes pro/perso. Les services fiscaux et l’Urssaf disposent de prérogatives spécifiques.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading18">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq18" aria-expanded="false" aria-controls="faq18">
          Avec quel autre statut puis‑je cumuler le statut de micro ?
        </button>
      </h2>
      <div id="faq18" class="accordion-collapse collapse" aria-labelledby="faqHeading18" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Vous pouvez cumuler avec un emploi salarié, ou des mandats (gérant minoritaire, président de SAS/SASU). Vous ne pouvez pas cumuler avec la qualité de gérant majoritaire de SARL.</p>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="faqHeading19">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq19" aria-expanded="false" aria-controls="faq19">
          Pourquoi le calcul des cotisations diffère parfois de mon relevé Urssaf ?
        </button>
      </h2>
      <div id="faq19" class="accordion-collapse collapse" aria-labelledby="faqHeading19" data-bs-parent="#faqAccordion">
        <div class="accordion-body">
          <p>Notre application calcule au centime près, tandis que l’URSSAF arrondit chaque ligne de calcul, ce qui peut entraîner un léger écart. Des aides ou exonérations dont vous avez bénéficié peuvent aussi modifier le total.</p>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>