<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$action = $_POST['action'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// --------- Si on clique sur "TEST DEVIS", on génère le PDF via devis_pdf_layout.php ----------
if ($isPost && $action === 'test_devis') {
    // Vérif CSRF simple
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        echo 'Requête invalide (CSRF).';
        exit;
    }

    // Construire un "faux" devis à partir du formulaire
    $devis = [
        'numero'          	=> $_POST['numero']          			?? 'test_numero',
        'date_creation'   	=> $_POST['date_creation']   			?? date('Y-m-d'),
        'date_echeance'   	=> $_POST['date_echeance']   			?? date('Y-m-d', strtotime('+30 days')),
        'code_client'     	=> $_POST['code_client']     			?? 'test_code_client',
        'mode_paiement'  	=> $_POST['mode_paiement']  			?? 'test_mode_paiement',
        'nom'      			=> $_POST['nom']      			?? 'test_client_nom',
        'adresse'  			=> $_POST['adresse']  	?? 'test_client_adresse',
		'code_postal' 		=> $codePostal,
        'ville'       		=> $ville,
        // Champs annexes 
		'piece' 			=> $_POST['piece']	?? 'test_piece',
        'description_installation' => $_POST['description_installation'] ?? 'test_Description de l installation',
    ];

    // Lignes (on autorise par ex. 5 lignes de test)
    $lignes = [];
    $linesPost = $_POST['lines'] ?? [];

    for ($i = 0; $i < 5; $i++) {
        $line = $linesPost[$i] ?? [];

        $code = trim($line['code'] ?? ('test_code_' . ($i + 1)));
        $desc = trim($line['description'] ?? ('test_description_' . ($i + 1)));
        $qte  = str_replace(',', '.', (string)($line['qte'] ?? '1'));
        $pu   = str_replace(',', '.', (string)($line['pu_ht'] ?? '100'));
        $tva  = str_replace(',', '.', (string)($line['tva'] ?? '20'));

        $qteF = (float)$qte;
        $puF  = (float)$pu;
        $tvaF = (float)$tva;

        // On ignore une ligne complètement vide
        if ($code === '' && $desc === '') {
            continue;
        }

        $mht = $qteF * $puF;

        $lignes[] = [
            'code'        => $code,
            'description' => $desc,
            'qte'         => $qteF,
            'pu_ht'       => $puF,
            'montant_ht'  => $mht,
            'tva'         => $tvaF,
        ];
    }

    if (!$lignes) {
        // Au cas où tout est vide, on force une ligne de test
        $lignes[] = [
            'code'        => 'test_code_1',
            'description' => 'test_description_1',
            'qte'         => 1,
            'pu_ht'       => 100,
            'montant_ht'  => 100,
            'tva'         => 20,
        ];
    }

    // Appel de la mise en page PDF dédiée
    require_once __DIR__ . '/devis_pdf_layout.php';

    if (!function_exists('energia_render_devis_pdf')) {
        http_response_code(500);
        echo "Fonction energia_render_devis_pdf() introuvable dans devis_pdf_layout.php";
        exit;
    }

    energia_render_devis_pdf($devis, $lignes);
    exit;
}

// --------- Valeurs par défaut pour affichage du formulaire (GET ou POST sans action PDF) ----------

$def = function(string $name, string $fallback) {
    return isset($_POST[$name]) ? (string)$_POST[$name] : $fallback;
};

$defLines = function(int $i, string $field, string $fallback) {
    return isset($_POST['lines'][$i][$field]) ? (string)$_POST['lines'][$i][$field] : $fallback;
};

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test PDF - Energia</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <link rel="stylesheet" href="style.css">
    <style>
      /* Petit bloc pour aligner les boutons de test en haut */
      .section-nav {
        position: sticky;
        top: 0;
        z-index: 5;
        background: var(--bg-2, #f3f6fb);
        padding: 10px 0 14px;
        margin-bottom: 16px;
        border-bottom: 1px solid var(--bd, #d9e1ec);
        display: flex;
        gap: 10px;
        align-items: center;
      }
      .section-nav .title {
        font-weight: 600;
        margin-right: auto;
      }
      .form-grid {
        display: grid;
        grid-template-columns: 1.2fr 1.2fr;
        gap: 16px;
      }
      @media (max-width: 960px) {
        .form-grid {
          grid-template-columns: 1fr;
        }
      }
      .card {
        background: var(--card, #fff);
        border-radius: var(--r-md, 12px);
        box-shadow: var(--shadow-1, 0 4px 14px rgba(15, 23, 42, .08));
        padding: 16px 18px;
      }
      .card h2 {
        margin-top: 0;
        font-size: 18px;
        margin-bottom: 10px;
      }
      .stack {
        display: grid;
        gap: 10px;
      }
      .lines-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
      }
      .lines-table th,
      .lines-table td {
        border: 1px solid var(--bd, #d9e1ec);
        padding: 6px 8px;
        vertical-align: top;
      }
      .lines-table th {
        background: var(--bg-2, #f3f6fb);
        text-align: left;
      }
    </style>
</head>
<body>

<?php require __DIR__ . '/inc/sidebar.php'; ?>

<div class="main">
  <div class="section-nav">
    <div class="title">Tests de génération PDF</div>

    <button type="submit" form="form-test-pdf" name="action" value="test_devis" class="btn-primary" formaction="test_PDF.php" formtarget="_blank">
      TEST DEVIS
    </button>
    <button type="button" class="btn-secondary" disabled>
      TEST FACTURE (à venir)
    </button>
    <button type="button" class="btn-secondary" disabled>
      TEST BDC (à venir)
    </button>
  </div>

  <form method="post" id="form-test-pdf">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="form-grid">
      <!-- Bloc infos devis -->
      <div class="card">
        <h2>Informations Devis</h2>
        <div class="stack">
          <div>
            <label for="numero">Numéro de devis</label>
            <input type="text" id="numero" name="numero"
                   value="<?= htmlspecialchars($def('numero', 'test_numero')) ?>">
          </div>
		  <div>
			  <label for="description">Description de l'installation</label>
			  <textarea id="description" name="description" rows="4"
						style="width:100%;resize:vertical;"><?= htmlspecialchars($def('description', 'test_Description de l installation')) ?></textarea>
			</div>
			<div>
			  <label for="piece">Piece</label>
			  <textarea id="piece" name="piece" rows="1"
						style="width:100%;resize:vertical;"><?= htmlspecialchars($def('piece', 'test_piece')) ?></textarea>
			</div>
          <div>
            <label for="date_creation">Date</label>
            <input type="date" id="date_creation" name="date_creation"
                   value="<?= htmlspecialchars($def('date_creation', date('Y-m-d'))) ?>">
          </div>
          <div>
            <label for="date_echeance">Date de validité / échéance</label>
            <input type="date" id="date_echeance" name="date_echeance"
                   value="<?= htmlspecialchars($def('date_echeance', date('Y-m-d', strtotime('+30 days')))) ?>">
          </div>
          <div>
            <label for="code_client">Code client</label>
            <input type="text" id="code_client" name="code_client"
                   value="<?= htmlspecialchars($def('code_client', 'test_code_client')) ?>">
          </div>
          <div>
            <label for="mode_reglement">Mode de règlement</label>
            <input type="text" id="mode_reglement" name="mode_reglement"
                   value="<?= htmlspecialchars($def('mode_paiement', 'test_mode_paiement')) ?>">
          </div>
        </div>
      </div>

      <!-- Bloc client -->
      <div class="card">
        <h2>Client</h2>
        <div class="stack">
          <div>
            <label for="client_nom">Nom / Raison sociale</label>
            <input type="text" id="nom" name="nom"
                   value="<?= htmlspecialchars($def('nom', 'test_nomduclient')) ?>">
          </div>
          <div>
            <label for="client_adresse">Adresse</label>
            <input type="text" id="adresse" name="adresse"
                   value="<?= htmlspecialchars($def('adresse', 'test_adresseduclient')) ?>">
          </div>
          <div>
            <label for="client_cp_ville">Code postal + Ville</label>
            <input type="text" id="ville" name="ville"
                   value="<?= htmlspecialchars($def('ville', 'test_villeduclient')) ?>">
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:24px;" class="card">
      <h2>Lignes de devis (test)</h2>
      <p class="muted">
        Renseigne quelques lignes pour voir l’impact sur le tableau du PDF.
        Par défaut chaque champ est prérempli avec <code>test_…</code>.
      </p>

      <table class="lines-table">
        <thead>
        <tr>
          <th style="width:120px;">Code</th>
          <th>Description</th>
          <th style="width:70px;">Qté</th>
          <th style="width:110px;">P.U. HT</th>
          <th style="width:80px;">TVA %</th>
        </tr>
        </thead>
        <tbody>
        <?php for ($i = 0; $i < 5; $i++): ?>
          <tr>
            <td>
              <input type="text"
                     name="lines[<?= $i ?>][code]"
                     value="<?= htmlspecialchars($defLines($i, 'code', 'test_code_' . ($i + 1))) ?>">
            </td>
            <td>
              <textarea name="lines[<?= $i ?>][description]" rows="2"
                        style="width:100%;resize:vertical;"><?= htmlspecialchars($defLines($i, 'description', 'test_description_' . ($i + 1))) ?></textarea>
            </td>
            <td>
              <input type="number" step="0.01" min="0"
                     name="lines[<?= $i ?>][qte]"
                     value="<?= htmlspecialchars($defLines($i, 'qte', '1')) ?>">
            </td>
            <td>
              <input type="number" step="0.01" min="0"
                     name="lines[<?= $i ?>][pu_ht]"
                     value="<?= htmlspecialchars($defLines($i, 'pu_ht', '100')) ?>">
            </td>
            <td>
              <input type="number" step="0.01" min="0"
                     name="lines[<?= $i ?>][tva]"
                     value="<?= htmlspecialchars($defLines($i, 'tva', '20')) ?>">
            </td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:18px; display:flex; gap:10px;">
    <button type="submit" form="form-test-pdf" name="action" value="test_devis" class="btn-primary" formaction="test_PDF.php" formtarget="_blank">
      TEST DEVIS
    </button>
      <button type="button" class="btn-secondary" disabled>TEST FACTURE (à venir)</button>
      <button type="button" class="btn-secondary" disabled>TEST BDC (à venir)</button>
    </div>
  </form>
</div>

</body>
</html>
