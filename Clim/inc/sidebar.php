<?php
/* inc/sidebar.php
   — Professional sidebar navigation with SVG icons
   — Auto-highlights the active link based on the current page
*/
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$username = $_SESSION['username'] ?? '';

$CURRENT = basename($_SERVER['SCRIPT_NAME']);

function nav_active(array $files, string $current): string {
    foreach ($files as $f) {
        if ($current === $f) { return 'active'; }
    }
    return '';
}

/* SVG icons (Lucide-style, inline for zero dependencies) */
$ICONS = [
    'home'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'users'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'file'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 12h4"/><path d="M10 16h4"/></svg>',
    'folder'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>',
    'wrench'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/></svg>',
    'landmark' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>',
    'chart'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
    'shield'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    'logout'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2 2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>',
];

$NAV = [
    ['href'=>'index.php',          'icon'=>'home',     'label'=>'Accueil',                'match'=>['index.php']],
    ['href'=>'clientele.php',      'icon'=>'users',    'label'=>'Clientele',              'match'=>['clientele.php','ajout_client.php','update_client.php']],
    ['href'=>'devis.php',          'icon'=>'file',     'label'=>'Devis / Factures',       'match'=>['devis.php','traitement_devis.php','generer_facture.php','generer_bdc.php','factures.php']],
    ['href'=>'brochures.php',      'icon'=>'folder',   'label'=>'Brochures',              'match'=>['brochures.php']],
    ['href'=>'ajout_pac.php',      'icon'=>'wrench',   'label'=>'Materiels',              'match'=>['ajout_pac.php']],
    ['href'=>'manage_bank.php',    'icon'=>'landmark', 'label'=>'Banques',                'match'=>['manage_bank.php']],
    ['href'=>'analyses.php',       'icon'=>'chart',    'label'=>'Analyses',               'match'=>['analyses.php']],
    ['href'=>'gestion_comptes.php','icon'=>'shield',   'label'=>'Comptes',                'match'=>['gestion_comptes.php']],
];
?>
<div class="sidebar">
  <div class="logo">
    <?php if (file_exists(__DIR__ . '/../assets/logo.jpeg')): ?>
      <img src="assets/logo.jpeg" alt="Logo" width="32" height="32">
    <?php endif; ?>
    <span>Climatisation</span>
  </div>

  <nav>
    <?php foreach ($NAV as $item): ?>
      <a href="<?= htmlspecialchars($item['href']) ?>"
         class="<?= nav_active($item['match'], $CURRENT) ?>">
        <span class="nav-icon"><?= $ICONS[$item['icon']] ?? '' ?></span>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="logout.php">
      <span class="nav-icon"><?= $ICONS['logout'] ?></span>
      <span>Deconnexion<?= $username !== '' ? ' (' . htmlspecialchars($username) . ')' : '' ?></span>
    </a>
  </div>
</div>
