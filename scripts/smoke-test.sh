#!/usr/bin/env bash
# Test de bout en bout de l'API (à lancer quand le serveur tourne et que la base est prête).
#   bash scripts/smoke-test.sh                      -> test côté étudiant
#   ADMIN_EMAIL=... ADMIN_PASS=... bash scripts/smoke-test.sh   -> + validation par un admin
set -u
BASE="${BASE:-http://127.0.0.1:8000}/api"
EMAIL="smoke_${RANDOM}@example.com"
PASS="MotDePasse12345"
FAILS=0

jar_student=$(mktemp); jar_admin=$(mktemp)
trap 'rm -f "$jar_student" "$jar_admin"' EXIT

token() { sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p'; }

# call JAR CSRF METHOD PATH [JSON]  -> écrit le corps dans $BODY et le code HTTP dans $CODE
call() {
  local jar="$1" csrf="$2" method="$3" path="$4" json="${5:-}"
  local args=(-s -b "$jar" -c "$jar" -X "$method" "$BASE$path" -H "X-CSRF-Token: $csrf" -w '\n%{http_code}')
  [ -n "$json" ] && args+=(-H 'Content-Type: application/json' -d "$json")
  local out; out=$(curl "${args[@]}")
  CODE="${out##*$'\n'}"; BODY="${out%$'\n'*}"
}

expect() { # description attendu
  if [ "$CODE" = "$2" ]; then echo "PASS  $1 ($CODE)"; else echo "FAIL  $1 : attendu $2, reçu $CODE -> $BODY"; FAILS=$((FAILS+1)); fi
}

call "$jar_student" "" GET /health;                          expect "health" 200
call "$jar_student" "" GET /csrf; CSRF=$(echo "$BODY" | token)
[ -n "$CSRF" ] && echo "PASS  jeton CSRF reçu" || { echo "FAIL  pas de jeton CSRF"; FAILS=$((FAILS+1)); }

call "$jar_student" "" POST /auth/register '{"nom":"Test","email":"a@b.co","mot_de_passe":"x"}'; expect "POST sans CSRF refusé" 403
call "$jar_student" "$CSRF" POST /auth/register '{"nom":"T","email":"pas-un-email","mot_de_passe":"court"}'; expect "inscription invalide refusée" 422
call "$jar_student" "$CSRF" POST /auth/register "{\"nom\":\"Étudiant Test\",\"email\":\"$EMAIL\",\"mot_de_passe\":\"$PASS\"}"; expect "création de compte" 201
call "$jar_student" "$CSRF" POST /auth/register "{\"nom\":\"Étudiant Test\",\"email\":\"$EMAIL\",\"mot_de_passe\":\"$PASS\"}"; expect "email en double refusé" 409

call "$jar_student" "$CSRF" POST /auth/login "{\"email\":\"$EMAIL\",\"mot_de_passe\":\"mauvais-mot-de-passe\"}"; expect "mauvais mot de passe" 401
call "$jar_student" "$CSRF" POST /auth/login "{\"email\":\"$EMAIL\",\"mot_de_passe\":\"$PASS\"}"; expect "connexion" 200
CSRF=$(echo "$BODY" | token)
call "$jar_student" "$CSRF" GET /auth/me;                    expect "auth/me connecté" 200
call "$jar_student" "$CSRF" GET /admin/enrollments;          expect "étudiant interdit sur route admin" 403
call "$jar_student" "$CSRF" POST /courses '{"titre":"Hack","langue":"fr"}'; expect "étudiant ne peut pas créer de cours" 403
call "$jar_student" "$CSRF" GET /courses;                    expect "liste des cours" 200
call "$jar_student" "$CSRF" POST /enrollments '{"id_cours":1}'; expect "demande d'inscription" 201
call "$jar_student" "$CSRF" POST /enrollments '{"id_cours":1}'; expect "demande en double refusée" 409
call "$jar_student" "$CSRF" GET /lessons/1/exercises;        expect "exercices refusés avant validation" 403

if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASS:-}" ]; then
  call "$jar_admin" "" GET /csrf; ACSRF=$(echo "$BODY" | token)
  call "$jar_admin" "$ACSRF" POST /auth/login "{\"email\":\"$ADMIN_EMAIL\",\"mot_de_passe\":\"$ADMIN_PASS\"}"; expect "connexion admin" 200
  ACSRF=$(echo "$BODY" | token)
  call "$jar_admin" "$ACSRF" GET "/admin/enrollments?statut=en_attente"; expect "liste des demandes" 200
  UID_STUDENT=$(echo "$BODY" | sed -n "s/.*\"id_user\":\([0-9]*\),\"id_cours\":1,[^}]*\"etudiant_email\":\"$EMAIL\".*/\1/p")
  if [ -z "$UID_STUDENT" ]; then
    UID_STUDENT=$(echo "$BODY" | grep -o "\"id_user\":[0-9]*,\"id_cours\":1,[^}]*$EMAIL" | head -1 | sed 's/"id_user":\([0-9]*\),.*/\1/')
  fi
  if [ -n "$UID_STUDENT" ]; then
    call "$jar_admin" "$ACSRF" PATCH "/admin/enrollments/$UID_STUDENT/1" '{"statut":"validee"}'; expect "validation par l'admin" 200
    call "$jar_student" "$CSRF" GET /lessons/1/exercises;     expect "exercices accessibles après validation" 200
    call "$jar_student" "$CSRF" POST /exercises/1/attempts '{"reponse":"is"}'; expect "tentative d'exercice" 200
    echo "      -> $BODY"
    call "$jar_student" "$CSRF" PUT /progress/1 '{"pourcentage":100}'; expect "progression enregistrée" 200
  else
    echo "FAIL  demande de l'étudiant introuvable dans la liste admin"; FAILS=$((FAILS+1))
  fi
  call "$jar_admin" "$ACSRF" POST /auth/logout;                expect "déconnexion admin" 200
fi

call "$jar_student" "$CSRF" POST /auth/logout;               expect "déconnexion" 200
call "$jar_student" "" GET /enrollments/me;                  expect "route protégée après logout" 401

echo; [ "$FAILS" -eq 0 ] && echo "TOUT EST OK" || { echo "$FAILS test(s) en échec"; exit 1; }
