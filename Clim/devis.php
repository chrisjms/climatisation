<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* ──────────── Paramètres recherche & limite ──────────── */
$q = trim($_GET['q'] ?? '');
$limit = 10;

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
function fetch_devis(PDO $pdo, string $q, int $limit): array {
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
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY d.`$dateCol` DESC, d.id DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_bdc(PDO $pdo, string $q, int $limit): array {
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
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY b.`$dateCol` DESC, b.id DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_factures(PDO $pdo, string $q, int $limit): array {
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
    if ($where) $sql .= " WHERE ".implode(' AND ', $where);
    $sql .= " ORDER BY f.`$dateCol` DESC, f.id DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ──────────── Helpers pour charger les lignes/PIÈCES ──────────── */
function fetchLinesFromDevisLignes(PDO $pdo, int $devisId): array {
    $sql = "
        SELECT pac_id, libelle, quantite, prix_unitaire AS prix_ht, tva_taux,
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
$devis_list = fetch_devis($pdo, $q, $limit);
$bdc_list   = fetch_bdc($pdo, $q, $limit);
$fac_list   = fetch_factures($pdo, $q, $limit);

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
            titleLabel.style.marginTop = '0';
            titleWrap.append(titleLabel, pieceNameInput);

            const actions = document.createElement('div');
            actions.className = 'piece-actions';

            const addMatBtn = document.createElement('button');
            addMatBtn.type = 'button';
            addMatBtn.className = 'btn-outline';
            addMatBtn.textContent = '➕ Ajouter un matériel';
            addMatBtn.onclick = () => addPacRow(card.querySelector('.lines-container'), pieceKey);

            const removePieceBtn = document.createElement('button');
            removePieceBtn.type = 'button';
            removePieceBtn.className = 'btn-danger';
            removePieceBtn.textContent = '🗑️ Supprimer la pièce';
            removePieceBtn.onclick = () => {
                const countRows = card.querySelectorAll('.pac-group').length;
                if (countRows > 0) {
                    if (!confirm('Supprimer cette pièce et tous ses matériels ?')) return;
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
            heads.innerHTML = '<span>Matériel (recherche / liste)</span><span>Nom sur devis</span><span>Quantité</span><span>Prix (€ HT)</span><span>TVA</span><span></span>';

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
                        (typeof it.tva !== 'undefined' ? Number(it.tva) : 20)
                    );
                });
            } else {
                // Une nouvelle pièce commence avec 1 ligne vide pour guider l'utilisateur
                addPacRow(linesContainer, pieceKey);
            }

            return card;
        }

        function addPacRow(containerEl, pieceKey, defaultId = '', defaultQty = null, defaultLabel = '', defaultPrice = '', defaultTva = 20) {
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
            listBtn.textContent = '📋 Liste';

            searchRow.append(search, listBtn);

            const sugg = document.createElement('div');
            sugg.className = 'pac-suggestions';

            const listAll = document.createElement('div');
            listAll.className = 'pac-list-all';

            const select = document.createElement('select');
            select.name = 'pac_ids[]';
            select.required = true;
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
            qty.required = true;
            qty.oninput = () => { updateTotal(); };
            qty.addEventListener('blur', () => { clampQty(qty); updateTotal(); });

            const prix = document.createElement('input');
            prix.type = 'number';
            prix.name = 'prix[]';
            prix.className = 'pac-price';
            prix.step = '0.01';
            prix.placeholder = 'Prix (€ HT)';
            prix.required = true;
            if (defaultPrice !== '') { prix.value = Number(defaultPrice).toFixed(2); }
            prix.oninput = updateTotal;

            const tva = document.createElement('select');
            tva.name = 'tva_taux[]';
            tva.className = 'pac-tva';
            // IMPORTANT : valeur 0 % = "0.0" (truthy en PHP), évite le fallback à 20 %
            [['20','20 %'], ['10','10 %'], ['0.0','0 %']].forEach(([val, label]) => {
                const o = new Option(label, val);
                tva.appendChild(o);
            });
            // Conserver les valeurs pré-remplies (0, 10, 20) en les adaptant
            const tvaVal = (Number(defaultTva) === 0 ? '0.0' : (Number(defaultTva) === 10 ? '10' : '20'));
            tva.value = tvaVal;
            tva.onchange = updateTotal;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'remove-btn';
            btn.textContent = '🗑';
            btn.title   = 'Retirer cette ligne';
            btn.onclick = () => { row.remove(); updateTotal(); };

            row.append(wrap, libelle, qty, prix, tva, btn);
            containerEl.appendChild(row);

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

                    const tvaV = parseFloat(row.querySelector('.pac-tva')?.value || '20');
                    const lineHT = round2((isNaN(prix) ? 0 : prix) * qty);

                    const rKey = String(isNaN(tvaV) ? 20 : tvaV);
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

        // Normalise les valeurs TVA à "0.0" avant envoi (évite le fallback PHP)
        function normalizeZeroTvaBeforeSubmit() {
            document.querySelectorAll('select.pac-tva').forEach(sel => {
                if (sel && (sel.value === '0' || sel.value === 0)) {
                    sel.value = '0.0';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const addBtn = document.getElementById('add-piece-btn');
            if (addBtn) addBtn.addEventListener('click', (e) => { e.preventDefault(); addPiece('Pièce'); });

            const prefill = window.__prefill || null;
            if (prefill) { prefillFromData(prefill); }
            else { addPiece('Salon'); }

            const inputDate = document.getElementById('date_creation_date');
            if (inputDate) { inputDate.addEventListener('change', syncDateCreation); syncDateCreation(); }

            const form = document.getElementById('form-devis');
            if (form) {
                form.addEventListener('submit', () => {
                    // 1) Sécuriser les quantités
                    document.querySelectorAll('.pac-qty').forEach(clampQty);
                    // 2) Supprimer les pièces vides
                    document.querySelectorAll('.piece-card').forEach(card => {
                        if (card.querySelectorAll('.pac-group').length === 0) {
                            card.remove();
                        }
                    });
                    // 3) Normaliser TVA 0 -> "0.0"
                    normalizeZeroTvaBeforeSubmit();
                    // 4) Sync date
                    syncDateCreation();
                    // 5) Recalculer et pousser le montant du paiement
                    const totals = updateTotal();
                    const m1 = document.getElementById('montant_paiement_1');
                    if (m1) m1.value = totals.totalTTC.toFixed(2);

                    try { localStorage.setItem('devis_created', '1'); } catch(e){}
                });
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

    <?php if (!empty($_GET['msg'])): ?>
      <div class="alert success"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
      <div class="alert error"><?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>

    <!-- ============== 1) PRÉPARATION DU DEVIS ============== -->
    <section id="prep" class="card">
      <h2>Preparation du devis</h2>
      <p class="muted">Sélectionnez le client, ajoutez des pièces et des matériels, puis validez.</p>

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
          <label for="description">Description de l'installation</label>
          <textarea id="description" name="description" rows="4" required></textarea>
        </div>

        <div class="pieces-toolbar">
          <button type="button" id="add-piece-btn" class="add-piece-btn">➕ Ajouter une pièce</button>
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
            <div id="pay-block" class="card" style="padding:var(--gap-3);">
              <div style="display:grid;grid-template-columns:1fr 160px 1fr;gap:var(--gap-2);align-items:end;">
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

        <div id="total-container">
          <span class="total-chip"><strong>Total HT :</strong> <span id="total">0.00 €</span></span>
          <span class="total-chip"><strong>Total TTC :</strong> <span id="total_ttc">0.00 €</span></span>
        </div>

        <div class="inline" style="margin-top:var(--gap-2);">
          <button type="submit" class="btn btn-save">Enregistrer le devis</button>
          <a class="btn btn-secondary" href="devis.php">Réinitialiser</a>
        </div>
      </form>
    </section>

    <!-- ============== 2) DOCUMENTS : recherche & 10 derniers ============== -->
    <section id="documents" class="card" style="margin-top:var(--gap-3);">
      <h2>Documents recents</h2>
      <p class="muted">Retrouvez rapidement les derniers devis, bons de commande et factures. Utilisez la recherche par client.</p>

      <form class="searchbar" method="get" action="#documents">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Recherche : nom, prénom ou téléphone du client">
        <button type="submit" class="btn btn-primary">Rechercher</button>
        <?php if ($q !== ''): ?><a class="btn btn-secondary" href="devis.php#documents">Réinitialiser</a><?php endif; ?>
      </form>

      <div class="cards">
        <!-- Devis -->
        <div class="card">
          <h3>Devis <span class="pill">10 derniers</span> <span class="pill">Résultats: <?= (int)$cnt_devis ?></span></h3>
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
        </div>

        <!-- Bons de commande -->
        <div class="card">
          <h3>Bons de commande <span class="pill">10 derniers</span> <span class="pill">Résultats: <?= (int)$cnt_bdc ?></span></h3>
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
        </div>

        <!-- Factures -->
        <div class="card">
          <h3>Factures <span class="pill">10 dernières</span> <span class="pill">Résultats: <?= (int)$cnt_fac ?></span></h3>
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

</body>
</html>
