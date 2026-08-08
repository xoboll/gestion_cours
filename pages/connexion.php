<?php
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../index.php');
}

$email        = trim($_POST['email'] ?? '');
$mot_de_passe = $_POST['mot_de_passe'] ?? '';

if (!$email || !$mot_de_passe) {
    $_SESSION['error_msg'] = 'Veuillez remplir tous les champs.';
    redirect('../index.php');
}

$db = getDB();

// 1. Chercher dans l'administration
$stmt = $db->prepare("SELECT * FROM administration WHERE email = ?");
$stmt->execute([$email]);
$admin = $stmt->fetch();

if ($admin && verifyPassword($mot_de_passe, $admin['mot_de_passe'])) {
    $_SESSION['user_id']    = $admin['id'];
    $_SESSION['user_type']  = 'admin';
    $_SESSION['user_nom']   = 'Administrateur';
    $_SESSION['user_email'] = $admin['email'];
    redirect('admin.php');
}

// 2. Chercher dans les enseignants
$stmt = $db->prepare("SELECT * FROM enseignants WHERE email = ?");
$stmt->execute([$email]);
$enseignant = $stmt->fetch();

if ($enseignant && verifyPassword($mot_de_passe, $enseignant['mot_de_passe'])) {
    $stmtU = $db->prepare("SELECT universite_id FROM enseignant_universites WHERE enseignant_id = ?");
    $stmtU->execute([$enseignant['id']]);
    $univIds = array_column($stmtU->fetchAll(), 'universite_id');

    $stmtC = $db->prepare("SELECT classe FROM enseignant_classes WHERE enseignant_id = ?");
    $stmtC->execute([$enseignant['id']]);
    $classesEns = array_column($stmtC->fetchAll(), 'classe');

    $stmtN = $db->prepare("SELECT niveau FROM enseignant_niveaux WHERE enseignant_id = ?");
    $stmtN->execute([$enseignant['id']]);
    $niveauxEns = array_column($stmtN->fetchAll(), 'niveau');

    $_SESSION['user_id']     = $enseignant['id'];
    $_SESSION['user_type']   = 'enseignant';
    $_SESSION['user_nom']    = $enseignant['prenom'] . ' ' . $enseignant['nom'];
    $_SESSION['user_email']  = $enseignant['email'];
    $_SESSION['urf_id']      = $enseignant['urf_id'];
    $_SESSION['universites'] = $univIds;
    $_SESSION['classes']     = $classesEns;
    $_SESSION['niveaux']     = $niveauxEns;
    redirect('enseignant.php');
}

// 3. Chercher dans les étudiants
$stmt = $db->prepare("SELECT * FROM etudiants WHERE email = ?");
$stmt->execute([$email]);
$etudiant = $stmt->fetch();

if ($etudiant && verifyPassword($mot_de_passe, $etudiant['mot_de_passe'])) {
    $_SESSION['user_id']       = $etudiant['id'];
    $_SESSION['user_type']     = 'etudiant';
    $_SESSION['user_nom']      = $etudiant['prenom'] . ' ' . $etudiant['nom'];
    $_SESSION['user_email']    = $etudiant['email'];
    $_SESSION['universite_id'] = $etudiant['universite_id'];
    $_SESSION['urf_id']        = $etudiant['urf_id'];
    $_SESSION['niveau']        = $etudiant['niveau'];
    $_SESSION['classe']        = $etudiant['classe'];
    redirect('etudiant.php');
}

$_SESSION['error_msg'] = 'Email ou mot de passe incorrect.';
redirect('../index.php');
