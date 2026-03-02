<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$message = '';

/* ───────── Helpers ───────── */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getMaxPosition(PDO $pdo, $categoryId) {
    if ($categoryId === null) {
        $stmt = $pdo->query('SELECT MAX(position) AS maxp FROM pompes_a_chaleur WHERE category_id IS NULL');
        return (int)($stmt->fetchColumn() ?? 0);
    } else {
        $stmt = $pdo->prepare('SELECT MAX(position) AS maxp FROM pompes_a_chaleur WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        return (int)($stmt->fetchColumn() ?? 0);
    }
}
function getMaxCatPosition(PDO $pdo) {
    $stmt = $pdo->query('SELECT MAX(position) FROM categories');
    return (int)($stmt->fetchColumn() ?? 0);
}

/* ───────── Filtres ───────── */
$search    = trim($_GET['search'] ?? '');
$catFilter = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;

/* ───────── CATEGORIES (CRUD léger) ───────── */
if (($_POST['action'] ?? '') === 'add_cat') {
    $catName = trim($_POST['cat_name'] ?? '');
    if ($catName !== '') {
        $newPos = getMaxCatPosition($pdo) + 1;
        $stmt = $pdo->prepare('INSERT INTO categories (nom, position) VALUES (?, ?)');
        try { $stmt->execute([$catName, $newPos]); $message = "Catégorie ajoutée."; }
        catch (Throwable $e) { $message = "Impossible d'ajouter la catégorie : " . $e->getMessage(); }
    } else { $message = "Le nom de la catégorie est requis."; }
}
if (($_POST['action'] ?? '') === 'rename_cat') {
    $catId   = isset($_POST['cat_id']) ? (int)$_POST['cat_id'] : 0;
    $catName = trim($_POST['cat_name'] ?? '');
    if ($catId > 0 && $catName !== '') {
        $stmt = $pdo->prepare('UPDATE categories SET nom = ? WHERE id = ?');
        try { $stmt->execute([$catName, $catId]); $message = "Catégorie renommée."; }
        catch (Throwable $e) { $message = "Impossible de renommer : " . $e->getMessage(); }
    } else { $message = "Sélectionnez une catégorie valide et un nom."; }
}
if (isset($_GET['delete_cat']) && is_numeric($_GET['delete_cat'])) {
    $catId = (int)$_GET['delete_cat'];
    $pdo->prepare('UPDATE pompes_a_chaleur SET category_id = NULL WHERE category_id = ?')->execute([$catId]);
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
    header('Location: ajout_pac.php'); exit;
}
if (isset($_GET['move_cat'], $_GET['cat_id']) && is_numeric($_GET['cat_id'])) {
    $catId = (int)$_GET['cat_id']; $direction = $_GET['move_cat'];
    $stmt = $pdo->prepare('SELECT id, position FROM categories WHERE id = ?'); $stmt->execute([$catId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($current) {
        if ($direction === 'up') {
            $stmt = $pdo->prepare('SELECT id, position FROM categories WHERE position < ? ORDER BY position DESC LIMIT 1');
            $stmt->execute([$current['position']]);
        } else {
            $stmt = $pdo->prepare('SELECT id, position FROM categories WHERE position > ? ORDER BY position ASC LIMIT 1');
            $stmt->execute([$current['position']]);
        }
        $swap = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($swap) {
            $pdo->prepare('UPDATE categories SET position = ? WHERE id = ?')->execute([$swap['position'], $current['id']]);
            $pdo->prepare('UPDATE categories SET position = ? WHERE id = ?')->execute([$current['position'], $swap['id']]);
        }
    }
    header('Location: ajout_pac.php'); exit;
}

$categories = $pdo->query('SELECT id, nom, position FROM categories ORDER BY position, nom')->fetchAll(PDO::FETCH_ASSOC);

/* ───────── MATERIELS ───────── */
if (isset($_GET['supprimer']) && is_numeric($_GET['supprimer'])) {
    $id_supprimer = (int) $_GET['supprimer'];
    $pdo->prepare('DELETE FROM pompes_a_chaleur WHERE id = ?')->execute([$id_supprimer]);
    header('Location: ajout_pac.php' . ($catFilter ? ('?cat='.(int)$catFilter) : '')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pac') {
    $nom         = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $prix        = $_POST['prix'] ?? null;
    $edit_id     = $_POST['edit_id'] ?? null;
    $category_id = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;

    if ($nom && is_numeric($prix)) {
        if ($edit_id) {
            $stmt = $pdo->prepare('UPDATE pompes_a_chaleur SET nom = ?, description = ?, prix = ?, category_id = ? WHERE id = ?');
            $stmt->execute([$nom, $description, $prix, $category_id, $edit_id]);
            $message = "Matériel modifié avec succès.";
        } else {
            $newPos = getMaxPosition($pdo, $category_id) + 1;
            $stmt = $pdo->prepare('INSERT INTO pompes_a_chaleur (nom, description, prix, position, category_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$nom, $description, $prix, $newPos, $category_id]);
            $message = "Matériel ajouté avec succès.";
        }
    } else {
        $message = "Veuillez fournir un nom et un prix valide.";
    }
}

/* Préremplissage si modification */
$edit_pac = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM pompes_a_chaleur WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $edit_pac = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Liste & groupement */
$params = []; $where = [];
if ($search !== '') { $where[] = '(p.nom LIKE ? OR p.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter !== null) { $where[] = 'p.category_id = ?'; $params[] = $catFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sqlList = "
SELECT p.*, c.nom AS cat_nom, c.position AS cat_position
FROM pompes_a_chaleur p
LEFT JOIN categories c ON c.id = p.category_id
$whereSql
ORDER BY COALESCE(c.position, 2147483647), p.position
";
$stmt = $pdo->prepare($sqlList);
$stmt->execute($params);
$pac_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($pac_list as $row) {
    $key = $row['category_id'] === null ? '' : (string)(int)$row['category_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'cat_id'  => $key,
            'cat_nom' => $row['cat_nom'] ?? '— Aucune —',
            'items'   => []
        ];
    }
    $grouped[$key]['items'][] = $row;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Matériels (PAC) — Gestion</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($_SESSION['csrf_token']) ?>">
<link rel="stylesheet" href="style.css">
</head>
<body class="noselect">
<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">

  <div class="page-header">
    <div>
      <h1>Gestion du materiel</h1>
      <div class="subtitle">Equipements, categories et tarifs</div>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="flash"><?= e($message) ?></div>
  <?php endif; ?>

  <!-- Bloc 1 & 2 côte à côte : Ajouter / Catégories -->
  <div class="grid-2">

    <!-- ============ 1) AJOUTER / MODIFIER UN MATERIEL ============ -->
    <section id="ajout" class="card">
      <h2><?= $edit_pac ? 'Modifier un materiel' : 'Ajouter un materiel' ?></h2>
      <p class="muted" style="margin-top:-6px">Renseignez le nom, un descriptif (facultatif), le prix HT et, si besoin, sa catégorie.</p>

      <form method="POST" action="ajout_pac.php" class="stack">
        <input type="hidden" name="action" value="save_pac">
        <?php if ($edit_pac): ?>
          <input type="hidden" name="edit_id" value="<?= (int)$edit_pac['id'] ?>">
        <?php endif; ?>

        <div>
          <label for="nom">Nom du matériel</label>
          <input id="nom" type="text" name="nom" value="<?= e($edit_pac['nom'] ?? '') ?>" required>
        </div>

        <div>
          <label for="description">Description (facultatif)</label>
          <textarea id="description" name="description" rows="4"><?= e($edit_pac['description'] ?? '') ?></textarea>
        </div>

        <div class="row-grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
          <div>
            <label for="prix">Prix (€)</label>
            <input id="prix" type="number" name="prix" step="0.01" value="<?= e($edit_pac['prix'] ?? '') ?>" required>
          </div>
          <div>
            <label for="category_id">Catégorie</label>
            <select id="category_id" name="category_id">
              <option value="">— Aucune —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"
                  <?= isset($edit_pac['category_id']) && (int)$edit_pac['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                  <?= e($cat['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="inline" style="margin-top:4px;">
          <button type="submit" class="btn btn-save"><?= $edit_pac ? 'Enregistrer les modifications' : 'Ajouter le matériel' ?></button>
          <?php if ($edit_pac): ?>
            <a class="btn btn-secondary" href="ajout_pac.php">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- ============ 2) CATEGORIES ============ -->
    <section id="categories" class="card">
      <h2>Categories</h2>
      <p class="muted" style="margin-top:-6px">Organisez vos matériels par catégories. Le tri (▲▼) change l'ordre d'affichage.</p>

      <div class="grid" style="grid-template-columns:1fr; gap:14px;">
        <div class="card" style="padding:12px;">
          <h3>Nouvelle categorie</h3>
          <form method="POST" action="ajout_pac.php" class="inline" style="margin-top:8px;">
            <input type="hidden" name="action" value="add_cat">
            <input type="text" name="cat_name" placeholder="Nom de la catégorie…" required style="flex:1;">
            <button type="submit" class="btn btn-primary">Ajouter</button>
          </form>
        </div>

        <div class="card" style="padding:12px;">
          <h3>Renommer une categorie</h3>
          <form method="POST" action="ajout_pac.php" class="inline" style="margin-top:8px; gap:8px;">
            <input type="hidden" name="action" value="rename_cat">
            <select name="cat_id" required style="flex:1; min-width:180px;">
              <option value="">— Choisir —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="cat_name" placeholder="Nouveau nom…" required style="flex:1;">
            <button type="submit" class="btn">Renommer</button>
          </form>
        </div>
      </div>

      <div style="margin-top:10px;">
        <?php if ($categories): ?>
          <?php foreach ($categories as $cat): ?>
            <span class="cat-chip">
              <strong><?= e($cat['nom']) ?></strong>
              <a class="muted" href="ajout_pac.php?move_cat=up&cat_id=<?= (int)$cat['id'] ?>" title="Monter">▲</a>
              <a class="muted" href="ajout_pac.php?move_cat=down&cat_id=<?= (int)$cat['id'] ?>" title="Descendre">▼</a>
              <a class="muted" href="ajout_pac.php?delete_cat=<?= (int)$cat['id'] ?>"
                 onclick="return confirm('Supprimer cette catégorie ? Les matériels seront détachés (Aucune).')">🗑</a>
            </span>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="muted">Aucune catégorie pour le moment.</p>
        <?php endif; ?>
      </div>
    </section>

  </div><!-- /grid-2 -->

  <!-- ============ 3) LISTE DES MATERIELS ============ -->
  <section id="liste" class="card full" style="margin-top:18px;">
    <h2>📦 Matériels enregistrés</h2>
    <div class="muted" style="margin-top:-6px">Filtrez, puis réordonnez les éléments par glisser-déposer (☰) à l'intérieur d'une même catégorie.</div>

    <form method="get" action="ajout_pac.php" class="filter-bar inline" style="gap:10px; align-items:end; margin:14px 0;">
      <div style="min-width:240px;">
        <label for="f-cat" class="muted" style="margin:0 0 4px; font-weight:600;">Catégorie</label>
        <select id="f-cat" name="cat">
          <option value="">— Toutes catégories —</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= $catFilter !== null && (int)$catFilter === (int)$cat['id'] ? 'selected' : '' ?>>
              <?= e($cat['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="spacer" style="max-width:420px;">
        <label for="f-search" class="muted" style="margin:0 0 4px; font-weight:600;">Recherche</label>
        <input id="f-search" type="text" name="search" placeholder="Nom ou description…" value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <?php if ($search !== '' || $catFilter !== null): ?>
        <a href="ajout_pac.php" class="btn btn-secondary">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <?php if ($pac_list): ?>
      <?php foreach ($grouped as $g): ?>
        <?php
          $cid = ($g['cat_id'] === '' ? 'none' : (int)$g['cat_id']);
          $countItems = count($g['items']);
        ?>
        <h4 class="cat-title" data-cat="<?= e($g['cat_id']) ?>">
          <button type="button" class="cat-toggle" aria-expanded="true" aria-controls="cat-<?= e($cid) ?>">▼</button>
          <span><?= e($g['cat_nom']) ?></span>
          <small class="cat-count">(<?= (int)$countItems ?>)</small>
        </h4>

        <div id="cat-<?= e($cid) ?>" class="cat-block">
          <div class="table-wrap">
            <table class="pac-table table-sticky">
              <thead>
              <tr>
                <th style="width:42px;"></th>
                <th>Nom</th>
                <th>Description</th>
                <th style="width:140px;">Prix (€)</th>
                <th style="width:180px;">Actions</th>
              </tr>
              </thead>
              <tbody class="pac-tbody" data-cat="<?= e($g['cat_id']) ?>">
              <?php foreach ($g['items'] as $pac): ?>
                <tr class="pac-row"
                    data-id="<?= (int)$pac['id'] ?>"
                    data-cat="<?= $pac['category_id'] === null ? '' : (int)$pac['category_id'] ?>">
                  <td class="drag-cell"><span class="drag-handle" title="Glisser pour réordonner">☰</span></td>
                  <td><strong><?= e($pac['nom']) ?></strong></td>
                  <td class="muted"><?= nl2br(e($pac['description'])) ?></td>
                  <td><span class="pill nowrap"><?= number_format((float)$pac['prix'], 2, ',', ' ') ?></span></td>
                  <td class="action-links">
                    <a class="btn btn-secondary" href="ajout_pac.php?edit=<?= (int)$pac['id'] ?><?= $catFilter !== null ? '&cat='.(int)$catFilter : '' ?>">✏ Modifier</a>
                    <a class="btn btn-danger" href="ajout_pac.php?supprimer=<?= (int)$pac['id'] ?><?= $catFilter !== null ? '&cat='.(int)$catFilter : '' ?>"
                       onclick="return confirm('Confirmer la suppression ?')">🗑 Supprimer</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted">Aucun matériel trouvé.</p>
    <?php endif; ?>
  </section>

</div><!-- /.main -->

<!-- DnD intra-catégorie + sauvegarde ordre -->
<script>
(function() {
  const tbodies = document.querySelectorAll('.pac-tbody');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  let draggingRow = null;
  let originCat   = null;
  let startFromHandle = false;
  let currentTbody = null;

  tbodies.forEach(tbody => attachDnD(tbody));

  function attachDnD(tbody) {
    tbody.addEventListener('mousedown', (e) => {
      const handle = e.target.closest('.drag-handle');
      startFromHandle = !!handle;
      if (!handle) return;
      const tr = handle.closest('tr');
      if (!tr) return;
      tr.setAttribute('draggable', 'true');
    });

    tbody.addEventListener('dragstart', (e) => {
      const tr = e.target.closest('tr');
      if (!tr) { e.preventDefault(); return; }
      if (!startFromHandle) { e.preventDefault(); return; }
      startFromHandle = false;

      draggingRow = tr;
      currentTbody = tbody;
      originCat   = tr.dataset.cat || '';

      e.dataTransfer.setData('text/plain', tr.dataset.id || '');
      e.dataTransfer.effectAllowed = 'move';

      tr.classList.add('dragging');
    });

    tbody.addEventListener('dragover', (e) => {
      if (!draggingRow) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      const overTr = e.target.closest('tr');
      if (!overTr) return;

      const overCat = overTr.dataset.cat || '';
      if (overCat !== originCat) return; // pas d'inter-catégorie

      const rect = overTr.getBoundingClientRect();
      const before = e.clientY < rect.top + rect.height / 2;

      if (before) {
        if (overTr !== draggingRow && overTr.previousElementSibling !== draggingRow) {
          tbody.insertBefore(draggingRow, overTr);
        }
      } else {
        if (overTr !== draggingRow && overTr.nextElementSibling !== draggingRow) {
          tbody.insertBefore(draggingRow, overTr.nextElementSibling);
        }
      }
    });

    tbody.addEventListener('drop', (e) => { e.preventDefault(); });

    tbody.addEventListener('dragend', async () => {
      const tr = draggingRow;
      draggingRow = null;

      if (tr) {
        tr.classList.remove('dragging');
        tr.setAttribute('draggable', 'false');
      }

      try {
        const payload = buildOrderPayload(currentTbody, originCat);
        if (!payload) return;

        const res = await saveOrder(payload);
        if (!res.ok) {
          console.error('Erreur serveur reorder', await res.text());
          alert("Échec de l'enregistrement de l'ordre.");
        }
      } catch (err) {
        console.error(err);
        alert("Échec de l'enregistrement de l'ordre.");
      } finally {
        originCat = null;
        currentTbody = null;
      }
    });
  }

  function buildOrderPayload(tbody, originCat) {
    if (!tbody) return null;
    const group = [];
    tbody.querySelectorAll('tr.pac-row').forEach(tr => {
      group.push(parseInt(tr.dataset.id, 10));
    });
    const payload = {};
    payload[originCat || ''] = group; // '' = Aucune (NULL)
    return payload;
  }

  async function saveOrder(payload) {
    return fetch('reorder_pac.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify({ orders: payload })
    });
  }
})();
</script>

<!-- Repli/dépli des catégories avec mémorisation locale -->
<script>
(function() {
  const STATE_KEY = 'pac-cat-collapsed';
  const keyFor = (cat) => `${STATE_KEY}:${cat === '' ? 'none' : cat}`;

  document.querySelectorAll('h4.cat-title').forEach(h => {
    const cat = h.dataset.cat ?? '';
    const block = document.getElementById('cat-' + (cat === '' ? 'none' : cat));
    const btn = h.querySelector('.cat-toggle');
    const collapsed = localStorage.getItem(keyFor(cat)) === '1';

    if (collapsed) {
      h.classList.add('collapsed');
      block?.classList.add('collapsed');
      if (btn) { btn.textContent = '▶'; btn.setAttribute('aria-expanded', 'false'); }
    } else {
      if (btn) { btn.textContent = '▼'; btn.setAttribute('aria-expanded', 'true'); }
    }

    const toggle = () => {
      const isCollapsed = h.classList.toggle('collapsed');
      block?.classList.toggle('collapsed', isCollapsed);
      const nowCollapsed = h.classList.contains('collapsed');
      localStorage.setItem(keyFor(cat), nowCollapsed ? '1' : '0');
      if (btn) {
        btn.textContent = nowCollapsed ? '▶' : '▼';
        btn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
      }
    };

    btn?.addEventListener('click', toggle);
    h.addEventListener('dblclick', (e) => {
      if (e.target.closest('button, a')) return;
      toggle();
    });
  });
})();
</script>
</body>
</html>
