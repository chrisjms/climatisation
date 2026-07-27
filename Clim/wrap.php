<?php
// wrap.php — ancien wrapper de debug pour generer_facture.php.
// Désactivé : son rôle (afficher les stack traces brutes) constitue une fuite d'info en prod.
// Garde le fichier pour ne casser aucun bookmark éventuel ; redirige proprement.
header('Location: index.php', true, 302);
exit;
