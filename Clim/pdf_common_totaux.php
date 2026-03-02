<?php
/* Utilitaires de totaux pour tFPDF — style « épais » + position bas de page */

function build_totaux_from_buckets(array $htByRate, float $totalHT, float $totalTTC): array {
  // Tri par taux ascendant
  ksort($htByRate, SORT_NUMERIC);
  $totalTVA = 0.0;
  $tvaLines = [];
  foreach ($htByRate as $rateStr => $ht) {
    $rate = (float)$rateStr;
    $tva = round($ht * $rate/100, 2);
    $totalTVA = round($totalTVA + $tva, 2);
    $tvaLines[] = ['label' => 'TVA '.rtrim(rtrim(number_format($rate,2,',',' '),'0'),',').' %', 'amount' => $tva];
  }
  return [
    'ht'  => $totalHT,
    'tva' => $totalTVA,
    'ttc' => $totalTTC,
    'tva_lines' => $tvaLines
  ];
}

/**
 * Dessine le tableau des totaux au bas de la page.
 * - Bordures épaisses
 * - Largeur table = 86 mm (ajuste si besoin)
 * - Colonnes: libellé / montant
 */
function draw_totaux_table_bottom(tFPDF $pdf, array $totaux): void {
  $margin = 15;        // marge gauche/droite
  $tableW = 86;        // largeur du tableau
  $col1W  = 50;        // libellé
  $col2W  = $tableW - $col1W;

  // Hauteurs
  $rowH   = 8;
  $yBottomPadding = 18; // laisse un peu d'air en bas
  $pageH  = $pdf->GetPageHeight();
  $tableRows = 2 + max(1, count($totaux['tva_lines'])); // HT + TTC + (>=1 TVA)
  $tableH = $rowH * ($tableRows + 1); // +1 pour l'en-tête vide (on encadre tout le bloc)

  // Si on est trop bas, on force un saut AVANT puis on dessine
  if ($pdf->GetY() > $pageH - $tableH - $yBottomPadding - 10) {
    $pdf->AddPage();
  }

  // Place le tableau à ~60 mm du bas
  $pdf->SetY($pageH - $tableH - $yBottomPadding);

  // Cadre externe épais
  $x = $pdf->GetPageWidth() - $margin - $tableW;
  $y = $pdf->GetY();

  $pdf->SetLineWidth(0.6);
  $pdf->Rect($x, $y, $tableW, $tableH);

  // Style texte
  $pdf->SetFont('DejaVu','',11);

  // Lignes intérieures
  $pdf->SetLineWidth(0.4);
  $cursorY = $y;

  // Ligne: Total HT
  $cursorY += $rowH;
  $pdf->Line($x, $cursorY, $x+$tableW, $cursorY);
  $pdf->SetXY($x+3, $cursorY-$rowH+2); $pdf->Cell($col1W-6, $rowH-2, 'Total HT', 0, 0, 'L');
  $pdf->SetXY($x+$col1W, $cursorY-$rowH+2); $pdf->SetFont('DejaVu','B',11);
  $pdf->Cell($col2W-4, $rowH-2, number_format($totaux['ht'], 2, ',', ' ').' €', 0, 0, 'R');
  $pdf->SetFont('DejaVu','',11);

  // Lignes: TVA (une ou plusieurs)
  if (empty($totaux['tva_lines'])) {
    $cursorY += $rowH;
    $pdf->Line($x, $cursorY, $x+$tableW, $cursorY);
    $pdf->SetXY($x+3, $cursorY-$rowH+2); $pdf->Cell($col1W-6, $rowH-2, 'TVA', 0, 0, 'L');
    $pdf->SetXY($x+$col1W, $cursorY-$rowH+2); $pdf->Cell($col2W-4, $rowH-2, number_format(0, 2, ',', ' ').' €', 0, 0, 'R');
  } else {
    foreach ($totaux['tva_lines'] as $line) {
      $cursorY += $rowH;
      $pdf->Line($x, $cursorY, $x+$tableW, $cursorY);
      $pdf->SetXY($x+3, $cursorY-$rowH+2); $pdf->Cell($col1W-6, $rowH-2, $line['label'], 0, 0, 'L');
      $pdf->SetXY($x+$col1W, $cursorY-$rowH+2); $pdf->Cell($col2W-4, $rowH-2, number_format($line['amount'], 2, ',', ' ').' €', 0, 0, 'R');
    }
  }

  // Ligne: Total TTC (ligne plus épaisse)
  $cursorY += $rowH;
  $pdf->SetLineWidth(0.8);
  $pdf->Line($x, $cursorY, $x+$tableW, $cursorY);
  $pdf->SetXY($x+3, $cursorY-$rowH+2); $pdf->SetFont('DejaVu','B',12);
  $pdf->Cell($col1W-6, $rowH-2, 'Total TTC', 0, 0, 'L');
  $pdf->SetXY($x+$col1W, $cursorY-$rowH+2);
  $pdf->Cell($col2W-4, $rowH-2, number_format($totaux['ttc'], 2, ',', ' ').' €', 0, 0, 'R');

  // Ligne: Net à payer (même épaisseur, encore plus visible)
  $cursorY += $rowH;
  $pdf->SetLineWidth(1.0);
  $pdf->Line($x, $cursorY, $x+$tableW, $cursorY);
  $pdf->SetXY($x+3, $cursorY-$rowH+2); $pdf->SetFont('DejaVu','B',12);
  $pdf->Cell($col1W-6, $rowH-2, 'Net à payer', 0, 0, 'L');
  $pdf->SetXY($x+$col1W, $cursorY-$rowH+2);
  $pdf->Cell($col2W-4, $rowH-2, number_format($totaux['ttc'], 2, ',', ' ').' €', 0, 0, 'R');

  // Bordure basse finale
  $pdf->SetLineWidth(0.6);
  $pdf->Rect($x, $y, $tableW, $tableH);
}
