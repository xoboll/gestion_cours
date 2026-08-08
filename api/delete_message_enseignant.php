<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'enseignant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$messageId    = intval($_POST['message_id'] ?? 0);
$enseignantId = $_SESSION['user_id'];

if (!$messageId) {
    echo json_encode(['success' => false, 'message' => 'Message invalide']);
    exit;
}

$db = getDB();
// Un enseignant peut supprimer ses propres messages envoyés,
// ainsi que les messages reçus de ses étudiants
$stmt = $db->prepare("
    DELETE FROM messages
    WHERE id = ?
      AND (
          (expediteur_id = ? AND expediteur_type = 'enseignant')
          OR (destinataire_enseignant_id = ? AND expediteur_type = 'etudiant' AND destinataire_type = 'enseignant_specifique')
      )
");
$stmt->execute([$messageId, $enseignantId, $enseignantId]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Message introuvable ou non autorisé']);
}
