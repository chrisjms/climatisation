<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('CSRF invalide.');
}

$clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
if ($clientId <= 0) {
    http_response_code(400);
    exit('Client invalide.');
}

// Infos client
$stmt = $pdo->prepare(
    'SELECT nom, prenom, telephone, email, adresse, details
       FROM clients
      WHERE id = ?'
);
$stmt->execute([$clientId]);
$cli = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cli) {
    http_response_code(404);
    exit('Client introuvable.');
}

$nomComplet = trim(($cli['prenom'] ?? '').' '.($cli['nom'] ?? ''));
$email      = $cli['email']     ?: 'Non renseigné';
$tel        = $cli['telephone'] ?: 'Non renseigné';
$adresse    = $cli['adresse']   ?: 'Non renseignée';
$notes      = trim($cli['details'] ?? '') !== '' ? $cli['details'] : 'Aucun détail saisi.';

// ------------------------------
// PDF
// ------------------------------
require_once __DIR__ . '/tfpdf/tfpdf.php';

// Détection du même logo que les factures (plusieurs possibilités courantes)
$logoCandidates = [
    // Si tu as une constante en config, décommente/utilise-la :
    // defined('FACTURE_LOGO') ? FACTURE_LOGO : null,
    __DIR__ . '/images/logo_facture.png',
    __DIR__ . '/images/logo-facture.png',
    __DIR__ . '/images/facture_logo.png',
    __DIR__ . '/assets/logo.jpeg', // fallback
];
$logoPath = null;
foreach ($logoCandidates as $c) {
    if ($c && is_file($c)) { $logoPath = $c; break; }
}

// Petite classe pour header/footer
class PDFNotesClient extends tFPDF {
    public $logoPath;
    public $docTitle = 'Notes client';

    function Header() {
        // Logo à gauche, plus haut
        if ($this->logoPath && is_file($this->logoPath)) {
            $this->Image($this->logoPath, 12, 5, 28); // Y = 5 (remonté)
        }

        // Titre à droite
        $this->AddFont('DejaVu','','DejaVuSans.ttf',true);
        $this->SetFont('DejaVu','',14);
        $this->SetTextColor(30,30,30);

        // Cadre du titre (barre grisée), aligné au logo
        $this->SetXY(50, 7);
        $this->SetFillColor(240,240,240);
        $this->SetDrawColor(220,220,220);
        $this->Cell(148, 12, mb_strtoupper($this->docTitle, 'UTF-8'), 1, 0, 'C', true);

        // Saut sous l’en-tête
        $this->Ln(20);
    }

    function Footer() {
        $this->SetY(-17);
        $this->SetDrawColor(220,220,220);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);

        $this->AddFont('DejaVu','','DejaVuSans.ttf',true);
        $this->SetFont('DejaVu','',9);
        $this->SetTextColor(90,90,90);

        $date = 'Généré le ' . date('d/m/Y H:i');
        $this->Cell(0, 5, $date, 0, 0, 'L');
        $this->Cell(0, 5, 'Page '.$this->PageNo().'/{nb}', 0, 0, 'R');
    }

    // Ligne libellé / valeur, avec gestion du retour à la ligne
    function InfoRow($label, $value, $labelW = 40) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFont('DejaVu','',10);
        $this->SetTextColor(80,80,80);
        $this->SetFillColor(248,248,248);
        $this->SetDrawColor(230,230,230);

        // Libellé
        $this->MultiCell($labelW, 8, $label, 1, 'L', true);

        // Valeur
        $this->SetXY($x + $labelW, $y);
        $this->SetTextColor(20,20,20);
        $this->SetFont('DejaVu','',11);

        // Largeur restante jusqu'à la marge droite
        $wPage   = $this->GetPageWidth();
        $rMargin = $this->rMargin; // FPDF
        $wValue  = $wPage - $rMargin - ($x + $labelW);

        $this->MultiCell($wValue, 8, $value, 1, 'L', false);
    }

    function SectionTitle($text) {
        $this->SetFont('DejaVu','',12);
        $this->SetTextColor(40,40,40);
        $this->SetFillColor(235, 242, 255);
        $this->SetDrawColor(200, 218, 255);
        $this->Cell(0, 9, $text, 1, 1, 'L', true);
        $this->Ln(1.5);
    }

    function NotesBox($text) {
        $this->SetFont('DejaVu','',11);
        $this->SetTextColor(25,25,25);
        $this->SetDrawColor(220,220,220);
        $this->SetFillColor(253,253,253);
        $this->MultiCell(0, 7, $text, 1, 'L', true);
    }
}

$pdf = new PDFNotesClient();
$pdf->logoPath = $logoPath;

$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 22); // pied de page propre
$pdf->SetMargins(10, 12, 10);

$pdf->AddPage();

// Métadonnées
$pdf->SetTitle('Notes client - ' . ($nomComplet ?: 'Client'), true);
$pdf->SetAuthor('Votre société');
$pdf->SetCreator('Site de gestion');
$pdf->SetSubject('Notes client');

// --- Bloc "Informations client"
$pdf->SectionTitle('Informations client');
$pdf->InfoRow('Nom', $nomComplet ?: 'Non renseigné');
$pdf->InfoRow('Email', $email);
$pdf->InfoRow('Téléphone', $tel);
$pdf->InfoRow('Adresse', $adresse);
$pdf->Ln(3);

// --- Bloc "Détails / Notes"
$pdf->SectionTitle('Détails / Notes');
$pdf->NotesBox($notes);

// Nom du fichier
$clean = preg_replace('~[^a-z0-9_-]+~i', '-', $nomComplet) ?: 'client';
$pdf->Output('D', "notes-{$clean}.pdf");
exit;
