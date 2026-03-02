<?php
$host = 'coprovsesteban.mysql.db';
$dbname = 'coprovsesteban';
$user = 'coprovsesteban';
$password = 'Climatisation2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('Erreur : ' . $e->getMessage());
}
?>