<?php
// voir_pdf.php — Stream robuste des PDF (devis/facture/bdc) sans 404 Apache.
// Utilise la DB pour récupérer le chemin stocké, sinon reconstruit depuis numéro/id.
//
// Usage: voir_pdf.php?src=devis|facture|bdc&id=123

require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$src = $_GET['src'] ?? '';
$id  = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

// Cartographie d'après ta BDD
$MAP = [
  'devis'   => [
      'table'      => 'devis',
      'defaultDir' => 'devis_pdf',
      'pdfCols'    => ['fichier_pdf','pdf_path','chemin_pdf'], // ton schéma: fichier_pdf
      'numCols'    => ['numero','num_devis','reference'],      // ton schéma: numero
  ],
  'facture' => [
      'table'      => 'factures',
      'defaultDir' => 'facture_pdf',
      'pdfCols'    => ['fichier_pdf','pdf_path','chemin_pdf'], // ton schéma: fichier_pdf
      'numCols'    => ['numero','num_facture','reference'],    // ton schéma: numero
  ],
  'bdc'     => [
      'table'      => 'bons_de_commande',
      'defaultDir' => 'bdc_pdf',
      'pdfCols'    => ['fichier_pdf','pdf_path','chemin_pdf'], // ton schéma: fichier_pdf
      'numCols'    => ['numero','reference'],                  // ton schéma: numero
  ],
];

function col_exists(PDO $pdo, string $table, string $col): bool {
  try { $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col)); return (bool)$st->fetch(); }
  catch (Throwable $e) { return false; }
}

function safe_join(string $base, string $rel): string {
  // empêche ../ etc.
  $rel = str_replace(["\\", "\0"], "/", $rel);
  $rel = preg_replace('~\.\./~', '', $rel);
  $rel = ltrim($rel, '/');
  return rtrim($base,'/').'/'.$rel;
}

function guess_base_dirs(string $defaultDir): array {
  // Répertoires possibles (en fonction des pratiques courantes)
  // Tu peux en retirer/ajouter si nécessaire.
  $cands = [
    $defaultDir,
    "pdf/$defaultDir",
    "pdf",
    "uploads/$defaultDir",
    "uploads/pdf/$defaultDir",
    "uploads",
    "documents/$defaultDir",
    "docs/$defaultDir",
    "", // racine du site
  ];
  // Dédup + garde l'ordre
  return array_values(array_unique(array_map(fn($p)=>trim($p,'/'), $cands)));
}

if (!isset($MAP[$src]) || $id <= 0) {
  http_response_code(400);
  echo 'Requête invalide.'; exit;
}

$cfg = $MAP[$src];

// Construit le SELECT selon colonnes existantes
$cols = ['id'];
foreach ($cfg['pdfCols'] as $c) if (col_exists($pdo, $cfg['table'], $c)) $cols[] = "`$c`";
foreach ($cfg['numCols'] as $c) if (col_exists($pdo, $cfg['table'], $c)) $cols[] = "`$c`";

$sql = "SELECT ".implode(',', $cols)." FROM `{$cfg['table']}` WHERE id=:id LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([':id'=>$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); echo 'Document introuvable.'; exit; }

// 1) Récupère numéro & chemin stocké
$numero = '';
foreach ($cfg['numCols'] as $c) { if (!empty($row[$c])) { $numero = (string)$row[$c]; break; } }

$pdfPath = '';
foreach ($cfg['pdfCols'] as $c) { if (!empty($row[$c])) { $pdfPath = trim((string)$row[$c]); break; } }

// 2) Si URL absolue → redirection
if ($pdfPath && preg_match('~^https?://~i', $pdfPath)) {
  header('Location: '.$pdfPath, true, 302);
  exit;
}

// 3) Liste de candidats
$candidates = [];
$defaultDir = trim($cfg['defaultDir'], '/');

// a) Chemin stocké en DB
if ($pdfPath !== '') {
  $clean = ltrim($pdfPath, '/');
  if ($clean !== '') {
    // Si c'est un nom simple → essai dans plusieurs bases
    if (strpos($clean, '/') === false) {
      foreach (guess_base_dirs($defaultDir) as $base) {
        $candidates[] = ($base === '') ? $clean : "$base/$clean";
      }
    } else {
      // chemin relatif → on teste tel quel + bases
      $candidates[] = $clean;
      foreach (guess_base_dirs($defaultDir) as $base) {
        $candidates[] = "$base/".basename($clean);
      }
    }
  }
}

// b) Motifs dérivés du numéro/id
$sanNum = $numero !== '' ? preg_replace('~[^A-Za-z0-9._-]+~', '_', $numero) : '';
$names  = [];
if ($sanNum !== '') {
  $names[] = "{$sanNum}.pdf";
  $names[] = "{$src}_{$sanNum}.pdf";
  $names[] = "{$src}-{$sanNum}.pdf";
}
$names[] = "{$src}_{$id}.pdf";
$names[] = "{$src}-{$id}.pdf";
$names[] = "{$id}.pdf";

foreach ($names as $nm) {
  foreach (guess_base_dirs($defaultDir) as $base) {
    $candidates[] = ($base === '') ? $nm : "$base/$nm";
  }
}

// Dédup propre
$candidates = array_values(array_unique(array_map(fn($p)=>trim($p,'/'), $candidates)));

// 4) Recherche effective sur le FS
$foundFs = null; $foundRel = null;
foreach ($candidates as $rel) {
  $fs = safe_join(__DIR__, $rel);
  if (is_file($fs)) { $foundFs = $fs; $foundRel = $rel; break; }
}

// 5) Pas trouvé → écran d'aide (montre tous les chemins testés)
if (!$foundFs) {
  http_response_code(404);
  header('Content-Type: text/html; charset=utf-8');
  echo "<h1>PDF introuvable</h1>";
  echo "<p><b>Type:</b> ".htmlspecialchars($src)." — <b>ID:</b> ".(int)$id."</p>";
  if ($numero !== '') echo "<p><b>Numéro:</b> ".htmlspecialchars($numero, ENT_QUOTES, 'UTF-8')."</p>";
  if ($pdfPath !== '') echo "<p><b>Chemin en DB:</b> <code>".htmlspecialchars($pdfPath, ENT_QUOTES, 'UTF-8')."</code></p>";
  echo "<details open><summary>Chemins testés</summary><ul>";
  foreach ($candidates as $rel) echo "<li>".htmlspecialchars($rel, ENT_QUOTES, 'UTF-8')."</li>";
  echo "</ul></details>";
  echo "<p>👉 Place le PDF à l'un des chemins ci-dessus (recommandé : <code>".htmlspecialchars($defaultDir, ENT_QUOTES, 'UTF-8')."/</code>) ou ajuste la valeur enregistrée en base dans <code>fichier_pdf</code>.</p>";
  exit;
}

// 6) Stream inline
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.basename($foundRel ?: $foundFs).'"');
header('Content-Length: '.filesize($foundFs));
readfile($foundFs);
exit;
