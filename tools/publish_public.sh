#!/usr/bin/env bash
# Publishes the latest COMMITTED code to the shared repository github.com/LyleZhyla/Scholarlink (public).
#
#   Git Bash, from the project folder:
#     tools/publish_public.sh "What changed"              -> one new commit on top of main
#     tools/publish_public.sh --replace "Message"         -> main becomes a single fresh commit (rewrites it)
#
# Never published: .env and API keys, vendor/ (run "composer install"), students' uploads, real student data.
# scholarlink_db.sql is rebuilt as structure + reference data only (no students, applications, messages);
# staff/provider accounts get the placeholder password ChangeMe@2026.
# The push stops if the scan finds a key, a personal e-mail address or a .env file.
set -euo pipefail

SRC=$(cd "$(dirname "$0")/.." && pwd)
REMOTE=${PUBLIC_REMOTE:-https://github.com/LyleZhyla/Scholarlink.git}
WORK=${PUBLIC_WORKDIR:-/c/xampp/scholarlink_public}          # outside htdocs, so it is never served
MYSQL_BIN=${MYSQL_BIN:-/c/xampp/mysql/bin}
PHP=${PHP_BIN:-/c/xampp/php/php}
DB=(-uroot -P"${DB_PORT:-3307}" -h127.0.0.1)
TMPDB="sl_publish_$$"

REPLACE=0
if [ "${1:-}" = "--replace" ]; then REPLACE=1; shift; fi
MESSAGE=${1:-"Update ScholarLink"}

die() { echo "✗ $*" >&2; "$MYSQL_BIN/mysql" "${DB[@]}" -e "DROP DATABASE IF EXISTS $TMPDB" 2>/dev/null || true; exit 1; }

if [ -n "$(git -C "$SRC" status --porcelain)" ]; then
    echo "! Uncommitted changes are NOT published (only the last commit is). Commit first if they should go too."
fi

# 1. Working copy of the public repository, reset to what is on GitHub
if [ ! -d "$WORK/.git" ]; then
    git clone -q "$REMOTE" "$WORK"
fi
git -C "$WORK" fetch -q origin
git -C "$WORK" checkout -q -B main origin/main
REMOTE_HEAD=$(git -C "$WORK" rev-parse origin/main)

# 2. The committed code, minus what must stay private
find "$WORK" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
git -C "$SRC" archive HEAD | tar -x -C "$WORK"
rm -rf "$WORK/vendor" "$WORK/uploads/documents" "$WORK/uploads/profiles" "$WORK/uploads/vault" "$WORK/uploads/renewals" "$WORK/.env"
printf '\n# Third-party libraries: install with "composer install" (versions pinned in composer.lock)\n/vendor/\n' >> "$WORK/.gitignore"

# 3. Database without real people: structure and reference data only
"$MYSQL_BIN/mysql" "${DB[@]}" -e "DROP DATABASE IF EXISTS $TMPDB; CREATE DATABASE $TMPDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
"$MYSQL_BIN/mysql" "${DB[@]}" "$TMPDB" < "$SRC/scholarlink_db.sql" || die "scholarlink_db.sql did not import"
HASH=$("$PHP" -d display_startup_errors=0 -r 'echo password_hash("ChangeMe@2026", PASSWORD_DEFAULT);' 2>/dev/null | tail -1)
PERSONAL_TABLES="application application_custom_answers audit_log deadline_reminders messages notifications profile_change_requests
                 scholar_renewals score submitted_document user_profile_answers user_vault system_notifications demo_data_rows demo_scholarship_slots"
SQL="SET FOREIGN_KEY_CHECKS = 0;"
for t in $PERSONAL_TABLES; do
    if [ -n "$("$MYSQL_BIN/mysql" "${DB[@]}" -N "$TMPDB" -e "SHOW TABLES LIKE '$t'")" ]; then SQL="$SQL TRUNCATE \`$t\`;"; fi
done
SQL="$SQL DELETE FROM users WHERE Role = 'Student';
     UPDATE users SET PasswordHash = '$HASH', ResetToken = NULL, ResetTokenExpire = NULL, GoogleID = NULL,
                      ContactNumber = NULL, DateOfBirth = NULL, ProfilePicture = NULL;
     SET FOREIGN_KEY_CHECKS = 1;"
"$MYSQL_BIN/mysql" "${DB[@]}" "$TMPDB" -e "$SQL"
{
    echo "-- ScholarLink database: structure and reference data only (programs, scholarships, requirements, forms, settings)."
    echo "-- No students, applications or messages. Staff/provider accounts use the placeholder password ChangeMe@2026: change it after the first sign-in."
    echo
    "$MYSQL_BIN/mysqldump" "${DB[@]}" --single-transaction --skip-comments --default-character-set=utf8mb4 \
        --ignore-table="$TMPDB.demo_data_rows" --ignore-table="$TMPDB.demo_scholarship_slots" "$TMPDB"
} > "$WORK/scholarlink_db.sql"
"$MYSQL_BIN/mysql" "${DB[@]}" -e "DROP DATABASE $TMPDB"

# 4. Safety scan: stop on keys, personal addresses or a .env file
cd "$WORK"
[ -e .env ] && die ".env would be published"
KEYS=$(grep -rlE "AIza[0-9A-Za-z_-]{35}|AQ\.Ab8[0-9A-Za-z_-]{20,}|GOCSPX-[A-Za-z0-9_-]{10,}|sk-[A-Za-z0-9]{20,}" --exclude-dir=.git . || true)
[ -n "$KEYS" ] && die "API key found in: $KEYS"
# (third-party libraries in */vendor/ carry their authors' and translators' addresses: not our users)
MAILS=$(grep -rhoiE "[A-Za-z0-9._%+-]+@(gmail|yahoo|outlook|hotmail)\.com" --exclude-dir=.git --exclude-dir=vendor --include=*.php --include=*.js --include=*.sql --include=*.json --include=*.md --include=*.html . | sort -u || true)
[ -n "$MAILS" ] && die "personal e-mail addresses found: $MAILS"
[ -n "$(find . -path ./.git -prune -o -type f \( -name '*.sql' ! -name 'scholarlink_db.sql' ! -path './migrations/*' \) -print)" ] && die "an extra .sql file (backup?) would be published"

# 5. Commit and push (no co-author lines)
git add -A
if [ "$REPLACE" = 1 ]; then
    git checkout -q --orphan publish-fresh
    git add -A
    git commit -q -m "$MESSAGE"
    git push --force-with-lease="main:$REMOTE_HEAD" origin publish-fresh:main
    git checkout -q -B main publish-fresh && git branch -q -D publish-fresh
elif git diff --cached --quiet; then
    echo "✓ Nothing changed since the last publish."
    exit 0
else
    git commit -q -m "$MESSAGE"
    git push -q origin main
fi
echo "✓ Published $(git rev-parse --short HEAD) to $REMOTE"
