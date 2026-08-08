<?php
require_once '../includes/config.php';

if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    redirect('../index.php');
}

$db = getDB();

// Filtres étudiants
$filterUniversite = intval($_GET['universite_id'] ?? 0);
$filterNiveau     = trim($_GET['niveau'] ?? '');

// Stats
$stmtStats = $db->query("SELECT
    (SELECT COUNT(*) FROM etudiants) as total_etudiants,
    (SELECT COUNT(*) FROM enseignants) as total_enseignants,
    (SELECT COUNT(*) FROM universites) as total_universites,
    (SELECT COUNT(*) FROM messages WHERE expediteur_type='administration') as total_messages
");
$stats = $stmtStats->fetch();

// Universités et URF (dynamique, pour les étudiants et enseignants)
$stmtUniv = $db->query("SELECT * FROM universites ORDER BY type, nom");
$universites = $stmtUniv->fetchAll();

$stmtUrf = $db->query("SELECT * FROM urfs ORDER BY nom");
$urfs = $stmtUrf->fetchAll();

$niveauxListe = ['Licence 1', 'Licence 2', 'Licence 3', 'Master 1', 'Master 2'];
$classesAmphi = ['Amphi A', 'Amphi B'];

// Étudiants filtrés
$query = "SELECT e.*, u.nom as universite_nom, u.sigle as universite_sigle,
                 f.nom as urf_nom, f.sigle as urf_sigle
          FROM etudiants e
          JOIN universites u ON u.id = e.universite_id
          JOIN urfs f ON f.id = e.urf_id
          WHERE 1=1";
$params = [];
if ($filterUniversite) { $query .= " AND e.universite_id = ?"; $params[] = $filterUniversite; }
if ($filterNiveau)     { $query .= " AND e.niveau = ?";        $params[] = $filterNiveau; }
$query .= " ORDER BY u.nom, e.niveau, e.classe, e.nom, e.prenom";
$stmtEtu = $db->prepare($query);
$stmtEtu->execute($params);
$etudiants = $stmtEtu->fetchAll();

// Enseignants (avec matière, universités, classes et niveaux agrégés)
$stmtEns = $db->query("
    SELECT e.*, f.nom as urf_nom, f.sigle as urf_sigle,
           STRING_AGG(DISTINCT u.sigle, ', ' ORDER BY u.sigle) as universites_sigles,
           STRING_AGG(DISTINCT ec.classe, ', ' ORDER BY ec.classe) as classes_liste,
           STRING_AGG(DISTINCT en.niveau, ', ' ORDER BY en.niveau) as niveaux_liste
    FROM enseignants e
    JOIN urfs f ON f.id = e.urf_id
    LEFT JOIN enseignant_universites eu ON eu.enseignant_id = e.id
    LEFT JOIN universites u ON u.id = eu.universite_id
    LEFT JOIN enseignant_classes ec ON ec.enseignant_id = e.id
    LEFT JOIN enseignant_niveaux en ON en.enseignant_id = e.id
    GROUP BY e.id, f.nom, f.sigle
    ORDER BY f.nom, e.nom
");
$enseignants = $stmtEns->fetchAll();

// Messages admin envoyés (historique)
$stmtMsgsAdmin = $db->query("
    SELECT m.*, e.nom as ens_nom, e.prenom as ens_prenom,
           u.nom as dest_universite_nom, u.sigle as dest_universite_sigle
    FROM messages m
    LEFT JOIN enseignants e ON e.id = m.destinataire_enseignant_id
    LEFT JOIN universites u ON u.id = m.dest_universite_id
    WHERE m.expediteur_type = 'administration'
    ORDER BY m.created_at DESC LIMIT 30
");
$messagesAdmin = $stmtMsgsAdmin->fetchAll();

// Messages reçus des enseignants
$stmtMsgsEns = $db->query("
    SELECT m.*, e.nom as ens_nom, e.prenom as ens_prenom, f.sigle as urf_sigle,
           STRING_AGG(DISTINCT u.sigle, ', ' ORDER BY u.sigle) as universites_sigles
    FROM messages m
    JOIN enseignants e ON e.id = m.expediteur_id
    JOIN urfs f ON f.id = e.urf_id
    LEFT JOIN enseignant_universites eu ON eu.enseignant_id = e.id
    LEFT JOIN universites u ON u.id = eu.universite_id
    WHERE m.expediteur_type = 'enseignant' AND m.destinataire_type = 'administration'
    GROUP BY m.id, e.nom, e.prenom, f.sigle
    ORDER BY m.created_at DESC LIMIT 30
");
$messagesEnseignants = $stmtMsgsEns->fetchAll();

// Notes et moyennes (vue admin)
$stmtNotes = $db->query("
    SELECT n.*, e.nom as etu_nom, e.prenom as etu_prenom, e.matricule,
           ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM notes n
    JOIN etudiants e ON e.id = n.etudiant_id
    JOIN enseignants ens ON ens.id = n.enseignant_id
    ORDER BY e.nom, n.matiere
");
$toutesNotes = $stmtNotes->fetchAll();

// Gestion POST
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_etudiant') {
        $eid = intval($_POST['etudiant_id']);
        $db->prepare("DELETE FROM etudiants WHERE id = ?")->execute([$eid]);
        header("Location: admin.php?msg=" . urlencode('✅ Étudiant supprimé.') . "&universite_id=$filterUniversite&niveau=" . urlencode($filterNiveau) . "#etudiants");
        exit;
    }

    if ($action === 'add_etudiant') {
        $nom     = trim($_POST['nom'] ?? '');
        $prenom  = trim($_POST['prenom'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $tel     = trim($_POST['tel'] ?? '');
        $age     = $_POST['age'] !== '' ? intval($_POST['age']) : null;
        $annee   = trim($_POST['annee_academique'] ?? '');
        $univId  = intval($_POST['universite_id'] ?? 0);
        $urfId   = intval($_POST['urf_id'] ?? 0);
        $matricule = trim($_POST['matricule'] ?? '');
        $classe  = trim($_POST['classe'] ?? '');
        $niveau  = trim($_POST['niveau'] ?? '');
        $mdp     = $_POST['mot_de_passe'] ?? 'password123';

        $errEtu = '';
        if (!$nom || !$prenom || !$email || !$annee || !$univId || !$urfId || !$matricule || !$classe || !$niveau) {
            $errEtu = 'Veuillez remplir tous les champs obligatoires.';
        } else {
            $stmtChkEmail = $db->prepare("SELECT id FROM etudiants WHERE email = ?");
            $stmtChkEmail->execute([$email]);
            if ($stmtChkEmail->fetch()) {
                $errEtu = 'Cet email est déjà utilisé par un autre étudiant.';
            } else {
                $stmtChkMat = $db->prepare("SELECT id FROM etudiants WHERE matricule = ?");
                $stmtChkMat->execute([$matricule]);
                if ($stmtChkMat->fetch()) {
                    $errEtu = 'Ce matricule est déjà utilisé par un autre étudiant.';
                }
            }
        }

        if ($errEtu) {
            header("Location: admin.php?msg=" . urlencode('❌ ' . $errEtu) . '#etudiants');
            exit;
        }

        $hash = hashPassword($mdp);
        $db->prepare("INSERT INTO etudiants (nom,prenom,email,tel,age,annee_academique,universite_id,urf_id,matricule,classe,niveau,mot_de_passe) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$nom,$prenom,$email,$tel ?: null,$age,$annee,$univId,$urfId,$matricule,$classe,$niveau,$hash]);
        header("Location: admin.php?msg=" . urlencode('✅ Étudiant ajouté.') . '#etudiants');
        exit;
    }

    if ($action === 'edit_etudiant') {
        $eid     = intval($_POST['etudiant_id']);
        $nom     = trim($_POST['nom'] ?? '');
        $prenom  = trim($_POST['prenom'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $tel     = trim($_POST['tel'] ?? '');
        $age     = $_POST['age'] !== '' ? intval($_POST['age']) : null;
        $annee   = trim($_POST['annee_academique'] ?? '');
        $univId  = intval($_POST['universite_id'] ?? 0);
        $urfId   = intval($_POST['urf_id'] ?? 0);
        $matricule = trim($_POST['matricule'] ?? '');
        $classe  = trim($_POST['classe'] ?? '');
        $niveau  = trim($_POST['niveau'] ?? '');

        $errEtu = '';
        $stmtChkEmail = $db->prepare("SELECT id FROM etudiants WHERE email = ? AND id != ?");
        $stmtChkEmail->execute([$email, $eid]);
        if ($stmtChkEmail->fetch()) {
            $errEtu = 'Cet email est déjà utilisé par un autre étudiant.';
        } else {
            $stmtChkMat = $db->prepare("SELECT id FROM etudiants WHERE matricule = ? AND id != ?");
            $stmtChkMat->execute([$matricule, $eid]);
            if ($stmtChkMat->fetch()) {
                $errEtu = 'Ce matricule est déjà utilisé par un autre étudiant.';
            }
        }

        if ($errEtu) {
            header("Location: admin.php?msg=" . urlencode('❌ ' . $errEtu) . '#etudiants');
            exit;
        }

        $db->prepare("UPDATE etudiants SET nom=?,prenom=?,email=?,tel=?,age=?,annee_academique=?,universite_id=?,urf_id=?,matricule=?,classe=?,niveau=? WHERE id=?")
           ->execute([$nom,$prenom,$email,$tel ?: null,$age,$annee,$univId,$urfId,$matricule,$classe,$niveau,$eid]);
        header("Location: admin.php?msg=" . urlencode('✅ Étudiant modifié.') . '#etudiants');
        exit;
    }

    if ($action === 'add_enseignant') {
        $nom     = trim($_POST['nom'] ?? '');
        $prenom  = trim($_POST['prenom'] ?? '');
        $tel     = trim($_POST['tel'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $mdp     = $_POST['mot_de_passe'] ?? '';
        $urfId   = intval($_POST['urf_id'] ?? 0);
        $univIds = array_values(array_unique(array_filter(array_map('intval', $_POST['universites'] ?? []))));
        $classesSel = array_values(array_unique(array_filter($_POST['classes'] ?? [])));
        $niveauxSel = array_values(array_unique(array_filter($_POST['niveaux'] ?? [])));

        $errAdd = '';
        if (!$nom || !$prenom || !$email || !$mdp || !$urfId || empty($univIds) || empty($classesSel) || empty($niveauxSel)) {
            $errAdd = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (strlen($mdp) < 8) {
            $errAdd = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif (count($classesSel) > 2 || count(array_diff($classesSel, $classesAmphi)) > 0) {
            $errAdd = 'Sélection de classe invalide.';
        } elseif (count($niveauxSel) > 2 || count(array_diff($niveauxSel, $niveauxListe)) > 0) {
            $errAdd = 'Sélection de niveau invalide.';
        } else {
            $ph = implode(',', array_fill(0, count($univIds), '?'));
            $stmtU = $db->prepare("SELECT id, type FROM universites WHERE id IN ($ph)");
            $stmtU->execute($univIds);
            $found = $stmtU->fetchAll();
            $types = array_unique(array_column($found, 'type'));
            if (count($found) !== count($univIds)) {
                $errAdd = 'Université(s) invalide(s).';
            } elseif (count($types) > 1) {
                $errAdd = 'Impossible de combiner université publique et privée.';
            } elseif ($types[0] === 'publique' && count($univIds) > 1) {
                $errAdd = 'Une seule université publique peut être sélectionnée.';
            } elseif ($types[0] === 'privee' && count($univIds) > 2) {
                $errAdd = 'Deux universités privées maximum.';
            } else {
                $stmtChk = $db->prepare("SELECT id FROM enseignants WHERE email = ?");
                $stmtChk->execute([$email]);
                if ($stmtChk->fetch()) { $errAdd = 'Cet email est déjà utilisé.'; }
            }
        }

        if ($errAdd) {
            header("Location: admin.php?msg=" . urlencode('❌ ' . $errAdd) . '#enseignants');
            exit;
        }

        $db->beginTransaction();
        try {
            $hash = hashPassword($mdp);
            $stmtInsEns = $db->prepare("INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmtInsEns->execute([$nom, $prenom, $tel ?: null, $email, $urfId, $hash]);
            $newEnsId = $stmtInsEns->fetchColumn();

            $stU = $db->prepare("INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES (?, ?)");
            foreach ($univIds as $uid) { $stU->execute([$newEnsId, $uid]); }
            $stC = $db->prepare("INSERT INTO enseignant_classes (enseignant_id, classe) VALUES (?, ?)");
            foreach ($classesSel as $cl) { $stC->execute([$newEnsId, $cl]); }
            $stN = $db->prepare("INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES (?, ?)");
            foreach ($niveauxSel as $niv) { $stN->execute([$newEnsId, $niv]); }

            $db->commit();
            header("Location: admin.php?msg=" . urlencode('✅ Enseignant ajouté.') . '#enseignants');
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: admin.php?msg=" . urlencode('❌ Erreur lors de l\'ajout.') . '#enseignants');
        }
        exit;
    }

    if ($action === 'send_message') {
        $type    = $_POST['destinataire_type'] ?? 'tous_enseignants';
        $ensId   = intval($_POST['destinataire_enseignant_id'] ?? 0) ?: null;
        $univId  = intval($_POST['dest_universite_id'] ?? 0) ?: null;
        $niveau  = trim($_POST['dest_niveau'] ?? '') ?: null;
        $classe  = trim($_POST['dest_classe'] ?? '') ?: null;
        $contenu = trim($_POST['contenu'] ?? '');
        if ($contenu) {
            $db->prepare("INSERT INTO messages (expediteur_type, destinataire_type, destinataire_enseignant_id, dest_universite_id, dest_niveau, dest_classe, contenu)
                          VALUES('administration',?,?,?,?,?,?)")
               ->execute([$type, $ensId, $univId, $niveau, $classe, $contenu]);
        }
        header("Location: admin.php?msg=" . urlencode('✅ Message envoyé.') . '#messages');
        exit;
    }

    if ($action === 'delete_message') {
        $mid = intval($_POST['message_id']);
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$mid]);
        header("Location: admin.php?msg=" . urlencode('✅ Message supprimé.') . '#messages');
        exit;
    }
}

$successMsg = sanitize($_GET['msg'] ?? '');

function getMoyClass($m) {
    if ($m === null) return '';
    if ($m >= 16) return 'moy-excellent';
    if ($m >= 12) return 'moy-bien';
    if ($m >= 10) return 'moy-moyen';
    return 'moy-faible';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration – CampusLink</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .msg-ens-card {
            background: linear-gradient(135deg, rgba(0,154,68,.06), rgba(0,154,68,.02));
            border: 1px solid rgba(0,154,68,.2);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .msg-ens-card .msg-content { flex: 1; }
        .msg-ens-card .msg-sender { font-weight: 700; font-size: .9rem; }
        .msg-ens-card .msg-meta   { font-size: .78rem; color: var(--gris-med); margin-top: 3px; }
        .msg-ens-card .msg-text   { font-size: .88rem; margin-top: 6px; }
        .btn-del-msg-admin {
            background: rgba(220,38,38,.1);
            border: 1px solid rgba(220,38,38,.2);
            color: #dc2626;
            border-radius: 8px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: .8rem;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-del-msg-admin:hover { background: rgba(220,38,38,.2); }
        .college-manage-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: white;
            border-radius: 10px;
            border: 1px solid #E0E4EA;
            margin-bottom: 8px;
        }
        .college-manage-item .col-name { font-weight: 600; }
        .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn {
            padding: 8px 16px;
            border-radius: 20px;
            border: 1.5px solid #E0E4EA;
            background: white;
            cursor: pointer;
            font-size: .85rem;
            font-weight: 600;
            color: var(--gris-med);
            transition: all .2s;
        }
        .tab-btn.active { background: var(--orange); border-color: var(--orange); color: white; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .tab-nav-side { display: flex; flex-direction: column; gap: 6px; }
        .tab-nav-side .tab-btn-side {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: 10px; border: none;
            background: transparent; cursor: pointer; text-align: left;
            font-size: .9rem; font-weight: 600; color: var(--gris-med);
            width: 100%; font-family: 'DM Sans', sans-serif;
            transition: all .2s;
        }
        .tab-nav-side .tab-btn-side:hover { background: rgba(0,0,0,.04); }
        .tab-nav-side .tab-btn-side.active { background: var(--orange); color: white; }
        .tab-panel-admin { display: none; }
        .tab-panel-admin.active { display: block; }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="../index.php" class="logo">🎓 <span>CampusLink</span> CI</a>
    <button class="sidebar-toggle" type="button" aria-label="Afficher le menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <ul class="nav-links">
        <li><span style="color:#8892a4;font-size:.88rem;">🛡️ Administrateur</span></li>
        <li><a href="../pages/deconnexion.php" style="color:#f87171;">Déconnexion</a></li>
    </ul>
</nav>

<div class="dashboard">
    <aside class="sidebar">
        <div class="user-info">
            <div class="avatar" style="background:linear-gradient(135deg,#4F46E5,#e07000);">🛡️</div>
            <h3>Administrateur</h3>
            <p>CampusLink</p>
            <span class="badge">Super Admin</span>
        </div>
        <ul class="sidebar-menu">
            <li class="sidebar-section-label">Tableau de Bord</li>
            <li style="padding:0;">
                <div class="tab-nav-side">
                    <button class="tab-btn-side active" data-tab="mes-informations">🪪 Mes Informations</button>
                    <button class="tab-btn-side" data-tab="stats">📊 Statistiques</button>
                    <button class="tab-btn-side" data-tab="etudiants">🎒 Étudiants</button>
                    <button class="tab-btn-side" data-tab="enseignants">👨‍🏫 Enseignants</button>
                    <button class="tab-btn-side" data-tab="notes-section">📊 Notes & Moyennes</button>
                    <button class="tab-btn-side" data-tab="messages">💬 Messages</button>
                </div>
            </li>
            <li class="sidebar-section-label">Compte</li>
            <li><a href="../pages/deconnexion.php">🚪 Déconnexion</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <h2 style="font-family:'Sora',serif;font-size:1.8rem;margin-bottom:6px;">Tableau de Bord Admin 🛡️</h2>
        <p style="color:var(--gris-med);margin-bottom:28px;">Gestion complète de la plateforme CampusLink</p>

        <?php if ($successMsg): $isErr = strpos($successMsg, '❌') === 0; ?>
        <div class="alert <?= $isErr ? 'alert-error' : 'alert-success' ?>" style="margin-bottom:20px;"><?= $successMsg ?></div>
        <?php endif; ?>

        <!-- ── MES INFORMATIONS ── -->
        <div id="mes-informations" class="tab-panel-admin active" style="margin-bottom:36px;">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">🪪 Mes Informations</h3>
            <div class="table-card" style="padding:24px 28px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;">
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Rôle</div>
                        <div style="font-weight:700;">🛡️ Super Administrateur</div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">E-mail</div>
                        <div style="font-weight:700;"><?= sanitize($_SESSION['user_email'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Plateforme</div>
                        <div style="font-weight:700;">CampusLink</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── STATS ── -->
        <div id="stats" class="admin-stats tab-panel-admin">
            <div class="stat-card">
                <div class="stat-icon si-orange">🎒</div>
                <div><h4><?= $stats['total_etudiants'] ?></h4><p>Étudiants</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-vert">👨‍🏫</div>
                <div><h4><?= $stats['total_enseignants'] ?></h4><p>Enseignants</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-blue">🏫</div>
                <div><h4><?= $stats['total_universites'] ?></h4><p>Universités</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-purple">💬</div>
                <div><h4><?= $stats['total_messages'] ?></h4><p>Messages envoyés</p></div>
            </div>
        </div>

        <!-- ── ÉTUDIANTS ── -->
        <div id="etudiants" class="tab-panel-admin" style="margin-bottom:40px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                <h3 style="font-size:1.15rem;font-weight:700;">🎒 Gestion des Étudiants</h3>
                <button onclick="document.getElementById('modal-add-etudiant').classList.add('open')" class="btn-primary" style="padding:9px 20px;font-size:.88rem;">+ Ajouter un étudiant</button>
            </div>

            <form id="filter-form" method="GET" action="admin.php" class="filter-bar">
                <input type="hidden" name="tab" value="etudiants">
                <div class="filter-group">
                    <label>Université</label>
                    <select name="universite_id">
                        <option value="">Toutes les universités</option>
                        <?php foreach ($universites as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUniversite==$u['id']?'selected':'' ?>><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Niveau</label>
                    <select name="niveau">
                        <option value="">Tous les niveaux</option>
                        <?php foreach ($niveauxListe as $niv): ?>
                        <option value="<?= sanitize($niv) ?>" <?= $filterNiveau===$niv?'selected':'' ?>><?= sanitize($niv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="padding:9px 20px;font-size:.88rem;">Filtrer</button>
            </form>

            <div class="table-card">
                <div class="table-header">
                    <h3>Liste des Étudiants</h3>
                    <span class="table-info"><?= count($etudiants) ?> étudiant(s)</span>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Matricule</th><th>Email</th><th>Université</th><th>URF</th><th>Niveau</th><th>Classe</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($etudiants)): ?>
                        <tr><td colspan="10" style="text-align:center;color:var(--gris-med);padding:40px;">Aucun étudiant trouvé.</td></tr>
                        <?php else: ?>
                        <?php foreach ($etudiants as $i => $etu): ?>
                        <tr>
                            <td style="color:var(--gris-med);"><?= $i+1 ?></td>
                            <td><strong><?= sanitize($etu['nom']) ?></strong></td>
                            <td><?= sanitize($etu['prenom']) ?></td>
                            <td style="font-size:.82rem;color:var(--gris-med);"><?= sanitize($etu['matricule']) ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($etu['email']) ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($etu['universite_sigle']) ?></td>
                            <td><span class="college-badge"><?= sanitize($etu['urf_sigle']) ?></span></td>
                            <td style="font-size:.82rem;"><?= sanitize($etu['niveau']) ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($etu['classe']) ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn-edit" onclick='openEditModal(<?= json_encode($etu) ?>)'>✏️</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cet étudiant ?')">
                                        <input type="hidden" name="action" value="delete_etudiant">
                                        <input type="hidden" name="etudiant_id" value="<?= $etu['id'] ?>">
                                        <button type="submit" class="btn-delete">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- ── ENSEIGNANTS ── -->
        <div id="enseignants" class="tab-panel-admin" style="margin-bottom:40px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                <h3 style="font-size:1.15rem;font-weight:700;">👨‍🏫 Liste des Enseignants</h3>
                <button type="button" onclick="document.getElementById('modal-add-enseignant').classList.add('open')" class="btn-primary" style="padding:9px 20px;font-size:.88rem;">➕ Ajouter un enseignant</button>
            </div>
            <div class="filter-bar" style="margin-bottom:16px;">
                <div class="filter-group" style="flex:1;min-width:220px;">
                    <label>Rechercher</label>
                    <input type="text" id="search-enseignants" placeholder="Nom, prénom, email, matière...">
                </div>
            </div>
            <div class="table-card">
                <div style="overflow-x:auto;">
                <table class="data-table" id="table-enseignants">
                    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Matière (URF)</th><th>Université(s)</th><th>Classe(s)</th><th>Niveau(x)</th><th>Email</th></tr></thead>
                    <tbody>
                        <?php if (empty($enseignants)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--gris-med);padding:30px;">Aucun enseignant.</td></tr>
                        <?php else: ?>
                        <?php foreach ($enseignants as $i => $ens): ?>
                        <tr class="row-enseignant" data-search="<?= strtolower(sanitize($ens['nom'] . ' ' . $ens['prenom'] . ' ' . $ens['email'] . ' ' . $ens['urf_sigle'] . ' ' . ($ens['universites_sigles'] ?? ''))) ?>">
                            <td style="color:var(--gris-med);"><?= $i+1 ?></td>
                            <td><strong><?= sanitize($ens['nom']) ?></strong></td>
                            <td><?= sanitize($ens['prenom']) ?></td>
                            <td><span class="college-badge"><?= sanitize($ens['urf_sigle']) ?></span></td>
                            <td style="font-size:.82rem;"><?= sanitize($ens['universites_sigles'] ?? '—') ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($ens['classes_liste'] ?? '—') ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($ens['niveaux_liste'] ?? '—') ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($ens['email']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p id="no-result-enseignants" style="display:none;text-align:center;color:var(--gris-med);padding:24px;">Aucun résultat pour cette recherche.</p>
                </div>
            </div>
        </div>

        <!-- ── NOTES & MOYENNES ── -->
        <div id="notes-section" class="tab-panel-admin" style="margin-bottom:40px;">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">📊 Notes & Moyennes des Étudiants</h3>
            <?php if (empty($toutesNotes)): ?>
            <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                <div style="font-size:2.5rem;margin-bottom:10px;">📋</div>
                <p style="color:var(--gris-med);">Aucune note enregistrée pour le moment.</p>
            </div>
            <?php else: ?>
            <div class="filter-bar" style="margin-bottom:16px;">
                <div class="filter-group" style="flex:1;min-width:220px;">
                    <label>Rechercher</label>
                    <input type="text" id="search-notes" placeholder="Étudiant, matricule, matière, enseignant...">
                </div>
            </div>
            <div class="table-card">
                <div class="table-header">
                    <h3>Toutes les notes</h3>
                    <span class="table-info"><?= count($toutesNotes) ?> note(s) enregistrée(s)</span>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table" id="table-notes">
                    <thead>
                        <tr><th>#</th><th>Étudiant</th><th>Matricule</th><th>Matière</th><th>Enseignant</th><th>CC/20</th><th>Examen/20</th><th>Moyenne finale</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toutesNotes as $i => $n): $moy = $n['moyenne'] !== null ? floatval($n['moyenne']) : null; ?>
                        <tr class="row-note" data-search="<?= strtolower(sanitize($n['etu_prenom'] . ' ' . $n['etu_nom'] . ' ' . $n['matricule'] . ' ' . $n['matiere'] . ' ' . $n['ens_prenom'] . ' ' . $n['ens_nom'])) ?>">
                            <td style="color:var(--gris-med);"><?= $i+1 ?></td>
                            <td><strong><?= sanitize($n['etu_prenom'] . ' ' . $n['etu_nom']) ?></strong></td>
                            <td style="font-size:.82rem;color:var(--gris-med);"><?= sanitize($n['matricule']) ?></td>
                            <td><?= sanitize($n['matiere']) ?></td>
                            <td style="font-size:.82rem;"><?= sanitize($n['ens_prenom'] . ' ' . $n['ens_nom']) ?></td>
                            <td><strong><?= $n['note_cc'] !== null ? sanitize($n['note_cc']) . '/20' : '—' ?></strong></td>
                            <td><strong><?= $n['note_examen'] !== null ? sanitize($n['note_examen']) . '/20' : '—' ?></strong></td>
                            <td>
                                <?php if ($moy !== null): ?>
                                <span class="moyenne-badge <?= getMoyClass($moy) ?>"><?= number_format($moy, 2) ?>/20</span>
                                <?php else: ?><span style="color:#ccc">—</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="no-result-notes" style="display:none;text-align:center;color:var(--gris-med);padding:24px;">Aucun résultat pour cette recherche.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── MESSAGES ── -->
        <div id="messages" class="tab-panel-admin" style="margin-bottom:40px;">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">💬 Messagerie</h3>

            <div class="tab-nav">
                <button class="tab-btn active" data-tab="tab-envoyer">📤 Envoyer un message</button>
                <button class="tab-btn" data-tab="tab-historique">📋 Mes messages envoyés (<?= count($messagesAdmin) ?>)</button>
                <button class="tab-btn" data-tab="tab-reponses">📨 Messages des enseignants (<?= count($messagesEnseignants) ?>)</button>
            </div>

            <!-- Formulaire envoi -->
            <div class="tab-panel active" id="tab-envoyer">
                <div class="msg-form">
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="action" value="send_message">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Destinataires</label>
                                <select name="destinataire_type" id="dest-type" required onchange="toggleDestFields(this.value)">
                                    <option value="tous_enseignants">👨‍🏫 Tous les enseignants</option>
                                    <option value="enseignant_specifique">👤 Enseignant spécifique</option>
                                    <option value="tous_etudiants">🎓 Tous les étudiants</option>
                                    <option value="groupe_etudiants">🎒 Un groupe d'étudiants (université + niveau + classe)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Enseignant spécifique -->
                        <div class="form-group" id="field-ens-specifique" style="display:none;">
                            <label>Choisir l'enseignant</label>
                            <select name="destinataire_enseignant_id">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($enseignants as $ens): ?>
                                <option value="<?= $ens['id'] ?>"><?= sanitize($ens['prenom'] . ' ' . $ens['nom'] . ' – ' . $ens['urf_sigle'] . ' (' . ($ens['universites_sigles'] ?? '') . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Groupe d'étudiants -->
                        <div id="field-classe-group" style="display:none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Université</label>
                                    <select name="dest_universite_id">
                                        <option value="">-- Université --</option>
                                        <?php foreach ($universites as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Niveau</label>
                                    <select name="dest_niveau">
                                        <option value="">-- Niveau --</option>
                                        <?php foreach ($niveauxListe as $niv): ?>
                                        <option value="<?= sanitize($niv) ?>"><?= sanitize($niv) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Classe</label>
                                    <select name="dest_classe">
                                        <option value="">-- Classe --</option>
                                        <?php foreach ($classesAmphi as $cl): ?>
                                        <option value="<?= sanitize($cl) ?>"><?= sanitize($cl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Message</label>
                            <textarea name="contenu" placeholder="Rédigez votre message ici..." required style="width:100%;min-height:100px;padding:12px;border:1.5px solid #E0E4EA;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.92rem;resize:vertical;outline:none;box-sizing:border-box;"></textarea>
                        </div>
                        <button type="submit" class="btn-submit" style="width:auto;padding:11px 28px;">📤 Envoyer</button>
                    </form>
                </div>
            </div>

            <!-- Historique messages admin envoyés -->
            <div class="tab-panel" id="tab-historique">
                <?php if (empty($messagesAdmin)): ?>
                <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                    <p style="color:var(--gris-med);">Aucun message envoyé.</p>
                </div>
                <?php else: ?>
                <div class="table-card">
                    <table class="data-table">
                        <thead><tr><th>Date</th><th>Destinataire</th><th>Message</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($messagesAdmin as $msg): ?>
                            <tr>
                                <td style="font-size:.82rem;white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></td>
                                <td>
                                    <?php if ($msg['destinataire_type'] === 'groupe_etudiants'): ?>
                                        <span class="college-badge">🎒 <?= sanitize($msg['dest_universite_sigle'] ?? '') ?> · <?= sanitize($msg['dest_niveau'] ?? '') ?> · <?= sanitize($msg['dest_classe'] ?? '') ?></span>
                                    <?php elseif ($msg['destinataire_type'] === 'tous_etudiants'): ?>
                                        <span class="college-badge" style="background:rgba(0,154,68,.1);color:var(--vert);">🎓 Tous les étudiants</span>
                                    <?php elseif ($msg['destinataire_type'] === 'enseignant_specifique'): ?>
                                        <span class="college-badge" style="background:rgba(59,130,246,.1);color:#2563eb;">👤 <?= sanitize(($msg['ens_prenom'] ?? '') . ' ' . ($msg['ens_nom'] ?? '')) ?></span>
                                    <?php else: ?>
                                        <span class="college-badge" style="background:rgba(0,154,68,.1);color:var(--vert);">👨‍🏫 Tous les enseignants</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:.88rem;"><?= sanitize(substr($msg['contenu'], 0, 100)) ?><?= strlen($msg['contenu']) > 100 ? '...' : '' ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Supprimer ce message ?')">
                                        <input type="hidden" name="action" value="delete_message">
                                        <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                        <button type="submit" class="btn-delete">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Messages reçus des enseignants -->
            <div class="tab-panel" id="tab-reponses">
                <?php if (empty($messagesEnseignants)): ?>
                <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                    <div style="font-size:2.5rem;margin-bottom:10px;">📭</div>
                    <p style="color:var(--gris-med);">Aucun message reçu des enseignants.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($messagesEnseignants as $msg): ?>
                    <div class="msg-ens-card">
                        <div style="font-size:1.5rem;">👨‍🏫</div>
                        <div class="msg-content">
                            <div class="msg-sender"><?= sanitize($msg['ens_prenom'] . ' ' . $msg['ens_nom']) ?> <span style="font-weight:400;color:var(--gris-med);">· <?= sanitize($msg['urf_sigle']) ?> · <?= sanitize($msg['universites_sigles'] ?? '') ?></span></div>
                            <div class="msg-meta">📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                            <div class="msg-text"><?= nl2br(sanitize($msg['contenu'])) ?></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Supprimer ce message ?')">
                            <input type="hidden" name="action" value="delete_message">
                            <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                            <button type="submit" class="btn-del-msg-admin">🗑️ Supprimer</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- MODAL AJOUTER ÉTUDIANT -->
<!-- MODAL AJOUTER ENSEIGNANT -->
<div class="modal-overlay" id="modal-add-enseignant">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">➕ Ajouter un Enseignant</div>
        <form method="POST" action="admin.php" id="form-add-enseignant">
            <input type="hidden" name="action" value="add_enseignant">
            <div class="form-row">
                <div class="form-group"><label>Nom *</label><input type="text" name="nom" required></div>
                <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Téléphone</label><input type="tel" name="tel"></div>
                <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
            </div>
            <div class="form-group"><label>Mot de passe *</label><input type="password" name="mot_de_passe" minlength="8" required></div>
            <div class="form-group">
                <label>Matière (URF) *</label>
                <select name="urf_id" required>
                    <option value="">-- Choisir une matière --</option>
                    <?php foreach ($urfs as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Université *</label>
                <div style="display:flex;gap:16px;margin-bottom:10px;font-size:.88rem;">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="add-ens-univ-type" value="publique" checked> Publique (1 choix)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="add-ens-univ-type" value="privee"> Privée(s) (1 ou 2)
                    </label>
                </div>
                <div id="add-ens-univ-publique-group">
                    <?php foreach ($universites as $u): if ($u['type'] !== 'publique') continue; ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="radio" name="universites[]" value="<?= $u['id'] ?>" class="add-ens-univ-pub-radio"> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="add-ens-univ-privee-group" style="display:none;">
                    <?php foreach ($universites as $u): if ($u['type'] !== 'privee') continue; ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="checkbox" name="universites[]" value="<?= $u['id'] ?>" class="add-ens-univ-priv-check"> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Classe(s) — 1 ou 2 *</label>
                <div style="display:flex;gap:16px;">
                    <?php foreach ($classesAmphi as $cl): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;">
                        <input type="checkbox" name="classes[]" value="<?= sanitize($cl) ?>"> <?= sanitize($cl) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Niveau(x) — 1 ou 2 *</label>
                <div style="display:flex;flex-wrap:wrap;gap:12px;">
                    <?php foreach ($niveauxListe as $niv): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;">
                        <input type="checkbox" name="niveaux[]" value="<?= sanitize($niv) ?>" class="add-ens-niveau-check"> <?= sanitize($niv) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn-submit">➕ Ajouter l'enseignant</button>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-add-etudiant">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">➕ Ajouter un Étudiant</div>
        <form method="POST" action="admin.php">
            <input type="hidden" name="action" value="add_etudiant">
            <div class="form-row">
                <div class="form-group"><label>Nom</label><input type="text" name="nom" required></div>
                <div class="form-group"><label>Prénom</label><input type="text" name="prenom" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Téléphone</label><input type="tel" name="tel"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Âge</label><input type="number" name="age" min="14" max="60"></div>
                <div class="form-group"><label>Année académique</label><input type="text" name="annee_academique" placeholder="2024-2025" required></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Université</label>
                    <select name="universite_id" required>
                        <option value="">-- Université --</option>
                        <?php foreach ($universites as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>URF</label>
                    <select name="urf_id" required>
                        <option value="">-- URF --</option>
                        <?php foreach ($urfs as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Classe</label>
                    <select name="classe" required>
                        <option value="">-- Classe --</option>
                        <?php foreach ($classesAmphi as $cl): ?>
                        <option value="<?= sanitize($cl) ?>"><?= sanitize($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Niveau</label>
                    <select name="niveau" required>
                        <option value="">-- Niveau --</option>
                        <?php foreach ($niveauxListe as $niv): ?>
                        <option value="<?= sanitize($niv) ?>"><?= sanitize($niv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Matricule</label><input type="text" name="matricule" placeholder="ex : 23E000009" required></div>
                <div class="form-group"><label>Mot de passe</label><input type="text" name="mot_de_passe" placeholder="password123"></div>
            </div>
            <button type="submit" class="btn-submit">➕ Ajouter l'étudiant</button>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER ÉTUDIANT -->
<div class="modal-overlay" id="modal-edit-etudiant">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">✏️ Modifier l'Étudiant</div>
        <form method="POST" action="admin.php" id="edit-form">
            <input type="hidden" name="action" value="edit_etudiant">
            <input type="hidden" name="etudiant_id" id="edit-id">
            <div class="form-row">
                <div class="form-group"><label>Nom</label><input type="text" name="nom" id="edit-nom" required></div>
                <div class="form-group"><label>Prénom</label><input type="text" name="prenom" id="edit-prenom" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email" required></div>
                <div class="form-group"><label>Téléphone</label><input type="tel" name="tel" id="edit-tel"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Âge</label><input type="number" name="age" id="edit-age" min="14" max="60"></div>
                <div class="form-group"><label>Année académique</label><input type="text" name="annee_academique" id="edit-annee"></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Université</label>
                    <select name="universite_id" id="edit-universite">
                        <?php foreach ($universites as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>URF</label>
                    <select name="urf_id" id="edit-urf">
                        <?php foreach ($urfs as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Classe</label>
                    <select name="classe" id="edit-classe">
                        <?php foreach ($classesAmphi as $cl): ?>
                        <option value="<?= sanitize($cl) ?>"><?= sanitize($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Niveau</label>
                    <select name="niveau" id="edit-niveau">
                        <?php foreach ($niveauxListe as $niv): ?>
                        <option value="<?= sanitize($niv) ?>"><?= sanitize($niv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Matricule</label><input type="text" name="matricule" id="edit-matricule" required></div>
            <button type="submit" class="btn-submit vert">💾 Sauvegarder</button>
        </form>
    </div>
</div>

<script src="../js/app.js"></script>
<script>
// Tabs
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const nav = this.closest('.tab-nav');
        nav.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        // Chercher les panels frères
        const section = nav.closest('div[id]') || nav.parentElement;
        section.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        const panel = document.getElementById(this.dataset.tab);
        if (panel) panel.classList.add('active');
    });
});

// Ouvrir modal modification étudiant
function openEditModal(etu) {
    document.getElementById('edit-id').value = etu.id;
    document.getElementById('edit-nom').value = etu.nom;
    document.getElementById('edit-prenom').value = etu.prenom;
    document.getElementById('edit-email').value = etu.email;
    document.getElementById('edit-tel').value = etu.tel || '';
    document.getElementById('edit-age').value = etu.age || '';
    document.getElementById('edit-annee').value = etu.annee_academique;
    document.getElementById('edit-universite').value = etu.universite_id;
    document.getElementById('edit-urf').value = etu.urf_id;
    document.getElementById('edit-classe').value = etu.classe;
    document.getElementById('edit-niveau').value = etu.niveau;
    document.getElementById('edit-matricule').value = etu.matricule;
    document.getElementById('modal-edit-etudiant').classList.add('open');
    document.body.style.overflow = 'hidden';
}

// Champs dynamiques selon destinataire
function toggleDestFields(val) {
    document.getElementById('field-ens-specifique').style.display  = val === 'enseignant_specifique' ? 'block' : 'none';
    document.getElementById('field-classe-group').style.display     = val === 'groupe_etudiants' ? 'block' : 'none';
}

// Navigation principale (sidebar) — chaque bouton affiche sa propre section
document.querySelectorAll('.tab-btn-side').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn-side').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel-admin').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.tab).classList.add('active');
        history.replaceState(null, '', '#' + this.dataset.tab);
    });
});

// Rester sur le bon onglet après un rechargement (filtre, ajout, etc.)
(function restoreActiveTab() {
    const params = new URLSearchParams(window.location.search);
    const wanted = params.get('tab') || window.location.hash.replace('#', '');
    if (!wanted) return;
    const targetBtn = document.querySelector('.tab-btn-side[data-tab="' + wanted + '"]');
    const targetPanel = document.getElementById(wanted);
    if (targetBtn && targetPanel) {
        document.querySelectorAll('.tab-btn-side').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel-admin').forEach(p => p.classList.remove('active'));
        targetBtn.classList.add('active');
        targetPanel.classList.add('active');
    }
})();

// ── Recherche (client, sans rechargement) ──
function setupSearch(inputId, rowSelector, noResultId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll(rowSelector).forEach(row => {
            const match = row.dataset.search.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const noRes = document.getElementById(noResultId);
        if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    });
}
setupSearch('search-enseignants', '.row-enseignant', 'no-result-enseignants');
setupSearch('search-notes', '.row-note', 'no-result-notes');

// ── Modal Ajouter Enseignant : bascule Université publique / privée ──
const addEnsUnivTypeRadios = document.querySelectorAll('input[name="add-ens-univ-type"]');
const addEnsUnivPubGroup   = document.getElementById('add-ens-univ-publique-group');
const addEnsUnivPrivGroup  = document.getElementById('add-ens-univ-privee-group');

function toggleAddEnsUnivType() {
    const type = document.querySelector('input[name="add-ens-univ-type"]:checked')?.value;
    if (type === 'publique') {
        addEnsUnivPubGroup.style.display = 'block';
        addEnsUnivPrivGroup.style.display = 'none';
        document.querySelectorAll('.add-ens-univ-priv-check').forEach(c => c.checked = false);
    } else {
        addEnsUnivPubGroup.style.display = 'none';
        addEnsUnivPrivGroup.style.display = 'block';
        document.querySelectorAll('.add-ens-univ-pub-radio').forEach(r => r.checked = false);
    }
}
addEnsUnivTypeRadios.forEach(r => r.addEventListener('change', toggleAddEnsUnivType));

document.querySelectorAll('.add-ens-univ-priv-check').forEach(box => {
    box.addEventListener('change', function () {
        const checked = document.querySelectorAll('.add-ens-univ-priv-check:checked');
        if (checked.length > 2) { this.checked = false; showToast('2 universités privées maximum', 'error'); }
    });
});
document.querySelectorAll('.add-ens-niveau-check').forEach(box => {
    box.addEventListener('change', function () {
        const checked = document.querySelectorAll('.add-ens-niveau-check:checked');
        if (checked.length > 2) { this.checked = false; showToast('2 niveaux maximum', 'error'); }
    });
});
document.getElementById('form-add-enseignant')?.addEventListener('submit', function (e) {
    const type = document.querySelector('input[name="add-ens-univ-type"]:checked')?.value;
    const univChecked = type === 'publique'
        ? document.querySelectorAll('.add-ens-univ-pub-radio:checked')
        : document.querySelectorAll('.add-ens-univ-priv-check:checked');
    const classesChecked = document.querySelectorAll('#form-add-enseignant input[name="classes[]"]:checked');
    const niveauxChecked = document.querySelectorAll('.add-ens-niveau-check:checked');
    if (univChecked.length < 1) { e.preventDefault(); showToast('Choisissez au moins une université', 'error'); return; }
    if (classesChecked.length < 1) { e.preventDefault(); showToast('Choisissez au moins une classe', 'error'); return; }
    if (niveauxChecked.length < 1) { e.preventDefault(); showToast('Choisissez au moins un niveau', 'error'); return; }
});
</script>
</body>
</html>
