# Plateforme de formation en ligne : grammaire anglais / français

Projet **PHP + MySQL** avec un **backend** (API JSON sécurisée) et un **frontend** (HTML/JS) séparés.
Les cours sont gratuits ; un **administrateur valide** chaque demande d'inscription.

```
formation-en-ligne/
├── database/            schema.sql (tables) · seed.sql (données d'exemple)
├── backend/             API PHP  (le frontend ne l'atteint que par /api)
│   ├── public/index.php   point d'entrée unique
│   ├── src/Core/          Router, Session, Csrf, Auth, Validator, Database...
│   ├── src/Controllers/   Auth, Course, Enrollment, Progress, Exercise
│   ├── src/routes.php     toutes les routes
│   ├── bin/create-admin.php   création d'un admin en ligne de commande
│   ├── storage/logs/      journaux (hors accès web)
│   └── .env.example       configuration (à copier en .env)
├── frontend/            interface (HTML, CSS, JS pur) : remplaçable par React, Vue, mobile...
├── deploy/nginx.conf    configuration de production (HTTPS)
├── scripts/smoke-test.sh   test de bout en bout de l'API
└── dev-router.php       serveur de développement
```

---------------------------------------------------------------------

## 1. Installation sur Ubuntu (22.04 / 24.04)

```bash
sudo apt update
sudo apt install -y php-cli php-fpm php-mysql php-mbstring mysql-server nginx unzip curl
php -v          # PHP 8.1 ou plus requis
```

Dézippez le projet, puis placez-vous dedans :

```bash
unzip formation-en-ligne.zip && cd formation-en-ligne
```

## 2. Base de données

```bash
# 2.1 Créer la base et les tables
sudo mysql < database/schema.sql

# 2.2 Créer le compte applicatif (privilèges limités, JAMAIS "root" dans l'appli)
sudo mysql -e "CREATE USER 'formation_app'@'localhost' IDENTIFIED BY 'UN_MOT_DE_PASSE_LONG_ET_UNIQUE';
               GRANT SELECT, INSERT, UPDATE, DELETE ON formation_en_ligne.* TO 'formation_app'@'localhost';
               FLUSH PRIVILEGES;"

# 2.3 (facultatif) Charger les cours d'exemple
sudo mysql formation_en_ligne < database/seed.sql
```

## 3. Configuration

```bash
cp backend/.env.example backend/.env
nano backend/.env        # mettez le même mot de passe dans DB_PASS
chmod 640 backend/.env
```

## 4. Créer le premier administrateur

```bash
php backend/bin/create-admin.php
```

Il n'existe volontairement **aucune route web** pour créer un admin : c'est une règle de sécurité.
L'inscription publique crée toujours un compte `etudiant`.

## 5. Lancer en développement

```bash
php -S 127.0.0.1:8000 -t frontend dev-router.php
```

Ouvrez <http://127.0.0.1:8000>. Test automatique de l'API (dans un second terminal) :

```bash
bash scripts/smoke-test.sh
# avec la partie admin :
ADMIN_EMAIL=admin@exemple.com ADMIN_PASS='votre_mot_de_passe' bash scripts/smoke-test.sh
```

Si un test est en rouge, regardez `backend/storage/logs/app.log` : l'erreur exacte s'y trouve.

---------------------------------------------------------------------

## 6. L'API

Toutes les réponses sont en JSON. Les requêtes **POST / PUT / PATCH / DELETE** exigent l'en-tête
`X-CSRF-Token` (obtenu par `GET /api/csrf` ou renvoyé par `login`).

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| GET | `/api/csrf` | public | jeton CSRF |
| POST | `/api/auth/register` | public | créer un compte étudiant |
| POST | `/api/auth/login` | public | connexion |
| POST | `/api/auth/logout` | connecté | déconnexion |
| GET | `/api/auth/me` | connecté | utilisateur courant |
| GET | `/api/courses?langue=fr` | public | liste des cours |
| GET | `/api/courses/{id}` | public | cours + modules + leçons (liens visibles si inscription validée) |
| POST / PUT / DELETE | `/api/courses`, `/api/courses/{id}` | admin | gérer les cours |
| POST | `/api/courses/{id}/modules` | admin | ajouter un module |
| POST | `/api/modules/{id}/lessons` | admin | ajouter une leçon |
| POST | `/api/lessons/{id}/exercises` | admin | ajouter un exercice |
| POST | `/api/enrollments` | connecté | demander une inscription `{id_cours}` |
| GET | `/api/enrollments/me` | connecté | mes inscriptions |
| POST | `/api/enrollments/{id_cours}/cancel` | connecté | annuler |
| GET | `/api/admin/enrollments?statut=en_attente` | admin | demandes à traiter |
| PATCH | `/api/admin/enrollments/{id_user}/{id_cours}` | admin | `{statut: validee \| refusee}` |
| GET | `/api/progress/me` | connecté | ma progression |
| PUT | `/api/progress/{id_lecon}` | inscrit validé | `{pourcentage: 0..100}` |
| GET | `/api/lessons/{id}/exercises` | inscrit validé | exercices (sans les réponses) |
| POST | `/api/exercises/{id}/attempts` | inscrit validé | `{reponse}` → correction automatique |

Exemple avec `curl` :

```bash
curl -s -c cj -b cj http://127.0.0.1:8000/api/csrf                      # -> {"csrf_token":"..."}
curl -s -c cj -b cj -X POST http://127.0.0.1:8000/api/auth/login \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: LE_JETON' \
  -d '{"email":"admin@exemple.com","mot_de_passe":"..."}'
```

## 7. Sécurité : ce qui est en place

| Risque | Protection |
|---|---|
| Injection SQL | PDO, requêtes **préparées** partout, aucune valeur concaténée dans le SQL |
| Mots de passe volés | hash **Argon2id** (`password_hash`), mise à jour automatique du hash, jamais en clair |
| Vol de cookie de session (XSS) | cookie `HttpOnly` ; réponses JSON + `Content-Security-Policy` ; frontend sans `innerHTML` |
| Cookie intercepté | cookie `Secure` (`SESSION_SECURE=true`) + HTTPS + HSTS |
| Fixation de session | `session.use_strict_mode` + `session_regenerate_id(true)` à la connexion |
| Session volée sur un autre navigateur | liaison au User-Agent ; sinon la session est détruite |
| Session oubliée ouverte | expiration après 30 min d'inactivité **et** 8 h maximum |
| Révocation | sessions stockées en base : `DELETE FROM sessions WHERE user_id = 12;` |
| CSRF | jeton par session (en-tête `X-CSRF-Token`) + contrôle de l'en-tête `Origin` + `SameSite=Lax` |
| Force brute | 5 échecs / 15 min par email (et 20 par IP), puis erreur 429 ; couche Nginx en plus |
| Énumération d'emails | message de connexion identique, durée de calcul identique |
| Élévation de privilèges | rôle relu en base à chaque requête ; routes admin protégées ; pas de création d'admin par le web |
| Fuite d'informations | erreurs détaillées uniquement dans `storage/logs`, jamais dans la réponse |
| Données invalides | `Validator` : types, longueurs, valeurs permises, URL http(s) uniquement |
| Fichiers sensibles | seul `backend/public/` est exposé ; `.env` refusé par Nginx |

Les événements sensibles (échecs de connexion, accès admin refusé, CSRF) sont écrits dans `backend/storage/logs/app.log`.

---------------------------------------------------------------------

## 8. Mise en production (Ubuntu + Nginx + HTTPS)

```bash
# 1. Copier le projet
sudo mkdir -p /var/www && sudo cp -r formation-en-ligne /var/www/
sudo chown -R root:www-data /var/www/formation-en-ligne
sudo chmod -R 750 /var/www/formation-en-ligne
sudo chown -R www-data:www-data /var/www/formation-en-ligne/backend/storage

# 2. Configuration de production (backend/.env)
#    APP_ENV=production   APP_DEBUG=false   SESSION_SECURE=true

# 3. PHP : masquer la version
sudo sed -i 's/^expose_php = On/expose_php = Off/' /etc/php/*/fpm/php.ini
sudo systemctl restart php*-fpm

# 4. Nginx + certificat HTTPS gratuit
sudo cp deploy/nginx.conf /etc/nginx/sites-available/formation-en-ligne   # éditez exemple.com
sudo ln -s /etc/nginx/sites-available/formation-en-ligne /etc/nginx/sites-enabled/
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d exemple.com
sudo nginx -t && sudo systemctl reload nginx

# 5. Pare-feu
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable

# 6. Sauvegarde quotidienne de la base (crontab -e)
#    0 3 * * * mysqldump formation_en_ligne | gzip > /var/backups/formation-$(date +\%F).sql.gz
```

Adaptez `php8.3-fpm.sock` dans `deploy/nginx.conf` à votre version de PHP (`ls /run/php/`).

## 9. Pour aller plus loin

1. Réinitialisation de mot de passe et vérification de l'email (envoi de mail).
2. Page d'administration complète (modules, leçons, exercices) dans le frontend.
3. Tests automatisés (PHPUnit) et intégration continue.
4. Journalisation centralisée et surveillance (fail2ban sur `app.log`).
5. Remplacer le frontend par React/Vue ou une application mobile : l'API ne change pas.
