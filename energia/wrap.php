<?php
// wrap.php — affiche proprement toute erreur (y compris ParseError) de generer_facture.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // On relaie la requête telle quelle
    include __DIR__ . '/generer_facture.php';
} catch (Throwable $e) {
    // Attrape même les ParseError des includes en PHP 7+
    header('Content-Type: text/plain; charset=UTF-8', true, 500);
    echo "ERREUR CAPTURÉE : " . get_class($e) . "\n";
    echo $e->getMessage() . "\n\n";
    echo "Fichier : " . $e->getFile() . "\n";
    echo "Ligne   : " . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit;
}
