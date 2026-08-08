# 🎓 CampusLink – Guide d'Installation (PostgreSQL)

## Plateforme des universités

---

## 📋 Prérequis

- **PostgreSQL** (version 12 ou supérieure recommandée)
- **PHP 8+** avec l'extension `pdo_pgsql` activée
- Un serveur web (Apache, Nginx) ou le serveur intégré de PHP pour les tests

---

## 🛠️ Installation étape par étape

### 1. Copier les fichiers sur votre serveur

Copiez **tout le contenu** de ce dossier (`index.php`, `api/`, `css/`, `includes/`, `js/`, `pages/`, `database.sql`) dans le répertoire servi par votre serveur web, par exemple :

```
/var/www/gestion_cours/
```

### 2. Créer la base de données PostgreSQL

**En ligne de commande :**
```bash
createdb -U postgres gestion_cours
psql -U postgres -d gestion_cours -f database.sql
```

**Avec pgAdmin :**
1. Clic droit sur *Databases* → *Create* → *Database...* → nommez-la `gestion_cours`
2. Ouvrez l'outil de requête (Query Tool) sur cette base
3. Ouvrez le fichier `database.sql` fourni et exécutez-le entièrement

Cela va automatiquement :
- Créer toutes les tables (universités, URF, administration, étudiants, enseignants, notes, messages, réinitialisation de mot de passe)
- Insérer les 6 universités partenaires et les 4 URF
- Créer le compte administrateur avec les identifiants ci-dessous
- Insérer 5 enseignants et 30 étudiants de démonstration (voir `CREDENTIALS.md`)

### 3. Vérifier la configuration de connexion

Le fichier `includes/config.php` est configuré pour PostgreSQL :

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'gestion_cours');
define('DB_USER', 'postgres');
define('DB_PASS', 'postgres');
```

Adaptez `DB_USER` / `DB_PASS` selon les identifiants réels de votre installation PostgreSQL (créés lors de l'installation du serveur, ou via `ALTER USER postgres PASSWORD '...';`).

### 4. Vérifier que l'extension PHP PostgreSQL est active

```bash
php -m | grep pgsql
```

Si `pdo_pgsql` n'apparaît pas, installez-le : sous Ubuntu/Debian `sudo apt install php-pgsql`, sous Windows décommentez `extension=pdo_pgsql` dans `php.ini`, puis redémarrez votre serveur web.

### 5. Accéder à l'application

Ouvrez votre navigateur à l'adresse configurée sur votre serveur web (ex : `http://localhost/` si le document root pointe vers ce dossier), ou lancez le serveur de développement PHP pour tester rapidement :

```bash
php -S localhost:8000
```
puis ouvrez `http://localhost:8000/`.

---

## 🌐 Structure des Fichiers

```
gestion_cours/
├── index.php                          ← Page d'accueil principale
├── database.sql                       ← Schéma PostgreSQL (à importer via psql ou pgAdmin)
├── includes/
│   └── config.php                     ← Configuration BDD PostgreSQL + fonctions utilitaires
├── css/
│   └── style.css                      ← Styles (thème ivoirien)
├── js/
│   └── app.js                         ← JavaScript interactif
├── pages/
│   ├── connexion.php                  ← Traitement connexion unifiée (email + mot de passe, tous profils)
│   ├── deconnexion.php                ← Déconnexion
│   ├── inscription_enseignant.php
│   ├── inscription_etudiant.php
│   ├── etudiant.php                   ← Tableau de bord étudiant
│   ├── enseignant.php                 ← Tableau de bord enseignant
│   ├── admin.php                      ← Tableau de bord admin
│   ├── mot_de_passe_oublie.php        ← Demande de réinitialisation (détection automatique du profil)
│   └── reinitialiser_mot_de_passe.php ← Définition du nouveau mot de passe
└── api/
    ├── save_note.php                  ← AJAX : sauvegarder une note
    ├── delete_etudiant.php            ← AJAX : supprimer un étudiant
    ├── send_message_enseignant.php    ← AJAX : envoyer un message à l'administration
    ├── delete_message_admin.php       ← AJAX : supprimer un message (admin)
    └── delete_message_enseignant.php  ← AJAX : supprimer un message (enseignant)
```

---

## 🔐 Connexion unifiée

Un seul bouton **"Connexion"** sur la page d'accueil, un seul formulaire (email + mot de passe) pour les trois profils. L'application détecte automatiquement s'il s'agit d'un administrateur, d'un enseignant ou d'un étudiant, et redirige vers le tableau de bord correspondant.

### Administration
- **Email** : `alobe557@gmail.com`
- **Mot de passe** : `123456789`

### Enseignant / Étudiant
- Création de compte via le bouton "Inscription" sur la page d'accueil
- Connexion avec l'email et le mot de passe utilisés à l'inscription

---

## 🔑 Récupération de mot de passe

Les trois profils (administrateur, enseignant, étudiant) disposent d'un système de récupération de mot de passe autonome :

1. Sur l'écran de connexion, cliquer sur **"Mot de passe oublié ?"**
2. Choisir son profil et saisir son adresse e-mail
3. Un lien de réinitialisation valable **1 heure** est envoyé par e-mail

> ⚠️ **Important en environnement local** : par défaut, PHP local n'est pas configuré pour envoyer de vrais e-mails (`mail()` échoue silencieusement). Dans ce cas, l'application **affiche directement le lien de réinitialisation à l'écran**, ce qui permet de tester/utiliser la fonctionnalité sans configurer de serveur SMTP. Pour un usage en production, configurez `sendmail` dans `php.ini` ou remplacez l'appel à `mail()` par une librairie comme PHPMailer avec un vrai compte SMTP.

---

## 🎓 Modèle Étudiant (universitaire)

L'inscription des **étudiants** suit un modèle universitaire :

| Champ | Détail |
|-------|--------|
| Nom, Prénom | Obligatoires |
| Âge | Optionnel |
| Année académique | Obligatoire (ex : 2024-2025) |
| Université | Obligatoire — publique (UFHB, Université Nangui Abrogoua, Université de Man) ou privée (UICI, UIA, IIPEA) |
| URF (matière/filière) | Obligatoire — ISN, SJP, SEG ou MPE |
| Email, Mot de passe | Obligatoires |
| Matricule | Obligatoire, unique (ex : 23E000009) |
| Téléphone | Optionnel |
| Classe | Obligatoire — Amphi A ou Amphi B |
| Niveau | Obligatoire — Licence 1, Licence 2, Licence 3, Master 1, Master 2 |

À la fin de l'inscription, l'étudiant est **automatiquement connecté** et redirigé vers son tableau de bord, avec deux sections :
- **🪪 Mes Informations** : toutes ses données personnelles
- **👥 Ma Classe** : les actualités/messages destinés à son groupe (université + niveau + classe) et la liste de ses camarades (même université, URF, niveau et classe)

## 👨‍🏫 Modèle Enseignant (universitaire)

L'inscription des **enseignants** suit désormais le même modèle universitaire :

| Champ | Détail |
|-------|--------|
| Nom, Prénom | Obligatoires |
| Téléphone | Optionnel |
| Email, Mot de passe | Obligatoires |
| Matière à enseigner (URF) | Obligatoire — ISN, SJP, SEG ou MPE (une seule matière) |
| Université(s) | Obligatoire — **une seule** université publique, **ou une à deux** universités privées (jamais un mélange des deux) |
| Classe(s) | Obligatoire — une ou deux classes parmi Amphi A / Amphi B |
| Niveau(x) | Obligatoire — un ou deux niveaux parmi Licence 1 → Master 2 |

Comme pour les étudiants, l'enseignant est **automatiquement connecté** après son inscription. Son tableau de bord affiche :
- **🪪 Mes Informations** : matière, université(s), classe(s), niveau(x)
- **💬 Messages** : échanges avec l'administration
- **📋 Liste de Classe** : les étudiants qui partagent sa matière (URF), une de ses universités, un de ses niveaux et une de ses classes — avec possibilité de saisir leurs notes

> ✅ Les étudiants et les enseignants suivant maintenant le même modèle universitaire, la notation (un enseignant note ses étudiants) fonctionne automatiquement dès qu'un étudiant et un enseignant partagent : université, URF, niveau et classe.

---

## ✨ Fonctionnalités

| Profil | Fonctionnalités |
|--------|----------------|
| **Étudiant** | Voir ses informations, ses camarades de classe et les actualités de sa classe, récupération de mot de passe |
| **Enseignant** | Saisir/modifier les notes des étudiants correspondants, échanger des messages avec l'administration, récupération de mot de passe |
| **Admin** | Gérer les étudiants (CRUD), voir les enseignants, envoyer/recevoir des messages (y compris ciblés par université/niveau/classe), consulter toutes les notes |

---

## ⚠️ Notes de sécurité

- Tous les mots de passe sont hashés avec `bcrypt` (`password_hash` / `password_verify`)
- Les entrées utilisateur sont validées et échappées (protection XSS)
- Toutes les requêtes SQL utilisent des `prepared statements` PDO (protection contre l'injection SQL)
- Les liens de réinitialisation de mot de passe sont à usage unique et expirent après 1 heure
- Vérification de session sur toutes les pages protégées
- En production : changer le mot de passe administrateur par défaut, activer HTTPS, configurer un vrai envoi d'e-mail

---

🇨🇮 *Développé avec ❤️ pour la Côte d'Ivoire*
