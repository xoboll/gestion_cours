<?php
require_once 'includes/config.php';

// Redirection si déjà connecté
if (isLoggedIn()) {
    $type = $_SESSION['user_type'];
    if ($type === 'admin') redirect('pages/admin.php');
    elseif ($type === 'enseignant') redirect('pages/enseignant.php');
    else redirect('pages/etudiant.php');
}

$error_msg = $_SESSION['error_msg'] ?? '';
$success_msg = $_SESSION['success_msg'] ?? '';
$error_modal = $_SESSION['error_modal'] ?? 'modal-connexion';
unset($_SESSION['error_msg'], $_SESSION['success_msg'], $_SESSION['error_modal']);

$db = getDB();
$universitesPub  = $db->query("SELECT id, nom, sigle FROM universites WHERE type='publique' ORDER BY nom")->fetchAll();
$universitesPriv = $db->query("SELECT id, nom, sigle FROM universites WHERE type='privee' ORDER BY nom")->fetchAll();
$urfsListe       = $db->query("SELECT id, nom, sigle FROM urfs ORDER BY nom")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusLink – Plateforme des universités</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="index.php" class="logo">🎓 <span>CampusLink</span> CI</a>
    <ul class="nav-links">
        <li><a href="index.php" class="active">Accueil</a></li>
        <li><a href="#presentation">Présentation</a></li>
        <li><a href="#" id="btn-connexion">Connexion</a></li>
        <li><a href="#" id="btn-inscription" class="btn-connexion">Inscription</a></li>
        <li><a href="#about">À propos</a></li>
    </ul>
</nav>

<!-- ── HERO ── -->
<section class="hero">
    <div class="hero-slides">
        <div class="hero-slide slide-1 active"></div>
        <div class="hero-slide slide-2"></div>
        <div class="hero-slide slide-3"></div>
        <div class="hero-slide slide-4"></div>
    </div>
    <div class="hero-content">
        <h1>La <span class="accent">plateforme</span><br>des universités</h1>
        <p>CampusLink réunit étudiants, enseignants et administration de toutes les universités de Côte d'Ivoire sur une seule plateforme.</p>
        <div class="hero-buttons">
            <a href="#" id="hero-btn-inscription" class="btn-primary">✏️ S'inscrire maintenant</a>
            <a href="#presentation" class="btn-secondary">En savoir plus</a>
        </div>
    </div>
    <div class="college-name">🏛️ Université Félix Houphouët-Boigny</div>
    <div class="slide-indicators">
        <div class="indicator active"></div>
        <div class="indicator"></div>
        <div class="indicator"></div>
        <div class="indicator"></div>
    </div>
</section>

<!-- ── STATS ── -->
<div class="stats-bar">
    <div class="stat-item"><div class="num">6</div><div class="label">Universités partenaires</div></div>
    <div class="stat-item"><div class="num">4</div><div class="label">Matières (URF)</div></div>
    <div class="stat-item"><div class="num">5</div><div class="label">Niveaux (L1 → M2)</div></div>
    <div class="stat-item"><div class="num">∞</div><div class="label">Étudiants gérés</div></div>
</div>

<!-- ── PRÉSENTATION ── -->
<section class="section" id="presentation" style="background: white;">
    <div class="section-title">Nos <span>Universités</span></div>
    <div class="section-divider"></div>
    <p class="section-subtitle">Universités publiques et privées partenaires, de la Licence 1 au Master 2</p>
    <div class="colleges-grid">
        <?php
        // Dégradé de couleurs propre à chaque université (utilisé tant qu'aucun logo n'est déposé dans assets/logos/)
        $uniGradients = [
            'UFHB'  => 'linear-gradient(135deg, #065F46, #10B981)',
            'UNA'   => 'linear-gradient(135deg, #312E81, #4F46E5)',
            'U-MAN' => 'linear-gradient(135deg, #0F766E, #14B8A6)',
            'UICI'  => 'linear-gradient(135deg, #92400E, #F59E0B)',
            'UIA'   => 'linear-gradient(135deg, #9F1239, #F43F5E)',
            'IIPEA' => 'linear-gradient(135deg, #581C87, #A855F7)',
        ];
        $renderUniCard = function ($u, $typeLabel) use ($uniGradients) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $u['sigle']));
            $logoRel  = 'assets/logos/' . $slug . '.png';
            $logoFull = __DIR__ . '/' . $logoRel;
            $hasLogo  = file_exists($logoFull);
            $gradient = $uniGradients[$u['sigle']] ?? 'linear-gradient(135deg, #312E81, #4F46E5)';
            ?>
            <div class="college-card">
                <div class="img-wrap" style="<?= $hasLogo ? 'background:#fff;' : 'background:' . $gradient . ';' ?>">
                    <?php if ($hasLogo): ?>
                        <img src="<?= $logoRel ?>" alt="Logo <?= sanitize($u['sigle']) ?>" class="uni-logo-img">
                    <?php else: ?>
                        <span class="uni-monogram"><?= sanitize($u['sigle']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="college-card-body">
                    <h3><?= sanitize($u['nom']) ?></h3>
                    <p>Université <?= $typeLabel ?>.</p>
                    <span class="college-badge"><?= sanitize($u['sigle']) ?></span>
                </div>
            </div>
            <?php
        };
        foreach ($universitesPub as $u)  { $renderUniCard($u, 'publique'); }
        foreach ($universitesPriv as $u) { $renderUniCard($u, 'privée'); }
        ?>
    </div>
</section>

<!-- ── FONCTIONNALITÉS ── -->
<section class="section" style="background: var(--gris);">
    <div class="section-title">Ce que vous <span>pouvez faire</span></div>
    <div class="section-divider"></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;max-width:1000px;margin:0 auto;">
        <?php
        $features = [
            ['📝', 'Gestion des notes', 'Saisie et calcul automatique des moyennes par matière'],
            ['📊', 'Suivi des classes', 'Tableau de bord complet pour chaque classe et établissement'],
            ['💬', 'Messagerie interne', "L'administration communique directement avec élèves et enseignants"],
            ['✅', 'Présences', 'Les enseignants confirment leur présence directement sur la plateforme'],
            ['👤', 'Gestion étudiants', 'Ajout, modification et suppression des dossiers étudiants'],
            ['🔒', 'Sécurité', 'Authentification sécurisée pour chaque profil utilisateur'],
        ];
        foreach ($features as $f): ?>
        <div style="background:white;border-radius:14px;padding:28px 20px;box-shadow:var(--shadow);text-align:center;">
            <div style="font-size:2.4rem;margin-bottom:14px;"><?= $f[0] ?></div>
            <h3 style="font-size:1rem;font-weight:700;margin-bottom:8px;"><?= $f[1] ?></h3>
            <p style="font-size:.85rem;color:var(--gris-med);line-height:1.5;"><?= $f[2] ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ── À PROPOS ── -->
<section class="section" id="about" style="background:white;">
    <div style="max-width:700px;margin:0 auto;text-align:center;">
        <div class="section-title">À propos <span>de nous</span></div>
        <div class="section-divider"></div>
        <p style="font-size:1rem;line-height:1.8;color:#555;">
            CampusLink est une plateforme numérique ivoirienne dédiée à l'enseignement supérieur. 
            Notre mission est de simplifier le suivi académique des étudiants, faciliter la communication 
            entre l'administration, les enseignants et les étudiants, et digitaliser la gestion des notes 
            dans les universités publiques et privées de Côte d'Ivoire.
        </p>
        <p style="font-size:.95rem;color:var(--gris-med);margin-top:16px;">
            🇨🇮 Développé en Côte d'Ivoire, pour les Ivoiriens.
        </p>
    </div>
</section>

<!-- ── FOOTER ── -->
<footer class="footer">
    <p>&copy; <?= date('Y') ?> <strong>CampusLink</strong> — Tous droits réservés · 🇨🇮 Côte d'Ivoire</p>
</footer>

<!-- ══════════════════════════════════ MODALS ══════════════════════════════════ -->

<!-- Modal Connexion -->
<div class="modal-overlay" id="modal-connexion">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">👋 Connexion</div>
        <p class="modal-subtitle">Étudiant, enseignant ou administrateur : connectez-vous avec votre email</p>
        <?php if ($error_msg && $error_modal === 'modal-connexion'): ?>
            <div class="alert alert-error"><?= sanitize($error_msg) ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= sanitize($success_msg) ?></div>
        <?php endif; ?>
        <form method="POST" action="pages/connexion.php">
            <div class="form-group">
                <label>Adresse e-mail</label>
                <input type="email" name="email" placeholder="votre@email.com" required>
            </div>
            <div class="form-group">
                <label>Mot de passe</label>
                <input type="password" name="mot_de_passe" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-submit">Se connecter →</button>
        </form>
        <div class="form-links">
            <a href="pages/mot_de_passe_oublie.php">Mot de passe oublié ?</a><br>
            Pas encore inscrit ? <a href="#" data-open-modal="modal-inscription-choix">S'inscrire</a>
        </div>
    </div>
</div>

<!-- Modal Choix Inscription -->
<div class="modal-overlay" id="modal-inscription-choix">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">📋 Inscription</div>
        <p class="modal-subtitle">Choisissez votre profil pour commencer</p>
        <div class="type-chooser">
            <div class="type-card" data-open-modal="modal-inscription-enseignant">
                <div class="icon">👨‍🏫</div>
                <h3>Enseignant</h3>
                <p>Je suis professeur dans un établissement partenaire</p>
            </div>
            <div class="type-card" data-open-modal="modal-inscription-etudiant">
                <div class="icon">🎒</div>
                <h3>Étudiant</h3>
                <p>Je suis élève dans un établissement partenaire</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal Inscription Enseignant -->
<div class="modal-overlay" id="modal-inscription-enseignant">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">👨‍🏫 Inscription Enseignant</div>
        <p class="modal-subtitle">Créez votre compte enseignant</p>
        <?php if ($error_msg && $error_modal === 'modal-inscription-enseignant'): ?>
            <div class="alert alert-error"><?= sanitize($error_msg) ?></div>
        <?php endif; ?>
        <form method="POST" action="pages/inscription_enseignant.php" id="form-inscription-enseignant">
            <div class="form-row">
                <div class="form-group"><label>Nom *</label><input type="text" name="nom" placeholder="KOUASSI" required></div>
                <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" placeholder="Jean-Baptiste" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Téléphone</label><input type="tel" name="tel" placeholder="+225 07 00 00 00"></div>
                <div class="form-group"><label>E-mail *</label><input type="email" name="email" placeholder="prof@email.com" required></div>
            </div>
            <div class="form-group">
                <label>Mot de passe *</label>
                <input type="password" name="mot_de_passe" placeholder="Minimum 8 caractères" minlength="8" required>
            </div>
            <div class="form-group">
                <label>Matière à enseigner (URF) *</label>
                <select name="urf_id" required>
                    <option value="">-- Choisir une matière --</option>
                    <?php foreach ($urfsListe as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Université *</label>
                <div style="display:flex;gap:16px;margin-bottom:10px;font-size:.88rem;">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="univ-type" value="publique" checked> Université publique (1 choix)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="univ-type" value="privee"> Université(s) privée(s) (1 ou 2 choix)
                    </label>
                </div>
                <div id="univ-publique-group">
                    <?php foreach ($universitesPub as $u): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="radio" name="universites[]" value="<?= $u['id'] ?>" class="univ-pub-radio"> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="univ-privee-group" style="display:none;">
                    <?php foreach ($universitesPriv as $u): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="checkbox" name="universites[]" value="<?= $u['id'] ?>" class="univ-priv-check"> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Classe(s) — 1 ou 2 *</label>
                <div style="display:flex;gap:16px;">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;">
                        <input type="checkbox" name="classes[]" value="Amphi A"> Amphi A
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;">
                        <input type="checkbox" name="classes[]" value="Amphi B"> Amphi B
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label>Niveau(x) — 1 ou 2 *</label>
                <div style="display:flex;flex-wrap:wrap;gap:12px;">
                    <?php foreach (['Licence 1','Licence 2','Licence 3','Master 1','Master 2'] as $niv): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;">
                        <input type="checkbox" name="niveaux[]" value="<?= $niv ?>" class="niveau-check"> <?= $niv ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn-submit">Créer mon compte →</button>
        </form>
        <div class="form-links">Déjà inscrit ? <a href="#" data-open-modal="modal-connexion">Se connecter</a></div>
    </div>
</div>

<!-- Modal Inscription Étudiant -->
<div class="modal-overlay" id="modal-inscription-etudiant">
    <div class="modal">
        <button class="modal-close">✕</button>
        <div class="modal-title">🎒 Inscription Étudiant</div>
        <p class="modal-subtitle">Créez votre compte étudiant</p>
        <?php if ($error_msg && $error_modal === 'modal-inscription-etudiant'): ?>
            <div class="alert alert-error"><?= sanitize($error_msg) ?></div>
        <?php endif; ?>
        <form method="POST" action="pages/inscription_etudiant.php">
            <div class="form-row">
                <div class="form-group"><label>Nom *</label><input type="text" name="nom" placeholder="DIALLO" required></div>
                <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" placeholder="Amidou" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Âge</label><input type="number" name="age" placeholder="20" min="14" max="60"></div>
                <div class="form-group">
                    <label>Année académique *</label>
                    <input type="text" name="annee_academique" placeholder="2024-2025" required>
                </div>
            </div>
            <div class="form-group">
                <label>Université *</label>
                <div style="display:flex;gap:16px;margin-bottom:10px;font-size:.88rem;">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="etu-univ-type" value="publique" checked> Université publique
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="radio" name="etu-univ-type" value="privee"> Université privée
                    </label>
                </div>
                <div id="etu-univ-publique-group">
                    <?php foreach ($universitesPub as $u): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="radio" name="universite_id" value="<?= $u['id'] ?>" class="etu-univ-pub-radio" required> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="etu-univ-privee-group" style="display:none;">
                    <?php foreach ($universitesPriv as $u): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.9rem;margin-bottom:6px;">
                        <input type="radio" name="universite_id" value="<?= $u['id'] ?>" class="etu-univ-priv-radio"> <?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>URF *</label>
                <select name="urf_id" required>
                    <option value="">-- Choisir une URF --</option>
                    <?php foreach ($urfsListe as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= sanitize($u['nom'] . ' (' . $u['sigle'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Classe *</label>
                    <select name="classe" required>
                        <option value="">-- Classe --</option>
                        <option value="Amphi A">Amphi A</option>
                        <option value="Amphi B">Amphi B</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Niveau *</label>
                    <select name="niveau" required>
                        <option value="">-- Niveau --</option>
                        <option value="Licence 1">Licence 1</option>
                        <option value="Licence 2">Licence 2</option>
                        <option value="Licence 3">Licence 3</option>
                        <option value="Master 1">Master 1</option>
                        <option value="Master 2">Master 2</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Matricule *</label>
                    <input type="text" name="matricule" placeholder="ex : 23E000009" required>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="tel" name="tel" placeholder="+225 07 00 00 00">
                </div>
            </div>
            <div class="form-group"><label>E-mail *</label><input type="email" name="email" placeholder="etudiant@email.com" required></div>
            <div class="form-group">
                <label>Mot de passe *</label>
                <input type="password" name="mot_de_passe" placeholder="Minimum 8 caractères" minlength="8" required>
            </div>
            <button type="submit" class="btn-submit">Créer mon compte →</button>
        </form>
        <div class="form-links">Déjà inscrit ? <a href="#" data-open-modal="modal-connexion">Se connecter</a></div>
    </div>
</div>

<script src="js/app.js"></script>
<script>
// Ouvrir modal depuis hero button
document.getElementById('hero-btn-inscription')?.addEventListener('click', function(e){
    e.preventDefault();
    document.getElementById('modal-inscription-choix').classList.add('open');
    document.body.style.overflow='hidden';
});
<?php if ($error_msg): ?>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('<?= sanitize($error_modal) ?>').classList.add('open');
    document.body.style.overflow = 'hidden';
});
<?php endif; ?>

// ── Formulaire inscription étudiant : bascule Université publique / privée ──
const etuUnivTypeRadios = document.querySelectorAll('input[name="etu-univ-type"]');
const etuUnivPubGroup   = document.getElementById('etu-univ-publique-group');
const etuUnivPrivGroup  = document.getElementById('etu-univ-privee-group');

function toggleEtuUnivType() {
    const type = document.querySelector('input[name="etu-univ-type"]:checked')?.value;
    if (type === 'publique') {
        etuUnivPubGroup.style.display = 'block';
        etuUnivPrivGroup.style.display = 'none';
        document.querySelectorAll('.etu-univ-priv-radio').forEach(r => r.checked = false);
    } else {
        etuUnivPubGroup.style.display = 'none';
        etuUnivPrivGroup.style.display = 'block';
        document.querySelectorAll('.etu-univ-pub-radio').forEach(r => r.checked = false);
    }
}
etuUnivTypeRadios.forEach(r => r.addEventListener('change', toggleEtuUnivType));

// ── Formulaire inscription enseignant : bascule Université publique / privée ──
const univTypeRadios = document.querySelectorAll('input[name="univ-type"]');
const univPubGroup   = document.getElementById('univ-publique-group');
const univPrivGroup  = document.getElementById('univ-privee-group');

function toggleUnivType() {
    const type = document.querySelector('input[name="univ-type"]:checked')?.value;
    if (type === 'publique') {
        univPubGroup.style.display = 'block';
        univPrivGroup.style.display = 'none';
        document.querySelectorAll('.univ-priv-check').forEach(c => c.checked = false);
    } else {
        univPubGroup.style.display = 'none';
        univPrivGroup.style.display = 'block';
        document.querySelectorAll('.univ-pub-radio').forEach(r => r.checked = false);
    }
}
univTypeRadios.forEach(r => r.addEventListener('change', toggleUnivType));

// Limiter les universités privées à 2 maximum
document.querySelectorAll('.univ-priv-check').forEach(box => {
    box.addEventListener('change', function () {
        const checked = document.querySelectorAll('.univ-priv-check:checked');
        if (checked.length > 2) {
            this.checked = false;
            showToast('Vous pouvez choisir au maximum 2 universités privées', 'error');
        }
    });
});

// Limiter les niveaux à 2 maximum
document.querySelectorAll('.niveau-check').forEach(box => {
    box.addEventListener('change', function () {
        const checked = document.querySelectorAll('.niveau-check:checked');
        if (checked.length > 2) {
            this.checked = false;
            showToast('Vous pouvez choisir au maximum 2 niveaux', 'error');
        }
    });
});

// Validation finale avant envoi du formulaire enseignant
document.getElementById('form-inscription-enseignant')?.addEventListener('submit', function (e) {
    const type = document.querySelector('input[name="univ-type"]:checked')?.value;
    const univChecked = type === 'publique'
        ? document.querySelectorAll('.univ-pub-radio:checked')
        : document.querySelectorAll('.univ-priv-check:checked');
    const classesChecked = document.querySelectorAll('input[name="classes[]"]:checked');
    const niveauxChecked = document.querySelectorAll('.niveau-check:checked');

    if (univChecked.length < 1) {
        e.preventDefault();
        showToast('Veuillez choisir au moins une université', 'error');
        return;
    }
    if (classesChecked.length < 1) {
        e.preventDefault();
        showToast('Veuillez choisir au moins une classe (Amphi A ou B)', 'error');
        return;
    }
    if (niveauxChecked.length < 1) {
        e.preventDefault();
        showToast('Veuillez choisir au moins un niveau', 'error');
        return;
    }
});
</script>
</body>
</html>
