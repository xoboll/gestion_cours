<?php
require_once '../includes/config.php';

if (!isLoggedIn() || $_SESSION['user_type'] !== 'etudiant') {
    redirect('../index.php');
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Informations complètes de l'étudiant connecté
$stmt = $db->prepare("
    SELECT e.*, u.nom as universite_nom, u.sigle as universite_sigle, u.type as universite_type,
           f.nom as urf_nom, f.sigle as urf_sigle
    FROM etudiants e
    JOIN universites u ON u.id = e.universite_id
    JOIN urfs f ON f.id = e.urf_id
    WHERE e.id = ?
");
$stmt->execute([$userId]);
$moi = $stmt->fetch();

if (!$moi) {
    redirect('../pages/deconnexion.php');
}

$userName = $moi['prenom'] . ' ' . $moi['nom'];

// Camarades de la même classe (même université, URF, niveau et classe/amphi)
$stmtClasse = $db->prepare("
    SELECT id, nom, prenom, matricule, email, tel
    FROM etudiants
    WHERE universite_id = ? AND urf_id = ? AND niveau = ? AND classe = ? AND id != ?
    ORDER BY nom, prenom
");
$stmtClasse->execute([$moi['universite_id'], $moi['urf_id'], $moi['niveau'], $moi['classe'], $userId]);
$camarades = $stmtClasse->fetchAll();

// Messages destinés à cet étudiant : de l'administration (tous ou groupe ciblé),
// ou d'un enseignant (diffusion à toute sa classe, groupe précis, ou message direct)
$stmtMsg = $db->prepare("
    SELECT m.*, 'administration' as source, NULL as ens_nom, NULL as ens_prenom
    FROM messages m
    WHERE m.expediteur_type = 'administration'
      AND (
          m.destinataire_type = 'tous_etudiants'
          OR (m.destinataire_type = 'groupe_etudiants'
              AND m.dest_universite_id = ? AND m.dest_niveau = ? AND m.dest_classe = ?)
      )
    UNION ALL
    SELECT m.*, 'enseignant' as source, ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM messages m
    JOIN enseignants ens ON ens.id = m.expediteur_id
    WHERE m.expediteur_type = 'enseignant'
      AND m.destinataire_type = 'classe_enseignant'
      AND ens.urf_id = ?
      AND EXISTS (SELECT 1 FROM enseignant_universites eu WHERE eu.enseignant_id = ens.id AND eu.universite_id = ?)
      AND EXISTS (SELECT 1 FROM enseignant_niveaux en WHERE en.enseignant_id = ens.id AND en.niveau = ?)
      AND EXISTS (SELECT 1 FROM enseignant_classes ec WHERE ec.enseignant_id = ens.id AND ec.classe = ?)
    UNION ALL
    SELECT m.*, 'enseignant' as source, ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM messages m
    JOIN enseignants ens ON ens.id = m.expediteur_id
    WHERE m.expediteur_type = 'enseignant'
      AND m.destinataire_type = 'groupe_etudiants'
      AND ens.urf_id = ?
      AND m.dest_universite_id = ? AND m.dest_niveau = ? AND m.dest_classe = ?
    UNION ALL
    SELECT m.*, 'enseignant' as source, ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM messages m
    JOIN enseignants ens ON ens.id = m.expediteur_id
    WHERE m.expediteur_type = 'enseignant'
      AND m.destinataire_type = 'etudiant_specifique'
      AND m.destinataire_etudiant_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$stmtMsg->execute([
    $moi['universite_id'], $moi['niveau'], $moi['classe'],
    $moi['urf_id'], $moi['universite_id'], $moi['niveau'], $moi['classe'],
    $moi['urf_id'], $moi['universite_id'], $moi['niveau'], $moi['classe'],
    $userId
]);
$messages = $stmtMsg->fetchAll();

// Mes enseignants (pour pouvoir leur écrire) : ceux qui partagent
// mon université, mon URF, mon niveau et ma classe
$stmtMesEns = $db->prepare("
    SELECT ens.id, ens.nom, ens.prenom, f.sigle as urf_sigle
    FROM enseignants ens
    JOIN urfs f ON f.id = ens.urf_id
    JOIN enseignant_universites eu ON eu.enseignant_id = ens.id AND eu.universite_id = ?
    JOIN enseignant_niveaux en ON en.enseignant_id = ens.id AND en.niveau = ?
    JOIN enseignant_classes ec ON ec.enseignant_id = ens.id AND ec.classe = ?
    WHERE ens.urf_id = ?
    ORDER BY ens.nom
");
$stmtMesEns->execute([$moi['universite_id'], $moi['niveau'], $moi['classe'], $moi['urf_id']]);
$mesEnseignants = $stmtMesEns->fetchAll();

// Messages envoyés par l'étudiant à ses enseignants
$stmtMesEnvoyes = $db->prepare("
    SELECT m.*, ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM messages m
    JOIN enseignants ens ON ens.id = m.destinataire_enseignant_id
    WHERE m.expediteur_type = 'etudiant' AND m.expediteur_id = ? AND m.destinataire_type = 'enseignant_specifique'
    ORDER BY m.created_at DESC
    LIMIT 20
");
$stmtMesEnvoyes->execute([$userId]);
$mesMessagesEnvoyes = $stmtMesEnvoyes->fetchAll();

// Mes notes et moyennes (lecture seule — saisies par les enseignants)
$stmtNotes = $db->prepare("
    SELECT n.matiere, n.note_cc, n.note_examen, n.moyenne, n.updated_at,
           ens.nom as ens_nom, ens.prenom as ens_prenom
    FROM notes n
    JOIN enseignants ens ON ens.id = n.enseignant_id
    WHERE n.etudiant_id = ?
    ORDER BY n.matiere
");
$stmtNotes->execute([$userId]);
$mesNotes = $stmtNotes->fetchAll();

function getMoyClassEtu($m) {
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
    <title>Espace Étudiant – CampusLink</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .tab-nav-side { display: flex; flex-direction: column; gap: 6px; }
        .tab-nav-side .tab-btn-side {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: 10px; border: none;
            background: transparent; cursor: pointer; text-align: left;
            font-size: .92rem; font-weight: 600; color: var(--gris-med);
            width: 100%; font-family: 'DM Sans', sans-serif;
            transition: all .2s;
        }
        .tab-nav-side .tab-btn-side:hover { background: rgba(0,0,0,.04); }
        .tab-nav-side .tab-btn-side.active { background: var(--orange); color: white; }
        .tab-panel-etu { display: none; }
        .tab-panel-etu.active { display: block; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="../index.php" class="logo">🎓 <span>CampusLink</span> CI</a>
    <button class="sidebar-toggle" type="button" aria-label="Afficher le menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <ul class="nav-links">
        <li><span style="color:#8892a4;font-size:.88rem;">👤 <?= sanitize($userName) ?></span></li>
        <li><span style="color:#8892a4;font-size:.85rem;"><?= sanitize($moi['niveau']) ?> · <?= sanitize($moi['classe']) ?></span></li>
        <li><a href="../pages/deconnexion.php" style="color:#f87171;">Déconnexion</a></li>
    </ul>
</nav>

<div class="dashboard">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="user-info">
            <div class="avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
            <h3><?= sanitize($userName) ?></h3>
            <p><?= sanitize($moi['universite_sigle']) ?> · <?= sanitize($moi['urf_sigle']) ?></p>
            <span class="badge">🎒 <?= sanitize($moi['niveau']) ?> · <?= sanitize($moi['classe']) ?></span>
        </div>
        <ul class="sidebar-menu">
            <li class="sidebar-section-label">Navigation</li>
            <li style="padding:0;">
                <div class="tab-nav-side">
                    <button class="tab-btn-side active" data-tab="panel-informations">🪪 Informations</button>
                    <button class="tab-btn-side" data-tab="panel-notes">📊 Notes</button>
                    <button class="tab-btn-side" data-tab="panel-messages">💬 Messages</button>
                    <button class="tab-btn-side" data-tab="panel-classe">👥 Classe</button>
                    <button class="tab-btn-side" data-tab="panel-contact-enseignant">✉️ Contacter un enseignant</button>
                </div>
            </li>
            <li class="sidebar-section-label">Compte</li>
            <li><a href="../pages/deconnexion.php">🚪 Déconnexion</a></li>
        </ul>
    </aside>

    <!-- MAIN -->
    <main class="main-content">
        <h2 style="font-family:'Sora',serif;font-size:1.8rem;margin-bottom:6px;">
            Bonjour, <?= sanitize($moi['prenom']) ?> 👋
        </h2>
        <p style="color:var(--gris-med);margin-bottom:28px;font-size:.9rem;">
            <?= sanitize($moi['universite_nom']) ?> · <?= sanitize($moi['urf_nom']) ?> · <?= sanitize($moi['niveau']) ?> · <?= sanitize($moi['classe']) ?>
        </p>

        <!-- ── PANEL INFORMATIONS ── -->
        <div id="panel-informations" class="tab-panel-etu active">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">🪪 Mes Informations</h3>
            <div class="table-card" style="padding:24px 28px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;">
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Nom complet</div>
                        <div style="font-weight:700;"><?= sanitize($moi['prenom'] . ' ' . $moi['nom']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Matricule</div>
                        <div style="font-weight:700;"><?= sanitize($moi['matricule']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Âge</div>
                        <div style="font-weight:700;"><?= $moi['age'] !== null ? sanitize($moi['age']) . ' ans' : '—' ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Année académique</div>
                        <div style="font-weight:700;"><?= sanitize($moi['annee_academique']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Université</div>
                        <div style="font-weight:700;"><?= sanitize($moi['universite_nom']) ?> (<?= sanitize($moi['universite_sigle']) ?>)</div>
                        <div style="font-size:.8rem;color:var(--gris-med);"><?= $moi['universite_type'] === 'publique' ? 'Université publique' : 'Université privée' ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">URF</div>
                        <div style="font-weight:700;"><?= sanitize($moi['urf_nom']) ?> (<?= sanitize($moi['urf_sigle']) ?>)</div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Niveau</div>
                        <div style="font-weight:700;"><?= sanitize($moi['niveau']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Classe</div>
                        <div style="font-weight:700;"><?= sanitize($moi['classe']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">E-mail</div>
                        <div style="font-weight:700;"><?= sanitize($moi['email']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:.78rem;color:var(--gris-med);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Téléphone</div>
                        <div style="font-weight:700;"><?= $moi['tel'] ? sanitize($moi['tel']) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── PANEL NOTES (lecture seule) ── -->
        <div id="panel-notes" class="tab-panel-etu">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">📊 Mes Notes</h3>
            <p style="color:var(--gris-med);font-size:.85rem;margin-bottom:16px;">
                Consultation uniquement — seul l'enseignant concerné peut saisir ou modifier une note.
            </p>
            <?php if (empty($mesNotes)): ?>
            <div style="background:white;border-radius:14px;padding:40px;text-align:center;box-shadow:var(--shadow);">
                <div style="font-size:3rem;margin-bottom:12px;">📭</div>
                <p style="color:var(--gris-med);">Aucune note n'a encore été saisie pour vous.</p>
            </div>
            <?php else: ?>
            <div class="table-card">
                <div class="table-header">
                    <h3>Relevé de notes</h3>
                    <span class="table-info"><?= count($mesNotes) ?> matière(s)</span>
                </div>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Matière</th><th>Enseignant</th><th>CC /20</th><th>Examen /20</th><th>Moyenne finale</th><th>Mise à jour</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mesNotes as $n): $moy = $n['moyenne'] !== null ? floatval($n['moyenne']) : null; ?>
                        <tr>
                            <td><strong><?= sanitize($n['matiere']) ?></strong></td>
                            <td style="font-size:.85rem;"><?= sanitize($n['ens_prenom'] . ' ' . $n['ens_nom']) ?></td>
                            <td><?= $n['note_cc'] !== null ? sanitize($n['note_cc']) . '/20' : '—' ?></td>
                            <td><?= $n['note_examen'] !== null ? sanitize($n['note_examen']) . '/20' : '—' ?></td>
                            <td>
                                <?php if ($moy !== null): ?>
                                <span class="moyenne-badge <?= getMoyClassEtu($moy) ?>"><?= number_format($moy, 2) ?>/20</span>
                                <?php else: ?><span style="color:#ccc">—</span><?php endif; ?>
                            </td>
                            <td style="font-size:.8rem;color:var(--gris-med);"><?= date('d/m/Y', strtotime($n['updated_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── PANEL CLASSE ── -->
        <!-- ── PANEL MESSAGES (reçus de l'administration et des enseignants) ── -->
        <div id="panel-messages" class="tab-panel-etu">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">💬 Mes Messages</h3>
            <p style="color:var(--gris-med);font-size:.85rem;margin-bottom:16px;">
                Messages reçus de l'administration et de vos enseignants.
            </p>
            <?php if (empty($messages)): ?>
                <div class="message-box">
                    <div class="msg-icon">📭</div>
                    <div class="msg-body"><h4>Aucun message</h4><p>Vous n'avez reçu aucun message pour le moment.</p></div>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <div class="message-box" style="margin-bottom:12px;">
                    <div class="msg-icon"><?= $msg['source'] === 'enseignant' ? '👨‍🏫' : '📢' ?></div>
                    <div class="msg-body">
                        <h4><?= $msg['source'] === 'enseignant' ? sanitize('Message de ' . $msg['ens_prenom'] . ' ' . $msg['ens_nom']) : "Message de l'Administration" ?></h4>
                        <p><?= nl2br(sanitize($msg['contenu'])) ?></p>
                        <div class="msg-meta">
                            📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                            <?php if ($msg['destinataire_type'] === 'tous_etudiants'): ?>
                                · 🌍 Tous les étudiants
                            <?php elseif ($msg['destinataire_type'] === 'etudiant_specifique'): ?>
                                · 👤 Message personnel
                            <?php elseif ($msg['destinataire_type'] === 'groupe_etudiants'): ?>
                                · 🎯 Votre groupe
                            <?php else: ?>
                                · 🎯 Votre classe
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── PANEL CLASSE ── -->
        <div id="panel-classe" class="tab-panel-etu">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">
                👥 Ma Classe – <?= sanitize($moi['urf_sigle']) ?> <?= sanitize($moi['niveau']) ?> · <?= sanitize($moi['classe']) ?>
            </h3>

            <!-- Liste des camarades -->
            <?php if (empty($camarades)): ?>
            <div style="background:white;border-radius:14px;padding:40px;text-align:center;box-shadow:var(--shadow);">
                <div style="font-size:3rem;margin-bottom:12px;">🎒</div>
                <p style="color:var(--gris-med);">Aucun autre étudiant dans votre classe pour le moment.</p>
            </div>
            <?php else: ?>
            <div class="table-card">
                <div class="table-header">
                    <h3>Camarades de classe</h3>
                    <span class="table-info"><?= count($camarades) ?> étudiant(s)</span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Matricule</th>
                            <th>E-mail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($camarades as $c): ?>
                        <tr>
                            <td><strong><?= sanitize($c['nom']) ?></strong></td>
                            <td><?= sanitize($c['prenom']) ?></td>
                            <td><?= sanitize($c['matricule']) ?></td>
                            <td><?= sanitize($c['email']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── PANEL CONTACTER UN ENSEIGNANT ── -->
        <div id="panel-contact-enseignant" class="tab-panel-etu">
            <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:16px;">✉️ Contacter un enseignant</h3>

            <?php if (empty($mesEnseignants)): ?>
            <div style="background:white;border-radius:14px;padding:40px;text-align:center;box-shadow:var(--shadow);">
                <div style="font-size:3rem;margin-bottom:12px;">👨‍🏫</div>
                <p style="color:var(--gris-med);">Aucun enseignant ne correspond encore à votre classe.</p>
            </div>
            <?php else: ?>
            <div class="compose-box" style="background:white;border-radius:14px;padding:20px;box-shadow:var(--shadow);margin-bottom:24px;">
                <div class="form-group">
                    <label>Enseignant</label>
                    <select id="msg-ens-id">
                        <option value="">-- Choisir un enseignant --</option>
                        <?php foreach ($mesEnseignants as $ens): ?>
                        <option value="<?= $ens['id'] ?>"><?= sanitize($ens['prenom'] . ' ' . $ens['nom'] . ' (' . $ens['urf_sigle'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <textarea id="msg-ens-contenu" placeholder="Rédigez votre message ici..." style="width:100%;min-height:90px;padding:12px;border:1.5px solid #E0E4EA;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.92rem;resize:vertical;outline:none;margin:10px 0;box-sizing:border-box;"></textarea>
                <button class="btn-submit" id="btn-send-msg-ens" style="width:auto;padding:10px 24px;">📤 Envoyer</button>
            </div>

            <h4 style="font-size:1rem;font-weight:700;margin-bottom:12px;">Mes messages envoyés</h4>
            <?php if (empty($mesMessagesEnvoyes)): ?>
            <div style="background:white;border-radius:14px;padding:30px;text-align:center;box-shadow:var(--shadow);">
                <p style="color:var(--gris-med);">Vous n'avez encore écrit à aucun enseignant.</p>
            </div>
            <?php else: ?>
                <?php foreach ($mesMessagesEnvoyes as $msg): ?>
                <div class="message-box" data-msg-id="<?= $msg['id'] ?>" style="margin-bottom:12px;">
                    <div class="msg-icon">📤</div>
                    <div class="msg-body" style="flex:1;">
                        <h4>À <?= sanitize($msg['ens_prenom'] . ' ' . $msg['ens_nom']) ?></h4>
                        <p><?= nl2br(sanitize($msg['contenu'])) ?></p>
                        <div class="msg-meta">📅 <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                    </div>
                    <button class="btn-del-msg-etu" data-msg-id="<?= $msg['id'] ?>" style="background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.2);color:#dc2626;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:.8rem;white-space:nowrap;align-self:flex-start;">🗑️ Supprimer</button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/app.js"></script>
<script>
document.querySelectorAll('.tab-btn-side').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn-side').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel-etu').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(this.dataset.tab).classList.add('active');
    });
});

// Envoyer un message à un enseignant
document.getElementById('btn-send-msg-ens')?.addEventListener('click', async function () {
    const ensId = document.getElementById('msg-ens-id').value;
    const contenu = document.getElementById('msg-ens-contenu').value.trim();
    if (!ensId) { showToast('Veuillez choisir un enseignant', 'error'); return; }
    if (!contenu) { showToast('Le message ne peut pas être vide', 'error'); return; }
    this.textContent = '...';
    try {
        const fd = new FormData();
        fd.append('enseignant_id', ensId);
        fd.append('contenu', contenu);
        const res = await fetch('../api/send_message_etudiant.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('✅ Message envoyé', 'success');
            document.getElementById('msg-ens-contenu').value = '';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Erreur', 'error');
        }
    } catch (e) { showToast('Erreur réseau', 'error'); }
    this.textContent = '📤 Envoyer';
});

// Supprimer un message envoyé à un enseignant
document.querySelectorAll('.btn-del-msg-etu').forEach(btn => {
    btn.addEventListener('click', async function () {
        if (!confirm('Supprimer ce message ?')) return;
        const msgId = this.dataset.msgId;
        const fd = new FormData();
        fd.append('message_id', msgId);
        try {
            const res = await fetch('../api/delete_message_etudiant.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                this.closest('[data-msg-id]').remove();
                showToast('Message supprimé', 'success');
            } else {
                showToast(data.message || 'Erreur', 'error');
            }
        } catch (e) { showToast('Erreur réseau', 'error'); }
    });
});
</script>
</body>
</html>
