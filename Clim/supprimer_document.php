<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function back_err(string $msg, $client_id = null) {
    $loc = 'clientele.php';
    if ($client_id) { $loc .= '?client_id=' . urlencode((string)$client_id) . '&err=' . urlencode($msg) . '#fiche&tab=docs'; }
    else { $loc .= '?err=' . urlencode($msg); }
    header("Location: $loc");
    exit;
}

function back_ok(string $msg, $client_id) {
    header('Location: clientele.php?client_id=' . urlencode((string)$client_id) . '&msg=' . urlencode($msg) . '#fiche&tab=docs');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_err('Méthode non autorisée.');
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    back_err('CSRF invalide.');
}

$document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
if ($document_id <= 0) {
    back_err('Paramètres manquants.');
}

$stmt = $pdo->prepare('SELECT client_id, file_path FROM client_documents WHERE id = ?');
$stmt->execute([$document_id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    back_err('Document introuvable.', null);
}

$client_id   = (int)$doc['client_id'];
$db_file_raw = (string)($doc['file_path'] ?? '');

$server_path = '';
if ($db_file_raw !== '') {
    $rel       = ltrim($db_file_raw, '/');
    $candidate = realpath(__DIR__ . '/' . $rel);
    $base      = realpath(__DIR__);
    if ($candidate && $base && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
        $server_path = $candidate;
    } else {
        $filename = basename($rel);
        if ($filename !== '') {
            $fallback = __DIR__ . '/uploads/' . $filename;
            if (is_file($fallback)) { $server_path = $fallback; }
        }
    }
}

try {
    $pdo->beginTransaction();

    $del = $pdo->prepare('DELETE FROM client_documents WHERE id = ? LIMIT 1');
    $del->execute([$document_id]);

    if ($del->rowCount() !== 1) {
        $pdo->rollBack();
        back_err("La suppression n'a pas abouti.", $client_id);
    }

    if ($server_path !== '' && is_file($server_path)) {
        @unlink($server_path);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    back_err('Erreur lors de la suppression : ' . $e->getMessage(), $client_id);
}

back_ok('Document supprimé.', $client_id);
