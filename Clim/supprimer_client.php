<?php
/**
 * supprimer_client.php
 * - Supprime un client et toutes ses données liées
 * - Ajout : suppression des BDC (bons de commande) quelle que soit la structure (client_id / devis_id / facture_id)
 * - Supprime aussi les fichiers PDF/doc associés
 */

require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ===================== Helpers ===================== */
function back(array $params = []) {
    $qs = http_build_query($params);
    header('Location: clientele.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}
function ensure_dir(string $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
}
function log_msg(string $msg) {
    ensure_dir(__DIR__ . '/logs');
    @file_put_contents(__DIR__ . '/logs/delete_client.log', '['.date('c')."] $msg\n", FILE_APPEND);
}
function to_abs(string $path): string {
    if ($path === '') return $path;
    if (preg_match('~^/|^[A-Za-z]:[\\\\/]~', $path)) return $path;
    return __DIR__ . '/' . ltrim($path, '/');
}
function safe_unlink(string $path): bool {
    $abs = to_abs($path);
    return is_file($abs) ? @unlink($abs) : false;
}
function file_candidates(string $raw, ?string $defaultDir = null): array {
    $c = [];
    $raw = trim($raw ?? '');
    if ($raw === '') return $c;
    $c[] = $raw;
    $b = basename($raw);
    if ($b && $defaultDir) $c[] = rtrim($defaultDir, '/\\').'/'.$b;
    return array_values(array_unique($c));
}
function table_exists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}
function list_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => $r['Field'], $rows ?: []);
}

/**
 * Diagnostique les références FK sur clients.id et compte les lignes bloquantes.
 * Retourne un tableau [ ['table'=>'X','column'=>'client_id','cnt'=>N,'delete_rule'=>'CASCADE|RESTRICT|...'], ... ]
 */
function diagnose_fk_on_client(PDO $pdo, int $clientId): array {
    $rows = [];
    $sql = "
        SELECT
            kcu.TABLE_NAME   AS tbl,
            kcu.COLUMN_NAME  AS col,
            rc.DELETE_RULE   AS del_rule
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        JOIN information_schema.KEY_COLUMN_USAGE kcu
          ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
         AND rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
        WHERE rc.REFERENCED_TABLE_NAME = 'clients'
          AND rc.CONSTRAINT_SCHEMA = DATABASE()
    ";
    try {
        foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $r) {
            $tbl = $r['tbl']; $col = $r['col'];
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE `$col` = ?");
            $stmt->execute([$clientId]);
            $cnt = (int)$stmt->fetchColumn();
            if ($cnt > 0) {
                $rows[] = ['table'=>$tbl, 'column'=>$col, 'cnt'=>$cnt, 'delete_rule'=>$r['del_rule']];
            }
        }
    } catch (Throwable $e) {
        log_msg("diagnose_fk_on_client error: ".$e->getMessage());
    }
    return $rows;
}

/**
 * Détecte la table des BDC et les colonnes clés.
 * Retourne null si aucune table plausible n'est trouvée.
 * Ex: ['table'=>'bons_de_commande', 'col_id'=>'id', 'col_client'=>'client_id'|null, 'col_devis'=>'devis_id'|null, 'col_facture'=>'facture_id'|null, 'col_pdf'=>'fichier_pdf'|null]
 */
function find_bdc_mapping(PDO $pdo): ?array {
    $candidates = [
        'bons_de_commande','bons_commandes','bon_de_commande','bon_commandes',
        'bdc','bon_commande','commandes','bonsdecommande','bondecommande','purchase_orders'
    ];
    $idCols   = ['id','bdc_id','id_bdc','id_bondecommande','id_commande'];
    $cliCols  = ['client_id','id_client','client','idclient'];
    $devCols  = ['devis_id','id_devis'];
    $facCols  = ['facture_id','id_facture'];
    $pdfCols  = ['fichier_pdf','pdf_path','file_path','chemin_pdf','pdf','path_pdf','fichier','fichier_bdc','document','doc_path'];

    foreach ($candidates as $t) {
        if (!table_exists($pdo, $t)) continue;
        $cols = list_columns($pdo, $t);
        $find = function(array $want) use ($cols) {
            foreach ($want as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };
        $col_id   = $find($idCols)  ?? 'id';
        $col_cli  = $find($cliCols);
        $col_dev  = $find($devCols);
        $col_fac  = $find($facCols);
        $col_pdf  = $find($pdfCols);
        return [
            'table' => $t,
            'col_id' => $col_id,
            'col_client' => $col_cli,
            'col_devis' => $col_dev,
            'col_facture' => $col_fac,
            'col_pdf' => $col_pdf,
        ];
    }
    return null;
}

/**
 * Récupère les BDC à supprimer (pour suppression de fichiers ensuite)
 * Retourne un tableau unique de lignes ['id'=>int,'fichier_pdf'=>string|null]
 */
function fetch_bdc_rows(PDO $pdo, int $clientId, array $devisIds, array $factureIds, ?array $map): array {
    if (!$map) return [];
    $t = $map['table'];
    $rows = [];
    try {
        if ($map['col_client']) {
            $stmt = $pdo->prepare("SELECT `{$map['col_id']}` AS id, ".($map['col_pdf'] ? "`{$map['col_pdf']}` AS fichier_pdf" : "NULL AS fichier_pdf")." FROM `$t` WHERE `{$map['col_client']}` = ?");
            $stmt->execute([$clientId]);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        if ($map['col_devis'] && $devisIds) {
            $in = implode(',', array_fill(0, count($devisIds), '?'));
            $stmt = $pdo->prepare("SELECT `{$map['col_id']}` AS id, ".($map['col_pdf'] ? "`{$map['col_pdf']}` AS fichier_pdf" : "NULL AS fichier_pdf")." FROM `$t` WHERE `{$map['col_devis']}` IN ($in)");
            $stmt->execute($devisIds);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        if ($map['col_facture'] && $factureIds) {
            $in = implode(',', array_fill(0, count($factureIds), '?'));
            $stmt = $pdo->prepare("SELECT `{$map['col_id']}` AS id, ".($map['col_pdf'] ? "`{$map['col_pdf']}` AS fichier_pdf" : "NULL AS fichier_pdf")." FROM `$t` WHERE `{$map['col_facture']}` IN ($in)");
            $stmt->execute($factureIds);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
    } catch (Throwable $e) {
        log_msg("fetch_bdc_rows error: ".$e->getMessage());
    }

    // Dédupliquer par id
    $uniq = [];
    foreach ($rows as $r) { $uniq[(string)($r['id'] ?? '')] = $r; }
    return array_values($uniq);
}

/* ================= Entrées & garde-fous ================= */
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$csrf      = $_POST['csrf_token'] ?? '';
$debug     = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

if ($client_id <= 0) back(['err' => 'Client invalide.']);
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    back(['err' => 'Jeton CSRF invalide.']);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Vérifier existence client
    $stmt = $pdo->prepare('SELECT id, nom, prenom FROM clients WHERE id = ?');
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) back(['err' => "Client introuvable (#$client_id)."]);

    /* ======== Collecte des éléments liés (pour debug & suppression fichiers) ======== */
    // Devis
    $stmt = $pdo->prepare('SELECT id, fichier_pdf FROM devis WHERE client_id = ?');
    $stmt->execute([$client_id]);
    $devis_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $devis_ids  = array_map(fn($r)=>(int)$r['id'], $devis_rows);

    // Factures
    $stmt = $pdo->prepare('SELECT id, fichier_pdf FROM factures WHERE client_id = ?');
    $stmt->execute([$client_id]);
    $factures_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $facture_ids   = array_map(fn($r)=>(int)$r['id'], $factures_rows);

    // Documents client (MyISAM)
    $stmt = $pdo->prepare('SELECT id, file_path FROM client_documents WHERE client_id = ?');
    $stmt->execute([$client_id]);
    $doc_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Détection table/colonnes BDC + collecte des BDC (quel que soit le lien)
    $bdc_map  = find_bdc_mapping($pdo);
    $bdc_rows = fetch_bdc_rows($pdo, $client_id, $devis_ids, $facture_ids, $bdc_map);

    // Candidats fichiers à supprimer après commit
    $files_pdf = [];
    foreach ($devis_rows as $d) {
        if (!empty($d['fichier_pdf'])) {
            foreach (file_candidates((string)$d['fichier_pdf'], 'devis_pdf') as $cand) $files_pdf[] = $cand;
        }
    }
    foreach ($factures_rows as $f) {
        if (!empty($f['fichier_pdf'])) {
            foreach (file_candidates((string)$f['fichier_pdf'], 'facture_pdf') as $cand) $files_pdf[] = $cand;
        }
    }
    foreach ($bdc_rows as $b) {
        $pdf = trim((string)($b['fichier_pdf'] ?? ''));
        if ($pdf !== '') {
            // répertoire par défaut le plus courant pour BDC
            foreach (file_candidates($pdf, 'bdc_pdf') as $cand) $files_pdf[] = $cand;
            // + quelques autres candidats communs
            foreach (['bons_commandes_pdf','bon_de_commande_pdf','uploads','documents','bdc'] as $dir) {
                foreach (file_candidates($pdf, $dir) as $cand) $files_pdf[] = $cand;
            }
        }
    }
    $files_pdf = array_values(array_unique($files_pdf));

    $files_docs = array_values(array_unique(array_filter(array_map(
        fn($r)=> (string)($r['file_path'] ?? ''), $doc_rows
    ))));

    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== DEBUG suppression client #$client_id ({$client['nom']} {$client['prenom']}) ===\n";
        echo "- Devis: ".count($devis_rows)." (IDs: ".implode(',', $devis_ids).")\n";
        echo "- Factures: ".count($factures_rows)." (IDs: ".implode(',', $facture_ids).")\n";
        echo "- BDC: ".count($bdc_rows)." via table ".($bdc_map['table'] ?? '(aucune)')."\n";
        echo "- Documents: ".count($doc_rows)."\n";
        echo "- Fichiers PDF à supprimer: ".count($files_pdf)."\n";
        echo "- Fichiers documents à supprimer: ".count($files_docs)."\n";
        $fk = diagnose_fk_on_client($pdo, $client_id);
        echo "- FK actives pointant encore le client: ".count($fk)."\n";
        foreach ($fk as $x) {
            echo "  * {$x['table']}.{$x['column']} -> {$x['cnt']} lignes (DELETE_RULE={$x['delete_rule']})\n";
        }
        exit;
    }

    /* ===================== Suppression transactionnelle ===================== */
    $pdo->beginTransaction();

    // 0) BDC d'abord (pour éviter FK si BDC -> devis/factures/client)
    if ($bdc_map) {
        $t = $bdc_map['table'];
        // Supprimer par client_id si la colonne existe
        if ($bdc_map['col_client']) {
            $stmt = $pdo->prepare("DELETE FROM `$t` WHERE `{$bdc_map['col_client']}` = ?");
            $stmt->execute([$client_id]);
        }
        // Supprimer par devis_id si la colonne existe
        if ($bdc_map['col_devis'] && $devis_ids) {
            $in = implode(',', array_fill(0, count($devis_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM `$t` WHERE `{$bdc_map['col_devis']}` IN ($in)");
            $stmt->execute($devis_ids);
        }
        // Supprimer par facture_id si la colonne existe
        if ($bdc_map['col_facture'] && $facture_ids) {
            $in = implode(',', array_fill(0, count($facture_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM `$t` WHERE `{$bdc_map['col_facture']}` IN ($in)");
            $stmt->execute($facture_ids);
        }
    } else {
        // Fallback historique (si table standard bons_de_commande + liaison par devis_id uniquement)
        if ($devis_ids && table_exists($pdo, 'bons_de_commande')) {
            $in = implode(',', array_fill(0, count($devis_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM bons_de_commande WHERE devis_id IN ($in)");
            $stmt->execute($devis_ids);
        }
    }

    // 1) Nettoyage explicite des tables dépendantes des DEVIS (au cas où CASCADE absent)
    if ($devis_ids) {
        // devis_items
        $stmt = $pdo->prepare("DELETE di FROM devis_items di JOIN devis d ON d.id=di.devis_id WHERE d.client_id=?");
        try { $stmt->execute([$client_id]); } catch (Throwable $e) {}

        // devis_lignes
        $stmt = $pdo->prepare("DELETE dl FROM devis_lignes dl JOIN devis d ON d.id=dl.devis_id WHERE d.client_id=?");
        try { $stmt->execute([$client_id]); } catch (Throwable $e) {}

        // devis_paiements
        $stmt = $pdo->prepare("DELETE dp FROM devis_paiements dp JOIN devis d ON d.id=dp.devis_id WHERE d.client_id=?");
        try { $stmt->execute([$client_id]); } catch (Throwable $e) {}
    }

    // 2) Factures du client (si pas de FK cascade)
    if ($facture_ids) {
        // Si vous avez d'autres tables liées aux factures, supprimez-les ici en amont.
        $stmt = $pdo->prepare('DELETE FROM factures WHERE client_id = ?');
        $stmt->execute([$client_id]);
    }

    // 3) Devis du client
    $stmt = $pdo->prepare('DELETE FROM devis WHERE client_id = ?');
    $stmt->execute([$client_id]);

    // 4) Emails / Téléphones (par sécurité même si CASCADE existe)
    $stmt = $pdo->prepare('DELETE FROM client_emails WHERE client_id = ?');
    $stmt->execute([$client_id]);
    $stmt = $pdo->prepare('DELETE FROM client_telephones WHERE client_id = ?');
    $stmt->execute([$client_id]);

    // 5) Client
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id = ?');
    $stmt->execute([$client_id]);

    $pdo->commit();

    /* ===================== Post-commit : fichiers & MyISAM ===================== */

    // client_documents (MyISAM) : lignes + fichiers
    if ($doc_rows) {
        $stmt = $pdo->prepare('DELETE FROM client_documents WHERE client_id = ?');
        try { $stmt->execute([$client_id]); } catch (Throwable $e) {
            log_msg("Suppression client_documents échouée mais ignorée: ".$e->getMessage());
        }
    }
    foreach ($files_docs as $f) { safe_unlink($f); }
    foreach ($files_pdf  as $f) { safe_unlink($f); }

    back(['msg' => 'Client et données associées supprimés avec succès.']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }

    // Diagnostic FK rapide
    $fk = [];
    try { $fk = diagnose_fk_on_client($pdo, $client_id); } catch (Throwable $e2) {}

    $summary = [];
    foreach ($fk as $x) {
        $summary[] = "{$x['table']}({$x['column']}):{$x['cnt']}";
    }
    $hint = $summary ? ' | Références restantes: '.implode(', ', $summary) : '';

    log_msg("Erreur suppression client #$client_id : ".$e->getMessage().$hint);

    $msg = $e->getMessage();
    if (stripos($msg, 'foreign key') !== false || stripos($msg, 'a foreign key constraint fails') !== false) {
        $msg = "Blocage par clé étrangère. Vérifiez les liens restants (voir logs/delete_client.log).".$hint;
    }

    back(['err' => 'Suppression impossible : '.$msg]);
}
