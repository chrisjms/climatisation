<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: devis.php?err=Méthode invalide#documents'); exit;
}

if (empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    header('Location: devis.php?err=Token CSRF invalide#documents'); exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: devis.php?err=ID BDC invalide#documents'); exit;
}

// Récupère PDF
$stmt = $pdo->prepare("SELECT fichier_pdf FROM energia_bons_de_commande WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && !empty($row['fichier_pdf'])) {
    $path = __DIR__ . '/' . ltrim($row['fichier_pdf'], '/');
    if (is_file($path)) @unlink($path);
}

// Supprime BDC
$pdo->prepare("DELETE FROM energia_bons_de_commande WHERE id=?")->execute([$id]);

// header("Location: devis.php?msg=BDC supprimé#documents"); exit;

$retour = $_POST['retour'] ?? 'devis.php';
header("Location: $retour");
exit;
