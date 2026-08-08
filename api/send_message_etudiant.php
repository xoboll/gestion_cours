<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'etudiant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$contenu      = trim($_POST['contenu'] ?? '');
$enseignantId = intval($_POST['enseignant_id'] ?? 0);
$etudiantId   = $_SESSION['user_id'];

if (!$contenu || !$enseignantId) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

$db = getDB();

// Vérifier que cet enseignant enseigne bien à cet étudiant
// (même université, même URF, même niveau, même classe)
$stmtCheck = $db->prepare("
    SELECT ens.id
    FROM enseignants ens
    JOIN etudiants e ON e.id = ? AND e.urf_id = ens.urf_id
    JOIN enseignant_universites eu ON eu.enseignant_id = ens.id AND eu.universite_id = e.universite_id
    JOIN enseignant_classes ec ON ec.enseignant_id = ens.id AND ec.classe = e.classe
    JOIN enseignant_niveaux en ON en.enseignant_id = ens.id AND en.niveau = e.niveau
    WHERE ens.id = ?
");
$stmtCheck->execute([$etudiantId, $enseignantId]);
if (!$stmtCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Cet enseignant ne fait pas partie de vos enseignants']);
    exit;
}

$stmt = $db->prepare("
    INSERT INTO messages (expediteur_type, expediteur_id, destinataire_type, destinataire_enseignant_id, contenu)
    VALUES ('etudiant', ?, 'enseignant_specifique', ?, ?)
");
$stmt->execute([$etudiantId, $enseignantId, $contenu]);

echo json_encode(['success' => true]);
