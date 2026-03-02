<?php
// clientele.php — Vue claire avec séparation par sections + nav locale sticky
// Points clés :
// - L’affichage/tri des documents utilise une vraie date métier si présente (document_date, date_document, etc.).
// - On n’utilise PAS uploaded_at (qui est désormais NULL à l’upload).
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$clients            = [];
$documents          = [];
$devis              = [];
$factures           = [];
$bdc                = []; // bons de commande
$client_infos       = null;

$search             = trim($_GET['search'] ?? '');
$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$debug_bdc          = isset($_GET['debug_bdc']) ? (bool)$_GET['debug_bdc'] : false;
$bdc_debug_log      = [];

/* ───────── Helpers robustes ───────── */
function table_exists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}
function list_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => $r['Field'], $rows ?: []);
}
function table_has_columns(PDO $pdo, string $table, array $required): bool {
    if (!table_exists($pdo, $table)) return false;
    $cols = list_columns($pdo, $table);
    foreach ($required as $c) { if (!in_array($c, $cols, true)) return false; }
    return true;
}

/** Tri FR (Nom, Prénom) insensible accents/casse côté PHP (fallback ou renfort) */
function sort_clients_alpha(array &$clients): void {
    if (empty($clients)) return;
    if (class_exists('Collator')) {
        $coll = new Collator('fr_FR');
        if (method_exists($coll, 'setStrength')) { $coll->setStrength(Collator::PRIMARY); }
        usort($clients, function($a, $b) use ($coll) {
            $cmpNom = $coll->compare((string)($a['nom'] ?? ''), (string)($b['nom'] ?? ''));
            if ($cmpNom !== 0) return $cmpNom;
            return $coll->compare((string)($a['prenom'] ?? ''), (string)($b['prenom'] ?? ''));
        });
        return;
    }
    $norm = function(string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $x !== false ? $x : $s;
    };
    usort($clients, function($a, $b) use ($norm) {
        $na = $norm((string)($a['nom'] ?? ''));
        $nb = $norm((string)($b['nom'] ?? ''));
        if ($na !== $nb) return $na <=> $nb;
        $pa = $norm((string)($a['prenom'] ?? ''));
        $pb = $norm((string)($b['prenom'] ?? ''));
        return $pa <=> $pb;
    });
}

/** Récupère les BDC pour un client (détection auto table/colonnes) */
function fetch_bdc_for_client(PDO $pdo, int $clientId, bool $debug = false, array &$log = []): array {
    if ($clientId <= 0) return [];
    $candidateTables = [
        'bons_de_commande','bons_commandes','bon_de_commande',
        'bon_commandes','bdc','bon_commande','commandes',
        'bonsdecommande','bondecommande','purchase_orders'
    ];
    $idCols   = ['id','bdc_id','id_bdc','id_bondecommande','id_commande'];
    $cliCols  = ['client_id','id_client','client','idclient'];
    $devisCols= ['devis_id','id_devis'];
    $factCols = ['facture_id','id_facture'];
    $descCols = ['description','objet','titre','libelle','label','intitule'];
    $amtCols  = ['montant_ttc','montant_total_ttc','montant_total','total_ttc','total','montant','ttc','totalttc'];
    $dateCols = ['date_creation','date_bdc','created_at','date','date_emission','date_commande','date_creation_bdc'];
    $pdfCols  = ['fichier_pdf','pdf_path','file_path','chemin_pdf','pdf','path_pdf','fichier','fichier_bdc','document','doc_path'];

    $t = null; $cols = [];
    foreach ($candidateTables as $cand) {
        if (table_exists($pdo, $cand)) { $t = $cand; $cols = list_columns($pdo, $cand); break; }
    }
    if (!$t) { if ($debug) $log[] = "Aucune table BDC trouvée."; return []; }

    $findCol = function(array $wanted, array $cols) {
        foreach ($wanted as $c) if (in_array($c, $cols, true)) return $c;
        return null;
    };

    $colId   = $findCol($idCols,   $cols) ?? 'id';
    $colCli  = $findCol($cliCols,  $cols);
    $colDev  = $findCol($devisCols,$cols);
    $colFac  = $findCol($factCols, $cols);
    $selDesc = $findCol($descCols, $cols);
    $selAmt  = $findCol($amtCols,  $cols);
    $selDate = $findCol($dateCols, $cols);
    $selPdf  = $findCol($pdfCols,  $cols);

    if ($debug) {
        $log[] = "Table BDC: `$t`";
        $log[] = "Map: id=$colId, client_id=$colCli, devis_id=$colDev, facture_id=$colFac, desc=$selDesc, amt=$selAmt, date=$selDate, pdf=$selPdf";
    }

    $select = [];
    $select[] = "t.`$colId` AS id";
    $select[] = $selDesc ? "t.`$selDesc` AS description"   : "NULL AS description";
    $select[] = $selAmt  ? "t.`$selAmt`  AS montant_ttc"   : "NULL AS montant_ttc";
    $select[] = $selDate ? "t.`$selDate` AS date_creation" : "NULL AS date_creation";
    $select[] = $selPdf  ? "t.`$selPdf`  AS fichier_pdf"   : "NULL AS fichier_pdf";
    $orderBy = $selDate ? "t.`$selDate` DESC" : "t.`$colId` DESC";

    if ($colCli) {
        $sql = "SELECT ".implode(", ", $select)." FROM `$t` t WHERE t.`$colCli` = :cid ORDER BY $orderBy";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($debug) $log[] = "Résultats via `$t.$colCli` : ".count($rows);
        if ($rows) return $rows;
    }

    if ($colDev && table_has_columns($pdo, 'devis', ['id','client_id'])) {
        $sql = "SELECT ".implode(", ", $select)."
                  FROM `$t` t
                  JOIN `devis` d ON d.`id` = t.`$colDev`
                 WHERE d.`client_id` = :cid
              ORDER BY $orderBy";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($debug) $log[] = "Résultats via JOIN devis : ".count($rows);
        if ($rows) return $rows;
    }

    if ($colFac && table_has_columns($pdo, 'factures', ['id','client_id'])) {
        $sql = "SELECT ".implode(", ", $select)."
                  FROM `$t` t
                  JOIN `factures` f ON f.`id` = t.`$colFac`
                 WHERE f.`client_id` = :cid
              ORDER BY $orderBy";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cid' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($debug) $log[] = "Résultats via JOIN factures : ".count($rows);
        if ($rows) return $rows;
    }

    if ($debug) $log[] = "Aucun BDC trouvé pour client_id=$clientId";
    return [];
}

/* ──── Clients : liste + recherche (tri alphabétique fort côté SQL) ──── */
if ($search !== '') {
    $sql = 'SELECT id, nom, prenom, telephone, email
              FROM clients
             WHERE nom       LIKE :s
                OR prenom    LIKE :s
                OR telephone LIKE :s
                OR email     LIKE :s
          ORDER BY nom COLLATE utf8mb4_unicode_ci ASC,
                   prenom COLLATE utf8mb4_unicode_ci ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':s' => "%{$search}%"]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $clients = $pdo->query(
        'SELECT id, nom, prenom, telephone, email
           FROM clients
       ORDER BY nom COLLATE utf8mb4_unicode_ci ASC,
                prenom COLLATE utf8mb4_unicode_ci ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/* Renfort : re-tri côté PHP pour garantir l’ordre FR même si la BDD a une autre collation */
sort_clients_alpha($clients);

/* ──── Préchargement téléphones / e-mails pour la liste ──── */
$phonesByClient = [];
$emailsByClient = [];
if (!empty($clients)) {
    $ids = array_column($clients, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $stP = $pdo->prepare("SELECT client_id, phone, label FROM client_telephones WHERE client_id IN ($in) ORDER BY id");
    $stP->execute($ids);
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($phonesByClient[$cid])) $phonesByClient[$cid] = [];
        $phonesByClient[$cid][] = $r;
    }

    $stE = $pdo->prepare("SELECT client_id, email, label FROM client_emails WHERE client_id IN ($in) ORDER BY id");
    $stE->execute($ids);
    foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($emailsByClient[$cid])) $emailsByClient[$cid] = [];
        $emailsByClient[$cid][] = $r;
    }
}

/* ──── Fiche client ──── */
$client_phones = [];
$client_emails = [];
$dateAjoutAff  = null;

/* >>> Documents : préparer COALESCE(doc_date_cols...) pour affichage/tri (SANS uploaded_at) */
$doc_date_candidates = ['document_date','date_document','date_doc','doc_date','date_piece','date_fichier','date'];
$documents_coalesce  = 'NULL'; // s'il n'y a pas de vraie date, on laisse vide
if (table_exists($pdo, 'client_documents')) {
    $docCols = list_columns($pdo, 'client_documents');
    $present = array_values(array_intersect($doc_date_candidates, $docCols));
    if ($present) {
        $quoted = array_map(fn($c) => "`$c`", $present);
        $documents_coalesce = 'COALESCE('.implode(',', $quoted).')';
    }
}

if ($selected_client_id) {
    $stmt = $pdo->prepare(
        'SELECT id, nom, prenom, telephone, email, adresse, code_postal, ville, details, date_ajout
           FROM clients
          WHERE id = ?'
    );
    $stmt->execute([$selected_client_id]);
    $client_infos = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($client_infos['date_ajout'])) {
        try { $dt = new DateTime($client_infos['date_ajout']); $dateAjoutAff = $dt->format('d/m/Y'); }
        catch (Exception $e) { $dateAjoutAff = $client_infos['date_ajout']; }
    }

    $stmt = $pdo->prepare('SELECT phone, label FROM client_telephones WHERE client_id = ? ORDER BY id');
    $stmt->execute([$selected_client_id]);
    $client_phones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare('SELECT email, label FROM client_emails WHERE client_id = ? ORDER BY id');
    $stmt->execute([$selected_client_id]);
    $client_emails = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* >>> Récupération documents : du plus ancien au plus récent (NULL en dernier) */
    if (table_exists($pdo, 'client_documents')) {
        $sqlDocs = "
            SELECT id, client_id, type, nom, file_path, {$documents_coalesce} AS doc_date
              FROM client_documents
             WHERE client_id = ?
          ORDER BY {$documents_coalesce} IS NULL ASC, {$documents_coalesce} ASC, id ASC";
        $stmt = $pdo->prepare($sqlDocs);
        $stmt->execute([$selected_client_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $documents = [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, description, montant_ttc, date_creation, fichier_pdf
           FROM devis
          WHERE client_id = ?
       ORDER BY date_creation DESC'
    );
    $stmt->execute([$selected_client_id]);
    $devis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT id, description, montant_ttc, date_creation, fichier_pdf
           FROM factures
          WHERE client_id = ?
       ORDER BY date_creation DESC'
    );
    $stmt->execute([$selected_client_id]);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bdc = fetch_bdc_for_client($pdo, (int)$selected_client_id, $debug_bdc, $bdc_debug_log);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function initials($prenom, $nom){
    $p = trim((string)$prenom); $n = trim((string)$nom);
    $i = ($p !== '' ? mb_substr($p,0,1) : '') . ($n !== '' ? mb_substr($n,0,1) : '');
    return mb_strtoupper($i ?: 'C');
}
function normalize_phone_for_link($raw){
    $s = preg_replace('/[^0-9+]/','',(string)$raw);
    if ($s === '') return '';
    if ($s[0] === '+') return $s;
    if (str_starts_with($s,'00')) return '+'.substr($s,2);
    if ($s[0] === '0') return '+33'.substr($s,1); // défaut FR
    return '+'.$s;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Clientèle - Climatisation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h($_SESSION['csrf_token']) ?>">
<link rel="stylesheet" href="style.css">
<style>
  /* Petites retouches + mode "liste réduite" + édition masquée par défaut */
  .section-nav{position:sticky;top:0;z-index:6;background:linear-gradient(180deg,var(--bg,#fff),rgba(255,255,255,.92));border-bottom:1px solid var(--bd,#e7e7e7);padding:10px 0;margin:-8px 0 16px}
  .section-nav .quick{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .section-nav .spacer{flex:1}
  .card{padding:14px;border:1px solid var(--bd,#e5e5e5);border-radius:10px;background:var(--bg,#fff);box-shadow:0 1px 0 rgba(0,0,0,.02)}
  .client-list{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:6px}
  .client-item{display:flex;flex-direction:column;gap:2px;padding:10px;border:1px solid #ececec;border-radius:10px;background:#fafafa;cursor:pointer}
  .client-item:hover{background:#f5f7fb}
  .client-item.is-selected{border-color:#b7d2ff;background:#eef4ff}
  .small-muted{color:#666;font-size:.9em}
  .client-header{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #ececec;background:#fbfcfe;padding:12px;border-radius:12px;margin-bottom:10px}
  .client-header .avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#e8eefc;font-weight:700}
  .client-header .badge{background:#eef3ff;border:1px solid #dfe6ff;border-radius:20px;padding:2px 8px;margin-right:6px}
  .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 12px}
  .tabs .tab-btn{border:1px solid #e6e6e6;background:#f8f9fb;border-radius:999px;padding:6px 10px;cursor:pointer}
  .tabs .tab-btn.active{background:#1f6feb;color:#fff;border-color:#1f6feb}
  .tab-pane{display:none}
  .tab-pane.active{display:block}
  .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:960px){.info-grid{grid-template-columns:1fr}}
  .info-card{border:1px solid #ececec;background:#fff;border-radius:10px;padding:12px}
  .notes-view{white-space:pre-wrap}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,"Liberation Mono",Consolas,monospace}
  .notes-btn{background:#0b5ed7;color:#fff;border:none;border-radius:8px;padding:6px 10px;cursor:pointer}
  .notes-btn.secondary{background:#6c757d}
  .delete-btn{background:#e55353;color:#fff;border:none;border-radius:8px;padding:6px 10px;cursor:pointer}
  .icon-btn{border:1px solid #e0e0e0;background:#fff;border-radius:8px;padding:4px 8px;cursor:pointer}
  .upload-form .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:960px){.upload-form .row{grid-template-columns:1fr}}
  .toast{position:fixed;bottom:18px;right:18px;background:#222;color:#fff;padding:10px 12px;border-radius:10px;opacity:0;pointer-events:none;transition:.25s;z-index:999}
  .toast.show{opacity:1}
  .pill{display:inline-block;border:1px solid #e3e3e3;border-radius:999px;padding:2px 8px;background:#fafafa}
  .quick a.btn, .btn{display:inline-block;border:1px solid #dadada;border-radius:10px;padding:6px 10px;background:#fff;text-decoration:none}
  .btn-secondary{background:#f2f4f7}
  .btn-ghost{background:transparent;border:1px dashed #c9d3e1}

  /* Liste réduite : on n'affiche que le client sélectionné */
  .clients-panel.collapsed .list-hint{display:flex}
  .clients-panel .list-hint{display:none;align-items:center;gap:8px;margin:6px 0 0}
  .clients-panel.collapsed .client-item{display:none}
  .clients-panel.collapsed .client-item.is-selected{display:flex}

  /* Edition coordonnées masquée par défaut */
  #coordEditCard{display:none}
  .show-edit #coordEditCard{display:block}
  .show-edit #coordEditBtn{display:none}

  /* Form inline */
  form.inline{display:inline}
</style>
<script>
function filterClients(){
    const search=document.getElementById("search").value.toLowerCase();
    document.querySelectorAll(".client-item").forEach(li=>{
        li.style.display=li.classList.contains('is-selected') && document.querySelector('.clients-panel')?.classList.contains('collapsed')
          ? 'flex'
          : (li.textContent.toLowerCase().includes(search)?"flex":"none");
    });
}
</script>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>

<!-- Nav locale sticky -->
<div class="main">

  <!-- ============== 1) RECHERCHE & LISTE ============== -->
  <section id="recherche" class="card clients-panel <?= $selected_client_id ? 'collapsed' : '' ?>">
    <h1 style="margin:0 0 8px">🔎 Rechercher un client</h1>

    <!-- Messages -->
    <?php if (!empty($_GET['msg'])): ?>
      <div class="alert success"><?= h($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
      <div class="alert error"><?= h($_GET['err']) ?></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 180px;gap:10px;align-items:center;margin:8px 0 4px">
      <input type="text" id="search" class="mono"
             placeholder="Tapez un nom, prénom, numéro ou email… (Ctrl/Cmd+K)"
             onkeyup="filterClients()" value="<?= h($search) ?>">
      <a class="btn" href="ajout_client.php?retour=clientele.php">➕ Ajouter</a>
    </div>

    <?php if ($selected_client_id): ?>
      <div class="list-hint">
        <span class="small-muted">Liste réduite au client sélectionné.</span>
        <a class="btn btn-secondary" href="clientele.php#recherche" title="Afficher toute la liste">↩︎ Afficher toute la liste</a>
      </div>
    <?php endif; ?>

    <ul class="client-list">
        <?php foreach ($clients as $c): ?>
            <?php
                $cid = (int)$c['id'];
                $isSelected = $selected_client_id && $cid === (int)$selected_client_id;
                $phones = $phonesByClient[$cid] ?? [];
                $emails = $emailsByClient[$cid] ?? [];
                if (!empty($c['telephone']) && !array_filter($phones, fn($p)=>trim($p['phone'])===trim($c['telephone']))) {
                    array_unshift($phones, ['phone'=>$c['telephone'],'label'=>null]);
                }
                if (!empty($c['email']) && !array_filter($emails, fn($m)=>strtolower(trim($m['email']))===strtolower(trim($c['email'])))) {
                    array_unshift($emails, ['email'=>$c['email'],'label'=>null]);
                }
                $phonesTxt = implode(' • ', array_map(fn($p)=>$p['phone'], array_slice($phones,0,3)));
                $emailsTxt = implode(' • ', array_map(fn($m)=>$m['email'], array_slice($emails,0,2)));
            ?>
            <li class="client-item <?= $isSelected ? 'is-selected' : '' ?>"
                data-id="<?= $cid ?>"
                onclick="window.location.href='clientele.php?client_id=<?= $cid ?>#fiche&tab=coord'">
                <span>
                    <strong><?= h(($c['nom'] ?? '').' '.($c['prenom'] ?? '')) ?></strong>
                    <?php if($phones): ?>
                        <span class="pill">📞 <?= h(count($phones)) ?> n°</span>
                    <?php endif; ?>
                    <?php if($emails): ?>
                        <span class="pill">📧 <?= h(count($emails)) ?> mail(s)</span>
                    <?php endif; ?>
                </span>
                <span class="small-muted">
                    <?php if($phonesTxt): ?>
                        📞 <?= h($phonesTxt) ?><?php if(count($phones) > 3): ?> …<?php endif; ?>&nbsp;&nbsp;
                    <?php endif; ?>
                    <?php if($emailsTxt): ?>
                        📧 <?= h($emailsTxt) ?><?php if(count($emails) > 2): ?> …<?php endif; ?>
                    <?php else: ?>
                        <span style="color:#999">email non renseigné</span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($selected_client_id && $client_infos): ?>
  <?php
    // Principaux + actions rapides
    $allPhones = $client_phones;
    if (empty($allPhones) && !empty($client_infos['telephone'])) {
        $allPhones[] = ['phone'=>$client_infos['telephone'], 'label'=>null];
    } elseif (!empty($client_infos['telephone'])) {
        $found = false;
        foreach ($allPhones as $pp) { if (trim($pp['phone']) === trim($client_infos['telephone'])) { $found = true; break; } }
        if (!$found) array_unshift($allPhones, ['phone'=>$client_infos['telephone'],'label'=>null]);
    }
    $allEmails = $client_emails;
    if (empty($allEmails) && !empty($client_infos['email'])) {
        $allEmails[] = ['email'=>$client_infos['email'], 'label'=>null];
    } elseif (!empty($client_infos['email'])) {
        $found = false;
        foreach ($allEmails as $mm) { if (strcasecmp(trim($mm['email']), trim($client_infos['email'])) === 0) { $found = true; break; } }
        if (!$found) array_unshift($allEmails, ['email'=>$client_infos['email'],'label'=>null]);
    }
    $mainPhone = $allPhones[0]['phone'] ?? '';
    $mainMail  = $allEmails[0]['email'] ?? '';
    $mapsQ = rawurlencode(trim(($client_infos['adresse'] ?? '').' '.($client_infos['code_postal'] ?? '').' '.($client_infos['ville'] ?? '')));
  ?>

  <!-- ============== 2) FICHE CLIENT ============== -->
  <section id="fiche" class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px">
      <h2 style="margin:0;">👤 Fiche client</h2>
      <a class="btn btn-secondary" href="clientele.php#recherche" title="Fermer la fiche">✖ Fermer la fiche</a>
    </div>

    <!-- En-tête client -->
    <div class="client-header">
      <div class="left" style="display:flex;gap:10px;align-items:center">
        <div class="avatar"><?= h(initials($client_infos['prenom'] ?? '', $client_infos['nom'] ?? '')) ?></div>
        <div>
          <div style="font-weight:bold;font-size:16px"><?= h(($client_infos['nom'] ?? '').' '.($client_infos['prenom'] ?? '')) ?></div>
          <div class="small-muted">
            <?php if(!empty($client_infos['ville'])): ?><span class="badge">🏙️ <?= h($client_infos['ville']) ?></span><?php endif; ?>
            <?php if(!empty($client_infos['code_postal'])): ?><span class="badge">📮 <?= h($client_infos['code_postal']) ?></span><?php endif; ?>
            <?php if(!empty($client_infos['date_ajout'])): ?>
              <?php $da = $dateAjoutAff ?: $client_infos['date_ajout']; ?>
              <span class="badge">🗓️ Ajouté le <?= h($da) ?></span>
            <?php endif; ?>
            <span class="badge">📄 <?= count($documents) ?> docs</span>
            <span class="badge">🧾 <?= count($devis) ?> devis</span>
            <span class="badge">💶 <?= count($factures) ?> factures</span>
            <span class="badge">📝 <?= count($bdc) ?> BDC</span>
          </div>
        </div>
      </div>
      <div class="quick" style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if($mainPhone): ?>
          <a class="btn" href="tel:<?= h(preg_replace('/\s+/','',$mainPhone)) ?>">📞 Appeler</a>
          <?php $wa = normalize_phone_for_link($mainPhone); ?>
          <?php if($wa): ?><a class="btn" href="https://wa.me/<?= h(ltrim($wa,'+')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
          <button class="btn copy" data-copy="<?= h($mainPhone) ?>">📋 Copier n°</button>
        <?php endif; ?>
        <?php if($mainMail): ?>
          <a class="btn" href="mailto:<?= h($mainMail) ?>">✉️ Email</a>
          <button class="btn copy" data-copy="<?= h($mainMail) ?>">📋 Copier mail</button>
        <?php endif; ?>
        <?php if($mapsQ): ?><a class="btn" href="https://www.google.com/maps/search/?api=1&query=<?= $mapsQ ?>" target="_blank" rel="noopener">🗺️ Maps</a><?php endif; ?>
      </div>
    </div>

    <!-- Onglets -->
    <div class="tabs">
      <button class="tab-btn" data-tab="coord">Coordonnées</button>
      <button class="tab-btn" data-tab="notes">Notes</button>
      <button class="tab-btn" data-tab="docs">Documents (<?= count($documents) ?>)</button>
      <button class="tab-btn" data-tab="devis">Devis (<?= count($devis) ?>)</button>
      <button class="tab-btn" data-tab="factures">Factures (<?= count($factures) ?>)</button>
      <button class="tab-btn" data-tab="bdc">BDC (<?= count($bdc) ?>)</button>
    </div>

    <!-- Pane : Coordonnées -->
    <div id="pane-coord" class="tab-pane">
      <div class="info-grid" id="coordGrid">
        <div class="info-card" id="cardCoord">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <h4 style="margin:0">Coordonnées</h4>
            <!-- Boutons côte à côte : Modifier + Supprimer -->
            <div style="display:flex;gap:8px;align-items:center">
              <button id="coordEditBtn" class="btn btn-ghost" type="button">✏ Modifier</button>
              <form action="supprimer_client.php"
                    method="POST"
                    class="inline"
                    onsubmit="return confirm('⚠️ Cette action supprimera aussi tous les documents et coordonnées du client. Confirmer la suppression ?');">
                <input type="hidden" name="client_id" value="<?= (int)$selected_client_id ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <button type="submit" class="delete-btn">🗑 Supprimer</button>
              </form>
            </div>
          </div>
          <div id="coordView" style="margin-top:8px">
            <p><strong>Nom :</strong><br>
               <?= h(($client_infos['nom'] ?? '').' '.($client_infos['prenom'] ?? '')) ?></p>

            <p><strong>Téléphones (tous) :</strong><br>
              <?php if ($allPhones): ?>
                <ul style="margin:.3rem 0 0 1rem;padding:0">
                  <?php foreach ($allPhones as $i=>$p): ?>
                    <li style="margin:.15rem 0">
                      <span class="mono"><?= h($p['phone']) ?></span>
                      <?php if($i===0): ?><em style="color:#0b5ed7"> (principal)</em><?php endif; ?>
                      <?php if(!empty($p['label'])): ?> — <span style="color:#555"><?= h($p['label']) ?></span><?php endif; ?>
                      <a class="icon-btn" style="margin-left:6px" href="tel:<?= h(preg_replace('/\s+/','',$p['phone'])) ?>" title="Appeler">📞</a>
                      <button class="icon-btn copy" data-copy="<?= h($p['phone']) ?>" title="Copier">📋</button>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <span style="color:#999">Aucun numéro</span>
              <?php endif; ?>
            </p>

            <p><strong>E-mails (tous) :</strong><br>
              <?php if ($allEmails): ?>
                <ul style="margin:.3rem 0 0 1rem;padding:0">
                  <?php foreach ($allEmails as $i=>$m): ?>
                    <li style="margin:.15rem 0">
                      <a class="mono" href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a>
                      <?php if($i===0): ?><em style="color:#0b5ed7"> (principal)</em><?php endif; ?>
                      <?php if(!empty($m['label'])): ?> — <span style="color:#555"><?= h($m['label']) ?></span><?php endif; ?>
                      <button class="icon-btn copy" data-copy="<?= h($m['email']) ?>" title="Copier">📋</button>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <span style="color:#999">Aucun e-mail</span>
              <?php endif; ?>
            </p>

            <p><strong>Date d'ajout :</strong><br>
              <?php if (!empty($dateAjoutAff)): ?>
                <span class="mono"><?= h($dateAjoutAff) ?></span>
              <?php elseif (!empty($client_infos['date_ajout'])): ?>
                <span class="mono"><?= h($client_infos['date_ajout']) ?></span>
              <?php else: ?>
                <span style="color:#999">Non renseignée</span>
              <?php endif; ?>
            </p>

            <h4>Adresse</h4>
            <div><?= nl2br(h($client_infos['adresse'] ?: 'Non renseignée')) ?></div>

            <p><strong>Code postal :</strong><br>
              <?= h($client_infos['code_postal'] ?: 'Non renseigné') ?>
            </p>

            <p><strong>Ville :</strong><br>
              <?= h($client_infos['ville'] ?: 'Non renseignée') ?>
            </p>
          </div>
        </div>

        <!-- Formulaire Édition (MASQUÉ par défaut) -->
        <div class="info-card" id="coordEditCard">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <h4 style="margin:0">Modifier</h4>
            <button id="coordCancelBtn" class="btn btn-secondary" type="button">✖ Annuler</button>
          </div>

          <!-- === FORMULAIRE D'ÉDITION (séparé) === -->
          <form id="coordForm" action="update_client.php" method="POST" style="margin-top:8px">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="client_id" value="<?= (int)$client_infos['id'] ?>">
            <!-- 🔁 RETOUR : rester sur le même client, onglet Coordonnées -->
            <input type="hidden" name="retour" value="clientele.php?client_id=<?= (int)$client_infos['id'] ?>#fiche&tab=coord">

            <div class="form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div>
                <label>Prénom<br>
                  <input type="text" name="prenom" maxlength="100" value="<?= h($client_infos['prenom'] ?? '') ?>">
                </label>
              </div>
              <div>
                <label>Nom<br>
                  <input type="text" name="nom" maxlength="100" value="<?= h($client_infos['nom'] ?? '') ?>">
                </label>
              </div>

              <!-- Téléphones multiples -->
              <div class="full" style="grid-column:1/-1">
                <label style="display:block;margin-bottom:6px">Téléphones <small>(le premier sera le principal)</small></label>
                <div id="phonesWrap"></div>
                <button type="button" class="notes-btn" style="background:#1976d2;margin-top:6px" id="addPhoneBtn">+ Ajouter un téléphone</button>
                <div class="small-muted">Astuce : “⬆ Principal” remonte la ligne en première position.</div>
              </div>

              <!-- Emails multiples -->
              <div class="full" style="grid-column:1/-1">
                <label style="display:block;margin-bottom:6px">E-mails <small>(le premier sera le principal)</small></label>
                <div id="emailsWrap"></div>
                <button type="button" class="notes-btn" style="background:#1976d2;margin-top:6px" id="addEmailBtn">+ Ajouter un e-mail</button>
              </div>

              <!-- Adresse / CP / Ville -->
              <div class="full" style="grid-column:1/-1">
                <label>Adresse<br>
                  <textarea name="adresse" rows="3" maxlength="1000"><?= h($client_infos['adresse'] ?? '') ?></textarea>
                </label>
              </div>
              <div>
                <label>Code postal<br>
                  <input type="text" name="code_postal" maxlength="10" value="<?= h($client_infos['code_postal'] ?? '') ?>">
                </label>
              </div>
              <div>
                <label>Ville<br>
                  <input type="text" name="ville" maxlength="100" value="<?= h($client_infos['ville'] ?? '') ?>">
                </label>
              </div>

              <!-- Détails + Date d'ajout -->
              <div class="full" style="grid-column:1/-1">
                <label>Détails / Notes<br>
                  <textarea name="details" rows="4" maxlength="5000"><?= h($client_infos['details'] ?? '') ?></textarea>
                </label>
              </div>
              <div>
                <label>Date d'ajout<br>
                  <input type="date" name="date_ajout" value="<?= h($client_infos['date_ajout'] ?? date('Y-m-d')) ?>">
                </label>
              </div>
            </div>

            <div class="form-actions" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
              <button class="notes-btn" type="submit">💾 Enregistrer</button>
            </div>
          </form>

          <!-- (Le bouton Supprimer est à côté de “Modifier” en haut) -->
        </div>
      </div>
    </div>

    <!-- Pane : Notes -->
    <div id="pane-notes" class="tab-pane">
      <div class="info-card notes-card">
        <h4>Détails / Notes</h4>

        <div id="notesView" class="notes-view">
          <?php if (!empty($client_infos['details'])): ?>
            <?= nl2br(h($client_infos['details'])) ?>
          <?php else: ?>
            <span class="small-muted">Aucune note pour l’instant.</span>
          <?php endif; ?>
        </div>

        <div id="notesEdit" style="display:none;">
          <textarea id="notesTextarea" class="notes-edit" placeholder="Saisissez vos notes…"><?= h($client_infos['details'] ?? '') ?></textarea>
          <div class="notes-actions" style="display:flex;gap:8px;margin-top:8px">
            <button id="saveNotes" class="notes-btn">💾 Enregistrer</button>
            <button id="cancelNotes" class="notes-btn secondary">✖ Annuler</button>
          </div>
        </div>

        <div class="notes-toolbar" style="margin-top:8px;display:flex;gap:6px">
          <button id="editNotes" class="icon-btn" title="Modifier">✏️</button>
          <form action="export_notes_pdf.php" method="POST" class="inline" style="margin:0">
            <input type="hidden" name="client_id" value="<?= (int)$selected_client_id ?>">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <button type="submit" class="icon-btn" title="Exporter les notes en PDF">⬇️</button>
          </form>
        </div>

        <input type="hidden" id="notesClientId" value="<?= (int)$selected_client_id ?>">
      </div>
    </div>

    <!-- Pane : Documents -->
    <div id="pane-docs" class="tab-pane">
      <div class="upload-form info-card">
        <h4>Uploader un document</h4>
        <form action="upload_document.php" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="client_id" value="<?= h($selected_client_id) ?>">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

          <div class="row">
            <label>Nom du document<br><input type="text" name="nom" placeholder="Ex : Attestation décennale, CNI, RIB…" required></label>
            <!-- Type = champ texte libre -->
            <label>Type de document<br><input type="text" name="type" placeholder="photo / devis / entretien" required></label>
          </div>
          <div class="row" style="margin-top:8px">
            <label>Fichier<br><input type="file" name="file" required></label>
            <!-- Saisie de la date métier -->
            <label>Date du document (affichée)<br><input type="date" name="document_date" placeholder="YYYY-MM-DD"></label>
          </div>
          <button type="submit" class="notes-btn" style="margin-top:8px">📤 Uploader</button>
          <div class="small-muted" style="margin-top:6px">
            La date ci-dessus (si fournie) sera enregistrée dans <code>client_documents</code> (colonne détectée
            automatiquement, ex. <code>document_date</code>). Si aucune colonne n’existe, elle sera créée.
          </div>
        </form>
      </div>

      <h3 style="margin:10px 0 6px">Documents du client <span class="small-muted">— du plus ancien au plus récent</span></h3>
      <?php if ($documents): ?>
        <div class="info-card" style="padding:0">
          <table style="width:100%">
            <thead><tr><th style="text-align:left;padding:8px">Nom</th><th>Type</th><th>Date</th><th>Fichier</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($documents as $doc):
              $docDate = '';
              if (!empty($doc['doc_date'])) {
                try { $dtD = new DateTime($doc['doc_date']); $docDate = $dtD->format('d/m/Y'); }
                catch (Exception $e) { $docDate = $doc['doc_date']; }
              }
            ?>
              <tr>
                <td class="mono" style="padding:6px 8px"><?= h($doc['nom'] ?: basename((string)$doc['file_path'])) ?></td>
                <td style="text-align:center"><?= h($doc['type']) ?></td>
                <td style="text-align:center"><?= h($docDate ?: '—') ?></td>
                <td style="text-align:center">
                  <?php if (!empty($doc['file_path'])): ?>
                    <a href="<?= h($doc['file_path']) ?>" target="_blank">📄 Ouvrir</a>
                  <?php else: ?>
                    <span style="color:#999;">(manquant)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="POST" action="supprimer_document.php" class="inline" onsubmit="return confirm('Supprimer ce document ?');">
                    <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button class="delete-btn" type="submit">🗑 Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="small-muted">Aucun document pour ce client.</p>
      <?php endif; ?>
    </div>

    <!-- Pane : Devis -->
    <div id="pane-devis" class="tab-pane">
      <h3>Devis réalisés</h3>
      <?php if ($devis): ?>
        <div class="info-card" style="padding:0">
          <table style="width:100%">
            <thead><tr><th>Date</th><th>Description</th><th>Total TTC (€)</th><th>PDF</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($devis as $d):
              $fileName = basename((string)$d['fichier_pdf']);
              $url      = "devis_pdf/$fileName";
              $dateAff = '';
              if (!empty($d['date_creation'])) {
                  try { $dtTmp = new DateTime($d['date_creation']); $dateAff = $dtTmp->format('d/m/Y'); }
                  catch (Exception $e) { $dateAff = $d['date_creation']; }
              }
            ?>
              <tr>
                <td class="mono" style="text-align:center"><?= h($dateAff) ?></td>
                <td style="padding:6px 8px"><?= nl2br(h($d['description'])) ?></td>
                <td class="mono" style="text-align:right;padding-right:8px"><?= number_format((float)$d['montant_ttc'],2,',',' ') ?></td>
                <td style="text-align:center">
                  <?php if ($d['fichier_pdf'] && file_exists(__DIR__ . "/devis_pdf/$fileName")): ?>
                    <a href="<?= h($url) ?>" target="_blank">📄 Ouvrir</a>
                  <?php else: ?>
                    <span style="color:#999;">(manquant)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="POST" action="supprimer_devis.php" class="inline" onsubmit="return confirm('Supprimer ce devis ?');">
                    <input type="hidden" name="devis_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="fichier_pdf" value="<?= h((string)$d['fichier_pdf']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button class="delete-btn" type="submit">🗑 Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="small-muted">Aucun devis pour ce client.</p>
      <?php endif; ?>
    </div>

    <!-- Pane : Factures -->
    <div id="pane-factures" class="tab-pane">
      <h3>Factures émises</h3>
      <?php if ($factures): ?>
        <div class="info-card" style="padding:0">
          <table style="width:100%">
            <thead><tr><th>Date</th><th>Description</th><th>Total TTC (€)</th><th>PDF</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($factures as $f):
              $fileNameF = basename((string)$f['fichier_pdf']);
              $urlF      = "facture_pdf/$fileNameF";
              $dateAffF = '';
              if (!empty($f['date_creation'])) {
                  try { $dtF = new DateTime($f['date_creation']); $dateAffF = $dtF->format('d/m/Y'); }
                  catch (Exception $e) { $dateAffF = $f['date_creation']; }
              }
            ?>
              <tr>
                <td class="mono" style="text-align:center"><?= h($dateAffF) ?></td>
                <td style="padding:6px 8px"><?= nl2br(h($f['description'])) ?></td>
                <td class="mono" style="text-align:right;padding-right:8px"><?= number_format((float)$f['montant_ttc'],2,',',' ') ?></td>
                <td style="text-align:center">
                  <?php if ($f['fichier_pdf'] && file_exists(__DIR__ . "/facture_pdf/$fileNameF")): ?>
                    <a href="<?= h($urlF) ?>" target="_blank">📄 Ouvrir</a>
                  <?php else: ?>
                    <span style="color:#999;">(manquant)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="POST" action="supprimer_facture.php" class="inline" onsubmit="return confirm('Supprimer cette facture ?');">
                    <input type="hidden" name="facture_id" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="fichier_pdf" value <?= '"'.h((string)$f['fichier_pdf']).'"' ?>>
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button class="delete-btn" type="submit">🗑 Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="small-muted">Aucune facture pour ce client.</p>
      <?php endif; ?>
    </div>

    <!-- Pane : BDC -->
    <div id="pane-bdc" class="tab-pane">
      <h3>Bons de commande</h3>
      <?php if (!empty($bdc)): ?>
        <div class="info-card" style="padding:0">
          <table style="width:100%">
            <thead>
              <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Total TTC (€)</th>
                <th>PDF</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($bdc as $b):
              $dateBDC = '';
              if (!empty($b['date_creation'])) {
                try { $dtB = new DateTime($b['date_creation']); $dateBDC = $dtB->format('d/m/Y'); }
                catch (Exception $e) { $dateBDC = $b['date_creation']; }
              }
              $fileNameB = basename((string)($b['fichier_pdf'] ?? ''));
              $resolvedUrl = null;
              $stored = trim((string)($b['fichier_pdf'] ?? ''));
              if ($stored && file_exists(__DIR__ . '/' . ltrim($stored, '/'))) {
                  $resolvedUrl = '/' . ltrim($stored, '/');
              } elseif ($fileNameB) {
                  $tryDirs = ['bdc_pdf','bons_commandes_pdf','bon_de_commande_pdf','uploads','documents','bdc'];
                  foreach ($tryDirs as $d) {
                      if (file_exists(__DIR__ . "/$d/$fileNameB")) { $resolvedUrl = "$d/$fileNameB"; break; }
                  }
              }
              $montant = is_null($b['montant_ttc']) ? '' : number_format((float)$b['montant_ttc'], 2, ',', ' ');
            ?>
              <tr>
                <td class="mono" style="text-align:center"><?= h($dateBDC) ?></td>
                <td style="padding:6px 8px"><?= nl2br(h($b['description'] ?? '')) ?></td>
                <td class="mono" style="text-align:right;padding-right:8px"><?= $montant ?></td>
                <td style="text-align:center">
                  <?php if ($resolvedUrl): ?>
                    <a href="<?= h($resolvedUrl) ?>" target="_blank">📄 Ouvrir</a>
                  <?php else: ?>
                    <span style="color:#999;">(manquant)</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="POST" action="supprimer_bdc.php" class="inline" onsubmit="return confirm('Supprimer ce bon de commande ?');">
                    <input type="hidden" name="bdc_id" value="<?= (int)($b['id'] ?? 0) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button class="delete-btn" type="submit">🗑 Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($debug_bdc && $bdc_debug_log): ?>
          <pre class="mono" style="background:#fafafa;border:1px solid #ddd;padding:8px;border-radius:8px"><?= h(implode("\n", $bdc_debug_log)) ?></pre>
        <?php endif; ?>

      <?php else: ?>
        <p class="small-muted">Aucun bon de commande pour ce client.</p>
        <?php if ($debug_bdc && $bdc_debug_log): ?>
          <pre class="mono" style="background:#fafafa;border:1px solid #ddd;padding:8px;border-radius:8px"><?= h(implode("\n", $bdc_debug_log)) ?></pre>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

</div>

<div id="toast" class="toast">Copié ✅</div>

<script>
/* Tabs (hash-aware) */
(function(){
  const btns = document.querySelectorAll('.tab-btn');
  const panes = {
    coord: document.getElementById('pane-coord'),
    notes: document.getElementById('pane-notes'),
    docs:  document.getElementById('pane-docs'),
    devis: document.getElementById('pane-devis'),
    factures: document.getElementById('pane-factures'),
    bdc:   document.getElementById('pane-bdc')
  };
  if(!btns.length) return;
  function activate(name){
    btns.forEach(b => b.classList.toggle('active', b.dataset.tab===name));
    Object.entries(panes).forEach(([k,el]) => el?.classList.toggle('active', k===name));
    if (!location.hash.includes('tab=')) {
      history.replaceState(null,'', (location.hash ? location.hash + '&' : '#') + 'tab='+name);
    } else {
      const newHash = location.hash.replace(/tab=[a-z]+/i, 'tab='+name);
      history.replaceState(null,'', newHash);
    }
  }
  function init(){
    const m = (location.hash||'').match(/tab=([a-z]+)/i);
    const name = m ? m[1] : 'coord';
    activate(panes[name] ? name : 'coord');
  }
  btns.forEach(b => b.addEventListener('click',()=>activate(b.dataset.tab)));
  window.addEventListener('hashchange', init);
  init();
})();

/* Copier + toast */
(function(){
  const toast = document.getElementById('toast');
  function showToast(msg){ toast.textContent = msg || 'Copié ✅'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1400); }
  document.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.copy'); if(!btn) return;
    const val = btn.getAttribute('data-copy') || '';
    try{ await navigator.clipboard.writeText(val); showToast(); }catch{ alert('Impossible de copier'); }
  });
})();

/* Édition Notes (AJAX) */
(function(){
  const editBtn = document.getElementById('editNotes');
  const view    = document.getElementById('notesView');
  const edit    = document.getElementById('notesEdit');
  const ta      = document.getElementById('notesTextarea');
  const save    = document.getElementById('saveNotes');
  const cancel  = document.getElementById('cancelNotes');
  const clientId= document.getElementById('notesClientId')?.value;
  const csrf    = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if(!clientId || !editBtn || !view || !edit || !ta || !save || !cancel) return;

  function toggle(on){ edit.style.display = on?'block':'none'; view.style.display = on?'none':'block'; if(on){ ta.focus(); const v=ta.value; ta.value=''; ta.value=v; } }
  editBtn.addEventListener('click', ()=>toggle(true));
  cancel.addEventListener('click', (e)=>{ e.preventDefault(); toggle(false); });

  document.addEventListener('keydown', (e)=>{
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){
      if(edit.style.display==='block'){ e.preventDefault(); save.click(); }
    }
  });

  save.addEventListener('click', async ()=>{
    const details = ta.value; save.disabled = true;
    try{
      const res = await fetch('update_notes.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify({ client_id: parseInt(clientId,10), details })
      });
      if(!res.ok){ const t=await res.text(); alert('Échec : '+t); return; }
      view.innerHTML = details.trim() ? details.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') : '<span class="small-muted">Aucune note pour l’instant.</span>';
      toggle(false);
      const toast = document.getElementById('toast'); toast.textContent='Sauvegardé ✅'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),1500);
    }catch(e){ alert('Erreur réseau'); console.error(e); }
    finally{ save.disabled=false; }
  });
})();

/* Multi-entrées (téléphones / e-mails) dans le formulaire */
(function(){
  const phonesWrap = document.getElementById('phonesWrap');
  const emailsWrap = document.getElementById('emailsWrap');
  const addPhoneBtn= document.getElementById('addPhoneBtn');
  const addEmailBtn= document.getElementById('addEmailBtn');
  if(!phonesWrap || !emailsWrap) return;

  function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function rowTpl(kind, value='', label=''){
    const isPhone = (kind==='phone');
    const nameV = isPhone ? 'phones[]'       : 'emails[]';
    const nameL = isPhone ? 'phones_label[]' : 'emails_label[]';
    const type  = isPhone ? 'tel' : 'email';
    const placeholder = isPhone ? '06 12 34 56 78' : 'nom@domaine.tld';
    const attrs = isPhone ? 'maxlength="50" inputmode="tel" pattern="[0-9+\\s.\\-()]{6,50}"' : 'maxlength="190"';
    return `
      <div class="row" style="align-items:end;margin-bottom:6px;border:1px dashed #d7d7d7;padding:8px;border-radius:8px;background:#fafafa">
        <div>
          <label>${isPhone?'Numéro':'E-mail'}<br>
            <input type="${type}" name="${nameV}" value="${esc(value)}" ${attrs} placeholder="${placeholder}" required>
          </label>
        </div>
        <div>
          <label>Libellé <small>(Pro, Perso…)</small><br>
            <input type="text" name="${nameL}" value="${esc(label)}" maxlength="50" placeholder="Pro / Perso / Autre">
          </label>
        </div>
        <div style="display:flex;gap:6px">
          <button type="button" class="notes-btn secondary js-move-top" title="Mettre en principal">⬆ Principal</button>
          <button type="button" class="delete-btn js-remove"    title="Supprimer">🗑</button>
        </div>
      </div>`;
  }

  function addPhone(value='', label=''){ phonesWrap.insertAdjacentHTML('beforeend', rowTpl('phone', value, label)); }
  function addEmail(value='', label=''){ emailsWrap.insertAdjacentHTML('beforeend', rowTpl('email', value, label)); }

  function hook(container){
    container.addEventListener('click', (e)=>{
      const row = e.target.closest('.row'); if(!row) return;
      if(e.target.closest('.js-remove')){ row.parentNode.removeChild(row); }
      if(e.target.closest('.js-move-top')){ container.insertBefore(row, container.firstElementChild); }
    });
  }
  hook(phonesWrap); hook(emailsWrap);

  // Pré-remplissage depuis PHP
  const initPhones = <?php
    $seedPhones = $client_phones;
    if ($selected_client_id) {
        if (empty($seedPhones) && !empty($client_infos['telephone'])) {
            $seedPhones[] = ['phone'=>$client_infos['telephone'], 'label'=>null];
        } elseif (!empty($client_infos['telephone'])) {
            $exists = false;
            foreach ($seedPhones as $p) if (trim($p['phone']) === trim($client_infos['telephone'])) { $exists = true; break; }
            if (!$exists) array_unshift($seedPhones, ['phone'=>$client_infos['telephone'],'label'=>null]);
        }
    }
    echo json_encode($seedPhones, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  ?>;

  const initEmails = <?php
    $seedEmails = $client_emails;
    if ($selected_client_id) {
        if (empty($seedEmails) && !empty($client_infos['email'])) {
            $seedEmails[] = ['email'=>$client_infos['email'], 'label'=>null];
        } elseif (!empty($client_infos['email'])) {
            $exists = false;
            foreach ($seedEmails as $m) if (strcasecmp(trim($m['email']), trim($client_infos['email'])) === 0) { $exists = true; break; }
            if (!$exists) array_unshift($seedEmails, ['email'=>$client_infos['email'],'label'=>null]);
        }
    }
    echo json_encode($seedEmails, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  ?>;

  if (Array.isArray(initPhones) && initPhones.length){ initPhones.forEach(p=> addPhone(p.phone||'', p.label||'')); } else { addPhone('',''); }
  if (Array.isArray(initEmails) && initEmails.length){ initEmails.forEach(m=> addEmail(m.email||'', m.label||'')); } else { addEmail('', ''); }

  addPhoneBtn?.addEventListener('click', ()=> addPhone('', ''));
  addEmailBtn?.addEventListener('click', ()=> addEmail('', ''));
})();

/* Raccourcis clavier */
(function(){
  const input = document.getElementById('search');
  document.addEventListener('keydown', (e)=>{
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){
      e.preventDefault(); input?.focus(); input?.select();
    }
    if(e.key === 'Escape'){
      const hasFiche = document.getElementById('fiche');
      if(hasFiche){ window.location.href = 'clientele.php#recherche'; }
    }
  });
})();

/* Affichage/masquage édition coordonnées */
(function(){
  const grid = document.getElementById('coordGrid');
  const showBtn = document.getElementById('coordEditBtn');
  const cancelBtn = document.getElementById('coordCancelBtn');
  if(!grid || !showBtn || !cancelBtn) return;

  function openEdit(){
    grid.classList.add('show-edit');
    // Ajouter edit=1 dans le hash si on est déjà sur l’onglet coord
    if (location.hash.includes('tab=coord')) {
      if (!location.hash.includes('edit=1')) {
        const h = location.hash + '&edit=1';
        history.replaceState(null,'', h);
      }
    }
  }
  function closeEdit(){
    grid.classList.remove('show-edit');
    if (location.hash.includes('edit=1')) {
      history.replaceState(null,'', location.hash.replace(/(&?edit=1)/,''));
    }
  }

  showBtn.addEventListener('click', openEdit);
  cancelBtn.addEventListener('click', closeEdit);

  // Ouvrir auto si hash contient edit=1
  if (location.hash.includes('edit=1')) openEdit();
})();
</script>
</body>
</html>
