<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'enseignant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$contenu      = trim($_POST['contenu'] ?? '');
$enseignantId = $_SESSION['user_id'];

if (!$contenu) {
    echo json_encode(['success' => false, 'message' => 'Le message ne peut pas être vide']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("
    INSERT INTO messages (expediteur_type, expediteur_id, destinataire_type, contenu)
    VALUES ('enseignant', ?, 'administration', ?)
");
$stmt->execute([$enseignantId, $contenu]);

echo json_encode(['success' => true]);
