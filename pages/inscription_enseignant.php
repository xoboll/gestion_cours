<?php
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php');

// Toute erreur sur cette page doit rouvrir le formulaire d'inscription enseignant (pas la connexion)
$_SESSION['error_modal'] = 'modal-inscription-enseignant';

$nom          = trim($_POST['nom'] ?? '');
$prenom       = trim($_POST['prenom'] ?? '');
$tel          = trim($_POST['tel'] ?? '');
$email        = trim($_POST['email'] ?? '');
$mot_de_passe = $_POST['mot_de_passe'] ?? '';
$urf_id       = intval($_POST['urf_id'] ?? 0);
$universites  = array_map('intval', $_POST['universites'] ?? []);
$classes      = $_POST['classes'] ?? [];
$niveaux      = $_POST['niveaux'] ?? [];

$universites = array_values(array_unique(array_filter($universites)));
$classes     = array_values(array_unique(array_filter($classes)));
$niveaux     = array_values(array_unique(array_filter($niveaux)));

// Champs obligatoires
if (!$nom || !$prenom || !$email || !$mot_de_passe || !$urf_id
    || empty($universites) || empty($classes) || empty($niveaux)) {
    $_SESSION['error_msg'] = 'Veuillez remplir tous les champs obligatoires.';
    redirect('../index.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_msg'] = 'Adresse e-mail invalide.';
    redirect('../index.php');
}

if (strlen($mot_de_passe) < 8) {
    $_SESSION['error_msg'] = 'Le mot de passe doit contenir au moins 8 caractères.';
    redirect('../index.php');
}

$classesValides = ['Amphi A', 'Amphi B'];
$niveauxValides = ['Licence 1', 'Licence 2', 'Licence 3', 'Master 1', 'Master 2'];

if (count($classes) > 2 || count(array_diff($classes, $classesValides)) > 0) {
    $_SESSION['error_msg'] = 'Sélection de classe invalide (1 ou 2 classes parmi Amphi A / Amphi B).';
    redirect('../index.php');
}

if (count($niveaux) > 2 || count(array_diff($niveaux, $niveauxValides)) > 0) {
    $_SESSION['error_msg'] = 'Sélection de niveau invalide (1 ou 2 niveaux maximum).';
    redirect('../index.php');
}

$db = getDB();

// Vérifier l'URF
$stmtF = $db->prepare("SELECT id FROM urfs WHERE id = ?");
$stmtF->execute([$urf_id]);
if (!$stmtF->fetch()) {
    $_SESSION['error_msg'] = 'Matière (URF) invalide.';
    redirect('../index.php');
}

// Vérifier les universités choisies : soit UNE université publique,
// soit UNE OU DEUX universités privées (jamais un mélange)
$placeholders = implode(',', array_fill(0, count($universites), '?'));
$stmtU = $db->prepare("SELECT id, type FROM universites WHERE id IN ($placeholders)");
$stmtU->execute($universites);
$universitesTrouvees = $stmtU->fetchAll();

if (count($universitesTrouvees) !== count($universites)) {
    $_SESSION['error_msg'] = 'Une ou plusieurs universités sélectionnées sont invalides.';
    redirect('../index.php');
}

$typesTrouves = array_unique(array_column($universitesTrouvees, 'type'));

if (count($typesTrouves) > 1) {
    $_SESSION['error_msg'] = 'Vous ne pouvez pas combiner une université publique avec des universités privées.';
    redirect('../index.php');
}
if ($typesTrouves[0] === 'publique' && count($universites) > 1) {
    $_SESSION['error_msg'] = 'Une seule université publique peut être sélectionnée.';
    redirect('../index.php');
}
if ($typesTrouves[0] === 'privee' && count($universites) > 2) {
    $_SESSION['error_msg'] = 'Vous pouvez sélectionner une ou deux universités privées maximum.';
    redirect('../index.php');
}

// Email déjà utilisé ?
$stmt = $db->prepare("SELECT id FROM enseignants WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    $_SESSION['error_msg'] = 'Un compte existe déjà avec cet email. Connectez-vous ou utilisez "Mot de passe oublié" si besoin.';
    redirect('../index.php');
}

$hash = hashPassword($mot_de_passe);

$db->beginTransaction();
try {
    $stmt = $db->prepare("INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
    $stmt->execute([$nom, $prenom, $tel ?: null, $email, $urf_id, $hash]);
    $enseignantId = $stmt->fetchColumn();

    $stmtU = $db->prepare("INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES (?, ?)");
    foreach ($universites as $uid) { $stmtU->execute([$enseignantId, $uid]); }

    $stmtC = $db->prepare("INSERT INTO enseignant_classes (enseignant_id, classe) VALUES (?, ?)");
    foreach ($classes as $cl) { $stmtC->execute([$enseignantId, $cl]); }

    $stmtN = $db->prepare("INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES (?, ?)");
    foreach ($niveaux as $niv) { $stmtN->execute([$enseignantId, $niv]); }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['error_msg'] = 'Une erreur est survenue lors de l\'inscription. Veuillez réessayer.';
    redirect('../index.php');
}

// Connexion automatique et redirection vers le tableau de bord personnel
$_SESSION['user_id']      = $enseignantId;
$_SESSION['user_type']    = 'enseignant';
$_SESSION['user_nom']     = $prenom . ' ' . $nom;
$_SESSION['user_email']   = $email;
$_SESSION['urf_id']       = $urf_id;
$_SESSION['universites']  = $universites;
$_SESSION['classes']      = $classes;
$_SESSION['niveaux']      = $niveaux;

redirect('enseignant.php');
