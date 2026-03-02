<?php
require 'auth.php';
require 'config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Lire le body une seule fois
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Récupérer le CSRF depuis l'en-tête OU (fallback) depuis le JSON
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfBody   = is_array($data) && isset($data['csrf_token']) ? $data['csrf_token'] : '';
$csrf       = $csrfHeader ?: $csrfBody;

if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo 'CSRF token invalide';
    exit;
}

if (!is_array($data) || !isset($data['orders']) || !is_array($data['orders'])) {
    http_response_code(400);
    echo 'Payload invalide';
    exit;
}

// $data['orders'] attendu: { "": [3,9,2] } ou { "5": [8,6] } – une catégorie à la fois
try {
    $pdo->beginTransaction();

    foreach ($data['orders'] as $catKey => $ids) {
        if (!is_array($ids)) continue;

        $isNull = ($catKey === '' || $catKey === null);
        $categoryId = $isNull ? null : (int)$catKey;

        // Normaliser la liste d'IDs (ints uniques)
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Mettre à jour position séquentielle
        $position = 1;
        foreach ($ids as $id) {
            if ($isNull) {
                $stmt = $pdo->prepare('UPDATE energia_pompes_a_chaleur SET position = ? WHERE id = ? AND category_id IS NULL');
                $stmt->execute([$position, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE energia_pompes_a_chaleur SET position = ? WHERE id = ? AND category_id = ?');
                $stmt->execute([$position, $id, $categoryId]);
            }
            $position++;
        }
    }

    $pdo->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo 'Erreur serveur: ' . $e->getMessage();
}
