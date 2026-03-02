<?php
session_start();
require 'config.php'; // doit fournir $pdo

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Erreur : connexion PDO non valide (vérifiez config.php)";
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérif CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Jeton de sécurité invalide.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            try {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Mise à jour du last_login
                    $upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                    $upd->execute([$user['id']]);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];

                    header('Location: index.php');
                    exit();
                } else {
                    $message = 'Identifiant ou mot de passe incorrect.';
                }
            } catch (Throwable $e) {
                $message = 'Erreur serveur : ' . h($e->getMessage());
            }
        } else {
            $message = 'Veuillez remplir tous les champs.';
        }
    }
    // On régénère un token après un POST (évite la réutilisation)
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion - Climatisation</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="logo-area">
            <?php if (file_exists(__DIR__ . '/assets/logo.jpeg')): ?>
                <img src="assets/logo.jpeg" alt="Logo" width="48" height="48" style="margin:0 auto;border-radius:var(--r-sm);">
            <?php endif; ?>
            <h1>Climatisation</h1>
            <p>Connectez-vous pour acceder a votre espace</p>
        </div>

        <?php if ($message): ?>
            <div class="error"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required
                       value="<?= h($_POST['username'] ?? '') ?>"
                       placeholder="Votre identifiant">
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required
                           placeholder="Votre mot de passe">
                    <button type="button" class="password-toggle" id="togglePwd" aria-label="Afficher le mot de passe">
                        <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;margin-top:8px;">Se connecter</button>
        </form>
    </div>
</div>

<script>
(function(){
    const btn = document.getElementById('togglePwd');
    const pw = document.getElementById('password');
    const eyeOpen = document.getElementById('eyeOpen');
    const eyeClosed = document.getElementById('eyeClosed');
    if (btn && pw) {
        btn.addEventListener('click', function(){
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            eyeOpen.style.display = show ? 'none' : '';
            eyeClosed.style.display = show ? '' : 'none';
        });
    }
})();
</script>
</body>
</html>
