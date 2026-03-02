<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_msg(string $msg=null, string $err=null) {
  $q = [];
  if ($msg) $q['msg'] = $msg;
  if ($err) $q['err'] = $err;
  $qs = $q ? ('?'.http_build_query($q)) : '';
  header("Location: gestion_societe.php$qs");
  exit;
}

function is_logged_in(): bool { return isset($_SESSION['username']) && $_SESSION['username'] !== ''; }
if (!is_logged_in()) { http_response_code(403); exit("Accès réservé."); }

/* Rôle minimal : admin (même logique que gestion_comptes.php) */
$meRole = 'user';
if (!empty($_SESSION['username'])) {
  $st = $pdo->prepare("SELECT role FROM energia_users WHERE username=:u LIMIT 1");
  $st->execute([':u'=>$_SESSION['username']]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) $meRole = ($row['role']==='admin') ? 'admin' : 'user';
}
$isAdmin = ($meRole==='admin'); // même garde‑fou que la page comptes. [1](https://cryonna-my.sharepoint.com/personal/christophe_rcadvance_fr/Documents/Fichiers%20Microsoft%20Copilot%20Chat/gestion_comptes.php)

/* Ensure table exists */
$pdo->exec("
CREATE TABLE IF NOT EXISTS energia_societe (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(191) NOT NULL,
  slogan1 VARCHAR(191) NULL,
  slogan2 VARCHAR(191) NULL,
  adresse VARCHAR(255) NULL,
  code_postal VARCHAR(30) NULL,
  ville VARCHAR(120) NULL,
  pays VARCHAR(120) NULL,
  telephone VARCHAR(60) NULL,
  site_web VARCHAR(191) NULL,
  email VARCHAR(191) NULL,
  logo_path VARCHAR(255) NULL,
  capital VARCHAR(191) NULL,
  siren VARCHAR(30) NULL,
  siret VARCHAR(30) NULL,
  tva_intra VARCHAR(40) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Fetch or create singleton row */
$st = $pdo->query("SELECT * FROM energia_societe ORDER BY id LIMIT 1");
$soc = $st->fetch(PDO::FETCH_ASSOC);
if (!$soc) {
  $pdo->exec("INSERT INTO energia_societe (nom) VALUES ('Ma société')");
  $st = $pdo->query("SELECT * FROM energia_societe ORDER BY id LIMIT 1");
  $soc = $st->fetch(PDO::FETCH_ASSOC);
}

/* POST: Save */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    redirect_msg(null, "Token CSRF invalide.");
  }
  if (!$isAdmin) redirect_msg(null, "Droit insuffisant.");

  $fields = [
    'nom','slogan1','slogan2','adresse','code_postal','ville','pays',
    'telephone','site_web','email','capital','siren','siret','tva_intra'
  ];
  $data = [];
  foreach ($fields as $f) { $data[$f] = trim((string)($_POST[$f] ?? '')); }

// Formatage CAPITAL
if ($data['capital'] !== '') {
    // On retire tout sauf chiffres et virgule/point
    $raw = str_replace([' ', '€'], '', $data['capital']);
    $raw = str_replace(',', '.', $raw);

    // Conversion en float
    $num = floatval($raw);

    // Convertit en format européen : 50 000,00 €
    $data['capital'] = number_format($num, 2, ',', ' ') . ' €';
}

// Formatage téléphone
if ($data['telephone'] !== '') {
    $tel = preg_replace('/\D+/', '', $data['telephone']); // retire tout sauf chiffres
    if (strlen($tel) >= 10) {
        $tel = substr($tel,0,10);
        $tel = implode('.', str_split($tel, 2));
    }
    $data['telephone'] = $tel;
}
  // Upload logo
  $logo_path = $soc['logo_path'] ?? null;
  if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) {
      redirect_msg(null, "Format de logo non supporté (png/jpg/gif/webp).");
    }
    $destDir = __DIR__ . '/assets/img';
    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
    $fname = 'logo_societe.' . $ext;
    $dest = $destDir . '/' . $fname;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
      redirect_msg(null, "Échec upload du logo.");
    }
    $logo_path = 'assets/img/' . $fname; // chemin relatif web
  }

  $up = $pdo->prepare("
    UPDATE energia_societe SET
      nom=:nom, slogan1=:s1, slogan2=:s2, adresse=:adr, code_postal=:cp, ville=:vil, pays=:pay,
      telephone=:tel, site_web=:web, email=:mail, logo_path=:logo,
      capital=:cap, siren=:siren, siret=:siret, tva_intra=:tva
    WHERE id=:id
  ");
  $ok = $up->execute([
    ':nom'=>$data['nom'], ':s1'=>$data['slogan1'], ':s2'=>$data['slogan2'],
    ':adr'=>$data['adresse'], ':cp'=>$data['code_postal'], ':vil'=>$data['ville'], ':pay'=>$data['pays'],
    ':tel'=>$data['telephone'], ':web'=>$data['site_web'], ':mail'=>$data['email'], ':logo'=>$logo_path,
    ':cap'=>$data['capital'], ':siren'=>$data['siren'], ':siret'=>$data['siret'], ':tva'=>$data['tva_intra'],
    ':id'=>$soc['id']
  ]);
  if ($ok) redirect_msg("Informations société mises à jour.");
  redirect_msg(null, "Erreur lors de l'enregistrement.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Gestion de la société</title>
  <link rel="stylesheet" href="style.css">
  <style>
  /* Agrandir tous les inputs + selects + textareas de ce formulaire */
  .societe-form input[type="text"],
  .societe-form input[type="email"],
  .societe-form input[type="tel"],
  .societe-form input[type="file"],
  .societe-form textarea,
  .societe-form select {
    width: 100%;
    min-height: 40px;          /* + haut */
    font-size: 15px;           /* + lisible */
    padding: 8px 10px;
    box-sizing: border-box;
  }

  /* Textarea plus confortable (adresse…) */
  .societe-form textarea {
    min-height: 110px;         /* ajuste à ton goût (110–160px) */
    line-height: 1.45;
  }

  /* Grille un peu plus aérée */
  .societe-grid {
    display: grid;
    grid-template-columns: 1fr 1fr; /* 2 colonnes égales */
    gap: 16px;                      /* un poil plus que 14px */
  }
  @media (max-width: 960px){
    .societe-grid { grid-template-columns: 1fr; }
  }

  /* Champs très larges (1 colonne = pleine largeur) */
  .societe-grid .col-span-2 { grid-column: 1 / -1; }
</style>
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>
<div class="main">
  <h1>Gestion de la société</h1>
  <?php if (!empty($_GET['msg'])): ?><div class="alert success"><?= e($_GET['msg']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="alert error"><?= e($_GET['err']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Identité & Coordonnées</h2>
    <?php if (!$isAdmin): ?>
      <p class="muted">Vous n’avez pas les droits pour modifier ces informations.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="societe-form">
  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

  <div class="societe-grid">
    <div>
      <label>Nom</label>
      <input name="nom" type="text" inputmode="email" required value="<?= e($soc['nom'] ?? '') ?>">
    </div>

    <div>
      <label>Logo (png/jpg/gif/webp)</label>
      <input type="file" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp" <?= $isAdmin?'':'disabled' ?>>
      <?php if (!empty($soc['logo_path'])): ?>
    <div class="small muted">
        Logo actuel :
        <br>
        <img src="<?= e($soc['logo_path']) ?>" 
             alt="logo société" 
             style="max-height:80px; margin-top:6px; border:1px solid #ccc; padding:4px; background:#fff;">
    </div>
<?php endif; ?>
    </div>

    <div>
      <label>Slogan ligne 1</label>
      <input name="slogan1" type="text" inputmode="email" value="<?= e($soc['slogan1'] ?? '') ?>">
    </div>

    <div>
      <label>Slogan ligne 2</label>
      <input name="slogan2" type="text" inputmode="email" value="<?= e($soc['slogan2'] ?? '') ?>">
    </div>

    <!-- Adresse sur 2 colonnes pour l’élargir -->
    <div class="col-span-2">
      <label>Adresse</label>
      <input name="adresse" type="text" inputmode="email" value="<?= e($soc['adresse'] ?? '') ?>">
    </div>

    <div>
      <label>Code postal</label>
      <input name="code_postal" type="text" inputmode="email" value="<?= e($soc['code_postal'] ?? '') ?>">
    </div>

    <div>
      <label>Ville</label>
      <input name="ville" type="text" inputmode="email" value="<?= e($soc['ville'] ?? '') ?>">
    </div>

    <div>
      <label>Pays</label>
      <input name="pays" type="text" inputmode="email" value="<?= e($soc['pays'] ?? '') ?>">
    </div>

    <div>
      <label>Téléphone</label>
      <input name="telephone" type="text" inputmode="email" value="<?= e($soc['telephone'] ?? '') ?>">
    </div>

    <div>
      <label>Site web</label>
      <input name="site_web" type="text" inputmode="email" value="<?= e($soc['site_web'] ?? '') ?>">
    </div>

    <div>
      <label>Email</label>
      <input name="email" type="email" value="<?= e($soc['email'] ?? '') ?>">
    </div>

    <div>
      <label>Capital</label>
      <input name="capital" type="text" inputmode="email" value="<?= e($soc['capital'] ?? '') ?>">
    </div>

    <div>
      <label>SIREN</label>
      <input name="siren" type="text" inputmode="email" value="<?= e($soc['siren'] ?? '') ?>">
    </div>

    <div>
      <label>SIRET</label>
      <input name="siret" type="text" inputmode="email" value="<?= e($soc['siret'] ?? '') ?>">
    </div>

    <div class="col-span-2">
      <label>Numéro de TVA</label>
      <input name="tva_intra" type="text" inputmode="email" value="<?= e($soc['tva_intra'] ?? '') ?>">
    </div>
  </div>

  <div style="margin-top:10px;">
    <?php if ($isAdmin): ?>
      <button class="btn btn-primary" type="submit">💾 Enregistrer</button>
    <?php else: ?>
      <button class="btn" type="button" disabled>Lecture seule</button>
    <?php endif; ?>
  </div>
</form>
  </div>
</div>
</body>
</html>