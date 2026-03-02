<?php
declare(strict_types=1);

/**
 * GENERER FACTURE — ENERGIA
 * - Entête & pied de page = version PJ (logo Daikin, bloc société, “FACTURE” + N°, dates, bloc client, séparateurs, mentions, RIB, ligne légale)
 * - BDC = table energia_bons_de_commande
 * - Numérotation: FACT-YYYY-00001 (incrément annuel)
 * - Nom de fichier: FACT-YYYY-00001.pdf
 * - Chemin en BDD: facture_pdf/FACT-YYYY-00001.pdf (RELATIF, pour suppression OK)
 * - Tables: energia_*
 */

require __DIR__.'/auth.php';
require __DIR__.'/config.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
require __DIR__ . '/tfpdf/tfpdf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function abort_with(string $msg){
  http_response_code(400);
  echo "<pre>".htmlspecialchars($msg)."</pre>";
  exit;
}
function has_column(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $stmt->execute([$column]);
  return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}
function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function parse_money($s): float {
  $s = (string)$s; if ($s==='') return 0.0;
  $s = str_replace(["\xC2\xA0", "\u{00A0}", ' '], '', $s);
  $s = str_replace(',', '.', $s);
  return (float)$s;
}

/* ───────── Helpers chargement source (devis / BDC) ───────── */
function load_source(PDO $pdo, string $src, int $id): array {
  if ($src === 'bdc') {
    // ⚠️ table BDC correcte: energia_bons_de_commande (pas energie_bdc)
    if (!table_exists($pdo, 'energia_bons_de_commande')) abort_with("Table energia_bons_de_commande absente.");
    $st = $pdo->prepare("SELECT * FROM energia_bons_de_commande WHERE id=?");
    $st->execute([$id]);
    $bdc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bdc) abort_with("Bon de commande introuvable.");

    $dvId = (int)($bdc['devis_id'] ?? 0);
    if ($dvId <= 0) abort_with("Bon de commande sans devis associé.");

    $st = $pdo->prepare("SELECT * FROM energia_devis WHERE id=?");
    $st->execute([$dvId]);
    $devis = $st->fetch(PDO::FETCH_ASSOC);
    if (!$devis) abort_with("Devis introuvable (rattaché au BDC).");

    $st = $pdo->prepare("SELECT nom, prenom, adresse, code_postal, ville, telephone, email FROM energia_clients WHERE id=?");
    $st->execute([$devis['client_id']]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom'=>'Inconnu','prenom'=>'','adresse'=>'','code_postal'=>'','ville'=>'','telephone'=>'','email'=>''];

    $acompte_suggere = isset($bdc['acompte_montant']) ? (float)$bdc['acompte_montant'] : 0.0;
    return ['devis'=>$devis, 'bdc'=>$bdc, 'client'=>$client, 'acompte_suggere'=>$acompte_suggere];
  }

  // src=devis (par défaut)
  $st = $pdo->prepare("SELECT * FROM energia_devis WHERE id=?");
  $st->execute([$id]);
  $devis = $st->fetch(PDO::FETCH_ASSOC);
  if (!$devis) abort_with("Devis introuvable.");

  $st = $pdo->prepare("SELECT nom, prenom, adresse, code_postal, ville, telephone, email FROM energia_clients WHERE id=?");
  $st->execute([$devis['client_id']]);
  $client = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom'=>'Inconnu','prenom'=>'','adresse'=>'','code_postal'=>'','ville'=>'','telephone'=>'','email'=>''];

  return ['devis'=>$devis, 'bdc'=>null, 'client'=>$client, 'acompte_suggere'=>0.0];
}

function get_devis_lignes(PDO $pdo, int $devis_id): array {
  $hasPieceKey = has_column($pdo, 'energia_devis_lignes', 'piece_key');
  $hasPieceNom = has_column($pdo, 'energia_devis_lignes', 'piece_nom');
  $cols = "devis_id, pac_id, libelle, quantite, prix_unitaire, tva_taux, total_ht, total_ttc"
        . ($hasPieceKey ? ", piece_key" : "")
        . ($hasPieceNom ? ", piece_nom" : "");
  $st = $pdo->prepare("SELECT $cols FROM energia_devis_lignes WHERE devis_id=?");
  $st->execute([$devis_id]);
  $raw = $st->fetchAll(PDO::FETCH_ASSOC);

  // Enrichit avec description PAC (si dispo)
  $getDesc = $pdo->prepare("SELECT description, nom FROM energia_pompes_a_chaleur WHERE id=?");
  $rows = [];
  foreach ($raw as $ln) {
    $desc = ''; $nom_catalogue = '';
    if (!empty($ln['pac_id'])) {
      $getDesc->execute([$ln['pac_id']]);
      if ($row = $getDesc->fetch(PDO::FETCH_ASSOC)) {
        $desc = (string)($row['description'] ?? '');
        $nom_catalogue = (string)($row['nom'] ?? '');
      }
    }
    if (trim((string)$ln['libelle']) === '' && $nom_catalogue !== '') {
      $ln['libelle'] = $nom_catalogue;
    }
    if (!$hasPieceKey) $ln['piece_key'] = '_global';
    if (!$hasPieceNom) $ln['piece_nom'] = 'Sans pièce';
    if (trim((string)$ln['piece_key']) === '') $ln['piece_key'] = '_global';
    if (trim((string)$ln['piece_nom']) === '') $ln['piece_nom'] = 'Sans pièce';
    $ln['_desc'] = $desc;
    $rows[] = $ln;
  }
  return $rows;
}

/* ───────── GET : mini-formulaire (si appelé en GET) ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

  $src = isset($_GET['src']) ? strtolower(trim($_GET['src'])) : 'devis';
  if ($src !== 'devis' && $src !== 'bdc') $src = 'devis';
  if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) abort_with(($src==='bdc'?'ID de BDC':'ID de devis')." manquant ou invalide.");

  $id  = (int)$_GET['id'];
  $ctx = load_source($pdo, $src, $id);

  $client_full = htmlspecialchars(trim(($ctx['client']['prenom']??'').' '.($ctx['client']['nom']??'')), ENT_QUOTES);
  $title_src   = $src==='bdc'
    ? 'depuis le BDC '.htmlspecialchars($ctx['bdc']['numero'] ?? ('#'.$id), ENT_QUOTES).' (devis '.htmlspecialchars($ctx['devis']['numero'] ?? '#?', ENT_QUOTES).')'
    : 'depuis le devis '.htmlspecialchars($ctx['devis']['numero'] ?? ('#'.$id), ENT_QUOTES);

  // Calcul du chemin CSS correct
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
  if ($base === '\\' || $base === '.') $base = '';
  $cssHref = $base . '/assets/css/admin.css?v=' . date('Ymd');

  ?>
  <!DOCTYPE html>
  <html lang="fr">
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Générer la facture</title>

      <!-- CSS chargé correctement -->
      <link rel="stylesheet" href="<?= htmlspecialchars($cssHref) ?>">
  </head>

  <body class="ui-bg">

  <main class="ui-center">
      <section class="card card--elevated">

          <header class="card__header">
              <h1 class="card__title">Générer la facture</h1>
              <p class="card__subtitle"><?= $title_src ?></p>
          </header>

          <div class="card__content">
              <p class="muted">Client</p>
              <p class="client-name"><?= $client_full ?></p>

              <form method="post" class="form-grid">

                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="src" value="<?= htmlspecialchars($src) ?>">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">

                  <div class="form-field">
                      <label class="form-label">Date de facture</label>
                      <input type="date" id="date_facture" name="date_facture" class="input input--sm" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?>" required>
				  </div>

                  <div class="form-field">
                      <label class="form-label">Date de prestation</label>
                      <input type="date" id="date_prestation" name="date_prestation" class="input input--sm">
                  </div>

                  <div class="form-field form-field--full">
                      <label class="form-label">Acompte payé (optionnel)</label>
                      <input type="text" name="acompte" class="input" placeholder="500,00">
                      <small class="hint">Format accepté : 500, 500.00 ou 500,00</small>
                  </div>

                  <div class="form-actions">
                      <a class="btn btn--ghost" href="devis.php">Annuler</a>
                      <button type="submit" class="btn btn--primary">Créer la facture</button>
                  </div>

              </form>
          </div>
      </section>
  </main>

  </body>
  </html>
  <?php
  exit;
}

/* ───────── POST : génération ───────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') abort_with('Méthode non supportée.');
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) abort_with('Token CSRF invalide.');

$src = isset($_POST['src']) ? strtolower(trim($_POST['src'])) : 'devis';
if ($src !== 'devis' && $src !== 'bdc') $src = 'devis';
if (!isset($_POST['id']) || !ctype_digit($_POST['id'])) abort_with(($src==='bdc'?'ID de BDC':'ID de devis')." manquant ou invalide.");
$id = (int)$_POST['id'];

$date_facture    = !empty($_POST['date_facture'])    ? $_POST['date_facture']    : date('Y-m-d');
$date_prestation = !empty($_POST['date_prestation']) ? $_POST['date_prestation'] : null;
$acompte         = isset($_POST['acompte']) ? parse_money($_POST['acompte']) : 0.0;

$ctx    = load_source($pdo, $src, $id);
$devis  = $ctx['devis'];
$bdc    = $ctx['bdc'];
$client = $ctx['client'];

$lignes = get_devis_lignes($pdo, (int)$devis['id']);

// Totaux globaux
$total_ht = 0.0; $total_ttc = 0.0;
foreach ($lignes as $l) { $total_ht += (float)$l['total_ht']; $total_ttc += (float)$l['total_ttc']; }
$total_tva = max(0.0, $total_ttc - $total_ht);

// Acompte
if ($acompte < 0) $acompte = 0.0;
if ($acompte > $total_ttc) $acompte = $total_ttc;
$net_a_payer = max(0.0, $total_ttc - $acompte);

/* ───────── Numérotation & nom de fichier ───────── */
$annee  = (int)date('Y', strtotime($date_facture));
$prefix = sprintf('FACT-%04d-', $annee);

$st = $pdo->prepare("
  SELECT numero FROM energia_factures
  WHERE numero LIKE CONCAT(?, '%')
  ORDER BY numero DESC
  LIMIT 1
");
$st->execute([$prefix]);
$last = $st->fetchColumn();
$seq  = ($last && preg_match('/^'.preg_quote($prefix,'/').'(\d{5})$/', $last, $m)) ? ((int)$m[1] + 1) : 1;

$numero_facture = $prefix . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
$filename       = $numero_facture . '.pdf';
$folder_abs     = __DIR__ . '/facture_pdf/';
$filepath_abs   = $folder_abs . $filename;
$filepath_web   = 'facture_pdf/' . $filename; // RELATIF => compat suppr_facture.php

if (!is_dir($folder_abs)) @mkdir($folder_abs, 0775, true);

/* ───────── PDF — entête & pied = version PJ ───────── */
/* Réf : ton fichier PJ "generer_factureETETE.php" (structure header/footer/sections) [1](https://cryonna-my.sharepoint.com/personal/christophe_rcadvance_fr/Documents/Fichiers%20Microsoft%20Copilot%20Chat/generer_factureETETE.php) */

class PDF_FactureStyleDevis extends tFPDF {

    protected $banks;
    protected $footerInfo;
    protected $headerMeta;


	public function setMeta($banks, $footerInfo, $headerMeta) {
		$this->banks      = $banks;
		$this->footerInfo = $footerInfo;
		$this->headerMeta = $headerMeta;
	}

    function Header() {

        // Logo
        $logo = __DIR__ . '/assets/img/daikin_header.png';
        $logoY = 6; $logoW = 75;

        if (is_file($logo)) {
            $this->Image($logo, 10, $logoY, $logoW);
            list($imgW, $imgH) = getimagesize($logo);
            $printedH = $logoW * ($imgH / $imgW);
            $afterLogoY = $logoY + $printedH + 4;
        } else {
            $afterLogoY = 20;
        }

        // Bloc société
        $this->SetXY(10, $afterLogoY);
        $this->SetFont('DejaVu','B',12);
        $this->Cell(0,5,'ENERGIA Aire Acondicionado - SL',0,1,'L');

        $this->SetFont('DejaVu','B',8);
        $this->Cell(0,4,'Electricité - Chauffage - Climatisation',0,1,'L');
        $this->Cell(0,4,'Chauffe eau Solaire - Photovoltaique',0,1,'L');

        $this->SetFont('DejaVu','',8);
        $this->Cell(0,4,'Avda Ricardo Soriano 72',0,1,'L');
        $this->Cell(0,4,'29601 MARBELLA - ESPANA',0,1,'L');
        $this->Cell(0,4,'Tél : 06.10.26.83.34',0,1,'L');
        $this->Cell(0,4,'Site web : www.energia-marbella.com',0,1,'L');
        $this->Cell(0,4,'Email : energia.aireacondicionado29@gmail.com',0,1,'L');

        // Titre FACTURE
        $rightColW = 60;
        $rightColX = $this->w - $this->rMargin - $rightColW;

        $this->SetFont('DejaVu','',14);
        $this->SetXY($rightColX, 14);
        $this->Cell($rightColW,8,'FACTURE',0,1,'R');

        $yAfterHeader = max($this->GetY(), 30);


// Sous le titre FACTURE
$this->SetFont('DejaVu','',10);
$this->SetXY($rightColX, 22);
$this->Cell($rightColW,6,'N° '.$this->headerMeta['numero'],0,1,'R');

// puis utiliser yAfterHeader APRÈS ce bloc
$yAfterHeader = max($this->GetY(), 30);


        if (!empty($this->headerMeta['date'])) {
            $this->SetFont('DejaVu','',9);
            $this->SetXY($rightColX,$yAfterHeader);
            $this->Cell($rightColW,5,'Date : '.$this->headerMeta['date'],0,1,'R');
            $yAfterHeader = $this->GetY();
        }

        if (!empty($this->headerMeta['date_prestation'])) {
            $this->SetFont('DejaVu','',9);
            $this->SetXY($rightColX,$yAfterHeader);
            $this->Cell($rightColW,5,'Prestation : '.$this->headerMeta['date_prestation'],0,1,'R');
            $yAfterHeader = $this->GetY();
        }

        // Bloc client (droite)
        $rightW = 90;
        $rightX = $this->w - $this->rMargin - $rightW;
        $yClientStart = max(60, $yAfterHeader + 4);

        $this->SetFont('DejaVu','',7);
        $this->SetXY($rightX,$yClientStart);

        foreach (['client_nom','client_adresse','client_cp','client_ville','client_tel','client_email'] as $k) {
            if (!empty($this->headerMeta[$k])) {
                $this->SetX($rightX);
                $this->MultiCell($rightW,4,$this->headerMeta[$k],0,'L');
            }
        }
    }

    function Footer() {

        $this->SetFont('DejaVu','',6);
        $this->SetY(-38);
    $rightBoxW = 90;
    $this->SetX($this->w - $this->rMargin - $rightBoxW);
    $footerLines = [];
    if (is_array($this->footerInfo)) {
      if (!empty($this->footerInfo['acompte']) && $this->footerInfo['acompte'] > 0) {
        $footerLines[] = "Acompte reçu : ".number_format((float)$this->footerInfo['acompte'],2,',',' ')." €";
      }
      if (!empty($this->footerInfo['planned_mode'])) {
        $line = "Mode de règlement prévu : ".$this->footerInfo['planned_mode'];
        if (!empty($this->footerInfo['planned_amount'])) {
          $line .= " — ".number_format((float)$this->footerInfo['planned_amount'],2,',',' ')." €";
        }
        $footerLines[] = $line;
      }
      if (!empty($this->footerInfo['note_acompte'])) { $footerLines[] = $this->footerInfo['note_acompte']; }
      if (!empty($this->footerInfo['refs']))         { $footerLines[] = $this->footerInfo['refs']; }
    }
    if ($footerLines) { $this->MultiCell($rightBoxW, 4, implode("\n", $footerLines), 0, 'R'); }

    // Lignes signature
    $this->SetY(-28);
    $this->SetX($this->lMargin);
    $this->SetFont('DejaVu','',7);
    $this->Cell(0,5,"Bon pour accord ..............., le ....... à .................",0,1,'L');
    $this->Cell(0,5,"Signature",0,1,'L');
    $this->Ln(1);

    // RIB (optionnel) — à brancher si tu récupères en DB
    // $this->SetFont('DejaVu','',6);
    // foreach ((array)$this->banks as $b) {
    //   if (!$b) continue;
    //   $rib = "RIB : IBAN {$b['iban']} • BIC {$b['bic']} • Banque {$b['banque']} • Titulaire {$b['titulaire']}";
    //   $this->SetTextColor(200, 0, 0);
    //   $this->Cell(0,5,$rib,0,1,'L');
    //   $this->SetTextColor(0, 0, 0);
    // }

    // Mentions
    $this->SetFont('DejaVu','',5);
    $txt = "Escompte pour règlement anticipé : 0 %\n"
         . "En cas de retard de paiement, pénalité = 3 × taux légal\n"
         . "(Décret 2009-138 du 9 février 2009)";
    $this->MultiCell(0,4,$txt,0,'L');

    // Identifiants légaux (bas à droite)
    $this->SetFont('DejaVu','',6);
    $this->SetY(-10);
    $this->SetX($this->lMargin);
    $this->Cell( 0, 5, 'Siret : 91531673100016 - RM : 915316731 - N° TVA intracom : FR00915316731', 0, 0, 'R' );
  }
  function TableHeader() {
	$this->SetX(10);
    $this->SetFont('DejaVu','',8);
    $this->SetFillColor(230,230,230);
    $this->Cell(90,7,'Description',1,0,'C',true);
    $this->Cell(20,7,'Qté',1,0,'C',true);
    $this->Cell(30,7,'PU HT',1,0,'C',true);
    $this->Cell(20,7,'TVA',1,0,'C',true);
    $this->Cell(30,7,'TTC',1,1,'C',true);
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
          $buf=''; $len=mb_strlen($word,'UTF-8');
          for($i=0;$i<$len;$i++){
            $c=mb_substr($word,$i,1,'UTF-8');
            if ($this->GetStringWidth($buf.$c) <= $w) $buf.=$c;
            else { $res[]=$buf; $buf=$c; }
          }
          $line=$buf;
        } else $line=$word;
      }
    }
    if ($line!=='') $res[]=$line;
    $this->SetFont($fam,$sty,$sz);
    return $res;
  }
  function RowDescription($name, $desc, $qty, $pu, $tva, $ttc) {
    
	$x0 = 10; 
	$this->SetX($x0);
 
	$y0 = $this->GetY();
    $wDesc=90; $wQty=20; $wPU=30; $wTVA=20; $wTTC=30; $pad=2; $wDescIn=$wDesc-2*$pad;
    $nameChunks = $this->WrapText($wDescIn, (string)$name, 'DejaVu','B',8);
    $descChunks = $this->WrapText($wDescIn, (string)$desc, 'DejaVu','',7);
    $nameLines = max(1, count($nameChunks));
    $descLines = max(1, count($descChunks));
    $hRow = max(($nameLines + $descLines) * 4 + 2*$pad, 7);

            if ($y0 + $hRow > ($this->h - $this->bMargin)) {
                $this->AddPage(); $this->TableHeader();                
				$x0 = 10;
				$y0 = $this->GetY();
				$this->SetX($x0);

            }
			
    $this->Rect($x0, $y0, $wDesc, $hRow);
    $this->Rect($x0 + $wDesc, $y0, $wQty, $hRow);
    $this->Rect($x0 + $wDesc + $wQty, $y0, $wPU, $hRow);
    $this->Rect($x0 + $wDesc + $wQty + $wPU, $y0, $wTVA, $hRow);
    $this->Rect($x0 + $wDesc + $wQty + $wPU + $wTVA, $y0, $wTTC, $hRow);

    $this->SetXY($x0 + $pad, $y0 + $pad);
    $this->SetFont('DejaVu','B',8);
    foreach ($nameChunks as $ln) { $this->Cell($wDescIn, 4, $ln, 0, 1, 'L'); $this->SetX($x0 + $pad); }
    $this->SetFont('DejaVu','',7);
    foreach ($descChunks as $ln) { $this->Cell($wDescIn, 4, $ln, 0, 1, 'L'); $this->SetX($x0 + $pad); }

    $this->SetFont('DejaVu','',8);
    $this->SetXY($x0 + $wDesc, $y0);
    $this->Cell($wQty, $hRow, (string)$qty, 0, 0, 'C');
    $this->Cell($wPU,  $hRow, number_format((float)$pu, 2, ',', ' ').' €', 0, 0, 'R');
    $this->Cell($wTVA, $hRow, number_format((float)$tva, 0, ',', ' ').' %', 0, 0, 'C');
    $this->Cell($wTTC, $hRow, number_format((float)$ttc, 2, ',', ' ').' €', 0, 1, 'R');

    $this->SetXY($x0, $y0 + $hRow);
  }
}

// Prépare meta header/footer
$headerMeta = [
  'numero'           => $numero_facture,
  'date'             => $date_facture,
  'date_prestation'  => ($date_prestation ?: null),
  'client_nom'       => trim(($client['prenom']??'').' '.($client['nom']??'')),
  'client_adresse'   => (string)($client['adresse'] ?? ''),
  'client_cp'        => (string)($client['code_postal'] ?? ''),
  'client_ville'     => (string)($client['ville'] ?? ''),
  'client_tel'       => (string)($client['telephone'] ?? ''),
  'client_email'     => (string)($client['email'] ?? ''),
];

$refs = "Réf. devis : ".($devis['numero'] ?? ('#'.(int)$devis['id']));
if ($bdc && !empty($bdc['numero'])) { $refs .= " — Réf. BDC : ".$bdc['numero']; }

$banks = []; // si tu veux afficher des RIB, alimente $banks ici depuis ta table
$footerInfo = [
  'planned_mode'   => $devis['mode_paiement'] ?? null,
  'planned_amount' => null,
  'acompte'        => $acompte,
  'note_acompte'   => null,
  'refs'           => $refs,
];

// PDF
$pdf = new PDF_FactureStyleDevis();
$pdf->setMeta($banks, $footerInfo, $headerMeta);
// Réserve footer identique au BDC
$pdf->SetAutoPageBreak(true, 60);

$pdf->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf', true);
$pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);
$pdf->SetFont('DejaVu','',9);

$pdf->AddPage();

if ($pdf->GetY() < 48) { $pdf->SetY(48); }

// Bandeau références (comme PJ)
$pdf->SetFont('DejaVu','',8);
$refLine = "Référence devis : ".($devis['numero'] ?? ('#'.(int)$devis['id']));
if ($bdc && !empty($bdc['numero'])) { $refLine .= " — BDC : ".$bdc['numero']; }
if (!empty($devis['date_creation'])) { $refLine .= " — Date devis : ".date('d/m/Y', strtotime($devis['date_creation'])); }
if (!empty($date_prestation))       { $refLine .= " — Prestation : ".date('d/m/Y', strtotime($date_prestation)); }
$pdf->Cell(0,6,$refLine,0,1,'L');

// Description installation (si présente)
if (!empty($devis['description'])) {
  $pdf->SetFont('DejaVu', '', 9);
  $pdf->Cell(0,6,"Description de l'installation :",0,1);
  $pdf->SetFont('DejaVu', 'B', 8);
  $pdf->MultiCell(0,6,(string)$devis['description']);
  $pdf->Ln(3);
}

// Table header
$pdf->TableHeader();

// Groupement par pièce
$pieceOrder=[]; $pieceTotals=[]; $grouped=[];
foreach ($lignes as $m) {
  $k = (string)$m['piece_key']; $n = (string)$m['piece_nom'];
  if (!array_key_exists($k, $pieceOrder)) $pieceOrder[$k] = count($pieceOrder);
  if (!isset($pieceTotals[$k])) $pieceTotals[$k] = ['nom'=>$n ?: 'Sans pièce','ht'=>0.0,'ttc'=>0.0];
  $pieceTotals[$k]['ht']  += (float)$m['total_ht'];
  $pieceTotals[$k]['ttc'] += (float)$m['total_ttc'];
}
foreach (array_keys($pieceOrder) as $k) $grouped[$k] = [];
foreach ($lignes as $m) { $grouped[$m['piece_key']][] = $m; }

// Sections
foreach (array_keys($pieceOrder) as $k) {
  $pieceName = ($pieceTotals[$k]['nom'] ?? 'Sans pièce');
  $pdf->SetFont('DejaVu','B',10);
  $pdf->SetFillColor(240,240,240);
  $pdf->Cell(90+20+30+20+30, 8, (string)$pieceName, 1, 1, 'L', true);
  $pdf->TableHeader();

  foreach ($grouped[$k] as $m) {
    $lib = (string)$m['libelle'];
    $desc= (string)($m['_desc'] ?? '');
    $qty = (int)$m['quantite'];
    $pu  = (float)$m['prix_unitaire'];
    $tva = (float)$m['tva_taux'];
    $ttc = (float)$m['total_ttc'];
    $pdf->RowDescription($lib, $desc, $qty, $pu, $tva, $ttc);
  }
  $pdf->Ln(4);
}

// Totaux
$pdf->Ln(2);
$pdf->Cell(0, 0, '', 'T'); $pdf->Ln(2);
$line = function($label, $value) use ($pdf){
  $pdf->SetFont('DejaVu', '', 8);
  $pdf->Cell(140,6,$label,0,0,'R');
  $pdf->Cell(30,6,number_format((float)$value,2,',',' ').' €',0,1,'R');
};
$line('Total HT', $total_ht);
$line('Total TVA', $total_tva);
$line('Total TTC', $total_ttc);
if ($acompte > 0) $line('Acompte payé', -$acompte);
$line('Reste à payer', $net_a_payer);

// Sauvegarde PDF
$pdf->Output('F', $filepath_abs);

/* ---------- INSERT energia_factures ---------- */
try { if (!has_column($pdo, 'energia_factures','acompte'))        $pdo->exec("ALTER TABLE energia_factures ADD COLUMN acompte DECIMAL(10,2) NULL AFTER montant_ttc"); } catch(Throwable $e){}
try { if (!has_column($pdo, 'energia_factures','date_prestation')) $pdo->exec("ALTER TABLE energia_factures ADD COLUMN date_prestation DATE NULL AFTER date_creation"); } catch(Throwable $e){}

$hasDateCreation = has_column($pdo,'energia_factures','date_creation');
$hasDatePrest    = has_column($pdo,'energia_factures','date_prestation');

if ($hasDateCreation && $hasDatePrest) {
  $sql = 'INSERT INTO energia_factures
          (numero, client_id, description, montant_ht, montant_ttc, acompte, date_creation, date_prestation, date_echeance, mode_paiement, bank_account_id, fichier_pdf)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
  $params = [
    $numero_facture, $devis['client_id'], $devis['description'],
    $total_ht, $total_ttc, $acompte,
    $date_facture, ($date_prestation ?: null), null,
    ($devis['mode_paiement'] ?? null), ($devis['bank_account_id'] ?? null),
    $filepath_web
  ];
} elseif ($hasDateCreation && !$hasDatePrest) {
  $sql = 'INSERT INTO energia_factures
          (numero, client_id, description, montant_ht, montant_ttc, acompte, date_creation, date_echeance, mode_paiement, bank_account_id, fichier_pdf)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)';
  $params = [
    $numero_facture, $devis['client_id'], $devis['description'],
    $total_ht, $total_ttc, $acompte,
    $date_facture, null, ($devis['mode_paiement'] ?? null), ($devis['bank_account_id'] ?? null),
    $filepath_web
  ];
} else {
  $sql = 'INSERT INTO energia_factures
          (numero, client_id, description, montant_ht, montant_ttc, acompte, date_echeance, mode_paiement, bank_account_id, fichier_pdf)
          VALUES (?,?,?,?,?,?,?,?,?,?)';
  $params = [
    $numero_facture, $devis['client_id'], $devis['description'],
    $total_ht, $total_ttc, $acompte,
    null, ($devis['mode_paiement'] ?? null), ($devis['bank_account_id'] ?? null),
    $filepath_web
  ];
}
$pdo->prepare($sql)->execute($params);

// Stream navigateur
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$filename.'"');
header('Content-Length: '.filesize($filepath_abs));
readfile($filepath_abs);
exit;