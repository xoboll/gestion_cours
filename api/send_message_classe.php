<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'enseignant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$contenu      = trim($_POST['contenu'] ?? '');
$mode         = $_POST['mode'] ?? 'tous';
$enseignantId = $_SESSION['user_id'];

if (!$contenu) {
    echo json_encode(['success' => false, 'message' => 'Le message ne peut pas être vide']);
    exit;
}

$db = getDB();

// Infos de l'enseignant (matière)
$stmtEns = $db->prepare("SELECT urf_id FROM enseignants WHERE id = ?");
$stmtEns->execute([$enseignantId]);
$ens = $stmtEns->fetch();
if (!$ens) {
    echo json_encode(['success' => false, 'message' => 'Enseignant introuvable']);
    exit;
}

if ($mode === 'tous') {
    // Visible par TOUS les étudiants qui partagent université + niveau + classe + URF de l'enseignant
    $stmt = $db->prepare("
        INSERT INTO messages (expediteur_type, expediteur_id, destinataire_type, contenu)
        VALUES ('enseignant', ?, 'classe_enseignant', ?)
    ");
    $stmt->execute([$enseignantId, $contenu]);
    echo json_encode(['success' => true]);
    exit;
}

if ($mode === 'groupe') {
    $univId = intval($_POST['dest_universite_id'] ?? 0);
    $niveau = trim($_POST['dest_niveau'] ?? '');
    $classe = trim($_POST['dest_classe'] ?? '');

    if (!$univId || !$niveau || !$classe) {
        echo json_encode(['success' => false, 'message' => 'Groupe incomplet']);
        exit;
    }

    // Vérifier que ce groupe fait bien partie des classes de l'enseignant
    $stmtCheck = $db->prepare("
        SELECT 1
        FROM enseignant_universites eu
        JOIN enseignant_niveaux en ON en.enseignant_id = eu.enseignant_id
        JOIN enseignant_classes ec ON ec.enseignant_id = eu.enseignant_id
        WHERE eu.enseignant_id = ? AND eu.universite_id = ? AND en.niveau = ? AND ec.classe = ?
    ");
    $stmtCheck->execute([$enseignantId, $univId, $niveau, $classe]);
    if (!$stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ce groupe ne fait pas partie de vos classes']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO messages (expediteur_type, expediteur_id, destinataire_type, dest_universite_id, dest_niveau, dest_classe, contenu)
        VALUES ('enseignant', ?, 'groupe_etudiants', ?, ?, ?, ?)
    ");
    $stmt->execute([$enseignantId, $univId, $niveau, $classe, $contenu]);
    echo json_encode(['success' => true]);
    exit;
}

if ($mode === 'etudiant') {
    $etudiantId = intval($_POST['destinataire_etudiant_id'] ?? 0);
    if (!$etudiantId) {
        echo json_encode(['success' => false, 'message' => 'Étudiant non spécifié']);
        exit;
    }

    // Vérifier que cet étudiant fait bien partie des classes de l'enseignant
    $stmtCheck = $db->prepare("
        SELECT e.id
        FROM etudiants e
        JOIN enseignants ens ON ens.id = ? AND ens.urf_id = e.urf_id
        JOIN enseignant_universites eu ON eu.enseignant_id = ens.id AND eu.universite_id = e.universite_id
        JOIN enseignant_classes ec ON ec.enseignant_id = ens.id AND ec.classe = e.classe
        JOIN enseignant_niveaux en ON en.enseignant_id = ens.id AND en.niveau = e.niveau
        WHERE e.id = ?
    ");
    $stmtCheck->execute([$enseignantId, $etudiantId]);
    if (!$stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Cet étudiant ne fait pas partie de vos classes']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO messages (expediteur_type, expediteur_id, destinataire_type, destinataire_etudiant_id, contenu)
        VALUES ('enseignant', ?, 'etudiant_specifique', ?, ?)
    ");
    $stmt->execute([$enseignantId, $etudiantId, $contenu]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Mode invalide']);
