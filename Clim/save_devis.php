<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {
    http_response_code(403);
    exit('CSRF invalide');
}

$client_nom = trim($_POST['client_nom'] ?? '');
$items = $_POST['items'] ?? [];

if ($client_nom === '') {
    exit('Nom client requis.');
}
if (!$items || !is_array($items)) {
    exit('Aucune ligne dans le devis.');
}

// Récupère les prix officiels depuis la BDD (pour éviter manip côté client)
$ids = array_map('intval', array_keys($items));
$ids = array_values(array_unique(array_filter($ids)));
if (!$ids) exit('Aucun article valide.');

$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, prix FROM pompes_a_chaleur WHERE id IN ($in)");
$stmt->execute($ids);
$prixMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $prixMap[(int)$row['id']] = (float)$row['prix'];
}

// Calcul total
$total = 0.0;
$cleanLines = [];
foreach ($items as $idStr => $line) {
    $id  = (int)($line['id'] ?? $idStr);
    $qty = max(1, (int)($line['qty'] ?? 1));
    if (!isset($prixMap[$id])) continue;
    $pu  = (float)$prixMap[$id];
    $lt  = $pu * $qty;
    $total += $lt;
    $cleanLines[] = ['pac_id'=>$id, 'qty'=>$qty, 'unit_price'=>$pu, 'line_total'=>$lt];
}
if (!$cleanLines) exit('Aucune ligne valide.');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO devis (client_nom, total) VALUES (?, ?)");
    $stmt->execute([$client_nom, $total]);
    $devis_id = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO devis_items (devis_id, pac_id, qty, unit_price, line_total)
                          VALUES (?, ?, ?, ?, ?)");
    foreach ($cleanLines as $ln) {
        $ins->execute([$devis_id, $ln['pac_id'], $ln['qty'], $ln['unit_price'], $ln['line_total']]);
    }

    $pdo->commit();
    header('Location: devis_view.php?id=' . $devis_id);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Erreur lors de l'enregistrement du devis : " . htmlspecialchars($e->getMessage());
}
