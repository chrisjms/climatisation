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
    header('Location: devis.php?err=ID devis invalide#documents'); exit;
}

// Supprimer le PDF
$stmt = $pdo->prepare("SELECT fichier_pdf FROM energia_devis WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && !empty($row['fichier_pdf'])) {
    $path = __DIR__ . '/' . ltrim($row['fichier_pdf'], '/');
    if (is_file($path)) @unlink($path);
}

// Supprimer DB
$pdo->prepare("DELETE FROM energia_devis WHERE id=?")->execute([$id]);

// header("Location: devis.php?msg=Devis supprimé#documents"); exit;

$retour = $_POST['retour'] ?? 'devis.php';
header("Location: $retour");
exit;
