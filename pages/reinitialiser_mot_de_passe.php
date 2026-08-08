<?php
require_once '../includes/config.php';

$tables = [
    'admin'      => ['table' => 'administration', 'col' => 'mot_de_passe'],
    'enseignant' => ['table' => 'enseignants',     'col' => 'mot_de_passe'],
    'etudiant'   => ['table' => 'etudiants',       'col' => 'mot_de_passe'],
];

$error = '';
$success = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($token === '') {
    $error = 'Lien de réinitialisation invalide.';
} else {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_type, email, expires_at, used_at FROM password_resets WHERE token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset || !isset($tables[$reset['user_type']])) {
        $error = 'Ce lien de réinitialisation est invalide.';
    } elseif (!empty($reset['used_at'])) {
        $error = 'Ce lien a déjà été utilisé.';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $error = 'Ce lien a expiré. Veuillez faire une nouvelle demande.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword === '' || $confirmPassword === '') {
            $error = 'Veuillez remplir tous les champs.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $table = $tables[$reset['user_type']]['table'];
            $col   = $tables[$reset['user_type']]['col'];
            $hash  = hashPassword($newPassword);

            $update = $db->prepare("UPDATE $table SET $col = ? WHERE email = ?");
            $update->execute([$hash, $reset['email']]);

            $markUsed = $db->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE token = ?");
            $markUsed->execute([$token]);

            $success = 'Votre mot de passe a bien été réinitialisé. Vous pouvez maintenant vous connecter.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation du mot de passe – CampusLink</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body style="background:var(--noir);min-height:100vh;display:flex;align-items:center;justify-content:center;">
<div style="width:100%;max-width:460px;padding:20px;">
    <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:44px 40px;">
        <h1 style="font-family:'Sora',serif;font-size:1.7rem;color:white;font-weight:700;margin-bottom:12px;">Nouveau mot de passe</h1>
        <p style="color:rgba(255,255,255,.45);font-size:.88rem;margin-bottom:24px;">Définissez un nouveau mot de passe pour votre compte.</p>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
        <div style="margin-top:16px;text-align:center;">
            <a href="mot_de_passe_oublie.php" style="color:rgba(247,127,0,.9);font-size:.9rem;text-decoration:none;">Faire une nouvelle demande →</a>
        </div>
        <?php elseif ($success): ?>
        <div class="alert alert-success"><?= sanitize($success) ?></div>
        <div style="margin-top:16px;text-align:center;">
            <a href="../index.php" style="color:rgba(247,127,0,.9);font-size:.9rem;text-decoration:none;">Aller à la connexion →</a>
        </div>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="token" value="<?= sanitize($token) ?>">
            <div class="form-group">
                <label style="color:rgba(255,255,255,.70);">Nouveau mot de passe</label>
                <input type="password" name="new_password" placeholder="Minimum 8 caractères" minlength="8"
                    style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:white;"
                    required>
            </div>
            <div class="form-group">
                <label style="color:rgba(255,255,255,.70);">Confirmer le mot de passe</label>
                <input type="password" name="confirm_password" placeholder="••••••••" minlength="8"
                    style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:white;"
                    required>
            </div>
            <button type="submit" class="btn-submit" style="margin-top:8px;">Réinitialiser →</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
