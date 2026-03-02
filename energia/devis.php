<?php
require 'auth.php';
require 'config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* Suppression d'un devis (INCHANGÉ) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_devis') {
  $postedToken = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    http_response_code(400); exit('Requête invalide (CSRF).');
  }
  $delId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($delId > 0) {
    try {
      $st = $pdo->prepare("SELECT fichier_pdf FROM energia_devis WHERE id = :id LIMIT 1");
      $st->execute([':id' => $delId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['fichier_pdf'])) {
        $pdfPath = __DIR__ . '/' . ltrim($row['fichier_pdf'], '/');
        if (is_file($pdfPath)) { @unlink($pdfPath); }
      }
      $pdo->prepare("DELETE FROM energia_devis_lignes WHERE devis_id = :id")->execute([':id' => $delId]);
      $pdo->prepare("DELETE FROM energia_devis WHERE id = :id")->execute([':id' => $delId]);
    } catch (Throwable $e) {
      @file_put_contents(__DIR__ . '/error_devis.log', '['.date('Y-m-d H:i:s').'] DEL ' . $e->getMessage() . "\n", FILE_APPEND);
    }
  }
  header('Location: devis.php'); exit;
}

/* Recherche / limite */
$q = trim($_GET['q'] ?? '');
$limit = 10;

/* Données (formulaire) */
$stmt = $pdo->query('SELECT id, nom, prenom FROM energia_clients ORDER BY nom, prenom');
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query('SELECT id, code, nom, description, prix, COALESCE(quantite_defaut, 1) AS quantite_defaut FROM energia_pompes_a_chaleur ORDER BY nom');
$pac_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query('SELECT id, nom_du_compte, iban FROM energia_bank_accounts WHERE est_actif=1 ORDER BY nom_du_compte');
$bank_list = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* -----------------------------------------------------------
   Helpers robustes
----------------------------------------------------------- */
function table_exists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function show_cols(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch(Throwable $e){
        return [];
    }
}

function first_col(array $prefs, array $cols, $fallback = null) { foreach ($prefs as $c) if (in_array($c, $cols, true)) return $c; return $fallback; }

function fetch_devis(PDO $pdo, string $q, int $limit): array {
  if (!table_exists($pdo, 'energia_devis') || !table_exists($pdo, 'energia_clients')) return [];
  $c_devis = show_cols($pdo, 'devis');
  $c_clients = show_cols($pdo, 'clients');
  $dateCol = first_col(['date_creation','created_at','date','date_devis'], $c_devis, 'date_creation');
  $montantCol = first_col(['montant_ttc','total_ttc','montant'], $c_devis, 'montant_ttc');
  $pdfCol = first_col(['fichier_pdf','pdf_path','chemin_pdf'], $c_devis, 'fichier_pdf');
  $numeroCol = first_col(['numero','num_devis','reference'], $c_devis, 'numero');
  $clientId = first_col(['client_id','id_client'], $c_devis, 'client_id');
  $nomCol = first_col(['nom','last_name','lastname'], $c_clients, 'nom');
  $preCol = first_col(['prenom','first_name','firstname'], $c_clients, 'prenom');
  $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);
  $sql = "
    SELECT d.id, d.`$numeroCol` AS numero, d.`$dateCol` AS date_doc,
           d.`$montantCol` AS montant_ttc, d.`$pdfCol` AS fichier_pdf,
           c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
    FROM energia_devis d
    JOIN energia_clients c ON c.id = d.`$clientId`
  ";
  $where = []; $params = [];
  if ($q !== '') { $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").") "; $params[':q'] = "%$q%"; }
  if ($where) $sql .= " WHERE ".implode(' AND ', $where);
  $sql .= " ORDER BY d.`$dateCol` DESC, d.id DESC LIMIT :lim";
  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -----------------------------------------------------------
   BDC — version corrigée (ENERGIA)
----------------------------------------------------------- */
function fetch_bdc(PDO $pdo, string $q, int $limit): array {

    if (!table_exists($pdo, 'energia_bons_de_commande') ||
        !table_exists($pdo, 'energia_devis') ||
        !table_exists($pdo, 'energia_clients')) {
        return [];
    }

    $c_bdc     = show_cols($pdo, 'energia_bons_de_commande');
    $c_devis   = show_cols($pdo, 'energia_devis');
    $c_clients = show_cols($pdo, 'energia_clients');

    $dateCol   = first_col(['date_creation'], $c_bdc, 'date_creation');
    $montantCol= first_col(['montant_ttc'],   $c_bdc, 'montant_ttc');
    $pdfCol    = first_col(['fichier_pdf'],   $c_bdc, 'fichier_pdf');
    $numeroCol = first_col(['numero'],        $c_bdc, 'numero');

    $dv_id     = first_col(['id'],            $c_devis, 'id');
    $dv_cli    = first_col(['client_id'],     $c_devis, 'client_id');

    $nomCol    = first_col(['nom'],           $c_clients, 'nom');
    $preCol    = first_col(['prenom'],        $c_clients, 'prenom');
    $phoneCol  = first_col(['telephone'],     $c_clients);

    $sql = "
        SELECT b.id, b.`$numeroCol` AS numero, b.`$dateCol` AS date_doc,
               b.`$montantCol` AS montant_ttc, b.`$pdfCol` AS fichier_pdf,
               c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
        FROM energia_bons_de_commande b
        JOIN energia_devis d ON d.`$dv_id` = b.devis_id
        JOIN energia_clients c ON c.id = d.`$dv_cli`
    ";

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q " .
                   ($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "") . ") ";
        $params[':q'] = "%$q%";
    }

    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY b.`$dateCol` DESC, b.id DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* -----------------------------------------------------------
   FACTURES — version corrigée (ENERGIA)
----------------------------------------------------------- */
function fetch_factures(PDO $pdo, string $q, int $limit): array {

    if (!table_exists($pdo, 'energia_factures') ||
        !table_exists($pdo, 'energia_clients')) {
        return [];
    }

    $c_fac     = show_cols($pdo, 'energia_factures');
    $c_clients = show_cols($pdo, 'energia_clients');

    $dateCol   = first_col(['date_facture','date_creation'], $c_fac, 'date_creation');
    $montantCol= first_col(['montant_ttc'],                  $c_fac, 'montant_ttc');
    $pdfCol    = first_col(['fichier_pdf'],                  $c_fac, 'fichier_pdf');
    $numeroCol = first_col(['numero'],                       $c_fac, 'numero');

    // liaison facture → devis → client
    $join = "";
    if (table_exists($pdo, 'energia_devis') && in_array('devis_id', $c_fac, true)) {
        $c_devis = show_cols($pdo, 'energia_devis');
        $dv_id   = first_col(['id'],        $c_devis, 'id');
        $dv_cli  = first_col(['client_id'], $c_devis, 'client_id');

        $join = "JOIN energia_devis d ON d.`$dv_id` = f.devis_id
                 JOIN energia_clients c ON c.id = d.`$dv_cli`";
    } else {
        // fallback direct facture → client
        $join = "JOIN energia_clients c ON c.id = f.client_id";
    }

    $nomCol    = first_col(['nom'],    $c_clients, 'nom');
    $preCol    = first_col(['prenom'], $c_clients, 'prenom');
    $phoneCol  = first_col(['telephone'], $c_clients);

    $sql = "
        SELECT f.id, f.`$numeroCol` AS numero, f.`$dateCol` AS date_doc,
               f.`$montantCol` AS montant_ttc, f.`$pdfCol` AS fichier_pdf,
               c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
        FROM energia_factures f
        $join
    ";

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q " .
                   ($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "") . ") ";
        $params[':q'] = "%$q%";
    }

    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY f.`$dateCol` DESC, f.id DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Lignes des devis (groupées par pièces) — repris de ta version */
function fetchLinesFromDevisLignes(PDO $pdo, int $devisId): array {
  $sql = "
    SELECT pac_id, libelle, quantite, prix_unitaire AS prix_ht, tva_taux,
           piece_key, piece_nom
    FROM energia_devis_lignes
    WHERE devis_id = :id ORDER BY id
  ";
  $q = $pdo->prepare($sql);
  $q->execute([':id' => $devisId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  return array_map(function($r){
    return [
      'pac_id' => isset($r['pac_id']) ? (string)$r['pac_id'] : null,
      'libelle'=> (string)($r['libelle'] ?? ''),
      'quantite'=>(int)($r['quantite'] ?? 1),
      'prix_ht'=>(float)($r['prix_ht'] ?? 0),
      'tva'    => isset($r['tva_taux']) ? (float)$r['tva_taux'] : 20.0,
      'piece_key'=> ($r['piece_key'] ?? null),
      'piece_nom'=> ($r['piece_nom'] ?? null),
    ];
  }, $rows);
}
function fetchLinesFromDevisItems(PDO $pdo, int $devisId): array {
  $sql = "
    SELECT di.pac_id, COALESCE(p.nom, CONCAT('Article #', di.pac_id)) AS libelle,
           di.qty AS quantite, di.unit_price AS prix_ht
    FROM devis_items di
    LEFT JOIN energia_pompes_a_chaleur p ON p.id = di.pac_id
    WHERE di.devis_id = :id ORDER BY di.id
  ";
  $q = $pdo->prepare($sql);
  $q->execute([':id' => $devisId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  return array_map(function($r){
    return [
      'pac_id' => isset($r['pac_id']) ? (string)$r['pac_id'] : null,
      'libelle'=> (string)($r['libelle'] ?? ''),
      'quantite'=>(int)($r['quantite'] ?? 1),
      'prix_ht'=>(float)($r['prix_ht'] ?? 0),
      'tva'    => 20.0,
    ];
  }, $rows);
}
function fetchDevisPieces(PDO $pdo, int $devisId): array {
  $pieces = [];
  if (table_exists($pdo, 'devis_lignes')) {
    $rows = fetchLinesFromDevisLignes($pdo, $devisId);
    if (!empty($rows)) {
      $groups = []; $order = [];
      foreach ($rows as $r) {
        if (!empty($r['piece_key']))      $gkey = 'k:' . $r['piece_key'];
        elseif (!empty($r['piece_nom']))  $gkey = 'n:' . mb_strtolower(trim($r['piece_nom']), 'UTF-8');
        else                              $gkey = 'z:__default__';
        if (!isset($groups[$gkey])) { $groups[$gkey] = ['nom' => $r['piece_nom'] ?: null, 'items' => []]; $order[] = $gkey; }
        $groups[$gkey]['items'][] = [
          'pac_id'=>$r['pac_id'],'libelle'=>$r['libelle'],'quantite'=>$r['quantite'],
          'prix_ht'=>$r['prix_ht'],'tva'=>$r['tva'],
        ];
      }
      $idx = 1;
      foreach ($order as $g) {
        $nom = $groups[$g]['nom'] ?: ('Pièce ' . $idx);
        $pieces[] = ['nom'=>$nom,'items'=>$groups[$g]['items']]; $idx++;
      }
      return [$pieces, 'devis_lignes'];
    }
  }
  if (table_exists($pdo, 'devis_items')) {
    $items = fetchLinesFromDevisItems($pdo, $devisId);
    if (!empty($items)) { $pieces[] = ['nom'=>'Pièce','items'=>$items]; return [$pieces, 'devis_items']; }
  }
  return [[], null];
}

/* Pré-remplissage (copie) */
$copyBanner = null;
$prefill = null;
if (isset($_GET['copy_from_id']) && ctype_digit((string)$_GET['copy_from_id'])) {
  $copyId = (int)$_GET['copy_from_id'];
  $qstmt = $pdo->prepare("SELECT d.id, d.numero, d.client_id, d.description FROM energia_devis d WHERE d.id = :id LIMIT 1");
  $qstmt->execute([':id' => $copyId]);
  $src = $qstmt->fetch(PDO::FETCH_ASSOC);
  if ($src) {
    [$pieces, $source] = fetchDevisPieces($pdo, $copyId);
    $flat = []; foreach ($pieces as $p) { foreach (($p['items'] ?? []) as $it) { $flat[] = $it; } }
    $prefill = [
      'copy_from_id'=>$src['id'],'original_numero'=>$src['numero'],'client_id'=>$src['client_id'],
      'description'=>$src['description'],'pieces'=>$pieces,'items'=>$flat,'source'=>$source,
    ];
    $srcText = $source ? " (source : {$source})" : '';
    $copyBanner = "Reprise du devis n°" . htmlspecialchars($src['numero']) . "{$srcText} — dates réinitialisées, un nouveau numéro sera attribué.";
  }
}

/* Listes */
$devis_list = fetch_devis($pdo, $q, $limit);
$bdc_list   = fetch_bdc($pdo, $q, $limit);
$fac_list   = fetch_factures($pdo, $q, $limit);
$cnt_devis = count($devis_list);
$cnt_bdc   = count($bdc_list);
$cnt_fac   = count($fac_list);

/* Numéro auto */

// ----- Génération du prochain numéro DE + 8 chiffres (affichage par défaut) -----
$nextNumero = 'DE10000000';

try {
    // 1) Récupère le DERNIER numéro strictement au format DE######## (par ordre numérique réel)
    $sql = "
        SELECT numero
        FROM energia_devis
        WHERE numero REGEXP '^DE[0-9]{8}$'
        ORDER BY CAST(SUBSTRING(numero, 3, 8) AS UNSIGNED) DESC
        LIMIT 1
    ";
    $st = $pdo->query($sql);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['numero']) && preg_match('/^DE(\d{8})$/', $row['numero'], $m)) {
        $num  = (int)$m[1] + 1; // incrément
        $nextNumero = 'DE' . str_pad((string)$num, 8, '0', STR_PAD_LEFT);
    } else {
        // Table vide ou aucun numéro strictement au bon format -> base à DE10000000
        $nextNumero = 'DE10000000';
    }
} catch (Throwable $e) {
    // Fallback robuste si erreur SQL : timestamp (évite tout doublon visuel)
    $nextNumero = 'DE' . date('YmdHis');
}


/* Affichage helpers */
function fmt_date($d) { if (!$d) return '—'; $ts = strtotime($d); if ($ts === false) return htmlspecialchars((string)$d, ENT_QUOTES, 'UTF-8'); return date('d/m/Y', $ts); }
function fmt_eur($n) { if ($n === null || $n === '') return '—'; return number_format((float)$n, 2, ',', ' ').' €'; }
function link_pdf(?string $path, string $defaultDir = ''): string {
  $path = trim((string)$path);
  if ($path === '') return '<span class="muted">—</span>';
  if (preg_match('~^https?://~i', $path)) return '<a class="btn btn-secondary" href="'.htmlspecialchars($path, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">Voir le PDF</a>';
  $rel = $path; if ($defaultDir && strpos($path, '/') === false) $rel = rtrim($defaultDir,'/').'/'.$path;
  return '<a class="btn btn-secondary" href="'.htmlspecialchars($rel, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">Voir le PDF</a>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Devis - Climatisation (Pièces & Matériels)</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- CSS global existant -->
  <link rel="stylesheet" href="style.css">
  <!-- ✅ CSS spécifique devis -->
  <link rel="stylesheet" href="assets/css/devis.css">
  <!-- ✅ Variables fournies au JS externalisé -->
  <script>
    window.pacData = <?= json_encode($pac_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    <?php if ($prefill): ?>window.__prefill = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;<?php endif; ?>
  </script>
  <style>
    /* Retouches locales conservées */
    .section-nav { position: sticky; top: 0; z-index: 5; background: linear-gradient(180deg, var(--bg), var(--bg-2)); padding: 8px 0 12px; margin: -8px 0 16px; border-bottom: 1px solid var(--bd); }
    .stack { display:grid; gap:12px; }
    .grid-2-tight { display:grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 960px){ .grid-2-tight { grid-template-columns: 1fr; } }
    .card h2 { display:flex; align-items:center; gap:8px; }
    .nowrap { white-space: nowrap; }
    .table-docs thead th { position: sticky; top: 0; background: #f6f7f9; }
    .piece-card { border:1px solid var(--bd,#e1e4e8); border-radius:12px; padding:12px; margin-bottom:12px; background:#fff; }
    .piece-head { display:flex; justify-content:space-between; align-items:end; gap:12px; margin-bottom:8px; }
    .piece-title-wrap { display:grid; gap:6px; min-width:220px; }
    .piece-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .btn-outline { border:1px solid #90caf9; background:#e3f2fd; border-radius:8px; padding:8px 10px; cursor:pointer; }
    .btn-danger { border:1px solid #ef9a9a; background:#ffebee; border-radius:8px; padding:8px 10px; cursor:pointer; }
    .piece-total { margin-top:8px; padding-top:6px; border-top:1px dashed #ddd; color:#445; font-size:14px; }
    .total-chip { display:inline-block; padding:6px 10px; border-radius:999px; background:#f2f7ff; border:1px solid #d6e3ff; margin-right:8px; }
    .muted { color:#6b7280; }
	.actions .btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    margin-right: 4px;
}

.btn-danger-light {
    background: #ffe5e5;
    border: 1px solid #ffbcbc;
    color: #cc0000;
}

.btn-danger-light:hover {
    background: #ffcccc;
}
.actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.actions form {
    display: inline-flex;
    margin: 0;
    padding: 0;
}

.actions form button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
  </style>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>
<div class="main">
  <h1>Devis - Factures - Bons de commandes <small id="source-badge" class="muted"></small></h1>
  <?php if ($copyBanner): ?><div class="info"><?= $copyBanner ?></div><?php endif; ?>
  <?php if (!empty($_GET['msg'])): ?><div class="alert success"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="alert error"><?= htmlspecialchars($_GET['err']) ?></div><?php endif; ?>

  <!-- ============== 1) PRÉPARATION DU DEVIS ============== -->
  <section id="prep" class="card">
    <h2>🧾 Préparation du devis</h2>
    <p class="muted" style="margin-top:-6px">Sélectionnez le client, ajoutez des pièces et des matériels, puis validez.</p>

    <form id="form-devis" method="POST" action="traitement_devis.php" target="_blank" class="stack">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" id="copied_from_id" name="copied_from_id" value="">

      <div class="grid-2-tight">
        <div>
          <label for="client_id">Client</label>
          <select id="client_id" name="client_id" required>
            <option value="">-- Choisir un client --</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars("{$c['nom']} {$c['prenom']}") ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="date_creation_date">Date de création</label>
          <input type="date" id="date_creation_date" name="date_creation_date" value="<?= date('Y-m-d') ?>" required>
          <input type="hidden" id="date_creation_hidden" name="date_creation" value="<?= date('Y-m-d\T00:00') ?>">
        </div>
      </div>

      <div>
        <label for="numero">Numéro de devis</label>
        <input type="text" id="numero" name="numero" value="<?= htmlspecialchars($nextNumero, ENT_QUOTES, 'UTF-8') ?>">
        <div class="hint">Par défaut : numéro auto (modifiez-le si besoin avant validation).</div>
      </div>

      <div>
        <label for="description">Description de l'installation</label>
        <textarea id="description" name="description" rows="4" required></textarea>
      </div>

      <div class="pieces-toolbar">
        <button type="button" id="add-piece-btn" class="add-piece-btn">➕ Ajouter une pièce</button>
        <span class="muted">Astuce : les quantités laissées vides seront prises comme <strong>1</strong> à l’enregistrement.</span>
      </div>

      <div id="pieces-holder"><!-- pièces dynamiques via JS --></div>

      <div class="grid-2-tight">
        <div>
          <label for="date_echeance">Date d’échéance</label>
          <input type="date" id="date_echeance" name="date_echeance" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
        </div>
        <div>
          <label style="margin-top:8px">Acompte (€)</label>
          <input type="number" step="0.01" min="0" name="acompte" id="acompte" placeholder="Calculé automatiquement à 40% du TTC">
          <div class="small-muted">Par défaut : 40% du TTC (modifiable).</div>
        </div>

        <div>
          <label>Paiement</label>
          <div id="pay-block" class="card" style="padding:12px;">
            <div style="display:grid;grid-template-columns: 1fr 160px 1fr; gap:10px; align-items:end;">
              <div>
                <label for="mode_paiement_1">Mode de règlement</label>
                <select id="mode_paiement_1" name="mode_paiement_1" required>
                  <option value=" "></option>
                  <option value="Chèque">Chèque</option>
                  <option value="Virement bancaire">Virement bancaire</option>
                  <option value="Espèces">Espèces</option>
                  <option value="Carte bancaire">Carte bancaire</option>
                </select>
              </div>
              <div>
                <label for="montant_paiement_1">Montant</label>
                <input type="number" id="montant_paiement_1" name="montant_paiement_1" step="0.01" min="0" value="0.00" readonly>
              </div>
              <div>
                <label for="bank_account_id_1">Compte bancaire (facultatif)</label>
                <select id="bank_account_id_1" name="bank_account_id_1">
                  <option value="">-- Choisir un compte bancaire --</option>
                  <?php foreach ($bank_list as $bn): ?>
                    <option value="<?= (int)$bn['id'] ?>"><?= htmlspecialchars("{$bn['nom_du_compte']} – {$bn['iban']}") ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <input type="hidden" name="mode_paiement_2" value="">
            <input type="hidden" name="montant_paiement_2" value="0">
            <input type="hidden" name="bank_account_id_2" value="">
          </div>
        </div>
      </div>

      <div id="total-container">
        <span class="total-chip"><strong>Total HT :</strong> <span id="total">0.00 €</span></span>
        <span class="total-chip"><strong>Total TTC :</strong> <span id="total_ttc">0.00 €</span></span>
      </div>

      <div class="inline" style="margin-top:6px;">
        <button type="submit" class="btn btn-save">💾 Enregistrer le devis</button>
        <a class="btn btn-secondary" href="devis.php">Réinitialiser</a>
      </div>
    </form>
  </section>

  <!-- ============== 2) DOCUMENTS : recherche & 10 derniers ============== -->
  <section id="documents" class="card" style="margin-top:18px;">
    <h2>📚 Documents</h2>
    <p class="muted" style="margin-top:-6px">Retrouvez rapidement les derniers devis, bons de commande et factures. Utilisez la recherche par client.</p>

    <form class="searchbar" method="get" action="#documents">
      <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Recherche : nom, prénom ou téléphone du client">
      <button type="submit" class="btn btn-primary">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn btn-secondary" href="devis.php#documents">Réinitialiser</a><?php endif; ?>
    </form>

    <div class="grid" style="grid-template-columns:1fr; gap:18px;">
      <!-- Devis -->
      <div class="card">
        <h3>🧾 Devis <span class="pill">10 derniers</span> <span class="pill">Résultats: <?= (int)$cnt_devis ?></span></h3>
        <div class="table-wrap">
          <table class="table-docs">
            <thead><tr><th>N°</th><th>Client</th><th>Date</th><th>Montant TTC</th><th>PDF</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$devis_list): ?>
              <tr><td colspan="6" class="muted">Aucun devis trouvé.</td></tr>
            <?php else: foreach ($devis_list as $d): ?>
              <tr>
                <td><?= htmlspecialchars($d['numero'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(trim(($d['client_prenom']??'').' '.($d['client_nom']??'')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= fmt_date($d['date_doc'] ?? '') ?></td>
                <td><?= fmt_eur($d['montant_ttc'] ?? '') ?></td>
                <td><a class="btn btn-secondary" href="voir_pdf.php?src=energia_devis&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">Voir le PDF</a></td>
<td class="actions">

    <a class="btn btn-warning" href="devis.php?copy_from_id=<?= (int)$d['id'] ?>#prep">
        Reprendre
    </a>

    <a class="btn btn-primary" href="generer_bdc.php?id=<?= (int)$d['id'] ?>" target="_blank">
        BDC
    </a>

    <a class="btn btn-success" href="generer_facture.php?src=devis&id=<?= (int)$d['id'] ?>" target="_blank">
        Facture
    </a>

    <form method="POST" action="supprimer_devis.php" class="inline"
          onsubmit="return confirm('Supprimer définitivement ce devis ?');">
        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <input type="hidden" name="retour" value="devis.php#documents">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button type="submit" class="btn btn-danger-light">
            Suppr
        </button>
    </form>

</td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Bons de commande -->
      <div class="card">
        <h3>🧾 Bons de commande <span class="pill">10 derniers</span> <span class="pill">Résultats: <?= (int)$cnt_bdc ?></span></h3>
        <div class="table-wrap">
          <table class="table-docs">
            <thead><tr><th>N°</th><th>Client</th><th>Date</th><th>Montant TTC</th><th>PDF</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$bdc_list): ?>
              <tr><td colspan="6" class="muted">Aucun bon de commande trouvé.</td></tr>
            <?php else: foreach ($bdc_list as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['numero'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(trim(($b['client_prenom']??'').' '.($b['client_nom']??'')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= fmt_date($b['date_doc'] ?? '') ?></td>
                <td><?= fmt_eur($b['montant_ttc'] ?? '') ?></td>
                <td><?= link_pdf($b['fichier_pdf'] ?? '', 'bdc_pdf') ?></td>
<td class="actions">

    <a class="btn btn-success" href="generer_facture.php?src=bdc&id=<?= (int)$b['id'] ?>" target="_blank">
        Facture
    </a>

    <form method="POST" action="supprimer_bdc.php" class="inline"
          onsubmit="return confirm('Supprimer ce bon de commande ?');">
        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <input type="hidden" name="retour" value="devis.php#documents">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button type="submit" class="btn btn-danger-light">
            Suppr
        </button>
    </form>

</td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Factures -->
      <div class="card">
        <h3>🧾 Factures <span class="pill">10 dernières</span> <span class="pill">Résultats: <?= (int)$cnt_fac ?></span></h3>
        <div class="table-wrap">
          <table class="table-docs">
            <thead><tr><th>N°</th><th>Client</th><th>Date</th><th>Montant TTC</th><th>PDF</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$fac_list): ?>
              <tr><td colspan="5" class="muted">Aucune facture trouvée.</td></tr>
            <?php else: foreach ($fac_list as $f): ?>
              <tr>
                <td><?= htmlspecialchars($f['numero'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(trim(($f['client_prenom']??'').' '.($f['client_nom']??'')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= fmt_date($f['date_doc'] ?? '') ?></td>
                <td><?= fmt_eur($f['montant_ttc'] ?? '') ?></td>
                <td><a class="btn btn-secondary" href="voir_pdf.php?src=facture&id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">Voir le PDF</a>
<td class="actions">

    <form method="POST" action="supprimer_facture.php" class="inline"
          onsubmit="return confirm('Supprimer cette facture ?');">
        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <input type="hidden" name="retour" value="devis.php#documents">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <button type="submit" class="btn btn-danger-light">
            Suppr
        </button>
    </form>

</td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ✅ JS externalisé -->
<!-- le fichier JS -->
<script src="assets/js/devis.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    DevisUI.init({
      csrfToken: <?= json_encode($_SESSION['csrf_token'] ?? '') ?>,
      prefill:   <?= isset($prefill) ? 'window.__prefill' : 'null' ?>
    });
  });
</script>
</body>
</html>