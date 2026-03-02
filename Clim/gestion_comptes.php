<?php
/* ============================================================
 * gestion_comptes.php — Gestion des comptes (UI type ajout_pac.php)
 * Accès: tout utilisateur connecté
 * Droits:
 *   - Admin: créer / supprimer des comptes, changer les rôles, réinitialiser n'importe quel mot de passe
 *   - User : ne peut changer que son propre mot de passe
 * Sécu: CSRF, password_hash(), garde-fous dernier admin & self-delete
 * Migration auto table `users`
 * Debug: ?debug=1
 * ============================================================ */

define('APP_DEBUG', isset($_GET['debug']));
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

/* ---- Logs/erreurs ---- */
set_error_handler(function($severity, $message, $file, $line){
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
        $errId = bin2hex(random_bytes(4));
        log_error_text("FATAL [$errId] {$e['message']} @ {$e['file']}:{$e['line']}");
        header('HTTP/1.1 500 Internal Server Error');
        if (APP_DEBUG) {
            echo "<pre style='white-space:pre-wrap'>FATAL [$errId]\n{$e['message']}\n{$e['file']}:{$e['line']}</pre>";
        } else {
            echo "Une erreur s'est produite (code: $errId).";
        }
    }
});
function log_error_text(string $text): void {
    $dir = __DIR__ . '/runtime/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir.'/app-'.date('Y-m-d').'.log', '['.date('H:i:s')."] $text\n", FILE_APPEND);
}
function log_exception(Throwable $e, string $errId): void {
    $msg = "EX [$errId] ".get_class($e).": ".$e->getMessage()."\n".
           $e->getFile().":".$e->getLine()."\n".
           $e->getTraceAsString();
    log_error_text($msg);
}

/* ---- App ---- */
try {
    require 'auth.php';
    require 'config.php';

    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException("Objet PDO \$pdo introuvable (vérifie config.php).");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    /* ---- Accès : tout utilisateur connecté ---- */
    function is_logged_in(): bool { return isset($_SESSION['username']) && $_SESSION['username'] !== ''; }
    if (!is_logged_in()) {
        http_response_code(403);
        exit("Accès réservé aux utilisateurs connectés. Veuillez vous authentifier.");
    }

    /* ---- Utils ---- */
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    function redirect_msg(string $msg=null, string $err=null) {
        $q = [];
        if ($msg) $q['msg'] = $msg;
        if ($err) $q['err'] = $err;
        $qs = $q ? ('?'.http_build_query($q)) : '';
        header("Location: gestion_comptes.php$qs");
        exit;
    }

    /* ---- Schéma users (migration auto) ---- */
    function users_columns(PDO $pdo): array {
        $st = $pdo->query("SHOW COLUMNS FROM `users`");
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['Field'];
        return $cols;
    }
    function index_exists(PDO $pdo, string $table, string $column): bool {
        $st = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Column_name = :col AND Non_unique = 0");
        $st->execute([':col'=>$column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    }
    function ensure_users_schema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(191) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','user') NOT NULL DEFAULT 'user',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $cols = users_columns($pdo);

        if (!in_array('password_hash', $cols, true)) {
            if (in_array('password', $cols, true)) {
                $pdo->exec("ALTER TABLE `users` CHANGE `password` `password_hash` VARCHAR(255) NOT NULL");
            } else {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `password_hash` VARCHAR(255) NOT NULL AFTER `username`");
            }
        }
        if (!in_array('role', $cols, true)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('admin','user') NOT NULL DEFAULT 'user' AFTER `password_hash`");
            if (in_array('is_admin', $cols, true)) {
                $pdo->exec("UPDATE `users` SET `role`='admin' WHERE `is_admin`=1");
            }
        }
        if (!in_array('created_at', $cols, true)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `role`");
        }
        if (!in_array('last_login', $cols, true)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_login` DATETIME NULL AFTER `created_at`");
        }
        if (!index_exists($pdo, 'users', 'username')) {
            try { $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uniq_users_username` (`username`)"); } catch (Throwable $e) {}
        }
    }
    try { ensure_users_schema($pdo); } catch (Throwable $e) { log_exception($e, 'MIGRATE'); }

    /* ---- Contexte utilisateur courant ---- */
    $meUsername = $_SESSION['username'] ?? '';
    $meId = 0;
    $meRole = 'user';
    if ($meUsername !== '') {
        $st = $pdo->prepare("SELECT id, role FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u'=>$meUsername]);
        if ($row = $st->fetch()) {
            $meId = (int)$row['id'];
            $meRole = ($row['role'] === 'admin') ? 'admin' : 'user';
        }
    }
    $isAdmin = ($meRole === 'admin');

    /* ---- Actions ---- */
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            redirect_msg(null, "Token CSRF invalide.");
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            if (!$isAdmin) redirect_msg(null, "Droit insuffisant.");
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm  = (string)($_POST['confirm']  ?? '');
            $role     = (string)($_POST['role']     ?? 'user');
            $role     = ($role === 'admin') ? 'admin' : 'user';

            if ($username === '' || mb_strlen($username) < 3) redirect_msg(null, "Le nom d'utilisateur doit contenir au moins 3 caractères.");
            if (!preg_match('~^[a-zA-Z0-9._-]{3,191}$~', $username)) redirect_msg(null, "Le nom d'utilisateur ne doit contenir que lettres, chiffres, points, tirets et underscores.");
            if (mb_strlen($password) < 8)                    redirect_msg(null, "Le mot de passe doit contenir au moins 8 caractères.");
            if ($password !== $confirm)                      redirect_msg(null, "La confirmation ne correspond pas.");

            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $st = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (:u, :p, :r)");
                $st->execute([':u'=>$username, ':p'=>$hash, ':r'=>$role]);
                redirect_msg("Compte « {$username} » créé avec succès.");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'UNIQUE') !== false) {
                    redirect_msg(null, "Ce nom d'utilisateur existe déjà.");
                }
                log_exception($e, 'CREATE');
                redirect_msg(null, "Erreur lors de la création du compte.");
            }
        }

        if ($action === 'passwd') {
            $user_id  = isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $password = (string)($_POST['new_password'] ?? '');
            $confirm  = (string)($_POST['confirm_password'] ?? '');

            if ($user_id <= 0)                redirect_msg(null, "Utilisateur invalide.");
            if (mb_strlen($password) < 8)     redirect_msg(null, "Le mot de passe doit contenir au moins 8 caractères.");
            if ($password !== $confirm)       redirect_msg(null, "La confirmation ne correspond pas.");

            if (!$isAdmin && $user_id !== $meId) {
                redirect_msg(null, "Vous ne pouvez changer que votre propre mot de passe.");
            }

            try {
                $st = $pdo->prepare("SELECT id, username FROM users WHERE id = :id");
                $st->execute([':id'=>$user_id]);
                $u = $st->fetch();
                if (!$u) redirect_msg(null, "Utilisateur introuvable.");

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $up = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
                $up->execute([':h'=>$hash, ':id'=>$user_id]);

                redirect_msg("Mot de passe réinitialisé pour « {$u['username']} ».");
            } catch (Throwable $e) {
                log_exception($e, 'PASSWD');
                redirect_msg(null, "Erreur lors de la mise à jour du mot de passe.");
            }
        }

        if ($action === 'delete') {
            if (!$isAdmin) redirect_msg(null, "Droit insuffisant.");
            $user_id = isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            if ($user_id <= 0) redirect_msg(null, "Utilisateur invalide.");

            try {
                $st = $pdo->prepare("SELECT id, username, role FROM users WHERE id = :id");
                $st->execute([':id'=>$user_id]);
                $u = $st->fetch();
                if (!$u) redirect_msg(null, "Utilisateur introuvable.");

                if ((int)$u['id'] === $meId) {
                    redirect_msg(null, "Vous ne pouvez pas supprimer votre propre compte.");
                }
                if ($u['role'] === 'admin') {
                    $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ($countAdmins <= 1) {
                        redirect_msg(null, "Impossible de supprimer le dernier administrateur.");
                    }
                }

                $del = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $del->execute([':id'=>$user_id]);
                redirect_msg("Compte « {$u['username']} » supprimé.");
            } catch (Throwable $e) {
                log_exception($e, 'DELETE');
                redirect_msg(null, "Erreur lors de la suppression.");
            }
        }

        if ($action === 'change_role') {
            if (!$isAdmin) redirect_msg(null, "Droit insuffisant.");
            $user_id = isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $role    = (string)($_POST['role'] ?? 'user');
            $role    = ($role === 'admin') ? 'admin' : 'user';
            if ($user_id <= 0) redirect_msg(null, "Utilisateur invalide.");

            try {
                $st = $pdo->prepare("SELECT id, username, role FROM users WHERE id = :id");
                $st->execute([':id'=>$user_id]);
                $u = $st->fetch();
                if (!$u) redirect_msg(null, "Utilisateur introuvable.");

                if ($u['role'] === 'admin' && $role === 'user') {
                    $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ($countAdmins <= 1) {
                        redirect_msg(null, "Impossible de rétrograder le dernier administrateur.");
                    }
                }
                if ((int)$u['id'] === $meId && $u['role'] === 'admin' && $role === 'user') {
                    $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                    if ($countAdmins <= 1) {
                        redirect_msg(null, "Vous êtes le dernier administrateur : rétrogradation impossible.");
                    }
                }

                $up = $pdo->prepare("UPDATE users SET role = :r WHERE id = :id");
                $up->execute([':r'=>$role, ':id'=>$user_id]);
                redirect_msg("Rôle mis à jour pour « {$u['username']} ».");
            } catch (Throwable $e) {
                log_exception($e, 'ROLE');
                redirect_msg(null, "Erreur lors du changement de rôle.");
            }
        }

        redirect_msg(null, "Action inconnue.");
    }

    /* ---- Liste + recherche ---- */
    $search = trim($_GET['search'] ?? '');
    $params = [];
    $sql = "SELECT id, username, role, created_at, last_login FROM users";
    if ($search !== '') {
        $sql .= " WHERE username LIKE :q";
        $params[':q'] = "%{$search}%";
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 200";
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->execute();
    $users = $st->fetchAll();

} catch (Throwable $e) {
    $errId = bin2hex(random_bytes(4));
    log_exception($e, $errId);
    header('HTTP/1.1 500 Internal Server Error');
    if (APP_DEBUG) {
        echo "<pre style='white-space:pre-wrap'>ERR [$errId]\n".get_class($e).": ".$e->getMessage()."\n".$e->getFile().":".$e->getLine()."\n\n".$e->getTraceAsString()."</pre>";
    } else {
        echo "Une erreur s'est produite (code: $errId). Essayez avec <code>?debug=1</code> pour plus de détails (temporairement).";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Gestion des comptes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($_SESSION['csrf_token']) ?>">
<link rel="stylesheet" href="style.css">
<style>
  /* Micro retouches spécifiques pour caler le look sur ajout_pac.php */
  .stack { display:grid; gap:12px; }
  .row-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  @media (max-width: 860px){ .row-grid { grid-template-columns: 1fr; } }
  .section-nav { position: sticky; top: 0; z-index: 5; background: linear-gradient(180deg, var(--bg), var(--bg-2)); padding: 8px 0 12px; margin: -8px 0 16px; border-bottom: 1px solid var(--bd); }
  .card h2 { display:flex; align-items:center; gap:8px; }
  .role-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid #e3e7ee; background:#f7f9fc; border-radius:999px; font-weight:700; }
  .help-list { margin:8px 0 0 0; padding-left:18px; color:#334155; }
  .help-list li { margin:6px 0; }
</style>
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
  <h1>Gestion des comptes</h1>

  <?php if (!empty($_GET['msg'])): ?><div class="flash"><?= e($_GET['msg']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="alert error"><?= e($_GET['err']) ?></div><?php endif; ?>

  <!-- ====== Bloc supérieur comme « ajout_pac.php » : deux cartes côte à côte ====== -->
  <div class="grid-2">
    <!-- 1) Création de compte (admin uniquement) -->
    <section id="create" class="card">
      <h2>➕ Créer un compte</h2>
      <p class="muted" style="margin-top:-6px">
        <?php if ($isAdmin): ?>
          Renseignez l'identifiant, un mot de passe et le rôle.
        <?php else: ?>
          Cette section est réservée aux administrateurs.
        <?php endif; ?>
      </p>

      <?php if ($isAdmin): ?>
      <form method="post" autocomplete="off" class="stack">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">

        <div>
          <label for="username">Nom d'utilisateur</label>
          <input type="text" name="username" id="username" minlength="3" required placeholder="ex : jdupont">
          <div class="hint">Autorisé : lettres, chiffres, « . » « - » « _ »</div>
        </div>

        <div class="row-grid">
          <div>
            <label for="password">Mot de passe</label>
            <input type="password" name="password" id="password" minlength="8" required placeholder="Min. 8 caractères">
          </div>
          <div>
            <label for="confirm">Confirmer le mot de passe</label>
            <input type="password" name="confirm" id="confirm" minlength="8" required>
          </div>
        </div>

        <div>
          <label for="role">Rôle</label>
          <select name="role" id="role">
            <option value="user">Utilisateur</option>
            <option value="admin">Administrateur</option>
          </select>
        </div>

        <div class="inline" style="margin-top:4px;">
          <button class="btn btn-save" type="submit">Créer le compte</button>
          <span class="muted">Les mots de passe sont chiffrés via <code>password_hash()</code>.</span>
        </div>
      </form>
      <?php else: ?>
        <div class="alert">
          Vous n'avez pas les droits suffisants pour créer des comptes.
        </div>
      <?php endif; ?>
    </section>

    <!-- 2) Changer un mot de passe -->
    <section id="passwd" class="card">
      <h2>🔐 Changer un mot de passe</h2>
      <p class="muted" style="margin-top:-6px">
        <?php if ($isAdmin): ?>
          Vous pouvez réinitialiser le mot de passe de n'importe quel compte.
        <?php else: ?>
          Vous pouvez uniquement modifier votre propre mot de passe.
        <?php endif; ?>
      </p>

      <form method="post" autocomplete="off" id="form-passwd" class="stack">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="passwd">

        <div>
          <label for="user_id">Utilisateur</label>
          <select name="user_id" id="user_id" required>
            <option value="">-- Choisir --</option>
            <?php foreach ($users as $u): ?>
              <?php if ($isAdmin || (int)$u['id'] === $meId): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['username']) ?> (<?= e($u['role']) ?>)</option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row-grid">
          <div>
            <label for="new_password">Nouveau mot de passe</label>
            <input type="password" name="new_password" id="new_password" minlength="8" required>
          </div>
          <div>
            <label for="confirm_password">Confirmer</label>
            <input type="password" name="confirm_password" id="confirm_password" minlength="8" required>
          </div>
        </div>

        <div class="inline" style="margin-top:4px;">
          <button class="btn btn-warning" type="submit">Mettre à jour</button>
          <span class="muted">Minimum 8 caractères.</span>
        </div>
      </form>

      <hr style="margin:14px 0;border:none;border-top:1px solid var(--bd);">
      <h3 style="margin:0 0 6px;">ℹ️ Aide rapide</h3>
      <ul class="help-list">
        <li><span class="role-pill">Administrateur</span> : crée & supprime des comptes, change les rôles.</li>
        <li><span class="role-pill">Utilisateur</span> : peut seulement modifier son propre mot de passe.</li>
        <li>On ne peut pas supprimer le <strong>dernier administrateur</strong>, ni <strong>se supprimer soi-même</strong>.</li>
      </ul>
    </section>
  </div><!-- /grid-2 -->

  <!-- ====== Liste des comptes (sans colonne ID) ====== -->
  <section id="liste" class="card full" style="margin-top:18px;">
    <h2>👥 Comptes existants</h2>
    <div class="muted" style="margin-top:-6px">Filtrez par identifiant. Les actions avancées sont réservées aux administrateurs.</div>

    <form method="get" action="gestion_comptes.php" class="filter-bar inline" style="gap:10px; align-items:end; margin:14px 0;">
      <div class="spacer" style="max-width:420px;">
        <label for="f-search" class="muted" style="margin:0 0 4px; font-weight:600;">Recherche</label>
        <input id="f-search" type="text" name="search" placeholder="Nom d'utilisateur…" value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Rechercher</button>
      <?php if ($search !== ''): ?>
        <a href="gestion_comptes.php" class="btn btn-secondary">Réinitialiser</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap">
      <table class="table-sticky">
        <thead>
          <tr>
            <th>Utilisateur</th>
            <th style="width:220px;">Rôle</th>
            <th style="width:180px;">Créé le</th>
            <th style="width:200px;">Dernière connexion</th>
            <th style="width:280px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="muted">Aucun utilisateur.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['username']) ?></strong></td>
            <td>
              <?php if ($isAdmin): ?>
                <form method="post" class="inline" style="gap:6px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <select name="role">
                    <option value="user"  <?= $u['role']==='user'  ? 'selected':''; ?>>Utilisateur</option>
                    <option value="admin" <?= $u['role']==='admin' ? 'selected':''; ?>>Administrateur</option>
                  </select>
                  <button class="btn" type="submit">Appliquer</button>
                </form>
              <?php else: ?>
                <span class="role-pill"><?= e(ucfirst($u['role'])) ?></span>
              <?php endif; ?>
            </td>
            <td><?= $u['created_at'] ? e(date('d/m/Y H:i', strtotime($u['created_at']))) : '—' ?></td>
            <td><?= $u['last_login'] ? e(date('d/m/Y H:i', strtotime($u['last_login']))) : '—' ?></td>
            <td class="actions">
              <button class="btn" onclick="prefillPass(<?= (int)$u['id'] ?>)">Init. mot de passe…</button>
              <?php if ($isAdmin): ?>
                <form method="post" onsubmit="return confirmSuppression(this);" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-danger" type="submit" <?= ((int)$u['id']===$meId)?'disabled':''; ?>>Supprimer</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <p class="muted" style="margin-top:8px;">
      Garde-fous : impossible de supprimer le <strong>dernier administrateur</strong> ni de <strong>se supprimer soi-même</strong>.
      <?php if(!$isAdmin): ?> — Les changements de rôle et suppressions sont réservés aux administrateurs.<?php endif; ?>
    </p>
  </section>
</div>

<script>
function prefillPass(id){
  const sel = document.getElementById('user_id');
  if (!sel) return;
  sel.value = String(id);
  const form = document.getElementById('form-passwd');
  const top = form ? (form.offsetTop - 10) : 0;
  window.scrollTo({top, behavior:'smooth'});
  const np = document.getElementById('new_password');
  if (np) np.focus();
}
function confirmSuppression(formEl){
  const uid = formEl.querySelector('input[name="user_id"]')?.value || '';
  const row = formEl.closest('tr');
  const uname = row ? row.children[0].textContent.trim() : '';
  return confirm("Supprimer le compte « " + (uname || ("ID " + uid)) + " » ? Cette action est irréversible.");
}
</script>

</body>
</html>
