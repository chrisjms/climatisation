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
    'all'   => 'Périmètre : tout l’historique',
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
<link rel="stylesheet" href="style.css">
<!-- Chart.js (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>


<div class="main">
    <h1>Analyses & rapports</h1>

    <div class="toolbar">
        <form method="get" action="analyses.php" class="inline">
            <label for="view">Visibilité :</label>
            <select name="view" id="view" onchange="this.form.submit()">
                <option value="day"   <?= $view==='day'  ?'selected':'' ?>>Par jours (<?= DAY_WINDOW ?> derniers)</option>
                <option value="month" <?= $view==='month'?'selected':'' ?>>Par mois (<?= MONTH_WINDOW ?> derniers)</option>
                <option value="year"  <?= $view==='year'?'selected':''  ?>>Par année (<?= YEAR_WINDOW ?> dernières)</option>
                <option value="all"   <?= $view==='all'  ?'selected':'' ?>>Tout (historique)</option>
            </select>
            <noscript><button type="submit" class="btn">Afficher</button></noscript>
        </form>

        <span class="badge"><?= htmlspecialchars($badgeText) ?></span>
    </div>

    <div class="grid">

        <!-- Cartes Graphiques -->
        <div class="card">
            <h3>Nombre de devis (<?= htmlspecialchars($granularityText) ?><?= $view==='all' ? ' — tout l’historique' : '' ?>)</h3>
            <canvas id="chartCountDevis"></canvas>
        </div>

        <div class="card">
            <h3>Nombre de factures (<?= htmlspecialchars($granularityText) ?><?= $view==='all' ? ' — tout l’historique' : '' ?>)</h3>
            <canvas id="chartCountFactures"></canvas>
        </div>

        <div class="card">
            <h3>Montant TTC des devis (<?= htmlspecialchars($granularityText) ?><?= $view==='all' ? ' — tout l’historique' : '' ?>)</h3>
            <canvas id="chartTotalDevis"></canvas>
        </div>

        <div class="card">
            <h3>Montant TTC des factures (<?= htmlspecialchars($granularityText) ?><?= $view==='all' ? ' — tout l’historique' : '' ?>)</h3>
            <canvas id="chartTotalFactures"></canvas>
        </div>

        <!-- Chiffre d'affaires TTC (factures) -->
        <div class="card full">
            <h3>Chiffre d’affaires TTC (Factures)</h3>

            <?php
            $years = $turnover['years'];   // [year => total_ttc]
            $total5 = $turnover['total5'];
            $totalAll = $turnover['totalAll'];
            ?>

            <table class="ca">
                <thead>
                    <tr>
                        <th>Année</th>
                        <th>Chiffre d’affaires TTC</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($years as $yr => $val): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$yr) ?></td>
                        <td><?= euro($val) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="kpi">
                <span class="pill">Total 5 ans : <?= euro($total5) ?></span>
                <span class="pill">Total global : <?= euro($totalAll) ?></span>
            </div>
        </div>

    </div>
</div>

<script>
/* Données PHP → JS */
const labelsDevis     = <?= json_encode($labelsDevis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const countDevis      = <?= json_encode($countDevis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const totalDevis      = <?= json_encode($totalDevis, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

const labelsFactures  = <?= json_encode($labelsFactures, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const countFactures   = <?= json_encode($countFactures, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const totalFactures   = <?= json_encode($totalFactures, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

// Pour le formatage, on traite "all" comme une échelle annuelle
const currentView = <?= json_encode($view === 'all' ? 'year' : $view) ?>;

function fmtLabel(label){
    // year: "2025"
    if (currentView === 'year') return label;

    // month: "YYYY-MM" → "MM/YYYY"
    if (currentView === 'month') {
        if (/^\d{4}-\d{2}$/.test(label)) {
            const [y,m] = label.split('-');
            return `${m}/${y}`;
        }
        return label;
    }

    // day: "YYYY-MM-DD" → "DD/MM"
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

// Création des graphiques
makeLineChart('chartCountDevis',    labelsDevis,    countDevis,   'Devis');
makeLineChart('chartCountFactures', labelsFactures, countFactures,'Factures');
makeLineChart('chartTotalDevis',    labelsDevis,    totalDevis,   'Total TTC devis', true);
makeLineChart('chartTotalFactures', labelsFactures, totalFactures,'Total TTC factures', true);
</script>
</body>
</html>
