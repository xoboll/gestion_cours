<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$etudiantId = intval($_POST['etudiant_id'] ?? 0);
if (!$etudiantId) {
    echo json_encode(['success' => false, 'message' => 'ID invalide']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("DELETE FROM etudiants WHERE id = ?");
$stmt->execute([$etudiantId]);

echo json_encode(['success' => true]);
