<?php
require 'auth.php';
require 'config.php';

$devis_id    = $_POST['devis_id']    ?? null;
$fichier_pdf = $_POST['fichier_pdf'] ?? null;

if ($devis_id) {
    // 1. Supprimer le fichier PDF du disque
    if ($fichier_pdf && file_exists($fichier_pdf)) {
        unlink($fichier_pdf);
    }
    // 2. Supprimer l'enregistrement en base
    $stmt = $pdo->prepare('DELETE FROM devis WHERE id = ?');
    $stmt->execute([$devis_id]);
}

// Redirection vers la fiche client
$client_id = $_GET['client_id'] ?? $_POST['client_id'] ?? null;
header("Location: clientele.php?client_id=" . intval($client_id));
exit;
