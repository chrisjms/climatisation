<?php
// update_client.php — mise à jour complète d'un client
// - Compatible avec formulaire simple (clientele.php) ET formulaire avancé (listes de phones/emails)
// - Transaction atomique
// - CSRF + validations basiques
// - Déduplication des listes
// - Le premier téléphone/email = principal (table clients)

require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/** Ajoute proprement un paramètre de query en préservant un éventuel fragment (#...) */
function add_query_param(string $url, string $param, string $value): string {
    $fragment = '';
    $pos = strpos($url, '#');
    if ($pos !== false) {
        $fragment = substr($url, $pos);           // inclut le '#'
        $url = substr($url, 0, $pos);             // base sans fragment
    }
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . rawurlencode($param) . '=' . rawurlencode($value) . $fragment;
}

/** Redirection avec ajout d'un paramètre */
function redirect_with(string $url, string $param, string $value) : void {
    header('Location: ' . add_query_param($url, $param, $value));
    exit;
}

/** Nettoyage minimal pour affichage éventuel (logs) */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function valid_date_ymd(?string $d): bool {
    if (!$d) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    $errs = DateTime::getLastErrors();
    return $dt && $errs['warning_count']===0 && $errs['error_count']===0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo 'Méthode non autorisée';
    exit;
}

/* ───────── CSRF ───────── */
$csrf_form = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_form)) {
    http_response_code(400);
    echo 'Jeton CSRF invalide';
    exit;
}

/* ───────── Paramètres ───────── */
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$retour_in = trim($_POST['retour'] ?? '');

/* Vérifier existence client au plus tôt */
if ($client_id <= 0) {
    // Fallback ultra-basique si pas d'ID
    header('Location: clientele.php?err=' . rawurlencode('Client invalide.'));
    exit;
}

/* Vérifier existence client */
$st = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$st->execute([$client_id]);
$client_row = $st->fetch(PDO::FETCH_ASSOC);
if (!$client_row) {
    header('Location: ' . add_query_param('clientele.php', 'err', 'Client introuvable.'));
    exit;
}

/* ───────── Normaliser/Sécuriser l’URL de retour ─────────
   - On refuse toute URL externe (contient "://")
   - Si vide ou douteuse, on construit: clientele.php?client_id=ID#fiche&tab=coord
   - On s'assure que client_id est présent dans la query
*/
function build_default_retour(int $id, bool $wantEdit = false): string {
    $hash = '#fiche&tab=coord' . ($wantEdit ? '&edit=1' : '');
    return 'clientele.php?client_id=' . $id . $hash;
}

$safeRetour = $retour_in;

// bannir schémas externes
if ($safeRetour === '' || strpos($safeRetour, '://') !== false) {
    $safeRetour = build_default_retour($client_id, false);
}

// forcer vers clientele.php si autre route
$baseOnly = $safeRetour;
$frag = '';
$posFrag = strpos($baseOnly, '#');
if ($posFrag !== false) { $frag = substr($baseOnly, $posFrag); $baseOnly = substr($baseOnly, 0, $posFrag); }

$basePath = strtok($baseOnly, '?'); // "clientele.php" attendu
if (basename($basePath) !== 'clientele.php') {
    $safeRetour = build_default_retour($client_id, false);
    $baseOnly = strtok($safeRetour, '#');
    $frag = substr($safeRetour, strlen($baseOnly)); // recompose le fragment
}

// s'assurer que client_id=... est dans la query
if (strpos($baseOnly, 'client_id=') === false) {
    $baseOnly = add_query_param($baseOnly, 'client_id', (string)$client_id);
}
$retour = $baseOnly . $frag;

/* ───────── Champs de base (table clients) ───────── */
$prenom      = trim($_POST['prenom'] ?? '');
$nom         = trim($_POST['nom'] ?? '');
$email_main  = trim($_POST['email'] ?? '');        // formulaire simple
$tel_main    = trim($_POST['telephone'] ?? '');    // formulaire simple
$adresse     = trim($_POST['adresse'] ?? '');
$code_postal = trim($_POST['code_postal'] ?? '');
$ville       = trim($_POST['ville'] ?? '');
$details     = trim($_POST['details'] ?? '');
$date_ajout  = trim($_POST['date_ajout'] ?? ($client_row['date_ajout'] ?? date('Y-m-d')));

/* ───────── Listes (formulaire avancé) ───────── */
$phones_raw = $_POST['phones']        ?? null; // array|string|null
$phones_lbl = $_POST['phones_label']  ?? null;
$emails_raw = $_POST['emails']        ?? null;
$emails_lbl = $_POST['emails_label']  ?? null;

/* ───────── Validations basiques ───────── */
$errors = [];

if ($prenom !== '' && mb_strlen($prenom) > 100) { $errors[] = 'Prénom trop long (max 100).'; }
if ($nom    !== '' && mb_strlen($nom)    > 100) { $errors[] = 'Nom trop long (max 100).'; }

if ($email_main !== '') {
    if (mb_strlen($email_main) > 190 || !filter_var($email_main, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Adresse e-mail principale invalide.";
    }
}

if ($tel_main !== '') {
    if (mb_strlen($tel_main) > 50) {
        $errors[] = "Téléphone principal trop long (max 50).";
    }
    if (!preg_match('/^[0-9+\s.\-()]{6,50}$/', $tel_main)) {
        $errors[] = "Téléphone principal invalide.";
    }
}

if (mb_strlen($adresse)     > 1000) { $errors[] = "Adresse trop longue (max 1000)."; }
if (mb_strlen($code_postal) >   10) { $errors[] = "Code postal trop long (max 10)."; }
if (mb_strlen($ville)       >  100) { $errors[] = "Ville trop longue (max 100)."; }

if (!valid_date_ymd($date_ajout)) {
    $date_ajout = date('Y-m-d'); // colonne NOT NULL : fallback
}

/* Valider listes si présentes */
$phones = null; // null = pas fourni ; array = fourni
if (is_array($phones_raw) || is_array($phones_lbl)) {
    $phones = [];
    $n = max(is_array($phones_raw)?count($phones_raw):0, is_array($phones_lbl)?count($phones_lbl):0);
    for ($i=0; $i<$n; $i++) {
        $p = trim((string)($phones_raw[$i] ?? ''));
        $l = trim((string)($phones_lbl[$i] ?? ''));
        if ($p === '') continue;
        if (mb_strlen($p) > 50 || !preg_match('/^[0-9+\s.\-()]{6,50}$/', $p)) {
            $errors[] = "Numéro de téléphone invalide : " . e($p);
            continue;
        }
        if ($l === '') $l = null;
        if ($l !== null && mb_strlen($l) > 50) $l = mb_substr($l, 0, 50);
        $phones[] = ['phone'=>$p, 'label'=>$l];
    }
    // déduplication par numéro
    $seen = [];
    $phones = array_values(array_filter($phones, function($row) use (&$seen){
        $k = mb_strtolower($row['phone']);
        if (isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    }));
}

$emails = null;
if (is_array($emails_raw) || is_array($emails_lbl)) {
    $emails = [];
    $n = max(is_array($emails_raw)?count($emails_raw):0, is_array($emails_lbl)?count($emails_lbl):0);
    for ($i=0; $i<$n; $i++) {
        $em = trim((string)($emails_raw[$i] ?? ''));
        $l  = trim((string)($emails_lbl[$i] ?? ''));
        if ($em === '') continue;
        if (mb_strlen($em) > 190 || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "E-mail invalide : " . e($em);
            continue;
        }
        if ($l === '') $l = null;
        if ($l !== null && mb_strlen($l) > 50) $l = mb_substr($l, 0, 50);
        $emails[] = ['email'=>$em, 'label'=>$l];
    }
    // déduplication par email
    $seen = [];
    $emails = array_values(array_filter($emails, function($row) use (&$seen){
        $k = mb_strtolower($row['email']);
        if (isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    }));
}

/* Si erreurs, on renvoie vers le même client + on ouvre l’éditeur */
if ($errors) {
    // Forcer un retour propre avec edit=1
    $errRetour = $retour;
    // si pas déjà &edit=1 dans le fragment, on l'ajoute
    if (strpos($errRetour, '#') === false) {
        $errRetour .= '#fiche&tab=coord&edit=1';
    } elseif (strpos($errRetour, 'edit=1') === false) {
        $errRetour .= '&edit=1';
    }
    header('Location: ' . add_query_param($errRetour, 'err', implode(' ', $errors)));
    exit;
}

/* Déterminer les "principaux" */
if (is_array($phones) && !empty($phones)) {
    $tel_principal = $phones[0]['phone'];
} else {
    $tel_principal = $tel_main; // peut être vide
}

if (is_array($emails) && !empty($emails)) {
    $email_principal = $emails[0]['email'];
} else {
    $email_principal = $email_main; // peut être vide
}

/* ───────── Transaction ───────── */
try {
    $pdo->beginTransaction();

    // 1) Update table clients
    $up = $pdo->prepare("
        UPDATE clients
           SET prenom      = :prenom,
               nom         = :nom,
               email       = :email,
               telephone   = :telephone,
               adresse     = :adresse,
               code_postal = :cp,
               ville       = :ville,
               details     = :details,
               date_ajout  = :date_ajout
         WHERE id = :id
    ");
    $up->execute([
        ':prenom'    => ($prenom===''?null:$prenom),
        ':nom'       => ($nom===''?null:$nom),
        ':email'     => ($email_principal===''?null:$email_principal),
        ':telephone' => ($tel_principal===''?null:$tel_principal),
        ':adresse'   => ($adresse===''?null:$adresse),
        ':cp'        => ($code_postal===''?null:$code_postal),
        ':ville'     => ($ville===''?null:$ville),
        ':details'   => ($details===''?null:$details),
        ':date_ajout'=> $date_ajout,
        ':id'        => $client_id
    ]);

    // 2) Gérer les listes secondaires
    if (is_array($phones)) {
        // Rebuild complet
        $pdo->prepare("DELETE FROM client_telephones WHERE client_id = ?")->execute([$client_id]);
        if (!empty($phones)) {
            $ins = $pdo->prepare("INSERT INTO client_telephones (client_id, phone, label) VALUES (:cid, :p, :l)");
            foreach ($phones as $row) {
                $ins->execute([':cid'=>$client_id, ':p'=>$row['phone'], ':l'=>$row['label']]);
            }
        }
    } else {
        // Aucune liste fournie : si un tel principal est donné, essayer de l'ajouter sans casser l'existant
        if ($tel_principal !== '') {
            $ins = $pdo->prepare("INSERT IGNORE INTO client_telephones (client_id, phone, label) VALUES (?, ?, ?)");
            $ins->execute([$client_id, $tel_principal, null]);
        }
    }

    if (is_array($emails)) {
        // Rebuild complet
        $pdo->prepare("DELETE FROM client_emails WHERE client_id = ?")->execute([$client_id]);
        if (!empty($emails)) {
            $ins = $pdo->prepare("INSERT INTO client_emails (client_id, email, label) VALUES (:cid, :e, :l)");
            foreach ($emails as $row) {
                $ins->execute([':cid'=>$client_id, ':e'=>$row['email'], ':l'=>$row['label']]);
            }
        }
    } else {
        // Aucune liste fournie : si un email principal est donné, tenter un ajout discret
        if ($email_principal !== '') {
            $ins = $pdo->prepare("INSERT IGNORE INTO client_emails (client_id, email, label) VALUES (?, ?, ?)");
            $ins->execute([$client_id, $email_principal, null]);
        }
    }

    $pdo->commit();

    // Succès : on reste sur la fiche du client (onglet Coordonnées)
    header('Location: ' . add_query_param($retour, 'msg', 'Coordonnées mises à jour.'));
    exit;

} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // Erreur : rester sur la fiche + afficher l'erreur et rouvrir l'éditeur
    $errRetour = $retour;
    if (strpos($errRetour, '#') === false) {
        $errRetour .= '#fiche&tab=coord&edit=1';
    } elseif (strpos($errRetour, 'edit=1') === false) {
        $errRetour .= '&edit=1';
    }
    header('Location: ' . add_query_param($errRetour, 'err', "Erreur lors de la mise à jour : " . $ex->getMessage()));
    exit;
}
