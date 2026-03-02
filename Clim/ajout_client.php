<?php
require 'auth.php';
require 'config.php';

$message = '';
$retour = $_GET['retour'] ?? 'clientele.php'; // page de retour par défaut

// Helpers
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function toNull($v){ return ($v === '' ? null : $v); }
function norm_phone(string $p): string {
    $p = trim($p);
    $p = preg_replace('/[^\d+]/', '', $p); // garde chiffres et +
    return $p;
}
function trunc(?string $s, int $len): ?string {
    if ($s === null) return null;
    return mb_substr($s, 0, $len);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Champs “fiche”
    $nom         = trim($_POST['nom'] ?? '');
    $prenom      = trim($_POST['prenom'] ?? '');
    $adresse     = trim($_POST['adresse'] ?? '');
    $code_postal = trim($_POST['code_postal'] ?? '');
    $ville       = trim($_POST['ville'] ?? '');
    $details     = trim($_POST['details'] ?? '');
    $retour_post = $_POST['retour'] ?? 'clientele.php';

    // Date (YYYY-MM-DD)
    $date_ajout = trim($_POST['date_ajout'] ?? date('Y-m-d'));
    $date_ok = false;
    if ($date_ajout !== '') {
        $dt     = DateTime::createFromFormat('Y-m-d', $date_ajout);
        $errors = DateTime::getLastErrors();
        $date_ok = $dt && $errors['warning_count'] === 0 && $errors['error_count'] === 0;
    }
    if (!$date_ok) $date_ajout = date('Y-m-d');

    // Téléphones (tableau)
    $telephones_raw = $_POST['telephone'] ?? [];
    if (!is_array($telephones_raw)) $telephones_raw = [$telephones_raw];
    $tel_labels_raw = $_POST['tel_label'] ?? [];
    if (!is_array($tel_labels_raw)) $tel_labels_raw = [$tel_labels_raw];

    $tels = []; $telLbl = [];
    foreach ($telephones_raw as $i => $t) {
        $t = norm_phone((string)$t);
        if ($t !== '') {
            $tels[]   = $t;
            $telLbl[] = trunc(trim((string)($tel_labels_raw[$i] ?? '')), 50);
        }
    }
    // Déduplication
    $seenTel = []; $tels_u = []; $telLbl_u = [];
    foreach ($tels as $i => $t) {
        if (isset($seenTel[$t])) continue;
        $seenTel[$t] = true;
        $tels_u[] = $t;
        $telLbl_u[] = $telLbl[$i] ?? null;
    }

    // Emails (tableau) — facultatifs
    $emails_raw = $_POST['email'] ?? [];
    if (!is_array($emails_raw)) $emails_raw = [$emails_raw];
    $email_labels_raw = $_POST['email_label'] ?? [];
    if (!is_array($email_labels_raw)) $email_labels_raw = [$email_labels_raw];

    $emails = []; $emailLbl = [];
    foreach ($emails_raw as $i => $m) {
        $m = trim((string)$m);
        if ($m !== '') {
            $m = mb_strtolower($m);
            $emails[]   = $m;
            $emailLbl[] = trunc(trim((string)($email_labels_raw[$i] ?? '')), 50);
        }
    }
    $seenMail = []; $emails_u = []; $emailLbl_u = [];
    foreach ($emails as $i => $m) {
        if (isset($seenMail[$m])) continue;
        $seenMail[$m] = true;
        $emails_u[]   = $m;
        $emailLbl_u[] = $emailLbl[$i] ?? null;
    }

    // Principaux pour la table clients
    $telephone_main = $tels_u[0]   ?? null;
    $email_main     = $emails_u[0] ?? null; // peut rester null (email facultatif)

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        // Insert client (conserve les champs “compat”)
        $stmt = $pdo->prepare('
            INSERT INTO clients (nom, prenom, telephone, email, adresse, code_postal, ville, details, date_ajout)
            VALUES (:nom, :prenom, :telephone, :email, :adresse, :code_postal, :ville, :details, :date_ajout)
        ');
        $stmt->execute([
            ':nom'         => toNull($nom),
            ':prenom'      => toNull($prenom),
            ':telephone'   => toNull($telephone_main),
            ':email'       => toNull($email_main), // NULL si aucun email saisi
            ':adresse'     => toNull($adresse),
            ':code_postal' => toNull($code_postal),
            ':ville'       => toNull($ville),
            ':details'     => toNull($details),
            ':date_ajout'  => $date_ajout,
        ]);
        $clientId = (int)$pdo->lastInsertId();

        // Téléphones multiples
        if (!empty($tels_u)) {
            $sqlTel = "
                INSERT INTO client_telephones (client_id, phone, label)
                VALUES (:cid, :ph, :lbl)
                ON DUPLICATE KEY UPDATE label = VALUES(label)
            ";
            $insTel = $pdo->prepare($sqlTel);
            foreach ($tels_u as $i => $ph) {
                $insTel->execute([
                    ':cid' => $clientId,
                    ':ph'  => $ph,
                    ':lbl' => toNull($telLbl_u[$i] ?? null),
                ]);
            }
        }

        // Emails multiples (seulement si fournis)
        if (!empty($emails_u)) {
            $sqlMail = "
                INSERT INTO client_emails (client_id, email, label)
                VALUES (:cid, :em, :lbl)
                ON DUPLICATE KEY UPDATE label = VALUES(label)
            ";
            $insMail = $pdo->prepare($sqlMail);
            foreach ($emails_u as $i => $em) {
                $insMail->execute([
                    ':cid' => $clientId,
                    ':em'  => $em,
                    ':lbl' => toNull($emailLbl_u[$i] ?? null),
                ]);
            }
        }

        $pdo->commit();
        header("Location: $retour_post");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "Impossible d'enregistrer le client : " . e($e->getMessage());
    }
}

// Valeur par défaut du champ date
$default_date_value = e($_POST['date_ajout'] ?? date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Ajouter un client - Climatisation</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<?php require __DIR__ . '/inc/sidebar.php'; ?>


<div class="main">
    <div class="card">
        <h2>Ajouter un nouveau client</h2>

        <?php if ($message): ?>
            <div class="info"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST" action="ajout_client.php?retour=<?= urlencode($retour) ?>">
            <input type="hidden" name="retour" value="<?= e($retour) ?>">

            <div class="grid-2">
                <!-- Colonne gauche : Téléphones + Emails + Date + Enregistrer -->
                <div class="stack">
                    <!-- Téléphones -->
                    <div class="section">
                        <h3>Téléphone (principal)</h3>
                        <div id="phones-wrapper">
                            <?php
                            $postedTels = $_POST['telephone'] ?? [];
                            $postedLbls = $_POST['tel_label'] ?? [];
                            if (!is_array($postedTels)) $postedTels = [$postedTels];
                            if (!is_array($postedLbls)) $postedLbls = [$postedLbls];
                            if (empty($postedTels)) $postedTels = [''];
                            foreach ($postedTels as $i => $v):
                                $lab = $postedLbls[$i] ?? '';
                            ?>
                            <div class="row-grid phone-row">
                                <input type="text" name="telephone[]" value="<?= e($v) ?>" placeholder="+33 6 12 34 56 78">
                                <input type="text" name="tel_label[]" value="<?= e($lab) ?>" placeholder="Libellé (Pro, Perso…)">
                                <button type="button" class="trash" onclick="removeRow(this)">🗑</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="toolbar">
                            <button type="button" class="btn btn-add" onclick="addPhone()">➕ Ajouter un téléphone</button>
                            <span class="hint">Le premier numéro sera utilisé comme « téléphone principal ».</span>
                        </div>
                    </div>

                    <!-- Emails (facultatif) -->
                    <div class="section">
                        <h3>Email (principal) — facultatif</h3>
                        <div id="emails-wrapper">
                            <?php
                            $postedMails   = $_POST['email'] ?? [];
                            $postedMailLbl = $_POST['email_label'] ?? [];
                            if (!is_array($postedMails))   $postedMails   = [$postedMails];
                            if (!is_array($postedMailLbl)) $postedMailLbl = [$postedMailLbl];
                            if (empty($postedMails)) $postedMails = [''];
                            foreach ($postedMails as $i => $m):
                                $ml = $postedMailLbl[$i] ?? '';
                            ?>
                            <div class="row-grid email-row">
                                <input type="email" name="email[]" value="<?= e($m) ?>" placeholder="exemple@mail.com">
                                <input type="text" name="email_label[]" value="<?= e($ml) ?>" placeholder="Libellé (Pro, Perso…)">
                                <button type="button" class="trash" onclick="removeRow(this)">🗑</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="toolbar">
                            <button type="button" class="btn btn-add" onclick="addEmail()">➕ Ajouter un email</button>
                            <span class="hint">Vous pouvez laisser vide si vous n’avez pas d’email.</span>
                        </div>
                    </div>

                    <!-- Date + Bouton -->
                    <div class="section" style="display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:center">
                        <label for="date_ajout">Date d'ajout :</label>
                        <input type="date" id="date_ajout" name="date_ajout" value="<?= $default_date_value ?>">
                    </div>

                    <div>
                        <button type="submit" class="btn btn-save">✔ Ajouter</button>
                    </div>
                </div>

                <!-- Colonne droite : Identité + Adresse -->
                <div class="stack">
                    <div class="section">
                        <div class="stack">
                            <div>
                                <label for="nom">Nom ou raison social :</label>
                                <input type="text" id="nom" name="nom" value="<?= e($_POST['nom'] ?? '') ?>">
                                <div class="hint">Renseignez le nom de famille ou la raison sociale.</div>
                            </div>
                            <div>
                                <label for="prenom">Prénom ou SIRET :</label>
                                <input type="text" id="prenom" name="prenom" value="<?= e($_POST['prenom'] ?? '') ?>" placeholder="">
                                <div class="hint">Pour une entreprise, vous pouvez mettre le SIRET ici (facultatif).</div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="stack">
                            <div>
                                <label for="adresse">Adresse :</label>
                                <textarea id="adresse" name="adresse" rows="3"><?= e($_POST['adresse'] ?? '') ?></textarea>
                            </div>
                            <div style="display:grid;grid-template-columns:180px 1fr;gap:12px">
                                <div>
                                    <label for="code_postal">Code postal :</label>
                                    <input type="text" id="code_postal" name="code_postal" value="<?= e($_POST['code_postal'] ?? '') ?>">
                                </div>
                                <div>
                                    <label for="ville">Ville :</label>
                                    <input type="text" id="ville" name="ville" value="<?= e($_POST['ville'] ?? '') ?>">
                                </div>
                            </div>
                            <div>
                                <label for="details">Détails et informations :</label>
                                <textarea id="details" name="details" rows="4"><?= e($_POST['details'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- /grid-2 -->
        </form>
    </div>
</div>

<script>
function removeRow(btn){
    const row = btn.closest('.row-grid');
    if (!row) return;
    const wrap = row.parentElement;
    row.remove();

    // S'assurer qu'il reste au moins une ligne dans chaque bloc
    if (wrap && wrap.id === 'phones-wrapper'  && wrap.children.length === 0) addPhone(true);
    if (wrap && wrap.id === 'emails-wrapper'  && wrap.children.length === 0) addEmail(true);

    // Masquer l’icône poubelle de la 1ère ligne
    refreshTrashVisibility('phones-wrapper','phone-row');
    refreshTrashVisibility('emails-wrapper','email-row');
}
function refreshTrashVisibility(wrapperId, rowClass){
    const rows = document.querySelectorAll('#'+wrapperId+' .'+rowClass+' .trash');
    rows.forEach((b, i) => { b.style.visibility = i === 0 ? 'hidden' : 'visible'; });
}
function addPhone(){
    const wrap = document.getElementById('phones-wrapper');
    const el = document.createElement('div');
    el.className = 'row-grid phone-row';
    el.innerHTML = `
        <input type="text" name="telephone[]" placeholder="+33 6 12 34 56 78">
        <input type="text" name="tel_label[]" placeholder="Libellé (Pro, Perso…)">
        <button type="button" class="trash" onclick="removeRow(this)">🗑</button>
    `;
    wrap.appendChild(el);
    refreshTrashVisibility('phones-wrapper','phone-row');
}
function addEmail(){
    const wrap = document.getElementById('emails-wrapper');
    const el = document.createElement('div');
    el.className = 'row-grid email-row';
    el.innerHTML = `
        <input type="email" name="email[]" placeholder="exemple@mail.com">
        <input type="text" name="email_label[]" placeholder="Libellé (Pro, Perso…)">
        <button type="button" class="trash" onclick="removeRow(this)">🗑</button>
    `;
    wrap.appendChild(el);
    refreshTrashVisibility('emails-wrapper','email-row');
}
document.addEventListener('DOMContentLoaded', () => {
    refreshTrashVisibility('phones-wrapper','phone-row');
    refreshTrashVisibility('emails-wrapper','email-row');
});
</script>
</body>
</html>
