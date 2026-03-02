<?php
// download_facture.php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id   = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'dl' ? 'dl' : 'view'; // view = inline, dl = attachment

if ($id <= 0) { http_response_code(400); exit('Facture invalide.'); }

$stmt = $pdo->prepare("SELECT numero, fichier_pdf FROM factures WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); exit('Facture introuvable.'); }

$filepath = $row['fichier_pdf'];
if (!$filepath || !is_file($filepath)) { http_response_code(404); exit('Fichier PDF introuvable.'); }

// Nom propre pour le téléchargement
$filename = basename($filepath);
if (stripos($filename, '.pdf') === false) $filename .= '.pdf';

// Envoi
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Length: '.filesize($filepath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
if ($mode === 'dl') {
    header('Content-Disposition: attachment; filename="'.$filename.'"');
} else {
    header('Content-Disposition: inline; filename="'.$filename.'"');
}
readfile($filepath);
exit;
