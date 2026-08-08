<?php
require_once '../includes/config.php';

if (!isLoggedIn() || $_SESSION['user_type'] !== 'enseignant') {
    redirect('../index.php');
}

$db = getDB();
$userId   = $_SESSION['user_id'];
$userName = $_SESSION['user_nom'];

// Informations complètes de l'enseignant + matière (URF)
$stmt = $db->prepare("
    SELECT e.*, f.nom as urf_nom, f.sigle as urf_sigle
    FROM enseignants e
    JOIN urfs f ON f.id = e.urf_id
    WHERE e.id = ?
");
$stmt->execute([$userId]);
$moi = $stmt->fetch();

if (!$moi) {
    redirect('../pages/deconnexion.php');
}

// Universités, classes et niveaux de l'enseignant
$stmtU = $db->prepare("
    SELECT u.id, u.nom, u.sigle, u.type
    FROM enseignant_universites eu
    JOIN universites u ON u.id = eu.universite_id
    WHERE eu.enseignant_id = ?
    ORDER BY u.nom
");
$stmtU->execute([$userId]);
$mesUniversites = $stmtU->fetchAll();

$stmtC = $db->prepare("SELECT classe FROM enseignant_classes WHERE enseignant_id = ? ORDER BY classe");
$stmtC->execute([$userId]);
$mesClasses = array_column($stmtC->fetchAll(), 'classe');

$stmtN = $db->prepare("SELECT niveau FROM enseignant_niveaux WHERE enseignant_id = ? ORDER BY niveau");
$stmtN->execute([$userId]);
$mesNiveaux = array_column($stmtN->fetchAll(), 'niveau');

$universitesLabel = implode(', ', array_map(fn($u) => $u['sigle'], $mesUniversites));
$classesLabel     = implode(' · ', $mesClasses);
$niveauxLabel     = implode(' · ', $mesNiveaux);

function getMoyClass($m) {
    if ($m === null) return '';
    if ($m >= 16) return 'moy-excellent';
    if ($m >= 12) return 'moy-bien';
    if ($m >= 10) return 'moy-moyen';
    return 'moy-faible';
}

// Construction des "classes" de l'enseignant = produit cartésien
// (université x niveau x classe amphi) parmi ses choix d'inscription,
// chacune listée séparément avec ses étudiants et leurs notes
$combos = [];
$totalEtudiants = 0;
foreach ($mesUniversites as $u) {
    foreach ($mesNiveaux as $niv) {
        foreach ($mesClasses as $cl) {
            $stmtEtu = $db->prepare("
                SELECT e.id, e.nom, e.prenom, e.matricule,
                       n.note_cc, n.note_examen, n.moyenne
                FROM etudiants e
                LEFT JOIN notes n ON n.etudiant_id = e.id AND n.enseignant_id = ?
                WHERE e.urf_id = ? AND e.universite_id = ? AND e.niveau = ? AND e.classe = ?
                ORDER BY e.nom, e.prenom
            ");
            $stmtEtu->execute([$userId, $moi['urf_id'], $u['id'], $niv, $cl]);
            $etus = $stmtEtu->fetchAll();
            $totalEtudiants += count($etus);
            $combos[] = [
                'key'        => 'combo-' . $u['id'] . '-' . preg_replace('/\s+/', '', $niv) . '-' . preg_replace('/\s+/', '', $cl),
                'universite' => $u,
                'niveau'     => $niv,
                'classe'     => $cl,
                'etudiants'  => $etus,
            ];
        }
    }
}

// Messages de l'administration destinés aux enseignants
$stmtMsg = $db->prepare("
    SELECT m.*
    FROM messages m
    WHERE m.expediteur_type = 'administration'
      AND (
          m.destinataire_type = 'tous_enseignants'
          OR (m.destinataire_type = 'enseignant_specifique' AND m.destinataire_enseignant_id = ?)
      )
    ORDER BY m.created_at DESC
    LIMIT 10
");
$stmtMsg->execute([$userId]);
$messagesAdmin = $stmtMsg->fetchAll();

// Historique des messages envoyés par cet enseignant à l'administration
$stmtMesMessages = $db->prepare("
    SELECT * FROM messages
    WHERE expediteur_type = 'enseignant' AND expediteur_id = ? AND destinataire_type = 'administration'
    ORDER BY created_at DESC
    LIMIT 20
");
$stmtMesMessages->execute([$userId]);
$mesMessages = $stmtMesMessages->fetchAll();

// Historique des messages envoyés par cet enseignant à ses étudiants
// (diffusion globale, groupe précis, ou étudiant spécifique)
$stmtMesClasseMessages = $db->prepare("
    SELECT m.*, e.nom as etu_nom, e.prenom as etu_prenom, u.sigle as dest_universite_sigle
    FROM messages m
    LEFT JOIN etudiants e ON e.id = m.destinataire_etudiant_id
    LEFT JOIN universites u ON u.id = m.dest_universite_id
    WHERE m.expediteur_type = 'enseignant' AND m.expediteur_id = ?
      AND m.destinataire_type IN ('classe_enseignant', 'groupe_etudiants', 'etudiant_specifique')
    ORDER BY m.created_at DESC
    LIMIT 30
");
$stmtMesClasseMessages->execute([$userId]);
$mesMessagesClasse = $stmtMesClasseMessages->fetchAll();

// Messages reçus des étudiants
$stmtMsgEtu = $db->prepare("
    SELECT m.*, e.nom as etu_nom, e.prenom as etu_prenom, e.matricule
    FROM messages m
    JOIN etudiants e ON e.id = m.expediteur_id
    WHERE m.expediteur_type = 'etudiant' AND m.destinataire_type = 'enseignant_specifique' AND m.destinataire_enseignant_id = ?
    ORDER BY m.created_at DESC
    LIMIT 30
");
$stmtMsgEtu->execute([$userId]);
$messagesRecusEtudiants = $stmtMsgEtu->fetchAll();

// Liste à plat de tous les étudiants de l'enseignant (pour le sélecteur "étudiant spécifique")
$tousMesEtudiants = [];
foreach ($combos as $c) {
    foreach ($c['etudiants'] as $etu) {
        $tousMesEtudiants[] = [
            'id' => $etu['id'], 'nom' => $etu['nom'], 'prenom' => $etu['prenom'], 'matricule' => $etu['matricule'],
            'label' => $etu['prenom'] . ' ' . $etu['nom'] . ' (' . $c['universite']['sigle'] . ' · ' . $c['niveau'] . ' · ' . $c['classe'] . ')',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Enseignant – CampusLink</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
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
        .tab-nav-side .tab-btn-side.active { background: #0EA5E9; color: white; }
        .tab-panel-ens { display: none; }
        .tab-panel-ens.active { display: block; }
        .combo-tab-nav { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .combo-tab-btn {
            padding: 8px 16px; border-radius: 20px; border: 1.5px solid #E0E4EA;
            background: white; cursor: pointer; font-size: .85rem; font-weight: 600;
            color: var(--gris-med); transition: all .2s;
        }
        .combo-tab-btn.active { background: #0EA5E9; border-color: #0EA5E9; color: white; }
        .combo-panel { display: none; }
        .combo-panel.active { display: block; }
        .msg-enseignant {
            background: linear-gradient(135deg, rgba(0,154,68,.08), rgba(0,154,68,.03));
            border: 1px solid rgba(0,154,68,.2);
            border-radius: 14px; padding: 16px 18px; margin-bottom: 12px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .msg-enseignant .msg-content { flex: 1; }
        .msg-enseignant .msg-date { font-size: .78rem; color: var(--gris-med); margin-top: 4px; }
        .msg-enseignant .btn-del-msg {
            background: rgba(220,38,38,.1); border: 1px solid rgba(220,38,38,.2); color: #dc2626;
            border-radius: 8px; padding: 5px 10px; cursor: pointer; font-size: .8rem;
            white-space: nowrap; flex-shrink: 0;
        }
        .msg-enseignant .btn-del-msg:hover { background: rgba(220,38,38,.2); }
        .compose-box { background: white; border-radius: 14px; padding: 20px; box-shadow: var(--shadow); margin-bottom: 24px; }
        .compose-box textarea {
            width: 100%; min-height: 90px; padding: 12px; border: 1.5px solid #E0E4EA;
            border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: .92rem;
            resize: vertical; outline: none; margin-bottom: 10px; box-sizing: border-box;
        }
        .compose-box textarea:focus { border-color: var(--orange); }
        .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn {
            padding: 8px 18px; border-radius: 20px; border: 1.5px solid #E0E4EA; background: white;
            cursor: pointer; font-size: .88rem; font-weight: 600; color: var(--gris-med); transition: all .2s;
        }
        .tab-btn.active { background: var(--orange); border-color: var(--orange); color: white; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="../index.php" class="logo">🎓 <span>CampusLink</span> CI</a>
    <ul class="nav-links">
        <li><span style="color:#8892a4;font-size:.88rem;">👨‍🏫 <?= sanitize($userName) ?></span></li>
        <li><span style="color:#8892a4;font-size:.85rem;"><?= sanitize($moi['urf_sigle']) ?> · <?= sanitize($classesLabel) ?></span></li>
        <li><a href="../pages/deconnexion.php" style="color:#f87171;">Déconnexion</a></li>
    </ul>
</nav>

<div class="dashboard">
    <aside class="sidebar">
        <div class="user-info">
            <div class="avatar" style="background:linear-gradient(135deg,#0EA5E9,#0284C7);">
                <?= strtoupper(substr($userName, 0, 1)) ?>
            </div>
            <h3><?= sanitize($userName) ?></h3>
            <p><?= sanitize($universitesLabel) ?></p>
            <span class="badge" style="background:rgba(0,154,68,.2);color:#0EA5E9;">
                👨‍🏫 <?= sanitize($moi['urf_sigle']) ?> · <?= sanitize($niveauxLabel) ?>
            </span>
        </div>
        <ul class="sidebar-menu">
            <li class="sidebar-section-label">Navigation</li>
            <li style="padding:0;">
                <div class="tab-nav-side">
                    <button class="tab-btn-side active" data-tab="panel-informations">🪪 Mes Informations</button>
                    <button class="tab-btn-side" data-tab="panel-info-classe">📢 Passer information aux étudiants</button>
                    <button class="tab-btn-side" data-tab="panel-mes-classes">📋 Mes Classes</button>
                    <button class="tab-btn-side" data-tab="panel-messagerie">💬 Messagerie Administration</button>
                </div>
            </li>
            <li class="sidebar-section-label">Compte</li>
            <li><a href="../pages/deconnexion.php">🚪 Déconnexion</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <h2 style="font-family:'Sora',serif;font-size:1.8rem;margin-bottom:6px;">
            Espace Enseignant 👨‍🏫
        </h2>
        <p style="color:var(--gris-med);margin-bottom:28px;font-size:.9rem;">
            Matière : <strong><?= sanitize($moi['urf_nom']) ?></strong> ·
            <?= sanitize($universitesLabel) ?> ·
            <?= sanitize($niveauxLabel) ?> ·
            <?= sanitize($classesLabel) ?> ·
            <?= $totalEtudiants ?> élève(s)
        </p>

        <!-- ── PANEL MES INFORMATIONS ── -->
        <div id="panel-informations" class="tab-panel-ens active">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">🪪 Mes Informations</h3>
            <div class="table-card" style="padding:24px 28px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;">
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Nom complet</div>
                        <div style="font-weight:700;"><?= sanitize($moi['prenom'] . ' ' . $moi['nom']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">E-mail</div>
                        <div style="font-weight:700;"><?= sanitize($moi['email']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Téléphone</div>
                        <div style="font-weight:700;"><?= $moi['tel'] ? sanitize($moi['tel']) : '—' ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Matière (URF)</div>
                        <div style="font-weight:700;"><?= sanitize($moi['urf_nom']) ?> (<?= sanitize($moi['urf_sigle']) ?>)</div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Université(s)</div>
                        <div style="font-weight:700;">
                            <?php foreach ($mesUniversites as $u): ?>
                            <div><?= sanitize($u['nom']) ?> (<?= sanitize($u['sigle']) ?>)</div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Classe(s)</div>
                        <div style="font-weight:700;"><?= sanitize($classesLabel) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Niveau(x)</div>
                        <div style="font-weight:700;"><?= sanitize($niveauxLabel) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── PANEL PASSER INFORMATION AUX ÉTUDIANTS ── -->
        <div id="panel-info-classe" class="tab-panel-ens">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">📢 Passer information aux étudiants de mes classes</h3>

            <div class="tab-nav">
                <button class="tab-btn active" data-tab="tab-composer">✍️ Écrire</button>
                <button class="tab-btn" data-tab="tab-envoyes">📤 Envoyés (<?= count($mesMessagesClasse) ?>)</button>
                <button class="tab-btn" data-tab="tab-recus-etu">📥 Reçus des étudiants (<?= count($messagesRecusEtudiants) ?>)</button>
            </div>

            <!-- Composer -->
            <div class="tab-panel active" id="tab-composer">
                <div class="compose-box">
                    <div class="form-group">
                        <label>Destinataire</label>
                        <select id="msg-classe-mode">
                            <option value="tous">🌍 Tous mes étudiants (toutes mes classes)</option>
                            <option value="groupe">🎯 Un groupe précis (université + niveau + classe)</option>
                            <option value="etudiant">👤 Un étudiant spécifique</option>
                        </select>
                    </div>

                    <div id="msg-classe-groupe-fields" style="display:none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Université</label>
                                <select id="msg-classe-univ">
                                    <?php foreach ($mesUniversites as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= sanitize($u['sigle']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Niveau</label>
                                <select id="msg-classe-niveau">
                                    <?php foreach ($mesNiveaux as $niv): ?>
                                    <option value="<?= sanitize($niv) ?>"><?= sanitize($niv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Classe</label>
                                <select id="msg-classe-classe">
                                    <?php foreach ($mesClasses as $cl): ?>
                                    <option value="<?= sanitize($cl) ?>"><?= sanitize($cl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="msg-classe-etudiant-fields" style="display:none;">
                        <div class="form-group">
                            <label>Étudiant</label>
                            <select id="msg-classe-etudiant-id">
                                <option value="">-- Choisir un étudiant --</option>
                                <?php foreach ($tousMesEtudiants as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= sanitize($e['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <textarea id="msg-classe-contenu" placeholder="Rédigez votre information ici..."></textarea>
                    <button class="btn-submit" id="btn-send-msg-classe" style="width:auto;padding:10px 24px;background:#0EA5E9;">📤 Envoyer</button>
                </div>
            </div>

            <!-- Messages envoyés -->
            <div class="tab-panel" id="tab-envoyes">
                <?php if (empty($mesMessagesClasse)): ?>
                <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                    <p style="color:var(--gris-med);">Vous n'avez encore envoyé aucune information à vos étudiants.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($mesMessagesClasse as $msg): ?>
                    <div class="msg-enseignant" data-msg-id="<?= $msg['id'] ?>">
                        <div style="font-size:1.5rem;">
                            <?= $msg['destinataire_type'] === 'etudiant_specifique' ? '👤' : ($msg['destinataire_type'] === 'groupe_etudiants' ? '🎯' : '🌍') ?>
                        </div>
                        <div class="msg-content">
                            <p style="font-size:.92rem;"><?= nl2br(sanitize($msg['contenu'])) ?></p>
                            <div class="msg-date">
                                📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?> ·
                                <?php if ($msg['destinataire_type'] === 'etudiant_specifique'): ?>
                                    À <?= sanitize($msg['etu_prenom'] . ' ' . $msg['etu_nom']) ?>
                                <?php elseif ($msg['destinataire_type'] === 'groupe_etudiants'): ?>
                                    Groupe : <?= sanitize($msg['dest_universite_sigle'] ?? '') ?> · <?= sanitize($msg['dest_niveau'] ?? '') ?> · <?= sanitize($msg['dest_classe'] ?? '') ?>
                                <?php else: ?>
                                    Tous mes étudiants
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn-del-msg" data-msg-id="<?= $msg['id'] ?>">🗑️ Supprimer</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Messages reçus des étudiants -->
            <div class="tab-panel" id="tab-recus-etu">
                <?php if (empty($messagesRecusEtudiants)): ?>
                <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                    <p style="color:var(--gris-med);">Aucun message reçu de vos étudiants pour le moment.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($messagesRecusEtudiants as $msg): ?>
                    <div class="msg-enseignant" data-msg-id="<?= $msg['id'] ?>" style="background:linear-gradient(135deg, rgba(247,127,0,.06), rgba(247,127,0,.02));border-color:rgba(247,127,0,.2);">
                        <div style="font-size:1.5rem;">🎒</div>
                        <div class="msg-content">
                            <p style="font-size:.92rem;font-weight:700;margin-bottom:2px;"><?= sanitize($msg['etu_prenom'] . ' ' . $msg['etu_nom']) ?> <span style="font-weight:400;color:var(--gris-med);font-size:.8rem;">· <?= sanitize($msg['matricule']) ?></span></p>
                            <p style="font-size:.92rem;"><?= nl2br(sanitize($msg['contenu'])) ?></p>
                            <div class="msg-date">📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                        </div>
                        <button class="btn-del-msg" data-msg-id="<?= $msg['id'] ?>">🗑️ Supprimer</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── PANEL MES CLASSES ── -->
        <div id="panel-mes-classes" class="tab-panel-ens">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">📋 Mes Classes</h3>

            <?php if (empty($combos)): ?>
            <div style="background:white;border-radius:14px;padding:40px;text-align:center;box-shadow:var(--shadow);">
                <div style="font-size:3rem;margin-bottom:12px;">🎒</div>
                <p style="color:var(--gris-med);">Aucune classe configurée.</p>
            </div>
            <?php else: ?>
            <div class="combo-tab-nav">
                <?php foreach ($combos as $i => $c): ?>
                <button class="combo-tab-btn <?= $i === 0 ? 'active' : '' ?>" data-combo="<?= $c['key'] ?>">
                    <?= sanitize($c['universite']['sigle']) ?> · <?= sanitize($c['niveau']) ?> · <?= sanitize($c['classe']) ?> (<?= count($c['etudiants']) ?>)
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($combos as $i => $c): ?>
            <div class="combo-panel <?= $i === 0 ? 'active' : '' ?>" id="<?= $c['key'] ?>">
                <div class="table-card">
                    <div class="table-header">
                        <h3><?= sanitize($c['universite']['nom']) ?> · <?= sanitize($c['niveau']) ?> · <?= sanitize($c['classe']) ?></h3>
                        <span class="table-info"><?= count($c['etudiants']) ?> élève(s) · Matière : <?= sanitize($moi['urf_sigle']) ?></span>
                    </div>
                    <?php if (empty($c['etudiants'])): ?>
                    <div style="padding:40px;text-align:center;">
                        <div style="font-size:2.5rem;margin-bottom:10px;">🎒</div>
                        <p style="color:var(--gris-med);">Aucun étudiant dans ce groupe pour le moment.</p>
                    </div>
                    <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th><th>Nom</th><th>Prénom</th><th>Matricule</th>
                                <th>Note CC /20</th><th>Note Examen /20</th><th>Moyenne finale</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($c['etudiants'] as $j => $etu):
                                $moy = $etu['moyenne'] !== null ? floatval($etu['moyenne']) : null;
                            ?>
                            <tr data-etudiant-id="<?= $etu['id'] ?>">
                                <td style="color:var(--gris-med);"><?= $j + 1 ?></td>
                                <td><strong><?= sanitize($etu['nom']) ?></strong></td>
                                <td><?= sanitize($etu['prenom']) ?></td>
                                <td style="font-size:.82rem;color:var(--gris-med);"><?= sanitize($etu['matricule']) ?></td>
                                <td>
                                    <input type="number" class="note-cc-input"
                                        value="<?= $etu['note_cc'] !== null ? sanitize($etu['note_cc']) : '' ?>"
                                        min="0" max="20" step="0.25" placeholder="—"
                                        style="width:75px;padding:6px 8px;border:1.5px solid #E0E4EA;border-radius:7px;font-size:.9rem;">
                                </td>
                                <td>
                                    <input type="number" class="note-examen-input"
                                        value="<?= $etu['note_examen'] !== null ? sanitize($etu['note_examen']) : '' ?>"
                                        min="0" max="20" step="0.25" placeholder="—"
                                        style="width:75px;padding:6px 8px;border:1.5px solid #E0E4EA;border-radius:7px;font-size:.9rem;">
                                </td>
                                <td>
                                    <span class="moyenne-badge <?= getMoyClass($moy) ?> moyenne-auto">
                                        <?= $moy !== null ? number_format($moy, 2) . '/20' : '—' ?>
                                    </span>
                                </td>
                                <td><button class="btn-save-cc-examen">💾 Sauvegarder</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── PANEL MESSAGERIE ADMINISTRATION ── -->
        <div id="panel-messagerie" class="tab-panel-ens">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">💬 Messagerie Administration</h3>

            <div class="tab-nav">
                <button class="tab-btn active" data-tab="tab-admin">📢 Messages de l'Admin (<?= count($messagesAdmin) ?>)</button>
                <button class="tab-btn" data-tab="tab-mes-msg">📤 Mes Messages (<?= count($mesMessages) ?>)</button>
                <button class="tab-btn" data-tab="tab-ecrire">✍️ Écrire à l'Admin</button>
            </div>

            <div class="tab-panel active" id="tab-admin">
                <?php if (empty($messagesAdmin)): ?>
                <div class="message-box">
                    <div class="msg-icon">📭</div>
                    <div class="msg-body"><h4>Aucun message</h4><p>L'administration n'a pas encore envoyé de message.</p></div>
                </div>
                <?php else: ?>
                    <?php foreach ($messagesAdmin as $msg): ?>
                    <div class="message-box" style="margin-bottom:12px;">
                        <div class="msg-icon">📢</div>
                        <div class="msg-body">
                            <h4>Message de l'Administration</h4>
                            <p><?= nl2br(sanitize($msg['contenu'])) ?></p>
                            <div class="msg-meta">📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="tab-panel" id="tab-mes-msg">
                <?php if (empty($mesMessages)): ?>
                <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                    <div style="font-size:2.5rem;margin-bottom:10px;">📭</div>
                    <p style="color:var(--gris-med);">Vous n'avez encore envoyé aucun message.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($mesMessages as $msg): ?>
                    <div class="msg-enseignant" data-msg-id="<?= $msg['id'] ?>">
                        <div style="font-size:1.5rem;">📤</div>
                        <div class="msg-content">
                            <p style="font-size:.92rem;"><?= nl2br(sanitize($msg['contenu'])) ?></p>
                            <div class="msg-date">📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?> · Envoyé à l'administration</div>
                        </div>
                        <button class="btn-del-msg" data-msg-id="<?= $msg['id'] ?>">🗑️ Supprimer</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="tab-panel" id="tab-ecrire">
                <div class="compose-box">
                    <h4 style="margin-bottom:12px;font-size:.95rem;">✍️ Envoyer un message à l'administration</h4>
                    <textarea id="msg-contenu" placeholder="Rédigez votre message ici..."></textarea>
                    <button class="btn-submit" id="btn-send-msg" style="width:auto;padding:10px 24px;">📤 Envoyer</button>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../js/app.js"></script>
<script>
// Navigation principale (sidebar)
document.querySelectorAll('.tab-btn-side').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn-side').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel-ens').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.tab).classList.add('active');
    });
});

// Sous-onglets (scoping par groupe .tab-nav le plus proche pour éviter les conflits entre panneaux)
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const scope = this.closest('.tab-panel-ens') || document;
        scope.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        scope.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.tab).classList.add('active');
    });
});

// Sous-onglets "Mes Classes"
document.querySelectorAll('.combo-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.combo-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.combo-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.combo).classList.add('active');
    });
});

// Sauvegarde note CC + Examen (moyenne calculée automatiquement côté serveur)
document.querySelectorAll('.btn-save-cc-examen').forEach(btn => {
    btn.addEventListener('click', async function () {
        const row = this.closest('tr');
        const etudiantId = row.dataset.etudiantId;
        const cc = row.querySelector('.note-cc-input').value;
        const examen = row.querySelector('.note-examen-input').value;
        if (cc === '' && examen === '') { showToast('Veuillez saisir au moins une note', 'error'); return; }
        for (const v of [cc, examen]) {
            if (v !== '' && (v < 0 || v > 20)) { showToast('Les notes doivent être entre 0 et 20', 'error'); return; }
        }
        this.textContent = '...';
        try {
            const fd = new FormData();
            fd.append('etudiant_id', etudiantId);
            fd.append('note_cc', cc);
            fd.append('note_examen', examen);
            const res = await fetch('../api/save_note.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const badge = row.querySelector('.moyenne-auto');
                if (data.moyenne !== null) {
                    badge.textContent = Number(data.moyenne).toFixed(2) + '/20';
                    badge.className = 'moyenne-badge moyenne-auto ' +
                        (data.moyenne >= 16 ? 'moy-excellent' : data.moyenne >= 12 ? 'moy-bien' : data.moyenne >= 10 ? 'moy-moyen' : 'moy-faible');
                } else {
                    badge.textContent = '—';
                }
                showToast('Notes sauvegardées ✓', 'success');
            } else {
                showToast(data.message || 'Erreur', 'error');
            }
        } catch (e) { showToast('Erreur réseau', 'error'); }
        this.textContent = '💾 Sauvegarder';
    });
});

// Envoyer message à l'admin
document.getElementById('btn-send-msg').addEventListener('click', async function() {
    const contenu = document.getElementById('msg-contenu').value.trim();
    if (!contenu) { showToast('Le message ne peut pas être vide', 'error'); return; }
    this.textContent = '...';
    try {
        const fd = new FormData();
        fd.append('contenu', contenu);
        const res = await fetch('../api/send_message_enseignant.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✅ Message envoyé à l\'administration', 'success');
            document.getElementById('msg-contenu').value = '';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Erreur', 'error');
        }
    } catch(e) { showToast('Erreur réseau', 'error'); }
    this.textContent = '📤 Envoyer';
});

// Envoyer une information aux étudiants de mes classes
// Afficher les champs selon le mode de destinataire choisi
document.getElementById('msg-classe-mode').addEventListener('change', function () {
    document.getElementById('msg-classe-groupe-fields').style.display = this.value === 'groupe' ? 'block' : 'none';
    document.getElementById('msg-classe-etudiant-fields').style.display = this.value === 'etudiant' ? 'block' : 'none';
});

document.getElementById('btn-send-msg-classe').addEventListener('click', async function() {
    const contenu = document.getElementById('msg-classe-contenu').value.trim();
    const mode = document.getElementById('msg-classe-mode').value;
    if (!contenu) { showToast('Le message ne peut pas être vide', 'error'); return; }
    if (mode === 'etudiant' && !document.getElementById('msg-classe-etudiant-id').value) {
        showToast('Veuillez choisir un étudiant', 'error'); return;
    }
    this.textContent = '...';
    try {
        const fd = new FormData();
        fd.append('contenu', contenu);
        fd.append('mode', mode);
        if (mode === 'groupe') {
            fd.append('dest_universite_id', document.getElementById('msg-classe-univ').value);
            fd.append('dest_niveau', document.getElementById('msg-classe-niveau').value);
            fd.append('dest_classe', document.getElementById('msg-classe-classe').value);
        } else if (mode === 'etudiant') {
            fd.append('destinataire_etudiant_id', document.getElementById('msg-classe-etudiant-id').value);
        }
        const res = await fetch('../api/send_message_classe.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✅ Information envoyée', 'success');
            document.getElementById('msg-classe-contenu').value = '';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Erreur', 'error');
        }
    } catch(e) { showToast('Erreur réseau', 'error'); }
    this.textContent = '📤 Envoyer';
});

// Supprimer un message envoyé (admin ou classe)
document.querySelectorAll('.btn-del-msg').forEach(btn => {
    btn.addEventListener('click', async function() {
        if (!confirm('Supprimer ce message ?')) return;
        const msgId = this.dataset.msgId;
        const fd = new FormData();
        fd.append('message_id', msgId);
        try {
            const res = await fetch('../api/delete_message_enseignant.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.closest('.msg-enseignant').remove();
                showToast('Message supprimé', 'success');
            } else {
                showToast(data.message || 'Erreur', 'error');
            }
        } catch(e) { showToast('Erreur réseau', 'error'); }
    });
});
</script>
</body>
</html>
