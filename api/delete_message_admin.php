<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$messageId = intval($_POST['message_id'] ?? 0);
if (!$messageId) {
    echo json_encode(['success' => false, 'message' => 'Message invalide']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
$stmt->execute([$messageId]);

echo json_encode(['success' => true]);
