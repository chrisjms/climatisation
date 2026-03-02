<?php
// ===== Redirection propre vers la page de connexion =====

// URL cible
$target = "http://coproven.eu/Clim/login.php";

// Construit l'URL actuelle
$current = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$current .= "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";

// Si on n'est pas déjà sur la bonne adresse, redirige
if ($current !== $target) {
    header("Location: $target", true, 301); // Redirection permanente (SEO-friendly)
    exit;
}

// Message de secours si la redirection échoue
echo "<html><body><p>Redirection en cours vers <a href='$target'>$target</a>...</p></body></html>";
?>
