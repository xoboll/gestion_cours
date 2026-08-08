<?php
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('../index.php');

// Toute erreur sur cette page doit rouvrir le formulaire d'inscription étudiant (pas la connexion)
$_SESSION['error_modal'] = 'modal-inscription-etudiant';

$nom               = trim($_POST['nom'] ?? '');
$prenom            = trim($_POST['prenom'] ?? '');
$age               = trim($_POST['age'] ?? '');
$annee_academique  = trim($_POST['annee_academique'] ?? '');
$universite_id     = intval($_POST['universite_id'] ?? 0);
$urf_id            = intval($_POST['urf_id'] ?? 0);
$email             = trim($_POST['email'] ?? '');
$mot_de_passe      = $_POST['mot_de_passe'] ?? '';
$matricule         = trim($_POST['matricule'] ?? '');
$tel               = trim($_POST['tel'] ?? '');
$classe            = trim($_POST['classe'] ?? '');
$niveau            = trim($_POST['niveau'] ?? '');

// Champs obligatoires
if (!$nom || !$prenom || !$annee_academique || !$universite_id || !$urf_id
    || !$email || !$mot_de_passe || !$matricule || !$classe || !$niveau) {
    $_SESSION['error_msg'] = 'Veuillez remplir tous les champs obligatoires.';
    redirect('../index.php');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_msg'] = 'Adresse e-mail invalide.';
    redirect('../index.php');
}

$classesValides = ['Amphi A', 'Amphi B'];
$niveauxValides = ['Licence 1', 'Licence 2', 'Licence 3', 'Master 1', 'Master 2'];

if (!in_array($classe, $classesValides, true) || !in_array($niveau, $niveauxValides, true)) {
    $_SESSION['error_msg'] = 'Classe ou niveau invalide.';
    redirect('../index.php');
}

$age = $age !== '' ? intval($age) : null;

$db = getDB();

// Vérifier que l'université et l'URF choisies existent bien
$stmtU = $db->prepare("SELECT id FROM universites WHERE id = ?");
$stmtU->execute([$universite_id]);
if (!$stmtU->fetch()) {
    $_SESSION['error_msg'] = 'Université invalide.';
    redirect('../index.php');
}

$stmtF = $db->prepare("SELECT id FROM urfs WHERE id = ?");
$stmtF->execute([$urf_id]);
if (!$stmtF->fetch()) {
    $_SESSION['error_msg'] = 'URF invalide.';
    redirect('../index.php');
}

// Email déjà utilisé ?
$stmt = $db->prepare("SELECT id FROM etudiants WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    $_SESSION['error_msg'] = 'Un compte existe déjà avec cet email. Connectez-vous ou utilisez "Mot de passe oublié" si besoin.';
    redirect('../index.php');
}

// Matricule déjà utilisé ?
$stmt = $db->prepare("SELECT id FROM etudiants WHERE matricule = ?");
$stmt->execute([$matricule]);
if ($stmt->fetch()) {
    $_SESSION['error_msg'] = 'Ce matricule est déjà utilisé.';
    redirect('../index.php');
}

$hash = hashPassword($mot_de_passe);

$stmt = $db->prepare("
    INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    RETURNING id
");
$stmt->execute([$nom, $prenom, $age, $annee_academique, $universite_id, $urf_id, $email, $hash, $matricule, $tel ?: null, $classe, $niveau]);

$etudiantId = $stmt->fetchColumn();

// Connexion automatique et redirection vers le tableau de bord personnel
$_SESSION['user_id']       = $etudiantId;
$_SESSION['user_type']     = 'etudiant';
$_SESSION['user_nom']      = $prenom . ' ' . $nom;
$_SESSION['user_email']    = $email;
$_SESSION['universite_id'] = $universite_id;
$_SESSION['urf_id']        = $urf_id;
$_SESSION['niveau']        = $niveau;
$_SESSION['classe']        = $classe;

redirect('etudiant.php');
