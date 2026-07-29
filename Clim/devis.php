<?php
require 'auth.php';
require 'config.php';
require __DIR__ . '/inc/tva.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* ──────────── Paramètres recherche & limite ──────────── */
$q = trim($_GET['q'] ?? '');
$limit = 10;
$page_devis = max(1, (int)($_GET['page_devis'] ?? 1));
$page_bdc   = max(1, (int)($_GET['page_bdc']   ?? 1));
$page_fac   = max(1, (int)($_GET['page_fac']    ?? 1));
$dateFrom   = trim($_GET['date_from'] ?? '');
$dateTo     = trim($_GET['date_to']   ?? '');
if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

/* ──────────── Récupération des données (formulaire) ──────────── */

// Clients
$stmt     = $pdo->query('SELECT id, nom, prenom FROM clients ORDER BY nom, prenom');
$clients  = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Matériels (PAC)
$stmt      = $pdo->query('SELECT id, nom, prix, COALESCE(quantite_defaut, 1) AS quantite_defaut FROM pompes_a_chaleur ORDER BY nom');
$pac_list  = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Comptes bancaires actifs
$stmt       = $pdo->query('SELECT id, nom_du_compte, iban FROM bank_accounts WHERE est_actif=1 ORDER BY nom_du_compte');
$bank_list  = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ──────────── Helpers DB robustes ──────────── */
function table_exists(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1"); return true; }
    catch(Throwable $e){ return false; }
}
function show_cols(PDO $pdo, string $table): array {
    try { $st = $pdo->query("SHOW COLUMNS FROM `{$table}`"); return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
    catch(Throwable $e){ return []; }
}
function first_col(array $prefs, array $cols, $fallback = null) {
    foreach ($prefs as $c) if (in_array($c, $cols, true)) return $c;
    return $fallback;
}

/* ──────────── LISTES : Devis / BDC / Factures (10 + recherche) ──────────── */
function fetch_devis(PDO $pdo, string $q, int $limit, int $page = 1, string $dateFrom = '', string $dateTo = ''): array {
    if (!table_exists($pdo, 'devis') || !table_exists($pdo, 'clients')) return [];
    $c_devis   = show_cols($pdo, 'devis');
    $c_clients = show_cols($pdo, 'clients');

    $dateCol    = first_col(['date_creation','created_at','date','date_devis'], $c_devis, 'date_creation');
    $montantCol = first_col(['montant_ttc','total_ttc','montant'],               $c_devis, 'montant_ttc');
    $pdfCol     = first_col(['fichier_pdf','pdf_path','chemin_pdf'],             $c_devis, 'fichier_pdf');
    $numeroCol  = first_col(['numero','num_devis','reference'],                  $c_devis, 'numero');
    $clientId   = first_col(['client_id','id_client'],                           $c_devis, 'client_id');

    $nomCol   = first_col(['nom','last_name','lastname'],        $c_clients, 'nom');
    $preCol   = first_col(['prenom','first_name','firstname'],   $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);

    $sql = "
        SELECT d.id, d.`$numeroCol` AS numero, d.`$dateCol` AS date_doc,
               d.`$montantCol` AS montant_ttc, d.`$pdfCol` AS fichier_pdf,
               c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
        FROM devis d
        JOIN clients c ON c.id = d.`$clientId`
    ";
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").") ";
        $params[':q'] = "%$q%";
    }
    if ($dateFrom !== '') { $where[] = "DATE(d.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(d.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY d.`$dateCol` DESC, d.id DESC LIMIT :lim OFFSET :off";

    $offset = ($page - 1) * $limit;
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_bdc(PDO $pdo, string $q, int $limit, int $page = 1, string $dateFrom = '', string $dateTo = ''): array {
    if (!table_exists($pdo, 'bons_de_commande') || !table_exists($pdo, 'devis') || !table_exists($pdo, 'clients')) return [];
    $c_bdc     = show_cols($pdo, 'bons_de_commande');
    $c_devis   = show_cols($pdo, 'devis');
    $c_clients = show_cols($pdo, 'clients');

    $dateCol    = first_col(['date_creation','created_at','date'], $c_bdc, 'date_creation');
    $montantCol = first_col(['montant_ttc','total_ttc','montant'],  $c_bdc, 'montant_ttc');
    $pdfCol     = first_col(['fichier_pdf','pdf_path','chemin_pdf'],$c_bdc, 'fichier_pdf');
    $numeroCol  = first_col(['numero','reference'],                 $c_bdc, 'numero');

    $dv_idCol  = first_col(['id'],        $c_devis, 'id');
    $dv_cliCol = first_col(['client_id'], $c_devis, 'client_id');

    $nomCol   = first_col(['nom','last_name','lastname'],        $c_clients, 'nom');
    $preCol   = first_col(['prenom','first_name','firstname'],   $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);

    $sql = "
        SELECT b.id, b.`$numeroCol` AS numero, b.`$dateCol` AS date_doc,
               b.`$montantCol` AS montant_ttc, b.`$pdfCol` AS fichier_pdf,
               c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
        FROM bons_de_commande b
        JOIN devis d   ON d.`$dv_idCol`  = b.devis_id
        JOIN clients c ON c.id = d.`$dv_cliCol`
    ";
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").") ";
        $params[':q'] = "%$q%";
    }
    if ($dateFrom !== '') { $where[] = "DATE(b.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(b.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY b.`$dateCol` DESC, b.id DESC LIMIT :lim OFFSET :off";

    $offset = ($page - 1) * $limit;
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_factures(PDO $pdo, string $q, int $limit, int $page = 1, string $dateFrom = '', string $dateTo = ''): array {
    if (!table_exists($pdo, 'factures') || !table_exists($pdo, 'clients')) return [];
    $c_fac     = show_cols($pdo, 'factures');
    $c_clients = show_cols($pdo, 'clients');

    $dateCol    = first_col(['date_facture','date_creation','created_at','date'], $c_fac, 'date_creation');
    $montantCol = first_col(['montant_ttc','total_ttc','montant'],                 $c_fac, 'montant_ttc');
    $pdfCol     = first_col(['fichier_pdf','pdf_path','chemin_pdf'],               $c_fac, 'fichier_pdf');
    $numeroCol  = first_col(['numero','num_facture','reference'],                  $c_fac, 'numero');

    $nomCol   = first_col(['nom','last_name','lastname'],        $c_clients, 'nom');
    $preCol   = first_col(['prenom','first_name','firstname'],   $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);

    // Schéma 1 : factures.client_id ; sinon schéma 2 : factures.devis_id -> devis.client_id
    if (in_array('client_id', $c_fac, true)) {
        $join = "JOIN clients c ON c.id = f.client_id";
    } else {
        if (!table_exists($pdo, 'devis')) return [];
        $c_devis  = show_cols($pdo, 'devis');
        $dv_idCol = first_col(['id'],        $c_devis, 'id');
        $dv_cli   = first_col(['client_id'], $c_devis, 'client_id');
        $join = "JOIN devis d ON d.`$dv_idCol` = f.devis_id JOIN clients c ON c.id = d.`$dv_cli`";
    }

    $sql = "
        SELECT f.id, f.`$numeroCol` AS numero, f.`$dateCol` AS date_doc,
               f.`$montantCol` AS montant_ttc, f.`$pdfCol` AS fichier_pdf,
               c.`$nomCol` AS client_nom, c.`$preCol` AS client_prenom
        FROM factures f
        $join
    ";
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = " (c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").") ";
        $params[':q'] = "%$q%";
    }
    if ($dateFrom !== '') { $where[] = "DATE(f.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(f.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY f.`$dateCol` DESC, f.id DESC LIMIT :lim OFFSET :off";

    $offset = ($page - 1) * $limit;
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ──────────── Compteurs pour pagination ──────────── */
function count_devis(PDO $pdo, string $q, string $dateFrom = '', string $dateTo = ''): int {
    if (!table_exists($pdo, 'devis') || !table_exists($pdo, 'clients')) return 0;
    $c_devis = show_cols($pdo, 'devis'); $c_clients = show_cols($pdo, 'clients');
    $clientId = first_col(['client_id','id_client'], $c_devis, 'client_id');
    $dateCol = first_col(['date_creation','created_at','date','date_devis'], $c_devis, 'date_creation');
    $nomCol = first_col(['nom','last_name','lastname'], $c_clients, 'nom');
    $preCol = first_col(['prenom','first_name','firstname'], $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);
    $sql = "SELECT COUNT(*) FROM devis d JOIN clients c ON c.id = d.`$clientId`";
    $where = []; $params = [];
    if ($q !== '') { $where[] = "(c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
    if ($dateFrom !== '') { $where[] = "DATE(d.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(d.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->execute();
    return (int)$st->fetchColumn();
}
function count_bdc(PDO $pdo, string $q, string $dateFrom = '', string $dateTo = ''): int {
    if (!table_exists($pdo, 'bons_de_commande') || !table_exists($pdo, 'devis') || !table_exists($pdo, 'clients')) return 0;
    $c_bdc = show_cols($pdo, 'bons_de_commande'); $c_devis = show_cols($pdo, 'devis'); $c_clients = show_cols($pdo, 'clients');
    $dateCol = first_col(['date_creation','created_at','date'], $c_bdc, 'date_creation');
    $dv_cliCol = first_col(['client_id'], $c_devis, 'client_id');
    $dv_idCol = first_col(['id'], $c_devis, 'id');
    $nomCol = first_col(['nom','last_name','lastname'], $c_clients, 'nom');
    $preCol = first_col(['prenom','first_name','firstname'], $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);
    $sql = "SELECT COUNT(*) FROM bons_de_commande b JOIN devis d ON d.`$dv_idCol` = b.devis_id JOIN clients c ON c.id = d.`$dv_cliCol`";
    $where = []; $params = [];
    if ($q !== '') { $where[] = "(c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
    if ($dateFrom !== '') { $where[] = "DATE(b.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(b.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->execute();
    return (int)$st->fetchColumn();
}
function count_factures(PDO $pdo, string $q, string $dateFrom = '', string $dateTo = ''): int {
    if (!table_exists($pdo, 'factures') || !table_exists($pdo, 'clients')) return 0;
    $c_fac = show_cols($pdo, 'factures'); $c_clients = show_cols($pdo, 'clients');
    $dateCol = first_col(['date_facture','date_creation','created_at','date'], $c_fac, 'date_creation');
    $nomCol = first_col(['nom','last_name','lastname'], $c_clients, 'nom');
    $preCol = first_col(['prenom','first_name','firstname'], $c_clients, 'prenom');
    $phoneCol = first_col(['telephone','tel','phone','mobile','gsm'], $c_clients);
    if (in_array('client_id', $c_fac, true)) {
        $join = "JOIN clients c ON c.id = f.client_id";
    } else {
        if (!table_exists($pdo, 'devis')) return 0;
        $c_devis = show_cols($pdo, 'devis');
        $dv_idCol = first_col(['id'], $c_devis, 'id');
        $dv_cli = first_col(['client_id'], $c_devis, 'client_id');
        $join = "JOIN devis d ON d.`$dv_idCol` = f.devis_id JOIN clients c ON c.id = d.`$dv_cli`";
    }
    $sql = "SELECT COUNT(*) FROM factures f $join";
    $where = []; $params = [];
    if ($q !== '') { $where[] = "(c.`$nomCol` LIKE :q OR c.`$preCol` LIKE :q".($phoneCol ? " OR c.`$phoneCol` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
    if ($dateFrom !== '') { $where[] = "DATE(f.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
    if ($dateTo   !== '') { $where[] = "DATE(f.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->execute();
    return (int)$st->fetchColumn();
}

function pagination_html(int $page, int $totalPages, string $pageParam, string $q, string $dateFrom = '', string $dateTo = ''): string {
    if ($totalPages <= 1) return '';
    // Preserve all current page params from URL
    $base = [];
    if ($q !== '') $base['q'] = $q;
    if ($dateFrom !== '') $base['date_from'] = $dateFrom;
    if ($dateTo   !== '') $base['date_to']   = $dateTo;
    foreach (['page_devis','page_bdc','page_fac'] as $pp) {
        $v = (int)($_GET[$pp] ?? 1);
        if ($v > 1) $base[$pp] = $v;
    }
    $prev = $page > 1 ? $page - 1 : null;
    $next = $page < $totalPages ? $page + 1 : null;
    $html = '<div class="pagination">';
    if ($prev) {
        $p = $base; $p[$pageParam] = $prev;
        if ($prev === 1) unset($p[$pageParam]);
        $html .= '<a class="btn btn-secondary" href="devis.php?' . htmlspecialchars(http_build_query($p)) . '#documents">&laquo; Precedent</a>';
    } else {
        $html .= '<button class="btn btn-secondary" disabled>&laquo; Precedent</button>';
    }
    $html .= '<span class="page-info">Page ' . $page . ' sur ' . $totalPages . '</span>';
    if ($next) {
        $p = $base; $p[$pageParam] = $next;
        $html .= '<a class="btn btn-secondary" href="devis.php?' . htmlspecialchars(http_build_query($p)) . '#documents">Suivant &raquo;</a>';
    } else {
        $html .= '<button class="btn btn-secondary" disabled>Suivant &raquo;</button>';
    }
    $html .= '</div>';
    return $html;
}

/* ──────────── Helpers pour charger les lignes/PIÈCES ──────────── */
function fetchLinesFromDevisLignes(PDO $pdo, int $devisId): array {
    // La colonne `offert` est ajoutée par migration auto (traitement_devis.php) : on tolère son absence.
    $hasOffert = in_array('offert', show_cols($pdo, 'devis_lignes'), true);
    $sql = "
        SELECT pac_id, libelle, quantite, prix_unitaire AS prix_ht, tva_taux,
               " . ($hasOffert ? 'offert' : '0 AS offert') . ",
               piece_key, piece_nom
        FROM devis_lignes
        WHERE devis_id = :id
        ORDER BY id
    ";
    $q = $pdo->prepare($sql);
    $q->execute([':id' => $devisId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){
        return [
            'pac_id'    => isset($r['pac_id']) ? (string)$r['pac_id'] : null,
            'libelle'   => (string)($r['libelle'] ?? ''),
            'quantite'  => (int)($r['quantite'] ?? 1),
            'prix_ht'   => (float)($r['prix_ht'] ?? 0),
            'tva'       => isset($r['tva_taux']) ? (float)$r['tva_taux'] : 20.0,
            'offert'    => !empty($r['offert']),
            'piece_key' => ($r['piece_key'] ?? null),
            'piece_nom' => ($r['piece_nom'] ?? null),
        ];
    }, $rows);
}
function fetchLinesFromDevisItems(PDO $pdo, int $devisId): array {
    $sql = "
        SELECT di.pac_id,
               COALESCE(p.nom, CONCAT('Article #', di.pac_id)) AS libelle,
               di.qty        AS quantite,
               di.unit_price AS prix_ht
        FROM devis_items di
        LEFT JOIN pompes_a_chaleur p ON p.id = di.pac_id
        WHERE di.devis_id = :id
        ORDER BY di.id
    ";
    $q = $pdo->prepare($sql);
    $q->execute([':id' => $devisId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){
        return [
            'pac_id'   => isset($r['pac_id']) ? (string)$r['pac_id'] : null,
            'libelle'  => (string)($r['libelle'] ?? ''),
            'quantite' => (int)($r['quantite'] ?? 1),
            'prix_ht'  => (float)($r['prix_ht'] ?? 0),
            'tva'      => 20.0,
            'offert'   => false,
        ];
    }, $rows);
}

/**
 * Retourne les PIÈCES groupées.
 */
function fetchDevisPieces(PDO $pdo, int $devisId): array {
    $pieces = [];
    if (table_exists($pdo, 'devis_lignes')) {
        $rows = fetchLinesFromDevisLignes($pdo, $devisId);
        if (!empty($rows)) {
            $groups = [];
            $order  = [];
            foreach ($rows as $r) {
                $gkey = null;
                if (!empty($r['piece_key']))      { $gkey = 'k:' . $r['piece_key']; }
                elseif (!empty($r['piece_nom']))  { $gkey = 'n:' . mb_strtolower(trim($r['piece_nom']), 'UTF-8'); }
                else                               { $gkey = 'z:__default__'; }
                if (!isset($groups[$gkey])) {
                    $groups[$gkey] = [
                        'nom'   => $r['piece_nom'] ?: null,
                        'items' => [],
                    ];
                    $order[] = $gkey;
                }
                $groups[$gkey]['items'][] = [
                    'pac_id'   => $r['pac_id'],
                    'libelle'  => $r['libelle'],
                    'quantite' => $r['quantite'],
                    'prix_ht'  => $r['prix_ht'],
                    'tva'      => $r['tva'],
                    'offert'   => !empty($r['offert']),
                ];
            }
            $idx = 1;
            foreach ($order as $g) {
                $nom = $groups[$g]['nom'] ?: ('Pièce ' . $idx);
                $pieces[] = [
                    'nom'   => $nom,
                    'items' => $groups[$g]['items'],
                ];
                $idx++;
            }
            return [$pieces, 'devis_lignes'];
        }
    }
    if (table_exists($pdo, 'devis_items')) {
        $items = fetchLinesFromDevisItems($pdo, $devisId);
        if (!empty($items)) {
            $pieces[] = ['nom' => 'Pièce', 'items' => $items];
            return [$pieces, 'devis_items'];
        }
    }
    return [[], null];
}

/* ──────────── Pré-remplissage depuis un devis existant ──────────── */
$copyBanner = null;
$prefill    = null;
if (isset($_GET['copy_from_id']) && ctype_digit((string)$_GET['copy_from_id'])) {
    $copyId = (int)$_GET['copy_from_id'];
    $qstmt = $pdo->prepare("SELECT d.id, d.numero, d.client_id, d.description FROM devis d WHERE d.id = :id LIMIT 1");
    $qstmt->execute([':id' => $copyId]);
    $src = $qstmt->fetch(PDO::FETCH_ASSOC);
    if ($src) {
        [$pieces, $source] = fetchDevisPieces($pdo, $copyId);
        $flat = [];
        foreach ($pieces as $p) { foreach (($p['items'] ?? []) as $it) { $flat[] = $it; } }
        $prefill = [
            'copy_from_id'    => $src['id'],
            'original_numero' => $src['numero'],
            'client_id'       => $src['client_id'],
            'description'     => $src['description'],
            'pieces'          => $pieces,
            'items'           => $flat,
            'source'          => $source,
        ];
        $srcText = $source ? " (source : {$source})" : '';
        $copyBanner = "Reprise du devis n°" . htmlspecialchars($src['numero']) . "{$srcText} — dates réinitialisées, un nouveau numéro sera attribué.";
    }
}

/* ──────────── Exécutions des listes ──────────── */
$total_devis = count_devis($pdo, $q, $dateFrom, $dateTo);
$total_bdc   = count_bdc($pdo, $q, $dateFrom, $dateTo);
$total_fac   = count_factures($pdo, $q, $dateFrom, $dateTo);

$pages_devis = max(1, (int)ceil($total_devis / $limit));
$pages_bdc   = max(1, (int)ceil($total_bdc   / $limit));
$pages_fac   = max(1, (int)ceil($total_fac   / $limit));

$page_devis = min($page_devis, $pages_devis);
$page_bdc   = min($page_bdc,   $pages_bdc);
$page_fac   = min($page_fac,   $pages_fac);

$devis_list = fetch_devis($pdo, $q, $limit, $page_devis, $dateFrom, $dateTo);
$bdc_list   = fetch_bdc($pdo, $q, $limit, $page_bdc, $dateFrom, $dateTo);
$fac_list   = fetch_factures($pdo, $q, $limit, $page_fac, $dateFrom, $dateTo);

$cnt_devis = count($devis_list);
$cnt_bdc   = count($bdc_list);
$cnt_fac   = count($fac_list);

/* ──────────── Affichage helpers ──────────── */
function fmt_date($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    if ($ts === false) return htmlspecialchars((string)$d, ENT_QUOTES, 'UTF-8');
    return date('d/m/Y', $ts);
}
function fmt_eur($n) {
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, 2, ',', ' ').' €';
}

/**
 * Lien PDF permissif (conservé pour BDC).
 */
function link_pdf(?string $path, string $defaultDir = ''): string {
    $path = trim((string)$path);
    if ($path === '') return '<span class="muted">—</span>';
    if (preg_match('~^https?://~i', $path)) {
        return '<a class="btn btn-secondary" href="'.htmlspecialchars($path, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">Voir le PDF</a>';
    }
    $rel = $path;
    if ($defaultDir && strpos($path, '/') === false) {
        $rel = rtrim($defaultDir,'/').'/'.$path;
    }
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
    <link rel="stylesheet" href="style.css">
    <script>
        // Données matériels disponibles (depuis PHP)
        const pacData = <?= json_encode($pac_list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

        // Taux de TVA proposés — servis par inc/tva.php, qui valide aussi côté serveur.
        // Un seul endroit à modifier le jour où un taux change.
        const tvaRates    = <?= json_encode(tva_taux_courants(), JSON_UNESCAPED_UNICODE) ?>;
        const TVA_DEFAUT  = <?= json_encode(TVA_TAUX_DEFAUT) ?>;
        const TVA_AUTRE   = '__autre';

        // Gestion des pièces
        let pieceCounter = 0;
        function newPieceKey() { pieceCounter++; return 'p' + pieceCounter; }

        function addPiece(defaultName='Pièce', prefilledItems=[]) {
            const pieceKey = newPieceKey();
            const piecesHolder = document.getElementById('pieces-holder');

            const card = document.createElement('div');
            card.className = 'piece-card';
            card.dataset.pieceKey = pieceKey;

            const pieceNameInput = document.createElement('input');
            pieceNameInput.type = 'text';
            pieceNameInput.name = `pieces[${pieceKey}][nom]`;
            pieceNameInput.placeholder = "Nom de la pièce (ex. Salon, Cuisine)";
            pieceNameInput.value = defaultName || '';

            const titleWrap = document.createElement('div');
            titleWrap.className = 'piece-title-wrap';
            const titleLabel = document.createElement('label');
            titleLabel.textContent = 'Pièce :';
            titleLabel.classList.add('mt-0');
            titleWrap.append(titleLabel, pieceNameInput);

            const actions = document.createElement('div');
            actions.className = 'piece-actions';

            const addMatBtn = document.createElement('button');
            addMatBtn.type = 'button';
            addMatBtn.className = 'btn-outline';
            addMatBtn.textContent = '+ Ajouter un matériel';
            addMatBtn.onclick = () => addPacRow(card.querySelector('.lines-container'), pieceKey);

            const removePieceBtn = document.createElement('button');
            removePieceBtn.type = 'button';
            removePieceBtn.className = 'btn-danger';
            removePieceBtn.textContent = 'Supprimer la pièce';
            removePieceBtn.onclick = () => {
                const countRows = card.querySelectorAll('.pac-group').length;
                if (countRows > 0) {
                    confirmAction('Supprimer cette piece et tous ses materiels ?', function() {
                        card.remove();
                        updateTotal();
                    }, {title:'Suppression', confirmText:'Supprimer', danger:true});
                    return;
                }
                card.remove();
                updateTotal();
            };

            actions.append(addMatBtn, removePieceBtn);

            const head = document.createElement('div');
            head.className = 'piece-head';
            head.append(titleWrap, actions);

            const heads = document.createElement('div');
            heads.className = 'pac-heads';
            heads.innerHTML = '<span>Matériel (recherche / liste)</span><span>Nom sur devis</span><span>Quantité</span><span>Prix (€ HT)</span><span>TVA</span><span>Offert</span><span></span>';

            const linesContainer = document.createElement('div');
            linesContainer.className = 'lines-container';

            const pieceTotals = document.createElement('div');
            pieceTotals.className = 'piece-total';
            pieceTotals.innerHTML = 'Sous-total pièce — HT: <span class="subtotal-ht">0.00 €</span> • TTC: <span class="subtotal-ttc">0.00 €</span>';

            card.append(heads, linesContainer, pieceTotals);
            card.insertBefore(head, heads);
            piecesHolder.appendChild(card);

            if (Array.isArray(prefilledItems) && prefilledItems.length) {
                prefilledItems.forEach(it => {
                    addPacRow(linesContainer, pieceKey,
                        it.pac_id ? String(it.pac_id) : '',
                        (typeof it.quantite !== 'undefined' ? Number(it.quantite) : null),
                        it.libelle || '',
                        (typeof it.prix_ht !== 'undefined' ? Number(it.prix_ht) : ''),
                        (typeof it.tva !== 'undefined' ? Number(it.tva) : TVA_DEFAUT),
                        !!it.offert
                    );
                });
            } else {
                // Une nouvelle pièce commence avec 1 ligne vide pour guider l'utilisateur
                addPacRow(linesContainer, pieceKey);
            }

            return card;
        }

        function addPacRow(containerEl, pieceKey, defaultId = '', defaultQty = null, defaultLabel = '', defaultPrice = '', defaultTva = TVA_DEFAUT, defaultOffert = false) {
            if (!containerEl) return;
            const row = document.createElement('div');
            row.className = 'pac-group';

            const hiddenPieceKey = document.createElement('input');
            hiddenPieceKey.type = 'hidden';
            hiddenPieceKey.name = 'piece_keys[]';
            hiddenPieceKey.value = pieceKey;
            row.appendChild(hiddenPieceKey);

            const wrap = document.createElement('div');
            wrap.className = 'pac-select-wrap';

            const searchRow = document.createElement('div');
            searchRow.className = 'pac-search-row';

            const search = document.createElement('input');
            search.type = 'text';
            search.className = 'pac-search';
            search.placeholder = 'Rechercher un matériel…';

            const listBtn = document.createElement('button');
            listBtn.type = 'button';
            listBtn.className = 'list-btn';
            listBtn.title = 'Voir la liste des matériels';
            listBtn.textContent = 'Liste';

            searchRow.append(search, listBtn);

            const sugg = document.createElement('div');
            sugg.className = 'pac-suggestions';

            const listAll = document.createElement('div');
            listAll.className = 'pac-list-all';

            const select = document.createElement('select');
            select.name = 'pac_ids[]';
            // Pas de `required` HTML5 : ce select est masqué (display:none) donc non-focusable.
            // Un required sur un champ caché fait que le navigateur bloque la soumission EN SILENCE.
            // La présence d'un matériel est validée en JS au submit (voir handler form-devis).
            select.style.display = 'none';
            select.onchange = () => updatePrixEtQty(row);

            const firstOpt = new Option('-- Choisir un matériel --', '');
            select.appendChild(firstOpt);
            pacData.forEach(pac => {
              const opt = new Option(pac.nom, pac.id);
              opt.dataset.prix = pac.prix;
              opt.dataset.qdef = (pac.quantite_defaut ?? 1);
              select.appendChild(opt);
            });

            wrap.append(searchRow, sugg, listAll, select);

            const libelle = document.createElement('input');
            libelle.type = 'text';
            libelle.name = 'libelles[]';
            libelle.placeholder = 'Nom affiché sur le devis';
            libelle.value = defaultLabel || '';
            if (defaultLabel) libelle.dataset.touched = '1';
            libelle.oninput = () => { libelle.dataset.touched = '1'; };

            const qty = document.createElement('input');
            qty.type = 'number';
            qty.name = 'quantites[]';
            qty.className = 'pac-qty';
            qty.min = '1';
            qty.step = '1';
            qty.value = (defaultQty === null || typeof defaultQty === 'undefined') ? '' : String(parseInt(defaultQty,10));
            // Pas de `required` : une quantité vide est ramenée à 1 au submit (clampQty). Voir astuce UI.
            qty.oninput = () => { updateTotal(); };
            qty.addEventListener('blur', () => { clampQty(qty); updateTotal(); });

            const prix = document.createElement('input');
            prix.type = 'number';
            prix.name = 'prix[]';
            prix.className = 'pac-price';
            prix.step = '0.01';
            prix.placeholder = 'Prix (€ HT)';
            // Pas de `required` HTML5 : le prix est validé en JS au submit (uniquement pour les lignes
            // ayant un matériel sélectionné), pour éviter de bloquer sur une ligne vide.
            if (defaultPrice !== '') { prix.value = Number(defaultPrice).toFixed(2); }
            prix.oninput = updateTotal;

            // ── Cellule TVA : liste des taux courants + échappatoire « Autre… ».
            // Le taux réellement posté vit dans un input hidden : le <select> peut valoir
            // "__autre", et un taux de 0 % n'a plus besoin du hack "0.0" d'autrefois puisque
            // la valeur postée est toujours une chaîne explicite, jamais un champ absent.
            const tvaCell = document.createElement('div');
            tvaCell.className = 'tva-cell';

            const tvaValue = document.createElement('input');
            tvaValue.type  = 'hidden';
            tvaValue.name  = 'tva_taux[]';
            tvaValue.className = 'pac-tva-value';

            const tva = document.createElement('select');
            tva.className = 'pac-tva';
            tvaRates.forEach(r => {
                const o = new Option(r.label, String(r.taux));
                o.title = r.aide || '';
                tva.appendChild(o);
            });
            tva.appendChild(new Option('Autre…', TVA_AUTRE));

            const tvaCustom = document.createElement('input');
            tvaCustom.type = 'number';
            tvaCustom.className = 'pac-tva-custom';
            tvaCustom.step = '0.1';
            tvaCustom.min  = '0';
            tvaCustom.max  = '100';
            tvaCustom.title = 'Taux personnalisé, en pourcentage';
            tvaCustom.hidden = true;

            tva.onchange = () => {
                if (tva.value === TVA_AUTRE) {
                    tvaCustom.hidden = false;
                    if (tvaCustom.value === '') tvaCustom.value = String(Number(tvaValue.value) || 0);
                    tvaCustom.focus();
                    tvaCustom.select();
                } else {
                    tvaCustom.hidden = true;
                }
                syncTvaCell(row);
                updateTotal();
            };
            tvaCustom.oninput = () => { syncTvaCell(row); updateTotal(); };
            tvaCustom.addEventListener('blur', () => { syncTvaCell(row); updateTotal(); });

            tvaCell.append(tvaValue, tva, tvaCustom);

            // ── Case "Offert" : la ligne reste imprimée sur le devis mais ne pèse rien dans les totaux.
            // Le drapeau est un input hidden TOUJOURS posté ('0' ou '1') : une checkbox non cochée
            // n'est pas envoyée par le navigateur, ce qui décalerait offerts[] par rapport à prix[].
            const offertWrap = document.createElement('label');
            offertWrap.className = 'offert-toggle';
            offertWrap.title = 'Offrir cet article : il apparaît sur le devis avec la mention « Offert »';

            const offertFlag = document.createElement('input');
            offertFlag.type  = 'hidden';
            offertFlag.name  = 'offerts[]';
            offertFlag.value = '0';

            const offertBox = document.createElement('input');
            offertBox.type = 'checkbox';
            offertBox.className = 'pac-offert';
            offertBox.onchange = () => { applyOffert(row, offertBox.checked); updateTotal(); };

            const offertTxt = document.createElement('span');
            offertTxt.textContent = 'Offert';

            offertWrap.append(offertBox, offertTxt, offertFlag);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'remove-btn';
            btn.textContent = 'Suppr.';
            btn.title   = 'Retirer cette ligne';
            btn.onclick = () => { row.remove(); updateTotal(); };

            row.append(wrap, libelle, qty, prix, tvaCell, offertWrap, btn);
            containerEl.appendChild(row);

            // Positionne le taux initial (catalogue, reprise de devis ou brouillon)
            setRowTva(row, defaultTva);

            function highlight(text, q){
              const i = text.toLowerCase().indexOf(q.toLowerCase());
              if (i<0) return text;
              return text.substring(0,i) + '<span class="hl">' + text.substring(i, i+q.length) + '</span>' + text.substring(i+q.length);
            }
            function renderSuggestions(q) {
              const query = (q || '').toLowerCase().trim();
              sugg.innerHTML = '';
              if (!query) { sugg.style.display = 'none'; return; }
              const matches = pacData.filter(p =>
                String(p.nom).toLowerCase().includes(query) ||
                String(p.prix).toLowerCase().includes(query) ||
                String(p.id).includes(query)
              ).slice(0, 14);
              if (matches.length === 0) { sugg.style.display = 'none'; return; }
              matches.forEach(p => {
                const item = document.createElement('div'); item.className = 'item';
                const name = document.createElement('span'); name.className='name'; name.innerHTML = highlight(p.nom, query);
                const price = document.createElement('span'); price.className='price'; price.textContent = Number(p.prix).toFixed(2) + ' €';
                item.onclick = () => selectPac(p);
                item.append(name, price);
                sugg.appendChild(item);
              });
              sugg.style.display = 'block';
            }
            function renderListAll() {
              const arr = [...pacData].sort((a,b)=> String(a.nom).localeCompare(String(b.nom)));
              listAll.innerHTML = '';
              arr.forEach(p => {
                const item = document.createElement('div'); item.className = 'item';
                const name = document.createElement('span'); name.className='name'; name.textContent = p.nom;
                const price = document.createElement('span'); price.className='price'; price.textContent = Number(p.prix).toFixed(2) + ' €';
                item.onclick = () => { selectPac(p); listAll.style.display='none'; };
                item.append(name, price);
                listAll.appendChild(item);
              });
              listAll.style.display = 'block';
            }
            function selectPac(p) {
              if (!select.querySelector(`option[value="${String(p.id)}"]`)) {
                const o = new Option(p.nom, p.id);
                o.dataset.prix = p.prix;
                o.dataset.qdef = (p.quantite_defaut ?? 1);
                select.appendChild(o);
              }
              select.value = String(p.id);
              updatePrixEtQty(row);
              search.value = p.nom;
              const libEl = row.querySelector('input[name="libelles[]"]');
              if (!libEl.dataset.touched || libEl.value.trim() === '') {
                libEl.value = p.nom;
              }
              sugg.style.display = 'none';
            }
            search.addEventListener('input', (e) => { renderSuggestions(e.target.value); listAll.style.display = 'none'; });
            search.addEventListener('keydown', (e) => {
              if (e.key === 'Enter') { e.preventDefault(); const first = sugg.querySelector('.item'); if (first) first.click(); }
              else if (e.key === 'Escape') { sugg.style.display = 'none'; listAll.style.display = 'none'; }
            });
            listBtn.addEventListener('click', (e) => { e.preventDefault(); if (listAll.style.display === 'block') listAll.style.display = 'none'; else { sugg.style.display = 'none'; renderListAll(); } });
            document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) { sugg.style.display = 'none'; listAll.style.display = 'none'; } });

            if (defaultId) {
              let p = pacData.find(x => String(x.id) === String(defaultId));
              if (!p) {
                  const label = defaultLabel ? defaultLabel : `Matériel #${defaultId} (archivé)`;
                  const o = new Option(label + ' (archivé)', String(defaultId));
                  if (defaultPrice !== '' && !isNaN(Number(defaultPrice))) o.dataset.prix = String(defaultPrice);
                  o.dataset.qdef = '1';
                  select.appendChild(o);
                  select.value = String(defaultId);
                  search.value = label;
              } else {
                  select.value = String(p.id);
                  search.value = p.nom;
              }
            }
            updatePrixEtQty(row, defaultPrice);
            if (defaultOffert) applyOffert(row, true);
        }

        // Bascule l'état "offert" d'une ligne : prix neutralisé et verrouillé.
        // On utilise readOnly (et non disabled) pour que le champ reste posté et que
        // les tableaux prix[] / quantites[] / offerts[] gardent le même nombre d'entrées.
        function applyOffert(row, on) {
            const flag = row.querySelector('input[name="offerts[]"]');
            const box  = row.querySelector('.pac-offert');
            const prix = row.querySelector('.pac-price');

            if (flag) flag.value = on ? '1' : '0';
            if (box)  box.checked = !!on;
            row.classList.toggle('is-offert', !!on);

            if (!prix) return;
            if (on) {
                // Mémorise le prix courant pour pouvoir le restaurer si on décoche
                if (prix.value !== '' && Number(prix.value) !== 0) prix.dataset.prevPrice = prix.value;
                prix.value = '0.00';
                prix.readOnly = true;
            } else {
                prix.readOnly = false;
                if (prix.dataset.prevPrice) {
                    prix.value = prix.dataset.prevPrice;
                    delete prix.dataset.prevPrice;
                } else if (Number(prix.value) === 0) {
                    // Aucun prix mémorisé (ligne reprise d'un devis existant) : on retombe sur le prix catalogue
                    const sel = row.querySelector('select[name="pac_ids[]"]');
                    const opt = sel && sel.selectedOptions[0];
                    prix.value = (opt && opt.dataset.prix) ? Number(opt.dataset.prix).toFixed(2) : '';
                }
            }
        }

        function isOffertRow(row) {
            const box = row.querySelector('.pac-offert');
            return !!(box && box.checked);
        }

        // ── TVA d'une ligne ────────────────────────────────────────────────────
        // Le hidden input `.pac-tva-value` est la SEULE source de vérité : c'est lui
        // qui part en POST, le <select> et le champ « Autre… » ne sont que l'interface.

        function rowTvaRate(row) {
            const hidden = row.querySelector('.pac-tva-value');
            const n = parseFloat(hidden ? hidden.value : '');
            return isNaN(n) ? TVA_DEFAUT : n;
        }

        // Pendant JS de la conversion faite par tva_normalise_taux() : « 13,5 » et « 13.5 »
        // désignent le même taux. Sans ça, parseFloat('13,5') vaut 13 et l'écran afficherait
        // un total différent de celui enregistré en base.
        function parseRate(raw) {
            if (typeof raw === 'string') raw = raw.trim().replace(',', '.');
            return parseFloat(raw);
        }

        function clampRate(n) {
            if (isNaN(n)) return TVA_DEFAUT;
            return Math.min(100, Math.max(0, Math.round(n * 100) / 100));
        }

        // Pendant JS de tva_label_taux() : « 20 % », « 5,5 % », « 0 % »
        function tvaLabel(rate) {
            const known = tvaRates.find(t => Number(t.taux) === rate);
            return known ? known.label : String(rate).replace('.', ',') + ' %';
        }

        // Recalcule le hidden depuis l'état visible. Le bornage à [0, 100] et l'arrondi
        // à 2 décimales reproduisent tva_normalise_taux() côté PHP : même règle des deux côtés.
        function syncTvaCell(row) {
            const sel    = row.querySelector('.pac-tva');
            const custom = row.querySelector('.pac-tva-custom');
            const hidden = row.querySelector('.pac-tva-value');
            if (!sel || !hidden) return;

            const raw = (sel.value === TVA_AUTRE)
                ? parseRate(custom ? custom.value : '')
                : parseRate(sel.value);
            hidden.value = String(clampRate(raw));
        }

        // Applique un taux : cale le select sur le cran correspondant s'il existe, bascule
        // sur « Autre… » sinon — cas d'un devis repris avec un taux sorti de la liste depuis.
        function setRowTva(row, rate) {
            const sel    = row.querySelector('.pac-tva');
            const custom = row.querySelector('.pac-tva-custom');
            const hidden = row.querySelector('.pac-tva-value');
            if (!sel || !hidden) return;

            const r = clampRate(parseRate(rate));
            if (tvaRates.some(t => Number(t.taux) === r)) {
                sel.value = String(r);
                if (custom) { custom.hidden = true; custom.value = ''; }
            } else {
                sel.value = TVA_AUTRE;
                if (custom) { custom.hidden = false; custom.value = String(r); }
            }
            hidden.value = String(r);
        }

        // Applique un taux à toutes les lignes du devis (cas courant : tout le devis
        // au même taux, qu'on ne veut pas saisir ligne par ligne).
        function applyTvaToAllRows(rate) {
            const rows = document.querySelectorAll('.pac-group');
            rows.forEach(row => setRowTva(row, rate));
            updateTotal();
            return rows.length;
        }

        function clampQty(input) {
            const raw = (input.value ?? '').trim();
            const n = parseInt(raw, 10);
            if (raw === '' || isNaN(n) || n < 1) input.value = '1';
            else input.value = String(n);
        }

        function updatePrixEtQty(row, forcedPrice = null) {
            const select = row.querySelector('select[name="pac_ids[]"]');
            const opt    = select.selectedOptions[0];
            const prixEl = row.querySelector('.pac-price');
            const qtyEl  = row.querySelector('.pac-qty');
            const libEl  = row.querySelector('input[name="libelles[]"]');

            if (opt && opt.value) {
                const prix = opt.dataset.prix;
                const qdef = opt.dataset.qdef || '1';
                if (!qtyEl.value || qtyEl.value === '' || qtyEl.value === '0') qtyEl.value = qdef;
                if (forcedPrice !== null && forcedPrice !== '' && !isNaN(Number(forcedPrice))) {
                    prixEl.value = Number(forcedPrice).toFixed(2);
                } else if (prix) {
                    prixEl.value = Number(prix).toFixed(2);
                }
                if (!libEl.dataset.touched || libEl.value.trim() === '') libEl.value = opt.text;
            } else {
                if (forcedPrice !== null && forcedPrice !== '' && !isNaN(Number(forcedPrice))) {
                    prixEl.value = Number(forcedPrice).toFixed(2);
                } else if (!prixEl.value) {
                    prixEl.value = '';
                }
                if (!qtyEl.value) qtyEl.value = '';
            }
            // Ligne offerte : le prix catalogue qu'on vient d'injecter est remis à 0 (et mémorisé).
            if (isOffertRow(row)) applyOffert(row, true);
            updateTotal();
        }

        // === Rounding robuste à 2 décimales ===
        function round2(n) {
            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
        }

        // === Calcul des totaux avec "mix TVA" (regroupement par taux) ===
        function updateTotal() {
            let totalHT = 0;
            let totalTTC = 0;

            // Totaux par taux de TVA (pour le total global)
            const globalHTByRate = {};

            document.querySelectorAll('.piece-card').forEach(card => {
                // Pour le sous-total pièce
                const pieceHTByRate = {};
                let pieceHT = 0;
                let pieceTTC = 0;

                card.querySelectorAll('.pac-group').forEach(row => {
                    const prix = parseFloat(row.querySelector('.pac-price')?.value || '0');
                    const qtyRaw = (row.querySelector('.pac-qty')?.value ?? '').trim();
                    // Quantité vide => 1
                    let qty = (qtyRaw === '' ? 1 : parseInt(qtyRaw, 10));
                    if (isNaN(qty) || qty < 1) qty = 1;

                    const tvaV = rowTvaRate(row);
                    // Article offert : ne compte pour rien dans les totaux
                    const lineHT = isOffertRow(row) ? 0 : round2((isNaN(prix) ? 0 : prix) * qty);

                    const rKey = String(tvaV);
                    pieceHTByRate[rKey] = round2((pieceHTByRate[rKey] || 0) + lineHT);
                    globalHTByRate[rKey] = round2((globalHTByRate[rKey] || 0) + lineHT);
                });

                Object.keys(pieceHTByRate).forEach(key => {
                    const rate = parseFloat(key);
                    const ht = pieceHTByRate[key];
                    pieceHT = round2(pieceHT + ht);
                    pieceTTC = round2(pieceTTC + round2(ht * (1 + (isNaN(rate) ? 0.20 : (rate/100)))));
                });

                totalHT = round2(totalHT + pieceHT);

                const subHTEl  = card.querySelector('.subtotal-ht');
                const subTTCEl = card.querySelector('.subtotal-ttc');
                if (subHTEl)  subHTEl.textContent  = pieceHT.toFixed(2) + ' €';
                if (subTTCEl) subTTCEl.textContent = pieceTTC.toFixed(2) + ' €';
            });

            Object.keys(globalHTByRate).forEach(key => {
                const rate = parseFloat(key);
                const ht = globalHTByRate[key];
                totalTTC = round2(totalTTC + round2(ht * (1 + (isNaN(rate) ? 0.20 : (rate/100)))));
            });

            const totalEl = document.getElementById('total');
            if (totalEl) totalEl.textContent = totalHT.toFixed(2) + ' €';

            const totalTTCEl = document.getElementById('total_ttc');
            if (totalTTCEl) totalTTCEl.textContent = totalTTC.toFixed(2) + ' €';

            const m1 = document.getElementById('montant_paiement_1');
            if (m1) m1.value = totalTTC.toFixed(2);

            return { totalHT, totalTTC };
        }

        function syncDateCreation() {
            const inputDate = document.getElementById('date_creation_date');
            const hidden    = document.getElementById('date_creation_hidden');
            if (!inputDate || !hidden) return;
            const d = (inputDate.value || '').trim();
            hidden.value = d ? (d + 'T00:00') : '';
        }

        function prefillFromData(data) {
            if (!data) return;
            const selClient = document.getElementById('client_id');
            if (selClient && data.client_id) selClient.value = String(data.client_id);
            const desc = document.getElementById('description');
            if (desc) desc.value = data.description || '';

            const holder = document.getElementById('pieces-holder');
            holder.innerHTML = '';

            if (Array.isArray(data.pieces) && data.pieces.length > 0) {
                data.pieces.forEach((p, i) => {
                    const nm = (p && typeof p.nom === 'string' && p.nom.trim() !== '') ? p.nom : ('Pièce ' + (i+1));
                    const items = Array.isArray(p.items) ? p.items : [];
                    addPiece(nm, items);
                });
            } else {
                addPiece('Pièce', Array.isArray(data.items) ? data.items : []);
            }

            const copiedFrom = document.getElementById('copied_from_id');
            if (copiedFrom && data.copy_from_id) copiedFrom.value = String(data.copy_from_id);
            updateTotal();
            if (data.source) {
                const b = document.getElementById('source-badge');
                if (b) b.textContent = data.source;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const addBtn = document.getElementById('add-piece-btn');
            if (addBtn) addBtn.addEventListener('click', (e) => { e.preventDefault(); addPiece('Pièce'); });

            // ── Application groupée du taux de TVA
            const bulkSel    = document.getElementById('tva-bulk-select');
            const bulkCustom = document.getElementById('tva-bulk-custom');
            const bulkApply  = document.getElementById('tva-bulk-apply');
            if (bulkSel && bulkApply) {
                bulkSel.addEventListener('change', () => {
                    const autre = (bulkSel.value === TVA_AUTRE);
                    if (!bulkCustom) return;
                    bulkCustom.hidden = !autre;
                    if (autre) { bulkCustom.focus(); bulkCustom.select(); }
                });
                bulkApply.addEventListener('click', () => {
                    const raw = (bulkSel.value === TVA_AUTRE)
                        ? parseRate(bulkCustom ? bulkCustom.value : '')
                        : parseRate(bulkSel.value);
                    if (isNaN(raw)) {
                        if (typeof showToast === 'function') showToast('Indiquez un taux de TVA.', 'error');
                        return;
                    }
                    const rate = clampRate(raw);
                    const n = applyTvaToAllRows(rate);
                    window.__devisDirty = true;
                    if (typeof showToast === 'function') {
                        showToast(n > 0
                            ? 'TVA ' + tvaLabel(rate) + ' appliquee a ' + n + ' ligne' + (n > 1 ? 's' : '') + '.'
                            : 'Aucune ligne a modifier.', n > 0 ? 'success' : 'error');
                    }
                });
            }

            const prefill = window.__prefill || null;
            // Auto-save draft restore
            var __draft = null;
            try { __draft = localStorage.getItem('devis_draft_v1'); } catch(ex){}
            if (prefill) {
                prefillFromData(prefill);
                try { localStorage.removeItem('devis_draft_v1'); } catch(ex){}
            } else if (__draft) {
                try {
                    var draftData = JSON.parse(__draft);
                    confirmAction(
                        'Un brouillon non enregistre a ete trouve. Voulez-vous le restaurer ?',
                        function(){
                            // Restore simple fields
                            var f = document.getElementById('form-devis');
                            if (draftData.client_id) { var s = f.querySelector('#client_id'); if(s) s.value = draftData.client_id; }
                            if (draftData.description) { var d = f.querySelector('#description'); if(d) d.value = draftData.description; }
                            if (draftData.date_creation_date) { var dc = f.querySelector('#date_creation_date'); if(dc) dc.value = draftData.date_creation_date; }
                            if (draftData.date_echeance) { var de = f.querySelector('#date_echeance'); if(de) de.value = draftData.date_echeance; }
                            if (draftData.mode_paiement_1) { var mp = f.querySelector('#mode_paiement_1'); if(mp) mp.value = draftData.mode_paiement_1; }
                            if (draftData.bank_account_id_1) { var ba = f.querySelector('#bank_account_id_1'); if(ba) ba.value = draftData.bank_account_id_1; }
                            // Restore pieces
                            if (draftData.pieces && draftData.pieces.length) {
                                document.getElementById('pieces-holder').innerHTML = '';
                                draftData.pieces.forEach(function(p){
                                    addPiece(p.nom || 'Piece', p.items || []);
                                });
                            }
                            syncDateCreation();
                            updateTotal();
                            window.__devisDirty = true;
                        },
                        {title:'Brouillon disponible', confirmText:'Restaurer', cancelText:'Ignorer', danger:false}
                    );
                    addPiece('Salon');
                } catch(ex){ addPiece('Salon'); }
            } else { addPiece('Salon'); }

            const inputDate = document.getElementById('date_creation_date');
            if (inputDate) { inputDate.addEventListener('change', syncDateCreation); syncDateCreation(); }

            const form = document.getElementById('form-devis');
            if (form) {
                form.addEventListener('submit', (e) => {
                    // --- Validation ---
                    function _clrErr(el){ if(!el)return; el.classList.remove('field-error'); var m=el.parentNode.querySelector('.field-error-msg'); if(m)m.remove(); }
                    function _setErr(el,msg){ if(!el)return; _clrErr(el); el.classList.add('field-error'); var s=document.createElement('span'); s.className='field-error-msg'; s.textContent=msg; el.parentNode.appendChild(s); }

                    var valid = true;
                    var clientId = document.getElementById('client_id');
                    var desc = document.getElementById('description');
                    var dateC = document.getElementById('date_creation_date');
                    var dateE = document.getElementById('date_echeance');

                    if (clientId && !clientId.value) { _setErr(clientId, 'Veuillez selectionner un client.'); valid = false; } else { _clrErr(clientId); }
                    if (desc && !desc.value.trim()) { _setErr(desc, 'Veuillez decrire l\'installation.'); valid = false; } else { _clrErr(desc); }
                    if (dateC && !dateC.value) { _setErr(dateC, 'Date de creation requise.'); valid = false; } else { _clrErr(dateC); }
                    if (dateE && !dateE.value) { _setErr(dateE, 'Date d\'echeance requise.'); valid = false; } else { _clrErr(dateE); }

                    // Au moins un matériel doit être sélectionné (le select étant masqué, on valide ici).
                    var validRows = 0, badPrice = false;
                    document.querySelectorAll('.pac-group').forEach(row => {
                        var sel = row.querySelector('select[name="pac_ids[]"]');
                        if (sel && sel.value) {
                            validRows++;
                            if (isOffertRow(row)) return; // pas de prix à saisir pour un article offert
                            var pr = row.querySelector('.pac-price');
                            if (!pr || pr.value === '' || isNaN(Number(pr.value))) badPrice = true;
                        }
                    });
                    if (validRows === 0) { if(typeof showToast==='function') showToast('Selectionnez au moins un materiel dans la liste.','error'); valid = false; }
                    else if (badPrice) { if(typeof showToast==='function') showToast('Renseignez un prix pour chaque materiel.','error'); valid = false; }

                    if (!valid) { e.preventDefault(); var fe=form.querySelector('.field-error'); if(fe)fe.scrollIntoView({behavior:'smooth',block:'center'}); return; }

                    // --- Original logic ---
                    // Retirer les lignes sans matériel sélectionné (évite d'envoyer des lignes vides en base).
                    document.querySelectorAll('.pac-group').forEach(row => {
                        var sel = row.querySelector('select[name="pac_ids[]"]');
                        if (!sel || !sel.value) row.remove();
                    });
                    document.querySelectorAll('.pac-qty').forEach(clampQty);
                    document.querySelectorAll('.piece-card').forEach(card => {
                        if (card.querySelectorAll('.pac-group').length === 0) card.remove();
                    });
                    syncDateCreation();
                    const totals = updateTotal();
                    const m1 = document.getElementById('montant_paiement_1');
                    if (m1) m1.value = totals.totalTTC.toFixed(2);

                    window.__devisDirty = false;
                    try { localStorage.removeItem('devis_draft_v1'); } catch(ex){}
                    try { localStorage.setItem('devis_created', '1'); } catch(ex){}
                });

                // Clear validation errors on input
                ['client_id','description','date_creation_date','date_echeance'].forEach(id => {
                    var el = document.getElementById(id);
                    if(el){ el.addEventListener('input',()=>{el.classList.remove('field-error');var m=el.parentNode.querySelector('.field-error-msg');if(m)m.remove();}); el.addEventListener('change',()=>{el.classList.remove('field-error');var m=el.parentNode.querySelector('.field-error-msg');if(m)m.remove();}); }
                });

                // Unsaved changes warning
                window.__devisDirty = false;
                form.addEventListener('input', function(){ window.__devisDirty = true; });
                form.addEventListener('change', function(){ window.__devisDirty = true; });
                window.addEventListener('beforeunload', function(e){
                    if (!window.__devisDirty) return;
                    e.preventDefault();
                    e.returnValue = '';
                });

                // Auto-save draft to localStorage (debounced 1500ms)
                var draftTimer = null;
                function collectDraft() {
                    var pieces = [];
                    document.querySelectorAll('.piece-card').forEach(function(card){
                        var nameInput = card.querySelector('input[name*="[nom]"]');
                        var items = [];
                        card.querySelectorAll('.pac-group').forEach(function(row){
                            var sel = row.querySelector('select[name="pac_ids[]"]');
                            items.push({
                                pac_id: sel ? sel.value : '',
                                libelle: (row.querySelector('input[name="libelles[]"]') || {}).value || '',
                                quantite: parseInt((row.querySelector('.pac-qty') || {}).value) || 1,
                                prix_ht: parseFloat((row.querySelector('.pac-price') || {}).value) || 0,
                                tva: rowTvaRate(row),
                                offert: isOffertRow(row)
                            });
                        });
                        pieces.push({ nom: nameInput ? nameInput.value : '', items: items });
                    });
                    return {
                        client_id: (document.getElementById('client_id') || {}).value || '',
                        description: (document.getElementById('description') || {}).value || '',
                        date_creation_date: (document.getElementById('date_creation_date') || {}).value || '',
                        date_echeance: (document.getElementById('date_echeance') || {}).value || '',
                        mode_paiement_1: (document.getElementById('mode_paiement_1') || {}).value || '',
                        bank_account_id_1: (document.getElementById('bank_account_id_1') || {}).value || '',
                        pieces: pieces
                    };
                }
                function saveDraft() {
                    clearTimeout(draftTimer);
                    draftTimer = setTimeout(function(){
                        try { localStorage.setItem('devis_draft_v1', JSON.stringify(collectDraft())); } catch(ex){}
                    }, 1500);
                }
                form.addEventListener('input', saveDraft);
                form.addEventListener('change', saveDraft);

                // Clear draft on reset
                var resetBtn = document.getElementById('btn-reset-devis');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function(){
                        try { localStorage.removeItem('devis_draft_v1'); } catch(ex){}
                    });
                }
            }
        });
    </script>
    <?php if ($prefill): ?>
    <script>window.__prefill = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;</script>
    <?php endif; ?>
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
    <div class="page-header">
      <div>
        <h1>Devis / Factures / BDC</h1>
        <div class="subtitle">Creation et gestion de vos documents commerciaux <small id="source-badge"></small></div>
      </div>
    </div>

    <?php if ($copyBanner): ?>
      <div class="info"><?= $copyBanner ?></div>
    <?php endif; ?>

    <!-- Toasts auto-triggered from URL params by inc/toast.js -->

    <!-- ============== 1) PRÉPARATION DU DEVIS ============== -->
    <section id="prep" class="card">
      <h2>Preparation du devis</h2>
      <p class="muted">Sélectionnez le client, ajoutez des pièces et des matériels, puis validez.</p>

      <form id="form-devis" method="POST" action="traitement_devis.php" target="_blank" class="stack" data-submit-once>
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
          <label for="description">Description de l'installation</label>
          <textarea id="description" name="description" rows="4" required></textarea>
        </div>

        <div class="pieces-toolbar">
          <button type="button" id="add-piece-btn" class="add-piece-btn">+ Ajouter une pièce</button>

          <!-- Raccourci : la grande majorité des devis est à un taux unique.
               Le taux reste modifiable ligne par ligne pour les devis mixtes. -->
          <div class="tva-bulk">
            <label for="tva-bulk-select">TVA de toutes les lignes</label>
            <select id="tva-bulk-select">
              <?php foreach (tva_taux_courants() as $t): ?>
                <option value="<?= htmlspecialchars((string)$t['taux'], ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($t['aide'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
              <option value="__autre">Autre…</option>
            </select>
            <input type="number" id="tva-bulk-custom" step="0.1" min="0" max="100"
                   placeholder="%" title="Taux personnalisé, en pourcentage" hidden>
            <button type="button" id="tva-bulk-apply" class="btn-outline">Appliquer</button>
          </div>

          <span class="muted">Astuce : les quantités laissées vides seront prises comme <strong>1</strong> à l'enregistrement.</span>
        </div>

        <div id="pieces-holder"><!-- pièces dynamiques --></div>

        <div class="grid-2-tight">
          <div>
            <label for="date_echeance">Date d'échéance</label>
            <input type="date" id="date_echeance" name="date_echeance" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
          </div>

          <div>
            <label>Paiement</label>
            <div id="pay-block" class="card">
              <div class="grid-3">
                <div>
                  <label for="mode_paiement_1">Mode de règlement</label>
                  <select id="mode_paiement_1" name="mode_paiement_1" required>
                    <option value="Chèque">Chèque</option>
                    <option value="Virement">Virement</option>
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

        <div id="devis-sticky-bar">
          <div id="total-container" aria-live="polite" aria-atomic="true">
            <span class="total-chip"><strong>Total HT :</strong> <span id="total">0.00 €</span></span>
            <span class="total-chip"><strong>Total TTC :</strong> <span id="total_ttc">0.00 €</span></span>
          </div>
          <div class="inline mt-2">
            <button type="submit" class="btn btn-save">Enregistrer le devis</button>
            <a id="btn-reset-devis" class="btn btn-secondary" href="devis.php">Réinitialiser</a>
          </div>
        </div>
      </form>
    </section>

    <!-- ============== 2) DOCUMENTS : recherche & 10 derniers ============== -->
    <section id="documents" class="card mt-3">
      <h2>Documents recents</h2>
      <p class="muted">Retrouvez rapidement les derniers devis, bons de commande et factures. Utilisez la recherche par client.</p>

      <form class="searchbar" method="get" action="#documents">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Recherche : nom, prenom ou telephone du client">
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" title="Date de debut">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" title="Date de fin">
        <button type="submit" class="btn btn-primary">Rechercher</button>
        <?php if ($q !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a class="btn btn-secondary" href="devis.php#documents">Reinitialiser</a><?php endif; ?>
      </form>
      <div class="inline" style="margin-bottom: var(--gap-3);">
        <a href="export_devis_csv.php?type=devis&q=<?= urlencode($q) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-secondary">Exporter devis CSV</a>
        <a href="export_devis_csv.php?type=bdc&q=<?= urlencode($q) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-secondary">Exporter BDC CSV</a>
        <a href="export_devis_csv.php?type=factures&q=<?= urlencode($q) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-secondary">Exporter factures CSV</a>
      </div>

      <div class="cards">
        <!-- Devis -->
        <div class="card">
          <h3>Devis <span class="pill"><?= $total_devis ?> total</span></h3>
          <div class="table-wrap">
            <table class="table-docs">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Client</th>
                  <th>Date</th>
                  <th>Montant TTC</th>
                  <th>PDF</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$devis_list): ?>
                <tr><td colspan="6" class="muted">Aucun devis trouvé.</td></tr>
              <?php else: foreach ($devis_list as $d): ?>
                <tr>
                  <td><?= htmlspecialchars($d['numero'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(trim(($d['client_prenom']??'').' '.($d['client_nom']??'')), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= fmt_date($d['date_doc'] ?? '') ?></td>
                  <td><?= fmt_eur($d['montant_ttc'] ?? '') ?></td>
                  <td>
                    <a class="btn btn-secondary"
                       href="voir_pdf.php?src=devis&id=<?= (int)$d['id'] ?>"
                       target="_blank" rel="noopener">Voir le PDF</a>
                  </td>
                  <td class="actions">
                    <a class="btn btn-warning" href="devis.php?copy_from_id=<?= (int)$d['id'] ?>#prep">Reprendre</a>
                    <a class="btn btn-primary" href="generer_bdc.php?id=<?= (int)$d['id'] ?>" target="_blank">BDC</a>
                    <a class="btn btn-success" href="generer_facture.php?src=devis&id=<?= (int)$d['id'] ?>" target="_blank">Facture</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?= pagination_html($page_devis, $pages_devis, 'page_devis', $q, $dateFrom, $dateTo) ?>
        </div>

        <!-- Bons de commande -->
        <div class="card">
          <h3>Bons de commande <span class="pill"><?= $total_bdc ?> total</span></h3>
          <div class="table-wrap">
            <table class="table-docs">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Client</th>
                  <th>Date</th>
                  <th>Montant TTC</th>
                  <th>PDF</th>
                  <th>Actions</th>
                </tr>
              </thead>
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
                    <a class="btn btn-success" href="generer_facture.php?src=bdc&id=<?= (int)$b['id'] ?>" target="_blank">Facture</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?= pagination_html($page_bdc, $pages_bdc, 'page_bdc', $q, $dateFrom, $dateTo) ?>
        </div>

        <!-- Factures -->
        <div class="card">
          <h3>Factures <span class="pill"><?= $total_fac ?> total</span></h3>
          <div class="table-wrap">
            <table class="table-docs">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Client</th>
                  <th>Date</th>
                  <th>Montant TTC</th>
                  <th>PDF</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$fac_list): ?>
                <tr><td colspan="5" class="muted">Aucune facture trouvée.</td></tr>
              <?php else: foreach ($fac_list as $f): ?>
                <tr>
                  <td><?= htmlspecialchars($f['numero'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(trim(($f['client_prenom']??'').' '.($f['client_nom']??'')), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= fmt_date($f['date_doc'] ?? '') ?></td>
                  <td><?= fmt_eur($f['montant_ttc'] ?? '') ?></td>
                  <td>
                    <a class="btn btn-secondary"
                       href="voir_pdf.php?src=facture&id=<?= (int)$f['id'] ?>"
                       target="_blank" rel="noopener">Voir le PDF</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <?= pagination_html($page_fac, $pages_fac, 'page_fac', $q, $dateFrom, $dateTo) ?>
        </div>
      </div>
    </section>

</div>

<script>
// Rafraîchissement après création (onglet PDF)
(function () {
  const KEY = 'devis_created';
  function reloadIfFlag() {
    try {
      if (localStorage.getItem(KEY) === '1') {
        localStorage.removeItem(KEY);
        location.reload();
      }
    } catch(e) {}
  }
  window.addEventListener('focus', reloadIfFlag);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) reloadIfFlag(); });
  window.addEventListener('storage', (e) => {
    if (e.key === KEY && e.newValue === '1') reloadIfFlag();
  });
})();
</script>

<?php require __DIR__ . '/inc/toast.php'; ?>
<?php require __DIR__ . '/inc/modal.php'; ?>
<script src="inc/submit-once.js"></script>
</body>
</html>
