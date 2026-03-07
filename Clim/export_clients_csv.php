<?php
require 'auth.php';
require 'config.php';

$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $sql = 'SELECT id, nom, prenom, telephone, email
              FROM clients
             WHERE nom LIKE :s OR prenom LIKE :s OR telephone LIKE :s OR email LIKE :s
          ORDER BY nom ASC, prenom ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':s' => "%{$q}%"]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $clients = $pdo->query('SELECT id, nom, prenom, telephone, email FROM clients ORDER BY nom ASC, prenom ASC')
               ->fetchAll(PDO::FETCH_ASSOC);
}

$filename = 'clients_export_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Nom', 'Prenom', 'Telephone', 'Email'], ';');
foreach ($clients as $c) {
    fputcsv($out, [
        $c['id'],
        $c['nom'] ?? '',
        $c['prenom'] ?? '',
        $c['telephone'] ?? '',
        $c['email'] ?? '',
    ], ';');
}
fclose($out);
exit;
