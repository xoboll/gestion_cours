<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'etudiant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$messageId  = intval($_POST['message_id'] ?? 0);
$etudiantId = $_SESSION['user_id'];

if (!$messageId) {
    echo json_encode(['success' => false, 'message' => 'Message invalide']);
    exit;
}

$db = getDB();
// Un étudiant ne peut supprimer que ses propres messages envoyés
$stmt = $db->prepare("DELETE FROM messages WHERE id = ? AND expediteur_id = ? AND expediteur_type = 'etudiant'");
$stmt->execute([$messageId, $etudiantId]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Message introuvable ou non autorisé']);
}
