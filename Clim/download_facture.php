<?php
// download_facture.php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id   = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = ($_GET['mode'] ?? 'view') === 'dl' ? 'dl' : 'view';

if ($id <= 0) { http_response_code(400); exit('Facture invalide.'); }

$stmt = $pdo->prepare("SELECT numero, fichier_pdf FROM factures WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); exit('Facture introuvable.'); }

$db_file_raw = (string)($row['fichier_pdf'] ?? '');
if ($db_file_raw === '') { http_response_code(404); exit('Fichier PDF introuvable.'); }

/* ── Résolution sûre du chemin : on n'accepte que les fichiers réellement présents
      sous facture_pdf/ (chemin canonique sous __DIR__). Empêche le path-traversal
      même si la colonne fichier_pdf contient des données malicieuses. ── */
$allowedDir = realpath(__DIR__ . '/facture_pdf');
$rel        = ltrim($db_file_raw, '/');
$candidate  = realpath(__DIR__ . '/' . $rel);

if (!$candidate || !$allowedDir || !str_starts_with($candidate, $allowedDir . DIRECTORY_SEPARATOR)) {
    // Fallback : essayer juste le basename sous facture_pdf/
    $fallback = $allowedDir ? realpath($allowedDir . '/' . basename($rel)) : false;
    if ($fallback && str_starts_with($fallback, $allowedDir . DIRECTORY_SEPARATOR)) {
        $candidate = $fallback;
    } else {
        http_response_code(404); exit('Fichier PDF introuvable.');
    }
}

if (!is_file($candidate)) { http_response_code(404); exit('Fichier PDF introuvable.'); }

/* ── Nom de fichier sûr pour l'en-tête Content-Disposition.
      On utilise un nom ASCII pur (filename=) + version UTF-8 (filename*=) selon RFC 5987. ── */
$rawName = basename($candidate);
if (stripos($rawName, '.pdf') === false) $rawName .= '.pdf';
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $rawName);
if ($asciiName === '' || $asciiName === '.pdf') $asciiName = 'facture.pdf';
$utf8Name  = rawurlencode($rawName);

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($candidate));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');
$disposition = ($mode === 'dl') ? 'attachment' : 'inline';
header("Content-Disposition: {$disposition}; filename=\"{$asciiName}\"; filename*=UTF-8''{$utf8Name}");
readfile($candidate);
exit;
