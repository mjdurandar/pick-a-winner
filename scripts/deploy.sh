#!/usr/bin/env bash
#
# Deploy the WIN app to Hostinger.
#
#   scripts/deploy.sh staging               deploy GitHub's master to staging
#   scripts/deploy.sh production            deploy GitHub's master to production
#   scripts/deploy.sh production --check    look only: say what a deploy would do
#
# What it does, in this order:
#   1. Refuses unless this checkout is master, has nothing uncommitted and
#      matches GitHub. The front-end is built here, so anything uncommitted
#      would ship in the build without its PHP.
#   2. Reads the server: which commit it is on, what would be pulled, which
#      migrations would run.
#   3. Builds the front-end here (the server has no Node).
#   4. Uploads the new asset files next to the old ones. Nothing is deleted, so
#      a host with the draw open keeps working through the deploy.
#   5. On the server: pulls the exact commit that was built, runs composer if
#      the lock file moved, switches to the new manifest, runs migrations,
#      clears the config/route/view caches and restarts the queue.
#   6. Loads the site to check it answers.
#
# Migrations that drop or empty something are HELD BACK and reported, never run
# as a side effect of a normal deploy. To run one on purpose, name it:
#   scripts/deploy.sh staging --allow=2026_08_19_000000_drop_is_hidden_from_locations_table
# (repeat --allow= for more, or --allow-destructive for every held one).
#
# Needs the "win-hostinger" entry in ~/.ssh/config (key login, no password).

set -euo pipefail

BRANCH=master
SSH_HOST="${WIN_DEPLOY_HOST:-win-hostinger}"
COMPOSER=/usr/local/bin/composer2

TARGET=""
CHECK=0
ALLOW_DESTRUCTIVE=0
ALLOWED=" "
for arg in "$@"; do
    case "$arg" in
        staging|production) TARGET="$arg" ;;
        --check) CHECK=1 ;;
        --allow-destructive) ALLOW_DESTRUCTIVE=1 ;;
        --allow=*) ALLOWED="$ALLOWED${arg#--allow=} " ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

# Each site runs the PHP version picked for it in hPanel. Artisan and composer
# have to use the same one, or they can disagree with the live site.
case "$TARGET" in
    staging)
        REMOTE_DIR=/home/u717531173/domains/white-rail-267402.hostingersite.com/public_html
        PHP=/opt/alt/php84/usr/bin/php
        SITE_URL=https://white-rail-267402.hostingersite.com
        ;;
    production)
        REMOTE_DIR=/home/u717531173/domains/win.adventureentertainment.com/public_html
        PHP=/opt/alt/php82/usr/bin/php
        SITE_URL=https://win.adventureentertainment.com
        ;;
    *)
        echo "Usage: scripts/deploy.sh <staging|production> [--check] [--allow=<migration>] [--allow-destructive]" >&2
        exit 2
        ;;
esac

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
info() { printf '  %s\n' "$*"; }
die()  { printf '\n\033[31mStopped: %s\033[0m\n' "$*" >&2; exit 1; }

SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=20 -o ControlMaster=auto -o "ControlPath=$HOME/.ssh/cm-win-%C" -o ControlPersist=120)
remote() { ssh "${SSH_OPTS[@]}" "$SSH_HOST" "$@"; }

cd "$(git rev-parse --show-toplevel)"

# ------------------------------------------------------------ 1. this machine
say "1. This checkout"
PROBLEMS=""
problem() { PROBLEMS="${PROBLEMS}  - $1"$'\n'; }

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
[ "$CURRENT_BRANCH" = "$BRANCH" ] || problem "You are on '$CURRENT_BRANCH', not $BRANCH."

DIRTY=$(git status --porcelain | wc -l | tr -d ' ')
[ "$DIRTY" = "0" ] || problem "$DIRTY file(s) are uncommitted or untracked. Commit (or stash) them first."

git fetch --quiet origin "$BRANCH" || die "Could not reach GitHub."
LOCAL_SHA=$(git rev-parse HEAD)
SHA=$(git rev-parse "origin/$BRANCH")
[ "$LOCAL_SHA" = "$SHA" ] || problem "Your $BRANCH and GitHub's $BRANCH differ. Push (or pull) first."

info "GitHub $BRANCH is at $(git log -1 --format='%h  %s' "$SHA")"
if [ -n "$PROBLEMS" ]; then
    printf '\n  Not ready to deploy from this checkout:\n%s' "$PROBLEMS"
    [ "$CHECK" = "1" ] || die "fix the above, then run this again."
fi

# ------------------------------------------------------------- 2. the server
say "2. The $TARGET server"
STATE=$(remote bash -s -- "$REMOTE_DIR" "$PHP" <<'REMOTE'
set -eu
cd "$1"; PHP="$2"
echo "sha=$(git rev-parse HEAD)"
echo "edited=$(git -c core.fileMode=false status --porcelain -uno | wc -l | tr -d ' ')"
echo "down=$([ -f storage/framework/down ] && echo yes || echo no)"
OUT=$("$PHP" artisan migrate:status --pending 2>&1 || true)
if printf '%s' "$OUT" | grep -Eq 'No pending migrations|Migration name'; then
    echo "migrations_read=yes"
    printf '%s\n' "$OUT" | grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+' | sed 's/^/pending=/' || true
else
    echo "migrations_read=no"
fi
REMOTE
) || die "Could not log in to $SSH_HOST. Is the win-hostinger key set up in ~/.ssh/config?"

field() { printf '%s\n' "$STATE" | sed -n "s/^$1=//p"; }
SERVER_SHA=$(field sha)
[ -n "$SERVER_SHA" ] || die "Could not read the server's commit."
[ "$(field edited)" = "0" ] || die "Tracked files were edited by hand on the server. Sort that out there first; this script will not overwrite them."
[ "$(field migrations_read)" = "yes" ] || die "Could not read the migration status on the server (is its database reachable?)."
[ "$(field down)" = "no" ] || info "Note: the site is in maintenance mode right now."

git cat-file -e "$SERVER_SHA^{commit}" 2>/dev/null \
    || die "The server is on commit ${SERVER_SHA:0:7}, which is not on GitHub."
git merge-base --is-ancestor "$SERVER_SHA" "$SHA" \
    || die "The server (${SERVER_SHA:0:7}) is not behind GitHub's $BRANCH; it has gone a different way."

info "Server is at   $(git log -1 --format='%h  %s' "$SERVER_SHA")"
COMMITS=$(git rev-list --count "$SERVER_SHA..$SHA")
if [ "$COMMITS" = "0" ]; then
    info "Code is already up to date. The build and caches would still be refreshed."
else
    info "$COMMITS commit(s) to deploy:"
    git log --format='    %h  %s' "$SERVER_SHA..$SHA" | sed -n '1,15p'
    [ "$COMMITS" -le 15 ] || info "  ... and $((COMMITS - 15)) more"
fi
git diff --quiet "$SERVER_SHA" "$SHA" -- composer.lock || info "composer.lock changed: composer install will run on the server."

# Pending after the pull = pending there now + migration files this deploy adds.
SERVER_PENDING=$(field pending)
ADDED=$(git diff --name-only --diff-filter=A "$SERVER_SHA" "$SHA" -- database/migrations | sed 's#.*/##; s#\.php$##')
PENDING=$(printf '%s\n%s\n' "$SERVER_PENDING" "$ADDED" | sed '/^$/d' | sort -u)

# A migration is destructive if its up() drops, empties or deletes. One that
# cannot be read counts as destructive.
is_destructive() {
    local src hits
    src=$(git show "$SHA:database/migrations/$1.php" 2>/dev/null) || return 0
    hits=$(printf '%s\n' "$src" | sed -n '/function up/,/function down/p' \
        | grep -Eic 'Schema::drop|dropIfExists|dropColumn|dropTimestamps|dropSoftDeletes|dropMorphs|dropRememberToken|truncate|DROP +(TABLE|COLUMN|DATABASE)|->delete\(|DELETE +FROM' || true)
    [ "${hits:-0}" -gt 0 ]
}

RUN=""; HELD=""; DROPPING=""
for m in $PENDING; do
    if ! is_destructive "$m"; then
        RUN="$RUN $m"
        info "migration, will run:    $m"
    elif [ "$ALLOW_DESTRUCTIVE" = "1" ] || [ "${ALLOWED#* $m }" != "$ALLOWED" ]; then
        RUN="$RUN $m"; DROPPING="$DROPPING $m"
        info "migration, WILL RUN (drops or deletes data):  $m"
    else
        HELD="$HELD $m"
        info "migration, HELD BACK (drops or deletes data): $m"
    fi
done
[ -n "$PENDING" ] || info "No migrations to run."
for name in $ALLOWED; do
    printf '%s\n' "$PENDING" | grep -qx "$name" || die "--allow=$name is not a pending migration on $TARGET."
done

if [ "$CHECK" = "1" ]; then
    say "Check only. Nothing was changed."
    exit 0
fi

# ---------------------------------------------------------------- 3. confirm
say "3. Confirm"
if [ "$TARGET" = "production" ]; then
    printf "  Type 'production' to deploy to the live site: "
    read -r ANSWER
    [ "$ANSWER" = "production" ] || die "not confirmed."
else
    printf "  Deploy to staging? [y/N] "
    read -r ANSWER
    case "$ANSWER" in y|Y|yes) ;; *) die "not confirmed." ;; esac
fi
if [ -n "$DROPPING" ]; then
    printf "  This will also run migrations that drop or delete data. Type 'drop' to allow it: "
    read -r ANSWER
    [ "$ANSWER" = "drop" ] || die "not confirmed."
fi

# ------------------------------------------------------------------ 4. build
say "4. Build the front-end"
[ -d node_modules ] || npm ci
STAMP=$(mktemp)
npm run build
[ public/build/manifest.json -nt "$STAMP" ] || die "the build did not write a new public/build/manifest.json."
rm -f "$STAMP"

# ----------------------------------------------------------------- 5. upload
say "5. Upload the new assets"
# Asset names carry a content hash, so these sit beside the old ones unused
# until the manifest is switched. No --delete: pages already open in a browser
# still ask for the old files.
rsync -az -e "ssh ${SSH_OPTS[*]}" public/build/assets/ "$SSH_HOST:$REMOTE_DIR/public/build/assets/"
rsync -az -e "ssh ${SSH_OPTS[*]}" public/build/manifest.json "$SSH_HOST:$REMOTE_DIR/public/build/manifest.next.json"
info "done"

# ------------------------------------------------------------------ 6. switch
say "6. Switch the server to ${SHA:0:7}"
ROLLBACK_HELP="To put the previous version back, on the server run:
    cd $REMOTE_DIR
    git -c core.fileMode=false reset --hard ${SERVER_SHA:0:12}
    cp -p public/build/manifest.prev.json public/build/manifest.json
    $PHP artisan config:clear && $PHP artisan route:clear && $PHP artisan view:clear
  (Tracked files only: .env, uploads and the database are not touched. Migrations
  that already ran are not undone.)"

# shellcheck disable=SC2086
remote bash -s -- "$REMOTE_DIR" "$PHP" "$COMPOSER" "$SHA" "$BRANCH" $RUN <<'REMOTE' || { printf '\n\033[31mThe switch stopped part-way; read the lines above.\033[0m\n  %s\n' "$ROLLBACK_HELP" >&2; exit 1; }
set -eu
cd "$1"; PHP="$2"; COMPOSER="$3"; SHA="$4"; BRANCH="$5"; shift 5
PREV=$(git rev-parse HEAD)

git fetch --quiet origin "$BRANCH"
git cat-file -e "$SHA^{commit}"
git -c core.fileMode=false merge --ff-only --quiet "$SHA"
echo "  code: $(git log -1 --format='%h  %s')"

[ ! -f public/build/manifest.json ] || cp -p public/build/manifest.json public/build/manifest.prev.json
mv public/build/manifest.next.json public/build/manifest.json
echo "  front-end: new manifest is live"

# Run composer only when vendor/ does not match composer.lock.
STALE=$("$PHP" -r '
    $lock = json_decode(file_get_contents("composer.lock"), true);
    $inst = @json_decode(@file_get_contents("vendor/composer/installed.json"), true) ?: [];
    $have = [];
    foreach (($inst["packages"] ?? $inst) as $p) { $have[$p["name"]] = $p["version"]; }
    $n = 0;
    foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $p) {
        if (($have[$p["name"]] ?? null) !== $p["version"]) { $n++; }
    }
    echo $n;')
if [ "$STALE" != "0" ]; then
    echo "  composer install ($STALE package(s) out of step)..."
    if OUT=$("$PHP" "$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader 2>&1); then
        printf '%s\n' "$OUT" | tail -2 | sed 's/^/    /'
    else
        printf '%s\n' "$OUT" | tail -25
        echo "  COMPOSER FAILED"
        exit 1
    fi
fi

for m in "$@"; do
    if OUT=$("$PHP" artisan migrate --force --path="database/migrations/$m.php" 2>&1); then
        echo "  migrated: $m"
    else
        printf '%s\n' "$OUT" | tail -25
        echo "  MIGRATION FAILED: $m"
        exit 1
    fi
done

"$PHP" artisan config:clear >/dev/null
"$PHP" artisan route:clear  >/dev/null
"$PHP" artisan view:clear   >/dev/null
"$PHP" artisan queue:restart >/dev/null
echo "  caches cleared, queue told to restart"

mkdir -p storage/logs
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) ${PREV} -> ${SHA}" >> storage/logs/deploy.log

LEFT=$("$PHP" artisan migrate:status --pending 2>&1 | grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+' | tr '\n' ' ' || true)
[ -z "$LEFT" ] || echo "  still pending (held back): $LEFT"
REMOTE

# ------------------------------------------------------------------- 7. check
say "7. Check the site"
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$SITE_URL/login" || true)
APP_JS=$(grep -oE '"file": *"assets/app-[^"]+\.js"' public/build/manifest.json | sed -n '1p' | sed -E 's/.*"(assets[^"]+)"/\1/')
JS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$SITE_URL/build/$APP_JS" || true)
info "$SITE_URL/login  ->  $CODE"
info "new app script    ->  $JS_CODE"

if [ "$CODE" = "200" ] && [ "$JS_CODE" = "200" ]; then
    say "Deployed ${SHA:0:7} to $TARGET."
else
    printf '\n\033[31mThe site did not answer as expected.\033[0m\n  %s\n' "$ROLLBACK_HELP"
    exit 1
fi
