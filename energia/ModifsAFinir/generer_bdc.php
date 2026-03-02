<?php
declare(strict_types=1);

/**
 * generer_bdc.php — Bon de Commande PDF calqué sur le Devis
 * - Rendu tFPDF identique au Devis (EnergiaPDF + helpers du layout Devis)
 * - Entête/pied alimentés par energia_societe (logo, coordonnées, identifiants, slogans)
 * - Lignes récupérées depuis les tables de devis (ou fallback JSON)
 * - Numéro BDC auto : BDC-YYYY-####
 * - Sauvegarde dans /bdc_pdf + mise à jour DB, affichage inline
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function abortX(int $code, string $msg){ http_response_code($code); exit($msg); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- DB helpers ---- */
function tableExists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0"); return true; } catch(Throwable $e){ return false; }
}
function showColumns(PDO $pdo, string $table): array {
  try { $st = $pdo->query("SHOW COLUMNS FROM `{$table}`"); return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
  catch(Throwable $e){ return []; }
}
function firstExisting(array $pool, array $cols) {
  foreach ($pool as $c) if (in_array($c, $cols, true)) return $c;
  return null;
}
function round2($n){ return round((float)$n + 1e-12, 2); }
function normRate($r){
  $r = is_numeric($r) ? (float)$r : 20.0;
  if (abs($r-0.0)<0.001) return 0.0;
  if (abs($r-10.0)<0.001) return 10.0;
  return 20.0;
}
function isAssoc(array $a){ return array_keys($a)!==range(0,count($a)-1); }
function firstKey(array $arr, array $keys){ foreach($keys as $k) if(array_key_exists($k,$arr)) return $k; return null; }

/* ---- Paramètres ---- */
$devisId = isset($_REQUEST['id']) && ctype_digit((string)$_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
if ($devisId <= 0) abortX(400, 'Devis ID manquant.');

/* ---- Devis + Client ---- */
function fetchDevis(PDO $pdo, int $id): ?array {
  $sql = "SELECT d.*, c.*, d.bank_account_id AS devis_bank_id,
                 ba.nom_du_compte, ba.titulaire, ba.banque, ba.iban, ba.bic
          FROM energia_devis d
          JOIN energia_clients c ON c.id = d.client_id
          LEFT JOIN energia_bank_accounts ba ON ba.id = d.bank_account_id
          WHERE d.id = :id LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
try { $energia_devis = fetchDevis($pdo, $devisId); }
catch(Throwable $e){ abortX(500, "Erreur lors du chargement du devis #$devisId."); }
if (!$energia_devis) abortX(404, "Devis introuvable #$devisId.");

/* ---- Société (footer / entête) ---- */
function fetchSociete(PDO $pdo): array {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energia_societe (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      nom VARCHAR(191) NOT NULL,
      slogan1 VARCHAR(191) NULL,
      slogan2 VARCHAR(191) NULL,
      adresse VARCHAR(255) NULL,
      code_postal VARCHAR(30) NULL,
      ville VARCHAR(120) NULL,
      pays VARCHAR(120) NULL,
      telephone VARCHAR(60) NULL,
      site_web VARCHAR(191) NULL,
      email VARCHAR(191) NULL,
      logo_path VARCHAR(255) NULL,
      capital VARCHAR(191) NULL,
      siren VARCHAR(30) NULL,
      siret VARCHAR(30) NULL,
      tva_intra VARCHAR(40) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $st = $pdo->query("SELECT * FROM energia_societe ORDER BY id LIMIT 1");
  $s = $st->fetch(PDO::FETCH_ASSOC);
  if (!$s) {
    $pdo->exec("INSERT INTO energia_societe (nom) VALUES ('Ma société')");
    $st = $pdo->query("SELECT * FROM energia_societe ORDER BY id LIMIT 1");
    $s = $st->fetch(PDO::FETCH_ASSOC);
  }
  return $s ?: [];
}
$societe = fetchSociete($pdo);

/* ---- Récupération des lignes du devis (multi stratégies) ---- */
function mapRows(array $rows): array {
  return array_values(array_map(function($r){
    $lib = trim((string)($r['libelle'] ?? ''));
    $desc= trim((string)($r['desc'] ?? ''));
    $q   = (int)($r['quantite'] ?? 0);
    $pu  = (float)($r['prix_ht'] ?? 0);
    $tva = array_key_exists('tva',$r) ? (float)$r['tva'] : 20.0;
    $pkey= (string)($r['piece_key'] ?? '');
    $pnom= (string)($r['piece_nom'] ?? '');
    $code= (string)($r['code'] ?? ''); // ajouté : code d'article si dispo
    return [
      'code'=>$code, 'libelle'=>$lib, 'desc'=>$desc, 'quantite'=>$q, 'prix_ht'=>$pu,
      'tva'=>$tva, 'piece_key'=>$pkey, 'piece_nom'=>$pnom,
    ];
  }, $rows));
}
function smartFetchFromTable(PDO $pdo, string $table, int $devisId): array {
  $cols = showColumns($pdo, $table);
  if (!$cols) return [];
  $idCol = firstExisting(['devis_id','id_devis','devisId','idDevis'], $cols);
  if (!$idCol) return [];
  $cCODE= firstExisting(['code','article_code','ref','reference','code_article'], $cols); // NEW
  $cLib = firstExisting(['libelle','intitule','designation','nom','label','title'], $cols);
  $cDesc= firstExisting(['description','desc','details','commentaire','texte'], $cols);
  $cQte = firstExisting(['quantite','qte','qty','quantity'], $cols);
  $cPU  = firstExisting(['prix_unitaire','pu_ht','unit_price','price_ht','prixht','prix'], $cols);
  $cTVA = firstExisting(['tva_taux','taux_tva','tva','vat','tax_rate','tax'], $cols);
  $cPKey= in_array('piece_key',$cols,true) ? 'piece_key' : null;
  $cPNom= in_array('piece_nom',$cols,true) ? 'piece_nom' : null;
  if (!$cLib && !$cPU && !$cQte) return [];

  $sel = [];
  if ($cCODE) $sel[]="`$cCODE` AS code";
  if ($cLib)  $sel[]="`$cLib`  AS libelle";
  if ($cDesc) $sel[]="`$cDesc` AS `desc`";
  if ($cQte)  $sel[]="`$cQte`  AS quantite";
  if ($cPU)   $sel[]="`$cPU`   AS prix_ht";
  if ($cTVA)  $sel[]="`$cTVA`  AS tva";
  if ($cPKey) $sel[]="`$cPKey` AS piece_key";
  if ($cPNom) $sel[]="`$cPNom` AS piece_nom";

  $orderCol = firstExisting(['ordre','position','rang','id'], $cols);
  $orderSql = $orderCol ? " ORDER BY `$orderCol`" : "";

  $sql = "SELECT ".implode(', ',$sel)." FROM `$table` WHERE `$idCol` = :id".$orderSql;
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$devisId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  return mapRows($rows);
}
function fetchFromJSONDevis(array $energia_devis): array {
  foreach ($energia_devis as $k => $v) {
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
      $code= firstKey($item, ['code','ref','reference','code_article']);
      if ($lib!==null && $pu!==null && $qte!==null) {
        $mapped[] = [
          'code'   => (string)($item[$code] ?? ''),
          'libelle'=> (string)($item[$lib] ?? ''),
          'desc'   => (string)($item[$desc]?? ''),
          'quantite'=> (int)($item[$qte] ?? 1),
          'prix_ht'=> (float)($item[$pu]  ?? 0),
          'tva'    => array_key_exists($tva ?? '', $item) ? (float)$item[$tva] : 20.0,
          'piece_key'=> (string)($item[$pkey] ?? ''),
          'piece_nom'=> (string)($item[$pnom] ?? ''),
        ];
      }
    }
    if ($mapped) return mapRows($mapped);
  }
  return [];
}

/* ---- Charger les lignes ---- */
$lines = [];
if (tableExists($pdo, 'energia_devis_lignes')) {
  $lines = smartFetchFromTable($pdo, 'energia_devis_lignes', $devisId);
}
if (!$lines && tableExists($pdo, 'energia_devis_items')) {
  $lines = smartFetchFromTable($pdo, 'energia_devis_items', $devisId);
}
$altTables = ['devis_articles','devis_details','devis_pac','devis_lignes_items'];
foreach ($altTables as $t) {
  if ($lines) break;
  if (tableExists($pdo, $t)) { $lines = smartFetchFromTable($pdo, $t, $devisId); }
}
if (!$lines) { $lines = fetchFromJSONDevis($energia_devis); }
if (empty($lines)) abortX(500, "Aucune ligne trouvée pour ce devis.");

/* ---- Totaux + groupement par pièce ---- */
$DEFAULT_PIECE_KEY = '_global';
$DEFAULT_PIECE_NAME = 'Sans pièce';

$total_ht = 0.0; $total_tva = 0.0; $total_ttc = 0.0;
$pieceOrder = []; $pieceTotals = []; $grouped = [];
$htByRate = []; // "0","10","20"
foreach ($lines as &$ln) {
  $qty = (int)($ln['quantite'] ?? 0); if ($qty <= 0) $qty = 1;
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

/* TTC par regroupement de taux (aligné energia_devis) */
$total_ttc = 0.0;
foreach ($htByRate as $rateStr => $ht) {
  $rate = (float)$rateStr;
  $total_ttc = round2($total_ttc + round2($ht * (1 + ($rate/100.0))));
}
$total_tva = round2($total_ttc - $total_ht);

/* ---- Table BDC + numérotation ---- */
function ensureBdcTable(PDO $pdo) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energia_bons_de_commande (
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
  $q = $pdo->prepare("SELECT numero FROM energia_bons_de_commande WHERE numero LIKE :pfx ORDER BY id DESC LIMIT 1");
  $q->execute([':pfx' => "BDC-$year-%"]);
  $last = $q->fetchColumn(); $seq = 0;
  if ($last && preg_match('~^BDC-'.$year.'-(\d{4})$~', $last, $m)) $seq = (int)$m[1];
  return sprintf('BDC-%s-%04d', $year, $seq+1);
}

/* ===================== Flux ===================== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ===== POST : Calcul acompte + génération PDF (style Devis) ===== */
if ($method === 'POST') {
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    abortX(400, 'CSRF token invalide.');
  }
  $atype = ($_POST['acompte_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
  $aval  = (float) str_replace(',', '.', (string)($_POST['acompte_value'] ?? '0'));

  if ($atype === 'percent') {
    $aval = max(0, min(100, $aval));
  } else {
    $aval = max(0, min($total_ttc, $aval));
  }
  $acompte_montant = ($atype==='percent') ? round2($total_ttc * ($aval/100.0)) : round2($aval);
  $reste = max(0, round2($total_ttc - $acompte_montant));

  try { ensureBdcTable($pdo); }
  catch(Throwable $e){ abortX(500, "Impossible de préparer la table des BDC."); }

  /* Enregistrement BDC */
  try {
    $numero = nextBdcNumber($pdo);
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare("
      INSERT INTO energia_bons_de_commande
      (numero, devis_id, date_creation, acompte_type, acompte_value, acompte_montant, montant_ttc, fichier_pdf)
      VALUES (:numero, :devis_id, :dt, :at, :av, :am, :ttc, NULL)
    ");
    $ins->execute([
      ':numero'=>$numero, ':devis_id'=>$devisId, ':dt'=>$now,
      ':at'=>$atype, ':av'=>$aval, ':am'=>$acompte_montant, ':ttc'=>$total_ttc
    ]);
    $bdcId = (int)$pdo->lastInsertId();
  } catch(Throwable $e){ abortX(500, "Enregistrement du BDC impossible."); }

  /* ====== PDF (style EXACT du Devis) ====== */
  require_once __DIR__ . '/devis_pdf_layout.php'; // EnergiaPDF + helpers + grille du Devis

  // Helpers entête spécifiques BDC (titre + entête société depuis BDD)
  if (!function_exists('bdc_draw_box_title')) {
    function bdc_draw_box_title(EnergiaPDF $pdf, string $title='BON DE COMMANDE'): void {
      $pdf->SetFont('DejaVu','B',16);
      $pdf->SetFillColor(220,220,220);
      $pdf->SetDrawColor(160,160,160);
      $pdf->Rect(175, 8, 25, 10, 'DF');
      $pdf->SetXY(175,8);
      $pdf->Cell(25,10,$title,0,0,'C');
    }
  }
  if (!function_exists('bdc_header_company_from_societe')) {
    function bdc_header_company_from_societe(EnergiaPDF $pdf, array $societe, string $fallbackLogoPath): void {
      $logoPath = !empty($societe['logo_path']) ? (__DIR__ . '/' . $societe['logo_path']) : $fallbackLogoPath;
      if (is_file($logoPath)) $pdf->Image($logoPath, 10, 8, 110, 16); // métriques du Devis
      $pdf->SetXY(9, 26);
      $pdf->SetFont('DejaVu','B',11);
      $nom = (string)($societe['nom'] ?? '');
      if ($nom !== '') $pdf->Cell(0,5,$nom,0,1,'L');
      $pdf->SetFont('DejaVu','',7);
      $sub = [];
      if (!empty($societe['slogan1']))   $sub[] = (string)$societe['slogan1'];
      if (!empty($societe['slogan2']))   $sub[] = (string)$societe['slogan2'];
      if (!empty($societe['adresse']))   $sub[] = (string)$societe['adresse'];
      $cpVille = trim((string)($societe['code_postal']??'').' '.(string)($societe['ville']??''));
      if ($cpVille !== '')               $sub[] = $cpVille;
      if (!empty($societe['pays']))      $sub[] = (string)$societe['pays'];
      if (!empty($societe['telephone'])) $sub[] = 'Tél : '.$societe['telephone'];
      if (!empty($societe['site_web']))  $sub[] = 'Site web : '.$societe['site_web'];
      if (!empty($societe['email']))     $sub[] = 'Email : '.$societe['email'];
      foreach ($sub as $l) { $pdf->SetX(9); $pdf->Cell(0,3.5,$l,0,1,'L'); }
    }
  }

  // === Création doc
  $pdf = new EnergiaPDF('P','mm','A4');
  $pdf->AliasNbPages('{nb}');
  $pdf->footerLine = ''; // tu peux mettre ta ligne si besoin
  $pdf->SetMargins(10,10,10);
  $pdf->SetAutoPageBreak(false);

  // Polices (comme Devis)
  $pdf->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf', true);
  $pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);
  $pdf->AddFont('DejaVu','I','DejaVuSansCondensed-Oblique.ttf', true);

  // Page 1
  $pdf->AddPage();
  bdc_draw_box_title($pdf, 'BON DE COMMANDE');

  // Entête société (mêmes positions que Devis)
  $daikinHeader = __DIR__ . '/assets/img/daikin_header.png'; // fallback
  bdc_header_company_from_societe($pdf, $societe, $daikinHeader);

  // Entête client (helper du Devis)
  $clientNom   = trim(($energia_devis['nom'] ?? '').' '.($energia_devis['prenom'] ?? ''));
  $clientAdr   = (string)($energia_devis['adresse'] ?? '');
  $clientCpVil = trim(($energia_devis['code_postal'] ?? '').' '.($energia_devis['ville'] ?? ''));
  energia_header_client($pdf, $clientNom, $clientAdr, $clientCpVil);

  // Tableau méta (style Devis)
  $numeroBDC  = $numero;
  $dateDoc    = date('Y-m-d');
  $codeClient = (string)($energia_devis['code_client'] ?? '');
  $validite   = (string)($energia_devis['date_echeance'] ?? '');
  $modeReg    = (string)($energia_devis['mode_paiement'] ?? '');
  energia_draw_meta_table($pdf, [
    $numeroBDC,
    ($dateDoc ?: ''),
    $codeClient,
    ($validite ? date('d/m/Y', strtotime($validite)) : ''),
    $modeReg,
  ]);

  // Grille (titres)
  $colW = [25,93,13,23,23,13]; // Code | Description | Qté | P.U. HT | Montant HT | TVA
  $yStartHeaderFirst = 95;
  $yStartBodyFirst   = $yStartHeaderFirst + 8;
  energia_table_header($pdf, $yStartHeaderFirst, $colW);

  $lineH = 6.0;
  $FOOTER_GAP_MM = 0.5;
  $footerLineY  = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
  $yBottomFull  = $footerLineY - $FOOTER_GAP_MM;
  $y = $yStartBodyFirst;

  // Description d'installation (si présente)
  if (!empty($energia_devis['description'])) {
    $pdf->SetFont('DejaVu','',7.7);
    $y = energia_row_multiline($pdf,$colW,[' ',(string)$energia_devis['description'],' ',' ',' ',' '],$y,$lineH);
  }

  // Lignes par pièce (style Devis)
  foreach (array_keys($pieceOrder ?: $grouped) as $pKey) {
    $y = energia_row_multiline($pdf,$colW,['','','','','',''],$y,2.5);
    $pieceTitle = (string)($pieceTotals[$pKey]['nom'] ?? 'Pièce');
    if ($pieceTitle !== '') {
      $pdf->SetFont('DejaVu','B',8.7);
      $y = energia_row_multiline($pdf,$colW,[' ',$pieceTitle,' ',' ',' ',' '],$y,$lineH);
      $pdf->SetFont('DejaVu','',7.7);
    }
    foreach ($grouped[$pKey] as $m) {
      $code = (string)($m['code'] ?? '');
      $desc = (string)($m['libelle'] ?? '');
      $qte  = number_format((float)($m['quantite'] ?? 0), 0, ',', ' ');
      $pu   = number_format((float)($m['prix_ht']  ?? 0), 2, ',', ' ').' €';
      $mht  = number_format((float)($m['total_ht'] ?? 0), 2, ',', ' ').' €';
      $tva  = number_format((float)($m['tva']      ?? 20), 0, ',', ' ').' %';

      $y = energia_row_multiline($pdf, $colW, [$code, $desc, $qte, $pu, $mht, $tva], $y, $lineH);

      if ($y + $lineH > $yBottomFull) {
        $pdf->Line(10, $y, 10 + array_sum($colW), $y);
        $pdf->AddPage();
        $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
        $yBottomFull = $footerLineY - $FOOTER_GAP_MM;
        energia_table_header($pdf, 20, $colW);
        $y = 28;
      }
    }
  }

  // Réserve bas + trait de fermeture comme Devis
  $blocBasH = 36; // (info TVA + totaux) — on duplique le même espace que dans le Devis
  $yBlocksTop = $yBottomFull - $blocBasH;
  while ($y + $lineH <= $yBlocksTop) {
    $y = energia_row_multiline($pdf,$colW,['','','','','',''],$y,$lineH);
  }
  if ($y < $yBlocksTop) { $y = energia_row_fill_to($pdf,$colW,$y,$yBlocksTop); }
  $pdf->Line(10, $y, 10 + array_sum($colW), $y);

  // Totaux (bloc à droite, style Devis)
  $xPos=120; $wLabel=48; $wVal=32; $hRow=6; $yTot=$yBlocksTop + 12; // position proche du Devis
  $pdf->SetDrawColor(160,160,160); $pdf->SetFillColor(220,220,220);
  $pdf->Line($xPos,$yTot,$xPos,$yTot+$hRow*4);
  $pdf->Line($xPos+$wLabel,$yTot,$xPos+$wLabel,$yTot+$hRow*4);
  $pdf->Line($xPos+$wLabel+$wVal,$yTot,$xPos+$wLabel+$wVal,$yTot+$hRow*4);
  $pdf->Line($xPos,$yTot,$xPos+$wLabel+$wVal,$yTot);
  $pdf->Line($xPos,$yTot+$hRow*4,$xPos+$wLabel+$wVal,$yTot+$hRow*4);
  $pdf->Line($xPos, $yTot+$hRow*3, $xPos+$wLabel+$wVal, $yTot+$hRow*3);
  for($i=0;$i<4;$i++){ $pdf->Rect($xPos,$yTot+($i*$hRow),$wLabel,$hRow,'F'); }
  $pdf->SetFont('DejaVu','',8);
  $rows = [
    ['Total HT', number_format((float)$total_ht,2,',',' ')],
    ['Total TVA', number_format((float)$total_tva,2,',',' ')],
    ['Total TTC', number_format((float)$total_ttc,2,',',' ')],
  ];
  for($i=0;$i<3;$i++){
    $yy = $yTot + ($i*$hRow);
    $pdf->SetXY($xPos+1.5,$yy); $pdf->Cell($wLabel-3,$hRow,$rows[$i][0],0,0,'L');
    $pdf->SetXY($xPos+$wLabel,$yy); $pdf->Cell($wVal-1.5,$hRow,$rows[$i][1],0,0,'R');
  }
  $pdf->SetFont('DejaVu','',9);
  $yy = $yTot + (3*$hRow);
  $pdf->SetXY($xPos+1.5,$yy); $pdf->Cell($wLabel-3,$hRow,'Net à payer',0,0,'L');
  $pdf->SetXY($xPos+$wLabel,$yy); $pdf->Cell($wVal-1.5,$hRow, number_format((float)$total_ttc,2,',',' ').' €', 0, 0, 'R');

  // Sauvegarde + réponse
  $dir = __DIR__ . '/bdc_pdf';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $fileName = $numero.'.pdf'; $fsPath = $dir.'/'.$fileName;
  $pdf->Output('F', $fsPath);

  $upd = $pdo->prepare("UPDATE energia_bons_de_commande SET fichier_pdf = :f WHERE id = :id");
  $upd->execute([':f'=>'bdc_pdf/'.$fileName, ':id'=>$bdcId]);

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="'.$fileName.'"');
  readfile($fsPath);
  exit;
}

/* ===== GET : Formulaire acompte ===== */
$clientFull = trim(($energia_devis['prenom'] ?? '').' '.($energia_devis['nom'] ?? ''));
$adr1 = (string)($energia_devis['adresse'] ?? $energia_devis['adresse1'] ?? $energia_devis['adresse_ligne1'] ?? '');
$cp = (string)($energia_devis['code_postal'] ?? $energia_devis['cp'] ?? '');
$ville = (string)($energia_devis['ville'] ?? '');
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
      const val = parseFloat((document.getElementById('acompte_value').value || '0').replace(',','.'));
      let acompte = 0;
      if (type === 'percent') {
        let p = Math.max(0, Math.min(100, isNaN(val)?0:val));
        acompte = totalTTC * (p/100.0);
      } else {
        acompte = Math.max(0, Math.min(totalTTC, isNaN(val)?0:val));
      }
      const reste = Math.max(0, totalTTC - acompte);
      document.getElementById('acompte_calc').textContent = fmt(acompte)+' €';
      document.getElementById('reste_calc').textContent = fmt(reste)+' €';
    }
    document.addEventListener('DOMContentLoaded', () => {
      document.getElementById('acompte_type').addEventListener('change', recompute);
      document.getElementById('acompte_value').addEventListener('input', recompute);
      recompute();
    });
  </script>
  style.css
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>
<div class="main">
  <div class="card">
    <h2>Bon de Commande — <small>depuis le devis <?= e($energia_devis['numero'] ?? ('#'.$devisId)) ?></small></h2>
    <p>
      <strong>Client :</strong> <?= e($clientFull) ?><br>
      <?php if ($adr1) echo e($adr1).'<br>'; ?>
      <?php if ($cp || $ville) echo e(trim($cp.' '.$ville)).'<br>'; ?>
      <span class="muted">Total TTC du devis :</span>
      <span id="total_ttc" data-raw="<?= e((string)$total_ttc) ?>">
        <strong><?= number_format($total_ttc,2,',',' ') ?> €</strong>
      </span>
    </p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="id" value="<?= (int)$devisId ?>">

      <div class="grid-2-tight" style="gap:14px;max-width:560px;">
        <div>
          <label for="acompte_type">Type d’acompte :</label>
          <select id="acompte_type" name="acompte_type">
            <option value="percent" selected>Pourcentage (%)</option>
            <option value="amount">Montant (€)</option>
          </select>
        </div>
        <div>
          <label for="acompte_value">Valeur :</label>
          <input type="number" id="acompte_value" name="acompte_value" step="0.01" value="30">
        </div>
      </div>

      <div class="total" style="margin-top:8px;">
        <div><strong>Acompte calculé :</strong> <span id="acompte_calc">0.00 €</span></div>
        <div><strong>Reste à payer :</strong> <span id="reste_calc">0.00 €</span></div>
      </div>

      <div class="actions" style="margin-top:10px;">
        devis.php← Retour</a>
        <button type="submit" class="btn">Générer le Bon de Commande (PDF)</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>