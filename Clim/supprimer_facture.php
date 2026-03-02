<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function back_err(string $msg, $client_id = null) {
    $loc = 'clientele.php';
    if ($client_id) { $loc .= '?client_id=' . urlencode((string)$client_id) . '&err=' . urlencode($msg); }
    else { $loc .= '?err=' . urlencode($msg); }
    header("Location: $loc");
    exit;
}

function back_ok(string $msg, $client_id) {
    header('Location: clientele.php?client_id=' . urlencode((string)$client_id) . '&msg=' . urlencode($msg));
    exit;
}

// Méthode + CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_err('Méthode non autorisée.');
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    back_err('CSRF invalide.');
}

// Inputs
$facture_id  = isset($_POST['facture_id']) ? (int)$_POST['facture_id'] : 0;
// Transmis par le formulaire (peut être vide) :
$posted_file = trim($_POST['fichier_pdf'] ?? '');

if ($facture_id <= 0) {
    back_err('Paramètres manquants.');
}

// 1) Retrouver la facture pour obtenir client_id et fichier_pdf (source de vérité)
$stmt = $pdo->prepare('SELECT client_id, fichier_pdf FROM factures WHERE id = ?');
$stmt->execute([$facture_id]);
$facture = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$facture) {
    back_err("Facture introuvable.", null);
}

$client_id   = (int)$facture['client_id'];
$db_file_raw = (string)($facture['fichier_pdf'] ?? '');

// 2) Calcul du chemin serveur du PDF si présent
// - Dans ton affichage, tu ouvres "facture_pdf/<filename>"
// - En base, on peut avoir un nom de fichier ou un chemin relatif.
// On sécurise :
$filename = basename($db_file_raw !== '' ? $db_file_raw : $posted_file);
$server_path = '';
if ($filename !== '') {
    // Dossier selon ton affichage:
    $server_path = __DIR__ . '/facture_pdf/' . $filename;
}

// 3) Suppression DB (d'abord vérifier contraintes FK)
try {
    $pdo->beginTransaction();

    // Si des tables enfant référencent factures(id) sans ON DELETE CASCADE,
    // il faut les nettoyer ici (exemples) :
    // $pdo->prepare('DELETE FROM paiements WHERE facture_id = ?')->execute([$facture_id]);

    $del = $pdo->prepare('DELETE FROM factures WHERE id = ? LIMIT 1');
    $del->execute([$facture_id]);

    if ($del->rowCount() !== 1) {
        $pdo->rollBack();
        back_err("La suppression n'a pas abouti (rowCount=0).", $client_id);
    }

    // 4) Suppression du fichier si présent (tolérante)
    if ($server_path !== '' && is_file($server_path)) {
        @unlink($server_path);
        // NB: pas d'échec critique si unlink échoue (droits, fichier déjà supprimé…)
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    back_err('Erreur lors de la suppression : ' . $e->getMessage(), $client_id);
}

back_ok('Facture supprimée.', $client_id);
