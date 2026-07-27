<?php
/***************************************************************
 * generer_bdc.php — Bon de Commande au format "comme les devis"
 * - Rendu PDF aligné sur traitement_devis.php (logo, en-tête, tableau…)
 * - Détection auto des lignes (tables/colonnes variables + fallback JSON)
 * - Gestion des pièces (piece_key/piece_nom) + fallback si manquantes
 * - Acompte (%) ou (€) + calcul Reste à payer
 * - Sauvegarde en bdd (table bons_de_commande) + PDF dans /bdc_pdf
 * - ⚠️ Sous-totaux PAR PIÈCE retirés (affichage)
 ***************************************************************/
require 'auth.php';
require 'config.php';
require __DIR__ . '/inc/helpers.php';

define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
$tfpdfPath = __DIR__ . '/tfpdf/tfpdf.php';
if (!is_file($tfpdfPath)) { http_response_code(500); exit("Bibliothèque tFPDF introuvable à $tfpdfPath"); }
require $tfpdfPath;

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function abortX($code, $msg) { http_response_code($code); exit($msg); }

/* ────────── Outils DB ────────── */
function tableExists(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0"); return true; } catch(Throwable $e){ return false; }
}
function showColumns(PDO $pdo, string $table): array {
    try { $st = $pdo->query("SHOW COLUMNS FROM `{$table}`"); return array_column($st->fetchAll(PDO::FETCH_ASSOC),'Field'); }
    catch(Throwable $e){ return []; }
}
function firstExisting(array $pool, array $cols) {
    foreach ($pool as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

/* ────────── Devis + Client ────────── */
$devisId = isset($_REQUEST['id']) && ctype_digit((string)$_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
if ($devisId <= 0) abortX(400, 'Devis ID manquant.');

function fetchDevis(PDO $pdo, int $id): ?array {
    $sql = "SELECT d.*, c.*,
                   d.bank_account_id AS devis_bank_id,
                   ba.nom_du_compte, ba.titulaire, ba.banque, ba.iban, ba.bic
              FROM devis d
              JOIN clients c ON c.id = d.client_id
         LEFT JOIN bank_accounts ba ON ba.id = d.bank_account_id
             WHERE d.id = :id
             LIMIT 1";
    $st  = $pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
try { $devis = fetchDevis($pdo, $devisId); } catch(Throwable $e){ abortX(500, "Erreur lors du chargement du devis (#$devisId)."); }
if (!$devis) abortX(404, "Devis introuvable (#$devisId).");

/* ────────── Récupération des lignes : stratégies multiples ────────── */
function mapRows(array $rows): array {
    // Normalise + conserve pièce si fournie — NE PAS écraser TVA 0 %
    return array_values(array_map(function($r){
        $lib = trim((string)($r['libelle'] ?? ''));
        $desc= trim((string)($r['desc'] ?? ''));
        $q   = (int)($r['quantite'] ?? 0);
        $pu  = (float)($r['prix_ht'] ?? 0);
        // tva: garder 0 si fourni
        $tva = array_key_exists('tva',$r) ? (float)$r['tva'] : 20.0;

        $pkey= (string)($r['piece_key'] ?? '');
        $pnom= (string)($r['piece_nom'] ?? '');
        return [
            'libelle'   => $lib,
            'desc'      => $desc,
            'quantite'  => $q,
            'prix_ht'   => $pu,
            'tva'       => $tva,
            'piece_key' => $pkey,
            'piece_nom' => $pnom,
        ];
    }, $rows));
}

function smartFetchFromTable(PDO $pdo, string $table, int $devisId): array {
    $cols = showColumns($pdo, $table);
    if (!$cols) return [];

    $idCol = firstExisting(['devis_id','id_devis','devisId','idDevis'], $cols);
    if (!$idCol) return [];

    $cLib  = firstExisting(['libelle','intitule','designation','nom','label','title'], $cols);
    $cDesc = firstExisting(['description','desc','details','commentaire','texte'], $cols);
    $cQte  = firstExisting(['quantite','qte','qty','quantity'], $cols);
    $cPU   = firstExisting(['prix_unitaire','pu_ht','unit_price','price_ht','prixht','prix'], $cols);
    $cTVA  = firstExisting(['tva_taux','taux_tva','tva','vat','tax_rate','tax'], $cols);

    $cPKey = in_array('piece_key',$cols,true) ? 'piece_key' : null;
    $cPNom = in_array('piece_nom',$cols,true) ? 'piece_nom' : null;

    if (!$cLib && !$cPU && !$cQte) return [];

    $sel = [];
    if ($cLib)  $sel[] = "`$cLib`   AS libelle";
    if ($cDesc) $sel[] = "`$cDesc`  AS `desc`";
    if ($cQte)  $sel[] = "`$cQte`   AS quantite";
    if ($cPU)   $sel[] = "`$cPU`    AS prix_ht";
    if ($cTVA)  $sel[] = "`$cTVA`   AS tva";
    if ($cPKey) $sel[] = "`$cPKey`  AS piece_key";
    if ($cPNom) $sel[] = "`$cPNom`  AS piece_nom";

    $orderCol = firstExisting(['ordre','position','rang','id'], $cols);
    $orderSql = $orderCol ? " ORDER BY `$orderCol`" : "";

    $sql = "SELECT ".implode(', ', $sel)." FROM `$table` WHERE `$idCol` = :id".$orderSql;
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$devisId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return mapRows($rows);
}

function fetchFromJSONDevis(array $devis): array {
    foreach ($devis as $k => $v) {
        if (!is_string($v)) continue;
        if (strpos($v,'{') === false && strpos($v,'[') === false) continue;
        $data = json_decode($v, true);
        if (!is_array($data)) continue;

        $items = (array)(isAssoc($data) ? [$data] : $data);
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $lib = firstKey($item, ['libelle','intitule','designation','nom','label','title','name']);
            $qte = firstKey($item, ['quantite','qte','qty','quantity']);
            $pu  = firstKey($item, ['prix_ht','pu_ht','unit_price','price_ht','prix','pu']);
            $tva = firstKey($item, ['tva','tva_taux','taux_tva','vat','tax_rate','tax']);
            $desc= firstKey($item, ['description','desc','details','commentaire','texte']);
            $pkey= firstKey($item, ['piece_key']);
            $pnom= firstKey($item, ['piece_nom']);
            if ($lib!==null || $pu!==null || $qte!==null) {
                $mapped[] = [
                    'libelle'   => (string)($item[$lib]  ?? ''),
                    'desc'      => (string)($item[$desc] ?? ''),
                    'quantite'  => (int)($item[$qte]    ?? 1),
                    'prix_ht'   => (float)($item[$pu]   ?? 0),
                    // garder 0 si fourni
                    'tva'       => array_key_exists($tva ?? '', $item) ? (float)$item[$tva] : 20.0,
                    'piece_key' => (string)($item[$pkey] ?? ''),
                    'piece_nom' => (string)($item[$pnom] ?? ''),
                ];
            }
        }
        if ($mapped) return mapRows($mapped);
    }
    return [];
}
function isAssoc(array $a){ return array_keys($a)!==range(0,count($a)-1); }
function firstKey(array $arr, array $keys){ foreach($keys as $k) if(array_key_exists($k,$arr)) return $k; return null; }

/* ==== Fallback pièces : enrichit $lines si piece_nom/piece_key manquent ==== */
function enrichPiecesFromDB(PDO $pdo, int $devisId, array $lines): array {
    // Si au moins une pièce est déjà renseignée, on garde.
    foreach ($lines as $r) { if (!empty($r['piece_nom']) || !empty($r['piece_key'])) { return $lines; } }

    // 1) devis_lignes avec colonnes piece_*
    if (tableExists($pdo, 'devis_lignes')) {
        $cols = showColumns($pdo, 'devis_lignes');
        $cPKey = in_array('piece_key', $cols, true) ? 'piece_key' : null;
        $cPNom = in_array('piece_nom', $cols, true) ? 'piece_nom' : null;

        if ($cPKey || $cPNom) {
            $sql = "SELECT ".($cPKey ? "`$cPKey` AS piece_key, " : "'' AS piece_key, ")
                 . ($cPNom ? "`$cPNom` AS piece_nom " : "'' AS piece_nom ")
                 . "FROM devis_lignes WHERE devis_id = :id ORDER BY id";
            $st = $pdo->prepare($sql);
            $st->execute([':id'=>$devisId]);
            $dl = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // cas 1: même nombre de lignes → mappage par index
            if (count($dl) === count($lines) && count($dl) > 0) {
                foreach ($lines as $i => &$ln) {
                    $ln['piece_key'] = (string)($dl[$i]['piece_key'] ?? ($ln['piece_key'] ?? ''));
                    $ln['piece_nom'] = (string)($dl[$i]['piece_nom'] ?? ($ln['piece_nom'] ?? ''));
                }
                unset($ln);
                return $lines;
            }

            // cas 2: un seul nom de pièce distinct → appliquer partout
            $names = array_values(array_unique(array_map(fn($r)=>trim((string)($r['piece_nom'] ?? '')),$dl)));
            $names = array_filter($names, fn($v)=>$v!=='');
            if (count($names) === 1) {
                $unique = $names[0];
                foreach ($lines as &$ln) { if (empty($ln['piece_nom'])) $ln['piece_nom'] = $unique; }
                unset($ln);
                return $lines;
            }
        }
    }

    // 2) Table devis_pieces (devis_id, piece_key, piece_nom)
    if (tableExists($pdo, 'devis_pieces')) {
        try {
            $st = $pdo->prepare("SELECT piece_key, piece_nom FROM devis_pieces WHERE devis_id = :id ORDER BY id");
            $st->execute([':id' => $devisId]);
            $pcs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($pcs) {
                $fallback = trim((string)($pcs[0]['piece_nom'] ?? ''));
                if ($fallback !== '') {
                    foreach ($lines as &$ln) { if (empty($ln['piece_nom'])) $ln['piece_nom'] = $fallback; }
                    unset($ln);
                    return $lines;
                }
            }
        } catch(Throwable $e) { /* silencieux */ }
    }

    // 3) Rien trouvé : on laissera "Sans pièce"
    return $lines;
}

/* Stratégies successives pour charger les lignes */
$lines = [];
if (tableExists($pdo, 'devis_lignes')) {
    $lines = smartFetchFromTable($pdo, 'devis_lignes', $devisId);
}
if (!$lines && tableExists($pdo, 'devis_items')) {
    $lines = smartFetchFromTable($pdo, 'devis_items', $devisId);
}
$altTables = ['devis_articles','devis_details','devis_pac','devis_lignes_items'];
foreach ($altTables as $t) {
    if ($lines) break;
    if (tableExists($pdo, $t)) {
        $lines = smartFetchFromTable($pdo, $t, $devisId);
    }
}
if (!$lines) {
    $lines = fetchFromJSONDevis($devis);
}
if (empty($lines)) abortX(500, "Aucune ligne trouvée pour ce devis.");

/* ────────── Enrichissement pièces si manquantes ────────── */
$lines = enrichPiecesFromDB($pdo, $devisId, $lines);

/* ────────── Helpers arrondis + normalisation TVA ────────── */
function round2($n){ return round((float)$n + 1e-12, 2); }
function normRate($r){
    $r = is_numeric($r) ? (float)$r : 20.0;
    if (abs($r-0.0)<0.001)  return 0.0;
    if (abs($r-10.0)<0.001) return 10.0;
    return 20.0;
}

/* ────────── Totaux + groupement par pièce (arrondi par ligne) ────────── */
$DEFAULT_PIECE_KEY  = '_global';
$DEFAULT_PIECE_NAME = 'Sans pièce';

$total_ht = 0.0; $total_tva = 0.0; $total_ttc = 0.0;
$pieceOrder = []; $pieceTotals = []; $grouped = [];
$htByRate = []; // clé "0", "10", "20"

foreach ($lines as &$ln) {
    $qty = (int)($ln['quantite'] ?? 0);
    if ($qty <= 0) $qty = 1;
    $pu  = (float)($ln['prix_ht'] ?? 0);
    $tva = normRate($ln['tva'] ?? 20.0);

    $lht  = round2($qty * $pu);
    $ltva = round2($lht * ($tva/100.0));
    $lttc = round2($lht + $ltva);

    $ln['tva'] = $tva;
    $ln['total_ht']  = $lht;
    $ln['total_ttc'] = $lttc;

    $pKey = trim((string)($ln['piece_key'] ?? '')); if ($pKey==='') $pKey = $DEFAULT_PIECE_KEY;
    $pNom = trim((string)($ln['piece_nom'] ?? '')); if ($pNom==='') $pNom = $DEFAULT_PIECE_NAME;

    if (!array_key_exists($pKey,$pieceOrder)) $pieceOrder[$pKey] = count($pieceOrder);
    if (!isset($pieceTotals[$pKey])) $pieceTotals[$pKey] = ['nom'=>$pNom,'ht'=>0.0,'ttc'=>0.0];

    $pieceTotals[$pKey]['ht']  = round2($pieceTotals[$pKey]['ht']  + $lht);
    $pieceTotals[$pKey]['ttc'] = round2($pieceTotals[$pKey]['ttc'] + $lttc);
    $grouped[$pKey][] = $ln;

    $total_ht = round2($total_ht + $lht);

    $rk = (string)$tva;
    $htByRate[$rk] = round2(($htByRate[$rk] ?? 0.0) + $lht);
}
unset($ln);

// Calcul TTC global par regroupement de taux (aligné devis)
$total_ttc = 0.0;
foreach ($htByRate as $rateStr => $ht) {
    $rate = (float)$rateStr;
    $total_ttc = round2($total_ttc + round2($ht * (1 + ($rate/100.0))));
}
$total_tva = round2($total_ttc - $total_ht);

/* ────────── Table BDC + numérotation ────────── */
function ensureBdcTable(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bons_de_commande (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(50) NOT NULL UNIQUE,
            devis_id INT UNSIGNED NOT NULL,
            date_creation DATETIME NOT NULL,
            acompte_type ENUM('percent','amount') NOT NULL,
            acompte_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            acompte_montant DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            montant_ttc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            fichier_pdf VARCHAR(255) DEFAULT NULL,
            INDEX(devis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
function nextBdcNumber(PDO $pdo): string {
    $year = date('Y');
    $q = $pdo->prepare("SELECT numero FROM bons_de_commande WHERE numero LIKE :pfx ORDER BY id DESC LIMIT 1");
    $q->execute([':pfx' => "BDC-$year-%"]);
    $last = $q->fetchColumn(); $seq = 0;
    if ($last && preg_match('~^BDC-'.$year.'-(\d{4})$~', $last, $m)) $seq = (int)$m[1];
    return sprintf('BDC-%s-%04d', $year, $seq+1);
}

/* ────────── Flux : GET (form acompte) / POST (génère PDF) ────────── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) abortX(400, 'CSRF token invalide.');
    $atype = ($_POST['acompte_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
    $aval  = (float) str_replace(',', '.', (string)($_POST['acompte_value'] ?? '0'));

    // bornes en fonction du type, basées sur le TTC calculé ci-dessus
    if ($atype === 'percent') {
        $aval = max(0, min(100, $aval));
    } else {
        $aval = max(0, min($total_ttc, $aval));
    }

    $acompte_montant = ($atype==='percent') ? round2($total_ttc * ($aval/100.0)) : round2($aval);
    $reste = max(0, round2($total_ttc - $acompte_montant));

    try { ensureBdcTable($pdo); } catch(Throwable $e){ abortX(500, "Impossible de préparer la table des BDC."); }
    try {
        $numero = nextBdcNumber($pdo);
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare("
            INSERT INTO bons_de_commande
            (numero, devis_id, date_creation, acompte_type, acompte_value, acompte_montant, montant_ttc, fichier_pdf)
            VALUES (:numero, :devis_id, :dt, :at, :av, :am, :ttc, NULL)
        ");
        $ins->execute([
            ':numero'=>$numero, ':devis_id'=>$devisId, ':dt'=>$now,
            ':at'=>$atype, ':av'=>$aval, ':am'=>$acompte_montant, ':ttc'=>$total_ttc
        ]);
        $bdcId = (int)$pdo->lastInsertId();
    } catch(Throwable $e){
        if (is_duplicate_key_error($e)) {
            abortX(409, "Conflit de numérotation BDC. Veuillez réessayer.");
        }
        error_log('[generer_bdc] '.$e->getMessage());
        abortX(500, "Enregistrement BDC impossible.");
    }

    /* ───── Vérification polices (comme devis) ───── */
    $fontDir = __DIR__ . '/tfpdf/font';
    $ttfReg  = $fontDir . '/DejaVuSansCondensed.ttf';
    $ttfBold = $fontDir . '/DejaVuSansCondensed-Bold.ttf';
    if (!is_file($ttfReg) || !is_file($ttfBold)) {
        http_response_code(500);
        exit("Police DejaVu manquante. Ajoutez les fichiers :
- tfpdf/font/DejaVuSansCondensed.ttf
- tfpdf/font/DejaVuSansCondensed-Bold.ttf");
    }

    /* ───── Classe PDF alignée sur traitement_devis.php ───── */
    class PDF_Doc extends tFPDF {
        protected $banks;
        protected $payments;
        protected $headerMeta;
        protected $docTitle;

        public function __construct($docTitle, $banks = null, $payments = null, $headerMeta = null) {
            parent::__construct();
            $this->docTitle   = $docTitle;
            $this->banks      = $banks;
            $this->payments   = $payments;
            $this->headerMeta = $headerMeta;
            $this->SetMargins(10, 15, 10);
            $this->SetAutoPageBreak(true, 60);
        }

        function Header() {
            // Logo (si présent)
            $logo = __DIR__ . '/assets/logo.jpeg';
            if (file_exists($logo)) $this->Image($logo, 10, 8, 30);

            // Bloc société (centré)
            $this->SetXY(10, 10);
            $this->SetFont('DejaVu','',12);
            $this->Cell(0,6,'COPROVEN',0,1,'C');

            $this->SetFont('DejaVu','',8);
            $this->Cell(0,5,'Électricité – Chauffage – Climatisation',0,1,'C');
            $this->Cell(0,5,'Chauffe-eau Solaire – Photovoltaïques',0,1,'C');
            $this->Ln(2);
            $this->SetFont('DejaVu','',7);
            $this->Cell(0,5,'6 allée des Dunes – 33470 Gujan-Mestras',0,1,'C');
            $this->Cell(0,5,'Tél. : 06 37 05 35 22 – coproven.climatisation@gmail.com',0,1,'C');

            // Colonne droite : Titre + Numéro + Date
            $rightColW = 60;
            $rightColX = $this->w - $this->rMargin - $rightColW;

            // Titre (DEVIS / BON DE COMMANDE)
            $this->SetFont('DejaVu','',14);
            $this->SetXY($rightColX, 12);
            $this->Cell($rightColW, 8, $this->docTitle, 0, 1, 'R');

            $yAfterHeader = $this->GetY();
            // Numéro document
            if (!empty($this->headerMeta['numero_doc'])) {
                $this->SetFont('DejaVu','',10);
                $this->SetXY($rightColX, 20);
                $this->Cell($rightColW, 6, 'N° '.$this->headerMeta['numero_doc'], 0, 1, 'R');
                $yAfterHeader = max($yAfterHeader, $this->GetY());
            }
            // Date (doc)
            if (!empty($this->headerMeta['date_doc'])) {
                $dcTxt = date('d/m/Y', strtotime($this->headerMeta['date_doc']));
                $this->SetFont('DejaVu','',9);
                $this->SetXY($rightColX, $this->GetY());
                $this->Cell($rightColW, 5, 'Date : '.$dcTxt, 0, 1, 'R');
                $yAfterHeader = max($yAfterHeader, $this->GetY());
            }

            // Bloc infos client (à droite)
            $rightW = 90;
            $this->SetFont('DejaVu','',7);
            $yClientStart = max(28, $yAfterHeader + 4);
            $this->SetXY($this->w - $this->rMargin - $rightW, $yClientStart);

            if (is_array($this->headerMeta)) {
                $lines = [];
                if (!empty($this->headerMeta['client_nom']))      $lines[] = "Client : ".$this->headerMeta['client_nom'];
                if (!empty($this->headerMeta['client_adresse']))  $lines[] = "Adresse : ".$this->headerMeta['client_adresse'];
                $cpVille = trim(trim(($this->headerMeta['client_cp'] ?? '')) . ' ' . trim(($this->headerMeta['client_ville'] ?? '')));
                if ($cpVille !== '') $lines[] = "Code postal / Ville : ".$cpVille;
                if (!empty($this->headerMeta['client_tel']))      $lines[] = "Tél. : ".$this->headerMeta['client_tel'];
                if (!empty($this->headerMeta['client_email']))    $lines[] = "Email : ".$this->headerMeta['client_email'];

                $txt = implode("\n", $lines);
                if ($txt !== '') $this->MultiCell($rightW, 5, $txt, 0, 'R');
            }
            $startY = max($this->GetY() + 4, 48);
            $this->SetY($startY);
        }

        function Footer() {
            // Paiement / RIB / Mentions identiques aux devis
            $this->SetFont('DejaVu','',6);
            $this->SetY(-35);
            $rightBoxW = 90;
            $this->SetX($this->w - $this->rMargin - $rightBoxW);
            if (is_array($this->payments) && count($this->payments) > 0) {
                $p = $this->payments[0];
                $txt = "{$p['label']} : ".number_format((float)$p['montant'],2,',',' ')." €\n";
                $this->MultiCell($rightBoxW, 4, $txt, 0, 'R');
            }

            // Signature
            $this->SetY(-28);
            $this->SetX($this->lMargin);
            $this->SetFont('DejaVu','',7);
            $this->Cell(0,5,"Bon pour accord ..............., le ....... à .................",0,1,'L');
            $this->Cell(0,5,"Signature",0,1,'L');
            $this->Ln(1);

            // RIB (si présent)
            if (is_array($this->banks)) {
                $this->SetFont('DejaVu','',6);
                foreach ($this->banks as $b) {
                    if (!$b) continue;
                    $rib = "RIB : IBAN {$b['iban']}  •  BIC {$b['bic']}  •  Banque {$b['banque']}  •  Titulaire {$b['titulaire']}";
                    $this->SetTextColor(200, 0, 0);
                    $this->Cell(0,5,$rib,0,1,'L');
                    $this->SetTextColor(0, 0, 0);
                }
            }

            // Mentions
            $this->SetFont('DejaVu','',5);
            $txt = "Escompte pour règlement anticipé : 0 %\n"
                 . "En cas de retard de paiement, pénalité = 3 × taux légal\n"
                 . "(Décret 2009-138 du 9 février 2009)";
            $this->MultiCell(0,4,$txt,0,'L');

            // ── IDENTIFIANTS LÉGAUX EN BAS À DROITE (chaque page) ──
            $this->SetFont('DejaVu','',6);
            $this->SetY(-10); // ~10 mm du bas
            $this->SetX($this->lMargin);
            $this->Cell(
                0,
                5,
                'Siret : 91531673100016 - RM : 915316731 - N° TVA intracom : FR00915316731',
                0,
                0,
                'R'
            );
        }

        function TableHeader() {
            $this->SetFont('DejaVu','',8);
            $this->SetFillColor(230,230,230);
            $this->Cell(70,7,'Description',1,0,'C',true);
            $this->Cell(20,7,'Qté',1,0,'C',true);
            $this->Cell(30,7,'PU HT',1,0,'C',true);
            $this->Cell(20,7,'TVA',1,0,'C',true);
            $this->Cell(30,7,'TTC',1,1,'C',true);
        }

        function SectionTitle($title) {
            $h = 8; $w = 70+20+30+20+30; // 170
            if ($this->GetY() + $h > ($this->h - $this->bMargin)) $this->AddPage();
            $this->SetFont('DejaVu','B',10);
            $this->SetFillColor(240,240,240);
            $this->Cell($w, $h, (string)$title, 1, 1, 'L', true);
        }

        function WrapText($w, $txt, $family='DejaVu', $style='B', $size=8) {
            $fam=$this->FontFamily; $sty=$this->FontStyle; $sz=$this->FontSizePt;
            $this->SetFont($family,$style,$size);
            $res=[]; $line='';
            $words=preg_split('/\s+/u',(string)$txt,-1,PREG_SPLIT_NO_EMPTY);
            foreach($words as $word){
                $test=trim($line==='' ? $word : "$line $word");
                if ($this->GetStringWidth($test) <= $w) $line=$test;
                else {
                    if ($line!=='') $res[]=$line;
                    if ($this->GetStringWidth($word) > $w) {
                        $buf='';
                        $len=mb_strlen($word,'UTF-8');
                        for($i=0;$i<$len;$i++){
                            $c=mb_substr($word,$i,1,'UTF-8');
                            if ($this->GetStringWidth($buf.$c) <= $w) $buf.=$c;
                            else { $res[]=$buf; $buf=$c; }
                        }
                        $line=$buf;
                    } else { $line=$word; }
                }
            }
            if ($line!=='') $res[]=$line;
            $this->SetFont($fam,$sty,$sz);
            return $res;
        }

        function RowDescription($name, $desc, $qty, $pu, $tva, $ttc) {
            $x0 = $this->GetX(); $y0 = $this->GetY();
            $wDesc = 70; $wQty = 20; $wPU = 30; $wTVA = 20; $wTTC = 30; $pad = 2;
            $wDescIn = $wDesc - 2*$pad;

            $nameChunks = $this->WrapText($wDescIn, (string)$name, 'DejaVu','B',8);
            $descChunks = $this->WrapText($wDescIn, (string)$desc, 'DejaVu','',7);

            $hName = 4 * max(1,count($nameChunks));
            $hDesc = 4 * max(1,count($descChunks));
            $hRow  = max($hName + $hDesc + 2*$pad, 7);

            if ($y0 + $hRow > ($this->h - $this->bMargin)) {
                $this->AddPage(); $this->TableHeader();
                $x0 = $this->GetX(); $y0 = $this->GetY();
            }

            $this->Rect($x0,                             $y0, $wDesc, $hRow);
            $this->Rect($x0 + $wDesc,                    $y0, $wQty,  $hRow);
            $this->Rect($x0 + $wDesc + $wQty,            $y0, $wPU,   $hRow);
            $this->Rect($x0 + $wDesc + $wQty + $wPU,     $y0, $wTVA,  $hRow);
            $this->Rect($x0 + $wDesc + $wQty + $wPU + $wTVA, $y0, $wTTC, $hRow);

            $this->SetXY($x0 + $pad, $y0 + $pad);
            $this->SetFont('DejaVu','B',8);
            foreach ($nameChunks as $ln) { $this->Cell($wDescIn, 4, $ln, 0, 1, 'L'); $this->SetX($x0 + $pad); }
            $this->SetFont('DejaVu','',7);
            foreach ($descChunks as $ln) { $this->Cell($wDescIn, 4, $ln, 0, 1, 'L'); $this->SetX($x0 + $pad); }

            $this->SetFont('DejaVu','',8);
            $this->SetXY($x0 + $wDesc, $y0);
            $this->Cell($wQty, $hRow, (string)$qty, 0, 0, 'C');
            $this->Cell($wPU,  $hRow, number_format((float)$pu,  2, ',', ' ').' €', 0, 0, 'R');
            $this->Cell($wTVA, $hRow, number_format((float)$tva, 0, ',', ' ').' %', 0, 0, 'C');
            $this->Cell($wTTC, $hRow, number_format((float)$ttc, 2, ',', ' ').' €', 0, 1, 'R');

            $this->SetXY($x0, $y0 + $hRow);
        }
    }

    /* ───── Données pour l'entête/footers ───── */
    $clientFull = trim(($devis['prenom'] ?? '').' '.($devis['nom'] ?? ''));
    $adr1  = (string)($devis['adresse'] ?? $devis['adresse1'] ?? $devis['adresse_ligne1'] ?? '');
    $cp    = (string)($devis['code_postal'] ?? $devis['cp'] ?? '');
    $ville = (string)($devis['ville'] ?? '');

    $banks = [];
    if (!empty($devis['iban']) && !empty($devis['bic'])) {
        $banks[] = [
            'iban'=>$devis['iban'],
            'bic'=>$devis['bic'],
            'banque'=>$devis['banque'] ?? '',
            'titulaire'=>$devis['titulaire'] ?? '',
        ];
    }
    // Footer : on affiche l'info d'acompte demandé
    $payments = [['label'=>'Acompte demandé','montant'=>$acompte_montant]];

    $headerMeta = [
        'client_nom'     => $clientFull,
        'client_adresse' => $adr1,
        'client_cp'      => $cp,
        'client_ville'   => $ville,
        'client_tel'     => (string)($devis['telephone'] ?? ''),
        'client_email'   => (string)($devis['email'] ?? ''),
        'numero_doc'     => $numero,
        'date_doc'       => date('Y-m-d'),
    ];

    /* ───── Génération PDF (même rendu que devis) ───── */
    $pdf = new PDF_Doc('BON DE COMMANDE', $banks, $payments, $headerMeta);
    $pdf->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf',      true);
    $pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);
    $pdf->SetFont('DejaVu','',9);
    $pdf->AddPage();
    if ($pdf->GetY() < 48) { $pdf->SetY(48); }

    // Bloc "référence devis"
    $dv = $devis['numero'] ?? ('#'.$devisId);
    $dateDev = isset($devis['date_creation']) ? date('d/m/Y', strtotime($devis['date_creation'])) : date('d/m/Y');
    $pdf->SetFont('DejaVu','',8);
    $pdf->Cell(0,6,"Référence devis : $dv — Date : $dateDev",0,1,'L');

    // Description d'installation (si dispo)
    if (!empty($devis['description'])) {
        $pdf->SetFont('DejaVu','',9);
        $pdf->Cell(0,6,"Description de l'installation :",0,1);
        $pdf->SetFont('DejaVu','B', 8);
        $pdf->MultiCell(0,6,(string)$devis['description']);
        $pdf->Ln(2);
    }

    // Impression par pièce (⚠️ sans sous-totaux de pièce)
    $wTable = 70+20+30+20+30;
    $orderedKeys = array_keys($pieceOrder);
    if (!$orderedKeys) $orderedKeys = array_keys($grouped);

    foreach ($orderedKeys as $k) {
        $pieceName = ($pieceTotals[$k]['nom'] ?? $DEFAULT_PIECE_NAME);
        $pdf->SectionTitle($pieceName);
        $pdf->TableHeader();

        foreach ($grouped[$k] as $m) {
            $pdf->RowDescription(
                $m['libelle'],
                $m['desc'] ?? '',
                $m['quantite'],
                $m['prix_ht'],
                $m['tva'],
                $m['total_ttc']
            );
        }

        // (Sous-totaux par pièce retirés)
        $pdf->Ln(4);
    }

    // Totaux globaux + acompte/reste
    $pdf->Ln(2);
    $pdf->Cell(0, 0, '', 'T');
    $pdf->Ln(2);
    $row = function($label, $value) use ($pdf){
        $pdf->SetFont('DejaVu', '', 8);
        $pdf->Cell(140,6,$label,0,0,'R');
        $pdf->Cell(30,6,number_format($value,2,',',' ').' €',0,1,'R');
    };
    $row('Total HT',  $total_ht);
    $row('Total TVA', $total_tva);
    $row('Total TTC', $total_ttc);

    $labelA = ($atype==='percent') ? ('Acompte demandé ('.number_format($aval,2,',',' ').' %)') : 'Acompte demandé';
    $row($labelA, $acompte_montant);
    $row('Reste à payer', $reste);

    // Mentions spécifiques BDC
    $pdf->Ln(4);
    $pdf->SetFont('DejaVu','',7);
    $pdf->MultiCell(0,5,
        "Modalités : acompte à la commande, solde à l'installation.\n".
        "Le présent bon de commande vaut engagement ferme. Délai de rétractation selon dispositions légales en vigueur."
    );

    // Sauvegarde
    $dir = __DIR__ . '/bdc_pdf';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $fileName = $numero.'.pdf'; $fsPath = $dir.'/'.$fileName;
    $pdf->Output('F', $fsPath);

    $upd = $pdo->prepare("UPDATE bons_de_commande SET fichier_pdf = :f WHERE id = :id");
    $upd->execute([':f'=>'bdc_pdf/'.$fileName, ':id'=>$bdcId]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.$fileName.'"');
    readfile($fsPath);
    exit;
}

/* ────────── GET : formulaire acompte (simple) ────────── */
$clientFull = trim(($devis['prenom'] ?? '').' '.($devis['nom'] ?? ''));
$adr1  = (string)($devis['adresse'] ?? $devis['adresse1'] ?? $devis['adresse_ligne1'] ?? '');
$cp    = (string)($devis['code_postal'] ?? $devis['cp'] ?? '');
$ville = (string)($devis['ville'] ?? '');

// NOTE: on expose le TTC calculé après normalisation/arrondis (0/10/20 % supporté)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Générer un Bon de Commande</title>
<script>
function fmt(n){ return (Math.round(n*100)/100).toFixed(2); }
function recompute(){
  const totalTTC = parseFloat(document.getElementById('total_ttc').dataset.raw || '0');
  const type = document.getElementById('acompte_type').value;
  const val  = parseFloat((document.getElementById('acompte_value').value || '0').replace(',','.'));
  let acompte = 0;
  if (type === 'percent') { let p = Math.max(0, Math.min(100, isNaN(val)?0:val)); acompte = totalTTC * (p/100.0); }
  else { acompte = Math.max(0, Math.min(totalTTC, isNaN(val)?0:val)); }
  const reste = Math.max(0, totalTTC - acompte);
  document.getElementById('acompte_calc').textContent = fmt(acompte)+' €';
  document.getElementById('reste_calc').textContent   = fmt(reste)+' €';
}
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('acompte_type').addEventListener('change', recompute);
  document.getElementById('acompte_value').addEventListener('input', recompute);
  recompute();
});
</script>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="card">
  <h2>Bon de Commande — <small>depuis le devis <?= htmlspecialchars($devis['numero'] ?? ('#'.$devisId)) ?></small></h2>
  <p><strong>Client :</strong> <?= htmlspecialchars($clientFull) ?><br>
  <?php if ($adr1) echo htmlspecialchars($adr1).'<br>'; ?>
  <?php if ($cp || $ville) echo htmlspecialchars(trim($cp.' '.$ville)).'<br>'; ?>
  <span class="muted">Total TTC du devis :</span>
  <span id="total_ttc" data-raw="<?= htmlspecialchars($total_ttc) ?>">
    <strong><?= number_format($total_ttc,2,',',' ') ?> €</strong>
  </span></p>

  <form method="POST" data-submit-once>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="id" value="<?= (int)$devisId ?>">

    <label for="acompte_type">Type d'acompte :</label>
    <select id="acompte_type" name="acompte_type">
      <option value="percent" selected>Pourcentage (%)</option>
      <option value="amount">Montant (€)</option>
    </select>

    <label for="acompte_value">Valeur :</label>
    <input type="number" id="acompte_value" name="acompte_value" step="0.01" value="30">

    <div class="total">
      <div><strong>Acompte calculé :</strong> <span id="acompte_calc">0.00 €</span></div>
      <div><strong>Reste à payer :</strong> <span id="reste_calc">0.00 €</span></div>
    </div>

    <div class="actions">
      <a class="btn secondary" href="devis.php">← Retour</a>
      <button type="submit" class="btn">Générer le Bon de Commande (PDF)</button>
    </div>
  </form>
</div>
<script src="inc/submit-once.js"></script>
</body>
</html>
