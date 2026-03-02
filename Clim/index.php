<?php
// dashboard.php — Accueil enrichi pour votre app (KPIs + activité + top produits)
// Compatible avec vos pages existantes (clients, devis, factures, matériels, brochures)
// et tolérant aux schémas de BDD variés (auto-détection colonnes).

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "DEBUG START<br>";
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

// Lignes de devis (pour "Top matériels")
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
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
    <div class="page-header">
        <div>
            <h1>Bonjour, <?= h($_SESSION['username'] ?? 'Utilisateur') ?></h1>
            <div class="subtitle"><?= dmy($today) ?> — Tableau de bord</div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
        <a class="btn btn-primary" href="devis.php#form-devis">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Nouveau devis
        </a>
        <a class="btn" href="ajout_client.php?retour=index.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
            Nouveau client
        </a>
        <a class="btn" href="ajout_pac.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/></svg>
            Materiels
        </a>
        <a class="btn" href="brochures.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
            Brochures
        </a>
        <a class="btn" href="clientele.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Clientele
        </a>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-label">Clients</div>
            <div class="stat-value"><?= (int)$nbClients ?></div>
            <div class="stat-sub">Total en base</div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </div>
            <div class="stat-label">CA du mois (TTC)</div>
            <div class="stat-value"><?= eur($caMonth) ?></div>
            <div class="stat-sub"><?= dmy($firstMonth) ?> &rarr; <?= dmy($today) ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </div>
            <div class="stat-label">CA annuel (TTC)</div>
            <div class="stat-value"><?= eur($caYear) ?></div>
            <div class="stat-sub">Depuis le 01/01</div>
        </div>
        <?php if ($impayes !== null): ?>
        <div class="stat-card <?= $impayes > 0 ? 'orange' : 'green' ?>">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>
            </div>
            <div class="stat-label">Impayes</div>
            <div class="stat-value"><?= eur($impayes) ?></div>
            <div class="stat-sub">Factures en attente</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Two-column layout -->
    <div class="dashboard-grid">

        <!-- Left column -->
        <div class="card" style="padding:0;overflow:hidden;">
            <div class="card-section">
                <h3>Activite recente</h3>
                <?php if (!$activite): ?>
                    <p class="muted">Aucune activite recente.</p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($activite as $a):
                            $badgeClass = match($a['type']) {
                                'Client' => '',
                                'Devis' => 'warning',
                                'Devis' => 'success',
                                'Brochure' => 'neutral',
                                default => ''
                            };
                        ?>
                            <li>
                                <span>
                                    <span class="badge <?= $badgeClass ?>">
    <?= h($a['type']) ?>
</span>
                                    &nbsp;<?= h($a['label']) ?>
                                </span>
                                <span class="muted mono" style="display:flex;align-items:center;gap:8px;">
                                    <?= dmy($a['date']) ?>
                                    <?php if (!empty($a['url'])): ?>
                                        <a class="btn btn-sm" href="<?= h($a['url']) ?>">Ouvrir</a>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card-section">
                <h3>Derniers devis (TTC)</h3>
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
                                <td><?= h(trim(($d['nom'] ?? '') . ' ' . ($d['prenom'] ?? ''))) ?></td>
                                <td class="mono"><?= dmy($d['date_creation'] ?? '') ?></td>
                                <td class="mono" style="text-align:right"><?= eur($d['prix'] ?? null) ?></td>
                                <td><?= $hasFile ? '<a href="'.h($url).'" target="_blank">Ouvrir</a>' : '<span class="muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="muted">Aucun devis.</p>
                <?php endif; ?>
            </div>

            <div class="card-section">
                <h3>Dernieres factures (TTC)</h3>
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
                                <td><?= h(trim(($f['nom'] ?? '') . ' ' . ($f['prenom'] ?? ''))) ?></td>
                                <td class="mono"><?= dmy($f['date_creation'] ?? '') ?></td>
                                <td class="mono" style="text-align:right"><?= eur($f['prix'] ?? null) ?></td>
                                <td><?= $hasFile ? '<a href="'.h($urlF).'" target="_blank">Ouvrir</a>' : '<span class="muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="muted">Aucune facture.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right column -->
        <div style="display:grid;gap:var(--gap-3);align-content:start;">
            <!-- Pipeline -->
            <div class="card">
                <h3 style="margin-bottom:12px;">Vue pipeline</h3>
                <?php
                $dv30 = 0; $sumDv30 = 0.0;
                if ($hasDevis) {
                    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM($dvTotal),0) FROM devis WHERE DATE($dvDate) >= :dmin");
                    $st->execute([
    ':dmin' => date('Y-m-d', strtotime('-30 days'))
]); 
                    [$dv30, $sumDv30] = $st->fetch(PDO::FETCH_NUM);
                }
                ?>
                <div class="stat-card" style="box-shadow:none;border:1px solid var(--bd);">
                    <div class="stat-label">Devis (30 derniers jours)</div>
                    <div class="stat-value"><?= (int)$dv30 ?></div>
                    <div class="stat-sub">Montant cumule : <?= eur($sumDv30 ?? 0) ?></div>
                </div>
                <?php if ($toFollow !== null && $toFollow > 0): ?>
                <div class="stat-card orange" style="box-shadow:none;border:1px solid var(--bd);margin-top:8px;">
                    <div class="stat-label">Devis a relancer</div>
                    <div class="stat-value"><?= (int)$toFollow ?></div>
                    <div class="stat-sub">Sans facture associee (30j)</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($topPac)): ?>
            <div class="card">
                <h3>Top materiels (90 jours)</h3>
                <table class="compact">
                    <thead><tr><th>Materiel</th><th style="text-align:right">Qte</th><th style="text-align:right">CA (HT)</th></tr></thead>
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
            <div class="card">
                <h3>CA — 6 derniers mois (TTC)</h3>
                <table class="compact">
                    <thead><tr><th>Mois</th><th style="text-align:right">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($caMonths as $row): ?>
                        <tr>
                            <td class="mono">
    <?= h(date('m/Y', strtotime($row['mois'] . '-01'))) ?>
</td>
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
