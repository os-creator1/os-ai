#!/usr/bin/env bash
# Run ON THE SERVER, as the site user, after a deployment, to prove customer uploads and storage are
# shared between releases (so they survive deploys and rollbacks).
#
#   bash /home/forge/staging.getmotiongrove.com/current/deploy/forge/verify-uploads.sh /home/forge/staging.getmotiongrove.com
#
# It is read-mostly: the only write is a tiny probe file, created through the live release and removed
# again. It needs at least two releases to prove the rollback side; with one it checks the links only.

SITE="${1:-}"
if [ -z "$SITE" ] || [ ! -d "$SITE/current" ]; then echo "usage: $0 <forge site root containing current/ and releases/>" >&2; exit 2; fi
fail=0
ok()  { echo "ok    $1"; }
bad() { echo "FAIL  $1"; fail=$((fail + 1)); }

SHARED="$(cd "$SITE/storage/app/shared-public" 2>/dev/null && pwd -P)" || { bad "shared upload directory $SITE/storage/app/shared-public does not exist"; exit 1; }

DIRS="images/websites images/business images/logo images/branding/logo images/branding/logo_compact images/branding/logo_dark images/branding/favicon images/branding/auth_illustration images/branding/installer_illustration images/branding/agency mms voice senderid_docs"

for d in $DIRS; do
    link="$SITE/current/public/$d"
    if [ -L "$link" ] && [ "$(cd "$link" && pwd -P)" = "$SHARED/$d" ]; then ok "current/public/$d -> shared storage"; else bad "current/public/$d is not a link into $SHARED/$d"; fi
done

# storage and .env are shared too (Forge links them); the app writes both.
for p in storage .env; do
    if [ -L "$SITE/current/$p" ]; then ok "current/$p is a link (shared)"; else bad "current/$p is not a link"; fi
done

# public/storage must resolve inside the live release without pointing at a deleted release.
if [ -L "$SITE/current/public/storage" ] && [ -d "$SITE/current/public/storage" ]; then ok "public/storage resolves"; else bad "public/storage does not resolve (run: php artisan storage:link --relative)"; fi

# Probe: write through the live release, read it through every other release.
probe="images/websites/.persistence-probe-$$"
echo probe > "$SITE/current/public/$probe" 2>/dev/null && ok "wrote probe through current" || bad "cannot write to current/public/images/websites (permissions?)"
n=0
for rel in "$SITE"/releases/*/; do
    [ "$(cd "$rel" && pwd -P)" = "$(cd "$SITE/current" && pwd -P)" ] && continue
    n=$((n + 1))
    if [ -f "$rel/public/$probe" ]; then ok "previous release $(basename "$rel") sees the probe (rollback-safe)"; else bad "previous release $(basename "$rel") does NOT see the probe"; fi
done
[ "$n" -eq 0 ] && echo "note  only one release exists; deploy once more and re-run to prove the rollback side."
rm -f "$SITE/current/public/$probe"

if [ "$fail" -eq 0 ]; then echo "All persistence checks passed."; else echo "$fail check(s) failed."; fi
exit "$fail"
