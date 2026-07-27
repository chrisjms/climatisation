<?php
/***************************************************************
 * Génération de FACTURE depuis un devis OU un bon de commande
 * - Ajout: Date de prestation (intervention) → PDF + BDD (colonne date_prestation)
 * - Rendu PDF identique aux devis (tFPDF + DejaVu UTF-8)
 * - Source: ?src=devis&id=123  ou  ?src=bdc&id=45
 * - Si src=bdc: on récupère le BDC puis son devis associé
 * - Regroupement par PIÈCES (piece_key/piece_nom) — ⚠️ sans sous-totaux par pièce
 * - Formulaire: Date de facture + Date de prestation + Acompte (pré-rempli si BDC)
 * - Totaux: HT, TVA (mix), TTC, Acompte (si >0), Net à payer
 * - BDD: crée les colonnes 'factures.acompte' et 'factures.date_prestation' si absentes
 * - Pied de page: affiche la ligne légale (Siret/RM/TVA) en bas à droite
 ***************************************************************/
require 'auth.php';
require 'config.php';
require __DIR__ . '/inc/helpers.php';

// Garde-fou anti-doublon sur la numérotation des factures
ensure_unique_index($pdo, 'factures', 'numero');

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/php-error.log');
set_error_handler(function($errno,$errstr,$errfile,$errline){
  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
require __DIR__ . '/tfpdf/tfpdf.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function abort_with($msg){
    http_response_code(400);
    echo "<p style='font-family:Arial,sans-serif;padding:20px;color:#c62828'>".htmlspecialchars($msg)."</p>";
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
    // Accepte "1234.56" ou "1 234,56"
    $s = (string)$s;
    if ($s === '') return 0.0;
    $s = str_replace(["\xC2\xA0", "\u{00A0}", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    return (float)$s;
}

/* ───────────────── Helpers chargement source ───────────────── */
function load_source(PDO $pdo, string $src, int $id): array {
    // Retourne ['devis'=>..., 'bdc'=>null|..., 'client'=>..., 'acompte_suggere'=>float]
    if ($src === 'bdc') {
        if (!table_exists($pdo, 'bons_de_commande')) abort_with("Table bons_de_commande absente.");
        $st = $pdo->prepare("SELECT * FROM bons_de_commande WHERE id = ?");
        $st->execute([$id]);
        $bdc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$bdc) abort_with("Bon de commande introuvable.");
        $dvId = (int)($bdc['devis_id'] ?? 0);
        if ($dvId <= 0) abort_with("Bon de commande sans devis associé.");

        $st = $pdo->prepare("SELECT * FROM devis WHERE id = ?");
        $st->execute([$dvId]);
        $devis = $st->fetch(PDO::FETCH_ASSOC);
        if (!$devis) abort_with("Devis introuvable (rattaché au BDC).");

        $st = $pdo->prepare("SELECT nom, prenom, adresse, code_postal, ville, telephone, email FROM clients WHERE id = ?");
        $st->execute([$devis['client_id']]);
        $client = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom'=>'Inconnu','prenom'=>'','adresse'=>'','code_postal'=>'','ville'=>'','telephone'=>'','email'=>''];

        $acompte_suggere = isset($bdc['acompte_montant']) ? (float)$bdc['acompte_montant'] : 0.0;

        return ['devis'=>$devis, 'bdc'=>$bdc, 'client'=>$client, 'acompte_suggere'=>$acompte_suggere];
    }

    // src=devis (par défaut)
    $st = $pdo->prepare("SELECT * FROM devis WHERE id = ?");
    $st->execute([$id]);
    $devis = $st->fetch(PDO::FETCH_ASSOC);
    if (!$devis) abort_with("Devis introuvable.");

    $st = $pdo->prepare("SELECT nom, prenom, adresse, code_postal, ville, telephone, email FROM clients WHERE id = ?");
    $st->execute([$devis['client_id']]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom'=>'Inconnu','prenom'=>'','adresse'=>'','code_postal'=>'','ville'=>'','telephone'=>'','email'=>''];

    return ['devis'=>$devis, 'bdc'=>null, 'client'=>$client, 'acompte_suggere'=>0.0];
}

function compute_totals_from_devis(PDO $pdo, int $devis_id): array {
    $stmt = $pdo->prepare("SELECT total_ht, total_ttc FROM devis_lignes WHERE devis_id = ?");
    $stmt->execute([$devis_id]);
    $total_ht = 0.0; $total_ttc = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $total_ht  += (float)$l['total_ht'];
        $total_ttc += (float)$l['total_ttc'];
    }
    return [$total_ht, $total_ttc];
}

/* ───────────────── GET : mini-formulaire ───────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $src = isset($_GET['src']) ? strtolower(trim($_GET['src'])) : 'devis';
    if ($src !== 'devis' && $src !== 'bdc') $src = 'devis';

    if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) abort_with(($src==='bdc'?'ID de BDC':'ID de devis').' manquant ou invalide.');
    $id = (int)$_GET['id'];

    $ctx = load_source($pdo, $src, $id);
	[$total_ht, $total_ttc] = compute_totals_from_devis($pdo, (int)$ctx['devis']['id']);


    $client_full = htmlspecialchars(trim(($ctx['client']['prenom']??'').' '.($ctx['client']['nom']??'')));
    $title_src   = $src === 'bdc'
        ? 'depuis le BDC '.htmlspecialchars($ctx['bdc']['numero'] ?? ('#'.$id)).' (devis '.htmlspecialchars($ctx['devis']['numero'] ?? '#?').')'
        : 'depuis le devis '.htmlspecialchars($ctx['devis']['numero'] ?? ('#'.$id));
    $prefill_acompte = $src==='bdc' ? number_format((float)$ctx['acompte_suggere'], 2, ',', ' ') : '';

    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <title>Générer la facture</title>
        <style>
            :root { color-scheme: light dark; }
            body{font-family:Arial, sans-serif; background:#fafafa; margin:0; padding:24px;}
            .card{max-width:680px;margin:0 auto;background:#fff;border:1px solid #eaeaea;border-radius:12px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.06)}
            h2{margin:0 0 12px 0}
            .muted{color:#666}
            label{display:block;margin-top:12px;font-weight:bold}
            input[type="date"],input[type="number"],input[type="text"],button{width:100%;padding:10px;box-sizing:border-box}
            input[type="number"]{appearance:textfield}
            input[type="number"]::-webkit-outer-spin-button,input[type="number"]::-webkit-inner-spin-button{appearance:none;margin:0}
            .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
            .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#f5f5f5;border:1px solid #eee}
            button{margin-top:16px;background:#2e7d32;color:#fff;border:none;border-radius:10px;cursor:pointer}
            button:hover{background:#1b5e20}
            small{color:#666}
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Générer la facture — <small><?= $title_src ?></small></h2>
            <p class="muted">
                Client&nbsp;: <strong><?= $client_full ?></strong><br>
                Total TTC (calculé sur les lignes du devis)&nbsp;:
                <span class="pill"><strong><?= number_format($total_ttc, 2, ',', ' ') ?> €</strong></span>
                <?php if ($src==='bdc' && (float)$ctx['acompte_suggere']>0): ?>
                    <br><span class="muted">Acompte demandé au BDC&nbsp;:</span>
                    <span class="pill"><strong><?= number_format((float)$ctx['acompte_suggere'],2,',',' ') ?> €</strong></span>
                <?php endif; ?>
            </p>

            <form method="POST" data-submit-once>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="src" value="<?= htmlspecialchars($src) ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">

                <label for="date_facture">Date de la facture</label>
                <input type="date" id="date_facture" name="date_facture" value="<?= date('Y-m-d') ?>" required>

                <label for="date_prestation">Date de prestation (intervention) <small>(affichée sur la facture)</small></label>
                <input type="date" id="date_prestation" name="date_prestation" value="<?= date('Y-m-d') ?>">

                <div class="row">
                    <div>
                        <label for="acompte">Montant d'acompte payé (optionnel)</label>
                        <input type="text" id="acompte" name="acompte" value="<?= htmlspecialchars($prefill_acompte) ?>" placeholder="ex. 500,00">
                        <small>Vous pouvez saisir "500,00" ou "500.00".</small>
                    </div>
                    <div>
                        <label for="note_acompte">Note (facultatif)</label>
                        <input type="text" id="note_acompte" name="note_acompte" placeholder="ex. Acompte par virement">
                        <small>Affichée dans le pied de page.</small>
                    </div>
                </div>

                <button type="submit">Créer la facture</button>
            </form>

            <p class="muted" style="margin-top:10px">
                La facture reprend l'affichage et la structure du devis (pièces, <u>sans sous-totaux par pièce</u>, etc.).<br>
                Source&nbsp;: <strong><?= $src==='bdc' ? 'Bon de commande' : 'Devis' ?></strong>.
            </p>
        </div>
        <script src="inc/submit-once.js"></script>
    </body>
    </html>
    <?php
    exit;
}

/* ───────────────── POST : générer la facture ───────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) abort_with('CSRF token invalide.');
    $src              = isset($_POST['src']) ? strtolower(trim($_POST['src'])) : 'devis';
    if ($src !== 'devis' && $src !== 'bdc') $src = 'devis';
    $id_input         = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    $date_facture     = trim($_POST['date_facture'] ?? '');
    $date_prestation  = trim($_POST['date_prestation'] ?? ''); // optionnelle
    $acompte_raw      = (string)($_POST['acompte'] ?? '');
    $acompte          = parse_money($acompte_raw);
    $note_acompte     = trim((string)($_POST['note_acompte'] ?? ''));

    if ($id_input <= 0) abort_with(($src==='bdc'?'BDC':'Devis').' invalide.');

    // Validation date : format ET calendrier réel (rejette 2026-13-45)
    $check_date = function (string $d, string $label) {
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m)) {
            abort_with("$label invalide (format attendu YYYY-MM-DD).");
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            abort_with("$label invalide ($d n'existe pas).");
        }
    };
    $check_date($date_facture, 'Date de facture');
    if ($date_prestation !== '') $check_date($date_prestation, 'Date de prestation');

    // Contexte (devis + éventuellement bdc + client)
    $ctx = load_source($pdo, $src, $id_input);
    $devis  = $ctx['devis'];
    $bdc    = $ctx['bdc'];
    $client = $ctx['client'];

    // Si l'utilisateur n'a rien saisi pour l'acompte et qu'on vient d'un BDC, utiliser l'acompte BDC
    if (trim($acompte_raw)==='' && $bdc && isset($bdc['acompte_montant'])) {
        $acompte = (float)$bdc['acompte_montant'];
    }

    // Banque (UN seul éventuel, depuis le devis)
    $banks = [];
    if (!empty($devis['bank_account_id'])) {
        $stmt = $pdo->prepare("SELECT nom_du_compte, titulaire, banque, iban, bic FROM bank_accounts WHERE id = ?");
        $stmt->execute([$devis['bank_account_id']]);
        $b1 = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($b1) $banks[] = $b1;
    }

    // Mode de règlement prévu (issu du devis)
    $payment_planned = ['mode' => null, 'montant' => null];
    if ($pdo->query("SHOW TABLES LIKE 'devis_paiements'")->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT mode, montant FROM devis_paiements WHERE devis_id = ? AND ordre = 1 LIMIT 1");
        $stmt->execute([(int)$devis['id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p && !empty($p['mode'])) {
            $payment_planned['mode']    = (string)$p['mode'];
            $payment_planned['montant'] = isset($p['montant']) ? (float)$p['montant'] : null;
        }
    } else {
        $payment_planned['mode']    = (string)($devis['mode_paiement'] ?? '');
        $payment_planned['montant'] = null;
    }

    // Lignes du devis (avec pièces si colonnes présentes)
    $hasPieceKey = has_column($pdo, 'devis_lignes', 'piece_key');
    $hasPieceNom = has_column($pdo, 'devis_lignes', 'piece_nom');
    $hasOffert   = has_column($pdo, 'devis_lignes', 'offert');
    $cols = "devis_id, pac_id, libelle, quantite, prix_unitaire, tva_taux, total_ht, total_ttc"
          . ($hasOffert   ? ", offert"    : ", 0 AS offert")
          . ($hasPieceKey ? ", piece_key" : "")
          . ($hasPieceNom ? ", piece_nom" : "");
    $stmt = $pdo->prepare("SELECT $cols FROM devis_lignes WHERE devis_id = ?");
    $stmt->execute([(int)$devis['id']]);
    $raw_lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$raw_lignes) abort_with("Aucune ligne trouvée pour ce devis.");

    // Enrichit description produit depuis le catalogue PAC
    $getDesc = $pdo->prepare('SELECT description, nom FROM pompes_a_chaleur WHERE id = ?');
    $lignes = [];
    foreach ($raw_lignes as $ln) {
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
        $ln['_desc'] = $desc;
        if (!$hasPieceKey) $ln['piece_key'] = '_global';
        if (!$hasPieceNom) $ln['piece_nom'] = 'Sans pièce';
        if (trim((string)$ln['piece_key']) === '') $ln['piece_key'] = '_global';
        if (trim((string)$ln['piece_nom']) === '') $ln['piece_nom'] = 'Sans pièce';
        $lignes[] = $ln;
    }

    // Totaux globaux (mix TVA)
    $total_ht = 0.0; $total_ttc = 0.0;
    foreach ($lignes as $l) {
        $total_ht  += (float)$l['total_ht'];
        $total_ttc += (float)$l['total_ttc'];
    }
    $total_tva = $total_ttc - $total_ht;

    // Valide acompte : on refuse explicitement plutôt que de clamper silencieusement.
    if ($acompte < 0) {
        abort_with("L'acompte ne peut pas être négatif.");
    }
    if ($acompte > $total_ttc + 0.01) {
        abort_with(sprintf(
            "L'acompte (%s €) dépasse le total TTC (%s €).",
            number_format($acompte, 2, ',', ' '),
            number_format($total_ttc, 2, ',', ' ')
        ));
    }
    $net_a_payer = max(0.0, $total_ttc - $acompte);

    /* ───── Polices DejaVu ───── */
    $fontDir = __DIR__ . '/tfpdf/font';
    $ttfReg  = $fontDir . '/DejaVuSansCondensed.ttf';
    $ttfBold = $fontDir . '/DejaVuSansCondensed-Bold.ttf';
    if (!is_file($ttfReg) || !is_file($ttfBold)) {
        http_response_code(500);
        exit("Police DejaVu manquante. Ajoutez les fichiers :
- tfpdf/font/DejaVuSansCondensed.ttf
- tfpdf/font/DejaVuSansCondensed-Bold.ttf");
    }

    /* ─────────────── PDF (classe style devis) ─────────────── */
    class PDF_FactureStyleDevis extends tFPDF {
        protected $banks;
        protected $footerInfo;
        protected $headerMeta;

        public function __construct($banks = null, $footerInfo = null, $headerMeta = null) {
            parent::__construct();
            $this->banks      = $banks;
            $this->footerInfo = $footerInfo; // ['planned_mode','planned_amount','acompte','note_acompte','refs']
            $this->headerMeta = $headerMeta;  // client + numero + date + prestation_date + refs
            $this->SetMargins(10, 15, 10);
            $this->SetAutoPageBreak(true, 60);
        }

        function Header() {
            $logo = __DIR__ . '/assets/logo.jpeg';
            if (file_exists($logo)) $this->Image($logo, 10, 8, 30);

            $this->SetXY(10, 10);
            $this->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf', true);
            $this->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);

            $this->SetFont('DejaVu','',12);
            $this->Cell(0,6,'COPROVEN',0,1,'C');

            $this->SetFont('DejaVu','',8);
            $this->Cell(0,5,'Électricité – Chauffage – Climatisation',0,1,'C');
            $this->Cell(0,5,'Chauffe-eau Solaire – Photovoltaïques',0,1,'C');
            $this->Ln(2);
            $this->SetFont('DejaVu','',7);
            $this->Cell(0,5,'6 allée des Dunes – 33470 Gujan-Mestras',0,1,'C');
            $this->Cell(0,5,'Tél. : 06 37 05 35 22 – coproven.climatisation@gmail.com',0,1,'C');

            // Colonne droite : Titre + Numéro + Date(s)
            $rightColW = 60;
            $rightColX = $this->w - $this->rMargin - $rightColW;

            $this->SetFont('DejaVu','',14);
            $this->SetXY($rightColX, 12);
            $this->Cell($rightColW, 8, 'FACTURE', 0, 1, 'R');

            $yAfterHeader = $this->GetY();
            if (!empty($this->headerMeta['numero'])) {
                $this->SetFont('DejaVu','',10);
                $this->SetXY($rightColX, 20);
                $this->Cell($rightColW, 6, 'N° '.$this->headerMeta['numero'], 0, 1, 'R');
                $yAfterHeader = max($yAfterHeader, $this->GetY());
            }
            if (!empty($this->headerMeta['date'])) {
                $dcTxt = date('d/m/Y', strtotime($this->headerMeta['date']));
                $this->SetFont('DejaVu','',9);
                $this->SetXY($rightColX, $this->GetY());
                $this->Cell($rightColW, 5, 'Date : '.$dcTxt, 0, 1, 'R');
                $yAfterHeader = max($yAfterHeader, $this->GetY());
            }
            if (!empty($this->headerMeta['prestation_date'])) {
                $dpTxt = date('d/m/Y', strtotime($this->headerMeta['prestation_date']));
                $this->SetFont('DejaVu','',9);
                $this->SetXY($rightColX, $this->GetY());
                $this->Cell($rightColW, 5, 'Prestation : '.$dpTxt, 0, 1, 'R');
                $yAfterHeader = max($yAfterHeader, $this->GetY());
            }

            // Bloc client à droite
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
                if (!empty($this->footerInfo['note_acompte'])) {
                    $footerLines[] = $this->footerInfo['note_acompte'];
                }
                if (!empty($this->footerInfo['refs'])) {
                    $footerLines[] = $this->footerInfo['refs']; // Références devis/BDC
                }
            }

            if ($footerLines) {
                $this->MultiCell($rightBoxW, 4, implode("\n", $footerLines), 0, 'R');
            }

            // Lignes signature
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

            // Mentions
            $this->SetFont('DejaVu','',5);
            $txt = "Escompte pour règlement anticipé : 0 %\n"
                 . "En cas de retard de paiement, pénalité = 3 × taux légal\n"
                 . "(Décret 2009-138 du 9 février 2009)";
            $this->MultiCell(0,4,$txt,0,'L');

            // ── IDENTIFIANTS LÉGAUX EN BAS À DROITE ──
            $this->SetFont('DejaVu','',6);
            $this->SetY(-10); // environ 10 mm du bas
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
            $w = 70+20+30+20+30; // 170
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
                        $buf=''; $len=mb_strlen($word,'UTF-8');
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
            if ($line!=='') $res[]=$line;
            $this->SetFont($fam,$sty,$sz);
            return $res;
        }

        function RowDescription($name, $desc, $qty, $pu, $tva, $ttc, $offert = false) {
            $x0 = $this->GetX();
            $y0 = $this->GetY();

            $wDesc = 70; $wQty = 20; $wPU = 30; $wTVA = 20; $wTTC = 30;
            $pad = 2;
            $wDescIn = $wDesc - 2*$pad;

            $nameChunks = $this->WrapText($wDescIn, (string)$name, 'DejaVu','B',8);
            $descChunks = $this->WrapText($wDescIn, (string)$desc, 'DejaVu','',7);

            $nameLines = max(1, count($nameChunks));
            $descLines = max(1, count($descChunks));

            $hRow  = max(($nameLines + $descLines) * 4 + 2*$pad, 7);

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
            if ($offert) {
                // Article offert : mention à la place des montants (la ligne vaut 0 €).
                $this->SetFont('DejaVu','B',8);
                $this->Cell($wPU,  $hRow, 'Offert', 0, 0, 'R');
                $this->SetFont('DejaVu','',8);
                $this->Cell($wTVA, $hRow, '—', 0, 0, 'C');
                $this->SetFont('DejaVu','B',8);
                $this->Cell($wTTC, $hRow, 'Offert', 0, 1, 'R');
                $this->SetFont('DejaVu','',8);
            } else {
                $this->Cell($wPU,  $hRow, number_format((float)$pu,  2, ',', ' ').' €', 0, 0, 'R');
                $this->Cell($wTVA, $hRow, number_format((float)$tva, 0, ',', ' ').' %', 0, 0, 'C');
                $this->Cell($wTTC, $hRow, number_format((float)$ttc, 2, ',', ' ').' €', 0, 1, 'R');
            }

            $this->SetXY($x0, $y0 + $hRow);
        }
    }

    // Métadonnées d'en-tête
    $headerMeta = [
        'client_nom'        => trim(($client['prenom'] ?? '').' '.($client['nom'] ?? '')),
        'client_adresse'    => (string)($client['adresse'] ?? ''),
        'client_cp'         => (string)($client['code_postal'] ?? ''),
        'client_ville'      => (string)($client['ville'] ?? ''),
        'client_tel'        => (string)($client['telephone'] ?? ''),
        'client_email'      => (string)($client['email'] ?? ''),
    ];

    // Numéro de facture (année basée sur la date facture)
    $annee = date('Y', strtotime($date_facture));
    $prefix = "FACT-$annee-";
    $last = $pdo->query("
        SELECT numero FROM factures
        WHERE numero LIKE ".$pdo->quote($prefix.'%')."
        ORDER BY id DESC
        LIMIT 1
    ")->fetchColumn();
    $seq = $last ? (int)substr($last, -5) + 1 : 1;
    $numero_facture = $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);

    $headerMeta['numero']          = $numero_facture;
    $headerMeta['date']            = $date_facture;
    $headerMeta['prestation_date'] = $date_prestation ?: null;

    // Références (affichées dans le pied si utile)
    $refs = "Réf. devis : ".($devis['numero'] ?? ('#'.(int)$devis['id']));
    if ($bdc && !empty($bdc['numero'])) {
        $refs .= " — Réf. BDC : ".$bdc['numero'];
    }

    // Footer info
    $footerInfo = [
        'planned_mode'   => $payment_planned['mode'] ?: null,
        'planned_amount' => $payment_planned['montant'] !== null ? (float)$payment_planned['montant'] : null,
        'acompte'        => $acompte,
        'note_acompte'   => $note_acompte,
        'refs'           => $refs,
    ];

    // PDF
    $pdf = new PDF_FactureStyleDevis($banks, $footerInfo, $headerMeta);
    $pdf->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf',      true);
    $pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);
    $pdf->SetFont('DejaVu','',9);
    $pdf->AddPage();
    if ($pdf->GetY() < 48) { $pdf->SetY(48); }

    // Bandeau références
    $pdf->SetFont('DejaVu','',8);
    $refLine = "Référence devis : ".($devis['numero'] ?? ('#'.(int)$devis['id']));
    if ($bdc && !empty($bdc['numero'])) { $refLine .= " — BDC : ".$bdc['numero']; }
    $dateDevTxt = !empty($devis['date_creation']) ? date('d/m/Y', strtotime($devis['date_creation'])) : '';
    if ($dateDevTxt !== '') $refLine .= " — Date devis : ".$dateDevTxt;
    if (!empty($date_prestation)) {
        $refLine .= " — Prestation : ".date('d/m/Y', strtotime($date_prestation));
    }
    $pdf->Cell(0,6,$refLine,0,1,'L');

    // Description installation
    if (!empty($devis['description'])) {
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell(0,6,"Description de l'installation :",0,1);
        $pdf->SetFont('DejaVu', 'B', 8);
        $pdf->MultiCell(0,6,(string)$devis['description']);
        $pdf->Ln(3);
    }

    // Impression par PIÈCE (⚠️ sans sous-totaux par pièce)
    $wTable = 70+20+30+20+30;

    // Construit ordre des pièces
    $pieceOrder = [];
    $pieceTotals = [];
    foreach ($lignes as $m) {
        $k = (string)$m['piece_key'];
        $n = (string)$m['piece_nom'];
        if (!array_key_exists($k, $pieceOrder)) {
            $pieceOrder[$k] = count($pieceOrder);
        }
        if (!isset($pieceTotals[$k])) {
            $pieceTotals[$k] = ['nom'=>$n ?: 'Sans pièce', 'ht'=>0.0, 'ttc'=>0.0];
        }
        $pieceTotals[$k]['ht']  += (float)$m['total_ht'];
        $pieceTotals[$k]['ttc'] += (float)$m['total_ttc'];
    }
    // Groupement
    $grouped = [];
    foreach (array_keys($pieceOrder) as $k) $grouped[$k] = [];
    foreach ($lignes as $m) { $grouped[$m['piece_key']][] = $m; }

    // Sections
    foreach (array_keys($pieceOrder) as $k) {
        $pieceName = ($pieceTotals[$k]['nom'] ?? 'Sans pièce');
        $pdf->SectionTitle($pieceName);
        $pdf->TableHeader();
        foreach ($grouped[$k] as $m) {
            $lib = (string)$m['libelle'];
            $desc= (string)($m['_desc'] ?? '');
            $qty = (int)$m['quantite'];
            $pu  = (float)$m['prix_unitaire'];
            $tva = (float)$m['tva_taux']; // 10 ou 20
            $ttc = (float)$m['total_ttc'];
            $pdf->RowDescription($lib, $desc, $qty, $pu, $tva, $ttc, !empty($m['offert']));
        }
        $pdf->Ln(4);
    }

    // Totaux globaux
    $pdf->Ln(2);
    $pdf->Cell(0, 0, '', 'T');
    $pdf->Ln(2);
    $row = function($label, $value) use ($pdf){
        $pdf->SetFont('DejaVu', '', 8);
        $pdf->Cell(140,6,$label,0,0,'R');
        $pdf->Cell(30,6,number_format((float)$value,2,',',' ').' €',0,1,'R');
    };
    $row('Total HT',  $total_ht);
    $row('Total TVA', $total_tva);
    $row('Total TTC', $total_ttc);
    if ($acompte > 0) {
        $row('Acompte payé', -$acompte);
    }
    $row('Reste à payer', $net_a_payer);

    // Sauvegarde PDF
    $dirPdf = __DIR__ . '/facture_pdf';
    if (!is_dir($dirPdf)) mkdir($dirPdf,0775,true);
    $date_stamp  = date('Ymd', strtotime($date_facture)).'_'.date('His');
    $client_slug = preg_replace('/\s+/', '_', trim($client['nom'] ?: 'client'));
    $filename    = "facture_{$client_slug}_{$date_stamp}.pdf";
    $filepath    = "$dirPdf/$filename";
    $pdf->Output('F', $filepath);

    // Assure la présence des colonnes 'acompte' et 'date_prestation' dans factures
    try {
        if (!has_column($pdo, 'factures', 'acompte')) {
            $pdo->exec("ALTER TABLE factures ADD COLUMN acompte DECIMAL(10,2) NULL AFTER montant_ttc");
        }
    } catch (\Throwable $e) {
        error_log('[FACTURES ALTER acompte] '.$e->getMessage());
    }
    try {
        if (!has_column($pdo, 'factures', 'date_prestation')) {
            $pdo->exec("ALTER TABLE factures ADD COLUMN date_prestation DATE NULL AFTER date_creation");
        }
    } catch (\Throwable $e) {
        error_log('[FACTURES ALTER date_prestation] '.$e->getMessage());
    }

    // Insertion DB (date_creation = date facture si la colonne existe)
    $hasDateCreation  = has_column($pdo, 'factures', 'date_creation');
    $hasDatePrest     = has_column($pdo, 'factures', 'date_prestation');

    if ($hasDateCreation && $hasDatePrest) {
        $sql = 'INSERT INTO factures
                (numero, client_id, description, montant_ht, montant_ttc, acompte,
                 date_creation, date_prestation, date_echeance, mode_paiement, bank_account_id, fichier_pdf)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
        $params = [
            $numero_facture, $devis['client_id'], $devis['description'], $total_ht, $total_ttc, $acompte,
            $date_facture, ($date_prestation ?: null), null, ($payment_planned['mode'] ?? null), ($devis['bank_account_id'] ?? null), $filepath
        ];
    } elseif ($hasDateCreation && !$hasDatePrest) {
        // fallback si la colonne n'a pas pu être créée
        $sql = 'INSERT INTO factures
                (numero, client_id, description, montant_ht, montant_ttc, acompte,
                 date_creation, date_echeance, mode_paiement, bank_account_id, fichier_pdf)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)';
        $params = [
            $numero_facture, $devis['client_id'], $devis['description'], $total_ht, $total_ttc, $acompte,
            $date_facture, null, ($payment_planned['mode'] ?? null), ($devis['bank_account_id'] ?? null), $filepath
        ];
    } else {
        // très vieux schéma sans date_creation
        $sql = 'INSERT INTO factures
                (numero, client_id, description, montant_ht, montant_ttc, acompte,
                 date_echeance, mode_paiement, bank_account_id, fichier_pdf)
                VALUES (?,?,?,?,?,?,?,?,?,?)';
        $params = [
            $numero_facture, $devis['client_id'], $devis['description'], $total_ht, $total_ttc, $acompte,
            null, ($payment_planned['mode'] ?? null), ($devis['bank_account_id'] ?? null), $filepath
        ];
    }
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        @unlink($filepath); // PDF orphelin si l'INSERT a échoué
        if (is_duplicate_key_error($e)) {
            abort_with('Conflit de numérotation (une autre facture a pris le même numéro). Veuillez réessayer.');
        }
        error_log('[generer_facture] '.$e->getMessage());
        abort_with('Erreur enregistrement facture. Réessayez ou consultez les logs.');
    }

    // Stream navigateur
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.$filename.'"');
    header('Content-Length: '.filesize($filepath));
    readfile($filepath);
    exit;
}

abort_with('Méthode non supportée.');
