<?php
declare(strict_types=1);
/* ==========================================================
 ENERGIA — DEVIS PDF LAYOUT (PIXEL-PERFECT) — vPage1FullWhenPage2
========================================================== */

require_once __DIR__ . '/tfpdf/tfpdf.php';
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', __DIR__ . '/tfpdf/font/');
}

/* ---------- Helpers ---------- */
function energia_money_fr(float $n): string { return number_format($n, 2, ',', ' '); }
function energia_date_fr(?string $s): string {
    if (!$s) return '';
    $t = strtotime($s);
    return $t ? date('d/m/Y', $t) : (string)$s;
}

/**
 * Ajoute un espace vertical dans la grille (ligne vide avec traits complets).
 * Retourne le nouveau Y.
 */
function energia_add_grid_gap(EnergiaPDF $pdf, array $colW, float $y, float $gapH): float {
    // Astuce : ' ' (espace) en Description pour forcer le tracé complet.
    return energia_row_multiline($pdf, $colW, ['', ' ', '', '', '', ''], $y, $gapH);
}

/**
 * Estime la hauteur verticale nécessaire pour UNE ligne d'article,
 * compte tenu de l'interligne description ($lineH), de l'éco-texte (ECO_LINE_H)
 * et d'un éventuel espace ajouté avant/après la ligne.
 */
function energia_estimate_row_height(
    EnergiaPDF $pdf,
    array $colW,
    string $desc,
    string $ecoText,
    float $lineH,
    float $ecoLineH,
    float $ecoOffset = 2.2,
    float $spaceBefore = 0.0,
    float $spaceAfter  = 0.0
): float {
    $nbDesc = max(1, (int)$pdf->NbLines($colW[1], $desc));
    $hDesc  = $nbDesc * $lineH;

    $hEco = 0.0;
    if (trim($ecoText) !== '') {
        $nbEco = max(1, (int)$pdf->NbLines($colW[1], $ecoText));
        // même logique que l'impression (écoStartY = descY + descH - ecoOffset)
        $hEco  = max(0.0, $nbEco * $ecoLineH - $ecoOffset);
    }

    return $spaceBefore + max($lineH, $hDesc + $hEco) + $spaceAfter;
}

/* ---------- Classe PDF ---------- */
class EnergiaPDF extends tFPDF {
    public string $footerLine = '';

    public function Header(): void { /* aucun header auto */ }

    public function Footer(): void {
        // Ligne au-dessus du footer
        $this->SetY(-18);
        $yLine = $this->GetY() - 1; // ≈ 278 en A4 portrait
        $this->SetDrawColor(160,160,160);
        $this->Line(10, $yLine, 200, $yLine);

        // Texte centre + pagination
        $this->SetY($yLine + 2);
        $this->SetFont('DejaVu','',7);
        $this->SetTextColor(0,0,0);
        if ($this->footerLine !== '') {
            $this->Cell(0, 4, $this->footerLine, 0, 1, 'C');
        }
        $this->Cell(0, 4, $this->PageNo().' sur {nb}', 0, 0, 'R');
    }

    // Nb lignes d'une MultiCell (estimation hauteur)
    public function NbLines($w, $txt) {
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;

        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb-1] == "\n") $nb--;

        $sep = -1; $i=0; $j=0; $l=0; $nl=1;

        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++; $sep=-1; $j=$i; $l=0; $nl++; continue;
            }
            if ($c == ' ') { $sep = $i; }
            $l += 200;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
                } else {
                    $i = $sep + 1;
                }
                $sep = -1; $j=$i; $l=0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

/* ---------- Dessins grille ---------- */
// Encadré "Devis"
function energia_draw_box_title(EnergiaPDF $pdf, string $title='Devis'): void {
    $pdf->SetFont('DejaVu','B',16);
    $pdf->SetFillColor(220,220,220);
    $pdf->SetDrawColor(160,160,160);
    $pdf->Rect(175, 8, 25, 10, 'DF');
    $pdf->SetXY(175,8);
    $pdf->Cell(25,10,$title,0,0,'C');
}

// Entête entreprise + logo
function energia_header_company(EnergiaPDF $pdf, string $logoPath): void {
    if (is_file($logoPath)) $pdf->Image($logoPath, 10, 8, 110, 16); // 110 x 16 mm confirmés
    $pdf->SetXY(9, 26);
    $pdf->SetFont('DejaVu','B',11);
    $pdf->Cell(0,5,"ENERGIA Aire Acondicionado - SL",0,1,'L');
    $pdf->SetFont('DejaVu','',7);
    foreach ([
        "Electricité - Chauffage - Climatisation",
        "Chauffe eau Solaire - Photovoltaique",
        "Avda Ricardo Soriano 72",
        "29601 MARBELLA - ESPANA",
        "Tél : 06.10.26.83.34",
        "Site web : www.energia-marbella.com",
        "Email : energia.aireacondicionado29@gmail.com",
    ] as $l) { $pdf->SetX(9); $pdf->Cell(0,3.5,$l,0,1,'L'); }
}

// Bloc client
function energia_header_client(EnergiaPDF $pdf, string $nom, string $adresse, string $cpVille): void {
    $x=120; $y=35; $w=65;
    $pdf->SetXY($x,$y);
    if ($nom!=='')    { $pdf->SetFont('DejaVu','',10); $pdf->Cell($w,4,$nom,0,1,'L'); }
    if ($adresse!==''){ $pdf->SetFont('DejaVu','',9);  $pdf->SetX($x); $pdf->Cell($w,4,$adresse,0,1,'L'); }
    if ($cpVille!==''){ $pdf->SetX($x); $pdf->Cell($w,4,$cpVille,0,1,'L'); }
}

// Tableau méta
function energia_draw_meta_table(EnergiaPDF $pdf, array $vals): void {
    $labels = ['Numéro','Date','Code client','Date de validité','Mode de règlement'];
    $usableWidth=190; $min=[28,23,23,28,45];
    $pdf->SetFont('DejaVu','',8.5);
    $w=[];
    foreach($labels as $i=>$lab){
        $v = isset($vals[$i]) ? (string)$vals[$i] : '';
        $wLab = $pdf->GetStringWidth($lab)+6;
        $wVal = $pdf->GetStringWidth($v)+6;
        $w[$i] = max($wLab,$wVal,$min[$i]);
    }
    $sum = array_sum($w);
    if ($sum>$usableWidth){ $scale=$usableWidth/$sum; foreach($w as $i=>$x){ $w[$i]=(int)floor($x*$scale); } }
    $x=10; $y=75; $h=7;
    $pdf->SetDrawColor(160,160,160);
    $pdf->SetXY($x,$y); $pdf->SetFillColor(220,220,220); $pdf->SetFont('DejaVu','',8.5);
    foreach($labels as $i=>$lab){ $pdf->Cell($w[$i],$h,$lab,1,0,'C',true); }
    $pdf->Ln();
    $pdf->SetFillColor(255,255,255); $pdf->SetFont('DejaVu','',9); $pdf->SetX($x);
    foreach($vals as $i=>$v){ $pdf->Cell($w[$i],$h,(string)$v,1,0,'C'); }
}

// En-tête grille
function energia_table_header(EnergiaPDF $pdf, float $y, array $colW): void {
    $hdr = ['Code','Description','Qté','P.U. HT','Montant HT','TVA'];
    $pdf->SetXY(10,$y);
    $pdf->SetFillColor(220,220,220); $pdf->SetDrawColor(160,160,160);
    $pdf->SetFont('DejaVu','',9);
    foreach($hdr as $i=>$t){ $pdf->Cell($colW[$i],8,$t,1,0,'C',true); }
    $pdf->Ln();
}

/**
 * Affiche une ligne du tableau avec description multi-lignes.
 * Si $cols[6] (ecoText) est fourni ET non vide, on l'affiche
 * en petit gris sous la description.
 * $cols = [code, desc, qte, pu, mht, tva, (optionnel) ecoText]
 */
function energia_row_multiline(
    EnergiaPDF $pdf,
    array $colW,
    array $cols,
    float $y,
    float $lineH = 6,
    bool $descBold = false
): float {
    // PARAMETRES eco alignés avec l'estimation
    $ECO_LINE_H = 6.6;     // même valeur que tu utilises visuellement
    $ECO_OFFSET = 2.2;     // rapproche l'éco du bloc description

    $x = 10;
    $yStart = $y;
    $pdf->SetDrawColor(160,160,160);

    // Colonnes
    $code = (string)($cols[0] ?? '');
    $desc = (string)($cols[1] ?? '');
    $qte  = (string)($cols[2] ?? '');
    $pu   = (string)($cols[3] ?? '');
    $mht  = (string)($cols[4] ?? '');
    $tva  = (string)($cols[5] ?? '');
    $ecoText = (string)($cols[6] ?? '');
    $hasEco = ($ecoText !== '');

    $baseFontFam = 'DejaVu';
    $baseFontSty = '';
    $baseFontSz  = 7.7;

    // Interligne description = lineH (déjà passé par l'appelant)
    $descLineH = $lineH;

    // Mesures pour la description
    $pdf->SetFont($baseFontFam, ($descBold ? 'B' : $baseFontSty), $baseFontSz);
    $nbDesc = max(1, (int)$pdf->NbLines($colW[1], $desc));
    $descH  = $nbDesc * $descLineH;

    // Rendu Description
    $descX = $x + $colW[0];
    $descY = $yStart;
    $pdf->SetFont($baseFontFam, ($descBold ? 'B' : $baseFontSty), $baseFontSz);
    $pdf->SetXY($descX, $descY);
    $pdf->MultiCell($colW[1], $descLineH, $desc, 0, 'L', false);

    // Sous-texte éco
    if ($hasEco) {
        $pdf->SetTextColor(120,120,120);
        $pdf->SetFont($baseFontFam, 'I', 6.0);
        $ecoStartY = $descY + $descH - $ECO_OFFSET;
        $pdf->SetXY($descX, $ecoStartY);
        $pdf->MultiCell($colW[1], $ECO_LINE_H, $ecoText, 0, 'L', false);
        $pdf->SetTextColor(0,0,0);
    }

    // Y APRÈS description multiligne (et éco éventuelle)
    $yAfterDesc = $pdf->GetY();

    // Autres colonnes (alignées en haut)
    $pdf->SetFont($baseFontFam, $baseFontSty, $baseFontSz);
    $pdf->SetXY($x, $yStart);
    $pdf->Cell($colW[0], $descLineH, $code, 0, 0, 'L');
    $cur = $x + $colW[0] + $colW[1];
    $pdf->SetXY($cur, $yStart); $pdf->Cell($colW[2], $descLineH, $qte, 0, 0, 'R'); $cur += $colW[2];
    $pdf->SetXY($cur, $yStart); $pdf->Cell($colW[3], $descLineH, $pu, 0, 0, 'R'); $cur += $colW[3];
    $pdf->SetXY($cur, $yStart); $pdf->Cell($colW[4], $descLineH, $mht, 0, 0, 'R'); $cur += $colW[4];
    $pdf->SetXY($cur, $yStart); $pdf->Cell($colW[5], $descLineH, $tva, 0, 0, 'R');

    // TRAITS VERTICAUX (jusqu'au bas réel)
    $lineYBottom = $yAfterDesc;
    $xx = $x;
    $pdf->Line($xx, $yStart, $xx, $lineYBottom);
    foreach ($colW as $w) {
        $xx += $w;
        $pdf->Line($xx, $yStart, $xx, $lineYBottom);
    }

    return $yAfterDesc;
}

// Remplissage exact jusqu'à une position
function energia_row_fill_to(EnergiaPDF $pdf, array $colW, float $y, float $yTarget): float {
    if ($yTarget <= $y) return $y;
    $x=10; $xx=$x;
    $pdf->Line($xx,$y,$xx,$yTarget);
    foreach($colW as $w){ $xx += $w; $pdf->Line($xx,$y,$xx,$yTarget); }
    return $yTarget;
}

/* ---------- Rendu principal ---------- */
function energia_render_devis_pdf(array $devis, array $lignes, string $savePath = null): void {
    $logoPath = __DIR__ . '/assets/img/daikin_header.png';
    $footer = "Siret : B72684137 - RM : B72684137 - N° TVA intracom : ESB72684137 - Capital : 50 000,00 €";

    // Métadonnées
    $numero   = $devis['numero']        ?? '';
    $dateDoc  = $devis['date_creation'] ?? null;
    $echeance = $devis['date_echeance'] ?? null;
    $codeCl   = $devis['code_client']   ?? '';
    $modeReg  = $devis['mode_paiement'] ?? '';

    // Client
    $clientNom = trim(($devis['nom']??'').' '.($devis['prenom']??''));
    $clientAdr = $devis['adresse'] ?? '';
    $clientCpV = trim(($devis['code_postal']??'').' '.($devis['ville']??''));

    // Description installation (1re ligne si présente)
    $descInstall = $devis['description_installation']
        ?? $devis['description_travaux']
        ?? $devis['description']
        ?? '';

    // Totaux HT
    $totalHT = 0.0; foreach($lignes as $l){ $totalHT += (float)($l['total_ht'] ?? 0); }

    // Pré-agrégation TVA (base) + TVA prévisionnelle (pour acompte)
    $tvaResume = [];
    $montantTVAPre = 0.0;
    foreach ($lignes as $l) {
        $taux = isset($l['tva_taux']) ? (float)$l['tva_taux'] : 0.0;
        $ht   = isset($l['total_ht']) ? (float)$l['total_ht'] : 0.0;
        $k = (string)round($taux);
        if (!isset($tvaResume[$k])) $tvaResume[$k] = ['base_ht'=>0.0,'tva'=>0.0];
        $tvaResume[$k]['base_ht'] += $ht;
        $montantTVAPre += $ht * ($taux/100.0);
    }
    $montantTVAPre = round($montantTVAPre,2);

    // PDF
    $pdf = new EnergiaPDF('P','mm','A4');
    $pdf->AliasNbPages('{nb}');
    $pdf->footerLine = $footer;
    $pdf->SetMargins(10,10,10);
    $pdf->SetAutoPageBreak(false);

    // Polices
    $pdf->AddFont('DejaVu','', 'DejaVuSansCondensed.ttf', true);
    $pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf', true);
    $pdf->AddFont('DejaVu','I','DejaVuSansCondensed-Oblique.ttf', true);

    // === Ligne footer dynamique (au mm)
    $FOOTER_GAP_MM = 0.5;
    $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
    $yBottomFull = $footerLineY - $FOOTER_GAP_MM; // limite utile absolue (toutes pages)

    // --- Métriques grille
    $colW = [25,93,13,23,23,13];
    $yStartHeaderFirst = 95; // en-tête page 1
    $yStartBodyFirst   = $yStartHeaderFirst + 8;
    $yStartHeaderNext  = 20; // en-tête pages suivantes
    $yStartBodyNext    = $yStartHeaderNext + 8;

    // **Seuils page 1**
    $lineH   = 3.5;     // interligne description pour les articles
    $hInfo   = 7.0;     // texte info (≈2 lignes à 3.5)
    $gapInfo2= 5.0;     // marge après info
    $hTVA    = 6.0 * (1+3); // 24 mm
    $hTotaux = 6.0 * 4;     // 24 mm (4 lignes cadrées)
    $blocBasH= $hInfo + $gapInfo2 + max($hTVA, $hTotaux); // 7 + 5 + 24 = 36 mm

    // Règlement (dans la grille)
    $lineHRegTitle = 3.8;
    $lineHRegText  = 4.4;

    // Espaces personnalisés
    $EspaceApresArticles = 3.0;   // mm
    $EspaceAvantReglement = 3.0;  // mm

    // PARAM eco (identiques à energia_row_multiline)
    $ECO_LINE_H = 6.6;
    $ECO_OFFSET = 2.2;

    // PAGE 1
    $pdf->AddPage();
    energia_draw_box_title($pdf,'Devis');
    energia_header_company($pdf,$logoPath);
    energia_header_client($pdf,$clientNom,$clientAdr,$clientCpV);
    energia_draw_meta_table($pdf, [
        $numero,
        energia_date_fr($dateDoc),
        $codeCl,
        energia_date_fr($echeance),
        $modeReg,
    ]);
    energia_table_header($pdf,$yStartHeaderFirst,$colW);
    $y = $yStartBodyFirst;

    // -------- Description installation (MultiCell "libre" + traits)
    if ($descInstall !== '') {
        $pdf->SetFont('DejaVu','',7.2);
        $descIntroLH = 4.0;

        $xLeft  = 10;
        $xDesc  = $xLeft + $colW[0];
        $wDesc  = $colW[1];

        $yTop = $y;
        $pdf->SetXY($xDesc, $yTop);
        $pdf->MultiCell($wDesc, $descIntroLH, $descInstall, 0, 'L');
        $yBottom = $pdf->GetY();

        // Traits verticaux
        $xx = $xLeft;
        $pdf->SetDrawColor(160,160,160);
        $pdf->Line($xx, $yTop, $xx, $yBottom);
        foreach ($colW as $w) { $xx += $w; $pdf->Line($xx, $yTop, $xx, $yBottom); }

        $y = $yBottom; // pas d'espace additionnel ici
    }

    // ***** Multi-pages intelligent
    $hasSecondPage = false;
    $count = count($lignes);

    for ($idx=0; $idx<$count; $idx++) {
        $l = $lignes[$idx];

        // Titre de pièce si changement
        static $currentPieceKey = null;
        if (!empty($l['piece_key']) && $l['piece_key'] !== $currentPieceKey) {
            $currentPieceKey = $l['piece_key'];

            // Espace avant pièce (ligne vide avec grille)
            $y = energia_add_grid_gap($pdf, $colW, $y, 6);

            // pièce en GRAS (ligne de tableau)
            if (!empty($l['piece_nom'])) {
                $y = energia_row_multiline(
                    $pdf, $colW,
                    ['', (string)$l['piece_nom'], '', '', '', ''],
                    $y,
                    $lineH,
                    true
                );
            }
        }

        // Texte article
        $desc = (string)($l['libelle'] ?? '');

        // Eco-texte éventuel
        $ecoText = '';
        if (!empty($l['eco_unit_ht']) && (float)$l['eco_unit_ht'] > 0) {
            $ecoText = 'Dont une éco-contribution totale (HT) : '
                     . energia_money_fr((float)($l['eco_total_ht'] ?? 0))
                     . '€, soit ' . energia_money_fr((float)$l['eco_unit_ht'])
                     . '€ unitaire';
        }

        // ----- DECISION DE SAUT DE PAGE (estimation fidèle) -----
        $rowH = energia_estimate_row_height(
            $pdf, $colW, $desc, $ecoText,
            $lineH, $ECO_LINE_H, $ECO_OFFSET,
            $EspaceApresArticles, 0.0 // espace AVANT la ligne dans ton flux actuel
        );

        $thresholdOnePage = $yBottomFull - $blocBasH;

        if ($pdf->PageNo() === 1) {
            if ($y + $rowH > $thresholdOnePage) {
                // Remplit P1 jusqu'en bas
                while ($y + $lineH <= $yBottomFull) {
                    $y = energia_row_multiline($pdf, $colW, ['', ' ', '', '', '', ''], $y, $lineH);
                }
                if ($y < $yBottomFull) {
                    $y = energia_row_fill_to($pdf, $colW, $y, $yBottomFull);
                }
                $pdf->Line(10, $y, 10 + array_sum($colW), $y);

                // Nouvelle page
                $pdf->AddPage();
                $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
                $yBottomFull = $footerLineY - $FOOTER_GAP_MM;
                energia_table_header($pdf,$yStartHeaderNext,$colW);
                $y = $yStartBodyNext;
            }
        } else {
            if ($y + $rowH > $yBottomFull) {
                $pdf->Line(10, $y, 10 + array_sum($colW), $y);
                $pdf->AddPage();
                $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
                $yBottomFull = $footerLineY - $FOOTER_GAP_MM;
                energia_table_header($pdf,$yStartHeaderNext,$colW);
                $y = $yStartBodyNext;
            }
        }

        // ----- ESPACE AVANT L'ARTICLE (ligne vide)
        $y = energia_add_grid_gap($pdf, $colW, $y, $EspaceApresArticles);

        // ----- LIGNE ARTICLE
        $code = (string)($l['code'] ?? '');
        $qte  = energia_money_fr((float)($l['quantite'] ?? 0));
        $pu   = energia_money_fr((float)($l['prix_unitaire'] ?? 0));
        $mht  = energia_money_fr((float)($l['total_ht'] ?? 0));
        $tva  = energia_money_fr((float)($l['tva_taux'] ?? 0));

        $y = energia_row_multiline(
            $pdf, $colW,
            [$code, $desc, $qte, $pu, $mht, $tva, $ecoText],
            $y,
            $lineH
        );
    }

    // ======= REGLEMENT =======
    // Espace avant règlement
    $y = energia_add_grid_gap($pdf, $colW, $y, $EspaceAvantReglement);

    // Calcul bloc règlement (pour pagination)
    $reglementTexte = "Par virement bancaire - 40% à la commande soit "
        . energia_money_fr(
            (isset($devis['acompte']) && $devis['acompte']!=='')
                ? (float)$devis['acompte']
                : round(($totalHT + $montantTVAPre) * 0.40, 2)
        )
        . " euro - Le solde à la livraison.";

    $pdf->SetFont('DejaVu','',6);
    $nbRegTextLines = $pdf->NbLines($colW[1], $reglementTexte);
    $hRegTextReal   = max(1,$nbRegTextLines) * $lineHRegText;
    $hRegBlock      = $lineHRegTitle + $hRegTextReal;

    $yLimitLastPageStop = $yBottomFull - $blocBasH;

    if ($y + $hRegBlock > $yLimitLastPageStop) {
        if ($pdf->PageNo() === 1) {
            while ($y + $lineH <= $yBottomFull) $y = energia_add_grid_gap($pdf,$colW,$y,$lineH);
            if ($y < $yBottomFull) $y = energia_row_fill_to($pdf,$colW,$y,$yBottomFull);
            $pdf->Line(10, $y, 10 + array_sum($colW), $y);
        } else {
            $pdf->Line(10, $y, 10 + array_sum($colW), $y);
        }
        $pdf->AddPage();
        $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
        $yBottomFull = $footerLineY - $FOOTER_GAP_MM;
        energia_table_header($pdf,$yStartHeaderNext,$colW);
        $y = $yStartBodyNext;
        $yLimitLastPageStop = $yBottomFull - $blocBasH;
    }

    // Affiche règlement (titre + texte) dans la grille
    $pdf->SetFont('DejaVu','B',7); $pdf->SetTextColor(255,0,0);
    $y = energia_row_multiline($pdf,$colW,['','Règlement','','','',''],$y,$lineHRegTitle);
    $pdf->SetFont('DejaVu','',6);  $pdf->SetTextColor(0,0,0);
    $y = energia_row_multiline($pdf,$colW,['',$reglementTexte,'','','',''],$y,$lineHRegText);

    // ---------- TVA / TTC finaux
    $montantTVA = 0.0;
    foreach ($tvaResume as $k=>$data) {
        $rate = (float)$k;
        $tvaResume[$k]['tva'] = round((float)$data['base_ht'] * ($rate/100), 2);
        $montantTVA += $tvaResume[$k]['tva'];
    }
    $montantTVA = round($montantTVA,2);
    $totalTTC = round((float)$totalHT + (float)$montantTVA, 2);

    // ---------- Eco-contribution TTC (optionnelle) — NOUVEAU
    $ecoTotalTTCDoc = 0.0;
    foreach ($lignes as $l) {
        $ecoTotalTTCDoc += (float)($l['eco_total_ttc'] ?? 0);
    }
    $ecoTotalTTCDoc = round($ecoTotalTTCDoc, 2);

    // ---------- BLOC BAS (HORS TABLEAU), ANCRÉ AU BAS de la DERNIERE PAGE
    $yBlocksTop = $yBottomFull - $blocBasH;

    // Coller proprement la grille à $yBlocksTop
    if ($y < $yBlocksTop) {
        $y = energia_row_fill_to($pdf, $colW, $y, $yBlocksTop);
        $pdf->Line(10, $y, 10 + array_sum($colW), $y);
    } else {
        $pdf->Line(10, $y, 10 + array_sum($colW), $y);
        // nouvelle page
        $pdf->AddPage();
        $footerLineY = (method_exists($pdf,'GetPageHeight') ? $pdf->GetPageHeight() : 297) - 19.0;
        $yBottomFull = $footerLineY - $FOOTER_GAP_MM;
        energia_table_header($pdf,$yStartHeaderNext,$colW);
        $y = $yStartBodyNext;
        $yBlocksTop = $yBottomFull - $blocBasH;
    }

    // Info TVA
    $pdf->SetFont('DejaVu','',6);
    $infoTva = "Devis gratuit. Les prix TTC sont établis sur la base des taux de TVA en vigueur à la date de remise de l'offre. Toute variation de ces taux sera répercutée sur les prix.";
    $pdf->SetXY(10, $yBlocksTop);
    $pdf->MultiCell(190, 3.5, $infoTva, 0, 'L');

    // Y commun TVA / Totaux
    $yAfterInfo = $pdf->GetY() + $gapInfo2;

    // Tableau TVA (gauche)
    $xTVA=10; $yTVA=$yAfterInfo; $wT=15; $wB=25; $wM=25; $h=6;
    $nbRows = 1+3; $hTotal = $h*$nbRows;
    $pdf->SetDrawColor(160,160,160);
    $pdf->Line($xTVA, $yTVA, $xTVA, $yTVA+$hTotal);
    $pdf->Line($xTVA+$wT, $yTVA, $xTVA+$wT, $yTVA+$hTotal);
    $pdf->Line($xTVA+$wT+$wB, $yTVA, $xTVA+$wT+$wB, $yTVA+$hTotal);
    $pdf->Line($xTVA+$wT+$wB+$wM, $yTVA, $xTVA+$wT+$wB+$wM, $yTVA+$hTotal);
    $pdf->Line($xTVA,$yTVA,$xTVA+$wT+$wB+$wM,$yTVA);
    $pdf->Line($xTVA,$yTVA+$h,$xTVA+$wT+$wB+$wM,$yTVA+$h);
    $pdf->Line($xTVA,$yTVA+$hTotal,$xTVA+$wT+$wB+$wM,$yTVA+$hTotal);
    $pdf->SetFont('DejaVu','',7.5);
    $pdf->SetFillColor(220,220,220);
    $pdf->SetXY($xTVA,$yTVA);
    $pdf->Cell($wT,$h,'Taux',0,0,'C',true);
    $pdf->Cell($wB,$h,'Base HT',0,0,'C',true);
    $pdf->Cell($wM,$h,'Montant TVA',0,1,'C',true);
    foreach ([20,10,0] as $taux) {
        $base = (float)($tvaResume[(string)$taux]['base_ht'] ?? 0);
        $tvaM = (float)($tvaResume[(string)$taux]['tva'] ?? 0);
        $pdf->SetXY($xTVA,$pdf->GetY());
        $pdf->Cell($wT,$h, $base>0 ? energia_money_fr((float)$taux):'', 0,0,'C');
        $pdf->Cell($wB,$h, $base>0 ? energia_money_fr($base):'', 0,0,'R');
        $pdf->Cell($wM,$h, $base>0 ? energia_money_fr($tvaM):'', 0,1,'R');
    }

    // Totaux (droite)
    $xPos=120; $wLabel=48; $wVal=32; $hRow=6; $yTot=$yAfterInfo;
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
        ['Total HT', energia_money_fr((float)$totalHT)],
        ['Total TVA', energia_money_fr((float)$montantTVA)],
        ['Total TTC', energia_money_fr((float)$totalTTC)],
    ];
    for($i=0;$i<3;$i++){
        $yy = $yTot + ($i*$hRow);
        $pdf->SetXY($xPos+1.5,$yy);         $pdf->Cell($wLabel-3,$hRow,$rows[$i][0],0,0,'L');
        $pdf->SetXY($xPos+$wLabel,$yy);     $pdf->Cell($wVal-1.5,$hRow,$rows[$i][1],0,0,'R');
    }

    $pdf->SetFont('DejaVu','',9);
    $yy = $yTot + (3*$hRow);
    $pdf->SetXY($xPos+1.5,$yy);             $pdf->Cell($wLabel-3,$hRow,'Net à payer',0,0,'L');
    $pdf->SetXY($xPos+$wLabel,$yy);         $pdf->Cell($wVal-1.5,$hRow, energia_money_fr((float)$totalTTC).' €', 0, 0, 'R');

    // Eco-contribution TTC (sous le bloc Totaux)
    $ecoTotalTTCDoc = 0.0;
    foreach ($lignes as $l) { $ecoTotalTTCDoc += (float)($l['eco_total_ttc'] ?? 0); }
    $ecoTotalTTCDoc = round($ecoTotalTTCDoc, 2);
    if ($ecoTotalTTCDoc > 0) {
        $pdf->SetFont('DejaVu','',8);
        $ecoTopMargin = 0.2;
        $ecoRowH = 3.8;
        $yBlockBottom = $yTot + ($hRow * 4);
        $ecoY = $yBlockBottom + $ecoTopMargin;
        $maxY = $yBottomFull - 2.0;
        if ($ecoY > $maxY) { $ecoY = $maxY; }
        $pdf->SetXY($xPos, $ecoY);
        $pdf->Cell(
            $wLabel + $wVal,
            $ecoRowH,
            'Dont un total de ' . energia_money_fr($ecoTotalTTCDoc) . '€ TTC d’éco-contribution',
            0,
            0,
            'R'
        );
    }

    // ==================================================================
    //  OPTION C : SAUVEGARDE + AFFICHAGE
    // ==================================================================
    if ($savePath) {
        // 1) Sauvegarde locale
        $pdf->Output('F', $savePath);
    }

    // 2) Affichage direct dans navigateur
    if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
    header('Content-Type: application/pdf');
    $pdf->Output('I', basename($savePath) ?: 'devis.pdf');
}