<?php
declare(strict_types=1);

/**
 * Retourne les catégories actives (perso + globales) triées, sans doublons visuels.
 * - Déduplique par nom (lower(trim(name))) pour éviter "Alimentation" vs "alimentation".
 * - COALESCE(active,1)=1 inclut les catégories même si la colonne active n'existe pas.
 */
function fetchActiveCategories(PDO $pdo, int $userId): array {
    $sql = "
        SELECT id, TRIM(name) AS name
        FROM categories
        WHERE (user_id = :u OR user_id IS NULL)
          AND COALESCE(active, 1) = 1
        ORDER BY LOWER(TRIM(name)), id
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':u' => $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Déduplique par nom normalisé (lower+trim)
    $byName = [];
    foreach ($rows as $r) {
        $name = (string)($r['name'] ?? '');
        $norm = strtolower(trim($name));
        if ($norm === '') continue;
        if (!isset($byName[$norm])) {
            $byName[$norm] = [
                'id'   => (int)$r['id'],
                'name' => trim($name),
            ];
        }
        // Si même nom avec id plus petit, on conserve le plus petit (cohérence)
        if ((int)$r['id'] < $byName[$norm]['id']) {
            $byName[$norm] = [
                'id'   => (int)$r['id'],
                'name' => trim($name),
            ];
        }
    }

    // Trie alphabétique final insensible à la casse
    uasort($byName, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return array_values($byName);
}

/**
 * Rend le <select> des catégories.
 * - $selectedId: category_id sélectionné (depuis $_GET par ex.), ou null.
 * - $name: attribut name du <select> (par défaut 'category_id').
 * - $includeAllOption: ajoute "Toutes".
 */
function renderCategorySelect(PDO $pdo, int $userId, ?int $selectedId = null, string $name = 'category_id', bool $includeAllOption = true): void {
    $cats = fetchActiveCategories($pdo, $userId);
    echo '<select name="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'" class="form-select form-select-sm">';
    if ($includeAllOption) {
        echo '<option value="">Toutes</option>';
    }
    foreach ($cats as $c) {
        $id   = (int)$c['id'];
        $label = htmlspecialchars($c['name'] ?: 'Sans nom', ENT_QUOTES, 'UTF-8');
        $sel  = ($selectedId !== null && $selectedId === $id) ? ' selected' : '';
        echo "<option value=\"$id\"$sel>$label</option>";
    }
    echo '</select>';
}