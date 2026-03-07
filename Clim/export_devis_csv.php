<?php
require 'auth.php';
require 'config.php';

$type     = $_GET['type'] ?? 'devis';
$q        = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');

if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

/* ── Helpers ── */
function _tbl_exists(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch(Throwable $e){ return false; }
}
function _cols(PDO $pdo, string $t): array {
    try { $st = $pdo->query("SHOW COLUMNS FROM `$t`"); return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'Field'); } catch(Throwable $e){ return []; }
}
function _pick(array $prefs, array $cols, $fb = null) {
    foreach ($prefs as $c) if (in_array($c, $cols, true)) return $c;
    return $fb;
}

$rows = [];

if ($type === 'devis') {
    if (!_tbl_exists($pdo, 'devis') || !_tbl_exists($pdo, 'clients')) { $rows = []; } else {
        $cd = _cols($pdo, 'devis'); $cc = _cols($pdo, 'clients');
        $dateCol = _pick(['date_creation','created_at','date','date_devis'], $cd, 'date_creation');
        $montant = _pick(['montant_ttc','total_ttc','montant'], $cd, 'montant_ttc');
        $numero  = _pick(['numero','num_devis','reference'], $cd, 'numero');
        $clientId = _pick(['client_id','id_client'], $cd, 'client_id');
        $nom = _pick(['nom','last_name','lastname'], $cc, 'nom');
        $pre = _pick(['prenom','first_name','firstname'], $cc, 'prenom');
        $phone = _pick(['telephone','tel','phone','mobile','gsm'], $cc);

        $sql = "SELECT d.`$numero` AS numero, c.`$pre` AS prenom, c.`$nom` AS nom, d.`$dateCol` AS date_doc, d.`$montant` AS montant_ttc
                FROM devis d JOIN clients c ON c.id = d.`$clientId`";
        $where = []; $params = [];
        if ($q !== '') { $where[] = "(c.`$nom` LIKE :q OR c.`$pre` LIKE :q".($phone ? " OR c.`$phone` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
        if ($dateFrom !== '') { $where[] = "DATE(d.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
        if ($dateTo   !== '') { $where[] = "DATE(d.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY d.`$dateCol` DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($type === 'bdc') {
    if (!_tbl_exists($pdo, 'bons_de_commande') || !_tbl_exists($pdo, 'devis') || !_tbl_exists($pdo, 'clients')) { $rows = []; } else {
        $cb = _cols($pdo, 'bons_de_commande'); $cd = _cols($pdo, 'devis'); $cc = _cols($pdo, 'clients');
        $dateCol = _pick(['date_creation','created_at','date'], $cb, 'date_creation');
        $montant = _pick(['montant_ttc','total_ttc','montant'], $cb, 'montant_ttc');
        $numero  = _pick(['numero','reference'], $cb, 'numero');
        $dvId    = _pick(['id'], $cd, 'id');
        $dvCli   = _pick(['client_id'], $cd, 'client_id');
        $nom = _pick(['nom','last_name','lastname'], $cc, 'nom');
        $pre = _pick(['prenom','first_name','firstname'], $cc, 'prenom');
        $phone = _pick(['telephone','tel','phone','mobile','gsm'], $cc);

        $sql = "SELECT b.`$numero` AS numero, c.`$pre` AS prenom, c.`$nom` AS nom, b.`$dateCol` AS date_doc, b.`$montant` AS montant_ttc
                FROM bons_de_commande b JOIN devis d ON d.`$dvId` = b.devis_id JOIN clients c ON c.id = d.`$dvCli`";
        $where = []; $params = [];
        if ($q !== '') { $where[] = "(c.`$nom` LIKE :q OR c.`$pre` LIKE :q".($phone ? " OR c.`$phone` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
        if ($dateFrom !== '') { $where[] = "DATE(b.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
        if ($dateTo   !== '') { $where[] = "DATE(b.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY b.`$dateCol` DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($type === 'factures') {
    if (!_tbl_exists($pdo, 'factures') || !_tbl_exists($pdo, 'clients')) { $rows = []; } else {
        $cf = _cols($pdo, 'factures'); $cc = _cols($pdo, 'clients');
        $dateCol = _pick(['date_facture','date_creation','created_at','date'], $cf, 'date_creation');
        $montant = _pick(['montant_ttc','total_ttc','montant'], $cf, 'montant_ttc');
        $numero  = _pick(['numero','num_facture','reference'], $cf, 'numero');
        $nom = _pick(['nom','last_name','lastname'], $cc, 'nom');
        $pre = _pick(['prenom','first_name','firstname'], $cc, 'prenom');
        $phone = _pick(['telephone','tel','phone','mobile','gsm'], $cc);

        if (in_array('client_id', $cf, true)) {
            $join = "JOIN clients c ON c.id = f.client_id";
        } else {
            if (!_tbl_exists($pdo, 'devis')) { $rows = []; goto output; }
            $cd = _cols($pdo, 'devis');
            $dvId = _pick(['id'], $cd, 'id');
            $dvCli = _pick(['client_id'], $cd, 'client_id');
            $join = "JOIN devis d ON d.`$dvId` = f.devis_id JOIN clients c ON c.id = d.`$dvCli`";
        }
        $sql = "SELECT f.`$numero` AS numero, c.`$pre` AS prenom, c.`$nom` AS nom, f.`$dateCol` AS date_doc, f.`$montant` AS montant_ttc
                FROM factures f $join";
        $where = []; $params = [];
        if ($q !== '') { $where[] = "(c.`$nom` LIKE :q OR c.`$pre` LIKE :q".($phone ? " OR c.`$phone` LIKE :q" : "").")"; $params[':q'] = "%$q%"; }
        if ($dateFrom !== '') { $where[] = "DATE(f.`$dateCol`) >= :df"; $params[':df'] = $dateFrom; }
        if ($dateTo   !== '') { $where[] = "DATE(f.`$dateCol`) <= :dt"; $params[':dt'] = $dateTo; }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY f.`$dateCol` DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

output:
$labels = ['devis' => 'devis', 'bdc' => 'bons_de_commande', 'factures' => 'factures'];
$filename = ($labels[$type] ?? $type) . '_export_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');
fputcsv($out, ['Numero', 'Client', 'Date', 'Montant TTC'], ';');
foreach ($rows as $r) {
    fputcsv($out, [
        $r['numero'] ?? '',
        trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
        $r['date_doc'] ?? '',
        $r['montant_ttc'] ?? '',
    ], ';');
}
fclose($out);
exit;
