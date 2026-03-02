<?php
require 'auth.php';
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['document_id'])) {
    $document_id = intval($_POST['document_id']);

    // Récupérer le fichier pour le supprimer
    $stmt = $pdo->prepare('SELECT file_path FROM client_documents WHERE id = ?');
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();

    if ($doc) {
        $file = $doc['file_path'];
        if (file_exists($file)) {
            unlink($file); // Supprimer le fichier physique
        }

        // Supprimer de la base
        $stmt = $pdo->prepare('DELETE FROM client_documents WHERE id = ?');
        $stmt->execute([$document_id]);
    }
}

header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
