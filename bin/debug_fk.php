<?php
$path = __DIR__ . '/../data/finance.db';
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function dumpSet($title, $rows) {
    echo "\n$title:\n";
    echo $rows ? json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : "(aucun)", "\n";
}

dumpSet('Users', $pdo->query("SELECT id,email FROM users")->fetchAll());

dumpSet('Accounts orphelins',
    $pdo->query("SELECT a.id,a.user_id,a.name FROM accounts a LEFT JOIN users u ON u.id=a.user_id WHERE u.id IS NULL")->fetchAll()
);

dumpSet('Categories orphelines',
    $pdo->query("SELECT c.id,c.user_id,c.name FROM categories c LEFT JOIN users u ON u.id=c.user_id WHERE u.id IS NULL")->fetchAll()
);

dumpSet('Transactions orphelines comptes',
    $pdo->query("SELECT t.id,t.account_id FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id WHERE a.id IS NULL")->fetchAll()
);

dumpSet('Transactions orphelines catégories',
    $pdo->query("SELECT t.id,t.category_id FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE t.category_id IS NOT NULL AND c.id IS NULL")->fetchAll()
);