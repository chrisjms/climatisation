<?php
require 'auth.php';
require 'config.php';

$action = $_GET['action'] ?? 'list';
$id     = $_GET['id']     ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Insert / Update
    $nom        = trim($_POST['nom_du_compte']);
    $iban       = trim($_POST['iban']);
    $bic        = trim($_POST['bic']);
    $titulaire  = trim($_POST['titulaire']);
    $banque     = trim($_POST['banque']);
    $est_actif  = isset($_POST['est_actif']) ? 1 : 0;

    if ($_POST['id']) {
        // update
        $stmt = $pdo->prepare("
          UPDATE bank_accounts
             SET nom_du_compte = ?, iban = ?, bic = ?, titulaire = ?, banque = ?, est_actif = ?
           WHERE id = ?
        ");
        $stmt->execute([$nom, $iban, $bic, $titulaire, $banque, $est_actif, $_POST['id']]);
    } else {
        // insert
        $stmt = $pdo->prepare("
          INSERT INTO bank_accounts
            (nom_du_compte, iban, bic, titulaire, banque, est_actif)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $iban, $bic, $titulaire, $banque, $est_actif]);
    }
    header('Location: manage_bank.php');
    exit;
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$id]);
    header('Location: manage_bank.php');
    exit;
}

$PILL_ICONS = [
    'active'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'inactive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Banques — Gestion</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
  <div class="page-header">
    <div>
      <?php if (in_array($action, ['add', 'edit'])): ?>
        <div class="breadcrumb"><a href="manage_bank.php">Banques</a><span class="sep">&#8250;</span><span class="current"><?= $action === 'edit' ? 'Modifier' : 'Nouveau compte' ?></span></div>
      <?php endif; ?>
      <h1>Comptes bancaires</h1>
      <div class="subtitle">Ajoutez et gerez les comptes bancaires utilises sur vos factures et devis</div>
    </div>
    <?php if (!in_array($action, ['add', 'edit'])): ?>
      <div class="actions">
        <a href="?action=add" class="btn btn-primary">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
          Nouveau compte
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (in_array($action, ['add', 'edit'])):
    if ($action === 'edit') {
      $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
      $stmt->execute([$id]);
      $row  = $stmt->fetch();
    } else {
      $row = [
        'id' => '',
        'nom_du_compte' => '',
        'iban' => '',
        'bic' => '',
        'titulaire' => '',
        'banque' => '',
        'est_actif' => 1
      ];
    }
  ?>
    <div class="grid-2">
      <section class="card">
        <h2><?= $action === 'edit' ? 'Modifier le compte' : 'Nouveau compte bancaire' ?></h2>
        <p class="muted"><?= $action === 'edit' ? 'Mettez a jour les informations bancaires ci-dessous' : 'Renseignez les coordonnees bancaires du nouveau compte' ?></p>

        <form method="post" class="stack">
          <input type="hidden" name="id" value="<?= $row['id'] ?>">

          <div class="form-group">
            <label for="nom_du_compte">Nom du compte</label>
            <input type="text" id="nom_du_compte" name="nom_du_compte" value="<?= htmlspecialchars($row['nom_du_compte']) ?>" placeholder="Ex : Compte principal" required>
          </div>

          <hr>

          <div class="row-grid">
            <div class="form-group">
              <label for="iban">IBAN</label>
              <input type="text" id="iban" name="iban" class="mono" value="<?= htmlspecialchars($row['iban']) ?>" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX">
              <div class="hint">Format international a 27 caracteres</div>
            </div>
            <div class="form-group">
              <label for="bic">BIC</label>
              <input type="text" id="bic" name="bic" class="mono" value="<?= htmlspecialchars($row['bic']) ?>" placeholder="BNPAFRPP">
            </div>
          </div>

          <div class="row-grid">
            <div class="form-group">
              <label for="titulaire">Titulaire</label>
              <input type="text" id="titulaire" name="titulaire" value="<?= htmlspecialchars($row['titulaire']) ?>">
            </div>
            <div class="form-group">
              <label for="banque">Banque</label>
              <input type="text" id="banque" name="banque" value="<?= htmlspecialchars($row['banque']) ?>">
            </div>
          </div>

          <hr>

          <div class="form-group">
            <label class="inline checkbox-label">
              <input type="checkbox" name="est_actif" <?= $row['est_actif'] ? 'checked' : '' ?>>
              <span>Compte actif</span>
            </label>
            <div class="hint">Les comptes actifs apparaissent dans le selecteur lors de la creation de factures</div>
          </div>

          <div class="inline">
            <button type="submit" class="btn btn-save">Enregistrer</button>
            <a href="manage_bank.php" class="btn btn-secondary">Annuler</a>
          </div>
        </form>
      </section>

      <section class="card">
        <h2>Aide</h2>
        <p class="muted">Informations sur les comptes bancaires</p>
        <ul class="help-list">
          <li>Les comptes <strong>actifs</strong> sont proposes lors de la generation de factures.</li>
          <li>L'IBAN et le BIC seront imprimes sur les factures PDF.</li>
          <li>Vous pouvez desactiver un compte sans le supprimer.</li>
        </ul>

        <hr>

        <h3>Besoin d'aide ?</h3>
        <p class="hint">Retrouvez votre IBAN et BIC sur votre releve bancaire ou dans l'espace en ligne de votre banque.</p>
      </section>
    </div>

  <?php else: ?>

    <?php
    $banks = $pdo->query("SELECT * FROM bank_accounts ORDER BY id DESC")->fetchAll();
    $activeCount = 0;
    foreach ($banks as $b) {
        if ($b['est_actif']) $activeCount++;
    }
    ?>

    <div class="stat-card green">
      <div class="stat-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
      </div>
      <div>
        <div class="stat-label">Comptes actifs</div>
        <div class="stat-value"><?= $activeCount ?></div>
        <div class="stat-sub">sur <?= count($banks) ?> compte<?= count($banks) > 1 ? 's' : '' ?> au total</div>
      </div>
    </div>

    <section class="card">
      <h2>Comptes enregistres</h2>
      <p class="muted">Liste de tous les comptes bancaires configures pour la facturation</p>

      <?php if (empty($banks)): ?>
        <div class="empty-state">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
          <p>Aucun compte bancaire enregistre</p>
          <a href="?action=add" class="btn btn-primary">Ajouter un compte</a>
        </div>
      <?php else: ?>
        <div class="table-wrap table-responsive">
          <table class="table-sticky">
            <thead>
              <tr>
                <th>Nom</th>
                <th>IBAN</th>
                <th>BIC</th>
                <th>Titulaire</th>
                <th>Banque</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($banks as $b): ?>
                <tr>
                  <td data-label="Nom"><strong><?= htmlspecialchars($b['nom_du_compte']) ?></strong></td>
                  <td data-label="IBAN" class="mono"><?= htmlspecialchars($b['iban']) ?></td>
                  <td data-label="BIC" class="mono"><?= htmlspecialchars($b['bic']) ?></td>
                  <td data-label="Titulaire"><?= htmlspecialchars($b['titulaire']) ?></td>
                  <td data-label="Banque"><?= htmlspecialchars($b['banque']) ?></td>
                  <td data-label="Statut">
                    <?php if ($b['est_actif']): ?>
                      <span class="status-pill active"><span class="pill-icon"><?= $PILL_ICONS['active'] ?></span>Actif</span>
                    <?php else: ?>
                      <span class="status-pill inactive"><span class="pill-icon"><?= $PILL_ICONS['inactive'] ?></span>Inactif</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="inline">
                      <a href="?action=edit&id=<?= $b['id'] ?>" class="btn btn-sm">Modifier</a>
                      <a href="?action=delete&id=<?= $b['id'] ?>"
                         class="btn btn-sm btn-danger"
                         onclick="event.preventDefault(); var u=this.href; confirmAction('Supprimer ce compte bancaire ?', function(){ location.href=u; }, {title:'Suppression', confirmText:'Supprimer', danger:true})">Supprimer</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/inc/toast.php'; ?>
<?php require __DIR__ . '/inc/modal.php'; ?>
</body>
</html>
