<?php
require_once '../includes/config.php';

$error = '';
$success = '';

// Ordre de recherche : administration, puis enseignants, puis étudiants
$tables = [
    'admin'      => 'administration',
    'enseignant' => 'enseignants',
    'etudiant'   => 'etudiants',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse e-mail valide.';
    } else {
        $db = getDB();
        $userType = null;

        foreach ($tables as $type => $table) {
            $stmt = $db->prepare("SELECT id FROM $table WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $userType = $type;
                break;
            }
        }

        if ($userType) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $insert = $db->prepare("INSERT INTO password_resets (user_type, email, token, expires_at) VALUES (?, ?, ?, ?)");
            $insert->execute([$userType, $email, $token, $expiresAt]);

            $resetLink = baseUrl('pages/reinitialiser_mot_de_passe.php?token=' . urlencode($token));
            $message = "Bonjour,\n\nVous avez demandé une réinitialisation de votre mot de passe CampusLink.\nCliquez sur le lien ci-dessous pour définir un nouveau mot de passe :\n\n$resetLink\n\nCe lien expire dans 1 heure.\nSi vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet e-mail.";
            $headers = "From: no-reply@gestcours.ci\r\nReply-To: no-reply@gestcours.ci\r\nX-Mailer: PHP/" . phpversion();
            $sent = @mail($email, 'Réinitialisation de votre mot de passe – CampusLink', $message, $headers);

            if ($sent) {
                $success = 'Un e-mail de réinitialisation a été envoyé à votre adresse.';
            } else {
                // Serveur local (WampServer) sans configuration mail : on affiche le lien directement
                $success = "L'envoi automatique d'e-mail n'est pas configuré sur ce serveur. Utilisez ce lien pour réinitialiser votre mot de passe : <a href=\"$resetLink\" style=\"color:#4F46E5;\">$resetLink</a>";
            }
        } else {
            // Message volontairement neutre pour ne pas révéler si l'email existe
            $success = 'Si cette adresse est reconnue, un lien de réinitialisation vous sera envoyé.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié – CampusLink</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body style="background:var(--noir);min-height:100vh;display:flex;align-items:center;justify-content:center;">

<div style="width:100%;max-width:460px;padding:20px;">
    <a href="../index.php" style="color:rgba(255,255,255,.5);text-decoration:none;font-size:.88rem;display:block;margin-bottom:24px;">← Retour à l'accueil</a>

    <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:44px 40px;">
        <div style="text-align:center;margin-bottom:28px;">
            <div style="font-size:3rem;margin-bottom:12px;">🔑</div>
            <h1 style="font-family:'Sora',serif;font-size:1.7rem;color:white;font-weight:700;">Mot de passe oublié</h1>
            <p style="color:rgba(255,255,255,.45);font-size:.88rem;margin-top:6px;">Saisissez votre email, nous détectons automatiquement votre profil</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><?= $success /* peut contenir un lien HTML généré par le serveur */ ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label style="color:rgba(255,255,255,.70);">Adresse e-mail</label>
                <input type="email" name="email" placeholder="votre@email.com"
                    style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:white;"
                    required>
            </div>
            <button type="submit" class="btn-submit" style="margin-top:8px;">Envoyer le lien de réinitialisation →</button>
        </form>

        <div style="margin-top:20px;text-align:center;">
            <a href="../index.php" style="color:rgba(255,255,255,.65);font-size:.9rem;text-decoration:none;">← Retour à la connexion</a>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@700;800&family=Inter:wght@400;600&display=swap">
</body>
</html>
