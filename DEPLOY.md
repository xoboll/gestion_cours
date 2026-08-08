# 🚀 DEPLOY.md — Mettre gestion_cours en ligne avec GitHub + Supabase + Render

Ce guide utilise **Render.com** plutôt que Vercel : Vercel exécute le PHP via
des fonctions serverless (sans état, dossier `api/` obligatoire, sessions
compliquées), alors que Render fait tourner un **vrai serveur Apache+PHP**
via Docker — exactement comme WampServer, mais en ligne. Résultat : aucune
restructuration du projet n'est nécessaire, et les sessions PHP fonctionnent
normalement.

Tout est **gratuit** sur les paliers de base des trois services.

---

## 1. Créer la base de données sur Supabase

1. Sur [supabase.com](https://supabase.com), créez un nouveau projet — nommez-le `gestion_cours`
2. Allez dans **SQL Editor**, collez tout le contenu de `database.sql`, cliquez sur **Run and enable RLS**
3. Vérifiez dans **Table Editor** que les tables sont bien créées

### Récupérer les identifiants de connexion

**Project Settings → Database**. Utilisez de préférence les identifiants du
**Connection Pooler** (mode *Session*, port `5432`, ou *Transaction*, port
`6543`) plutôt que la connexion directe — plus fiable depuis un hébergeur
externe. Notez : host, port, database (`postgres`), user, password.

---

## 2. Pousser le projet sur GitHub

```bash
cd gestion_cours
git init
git add .
git commit -m "Version initiale"
git branch -M main
git remote add origin https://github.com/VOTRE-COMPTE/gestion_cours.git
git push -u origin main
```

Le `Dockerfile` fourni dans le projet doit être à la racine du dépôt (c'est déjà le cas).

---

## 3. Déployer sur Render

1. Sur [render.com](https://render.com), connectez-vous avec votre compte GitHub
2. **New → Web Service**
3. Sélectionnez votre dépôt `gestion_cours`
4. Render détecte automatiquement le `Dockerfile` — laissez **Runtime = Docker**
5. Choisissez le plan **Free**
6. Dans **Environment Variables**, ajoutez :

| Nom | Valeur |
|---|---|
| `DB_HOST` | l'hôte du pooler Supabase noté à l'étape 1 |
| `DB_PORT` | `5432` (pooler session) ou `6543` (pooler transaction) |
| `DB_NAME` | `postgres` (nom fixe, imposé par Supabase — ne pas mettre `gestion_cours` ici) |
| `DB_USER` | `postgres` (ou `postgres.xxxxxxxx` si vous utilisez le pooler — copiez la valeur exacte affichée par Supabase) |
| `DB_PASS` | le mot de passe de votre base Supabase |
| `DB_SSLMODE` | `require` |

> `USE_DB_SESSIONS` n'est **pas nécessaire** avec Render (contrairement à Vercel) : le conteneur reste actif entre les requêtes, donc les sessions fichiers classiques de PHP fonctionnent normalement. Ne définissez pas cette variable.

7. Cliquez sur **Create Web Service**

Render construit l'image Docker (comptez quelques minutes la première fois) puis démarre le conteneur.

---

## 4. Tester

Render vous donne une URL du type `https://gestion-cours.onrender.com`. Testez :
- La page d'accueil s'affiche
- Connexion admin (`alobe557@gmail.com` / `123456789`)
- Vous restez connecté en changeant de page
- Une inscription étudiant/enseignant

---

## ⚠️ Limitations du palier gratuit

- **Render (gratuit)** : le service s'endort après 15 minutes sans trafic. Le premier visiteur après une pause attend 30-60 secondes le temps que le conteneur redémarre — normal, pas un bug.
- **Supabase (gratuit)** : le projet se met en pause après une semaine d'inactivité totale ; il suffit de rouvrir le tableau de bord Supabase pour le réveiller.
- **`mail()`** ne fonctionne pas nativement sur Render non plus. Ce n'est pas un problème : la page "Mot de passe oublié" affiche déjà automatiquement le lien à l'écran si l'envoi échoue — déjà prévu dans le code.

---

## En cas d'erreur au déploiement

- **Le build Docker échoue** : dans Render, onglet **Logs**, lisez l'erreur exacte — copiez-la-moi si besoin
- **Erreur de connexion à la base une fois le site en ligne** : revérifiez les 6 variables d'environnement (Render → *Environment*), en particulier `DB_HOST` et `DB_PASS`
- **Page blanche ou erreur 500** : Render → onglet **Logs** affiche les erreurs PHP réelles en direct
