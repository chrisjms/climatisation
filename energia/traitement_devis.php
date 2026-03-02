<?php
declare(strict_types=1);

// --- Configuration des erreurs ---
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// Helpers
function money_fr($n) { return number_format((float)$n, 2, ',', ' '); }
function date_fr($s) {
    if(!$s || $s == '0000-00-00') return '';
    return date('d/m/Y', strtotime($s));
}

$devisId = isset($_GET['id']) ? (int)$_GET['id'] : 0;


// ======================================================================
// 1) TRAITEMENT DU POST — CREATION DU DEVIS
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {

    /* ------------------- Client ---------------------- */
    $client_id = (int)$_POST['client_id'];

    /* ------------------- DATE CREATION ---------------------- */
    if (!empty($_POST['date_creation'])) {
        $date_creation = $_POST['date_creation']; // ISO (yyyy-mm-ddThh:mm)
    } elseif (!empty($_POST['date_creation_date'])) {
        $date_creation = $_POST['date_creation_date'] . 'T00:00';
    } else {
        $date_creation = date('Y-m-d\TH:i');
    }

    /* ------------------- NUMERO DE DEVIS ---------------------- */
    $numero = isset($_POST['numero']) ? trim($_POST['numero']) : '';

    if ($numero === '') {
        try {
            $stmtNum = $pdo->query("SELECT MAX(numero) AS max_num FROM energia_devis WHERE numero LIKE 'DE%'");
            $rowNum = $stmtNum->fetch(PDO::FETCH_ASSOC);

            if (!empty($rowNum['max_num']) && preg_match('/^DE(\d{8})$/', $rowNum['max_num'], $m)) {
                $num = (int)$m[1] + 1;
            } else {
                $num = 10000000;
            }
            $numero = 'DE' . str_pad((string)$num, 8, '0', STR_PAD_LEFT);

        } catch (Throwable $e) {
            $numero = 'DE' . date('YmdHis');
        }
    }

    /* ------------------- DESCRIPTION ---------------------- */
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    /* ------------------- ECHEANCE ---------------------- */
    $date_echeance = !empty($_POST['date_echeance']) ? $_POST['date_echeance'] : null;

    /* ------------------- REGLEMENT ---------------------- */
    $mode_paiement   = !empty($_POST['mode_paiement_1']) ? $_POST['mode_paiement_1'] : null;
    $bank_account_id = !empty($_POST['bank_account_id_1']) ? (int)$_POST['bank_account_id_1'] : null;

    /* ------------------- ACOMPTE ---------------------- */
    $acompte = null;
    if (isset($_POST['acompte']) && $_POST['acompte'] !== '') {
        $acompte = (float) str_replace(',', '.', (string)$_POST['acompte']);
    }

    // ======================================================================
    // INSERT DEVIS
    // ======================================================================
    $st = $pdo->prepare("
        INSERT INTO `energia_devis`
        (`client_id`, `numero`, `date_creation`, `description`,
         `montant_ht`, `montant_ttc`, `date_echeance`, `acompte`,
         `mode_paiement`, `bank_account_id`, `fichier_pdf`)
        VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, '')
    ");

    $st->execute([
        $client_id,
        $numero,
        $date_creation,
        $description,
        $date_echeance,
        $acompte,
        $mode_paiement,
        $bank_account_id,
    ]);

    $devisId = (int)$pdo->lastInsertId();


    // ======================================================================
    // 2) INSERT DES LIGNES
    // ======================================================================
    $pacIds     = $_POST['pac_ids']     ?? [];
    $libelles   = $_POST['libelles']    ?? [];
    $quantites  = $_POST['quantites']   ?? [];
    $prix       = $_POST['prix']        ?? [];
    $tvaTaux    = $_POST['tva_taux']    ?? [];
    $pieceKeys  = $_POST['piece_keys']  ?? [];
    $codes      = $_POST['codes']       ?? [];
    $piecesMeta = $_POST['pieces']      ?? [];

    $nbLignes = max(
        count($pacIds),
        count($libelles),
        count($quantites),
        count($prix),
        count($tvaTaux),
        count($pieceKeys),
        count($codes)
    );

    $totalDevisHT  = 0.0;
    $totalDevisTTC = 0.0;

    if ($nbLignes > 0) {

        // Éco
        $stEco = $pdo->prepare("SELECT eco_percent, eco_unit_ht FROM energia_pompes_a_chaleur WHERE id = ?");

        $stLigne = $pdo->prepare("
            INSERT INTO `energia_devis_lignes`
            (`devis_id`, `pac_id`, `code`, `libelle`, `quantite`,
             `prix_unitaire`, `tva_taux`, `total_ht`, `total_ttc`,
             `piece_key`, `piece_nom`,
             `eco_unit_ht`, `eco_total_ht`, `eco_total_ttc`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        for ($i = 0; $i < $nbLignes; $i++) {

            $pacId   = (int)($pacIds[$i] ?? 0);
            $codeStr = trim((string)($codes[$i] ?? ''));
            $libelleL = trim((string)($libelles[$i] ?? ''));

            $qte = (int)($quantites[$i] ?? 1);
            if ($qte < 1) $qte = 1;

            $pu  = (float)($prix[$i] ?? 0);
            $tva = (float)($tvaTaux[$i] ?? 20);

            $pieceKey = $pieceKeys[$i] ?? null;
            $pieceNom = '';
            if ($pieceKey !== null && isset($piecesMeta[$pieceKey]['nom'])) {
                $pieceNom = trim((string)$piecesMeta[$pieceKey]['nom']);
            }

            $totalHT  = round($pu * $qte, 2);
            $totalTTC = round($totalHT * (1 + $tva/100), 2);

            // Éco
            $eco_unit_ht = null;
            $eco_total_ht = null;
            $eco_total_ttc = null;

            if ($pacId) {
                $stEco->execute([$pacId]);
                if ($rowEco = $stEco->fetch(PDO::FETCH_ASSOC)) {
                    $ecoPercent = $rowEco['eco_percent']   ?? null;
                    $ecoUnitDef = $rowEco['eco_unit_ht']   ?? null;

                    if (!empty($ecoUnitDef) && $ecoUnitDef > 0) {
                        $eco_unit_ht = (float)$ecoUnitDef;
                    }
                    elseif (!empty($ecoPercent) && $ecoPercent > 0) {
                        $eco_unit_ht = round($pu * ($ecoPercent/100), 2);
                    }

                    if (!empty($eco_unit_ht)) {
                        $eco_total_ht  = round($eco_unit_ht * $qte, 2);
                        $eco_total_ttc = round($eco_total_ht * (1 + $tva/100), 2);
                    }
                }
            }

            $stLigne->execute([
                $devisId, $pacId, $codeStr, $libelleL, $qte,
                $pu, $tva, $totalHT, $totalTTC,
                $pieceKey, $pieceNom,
                $eco_unit_ht, $eco_total_ht, $eco_total_ttc
            ]);

            $totalDevisHT  += $totalHT;
            $totalDevisTTC += $totalTTC;
        }
    }

    // ======================================================================
    // 3) UPDATE MONTANTS
    // ======================================================================
    $pdo->prepare("
        UPDATE energia_devis SET montant_ht = ?, montant_ttc = ? WHERE id = ?
    ")->execute([$totalDevisHT, $totalDevisTTC, $devisId]);
}



// ======================================================================
// 4) RECUPERATION DEVIS POUR PDF
// ======================================================================
if ($devisId <= 0) die("ID de devis manquant.");

$st = $pdo->prepare("
    SELECT d.*, c.code_client, c.nom, c.prenom, c.adresse, c.code_postal, c.ville
    FROM energia_devis d
    LEFT JOIN energia_clients c ON d.client_id = c.id
    WHERE d.id = ?
");
$st->execute([$devisId]);
$devis = $st->fetch(PDO::FETCH_ASSOC);

if (!$devis) die('Devis introuvable.');

$devis['client_nom']      = trim(($devis['nom'] ?? '').' '.($devis['prenom'] ?? ''));
$devis['client_adresse']  = $devis['adresse'] ?? '';
$devis['client_cp_ville'] = trim(($devis['code_postal'] ?? '').' '.($devis['ville'] ?? ''));


// ======================================================================
// 5) LIGNES
// ======================================================================
$st = $pdo->prepare("
    SELECT dl.*, p.code AS pac_code
    FROM energia_devis_lignes dl
    LEFT JOIN energia_pompes_a_chaleur p ON p.id = dl.pac_id
    WHERE dl.devis_id = ?
    ORDER BY
      CASE WHEN dl.piece_key IS NULL OR dl.piece_key = '' THEN 1 ELSE 0 END,
      dl.piece_key ASC,
      dl.id ASC
");
$st->execute([$devisId]);
$lignes = $st->fetchAll(PDO::FETCH_ASSOC);


// ======================================================================
// 6) PDF — SAUVEGARDE + AFFICHAGE (OPTION C)
// ======================================================================
require_once __DIR__ . '/devis_pdf_layout.php';

// Nom fichier
$clientSlug = preg_replace('/[^A-Za-z0-9\-]/', '_',
    iconv('UTF-8','ASCII//TRANSLIT',$devis['client_nom'] ?? 'Client')
);
$dateSlug = date('Y-m-d', strtotime($devis['date_creation'] ?? 'now'));

$filename = "{$devis['numero']}_{$clientSlug}_{$dateSlug}.pdf";

// Chemins

$folder = __DIR__ . "/devis_pdf/";
if (!is_dir($folder)) {
    mkdir($folder, 0775, true);
}


$filepath     = $folder . $filename;
$filepath_web = "devis_pdf/" . $filename;

// Génération PDF + sauvegarde
energia_render_devis_pdf($devis, $lignes, $filepath);

// Update DB
$pdo->prepare("UPDATE energia_devis SET fichier_pdf = ? WHERE id = ?")
    ->execute([$filepath_web, $devisId]);

// Redirection vers le PDF
header("Location: $filepath_web");
exit;

?>
``