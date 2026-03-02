<?php
/* inc/sidebar.php
   — Barre latérale unique, incluse dans toutes les pages
   — Mise en surbrillance automatique du lien actif
*/
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$username = $_SESSION['username'] ?? '';

$CURRENT = basename($_SERVER['SCRIPT_NAME']); // ex: "clientele.php"

/* Marque un item actif si la page courante correspond à l’un des fichiers listés */
function nav_active(array $files, string $current): string {
    foreach ($files as $f) {
        if ($current === $f) { return 'active'; }
    }
    return '';
}

/* Déclare ici les entrées de menu (tu peux en ajouter/retirer)
   "match" permet de grouper plusieurs pages sous le même onglet.
*/
$NAV = [
    ['href'=>'index.php',          'icon'=>'🏠', 'label'=>'Accueil',                    'match'=>['index.php']],
    ['href'=>'clientele.php',      'icon'=>'👥', 'label'=>'Clientèle',                  'match'=>['clientele.php','ajout_client.php','update_client.php']],
    ['href'=>'devis.php',          'icon'=>'➕', 'label'=>'Devis/Factures/BDC',         'match'=>['devis.php','traitement_devis.php','generer_facture.php','generer_bdc.php','factures.php']],
    ['href'=>'brochures.php',      'icon'=>'📄', 'label'=>'Brochures',                  'match'=>['brochures.php']],
    ['href'=>'ajout_pac.php',      'icon'=>'🔥', 'label'=>'Matériels',                  'match'=>['ajout_pac.php']],
    ['href'=>'manage_bank.php',    'icon'=>'🏦', 'label'=>'Banques',                    'match'=>['manage_bank.php']],
    ['href'=>'analyses.php',       'icon'=>'📊', 'label'=>'Analyses et rapports',       'match'=>['analyses.php']],
    ['href'=>'gestion_comptes.php','icon'=>'👤', 'label'=>'Gestion des comptes',        'match'=>['gestion_comptes.php']],
	//['href'=>'gestion_societe.php','icon'=>'🏢', 'label'=>'Infos société',      'match'=>['gestion_societe.php']],
];

?>
<div class="sidebar">
  <div class="logo">Climatisation</div>
  <nav>
    <?php foreach ($NAV as $item): ?>
      <a href="<?= htmlspecialchars($item['href']) ?>"
         class="<?= nav_active($item['match'], $CURRENT) ?>">
        <span><?= $item['icon'] ?></span>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>

    <a href="logout.php">
      🔒 Déconnexion <?= $username !== '' ? '(' . htmlspecialchars($username) . ')' : '' ?>
    </a>
  </nav>
</div>
