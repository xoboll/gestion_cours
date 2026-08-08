<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_type'] !== 'enseignant') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$etudiantId   = intval($_POST['etudiant_id'] ?? 0);
$noteCcRaw    = $_POST['note_cc'] ?? '';
$noteExamRaw  = $_POST['note_examen'] ?? '';
$enseignantId = $_SESSION['user_id'];

if (!$etudiantId || ($noteCcRaw === '' && $noteExamRaw === '')) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

foreach (['note_cc' => $noteCcRaw, 'note_examen' => $noteExamRaw] as $label => $val) {
    if ($val !== '' && ($val < 0 || $val > 20)) {
        echo json_encode(['success' => false, 'message' => 'Les notes doivent être comprises entre 0 et 20']);
        exit;
    }
}

$db = getDB();

// Vérifier que l'étudiant partage l'université, l'URF, le niveau et la classe de l'enseignant
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
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

// Récupérer la matière (URF) de l'enseignant
$stmtEns = $db->prepare("SELECT f.nom FROM enseignants e JOIN urfs f ON f.id = e.urf_id WHERE e.id = ?");
$stmtEns->execute([$enseignantId]);
$ens = $stmtEns->fetch();
$matiere = $ens['nom'] ?? 'Matière';

// Récupérer les notes existantes pour ne pas écraser une note non transmise dans cette requête
$stmtExisting = $db->prepare("SELECT note_cc, note_examen FROM notes WHERE etudiant_id = ? AND enseignant_id = ?");
$stmtExisting->execute([$etudiantId, $enseignantId]);
$existing = $stmtExisting->fetch();

$noteCc     = $noteCcRaw !== '' ? floatval($noteCcRaw) : ($existing['note_cc'] ?? null);
$noteExamen = $noteExamRaw !== '' ? floatval($noteExamRaw) : ($existing['note_examen'] ?? null);

// Moyenne finale = CC x 40% + Examen x 60%, calculée seulement si les deux notes sont présentes
$moyenne = ($noteCc !== null && $noteExamen !== null)
    ? round(($noteCc * 0.4) + ($noteExamen * 0.6), 2)
    : null;

// Upsert
$stmt = $db->prepare("
    INSERT INTO notes (etudiant_id, enseignant_id, matiere, note_cc, note_examen, moyenne, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT (etudiant_id, enseignant_id)
    DO UPDATE SET note_cc = EXCLUDED.note_cc, note_examen = EXCLUDED.note_examen, moyenne = EXCLUDED.moyenne, updated_at = CURRENT_TIMESTAMP
");
$stmt->execute([$etudiantId, $enseignantId, $matiere, $noteCc, $noteExamen, $moyenne]);

echo json_encode(['success' => true, 'note_cc' => $noteCc, 'note_examen' => $noteExamen, 'moyenne' => $moyenne]);
