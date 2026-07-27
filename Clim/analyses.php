<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* === Paramètres d'affichage === */
const DAY_WINDOW   = 31; // nb de jours à afficher en vue "jour"
const MONTH_WINDOW = 12; // nb de mois à afficher en vue "mois"
const YEAR_WINDOW  = 5;  // nb d'années à afficher en vue "année"

// Vues disponibles (sans vue globale ; ajout de "all" = tout l'historique)
$allowedViews = ['day','month','year','all'];

// Par défaut : "day" (par jours)
$view = $_GET['view'] ?? 'day';
$view = in_array($view, $allowedViews, true) ? $view : 'day';

/**
 * Récupère les agrégats (count, sum) pour un tableau donné ('devis' ou 'factures')
 * @return array [labels[], counts[], totals[]]
 */
function getAggregates(PDO $pdo, string $table, string $view): array {
    if (!in_array($table, ['devis', 'factures'], true)) {
        throw new InvalidArgumentException('table invalide');
    }

    if ($view === 'day') {
        // 31 jours glissants, groupés par YYYY-MM-DD
        $sql = "
            SELECT DATE(date_creation) AS dday,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(montant_ttc), 0) AS total
              FROM {$table}
             WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL ".(DAY_WINDOW-1)." DAY)
          GROUP BY DATE(date_creation)
          ORDER BY DATE(date_creation)
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        foreach ($rows as $r) { $index[$r['dday']] = $r; }

        $labels = [];
        $counts = [];
        $totals = [];
        $start  = new DateTime('today');
        $start->modify('-'.(DAY_WINDOW-1).' days');
        $cursor = clone $start;

        for ($i = 0; $i < DAY_WINDOW; $i++) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $key;
            $counts[] = isset($index[$key]) ? (int)$index[$key]['cnt'] : 0;
            $totals[] = isset($index[$key]) ? (float)$index[$key]['total'] : 0.0;
            $cursor->modify('+1 day');
        }

    } elseif ($view === 'month') {
        // 12 mois glissants, groupés par YYYY-MM
        $sql = "
            SELECT DATE_FORMAT(date_creation, '%Y-%m') AS period,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(montant_ttc), 0) AS total
              FROM {$table}
             WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL ".MONTH_WINDOW." MONTH)
          GROUP BY YEAR(date_creation), MONTH(date_creation)
          ORDER BY YEAR(date_creation), MONTH(date_creation)
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        foreach ($rows as $r) { $index[$r['period']] = $r; }

        $labels = [];
        $counts = [];
        $totals = [];
        $now   = new DateTime('first day of this month');
        $start = (clone $now)->modify('-'.(MONTH_WINDOW-1).' months');
        $cursor = clone $start;

        for ($i = 0; $i < MONTH_WINDOW; $i++) {
            $label = $cursor->format('Y-m');
            $labels[] = $label;
            $counts[] = isset($index[$label]) ? (int)$index[$label]['cnt'] : 0;
            $totals[] = isset($index[$label]) ? (float)$index[$label]['total'] : 0.0;
            $cursor->modify('+1 month');
        }

    } elseif ($view === 'year') {
        // 5 dernières années, groupées par YYYY
        $currentYear = (int)date('Y');
        $startYear   = $currentYear - (YEAR_WINDOW - 1);

        $sql = "
            SELECT YEAR(date_creation) AS yr,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(montant_ttc), 0) AS total
              FROM {$table}
             WHERE YEAR(date_creation) BETWEEN :start AND :end
          GROUP BY YEAR(date_creation)
          ORDER BY YEAR(date_creation)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start' => $startYear, ':end' => $currentYear]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        foreach ($rows as $r) { $index[(string)$r['yr']] = $r; }

        $labels = [];
        $counts = [];
        $totals = [];
        for ($y = $startYear; $y <= $currentYear; $y++) {
            $key = (string)$y;
            $labels[] = $key;
            $counts[] = isset($index[$key]) ? (int)$index[$key]['cnt'] : 0;
            $totals[] = isset($index[$key]) ? (float)$index[$key]['total'] : 0.0;
        }

    } else { // $view === 'all' → Tout l'historique (agrégation par année)
        $sql = "
            SELECT YEAR(date_creation) AS yr,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(montant_ttc), 0) AS total
              FROM {$table}
          GROUP BY YEAR(date_creation)
          ORDER BY YEAR(date_creation)
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) { return [[], [], []]; }

        $minYear = (int)$rows[0]['yr'];
        $maxYear = (int)$rows[count($rows)-1]['yr'];

        $index = [];
        foreach ($rows as $r) { $index[(string)$r['yr']] = $r; }

        $labels = [];
        $counts = [];
        $totals = [];
        for ($y = $minYear; $y <= $maxYear; $y++) {
            $key = (string)$y;
            $labels[] = $key;
            $counts[] = isset($index[$key]) ? (int)$index[$key]['cnt'] : 0;
            $totals[] = isset($index[$key]) ? (float)$index[$key]['total'] : 0.0;
        }
    }

    return [$labels, $counts, $totals];
}

/**
 * Chiffre d'affaires TTC (factures) :
 * - Détail par année (5 dernières années)
 * - Total 5 ans
 * - Total global (tout l'historique)
 * @return array{years: array<int,float>, total5: float, totalAll: float}
 */
function getTurnover(PDO $pdo): array {
    $currentYear = (int)date('Y');
    $startYear   = $currentYear - 4;

    // Sommes par année (5 dernières années)
    $sql = "
        SELECT YEAR(date_creation) AS yr,
               COALESCE(SUM(montant_ttc), 0) AS total
          FROM factures
         WHERE YEAR(date_creation) BETWEEN :start AND :end
      GROUP BY YEAR(date_creation)
      ORDER BY YEAR(date_creation)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startYear, ':end' => $currentYear]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $index = [];
    foreach ($rows as $r) { $index[(int)$r['yr']] = (float)$r['total']; }

    $years = [];
    for ($y = $startYear; $y <= $currentYear; $y++) {
        $years[$y] = $index[$y] ?? 0.0;
    }

    // Total 5 ans
    $total5 = array_sum($years);

    // Total global (tout l'historique)
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_ttc), 0) FROM factures");
    $totalAll = (float)$stmt->fetchColumn();

    return [
        'years'    => $years,
        'total5'   => $total5,
        'totalAll' => $totalAll,
    ];
}

/** Format € côté PHP */
function euro(float $v, int $decimals = 2): string {
    return number_format($v, $decimals, ',', ' ') . ' €';
}

try {
    // Devis (pour les graphes)
    [$labelsDevis, $countDevis, $totalDevis] = getAggregates($pdo, 'devis', $view);
    // Factures (pour les graphes)
    [$labelsFactures, $countFactures, $totalFactures] = getAggregates($pdo, 'factures', $view);
    // Chiffre d'affaires TTC (factures)
    $turnover = getTurnover($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erreur lors du calcul: " . htmlspecialchars($e->getMessage());
    exit;
}

/* Badge de périmètre */
$badgeText = match ($view) {
    'day'   => 'Périmètre : '.DAY_WINDOW.' derniers jours',
    'month' => 'Périmètre : '.MONTH_WINDOW.' mois glissants',
    'year'  => 'Périmètre : '.YEAR_WINDOW.' dernières années',
    'all'   => 'Périmètre : tout l\'historique',
};

/* Texte pour titres */
function granularityText(string $v): string {
    return $v === 'month' ? 'par mois' : ($v === 'day' ? 'par jour' : 'par an');
}
$granularityText = granularityText($view === 'all' ? 'year' : $view);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Analyses & rapports</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
<!-- Chart.js (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1>Analyses & rapports</h1>
            <div class="subtitle">Suivi de l'activite, chiffre d'affaires et indicateurs cles de performance</div>
        </div>
    </div>

    <!-- View Tabs -->
    <div class="toolbar">
        <div class="view-tabs">
            <a href="analyses.php?view=day" class="view-tab <?= $view === 'day' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                Jours
            </a>
            <a href="analyses.php?view=month" class="view-tab <?= $view === 'month' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/></svg>
                Mois
            </a>
            <a href="analyses.php?view=year" class="view-tab <?= $view === 'year' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                Annees
            </a>
            <a href="analyses.php?view=all" class="view-tab <?= $view === 'all' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                Historique
            </a>
        </div>
    </div>

    <!-- Scope Summary Banner -->
    <div class="card scope-banner">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span class="muted scope-text"><?= htmlspecialchars($badgeText) ?></span>
    </div>

    <!-- KPI Summary Cards -->
    <?php
    $years = $turnover['years'];   // [year => total_ttc]
    $total5 = $turnover['total5'];
    $totalAll = $turnover['totalAll'];
    ?>
    <div class="kpi-grid">
        <div class="stat-card green">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </div>
            <div>
                <div class="stat-label">Total 5 ans (TTC)</div>
                <div class="stat-value"><?= euro($total5) ?></div>
                <div class="stat-sub">Chiffre d'affaires factures</div>
            </div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>
            </div>
            <div>
                <div class="stat-label">Total global (TTC)</div>
                <div class="stat-value"><?= euro($totalAll) ?></div>
                <div class="stat-sub">Tout l'historique</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div class="stat-label">Devis (periode)</div>
                <div class="stat-value"><?= array_sum($countDevis) ?></div>
                <div class="stat-sub"><?= euro(array_sum($totalDevis)) ?> TTC</div>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </div>
            <div>
                <div class="stat-label">Factures (periode)</div>
                <div class="stat-value"><?= array_sum($countFactures) ?></div>
                <div class="stat-sub"><?= euro(array_sum($totalFactures)) ?> TTC</div>
            </div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="grid">

        <div class="card">
            <h3>Nombre de devis <?= htmlspecialchars($granularityText) ?><?= $view === 'all' ? ' — tout l\'historique' : '' ?></h3>
            <canvas id="chartCountDevis"></canvas>
        </div>

        <div class="card">
            <h3>Nombre de factures <?= htmlspecialchars($granularityText) ?><?= $view === 'all' ? ' — tout l\'historique' : '' ?></h3>
            <canvas id="chartCountFactures"></canvas>
        </div>

        <div class="card">
            <h3>Montant TTC des devis <?= htmlspecialchars($granularityText) ?><?= $view === 'all' ? ' — tout l\'historique' : '' ?></h3>
            <canvas id="chartTotalDevis"></canvas>
        </div>

        <div class="card">
            <h3>Montant TTC des factures <?= htmlspecialchars($granularityText) ?><?= $view === 'all' ? ' — tout l\'historique' : '' ?></h3>
            <canvas id="chartTotalFactures"></canvas>
        </div>

    </div>

    <!-- Revenue Table -->
    <div class="card full mt-3">
        <h3>Chiffre d'affaires TTC par annee (Factures)</h3>
        <div class="table-wrap">
            <table class="ca">
                <thead>
                    <tr>
                        <th>Annee</th>
                        <th>Chiffre d'affaires TTC</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($years as $yr => $val): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars((string)$yr) ?></td>
                        <td class="mono"><?= euro($val) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
/* Donnees PHP -> JS — flags HEX_* pour neutraliser `</script>` ou `<!--` injectés via les données BDD */
<?php $JSON_SAFE = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT; ?>
const labelsDevis     = <?= json_encode($labelsDevis, $JSON_SAFE) ?>;
const countDevis      = <?= json_encode($countDevis, $JSON_SAFE) ?>;
const totalDevis      = <?= json_encode($totalDevis, $JSON_SAFE) ?>;

const labelsFactures  = <?= json_encode($labelsFactures, $JSON_SAFE) ?>;
const countFactures   = <?= json_encode($countFactures, $JSON_SAFE) ?>;
const totalFactures   = <?= json_encode($totalFactures, $JSON_SAFE) ?>;

// Pour le formatage, on traite "all" comme une echelle annuelle
const currentView = <?= json_encode($view === 'all' ? 'year' : $view, $JSON_SAFE) ?>;

function fmtLabel(label){
    // year: "2025"
    if (currentView === 'year') return label;

    // month: "YYYY-MM" -> "MM/YYYY"
    if (currentView === 'month') {
        if (/^\d{4}-\d{2}$/.test(label)) {
            const [y,m] = label.split('-');
            return `${m}/${y}`;
        }
        return label;
    }

    // day: "YYYY-MM-DD" -> "DD/MM"
    if (currentView === 'day') {
        if (/^\d{4}-\d{2}-\d{2}$/.test(label)) {
            const [y,m,d] = label.split('-');
            return `${d}/${m}`;
        }
        return label;
    }

    return label;
}

function makeLineChart(ctxId, labels, data, title, isCurrency=false){
    const ctx = document.getElementById(ctxId);
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: title,
                data,
                tension: 0.3,
                fill: false,
                pointRadius: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            if (!items?.length) return '';
                            const raw = items[0].label ?? '';
                            if (currentView === 'month' && /^\d{4}-\d{2}$/.test(raw)) {
                                const [y,m] = raw.split('-');
                                return `${m}/${y}`;
                            }
                            if (currentView === 'day' && /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
                                const [y,m,d] = raw.split('-');
                                return `${d}/${m}/${y}`;
                            }
                            return raw;
                        },
                        label: (ctx) => {
                            const val = ctx.parsed.y ?? 0;
                            if (isCurrency) {
                                return new Intl.NumberFormat('fr-FR', { style:'currency', currency:'EUR' }).format(val);
                            }
                            return String(val);
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { callback: (val, i) => fmtLabel(labels[i] ?? '') } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (val) => {
                            if (isCurrency) {
                                return new Intl.NumberFormat('fr-FR', {
                                    style:'currency', currency:'EUR', maximumFractionDigits:0
                                }).format(val);
                            }
                            return val;
                        }
                    }
                }
            }
        }
    });
}

// Creation des graphiques
makeLineChart('chartCountDevis',    labelsDevis,    countDevis,   'Devis');
makeLineChart('chartCountFactures', labelsFactures, countFactures,'Factures');
makeLineChart('chartTotalDevis',    labelsDevis,    totalDevis,   'Total TTC devis', true);
makeLineChart('chartTotalFactures', labelsFactures, totalFactures,'Total TTC factures', true);
</script>
</body>
</html>
