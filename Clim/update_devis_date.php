<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(400);
    exit('CSRF invalide');
}

$id = isset($_POST['devis_id']) ? (int)$_POST['devis_id'] : 0;
$dc = $_POST['date_creation'] ?? '';

if ($id <= 0) {
    header('Location: devis.php?err=ID devis invalide');
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dc)) {
    header('Location: devis.php?err=Format de date invalide');
    exit;
}

$date_creation = str_replace('T', ' ', $dc).':00'; // Y-m-d H:i:00

$stmt = $pdo->prepare('UPDATE devis SET date_creation = :dc WHERE id = :id');
$stmt->execute([':dc' => $date_creation, ':id' => $id]);

header('Location: devis.php?msg=Date de création mise à jour');
