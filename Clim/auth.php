<?php
// --- Session ---
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Réglages (facultatif)
 * On conserve uniquement l'hygiène de session (régénération d'ID).
 * La déconnexion automatique par inactivité a été SUPPRIMÉE.
 */
$REGEN_INTERVAL = 300; // Régénère l'ID de session toutes les 5 minutes (mets 0 pour désactiver)

/* ───────── Hygiène de session : régénération périodique de l'ID ───────── */
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif ($REGEN_INTERVAL > 0 && time() - $_SESSION['created'] > $REGEN_INTERVAL) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

/* ───────── Nettoyage optionnel : on supprime l'ancien marqueur d'activité ───────── */
if (isset($_SESSION['last_activity'])) {
    unset($_SESSION['last_activity']);
}

/* ───────── Contrôle d'accès ───────── */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
