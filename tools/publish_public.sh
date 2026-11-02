#!/usr/bin/env bash
# Publishes the latest COMMITTED code to the shared repository github.com/LyleZhyla/Scholarlink (public).
#
#   Git Bash, from the project folder:
#     tools/publish_public.sh "What changed"              -> one new commit on top of main
#     tools/publish_public.sh --dry-run "What changed"    -> shows what would change, pushes nothing
#     tools/publish_public.sh --replace "Message"         -> main becomes a single fresh commit (rewrites it)
#     tools/publish_public.sh --mark-synced               -> after merging teammates' commits: their latest is now included
#
# It refuses to publish while main has commits that are not in this copy, because publishing replaces the repository's
# contents with this copy: merge them first, then run --mark-synced. It also refuses to delete files from the repository
# unless --allow-deletes is given.
#
# Never published: .env and API keys, vendor/ (run "composer install"), students' uploads, real student data.
# scholarlink_db.sql is rebuilt as structure + reference data only: every table except the KEEP_TABLES list is emptied,
# so a new table is private by default;
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

REPLACE=0; DRY_RUN=0; MARK_SYNCED=0; ALLOW_DELETES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --replace) REPLACE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --mark-synced) MARK_SYNCED=1; shift ;;
        --allow-deletes) ALLOW_DELETES=1; shift ;;
        *) break ;;
    esac
done
MESSAGE=${1:-"Update ScholarLink"}

# Reference data that is safe to publish. Anything else (including tables added later) is emptied.
KEEP_TABLES=" academic_terms criteria disaster_recovery_settings document_requirement form_templates landing_content
    privacy_retention_policies program scholarship scholarship_award_notice scholarship_configuration_versions scholarship_criteria
    scholarship_custom_fields scholarship_pdf_edits scholarship_pdf_overrides scholarship_programs scholarship_report_formats
    scholarship_scoring_rubrics scholarship_workflow_stages system_settings user_profile_fields users "
KEEP_TABLES=" $(echo $KEEP_TABLES) "

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

if [ "$MARK_SYNCED" = 1 ]; then
    git -C "$WORK" update-ref refs/publish/last "$REMOTE_HEAD"
    echo "✓ Marked $(git -C "$WORK" rev-parse --short "$REMOTE_HEAD") as included in this copy."
    exit 0
fi

# Stop if anyone pushed since the last publish: this copy doesn't have their work, and publishing would erase it
LAST=$(git -C "$WORK" rev-parse -q --verify refs/publish/last || true)
if [ "$LAST" != "$REMOTE_HEAD" ]; then
    echo "✗ Not published: main has commits that are not in this copy. Publishing now would erase them:" >&2
    if [ -n "$LAST" ]; then git -C "$WORK" log --format='    %h %an: %s' "$LAST..$REMOTE_HEAD" >&2; else git -C "$WORK" log -5 --format='    %h %an: %s' "$REMOTE_HEAD" >&2; fi
    echo "  Merge them into this copy first, then run: tools/publish_public.sh --mark-synced" >&2
    exit 1
fi

# 2. The committed code, minus what must stay private
find "$WORK" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
git -C "$SRC" archive HEAD | tar -x -C "$WORK"
rm -rf "$WORK/vendor" "$WORK/uploads/documents" "$WORK/uploads/profiles" "$WORK/uploads/vault" "$WORK/uploads/renewals" "$WORK/.env"
printf '\n# Third-party libraries: install with "composer install" (versions pinned in composer.lock)\n/vendor/\n' >> "$WORK/.gitignore"

# 3. Database without real people: structure and reference data only
"$MYSQL_BIN/mysql" "${DB[@]}" -e "DROP DATABASE IF EXISTS $TMPDB; CREATE DATABASE $TMPDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
"$MYSQL_BIN/mysql" "${DB[@]}" "$TMPDB" < "$SRC/scholarlink_db.sql" || die "scholarlink_db.sql did not import"
HASH=$("$PHP" -d display_startup_errors=0 -r 'echo password_hash("ChangeMe@2026", PASSWORD_DEFAULT);' 2>/dev/null | tail -1)
SQL="SET FOREIGN_KEY_CHECKS = 0;"
for t in $("$MYSQL_BIN/mysql" "${DB[@]}" -N "$TMPDB" -e "SHOW TABLES" | tr -d '\r'); do
    case "$KEEP_TABLES" in *" $t "*) ;; *) SQL="$SQL TRUNCATE \`$t\`;" ;; esac
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
DELETED=$(git diff --cached --name-only --diff-filter=D)
if [ -n "$DELETED" ] && [ "$ALLOW_DELETES" = 0 ] && [ "$REPLACE" = 0 ]; then
    git reset -q --hard origin/main
    die "this would delete $(echo "$DELETED" | wc -l) file(s) from the repository (use --allow-deletes if that is intended): $(echo "$DELETED" | head -10 | tr '\n' ' ')"
fi
if [ "$DRY_RUN" = 1 ]; then
    echo "Dry run, nothing pushed. Would change:"
    git diff --cached --stat | tail -25
    git reset -q --hard origin/main
    exit 0
fi
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
git update-ref refs/publish/last HEAD
echo "✓ Published $(git rev-parse --short HEAD) to $REMOTE"
