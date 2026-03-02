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
                $stmt = $pdo->prepare('SELECT * FROM energia_users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Mise à jour du last_login
                    $upd = $pdo->prepare('UPDATE energia_users SET last_login = NOW() WHERE id = ?');
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
    <title>Connexion - Climatisation</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 500px;
            margin: 40px auto;
            background: #f8f9fa;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 { text-align: center; color: #333; }
        label { font-weight: bold; }
        input[type=text], input[type=password] {
            width: 100%; padding: 8px; margin: 5px 0 15px 0;
            border: 1px solid #ccc; border-radius: 5px;
        }
        button {
            width: 100%; padding: 10px;
            background: #007bff; color: #fff;
            border: none; border-radius: 5px;
            font-size: 16px; cursor: pointer;
        }
        button:hover { background: #0056b3; }
        .success {
            background: #d4edda; color: #155724;
            padding: 10px; border-radius: 5px; margin-bottom: 15px;
        }
        .error {
            background: #f8d7da; color: #721c24;
            padding: 10px; border-radius: 5px; margin-bottom: 15px;
        }
        .links { margin-top: 16px; text-align: center; }
        .links a { text-decoration: none; }
    </style>
</head>
<body>
    <h2>Connexion</h2>

    <?php if ($message): ?>
        <div class="error"><?= h($message) ?></div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <label for="username">Nom d'utilisateur :</label>
        <input type="text" id="username" name="username" required value="<?= h($_POST['username'] ?? '') ?>">

        <label for="password">Mot de passe :</label>
        <input type="password" id="password" name="password" required>

        <label>
            <input type="checkbox" id="showpwd"> Afficher le mot de passe
        </label>
        <br><br>

        <button type="submit">Se connecter</button>
    </form>

    <script>
        (function(){
            const toggle = document.getElementById('showpwd');
            const pw = document.getElementById('password');
            if (toggle && pw) {
                toggle.addEventListener('change', function(){
                    pw.type = this.checked ? 'text' : 'password';
                });
            }
        })();
    </script>
</body>
</html>
