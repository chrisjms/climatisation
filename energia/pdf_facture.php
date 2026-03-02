<?php
// pdf_facture.php
// Retourne le chemin web du PDF généré
function generer_pdf_facture(int $facture_id, string $numero, bool $hideDueDate = false): string {
    // … charge les données de la facture
    // $facture = ... SELECT * FROM energia_factures WHERE id=...

    // Construction du PDF (mPDF, TCPDF ou FPDF, selon ton projet)
    // $pdf = new ...;

    // En-tête / numéro / date de création :
    // $pdf->Write(..., "Facture n° $numero");
    // $pdf->Write(..., "Date : ".date('d/m/Y', strtotime($facture['date_creation'])));

    // Pas d’échéance si $hideDueDate === true
    // if (!$hideDueDate && !empty($facture['date_echeance'])) {
    //     $pdf->Write(..., "Échéance : ".date('d/m/Y', strtotime($facture['date_echeance'])));
    // }

    // … lignes + totaux …

    // Enregistrement
    $dir = __DIR__.'/factures_pdf';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $filename = 'facture_'.$numero.'.pdf';
    $full = $dir.'/'.$filename;

    // $pdf->Output($full, 'F');
    // Retour URL publique
    return 'factures_pdf/'.$filename;
}
