<?php
/***************************************************************
 *  traitement_devis.php — tFPDF (UTF-8) + enregistrement en BDD
 *  - Colonnes : Description | Qté | PU HT | TVA | TTC
 *  - 1re colonne = 2 étages : libellé (gras 8pt) + description (normal 7pt)
 *  - Calculs par ligne avec TVA 0 %, 10 % ou 20 % (mix possible)
 *  - Date de création affichée SANS HEURE, sous le N° (en-tête à droite)
 *  - DejaVu obligatoire (UTF-8)
 *  - ✅ Support des PIÈCES (Salon, Cuisine, …) :
 *      * Réceptionne piece_keys[] et pieces[<key>][nom] depuis le formulaire
 *      * Regroupe les lignes par pièce dans le PDF (⚠️ sans sous-totaux par pièce)
 *      * Enregistre piece_key / piece_nom dans devis_lignes (migration auto)
 ***************************************************************/

require 'auth.php';
require 'config.php';

// Chemin des polices pour tFPDF/FPDF
define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
require __DIR__ . '/tfpdf/tfpdf.php'; // tFPDF (TrueType, UTF-8)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/error_devis.log');
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
        error_log("[SHUTDOWN] {$e['message']} in {$e['file']}:{$e['line']}");
    }
});
set_exception_handler(function($ex){
    error_log("[EXCEPTION] ".$ex->getMessage()." @ ".$ex->getFile().":".$ex->getLine());
});

// ───── Méthode POST uniquement + CSRF ─────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Méthode non autorisée'); }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403); exit('CSRF invalide');
}

// ───── Récupération des champs ─────
$client_id       = $_POST['client_id']       ?? null;
$pac_ids         = $_POST['pac_ids']         ?? []; // peut contenir '' si ligne libre
$libelles        = $_POST['libelles']        ?? [];
$quantites       = $_POST['quantites']       ?? [];
$prix            = $_POST['prix']            ?? [];
$tva_taux_arr    = $_POST['tva_taux']        ?? []; // (0|10|20)
$description     = trim($_POST['description'] ?? '');
$date_echeance   = $_POST['date_echeance']   ?? null;

/* ==== NOUVEAU : pièces ==== */
$piece_keys      = $_POST['piece_keys']      ?? [];         // indexé comme les lignes
$pieces_post     = $_POST['pieces']          ?? [];         // ['p1'=>['nom'=>'Salon'], ...]
$DEFAULT_PIECE_KEY  = '_global';
$DEFAULT_PIECE_NAME = 'Sans pièce';

// Date de création (HTML: YYYY-MM-DDTHH:MM), compat: YYYY-MM-DDT00:00
$dc_raw = $_POST['date_creation'] ?? '';
if ($dc_raw === '' || $dc_raw === null) {
    $date_creation = date('Y-m-d H:i:s'); // défaut : maintenant
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dc_raw)) {
        http_response_code(400); exit('Format de date de création invalide.');
    }
    $date_creation = str_replace('T',' ', $dc_raw).':00'; // Y-m-d H:i:00
}

// Paiement UNIQUE
$mode1 = trim($_POST['mode_paiement_1'] ?? '');
$mont1 = (float)($_POST['montant_paiement_1'] ?? 0);
$ba1   = !empty($_POST['bank_account_id_1']) ? (int)$_POST['bank_account_id_1'] : null;

// ───── Validation minimale ─────
if (!$client_id || !$date_echeance) {
    http_response_code(400); exit('Formulaire incomplet.');
}
if ($mode1 === '') {
    http_response_code(400); exit('Un mode de règlement est requis.');
}

// ───── Infos client ─────
$stmt = $pdo->prepare('SELECT nom, prenom, adresse, code_postal, ville, telephone, email FROM clients WHERE id = ?');
$stmt->execute([(int)$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); exit('Client introuvable.'); }

// ───── Infos compte bancaire (facultatif) ─────
$bank1 = null;
if ($ba1) {
    $stmt = $pdo->prepare('SELECT nom_du_compte, titulaire, banque, iban, bic FROM bank_accounts WHERE id = ?');
    $stmt->execute([$ba1]);
    $bank1 = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ───── Helpers arrondis ───── */
function round2($n) { return round((float)$n + 1e-12, 2); }

/* ───── Lignes + totaux (arrondi par ligne, mix TVA 0/10/20) ───── */
$materiels = [];          // lignes à plat (avec piece_key/nom)
$pieceOrder = [];         // ordre d'apparition des pièces
$pieceTotals = [];        // key => ['nom'=>..., 'ht'=>..., 'ttc'=>...]

$total_ht  = 0.0;
$total_tva = 0.0;
$total_ttc = 0.0;

// Pour calcul global TTC : regrouper le HT par taux, puis appliquer la TVA par groupe
$htByRate = []; // ex: [0.0=>123.45, 10.0=>..., 20.0=>...]

// taille max des tableaux postés
$rowCount = max(
    count($prix),
    count($quantites),
    count($libelles),
    count($pac_ids),
    count($tva_taux_arr),
    count($piece_keys)
);

// map clé->nom provenant du POST
$pieceNameByKey = [];
if (is_array($pieces_post)) {
    foreach ($pieces_post as $k => $data) {
        $nm = '';
        if (is_array($data) && array_key_exists('nom', $data)) $nm = trim((string)$data['nom']);
        $pieceNameByKey[(string)$k] = ($nm !== '' ? $nm : $DEFAULT_PIECE_NAME);
    }
}

for ($i = 0; $i < $rowCount; $i++) {
    $pu   = isset($prix[$i])      ? (float)$prix[$i]      : 0.0;   // PU HT
    $qte  = isset($quantites[$i]) ? (int)$quantites[$i]   : 1;
    $lbl  = isset($libelles[$i])  ? trim((string)$libelles[$i]) : '';
    $pac  = isset($pac_ids[$i])   ? (int)$pac_ids[$i] : 0;

    // TVA acceptée : 0 / 10 / 20 ; toute autre valeur => 20
    $tvaRaw = $tva_taux_arr[$i] ?? 20;
    $tvaI   = is_numeric($tvaRaw) ? (float)$tvaRaw : 20.0;
    if (abs($tvaI - 0.0) < 0.001)      { $tvaI = 0.0; }
    elseif (abs($tvaI - 10.0) < 0.001) { $tvaI = 10.0; }
    else                                { $tvaI = 20.0; }

    // Pièce de la ligne
    $pKey = isset($piece_keys[$i]) ? trim((string)$piece_keys[$i]) : '';
    if ($pKey === '') $pKey = $DEFAULT_PIECE_KEY;
    $pName = $pieceNameByKey[$pKey] ?? $DEFAULT_PIECE_NAME;

    if ($pu <= 0) continue;           // ignore les lignes vides
    if ($qte <= 0) $qte = 1;

    // Récupère nom & description du catalogue si pac_id défini
    $desc_produit = '';
    if ($pac > 0) {
        $stmt = $pdo->prepare('SELECT nom, description FROM pompes_a_chaleur WHERE id = ?');
        $stmt->execute([$pac]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($lbl === '') $lbl = (string)$row['nom'];
            $desc_produit = (string)($row['description'] ?? '');
        }
    }
    if ($lbl === '') $lbl = 'Matériel';

    // === Calculs arrondis par ligne ===
    $ligne_ht  = round2($pu * $qte);
    $ligne_tva = round2($ligne_ht * ($tvaI / 100.0));
    $ligne_ttc = round2($ligne_ht + $ligne_tva);

    // Totaux pièce (indicatifs pour PDF, pas de sous-totaux imprimés)
    if (!array_key_exists($pKey, $pieceOrder)) {
        $pieceOrder[$pKey] = count($pieceOrder);
    }
    if (!isset($pieceTotals[$pKey])) {
        $pieceTotals[$pKey] = ['nom'=>$pName, 'ht'=>0.0, 'ttc'=>0.0];
    }
    $pieceTotals[$pKey]['ht']  = round2($pieceTotals[$pKey]['ht']  + $ligne_ht);
    $pieceTotals[$pKey]['ttc'] = round2($pieceTotals[$pKey]['ttc'] + $ligne_ttc);

    // Totaux globaux
    $total_ht = round2($total_ht + $ligne_ht);
    $rateKey  = (string)$tvaI; // clé "0", "10", "20"
    $htByRate[$rateKey] = round2(($htByRate[$rateKey] ?? 0.0) + $ligne_ht);

    $materiels[] = [
        'pac_id'     => ($pac > 0 ? $pac : null),
        'libelle'    => $lbl,
        'desc'       => $desc_produit,
        'quantite'   => $qte,
        'prix_ht'    => $pu,
        'tva_taux'   => $tvaI,
        'total_ht'   => $ligne_ht,
        'total_ttc'  => $ligne_ttc,
        'piece_key'  => $pKey,
        'piece_nom'  => $pName
    ];
}

if (!$materiels) { http_response_code(400); exit('Aucune ligne de matériel valide.'); }

// Calcul final TTC par regroupement de taux (aligné front)
$total_ttc = 0.0;
foreach ($htByRate as $rateStr => $ht) {
    $rate = (float)$rateStr;
    $total_ttc = round2($total_ttc + round2($ht * (1 + ($rate/100.0))));
}
$total_tva = round2($total_ttc - $total_ht);

// ───── Vérification du paiement UNIQUE (sur total TTC global) ─────
$mont1 = round2($mont1);
if (abs($mont1 - $total_ttc) > 0.01) {
    http_response_code(400); exit('Le montant du paiement ne correspond pas au total TTC calculé.');
}

// ───── Numéro de devis (année basée sur la date de création saisie) ─────
$annee  = date('Y', strtotime($date_creation));
$prefix = "DEV-$annee-";
$last   = $pdo->query("SELECT numero FROM devis WHERE numero LIKE ".$pdo->quote($prefix.'%')." ORDER BY id DESC LIMIT 1")->fetchColumn();
$seq    = $last ? (int)substr($last, -5) + 1 : 1;
$numero = $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);

// ───── Classe PDF ─────
class PDF_Devis extends tFPDF {
    protected $banks;
    protected $payments;
    protected $headerMeta;

    public function __construct($banks = null, $payments = null, $headerMeta = null) {
        parent::__construct();
        $this->banks      = $banks;
        $this->payments   = $payments;
        $this->headerMeta = $headerMeta;
        $this->SetMargins(10, 15, 10);
        $this->SetAutoPageBreak(true, 60);
    }

    function Header() {
        // Logo (ajuste si besoin)
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

        // Colonne droite réservée : Titre + Numéro + Date de création
        $rightColW = 60;
        $rightColX = $this->w - $this->rMargin - $rightColW;

        // Titre "DEVIS"
        $this->SetFont('DejaVu','',14);
        $this->SetXY($rightColX, 12);
        $this->Cell($rightColW, 8, 'DEVIS', 0, 1, 'R');

        // Numéro de devis
        $yAfterHeader = $this->GetY();
        if (!empty($this->headerMeta['numero_devis'])) {
            $this->SetFont('DejaVu','',10);
            $this->SetXY($rightColX, 20);
            $this->Cell($rightColW, 6, 'N° '.$this->headerMeta['numero_devis'], 0, 1, 'R');
            $yAfterHeader = max($yAfterHeader, $this->GetY());
        }

        // Date de création (SANS HEURE) juste sous le numéro
        if (!empty($this->headerMeta['date_creation'])) {
            $dcTxt = date('d/m/Y', strtotime($this->headerMeta['date_creation']));
            $this->SetFont('DejaVu','',9);
            $this->SetXY($rightColX, $this->GetY());
            $this->Cell($rightColW, 5, 'Date de création : '.$dcTxt, 0, 1, 'R');
            $yAfterHeader = max($yAfterHeader, $this->GetY());
        }

        // Bloc "infos client"
        $rightW = 90;
        $this->SetFont('DejaVu','',7);
        $yClientStart = max(28, $yAfterHeader + 4);
        $this->SetXY($this->w - $this->rMargin - $rightW, $yClientStart);

        if (is_array($this->headerMeta)) {
            $lines = [];
            if (!empty($this->headerMeta['client_nom']))      $lines[] = "Client : ".$this->headerMeta['client_nom'];
            if (!empty($this->headerMeta['client_adresse']))  $lines[] = "Adresse : ".$this->headerMeta['client_adresse'];
            $cpVille = trim(
                trim(($this->headerMeta['client_cp'] ?? '')) . ' ' .
                trim(($this->headerMeta['client_ville'] ?? ''))
            );
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
        // Paiement — en bas à droite
        $this->SetFont('DejaVu','',6);
        $this->SetY(-35);
        $rightBoxW = 90;
        $this->SetX($this->w - $this->rMargin - $rightBoxW);
        if (is_array($this->payments) && count($this->payments) > 0) {
            $p = $this->payments[0];
            $txt = "Règlement : {$p['mode']} — ".number_format((float)$p['montant'],2,',',' ')." €\n";
            $this->MultiCell($rightBoxW, 4, $txt, 0, 'R');
        }

        // "Bon pour accord ..." + Signature
        $this->SetY(-28);
        $this->SetX($this->lMargin);
        $this->SetFont('DejaVu','',7);
        $this->Cell(0,5,"Bon pour accord ..............., le ....... à .................",0,1,'L');
        $this->Cell(0,5,"Signature",0,1,'L');
        $this->Ln(1);

        // RIB
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

        // Mentions légales (gauche)
        $this->SetFont('DejaVu','',5);
        $txt = "Escompte pour règlement anticipé : 0 %\n"
             . "En cas de retard de paiement, pénalité = 3 × taux légal\n"
             . "(Décret 2009-138 du 9 février 2009)";
        $this->MultiCell(0,4,$txt,0,'L');

        // ── IDENTIFIANTS LÉGAUX EN BAS À DROITE (chaque page) ──
        $this->SetFont('DejaVu','',6);
        $this->SetY(-10); // 10 mm au-dessus du bas de page
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
        $h = 8;
        $w = 70+20+30+20+30; // largeur du tableau = 170
        if ($this->GetY() + $h > ($this->h - $this->bMargin)) {
            $this->AddPage();
        }
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
            if ($this->GetStringWidth($test) <= $w) {
                $line=$test;
            } else {
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
                } else {
                    $line=$word;
                }
            }
        }
        if ($line!=='') $res[]= $line;

        $this->SetFont($fam,$sty,$sz);
        return $res;
    }

    function RowDescription($name, $desc, $qty, $pu, $tva, $ttc) {
        $x0 = $this->GetX();
        $y0 = $this->GetY();

        $wDesc = 70; $wQty = 20; $wPU = 30; $wTVA = 20; $wTTC = 30;
        $pad = 2;
        $wDescIn = $wDesc - 2*$pad;

        $nameChunks = $this->WrapText($wDescIn, (string)$name, 'DejaVu','B',8);
        $descChunks = $this->WrapText($wDescIn, (string)$desc, 'DejaVu','',7);

        $nameLines = max(1, count($nameChunks));
        $descLines = max(1, count($descChunks));

        $hName = 4 * $nameLines;
        $hDesc = 4 * $descLines;
        $hRow  = max($hName + $hDesc + 2*$pad, 7);

        if ($y0 + $hRow > ($this->h - $this->bMargin)) {
            $this->AddPage();
            $this->TableHeader();
            $x0 = $this->GetX(); $y0 = $this->GetY();
        }

        $this->Rect($x0,                             $y0, $wDesc, $hRow);
        $this->Rect($x0 + $wDesc,                    $y0, $wQty,  $hRow);
        $this->Rect($x0 + $wDesc + $wQty,            $y0, $wPU,   $hRow);
        $this->Rect($x0 + $wDesc + $wQty + $wPU,     $y0, $wTVA,  $hRow);
        $this->Rect($x0 + $wDesc + $wQty + $wPU + $wTVA, $y0, $wTTC, $hRow);

        $this->SetXY($x0 + $pad, $y0 + $pad);

        $this->SetFont('DejaVu','B',8);
        foreach ($nameChunks as $ln) {
            $this->Cell($wDescIn, 4, $ln, 0, 1, 'L');
            $this->SetX($x0 + $pad);
        }

        $this->SetFont('DejaVu','',7);
        foreach ($descChunks as $ln) {
            $this->Cell($wDescIn, 4, $ln, 0, 1, 'L');
            $this->SetX($x0 + $pad);
        }

        $this->SetFont('DejaVu','',8);
        $this->SetXY($x0 + $wDesc, $y0);
        $this->Cell($wQty, $hRow, (string)$qty, 0, 0, 'C');
        $this->Cell($wPU,  $hRow, number_format((float)$pu,  2, ',', ' ').' €', 0, 0, 'R');
        $this->Cell($wTVA, $hRow, number_format((float)$tva, 0, ',', ' ').' %', 0, 0, 'C');
        $this->Cell($wTTC, $hRow, number_format((float)$ttc, 2, ',', ' ').' €', 0, 1, 'R');

        $this->SetXY($x0, $y0 + $hRow);
    }
}

// ───── Chemins des polices DejaVu (robustes) ─────
$baseFontDir = __DIR__ . '/tfpdf/font';
$ttfReg  = $baseFontDir . '/DejaVuSansCondensed.ttf';
$ttfBold = $baseFontDir . '/DejaVuSansCondensed-Bold.ttf';

// fallback : certains packs tFPDF mettent les TTF dans un sous-dossier "unifont"
if (!is_file($ttfReg) || !is_file($ttfBold)) {
    $unifontDir = $baseFontDir . '/unifont';
    $tryReg  = $unifontDir . '/DejaVuSansCondensed.ttf';
    $tryBold = $unifontDir . '/DejaVuSansCondensed-Bold.ttf';
    if (is_file($tryReg) && is_file($tryBold)) {
        $ttfReg  = $tryReg;
        $ttfBold = $tryBold;
    }
}

// message clair si manquant
if (!is_file($ttfReg) || !is_file($ttfBold)) {
    http_response_code(500);
    exit("Police DejaVu manquante. Placez les fichiers :
- tfpdf/font/DejaVuSansCondensed.ttf
- tfpdf/font/DejaVuSansCondensed-Bold.ttf
ou dans tfpdf/font/unifont/DejaVuSansCondensed*.ttf");
}

// ───── Instanciation + polices ─────
$banks = [];
if ($bank1) $banks[] = $bank1;

$payments = [];
if ($mode1 !== '' && $mont1 > 0) $payments[] = ['mode'=>$mode1, 'montant'=>$mont1];

$headerMeta = [
    'client_nom'     => trim(($client['prenom'] ?? '').' '.($client['nom'] ?? '')),
    'client_adresse' => (string)($client['adresse'] ?? ''),
    'client_cp'      => (string)($client['code_postal'] ?? ''),
    'client_ville'   => (string)($client['ville'] ?? ''),
    'client_tel'     => (string)($client['telephone'] ?? ''),
    'client_email'   => (string)($client['email'] ?? ''),
    'numero_devis'   => $numero,
    'date_creation'  => $date_creation,
];

$pdf = new PDF_Devis($banks, $payments, $headerMeta);

// IMPORTANT : passer uniquement le nom du .ttf, pas un chemin
$pdf->AddFont('DejaVu', '',  'DejaVuSansCondensed.ttf', true);
$pdf->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
$pdf->SetFont('DejaVu', '', 9);

$pdf->AddPage();
if ($pdf->GetY() < 48) { $pdf->SetY(48); }

/* === Bloc DATES (uniquement l'échéance côté corps) === */
$dt_echeance_txt = date('d/m/Y', strtotime($date_echeance));
$pdf->SetFont('DejaVu','',8);
$pdf->Cell(0,6,"Date d’échéance : $dt_echeance_txt",0,1,'L');
$pdf->Ln(1);
/* =================== */

// Description d'installation (TEXTE EN GRAS)
$pdf->SetFont('DejaVu', '', 9);
$pdf->Cell(0,6,"Description de l'installation :",0,1);
$pdf->SetFont('DejaVu', 'B', 8);
$pdf->MultiCell(0,6,$description);
$pdf->Ln(3);

/* === Impression par PIÈCE === */
$wTable = 70+20+30+20+30;

// Regrouper $materiels par piece_key en respectant l'ordre d'apparition
$grouped = [];
$orderedKeys = array_keys($pieceOrder);
foreach ($orderedKeys as $k) $grouped[$k] = [];

foreach ($materiels as $m) {
    $grouped[$m['piece_key']][] = $m;
}

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
            $m['tva_taux'],
            $m['total_ttc']
        );
    }

    // (Sous-totaux par pièce retirés)
    $pdf->Ln(5);
}

/* === Totaux globaux (mix de TVA) === */
$pdf->Ln(2);
$pdf->Cell(0, 0, '', 'T');
$pdf->Ln(2);
$__totalRow = function($pdf, $label, $value) {
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell(140,6,$label,0,0,'R');
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->Cell(30,6,number_format($value,2,',',' ').' €',0,1,'R');
};
$__totalRow($pdf, 'Total HT',  $total_ht);
$__totalRow($pdf, 'Total TVA', $total_tva);
$__totalRow($pdf, 'Total TTC', $total_ttc);
$__totalRow($pdf, 'Net à payer', $total_ttc);

/* === Sauvegarde du PDF === */
$dirPdf = __DIR__ . '/devis_pdf';
if (!is_dir($dirPdf)) mkdir($dirPdf,0775,true);
$date_stamp  = date('Ymd_His');
$client_slug = preg_replace('/\s+/', '_', $client['nom']);
$filename    = "devis_{$client_slug}_{$date_stamp}.pdf";
$filepath    = "$dirPdf/$filename";
$pdf->Output('F', $filepath);

/* === Enregistrement en BDD (transaction) === */

// Ajoute automatiquement les colonnes pièce si absentes
$hasPieceCols = false;
try {
    $col1 = $pdo->query("SHOW COLUMNS FROM devis_lignes LIKE 'piece_key'")->rowCount() > 0;
    $col2 = $pdo->query("SHOW COLUMNS FROM devis_lignes LIKE 'piece_nom'")->rowCount() > 0;
    $hasPieceCols = ($col1 && $col2);
    if (!$hasPieceCols && $pdo->query("SHOW TABLES LIKE 'devis_lignes'")->rowCount() > 0) {
        $pdo->exec("ALTER TABLE devis_lignes ADD COLUMN piece_key VARCHAR(32) NULL AFTER total_ttc");
        $pdo->exec("ALTER TABLE devis_lignes ADD COLUMN piece_nom  VARCHAR(255) NULL AFTER piece_key");
        $hasPieceCols = true;
    }
} catch (Throwable $e) {
    // On n'arrête pas la génération si la migration échoue ; on enregistrera sans les colonnes pièce.
    error_log('[MIGRATION devis_lignes] '.$e->getMessage());
}

try {
    $pdo->beginTransaction();

    $stmtDevis = $pdo->prepare(
        'INSERT INTO devis
           (client_id, numero, description, montant_ht, montant_ttc,
            date_echeance, mode_paiement, bank_account_id, fichier_pdf, date_creation)
         VALUES (:client_id, :numero, :description, :montant_ht, :montant_ttc,
                 :date_echeance, :mode_paiement, :bank_account_id, :fichier_pdf, :date_creation)'
    );
    $stmtDevis->execute([
        ':client_id'       => $client_id,
        ':numero'          => $numero,
        ':description'     => $description,
        ':montant_ht'      => $total_ht,
        ':montant_ttc'     => $total_ttc,
        ':date_echeance'   => $date_echeance,
        ':mode_paiement'   => $mode1,
        ':bank_account_id' => ($ba1 ?: null),
        ':fichier_pdf'     => $filepath,
        ':date_creation'   => $date_creation,
    ]);
    $devis_id = (int)$pdo->lastInsertId();

    if ($pdo->query("SHOW TABLES LIKE 'devis_lignes'")->rowCount() > 0) {
        if ($hasPieceCols) {
            $stmtLigne = $pdo->prepare(
                'INSERT INTO devis_lignes
                   (devis_id, pac_id, libelle, quantite, prix_unitaire, tva_taux, total_ht, total_ttc, piece_key, piece_nom)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($materiels as $m) {
                $stmtLigne->execute([
                    $devis_id,
                    $m['pac_id'] ?: null,
                    $m['libelle'],
                    $m['quantite'],
                    $m['prix_ht'],
                    $m['tva_taux'],
                    $m['total_ht'],
                    $m['total_ttc'],
                    $m['piece_key'],
                    $m['piece_nom'],
                ]);
            }
        } else {
            // fallback sans colonnes pièce
            $stmtLigne = $pdo->prepare(
                'INSERT INTO devis_lignes
                   (devis_id, pac_id, libelle, quantite, prix_unitaire, tva_taux, total_ht, total_ttc)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($materiels as $m) {
                $stmtLigne->execute([
                    $devis_id,
                    $m['pac_id'] ?: null,
                    $m['libelle'],
                    $m['quantite'],
                    $m['prix_ht'],
                    $m['tva_taux'],
                    $m['total_ht'],
                    $m['total_ttc'],
                ]);
            }
        }
    }

    if ($pdo->query("SHOW TABLES LIKE 'devis_paiements'")->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devis_paiements (
              id INT AUTO_INCREMENT PRIMARY KEY,
              devis_id INT NOT NULL,
              ordre TINYINT NOT NULL,
              mode VARCHAR(50) NOT NULL,
              montant DECIMAL(10,2) NOT NULL,
              bank_account_id INT NULL,
              CONSTRAINT fk_dp_devis FOREIGN KEY (devis_id) REFERENCES devis(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    $insPay = $pdo->prepare('INSERT INTO devis_paiements (devis_id, ordre, mode, montant, bank_account_id) VALUES (?,?,?,?,?)');
    if ($mode1 !== '' && $mont1 > 0) { $insPay->execute([$devis_id, 1, $mode1, $mont1, $ba1]); }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('Erreur enregistrement devis : '.$e->getMessage());
}

/* ──────────────────────────────────────────────────────────────
   Pose un drapeau dans localStorage puis redirige vers le PDF.
   ────────────────────────────────────────────────────────────── */
$pdfPublicUrl = 'devis_pdf/' . rawurlencode($filename);
if (ob_get_length()) { @ob_end_clean(); }

?><!doctype html>
<meta charset="utf-8">
<title>Devis créé</title>
<script>
try { localStorage.setItem('devis_created','1'); } catch(e) {}
location.replace(<?= json_encode($pdfPublicUrl) ?>);
</script>
<noscript>
  Devis créé. <a href="<?= htmlspecialchars($pdfPublicUrl) ?>">Ouvrir le PDF</a>.
</noscript>
<?php
exit;
