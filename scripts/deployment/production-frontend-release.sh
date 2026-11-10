#!/usr/bin/env bash
# Controlled production React storefront release operations.
# SiteGround WordPress and unrelated public_html content are never mirrored or deleted.
set -euo pipefail
umask 077

action="${1:?action required}"
target="${2:?absolute public_html path required}"
private="${3:?absolute private release path required}"
release="${4:?40-character source commit required}"

[[ "$release" =~ ^[a-f0-9]{40}$ ]] || { echo "Invalid release identity" >&2; exit 2; }
[[ "$target" = /* && "$private" = /* ]] || { echo "Absolute paths required" >&2; exit 2; }
[[ -d "$target" && ! -L "$target" ]] || { echo "Existing, non-symlinked public_html required" >&2; exit 2; }
target="$(cd "$target" && pwd -P)"
[[ "${target##*/}" == "public_html" ]] || { echo "Target must be a public_html directory" >&2; exit 2; }
case "$private/" in
  "$target/"*) echo "Private release data must remain outside public_html" >&2; exit 2 ;;
esac
mkdir -p "$private"
[[ ! -L "$private" ]] || { echo "Symlinked private release root forbidden" >&2; exit 2; }
private="$(cd "$private" && pwd -P)"
case "$private/" in
  "$target/"*) echo "Private release data must remain outside public_html" >&2; exit 2 ;;
esac

incoming_root="$private/incoming"
snapshots="$private/snapshots"
release_records="$private/releases"
state="$private/current-release"
lock="$private/.production-frontend-release-lock"
mkdir -p "$incoming_root" "$snapshots" "$release_records"
[[ ! -L "$incoming_root" && ! -L "$snapshots" && ! -L "$release_records" ]] ||
  { echo "Release data directories must not be symlinks" >&2; exit 2; }

incoming="$incoming_root/$release"
snapshot="$snapshots/$release"
record="$release_records/$release"

is_release_file() {
  case "$1" in
    .htaccess|index.html|asset-manifest.json|robots.txt|site.webmanifest|404.html|favicon.ico|favicon.png|favicon-16x16.png|favicon-32x32.png|favicon-48x48.png|apple-touch-icon.png|android-chrome-192x192.png|android-chrome-512x512.png|android-chrome-maskable-192x192.png|android-chrome-maskable-512x512.png) return 0 ;;
    assets/css/*|assets/fonts/*|assets/images/*|assets/js/*|errors/[0-9][0-9][0-9].html) return 0 ;;
    *) return 1 ;;
  esac
}

collect_current_managed_files() {
  local path
  for path in .htaccess index.html asset-manifest.json robots.txt site.webmanifest 404.html \
    favicon.ico favicon.png favicon-16x16.png favicon-32x32.png favicon-48x48.png \
    apple-touch-icon.png android-chrome-192x192.png android-chrome-512x512.png \
    android-chrome-maskable-192x192.png android-chrome-maskable-512x512.png; do
    [[ ! -f "$target/$path" ]] || printf '%s\n' "$path"
  done
  for path in assets/css assets/fonts assets/images assets/js errors; do
    [[ ! -d "$target/$path" ]] || find "$target/$path" -type f -printf '%P\n' | sed "s#^#$path/#"
  done
}

write_atomically() {
  local source="$1" relative="$2" destination temporary
  destination="$target/$relative"
  mkdir -p "$(dirname "$destination")"
  temporary="$(dirname "$destination")/.dtb-next-$(basename "$destination")-$release"
  install -m 644 "$source" "$temporary"
  mv -f "$temporary" "$destination"
}

restore_snapshot() {
  local prior_file current_file prior_release
  local previous_list="$snapshot/previous-files.txt"
  local release_list="$record/files.txt"
  [[ -d "$snapshot/files" && -f "$previous_list" && -f "$release_list" ]] || {
    echo "No complete production frontend snapshot for $release" >&2
    return 1
  }
  prior_release="$(cat "$snapshot/previous-release.txt")"
  # Restore prior public files except index.html; visitors keep using the current
  # shell until its matching assets, manifest, and rewrite rules are back.
  while IFS= read -r prior_file; do
    [[ -n "$prior_file" && "$prior_file" != index.html ]] || continue
    write_atomically "$snapshot/files/$prior_file" "$prior_file"
  done < "$previous_list"
  # Remove only files introduced by this release and absent from the snapshot.
  while IFS= read -r current_file; do
    [[ -n "$current_file" ]] || continue
    if ! grep -Fqx -- "$current_file" "$previous_list"; then
      rm -f -- "$target/$current_file"
    fi
  done < "$release_list"
  [[ -f "$snapshot/files/index.html" ]] || { echo "Snapshot has no previous index.html" >&2; return 1; }
  write_atomically "$snapshot/files/index.html" index.html
  printf '%s\n' "$prior_release" > "$private/.next-current-release"
  mv -f "$private/.next-current-release" "$state"
}

prune_old_snapshots() {
  local rank=0 candidate name
  while read -r _ candidate; do
    [[ -n "$candidate" ]] || continue
    name="${candidate##*/}"
    [[ "$name" =~ ^[a-f0-9]{40}$ && ! -L "$candidate" ]] || continue
    rank=$((rank + 1))
    if (( rank > 3 )); then
      rm -rf -- "$candidate"
      rm -rf -- "$release_records/$name"
    fi
  done < <(find "$snapshots" -mindepth 1 -maxdepth 1 -type d \
    -name '????????????????????????????????????????' -printf '%T@ %p\n' | sort -rn)
}

if [[ "$action" == "prepare" ]]; then
  [[ ! -e "$incoming" ]] || { echo "Incoming release already exists" >&2; exit 2; }
  mkdir -m 700 "$incoming"
  exit 0
fi

[[ "$action" == "publish" || "$action" == "rollback" || "$action" == "verify" ]] ||
  { echo "Unsupported production frontend action" >&2; exit 2; }
if [[ "$action" == "verify" ]]; then
  [[ -s "$record/sha256.txt" ]] || { echo "No release checksum record" >&2; exit 2; }
  (cd "$target" && sha256sum -c "$record/sha256.txt")
  exit 0
fi

mkdir "$lock" 2>/dev/null || { echo "Production frontend lock held; inspect before clearing" >&2; exit 3; }
trap 'rmdir "$lock" 2>/dev/null || true' EXIT

current=""
[[ ! -f "$state" ]] || current="$(cat "$state")"
[[ -z "$current" || "$current" =~ ^[a-f0-9]{40}$ ]] || { echo "Invalid release state" >&2; exit 2; }

if [[ "$action" == "rollback" ]]; then
  [[ "$current" == "$release" ]] || { echo "Rollback refused: requested release is not current" >&2; exit 4; }
  restore_snapshot
  echo "Rolled back production storefront release: $release"
  exit 0
fi

[[ "$current" != "$release" ]] || { echo "Already published: $release"; exit 0; }
[[ -d "$incoming" && -s "$incoming/index.html" && -s "$incoming/.htaccess" &&
   -s "$incoming/asset-manifest.json" && -s "$incoming/site.webmanifest" &&
   -s "$incoming/robots.txt" && -s "$incoming/.dtb-file-sha256" ]] ||
  { echo "Incomplete production frontend payload" >&2; exit 2; }
[[ ! -e "$snapshot" && ! -e "$record" ]] || { echo "Release snapshot/record already exists; refusing ambiguous retry" >&2; exit 2; }
[[ ! -L "$incoming" ]] || { echo "Incoming release may not be a symlink" >&2; exit 2; }
if find "$incoming" -type l -print -quit | grep -q .; then
  echo "Symlinks are forbidden in production frontend payloads" >&2
  exit 2
fi
(cd "$incoming" && sha256sum -c .dtb-file-sha256 >/dev/null) ||
  { echo "Production frontend payload checksum failed" >&2; exit 2; }
grep -Fq 'RewriteRule ^$ index.html [L]' "$incoming/.htaccess" || { echo "Root homepage rewrite missing" >&2; exit 2; }
grep -Fq 'RewriteRule ^ index.html [QSA,L]' "$incoming/.htaccess" || { echo "SPA fallback missing" >&2; exit 2; }
grep -Fq 'payment_intent=pi_' "$incoming/.htaccess" || { echo "WooPayments return routing missing" >&2; exit 2; }
if grep -Fq 'X-Robots-Tag "noindex, nofollow"' "$incoming/.htaccess"; then
  echo "Production payload is marked noindex" >&2
  exit 2
fi

mkdir -p -m 700 "$snapshot/files" "$record"
printf '%s\n' "$current" > "$snapshot/previous-release.txt"
collect_current_managed_files | sort -u > "$snapshot/previous-files.txt"
grep -Fqx '.htaccess' "$snapshot/previous-files.txt" &&
  grep -Fqx 'index.html' "$snapshot/previous-files.txt" || {
    echo "Existing root must contain .htaccess and index.html before release" >&2
    exit 2
  }
while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  is_release_file "$path" || { echo "Unexpected preexisting managed path: $path" >&2; exit 2; }
  mkdir -p "$snapshot/files/$(dirname "$path")"
  cp -p -- "$target/$path" "$snapshot/files/$path"
done < "$snapshot/previous-files.txt"

find "$incoming" -type f ! -name .dtb-file-sha256 -printf '%P\n' | sort > "$record/files.txt"
while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  is_release_file "$path" || { echo "Payload path outside production storefront allowlist: $path" >&2; exit 2; }
done < "$record/files.txt"
cp "$incoming/.dtb-file-sha256" "$record/sha256.txt"

recover_partial_publish() {
  echo "Publish failed; restoring previous production storefront files" >&2
  restore_snapshot || echo "Automatic restoration failed; use the Updraft full-site backup" >&2
}
trap 'recover_partial_publish; rmdir "$lock" 2>/dev/null || true' ERR

# Copy content-addressed assets and static error files first. No public_html
# directories are synchronized with --delete; WordPress and shared media survive.
while IFS= read -r path; do
  case "$path" in
    .htaccess|index.html|asset-manifest.json|robots.txt|site.webmanifest) continue ;;
  esac
  write_atomically "$incoming/$path" "$path"
done < "$record/files.txt"

# Switch the root routing/cache contract and metadata after assets are present.
for path in .htaccess site.webmanifest asset-manifest.json robots.txt; do
  write_atomically "$incoming/$path" "$path"
done

# index.html is the storefront activation point and is replaced last.
write_atomically "$incoming/index.html" index.html
printf '%s\n' "$release" > "$private/.next-current-release"
mv -f "$private/.next-current-release" "$state"
trap 'rmdir "$lock" 2>/dev/null || true' ERR

prune_old_snapshots || echo "Warning: unable to prune older frontend snapshots" >&2
rm -rf -- "$incoming"
echo "Published production storefront release: $release"
