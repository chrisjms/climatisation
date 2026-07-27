<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function back_err(string $msg, $client_id = null) {
    $loc = 'clientele.php';
    if ($client_id) { $loc .= '?client_id=' . urlencode((string)$client_id) . '&err=' . urlencode($msg) . '#fiche&tab=bdc'; }
    else { $loc .= '?err=' . urlencode($msg); }
    header("Location: $loc");
    exit;
}

function back_ok(string $msg, $client_id) {
    header('Location: clientele.php?client_id=' . urlencode((string)$client_id) . '&msg=' . urlencode($msg) . '#fiche&tab=bdc');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_err('Méthode non autorisée.');
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    back_err('CSRF invalide.');
}

$bdc_id = isset($_POST['bdc_id']) ? (int)$_POST['bdc_id'] : 0;
if ($bdc_id <= 0) {
    back_err('Paramètres manquants.');
}

$stmt = $pdo->prepare(
    'SELECT b.fichier_pdf, d.client_id
       FROM bons_de_commande b
  LEFT JOIN devis d ON d.id = b.devis_id
      WHERE b.id = ?'
);
$stmt->execute([$bdc_id]);
$bdc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bdc) {
    back_err('Bon de commande introuvable.', null);
}

$client_id   = (int)($bdc['client_id'] ?? 0);
$db_file_raw = (string)($bdc['fichier_pdf'] ?? '');

$server_path = '';
if ($db_file_raw !== '') {
    $rel = ltrim($db_file_raw, '/');
    $candidate = realpath(__DIR__ . '/' . $rel);
    $base      = realpath(__DIR__);
    if ($candidate && $base && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
        $server_path = $candidate;
    } else {
        $filename = basename($rel);
        if ($filename !== '') {
            $fallback = __DIR__ . '/bdc_pdf/' . $filename;
            if (is_file($fallback)) { $server_path = $fallback; }
        }
    }
}

try {
    $pdo->beginTransaction();

    $del = $pdo->prepare('DELETE FROM bons_de_commande WHERE id = ? LIMIT 1');
    $del->execute([$bdc_id]);

    if ($del->rowCount() !== 1) {
        $pdo->rollBack();
        back_err("La suppression n'a pas abouti.", $client_id ?: null);
    }

    if ($server_path !== '' && is_file($server_path)) {
        @unlink($server_path);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    back_err('Erreur lors de la suppression : ' . $e->getMessage(), $client_id ?: null);
}

back_ok('Bon de commande supprimé.', $client_id);
