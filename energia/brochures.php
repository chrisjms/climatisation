<?php
// brochures.php — gestion des energia_brochures (upload + listing + édition description)
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ───────────────────── Helpers ───────────────────── */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function selected($a,$b){ return $a===$b?'selected':''; }
function formatBytes($size, $precision = 2){
    $base = log(max(1,$size), 1024);
    $suffixes = array('o','Ko','Mo','Go','To');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[(int)floor($base)];
}

/* ───────────────────── Paramètres ───────────────────── */
$action  = $_GET['action'] ?? 'list';
$id      = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int)$_GET['id'] : null;
$message = '';
$errors  = [];
if (!empty($_GET['msg'])) $message = (string)$_GET['msg'];

/* ───────────────────── Suppression ───────────────────── */
if ($action === 'delete' && $id) {
    // CSRF
    $csrf = $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        exit('Jeton CSRF invalide pour la suppression.');
    }

    // Récupérer le fichier
    $stmt = $pdo->prepare("SELECT fichier_path FROM energia_brochures WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Supprimer la ligne
    $pdo->prepare("DELETE FROM energia_brochures WHERE id = ?")->execute([$id]);

    // Supprimer le fichier si présent (chemin absolu)
    if ($row && !empty($row['fichier_path'])) {
        $abs = __DIR__ . '/' . ltrim($row['fichier_path'], '/');
        if (is_file($abs)) { @unlink($abs); }
    }

    // Retour liste (on conserve filtres/tri)
    $back = $_GET;
    unset($back['action'], $back['id'], $back['csrf']);
    $back['msg'] = 'Brochure supprimée.';
    $qs = http_build_query($back);
    header('Location: brochures.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

/* ───────────────────── Upload ───────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'upload' || $action === 'list')) {
    // CSRF
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        exit('Jeton CSRF invalide pour l’upload.');
    }

    $titre       = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date_ajout  = trim($_POST['date_ajout'] ?? date('Y-m-d'));

    // Date au bon format
    $date_ok = false;
    if ($date_ajout !== '') {
        $dt     = DateTime::createFromFormat('Y-m-d', $date_ajout);
        $errors_dt = DateTime::getLastErrors();
        $date_ok = $dt && $errors_dt['warning_count'] === 0 && $errors_dt['error_count'] === 0;
    }
    if (!$date_ok) $date_ajout = date('Y-m-d');

    // Fichier
    if (!isset($_FILES['brochure']) || $_FILES['brochure']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "Veuillez sélectionner un fichier à téléverser.";
    } else {
        $file = $_FILES['brochure'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erreur d’upload (code {$file['error']}).";
        } else {
            // Validation basique
            $maxSize = 30 * 1024 * 1024; // 30 Mo
            if ($file['size'] > $maxSize) $errors[] = "Le fichier dépasse la taille maximale autorisée (30 Mo).";

            $allowedExt  = ['pdf','jpg','jpeg','png','webp','docx','pptx'];
            $allowedMime = [
                'application/pdf',
                'image/jpeg','image/png','image/webp',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ];

            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                $errors[] = "Extension non autorisée. Extensions acceptées : " . implode(', ', $allowedExt) . '.';
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
            if (!in_array($mime, $allowedMime, true)) {
                $errors[] = "Type MIME non autorisé ($mime).";
            }

            // Dossier de destination
            $destDir = __DIR__ . '/uploads/energia_brochures';
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                $errors[] = "Impossible de créer le dossier de stockage.";
            }

            if (!$errors) {
                // Nom unique
                $slugBase = preg_replace('~[^a-z0-9\-]+~i', '-', pathinfo($origName, PATHINFO_FILENAME));
                $slugBase = trim($slugBase, '-');
                if ($slugBase === '') $slugBase = 'brochure';

                $unique   = bin2hex(random_bytes(6));
                $filename = $slugBase . '-' . $unique . '.' . $ext;

                $destPath = $destDir . '/' . $filename;
                $relPath  = 'uploads/energia_brochures/' . $filename; // pour la base (chemin relatif web)

                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    $errors[] = "Impossible de déplacer le fichier téléversé.";
                } else {
                    // Enregistrement BDD
                    $stmt = $pdo->prepare("
                        INSERT INTO energia_brochures (titre, description, fichier_path, mime_type, taille_octets, date_ajout)
                        VALUES (:titre, :description, :fichier_path, :mime_type, :taille_octets, :date_ajout)
                    ");
                    $stmt->execute([
                        ':titre'        => ($titre === '' ? null : $titre),
                        ':description'  => ($description === '' ? null : $description),
                        ':fichier_path' => $relPath,
                        ':mime_type'    => $mime,
                        ':taille_octets'=> (int)filesize($destPath),
                        ':date_ajout'   => $date_ajout,
                    ]);

                    $message = "Brochure ajoutée avec succès.";
                    // Option : vider les champs après succès
                    $_POST = [];
                }
            }
        }
    }
}

/* ───────────────────── Mise à jour description ───────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_desc') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        exit('Jeton CSRF invalide pour la modification.');
    }
    $idPost = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($idPost <= 0) { http_response_code(400); exit('Identifiant invalide.'); }

    $newDesc = trim($_POST['description'] ?? '');
    $stmt = $pdo->prepare("UPDATE energia_brochures SET description = :d WHERE id = :id");
    $stmt->execute([':d' => ($newDesc === '' ? null : $newDesc), ':id' => $idPost]);

    // Conserver filtres/tri dans l’URL
    $back = array_intersect_key($_GET, ['q'=>1,'from'=>1,'to'=>1,'sort'=>1]);
    $back['msg'] = 'Description mise à jour.';
    $qs = http_build_query($back);
    header('Location: brochures.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

/* ───────────────────── Filtres & tri (listing) ───────────────────── */
$q       = trim($_GET['q'] ?? '');
$dateMin = trim($_GET['from'] ?? '');
$dateMax = trim($_GET['to'] ?? '');
$sort    = $_GET['sort'] ?? 'titre_asc'; // 'titre_asc' par défaut

$where = [];
$args  = [];

if ($q !== '') {
    $where[] = "(b.titre LIKE ? OR b.description LIKE ?)";
    $args[]  = "%$q%";
    $args[]  = "%$q%";
}
if ($dateMin !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateMin)) { $where[] = "DATE(b.date_ajout) >= ?"; $args[] = $dateMin; }
if ($dateMax !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $dateMax)) { $where[] = "DATE(b.date_ajout) <= ?"; $args[] = $dateMax; }
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$orders = [
    'date_desc'   => "COALESCE(b.date_ajout, b.id) DESC",
    'date_asc'    => "COALESCE(b.date_ajout, b.id) ASC",
    'titre_asc'   => "COALESCE(b.titre,'') COLLATE utf8mb4_unicode_ci ASC",
    'titre_desc'  => "COALESCE(b.titre,'') COLLATE utf8mb4_unicode_ci DESC",
    'type_asc'    => "COALESCE(b.mime_type,'') COLLATE utf8mb4_unicode_ci ASC",
    'type_desc'   => "COALESCE(b.mime_type,'') COLLATE utf8mb4_unicode_ci DESC",
    'taille_asc'  => "b.taille_octets ASC",
    'taille_desc' => "b.taille_octets DESC",
];
$orderBy = $orders[$sort] ?? $orders['titre_asc'];

/* ───────────────────── Récupération ───────────────────── */
$sql = "
  SELECT b.id, b.titre, b.description, b.fichier_path, b.mime_type, b.taille_octets, b.date_ajout
  FROM energia_brochures b
  $whereSql
  ORDER BY $orderBy
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$countRows = count($rows);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Brochures — Gestion</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
  <style>
    /* Micro retouches de mise en page de cette page */
    .section-nav { position: sticky; top: 0; z-index: 5; background: linear-gradient(180deg, var(--bg), var(--bg-2)); padding: 8px 0 12px; margin: -8px 0 16px; border-bottom: 1px solid var(--bd); }
    .stack { display: grid; gap: 12px; }
    .cards-2 { display:grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 960px){ .cards-2 { grid-template-columns: 1fr; } }
    .table-wrap { margin-top: 10px; }
    .file-pills { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  </style>
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
  <h1>Brochures</h1>

  <?php if (!empty($message)): ?>
    <div class="flash"><?= h($message) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="error">
      <strong>Erreur :</strong>
      <ul style="margin:6px 0 0 18px;">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Deux cartes côte à côte : Upload / Filtres -->
  <div class="cards-2">

    <!-- ───────────── 1) Ajout d'une brochure ───────────── -->
    <section id="upload" class="card">
      <h2>➕ Ajouter une brochure</h2>
      <p class="muted" style="margin-top:-6px">Formats acceptés : PDF, JPG, PNG, WEBP, DOCX, PPTX — 30&nbsp;Mo max.</p>

      <form method="post" enctype="multipart/form-data" action="brochures.php?action=upload" class="stack">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">

        <div>
          <label for="titre">Titre (optionnel)</label>
          <input type="text" id="titre" name="titre" value="<?= h($_POST['titre'] ?? '') ?>">
        </div>

        <div>
          <label for="description">Description (optionnelle)</label>
          <textarea id="description" name="description" rows="3"><?= h($_POST['description'] ?? '') ?></textarea>
        </div>

        <div class="inline" style="gap:12px;">
          <div style="min-width:180px;">
            <label for="date_ajout">Date (optionnelle)</label>
            <input type="date" id="date_ajout" name="date_ajout" value="<?= h($_POST['date_ajout'] ?? date('Y-m-d')) ?>">
          </div>
          <div class="spacer"></div>
        </div>

        <div>
          <label for="brochure"><strong>Fichier</strong></label>
          <input type="file" id="brochure" name="brochure" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.pptx" required>
        </div>

        <div class="inline">
          <button type="submit" class="btn btn-primary">Ajouter la brochure</button>
        </div>
      </form>
    </section>

    <!-- ───────────── 2) Filtres / tri ───────────── -->
    <section id="filtres" class="card">
      <h2>🔎 Filtres & tri</h2>
      <p class="muted" style="margin-top:-6px">Affinez l’affichage par mots-clés et dates, puis choisissez l’ordre de tri.</p>

      <form method="get" class="stack">
        <div>
          <label for="q">Recherche</label>
          <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Titre, description">
        </div>

        <div class="inline" style="gap:12px;">
          <div style="min-width:160px;">
            <label for="from">Du</label>
            <input type="date" id="from" name="from" value="<?= h($dateMin) ?>">
          </div>
          <div style="min-width:160px;">
            <label for="to">Au</label>
            <input type="date" id="to" name="to" value="<?= h($dateMax) ?>">
          </div>
        </div>

        <div>
          <label for="sort">Tri</label>
          <select id="sort" name="sort">
            <option value="titre_asc"   <?= selected($sort,'titre_asc') ?>>Titre A→Z</option>
            <option value="titre_desc"  <?= selected($sort,'titre_desc') ?>>Titre Z→A</option>
            <option value="date_desc"   <?= selected($sort,'date_desc') ?>>Date ↓</option>
            <option value="date_asc"    <?= selected($sort,'date_asc') ?>>Date ↑</option>
            <option value="type_asc"    <?= selected($sort,'type_asc') ?>>Type A→Z</option>
            <option value="type_desc"   <?= selected($sort,'type_desc') ?>>Type Z→A</option>
            <option value="taille_asc"  <?= selected($sort,'taille_asc') ?>>Taille ↑</option>
            <option value="taille_desc" <?= selected($sort,'taille_desc') ?>>Taille ↓</option>
          </select>
        </div>

        <div class="inline" style="gap:8px;">
          <button type="submit" class="btn btn-primary">Appliquer</button>
          <a class="btn btn-secondary" href="brochures.php">Réinitialiser</a>
        </div>
      </form>
    </section>

  </div><!-- /.cards-2 -->

  <!-- ───────────── 3) Listing ───────────── -->
  <section id="listing" class="card" style="margin-top:18px;">
    <h2>📚 Brochures enregistrées</h2>
    <div class="file-pills muted" style="margin-top:-6px">
      <span class="pill">Résultats : <strong><?= (int)$countRows ?></strong></span>
      <?php if ($q !== ''): ?><span class="pill">Recherche : "<?= h($q) ?>"</span><?php endif; ?>
      <?php if ($dateMin): ?><span class="pill">Du : <?= h($dateMin) ?></span><?php endif; ?>
      <?php if ($dateMax): ?><span class="pill">Au : <?= h($dateMax) ?></span><?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="table-sticky">
        <thead>
          <tr>
            <th style="width:110px;">Prévisualisation</th>
            <th style="min-width:180px;">Titre</th>
            <th>Description</th>
            <th style="width:110px;">Date</th>
            <th style="width:120px;">Type</th>
            <th style="width:110px; text-align:right">Taille</th>
            <th style="width:160px;">Fichier</th>
            <th style="width:140px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="muted">Aucune brochure trouvée.</td></tr>
        <?php else: foreach ($rows as $r):
            $isImg  = strpos($r['mime_type'], 'image/') === 0;
            $isPdf  = ($r['mime_type'] === 'application/pdf');
            $fileUrl = (!empty($r['fichier_path'])) ? $r['fichier_path'] : null;
            $abs = $fileUrl ? (__DIR__ . '/' . ltrim($fileUrl, '/')) : null;
            $fileOk = $abs && is_file($abs);

            // URL d'action pour conserver filtres/tri lors de la sauvegarde
            $keep = http_build_query(['q'=>$q,'from'=>$dateMin,'to'=>$dateMax,'sort'=>$sort]);
            $updateUrl = 'brochures.php?action=update_desc' . ($keep ? '&'.$keep : '');
        ?>
          <tr>
            <td>
              <?php if ($isImg && $fileOk): ?>
                <img class="preview" src="<?= h($fileUrl) ?>" alt="aperçu">
              <?php elseif ($isPdf): ?>
                <span class="badge">PDF</span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><strong><?= h($r['titre'] ?? '') ?></strong></td>
            <td>
              <?= nl2br(h($r['description'] ?? '')) ?: '<span class="muted">—</span>' ?>
              <details class="edit">
                <summary>✏️ Modifier</summary>
                <form method="post" action="<?= h($updateUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <textarea name="description" rows="4" placeholder="Saisissez la description…"><?= h($r['description'] ?? '') ?></textarea>
                  <div class="row">
                    <button type="submit" class="save">💾 Enregistrer</button>
                    <button type="button" class="cancel" onclick="this.closest('details').removeAttribute('open')">Annuler</button>
                  </div>
                </form>
              </details>
            </td>
            <td><?= $r['date_ajout'] ? date('d/m/Y', strtotime($r['date_ajout'])) : '<span class="muted">—</span>' ?></td>
            <td><?= h($r['mime_type'] ?? '—') ?></td>
            <td style="text-align:right"><?= $r['taille_octets'] !== null ? h(formatBytes((int)$r['taille_octets'])) : '—' ?></td>
            <td class="actions">
              <?php if ($fileOk): ?>
                <a class="btn btn-primary" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">Voir</a>
                <a class="btn btn-success" href="<?= h($fileUrl) ?>" download>Télécharger</a>
              <?php else: ?>
                <span class="muted">Indisp.</span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <a class="btn btn-danger"
                 href="?action=delete&id=<?= (int)$r['id'] ?>&<?= h(http_build_query(['q'=>$q,'from'=>$dateMin,'to'=>$dateMax,'sort'=>$sort,'csrf'=>$_SESSION['csrf_token']])) ?>"
                 onclick="return confirm('Supprimer cette brochure ? Le fichier sera aussi supprimé.');">🗑️ Supprimer</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div><!-- /.main -->

</body>
</html>
