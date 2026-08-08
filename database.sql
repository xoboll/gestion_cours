-- ============================================================
-- BASE DE DONNÉES : CAMPUSLINK
-- VERSION PostgreSQL
-- ============================================================
-- ⚠️ Ce script suppose que la base existe déjà et que vous y êtes connecté.
-- Si ce n'est pas le cas, créez-la d'abord (ligne de commande) :
--     createdb -U postgres gestion_cours
-- ou via pgAdmin : clic droit sur "Databases" → Create → Database... → nom "gestion_cours"
-- Puis exécutez ce script une fois connecté à cette base ("psql -U postgres -d gestion_cours -f database.sql").
-- ============================================================

-- ------------------------------------------------------------
-- Table Universités
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS universites (
    id     SERIAL PRIMARY KEY,
    nom    VARCHAR(150) NOT NULL,
    sigle  VARCHAR(20)  NOT NULL,
    type   VARCHAR(10)  NOT NULL CHECK (type IN ('publique','privee')),
    CONSTRAINT uniq_universite_sigle UNIQUE (sigle)
);

INSERT INTO universites (nom, sigle, type) VALUES
('Université Félix Houphouët-Boigny', 'UFHB', 'publique'),
('Université Nangui Abrogoua', 'UNA', 'publique'),
('Université de Man', 'U-MAN', 'publique'),
('Université Internationale de Côte d''Ivoire', 'UICI', 'privee'),
('Institut Universitaire d''Abidjan', 'UIA', 'privee'),
('Institut International Polytechnique des Élites d''Abidjan', 'IIPEA', 'privee')
ON CONFLICT (sigle) DO NOTHING;

-- Si vous aviez déjà importé une version antérieure avec le nom incorrect
-- "Université Internationale de Cocody", cette ligne corrige le nom sans casser
-- les étudiants/enseignants déjà rattachés à cette université (même sigle, id conservé) :
UPDATE universites SET nom = 'Université Internationale de Côte d''Ivoire' WHERE sigle = 'UICI';

-- ------------------------------------------------------------
-- Table URF / UFR (Unités de Formation et de Recherche)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS urfs (
    id     SERIAL PRIMARY KEY,
    nom    VARCHAR(150) NOT NULL,
    sigle  VARCHAR(20)  NOT NULL,
    CONSTRAINT uniq_urf_sigle UNIQUE (sigle)
);

INSERT INTO urfs (nom, sigle) VALUES
('Informatique et Sciences du Numérique', 'ISN'),
('Sciences Juridiques et Politiques', 'SJP'),
('Sciences Économiques et de Gestion', 'SEG'),
('Mines, Pétrole et Énergie', 'MPE')
ON CONFLICT (sigle) DO NOTHING;

-- ------------------------------------------------------------
-- Table Administration
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS administration (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe  VARCHAR(255) NOT NULL
);

-- Compte administrateur (email + mot de passe fournis, hashés en bcrypt)
-- email : alobe557@gmail.com
-- mot de passe : 123456789
INSERT INTO administration (email, mot_de_passe) VALUES
('alobe557@gmail.com', '$2b$10$oZqCIlnc8Aavkde/qEOb1OykOyKUGIghjkeOOBqa3f2FvxF7JL7s.')
ON CONFLICT (email) DO UPDATE SET mot_de_passe = EXCLUDED.mot_de_passe;

-- ------------------------------------------------------------
-- Table Étudiants (modèle universitaire)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etudiants (
    id                 SERIAL PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    prenom             VARCHAR(100) NOT NULL,
    age                INT NULL,
    annee_academique   VARCHAR(20) NOT NULL,
    universite_id      INT NOT NULL,
    urf_id             INT NOT NULL,
    email              VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe       VARCHAR(255) NOT NULL,
    matricule          VARCHAR(30) NOT NULL UNIQUE,
    tel                VARCHAR(20) NULL,
    classe             VARCHAR(10) NOT NULL CHECK (classe IN ('Amphi A','Amphi B')),
    niveau             VARCHAR(20) NOT NULL CHECK (niveau IN ('Licence 1','Licence 2','Licence 3','Master 1','Master 2')),
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_etu_universite FOREIGN KEY (universite_id) REFERENCES universites(id) ON DELETE RESTRICT,
    CONSTRAINT fk_etu_urf        FOREIGN KEY (urf_id)        REFERENCES urfs(id)        ON DELETE RESTRICT
);

-- ------------------------------------------------------------
-- Table Enseignants (modèle universitaire)
-- Un enseignant enseigne UNE matière (URF) mais peut intervenir dans
-- plusieurs universités, classes (amphi) et niveaux (tables de liaison ci-dessous).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enseignants (
    id             SERIAL PRIMARY KEY,
    nom            VARCHAR(100) NOT NULL,
    prenom         VARCHAR(100) NOT NULL,
    tel            VARCHAR(20) NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    urf_id         INT NOT NULL,
    mot_de_passe   VARCHAR(255) NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ens_urf FOREIGN KEY (urf_id) REFERENCES urfs(id) ON DELETE RESTRICT
);

-- Universités où intervient l'enseignant (1 publique OU 1-2 privées)
CREATE TABLE IF NOT EXISTS enseignant_universites (
    enseignant_id  INT NOT NULL,
    universite_id  INT NOT NULL,
    PRIMARY KEY (enseignant_id, universite_id),
    CONSTRAINT fk_eu_enseignant FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
    CONSTRAINT fk_eu_universite FOREIGN KEY (universite_id) REFERENCES universites(id) ON DELETE CASCADE
);

-- Classes (Amphi A / Amphi B) où intervient l'enseignant (1 ou 2)
CREATE TABLE IF NOT EXISTS enseignant_classes (
    enseignant_id  INT NOT NULL,
    classe         VARCHAR(10) NOT NULL CHECK (classe IN ('Amphi A','Amphi B')),
    PRIMARY KEY (enseignant_id, classe),
    CONSTRAINT fk_ec_enseignant FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
);

-- Niveaux où intervient l'enseignant (1 ou 2)
CREATE TABLE IF NOT EXISTS enseignant_niveaux (
    enseignant_id  INT NOT NULL,
    niveau         VARCHAR(20) NOT NULL CHECK (niveau IN ('Licence 1','Licence 2','Licence 3','Master 1','Master 2')),
    PRIMARY KEY (enseignant_id, niveau),
    CONSTRAINT fk_en_enseignant FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Table Notes
-- moyenne = note_cc x 40% + note_examen x 60% (calculée automatiquement côté PHP
-- dès que les deux notes sont renseignées)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notes (
    id             SERIAL PRIMARY KEY,
    etudiant_id    INT NOT NULL,
    enseignant_id  INT NOT NULL,
    matiere        VARCHAR(100) NOT NULL,
    note_cc        DECIMAL(5,2) NULL,
    note_examen    DECIMAL(5,2) NULL,
    moyenne        DECIMAL(5,2) NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_etudiant_enseignant UNIQUE (etudiant_id, enseignant_id),
    CONSTRAINT fk_note_etudiant   FOREIGN KEY (etudiant_id)   REFERENCES etudiants(id)   ON DELETE CASCADE,
    CONSTRAINT fk_note_enseignant FOREIGN KEY (enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- Table Messages
-- expediteur_type   : 'administration', 'enseignant' ou 'etudiant'
-- destinataire_type : 'tous_enseignants', 'enseignant_specifique',
--                      'tous_etudiants', 'groupe_etudiants', 'etudiant_specifique',
--                      'classe_enseignant', 'administration'
-- Le ciblage "groupe_etudiants" utilise université + niveau + classe (Amphi)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id                          SERIAL PRIMARY KEY,
    expediteur_type             VARCHAR(20) NOT NULL DEFAULT 'administration',
    expediteur_id               INT,
    destinataire_type           VARCHAR(30) NOT NULL,
    destinataire_enseignant_id  INT,
    destinataire_etudiant_id    INT,
    dest_universite_id          INT NULL,
    dest_niveau                 VARCHAR(20) NULL,
    dest_classe                 VARCHAR(20) NULL,
    contenu                     TEXT NOT NULL,
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_enseignant FOREIGN KEY (destinataire_enseignant_id) REFERENCES enseignants(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_etudiant   FOREIGN KEY (destinataire_etudiant_id)   REFERENCES etudiants(id)   ON DELETE CASCADE,
    CONSTRAINT fk_msg_universite FOREIGN KEY (dest_universite_id) REFERENCES universites(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- Table Sessions (nécessaire uniquement pour un déploiement serverless
-- type Vercel — voir includes/DbSessionHandler.php et DEPLOY.md)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(128) PRIMARY KEY,
    data        TEXT NOT NULL DEFAULT '',
    expires_at  TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- ------------------------------------------------------------
-- Table unifiée de récupération de mot de passe
-- user_type : 'admin', 'enseignant' ou 'etudiant'
-- Utilisée par la page "Mot de passe oublié" pour les 3 profils
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id          SERIAL PRIMARY KEY,
    user_type   VARCHAR(20) NOT NULL,
    email       VARCHAR(150) NOT NULL,
    token       VARCHAR(255) NOT NULL UNIQUE,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Index
-- ------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_etudiants_groupe ON etudiants(universite_id, urf_id, niveau, classe);
CREATE INDEX IF NOT EXISTS idx_enseignants_urf ON enseignants(urf_id);
CREATE INDEX IF NOT EXISTS idx_notes_etudiant ON notes(etudiant_id);
CREATE INDEX IF NOT EXISTS idx_messages_expediteur ON messages(expediteur_type, expediteur_id);
CREATE INDEX IF NOT EXISTS idx_messages_groupe ON messages(dest_universite_id, dest_niveau, dest_classe);
CREATE INDEX IF NOT EXISTS idx_password_resets_lookup ON password_resets(user_type, email);
-- ============================================================
-- DONNÉES DE TEST (SEED) — 5 enseignants + 30 étudiants (5 par université)
-- Mot de passe étudiants : Etudiant@2025
-- Mot de passe enseignants : Enseignant@2025
-- Voir CREDENTIALS.md pour la liste complète des identifiants
-- ============================================================

-- ── ÉTUDIANTS ──
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Diabate', 'Kouadio', 18, '2024-2025', (SELECT id FROM universites WHERE sigle='UFHB'), (SELECT id FROM urfs WHERE sigle='SJP'), 'kouadio.diabate1@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000001', '+225 07 19 78 22 56', 'Amphi B', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Diallo', 'Rokia', 18, '2024-2025', (SELECT id FROM universites WHERE sigle='UFHB'), (SELECT id FROM urfs WHERE sigle='MPE'), 'rokia.diallo2@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000002', '+225 07 21 65 63 18', 'Amphi A', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Fofana', 'Wilfried', 18, '2024-2025', (SELECT id FROM universites WHERE sigle='UFHB'), (SELECT id FROM urfs WHERE sigle='SJP'), 'wilfried.fofana3@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000003', '+225 07 82 25 38 90', 'Amphi B', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Gnabro', 'Fatou', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UFHB'), (SELECT id FROM urfs WHERE sigle='MPE'), 'fatou.gnabro4@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000004', '+225 07 60 16 38 15', 'Amphi A', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Coulibaly', 'Awa', 24, '2024-2025', (SELECT id FROM universites WHERE sigle='UFHB'), (SELECT id FROM urfs WHERE sigle='SJP'), 'awa.coulibaly5@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000005', '+225 07 28 79 25 83', 'Amphi B', 'Master 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Kouame', 'Yves', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UNA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'yves.kouame6@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000006', '+225 07 83 91 34 57', 'Amphi A', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Gnabro', 'Mamadou', 18, '2024-2025', (SELECT id FROM universites WHERE sigle='UNA'), (SELECT id FROM urfs WHERE sigle='ISN'), 'mamadou.gnabro7@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000007', '+225 07 89 36 73 97', 'Amphi B', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Aka', 'Sandrine', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UNA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'sandrine.aka8@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000008', '+225 07 68 56 48 41', 'Amphi A', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Yao', 'Akissi', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UNA'), (SELECT id FROM urfs WHERE sigle='ISN'), 'akissi.yao9@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000009', '+225 07 48 77 73 53', 'Amphi B', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Yeo', 'Christelle', 19, '2024-2025', (SELECT id FROM universites WHERE sigle='UNA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'christelle.yeo10@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000010', '+225 07 25 75 63 31', 'Amphi A', 'Master 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Amani', 'Awa', 25, '2024-2025', (SELECT id FROM universites WHERE sigle='U-MAN'), (SELECT id FROM urfs WHERE sigle='MPE'), 'awa.amani11@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000011', '+225 07 63 15 95 19', 'Amphi B', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Tanoh', 'Grace', 23, '2024-2025', (SELECT id FROM universites WHERE sigle='U-MAN'), (SELECT id FROM urfs WHERE sigle='SJP'), 'grace.tanoh12@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000012', '+225 07 53 98 54 86', 'Amphi A', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Yao', 'Didier', 19, '2024-2025', (SELECT id FROM universites WHERE sigle='U-MAN'), (SELECT id FROM urfs WHERE sigle='MPE'), 'didier.yao13@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000013', '+225 07 44 70 99 95', 'Amphi B', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Zadi', 'Cyrille', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='U-MAN'), (SELECT id FROM urfs WHERE sigle='SJP'), 'cyrille.zadi14@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000014', '+225 07 97 67 46 59', 'Amphi A', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Kouassi', 'Josiane', 25, '2024-2025', (SELECT id FROM universites WHERE sigle='U-MAN'), (SELECT id FROM urfs WHERE sigle='MPE'), 'josiane.kouassi15@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000015', '+225 07 55 31 88 24', 'Amphi B', 'Master 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Meite', 'Serge', 22, '2024-2025', (SELECT id FROM universites WHERE sigle='UICI'), (SELECT id FROM urfs WHERE sigle='ISN'), 'serge.meite16@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000016', '+225 07 26 41 60 60', 'Amphi A', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Yao', 'Estelle', 20, '2024-2025', (SELECT id FROM universites WHERE sigle='UICI'), (SELECT id FROM urfs WHERE sigle='SEG'), 'estelle.yao17@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000017', '+225 07 67 61 80 45', 'Amphi B', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Konan', 'Solange', 26, '2024-2025', (SELECT id FROM universites WHERE sigle='UICI'), (SELECT id FROM urfs WHERE sigle='ISN'), 'solange.konan18@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000018', '+225 07 45 63 55 97', 'Amphi A', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Traore', 'Akissi', 19, '2024-2025', (SELECT id FROM universites WHERE sigle='UICI'), (SELECT id FROM urfs WHERE sigle='SEG'), 'akissi.traore19@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000019', '+225 07 32 29 39 94', 'Amphi B', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Adou', 'Armand', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UICI'), (SELECT id FROM urfs WHERE sigle='ISN'), 'armand.adou20@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000020', '+225 07 33 43 46 10', 'Amphi A', 'Master 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Sanogo', 'Wilfried', 27, '2024-2025', (SELECT id FROM universites WHERE sigle='UIA'), (SELECT id FROM urfs WHERE sigle='SJP'), 'wilfried.sanogo21@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000021', '+225 07 82 50 26 98', 'Amphi B', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Zadi', 'Divine', 18, '2024-2025', (SELECT id FROM universites WHERE sigle='UIA'), (SELECT id FROM urfs WHERE sigle='MPE'), 'divine.zadi22@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000022', '+225 07 68 97 81 60', 'Amphi A', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Kouame', 'Emmanuel', 25, '2024-2025', (SELECT id FROM universites WHERE sigle='UIA'), (SELECT id FROM urfs WHERE sigle='SJP'), 'emmanuel.kouame23@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000023', '+225 07 91 61 17 34', 'Amphi B', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Aka', 'Serge', 20, '2024-2025', (SELECT id FROM universites WHERE sigle='UIA'), (SELECT id FROM urfs WHERE sigle='MPE'), 'serge.aka24@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000024', '+225 07 24 53 86 16', 'Amphi A', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Traore', 'Landry', 26, '2024-2025', (SELECT id FROM universites WHERE sigle='UIA'), (SELECT id FROM urfs WHERE sigle='SJP'), 'landry.traore25@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000025', '+225 07 22 56 88 13', 'Amphi B', 'Master 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Yeo', 'Serge', 24, '2024-2025', (SELECT id FROM universites WHERE sigle='IIPEA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'serge.yeo26@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000026', '+225 07 29 91 42 54', 'Amphi A', 'Licence 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Kouame', 'Estelle', 19, '2024-2025', (SELECT id FROM universites WHERE sigle='IIPEA'), (SELECT id FROM urfs WHERE sigle='ISN'), 'estelle.kouame27@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000027', '+225 07 72 69 71 71', 'Amphi B', 'Licence 2')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Kouame', 'Kouadio', 23, '2024-2025', (SELECT id FROM universites WHERE sigle='IIPEA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'kouadio.kouame28@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000028', '+225 07 43 71 98 30', 'Amphi A', 'Licence 3')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Brou', 'Adjoua', 23, '2024-2025', (SELECT id FROM universites WHERE sigle='IIPEA'), (SELECT id FROM urfs WHERE sigle='ISN'), 'adjoua.brou29@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000029', '+225 07 28 98 79 13', 'Amphi B', 'Master 1')
ON CONFLICT (email) DO NOTHING;
INSERT INTO etudiants (nom, prenom, age, annee_academique, universite_id, urf_id, email, mot_de_passe, matricule, tel, classe, niveau) VALUES ('Zadi', 'Christelle', 19, '2024-2025', (SELECT id FROM universites WHERE sigle='IIPEA'), (SELECT id FROM urfs WHERE sigle='SEG'), 'christelle.zadi30@etu-gestcours.ci', '$2b$10$QfiSsj/GlfhMoJIU9KtTMeF5us0lyPXPH96jrQ6nVFH7GA5TtLIze', '24E000030', '+225 07 99 43 76 56', 'Amphi A', 'Master 2')
ON CONFLICT (email) DO NOTHING;
-- ── ENSEIGNANTS ── (SQL pur, sans PL/pgSQL, compatible avec tous les éditeurs SQL dont Supabase)
-- Enseignant 1 : Josiane Meite
INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES ('Meite', 'Josiane', '+225 05 38 78 79 74', 'josiane.meite1@prof-gestcours.ci', (SELECT id FROM urfs WHERE sigle='ISN'), '$2b$10$42ZO4lvp3yV8vOCliC9W1uR.xG9gGdHMOFs1nlN9C3vxqR4Hjguxe')
ON CONFLICT (email) DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='josiane.meite1@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='UNA'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='josiane.meite1@prof-gestcours.ci'), 'Amphi A')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='josiane.meite1@prof-gestcours.ci'), 'Master 1')
ON CONFLICT DO NOTHING;

-- Enseignant 2 : Akissi Diallo
INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES ('Diallo', 'Akissi', '+225 05 76 73 55 13', 'akissi.diallo2@prof-gestcours.ci', (SELECT id FROM urfs WHERE sigle='SJP'), '$2b$10$42ZO4lvp3yV8vOCliC9W1uR.xG9gGdHMOFs1nlN9C3vxqR4Hjguxe')
ON CONFLICT (email) DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='akissi.diallo2@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='UIA'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='akissi.diallo2@prof-gestcours.ci'), 'Amphi B')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='akissi.diallo2@prof-gestcours.ci'), 'Amphi A')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='akissi.diallo2@prof-gestcours.ci'), 'Master 1')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='akissi.diallo2@prof-gestcours.ci'), 'Licence 3')
ON CONFLICT DO NOTHING;

-- Enseignant 3 : Josiane Yao
INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES ('Yao', 'Josiane', '+225 05 38 23 39 70', 'josiane.yao3@prof-gestcours.ci', (SELECT id FROM urfs WHERE sigle='SEG'), '$2b$10$42ZO4lvp3yV8vOCliC9W1uR.xG9gGdHMOFs1nlN9C3vxqR4Hjguxe')
ON CONFLICT (email) DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='josiane.yao3@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='UFHB'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='josiane.yao3@prof-gestcours.ci'), 'Amphi A')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='josiane.yao3@prof-gestcours.ci'), 'Amphi B')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='josiane.yao3@prof-gestcours.ci'), 'Master 1')
ON CONFLICT DO NOTHING;

-- Enseignant 4 : Josiane Tanoh
INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES ('Tanoh', 'Josiane', '+225 05 92 20 94 25', 'josiane.tanoh4@prof-gestcours.ci', (SELECT id FROM urfs WHERE sigle='MPE'), '$2b$10$42ZO4lvp3yV8vOCliC9W1uR.xG9gGdHMOFs1nlN9C3vxqR4Hjguxe')
ON CONFLICT (email) DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='IIPEA'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='UICI'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), 'Amphi A')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), 'Amphi B')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), 'Licence 1')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='josiane.tanoh4@prof-gestcours.ci'), 'Master 1')
ON CONFLICT DO NOTHING;

-- Enseignant 5 : Mamadou Silue
INSERT INTO enseignants (nom, prenom, tel, email, urf_id, mot_de_passe) VALUES ('Silue', 'Mamadou', '+225 05 30 31 26 13', 'mamadou.silue5@prof-gestcours.ci', (SELECT id FROM urfs WHERE sigle='ISN'), '$2b$10$42ZO4lvp3yV8vOCliC9W1uR.xG9gGdHMOFs1nlN9C3vxqR4Hjguxe')
ON CONFLICT (email) DO NOTHING;
INSERT INTO enseignant_universites (enseignant_id, universite_id) VALUES ((SELECT id FROM enseignants WHERE email='mamadou.silue5@prof-gestcours.ci'), (SELECT id FROM universites WHERE sigle='UFHB'))
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='mamadou.silue5@prof-gestcours.ci'), 'Amphi A')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_classes (enseignant_id, classe) VALUES ((SELECT id FROM enseignants WHERE email='mamadou.silue5@prof-gestcours.ci'), 'Amphi B')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='mamadou.silue5@prof-gestcours.ci'), 'Licence 2')
ON CONFLICT DO NOTHING;
INSERT INTO enseignant_niveaux (enseignant_id, niveau) VALUES ((SELECT id FROM enseignants WHERE email='mamadou.silue5@prof-gestcours.ci'), 'Master 2')
ON CONFLICT DO NOTHING;

