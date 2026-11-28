<?php
declare(strict_types=1);
session_start();
session_regenerate_id(true);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
echo htmlspecialchars($_GET['name'] ?? '', ENT_QUOTES, 'UTF-8');
$hash = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32));
$dbPass = getenv('DB_PASSWORD');
include __DIR__ . '/header.php';
setcookie('sid', $value, ['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
$_SESSION['user_id'] = $user['id'];
// eval( is only mentioned in this comment
