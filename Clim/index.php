<?php
// dashboard.php — Accueil enrichi pour votre app (KPIs + activité + top produits)
// Compatible avec vos pages existantes (clients, devis, factures, matériels, brochures)
// et tolérant aux schémas de BDD variés (auto-détection colonnes).

// --- Debug (à retirer en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Auth & PDO
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// ───────────────────────── Helpers BDD robustes ─────────────────────────
function table_exists(PDO $pdo, string $name): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}
function list_columns(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `$table`");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}
function first_col(array $prefs, array $cols, ?string $fallback = null): ?string {
    foreach ($prefs as $c) if (in_array($c, $cols, true)) return $c;
    return $fallback;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur($n){ if ($n===null || $n==='') return '—'; return number_format((float)$n, 2, ',', ' ').' €'; }
function ymd($d){ if(!$d) return ''; $ts = strtotime($d); return $ts? date('Y-m-d', $ts): (string)$d; }
function dmy($d){ if(!$d) return ''; $ts = strtotime($d); return $ts? date('d/m/Y', $ts): (string)$d; }

$today      = date('Y-m-d');
$firstMonth = date('Y-m-01');
$firstYear  = date('Y-01-01');

// ───────────────────────── Découverte schéma ─────────────────────────
// Clients
$hasClients = table_exists($pdo, 'clients');
$clientsCols = $hasClients ? list_columns($pdo, 'clients') : [];
$cliDateCol  = $hasClients ? first_col(['date_ajout','created_at','date_creation','inscrit_le'], $clientsCols, null) : null;

// Devis
$hasDevis   = table_exists($pdo, 'devis');
$devisCols  = $hasDevis ? list_columns($pdo, 'devis') : [];
$dvDate     = $hasDevis ? first_col(['date_creation','created_at','date','date_devis'], $devisCols, 'date_creation') : null;
$dvTotal    = $hasDevis ? first_col(['montant_ttc','total_ttc','montant'], $devisCols, 'montant_ttc') : null;
$dvPdf      = $hasDevis ? first_col(['fichier_pdf','pdf_path','chemin_pdf'], $devisCols, 'fichier_pdf') : null;
$dvCliId    = $hasDevis ? first_col(['client_id'], $devisCols, 'client_id') : null;

// Factures
$hasFact    = table_exists($pdo, 'factures');
$factCols   = $hasFact ? list_columns($pdo, 'factures') : [];
$faDate     = $hasFact ? first_col(['date_facture','date_creation','created_at','date'], $factCols, 'date_creation') : null;
$faTotal    = $hasFact ? first_col(['montant_ttc','total_ttc','montant'], $factCols, 'montant_ttc') : null;
$faPdf      = $hasFact ? first_col(['fichier_pdf','pdf_path','chemin_pdf'], $factCols, 'fichier_pdf') : null;
$faCliId    = $hasFact ? first_col(['client_id'], $factCols, 'client_id') : null;
$faPaidFlag = $hasFact ? first_col(['est_paye','paye','payee','is_paid','reglee','regle'], $factCols, null) : null;
$faPaidAmt  = $hasFact ? first_col(['montant_regle','regle_montant','montant_paye'], $factCols, null) : null;
$faBalance  = $hasFact ? first_col(['solde','reste_a_payer','reste'], $factCols, null) : null;
$faDevisId  = $hasFact ? first_col(['devis_id','id_devis'], $factCols, null) : null;

// Brochures (pour activité récente)
$hasBroch   = table_exists($pdo, 'brochures');
$broCols    = $hasBroch ? list_columns($pdo, 'brochures') : [];
$brDate     = $hasBroch ? first_col(['date_ajout','created_at','uploaded_at','date'], $broCols, 'date_ajout') : null;

// Lignes de devis (pour “Top matériels”)
$hasPac     = table_exists($pdo, 'pompes_a_chaleur');
$pacCols    = $hasPac ? list_columns($pdo, 'pompes_a_chaleur') : [];
$pacNameCol = $hasPac ? first_col(['nom','name','label'], $pacCols, 'nom') : null;
$linesFrom  = null; // 'devis_lignes' ou 'devis_items'
if (table_exists($pdo,'devis_lignes')) $linesFrom = 'devis_lignes';
elseif (table_exists($pdo,'devis_items')) $linesFrom = 'devis_items';

// ───────────────────────── KPIs de tête ─────────────────────────
$nbClients = $nbDevis = $nbFact = 0;
$caMonth   = 0.0;  // CA TTC du mois en cours
$caYear    = 0.0;  // CA TTC de l'année
$impayes   = 0.0;  // Impayés estimés
$toFollow  = 0;    // Devis à relancer (derniers 30j sans facture liée)

try {
    if ($hasClients) {
        $nbClients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    }
    if ($hasDevis) {
        $nbDevis = (int)$pdo->query("SELECT COUNT(*) FROM devis")->fetchColumn();
    }
    if ($hasFact) {
        $nbFact = (int)$pdo->query("SELECT COUNT(*) FROM factures")->fetchColumn();

        // CA du mois & de l'année
        $qMonth = $pdo->prepare("SELECT COALESCE(SUM($faTotal),0) FROM factures WHERE DATE($faDate) >= :d1 AND DATE($faDate) <= :d2");
        $qMonth->execute([':d1'=>$firstMonth, ':d2'=>$today]);
        $caMonth = (float)$qMonth->fetchColumn();

        $qYear = $pdo->prepare("SELECT COALESCE(SUM($faTotal),0) FROM factures WHERE DATE($faDate) >= :d1 AND DATE($faDate) <= :d2");
        $qYear->execute([':d1'=>$firstYear, ':d2'=>$today]);
        $caYear = (float)$qYear->fetchColumn();

        // Impayés (plusieurs stratégies)
        if ($faBalance) {
            $impayes = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST($faBalance,0)),0) FROM factures")->fetchColumn();
        } elseif ($faPaidAmt) {
            $impayes = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST($faTotal - COALESCE($faPaidAmt,0),0)),0) FROM factures")->fetchColumn();
        } elseif ($faPaidFlag) {
            // suppose 0 = impayée, 1 = payée
            $impayes = (float)$pdo->query("SELECT COALESCE(SUM($faTotal),0) FROM factures WHERE COALESCE($faPaidFlag,0) IN (0,'0','non','false')")->fetchColumn();
        } else {
            // Pas d'info de paiement : on n'affiche pas l'impayé
            $impayes = null;
        }
    }

    // Devis à relancer (30 derniers jours sans facture associée)
    if ($hasDevis && $faDevisId) {
        $st = $pdo->prepare("
            SELECT COUNT(*)
              FROM devis d
         LEFT JOIN factures f ON f.$faDevisId = d.id
             WHERE DATE(d.$dvDate) >= :dmin
               AND f.$faDevisId IS NULL
        ");
        $st->execute([':dmin'=>date('Y-m-d', strtotime('-30 days'))]);
        $toFollow = (int)$st->fetchColumn();
    } else {
        $toFollow = null;
    }
} catch (Throwable $e) {
    // Soft fail, on laisse les valeurs par défaut
}

// ───────────────────────── Listes rapides (5 derniers) ─────────────────────────
$derniersDevis = [];
$dernieresFactures = [];

if ($hasDevis) {
    $sql = "
        SELECT d.$dvDate AS date_creation,
               d.$dvTotal AS prix,
               d.$dvPdf   AS fichier_pdf,
               c.nom, c.prenom
          FROM devis d
          JOIN clients c ON c.id = d.$dvCliId
      ORDER BY d.$dvDate DESC, d.id DESC
         LIMIT 5";
    $derniersDevis = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
if ($hasFact) {
    $sql = "
        SELECT f.$faDate AS date_creation,
               f.$faTotal AS prix,
               f.$faPdf   AS fichier_pdf,
               c.nom, c.prenom
          FROM factures f
          JOIN clients c ON c.id = f.$faCliId
      ORDER BY f.$faDate DESC, f.id DESC
         LIMIT 5";
    $dernieresFactures = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ───────────────────────── Activité récente (mix) ─────────────────────────
$activite = []; // entries: [date, type, label, url?]
try {
    // Clients
    if ($hasClients && $cliDateCol) {
        $st = $pdo->query("SELECT id, $cliDateCol AS dt, nom, prenom FROM clients ORDER BY $cliDateCol DESC, id DESC LIMIT 6");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activite[] = ['date'=>$r['dt'], 'type'=>'Client', 'label'=>trim(($r['nom']??'').' '.($r['prenom']??'')), 'url'=>'clientele.php?client_id='.(int)$r['id']];
        }
    }
    // Devis
    if ($hasDevis) {
        $st = $pdo->query("SELECT id, $dvDate AS dt, description FROM devis ORDER BY $dvDate DESC, id DESC LIMIT 6");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activite[] = ['date'=>$r['dt'], 'type'=>'Devis', 'label'=>($r['description']??'—'), 'url'=>'devis.php?copy_from_id='.(int)$r['id'].'#form-devis'];
        }
    }
    // Factures
    if ($hasFact) {
        $st = $pdo->query("SELECT id, $faDate AS dt, description FROM factures ORDER BY $faDate DESC, id DESC LIMIT 6");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activite[] = ['date'=>$r['dt'], 'type'=>'Facture', 'label'=>($r['description']??'—'), 'url'=>null];
        }
    }
    // Brochures
    if ($hasBroch && $brDate) {
        $st = $pdo->query("SELECT id, $brDate AS dt, COALESCE(titre,'') AS titre FROM brochures ORDER BY $brDate DESC, id DESC LIMIT 6");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $activite[] = ['date'=>$r['dt'], 'type'=>'Brochure', 'label'=>($r['titre']?:'Fichier'), 'url'=>'brochures.php'];
        }
    }

    // Tri par date desc
    usort($activite, function($a,$b){
        return strcmp(ymd($b['date']), ymd($a['date']));
    });
    // Garde 10 max
    $activite = array_slice($activite, 0, 10);
} catch (Throwable $e) { /* ignore */ }

// ───────────────────────── Top matériels (90 jours) ─────────────────────────
$topPac = [];
if ($linesFrom && $hasPac) {
    try {
        if ($linesFrom === 'devis_lignes') {
            // devis_lignes(pac_id, quantite, prix_unitaire) -> devis(date_creation)
            $sql = "
                SELECT p.id, p.$pacNameCol AS nom,
                       COALESCE(SUM(dl.quantite),0) AS qty,
                       COALESCE(SUM(dl.quantite * dl.prix_unitaire),0) AS ca_ht
                  FROM devis_lignes dl
                  JOIN devis d ON d.id = dl.devis_id
             LEFT JOIN pompes_a_chaleur p ON p.id = dl.pac_id
                 WHERE DATE(d.$dvDate) >= :dmin
              GROUP BY p.id, p.$pacNameCol
              ORDER BY qty DESC, ca_ht DESC
                 LIMIT 5";
        } else {
            // devis_items(pac_id, qty, unit_price)
            $sql = "
                SELECT p.id, p.$pacNameCol AS nom,
                       COALESCE(SUM(di.qty),0) AS qty,
                       COALESCE(SUM(di.qty * di.unit_price),0) AS ca_ht
                  FROM devis_items di
                  JOIN devis d ON d.id = di.devis_id
             LEFT JOIN pompes_a_chaleur p ON p.id = di.pac_id
                 WHERE DATE(d.$dvDate) >= :dmin
              GROUP BY p.id, p.$pacNameCol
              ORDER BY qty DESC, ca_ht DESC
                 LIMIT 5";
        }
        $st = $pdo->prepare($sql);
        $st->execute([':dmin'=>date('Y-m-d', strtotime('-90 days'))]);
        $topPac = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */ }
}

// ───────────────────────── CA des 6 derniers mois ─────────────────────────
$caMonths = []; // [['mois'=>'2025-03','total'=>1234.56], ...]
if ($hasFact) {
    $first6 = date('Y-m-01', strtotime('-5 months')); // inclu mois courant
    $sql = "
        SELECT DATE_FORMAT($faDate,'%Y-%m') AS ym, COALESCE(SUM($faTotal),0) AS total
          FROM factures
         WHERE DATE($faDate) >= :dmin
      GROUP BY ym
      ORDER BY ym ASC";
    $st = $pdo->prepare($sql);
    $st->execute([':dmin'=>$first6]);
    $tmp = $st->fetchAll(PDO::FETCH_KEY_PAIR); // ym => total

    // générer tous les mois présents (même à 0)
    $cursor = new DateTime($first6);
    $end    = new DateTime($firstMonth); // début du mois courant
    $end->modify('+1 month'); // inclus courant
    while ($cursor < $end) {
        $k = $cursor->format('Y-m');
        $caMonths[] = ['mois'=>$k, 'total'=> (float)($tmp[$k] ?? 0)];
        $cursor->modify('+1 month');
    }
}

// ───────────────────────── HTML ─────────────────────────
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Accueil — Tableau de bord</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
      /* Habillage léger pour la page d'accueil */
      .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:10px 0 16px}
      @media (max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
      @media (max-width:640px){.kpi-grid{grid-template-columns:1fr}}
      .kpi{border:1px solid #e8e8e8;border-radius:12px;padding:12px;background:#fff}
      .kpi .label{color:#556; font-size:.95em}
      .kpi .value{font-size:1.6em;font-weight:700}
      .kpi .sub{color:#888;font-size:.85em}
      .cards{display:grid;grid-template-columns:1.2fr .8fr; gap:12px}
      @media (max-width:1100px){.cards{grid-template-columns:1fr}}
      .card{border:1px solid #e8e8e8;border-radius:12px;background:#fff}
      .card h3{margin:0;padding:10px 12px;border-bottom:1px solid #eee}
      .card .body{padding:10px 12px}
      .activity li{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px dashed #eee}
      .activity li:last-child{border-bottom:none}
      .muted{color:#777}
      .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
      .btn{display:inline-block;border:1px solid #ddd;border-radius:10px;padding:6px 10px;background:#fff;text-decoration:none}
      .quick{display:flex;gap:8px;flex-wrap:wrap}
      table.compact{width:100%}
      table.compact th, table.compact td{padding:6px 8px;border-bottom:1px solid #f1f1f1}
      .badge{display:inline-block;background:#eef3ff;border:1px solid #dfe6ff;border-radius:999px;padding:2px 8px}
    </style>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
    <h1 style="margin-bottom:4px">Bienvenue, <?= h($_SESSION['username'] ?? 'Utilisateur') ?></h1>
    <div class="muted">Aujourd’hui : <?= dmy($today) ?></div>

    <!-- Raccourcis -->
    <div class="quick" style="margin:12px 0 6px">
        <a class="btn" href="devis.php#form-devis">➕ Nouveau devis</a>
        <a class="btn" href="ajout_client.php?retour=dashboard.php">👤 Nouveau client</a>
        <a class="btn" href="ajout_pac.php">🧰 Gérer matériels</a>
        <a class="btn" href="brochures.php">📤 Uploader brochure</a>
        <a class="btn" href="clientele.php">📇 Clientèle</a>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi">
            <div class="label">Clients</div>
            <div class="value"><?= (int)$nbClients ?></div>
            <div class="sub muted">Total en base</div>
        </div>
        <div class="kpi">
            <div class="label">CA du mois (TTC)</div>
            <div class="value"><?= eur($caMonth) ?></div>
            <div class="sub muted">Période : <?= dmy($firstMonth) ?> → <?= dmy($today) ?></div>
        </div>
        <div class="kpi">
            <div class="label">CA annuel (TTC)</div>
            <div class="value"><?= eur($caYear) ?></div>
            <div class="sub muted">Depuis le 01/01</div>
        </div>
    </div>

    <!-- Deux colonnes -->
    <div class="cards">

        <!-- Colonne gauche -->
        <div class="card">
            <h3>Activité récente</h3>
            <div class="body">
                <?php if (!$activite): ?>
                    <div class="muted">Aucune activité récente.</div>
                <?php else: ?>
                    <ul class="activity" style="list-style:none;margin:0;padding:0">
                        <?php foreach ($activite as $a): ?>
                            <li>
                                <span>
                                    <span class="badge"><?= h($a['type']) ?></span>
                                    &nbsp;<?= h($a['label']) ?>
                                </span>
                                <span class="muted mono">
                                    <?= dmy($a['date']) ?>
                                    <?php if (!empty($a['url'])): ?>
                                        &nbsp;<a class="btn" href="<?= h($a['url']) ?>">Ouvrir</a>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <h3>Derniers devis (TTC)</h3>
            <div class="body">
                <?php if ($derniersDevis): ?>
                    <table class="compact">
                        <thead><tr><th>Client</th><th>Date</th><th style="text-align:right">Montant</th><th>PDF</th></tr></thead>
                        <tbody>
                        <?php foreach ($derniersDevis as $d):
                            $fileName = basename($d['fichier_pdf'] ?? '');
                            $url      = "devis_pdf/$fileName";
                            $hasFile  = $fileName && file_exists(__DIR__ . "/devis_pdf/$fileName");
                        ?>
                            <tr>
                                <td><?= h(trim(($d['nom']??'').' '.($d['prenom']??''))) ?></td>
                                <td class="mono"><?= dmy($d['date_creation'] ?? '') ?></td>
                                <td class="mono" style="text-align:right"><?= eur($d['prix'] ?? null) ?></td>
                                <td><?= $hasFile ? '<a href="'.h($url).'" target="_blank">📄 Ouvrir</a>' : '<span class="muted">(manquant)</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted">Aucun devis.</div>
                <?php endif; ?>
            </div>

            <h3>Dernières factures (TTC)</h3>
            <div class="body">
                <?php if ($dernieresFactures): ?>
                    <table class="compact">
                        <thead><tr><th>Client</th><th>Date</th><th style="text-align:right">Montant</th><th>PDF</th></tr></thead>
                        <tbody>
                        <?php foreach ($dernieresFactures as $f):
                            $fileNameF = basename($f['fichier_pdf'] ?? '');
                            $urlF      = "facture_pdf/$fileNameF";
                            $hasFile   = $fileNameF && file_exists(__DIR__ . "/facture_pdf/$fileNameF");
                        ?>
                            <tr>
                                <td><?= h(trim(($f['nom']??'').' '.($f['prenom']??''))) ?></td>
                                <td class="mono"><?= dmy($f['date_creation'] ?? '') ?></td>
                                <td class="mono" style="text-align:right"><?= eur($f['prix'] ?? null) ?></td>
                                <td><?= $hasFile ? '<a href="'.h($urlF).'" target="_blank">📄 Ouvrir</a>' : '<span class="muted">(manquant)</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted">Aucune facture.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Colonne droite -->
        <div class="card">
            <h3>Vue pipeline</h3>
            <div class="body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <div class="kpi" style="padding:10px">
                        <div class="label">Devis (30 derniers jours)</div>
                        <div class="value">
                            <?php
                            $dv30 = 0; $sumDv30 = 0.0;
                            if ($hasDevis) {
                                $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM($dvTotal),0) FROM devis WHERE DATE($dvDate) >= :dmin");
                                $st->execute([':dmin'=>date('Y-m-d', strtotime('-30 days'))]);
                                [$dv30, $sumDv30] = $st->fetch(PDO::FETCH_NUM);
                            }
                            echo (int)$dv30;
                            ?>
                        </div>
                        <div class="sub muted">Montant cumulé : <?= eur($sumDv30 ?? 0) ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($topPac)): ?>
            <h3>Top matériels (90 jours)</h3>
            <div class="body">
                <table class="compact">
                    <thead><tr><th>Matériel</th><th style="text-align:right">Qté</th><th style="text-align:right">CA (HT)</th></tr></thead>
                    <tbody>
                    <?php foreach ($topPac as $p): ?>
                        <tr>
                            <td><?= h($p['nom'] ?? '—') ?></td>
                            <td class="mono" style="text-align:right"><?= (int)($p['qty'] ?? 0) ?></td>
                            <td class="mono" style="text-align:right"><?= eur($p['ca_ht'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($caMonths)): ?>
            <h3>CA — 6 derniers mois (TTC)</h3>
            <div class="body">
                <table class="compact">
                    <thead><tr><th>Mois</th><th style="text-align:right">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($caMonths as $row): ?>
                        <tr>
                            <td class="mono"><?= h(date('m/Y', strtotime($row['mois'].'-01'))) ?></td>
                            <td class="mono" style="text-align:right"><?= eur($row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
