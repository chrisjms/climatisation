<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Vérif CSRF via en‑tête
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo 'CSRF invalide';
    exit;
}

// Lire JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$cid = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$details = $data['details'] ?? '';

if ($cid <= 0) {
    http_response_code(400);
    echo 'client_id manquant';
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE energia_clients SET details = ? WHERE id = ?');
    $stmt->execute([$details, $cid]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur serveur: ' . $e->getMessage();
}
