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
          UPDATE energia_bank_accounts
             SET nom_du_compte = ?, iban = ?, bic = ?, titulaire = ?, banque = ?, est_actif = ?
           WHERE id = ?
        ");
        $stmt->execute([$nom, $iban, $bic, $titulaire, $banque, $est_actif, $_POST['id']]);
    } else {
        // insert
        $stmt = $pdo->prepare("
          INSERT INTO energia_bank_accounts
            (nom_du_compte, iban, bic, titulaire, banque, est_actif)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $iban, $bic, $titulaire, $banque, $est_actif]);
    }
    header('Location: manage_bank.php');
    exit;
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM energia_bank_accounts WHERE id = ?")->execute([$id]);
    header('Location: manage_bank.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Banques – Gestion Devis & Factures</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>


<div class="main">
  <h1>Gestion des comptes bancaires</h1>

  <?php if (in_array($action, ['add', 'edit'])):
    if ($action === 'edit') {
      $stmt = $pdo->prepare("SELECT * FROM energia_bank_accounts WHERE id = ?");
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
    <form method="post" class="bank-form">
      <input type="hidden" name="id" value="<?= $row['id'] ?>">

      <label>Nom du compte</label>
      <input type="text" name="nom_du_compte" value="<?= htmlspecialchars($row['nom_du_compte']) ?>">

      <label>IBAN</label>
      <input type="text" name="iban" value="<?= htmlspecialchars($row['iban']) ?>">

      <label>BIC</label>
      <input type="text" name="bic" value="<?= htmlspecialchars($row['bic']) ?>">

      <label>Titulaire</label>
      <input type="text" name="titulaire" value="<?= htmlspecialchars($row['titulaire']) ?>">

      <label>Banque</label>
      <input type="text" name="banque" value="<?= htmlspecialchars($row['banque']) ?>">

      <label>
        <input type="checkbox" name="est_actif" <?= $row['est_actif'] ? 'checked' : '' ?>>
        Actif
      </label>

      <div class="actions">
        <button type="submit">Enregistrer</button>
        <a href="manage_bank.php">Annuler</a>
      </div>
    </form>

  <?php else: ?>

    <a href="?action=add" class="new-btn">+ Nouveau compte</a>

    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nom</th>
          <th>IBAN</th>
          <th>BIC</th>
          <th>Titulaire</th>
          <th>Banque</th>
          <th>Actif</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $banks = $pdo->query("SELECT * FROM energia_bank_accounts ORDER BY id DESC")->fetchAll();
        foreach ($banks as $b): ?>
          <tr>
            <td><?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['nom_du_compte']) ?></td>
            <td><?= htmlspecialchars($b['iban']) ?></td>
            <td><?= htmlspecialchars($b['bic']) ?></td>
            <td><?= htmlspecialchars($b['titulaire']) ?></td>
            <td><?= htmlspecialchars($b['banque']) ?></td>
            <td><?= $b['est_actif'] ? '✓' : '–' ?></td>
            <td>
              <a href="?action=edit&id=<?= $b['id'] ?>">✎</a>
              &nbsp;
              <a href="?action=delete&id=<?= $b['id'] ?>"
                 class="delete-link"
                 onclick="return confirm('Supprimer ce compte ?')">
                🗑️
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php endif; ?>
</div>

</body>
</html>
