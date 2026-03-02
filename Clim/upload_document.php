<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ───────── Redirect helpers ───────── */
function redirect_err(string $msg, $client_id = null) {
    $loc = 'clientele.php';
    if ($client_id) { $loc .= '?client_id='.(int)$client_id.'&err='.urlencode($msg).'#fiche&tab=docs'; }
    else { $loc .= '?err='.urlencode($msg).'#fiche&tab=docs'; }
    header("Location: $loc"); exit;
}
function redirect_ok(string $msg, $client_id) {
    $loc = 'clientele.php?client_id='.(int)$client_id.'&msg='.urlencode($msg).'#fiche&tab=docs';
    header("Location: $loc"); exit;
}

/* ───────── DB helpers ───────── */
function table_exists(PDO $pdo, string $name): bool {
    $s = $pdo->prepare("SHOW TABLES LIKE ?");
    $s->execute([$name]);
    return (bool)$s->fetchColumn();
}
function list_columns(PDO $pdo, string $table): array {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => $r['Field'], $rows ?: []);
}
function get_column_info(PDO $pdo, string $table, string $column): ?array {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$column]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/* ───────── Date helpers ───────── */
function normalize_date_to_sql(?string $in): ?string {
    $in = trim((string)$in);
    if ($in === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $in)) return $in;              // YYYY-MM-DD
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $in, $m)) {        // DD/MM/YYYY
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    try { return (new DateTime($in))->format('Y-m-d'); } catch(Throwable $e){ return null; }
}

/**
 * Choisit/Crée la colonne de date "métier".
 * @return array{col:?string, created:bool}
 */
function ensure_document_date_column(PDO $pdo): array {
    $table = 'client_documents';
    $cands = ['document_date','date_document','date_doc','doc_date','date_piece','date_fichier','date'];
    if (!table_exists($pdo, $table)) return ['col'=>null, 'created'=>false];

    $cols = list_columns($pdo, $table);
    foreach ($cands as $c) if (in_array($c, $cols, true)) return ['col'=>$c, 'created'=>false];

    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `document_date` DATE NULL");
        return ['col'=>'document_date', 'created'=>true];
    } catch (Throwable $e) {
        return ['col'=>null, 'created'=>false];
    }
}

/* ───────── Checks ───────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    redirect_err("Méthode non autorisée.");
}

/* CSRF */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    redirect_err("CSRF invalide.");
}

/* Inputs */
$client_id     = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$nom           = trim($_POST['nom']  ?? '');
$type_input    = trim($_POST['type'] ?? '');          // ← texte libre
$doc_date_in   = $_POST['document_date'] ?? '';
$doc_date_sql  = normalize_date_to_sql($doc_date_in);
$file          = $_FILES['file'] ?? null;

if ($client_id <= 0 || $nom === '' || $type_input === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
    redirect_err("Champs manquants ou upload invalide.", $client_id);
}

/* Taille & types */
$max_size = 20 * 1024 * 1024;
if ($file['size'] > $max_size) { redirect_err("Fichier trop volumineux (max 20 Mo).", $client_id); }

$allowed_ext  = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];
$allowed_mime = [
    'application/pdf',
    'image/jpeg','image/png','image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];
$orig_name = $file['name'];
$ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext, true)) { redirect_err("Extension non autorisée.", $client_id); }
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']) ?: '';
if (!in_array($mime, $allowed_mime, true)) { redirect_err("Type de fichier non autorisé.", $client_id); }

/* Dossier uploads */
$upload_dir = __DIR__ . '/uploads';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
    redirect_err("Impossible de créer le dossier d'upload.", $client_id);
}

/* Nom de fichier */
$base = preg_replace('~[^a-zA-Z0-9._-]~', '_', pathinfo($orig_name, PATHINFO_FILENAME));
$base = $base !== '' ? $base : 'doc';
$uniq = bin2hex(random_bytes(4));
$target_path = $upload_dir . '/' . $base . '_' . $client_id . '_' . time() . '_' . $uniq . '.' . $ext;

/* Déplacement */
if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    redirect_err("Erreur lors de l'enregistrement du fichier.", $client_id);
}
@chmod($target_path, 0644);

/* Chemin public */
$file_path_web = 'uploads/' . basename($target_path);

/* ───────── Table & colonne TYPE : permettre le texte libre ───────── */
if (!table_exists($pdo, 'client_documents')) {
    @unlink($target_path);
    redirect_err("Table client_documents introuvable.", $client_id);
}

$colInfo = get_column_info($pdo, 'client_documents', 'type');
if ($colInfo && isset($colInfo['Type']) && preg_match('/^enum/i', $colInfo['Type'])) {
    // Tente de convertir automatiquement ENUM -> VARCHAR(191)
    try {
        $isNull   = (isset($colInfo['Null']) && strtoupper($colInfo['Null']) === 'YES');
        $nullSql  = $isNull ? 'NULL' : 'NOT NULL';
        $defaultSql = '';
        if (array_key_exists('Default', $colInfo) && $colInfo['Default'] !== null) {
            $defaultSql = ' DEFAULT ' . $pdo->quote($colInfo['Default']);
        }
        $pdo->exec("ALTER TABLE `client_documents` MODIFY `type` VARCHAR(191) $nullSql$defaultSql");
        // Recharger l'info pour s'assurer du changement (optionnel)
        $colInfo = get_column_info($pdo, 'client_documents', 'type');
    } catch (Throwable $e) {
        // Si la conversion échoue, on vérifiera plus bas avant l'INSERT
    }
}

/* ───────── Préparer l'insertion (uploaded_at NULL + date métier) ───────── */
try {
    $cols_table = list_columns($pdo, 'client_documents');
    $fields = ['client_id','type','nom','file_path'];
    $params = [$client_id, $type_input, $nom, $file_path_web];

    // uploaded_at => NULL si présent (pas de date auto technique)
    if (in_array('uploaded_at', $cols_table, true)) {
        $fields[] = 'uploaded_at';
        $params[] = null;
    }

    // Colonne métier de date
    $dateInfo = ensure_document_date_column($pdo);
    $dateCol  = $dateInfo['col'];
    if ($doc_date_sql && $dateCol) {
        $fields[] = $dateCol;
        $params[] = $doc_date_sql;
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO client_documents ('.implode(',', array_map(fn($f)=>"`$f`", $fields)).') VALUES ('.$placeholders.')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $msg = $doc_date_sql
        ? "Document ajouté (date enregistrée)."
        : "Document ajouté.";
    redirect_ok($msg, $client_id);

} catch (Throwable $e) {
    // Si l'erreur vient d'un ENUM resté en place ("Incorrect enum value"),
    // on donne une explication utile.
    $msg = $e->getMessage();
    if (stripos($msg, 'Incorrect enum value') !== false) {
        @unlink($target_path);
        redirect_err(
            "Impossible d'enregistrer le type libre car la colonne `client_documents.type` est encore ENUM. ".
            "Merci d'exécuter cette commande MySQL puis de réessayer : ".
            "ALTER TABLE client_documents MODIFY `type` VARCHAR(191) NULL;",
            $client_id
        );
    }

    @unlink($target_path);
    redirect_err("Erreur base de données : " . $msg, $client_id);
}
