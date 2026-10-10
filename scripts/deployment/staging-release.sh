#!/usr/bin/env bash
# Staging-only SiteGround release operations. Runs over an authenticated SSH channel.
# No production publish mode is supported.
set -euo pipefail
umask 077

action="${1:?action required}"
target="${2:?absolute staging webroot required}"
private="${3:?absolute private release root required}"
release="${4:?release identifier required}"

[[ "$release" =~ ^[a-f0-9]{40}$ || ( "$action" == "rollback" && "$release" == "auto" ) ]] || { echo "Invalid release identity" >&2; exit 2; }
[[ "$target" = /* && "$private" = /* ]] || { echo "Absolute paths required" >&2; exit 2; }
[[ -d "$target" && -d "$private" ]] || { echo "Preexisting target and private directories required" >&2; exit 2; }
target="$(cd "$target" && pwd -P)"
private="$(cd "$private" && pwd -P)"
[[ "${target##*/}" == "staging" && "${target%/*}" == */public_html ]] ||
  { echo "Target must be the staging directory directly under public_html" >&2; exit 2; }
case "$private/" in
  "${target%/*}/"*) echo "Private release root must be outside public_html" >&2; exit 2 ;;
esac
[[ "$private" != "$target" && "$private" != "$target/"* ]] || exit 2
[[ ! -L "$target" && ! -L "$private" ]] || { echo "Symlinked roots forbidden" >&2; exit 2; }

incoming="$private/incoming/$release"
snapshots="$private/snapshots"
state="$private/current-release"
lock="$private/.staging-release-lock"
mkdir -p "$private/incoming" "$snapshots"

if [[ "$action" == "prepare" ]]; then
  # Incoming content is uploaded separately, outside the webroot.
  [[ ! -e "$incoming" ]] || { echo "Incoming release already exists" >&2; exit 2; }
  mkdir -m 700 "$incoming"
  exit 0
fi

[[ "$action" == "publish" || "$action" == "rollback" ]] ||
  { echo "Unsupported staging action" >&2; exit 2; }
mkdir "$lock" 2>/dev/null ||
  { echo "Staging lock held; inspect before manually clearing" >&2; exit 3; }
trap 'rmdir "$lock" || true' EXIT

current=""
[[ ! -f "$state" ]] || current="$(cat "$state")"
[[ -z "$current" || "$current" =~ ^[a-f0-9]{40}$ ]] ||
  { echo "Invalid release state" >&2; exit 2; }
if [[ "$action" == "rollback" && "$release" == "auto" ]]; then
  [[ -n "$current" ]] || { echo "No current staging release to roll back" >&2; exit 4; }
  release="$current"
fi

if [[ "$action" == "publish" ]]; then
  if [[ "$current" == "$release" ]]; then
    echo "Already published: $release"
    exit 0
  fi
  [[ -d "$incoming" && -s "$incoming/index.html" && -s "$incoming/asset-manifest.json" &&
     -s "$incoming/.htaccess" && -s "$incoming/site.webmanifest" &&
     -s "$incoming/.dtb-file-sha256" ]] ||
    { echo "Incomplete incoming release" >&2; exit 2; }
  (cd "$incoming" && sha256sum -c .dtb-file-sha256 >/dev/null) ||
    { echo "Incoming release hash verification failed" >&2; exit 2; }
  [[ ! -e "$snapshots/$release" ]] ||
    { echo "Release snapshot already exists; refuse ambiguous retry" >&2; exit 2; }
  mkdir -m 700 "$snapshots/$release"
  cp -a "$target/." "$snapshots/$release/"
  printf '%s\n' "$current" > "$snapshots/$release/.dtb-previous-release"
  # Restore the snapshot if publication fails after modifying any staging file.
  recover_partial_publish() {
    local saved="$snapshots/$release"
    echo "Publish failed; attempting staging restoration" >&2
    rsync -a --delete --exclude '/index.html' --exclude '/asset-manifest.json' \
      --exclude '/.dtb-previous-release' "$saved/" "$target/" || true
    for mutable in asset-manifest.json index.html; do
      if [[ -f "$saved/$mutable" ]]; then
        cp -f "$saved/$mutable" "$target/$mutable" || true
      else
        rm -f "$target/$mutable" || true
      fi
    done
  }
  trap 'recover_partial_publish' ERR
  # Stage is a build-owned mirror: delete stale assets and files, then atomically swap
  # the manifest and index to limit exposure to partially published entrypoints.
  # Existing browser tabs referencing old hashed assets may need to reload.
  rsync -a --delete --exclude '/index.html' --exclude '/asset-manifest.json' \
    --exclude '/.dtb-file-sha256' "$incoming/" "$target/"
  rm -f "$target/.dtb-file-sha256"
  install -m 644 "$incoming/asset-manifest.json" "$target/.dtb-next-manifest"
  mv -f "$target/.dtb-next-manifest" "$target/asset-manifest.json"
  install -m 644 "$incoming/index.html" "$target/.dtb-next-index"
  mv -f "$target/.dtb-next-index" "$target/index.html"
  printf '%s\n' "$release" > "$private/.next-current-release"
  mv -f "$private/.next-current-release" "$state"
  trap - ERR
  echo "Published staging release: $release"
else
  [[ "$current" == "$release" ]] ||
    { echo "Rollback refused: release is not current" >&2; exit 4; }
  backup="$snapshots/$release"
  [[ -d "$backup" && -f "$backup/.dtb-previous-release" ]] ||
    { echo "No verified rollback snapshot" >&2; exit 4; }
  previous="$(cat "$backup/.dtb-previous-release")"
  [[ -z "$previous" || "$previous" =~ ^[a-f0-9]{40}$ ]] || exit 4
  # Restore the exact prior staging snapshot; remove files from the newer release.
  rsync -a --delete --exclude '/index.html' --exclude '/asset-manifest.json' \
    --exclude '/.dtb-previous-release' "$backup/" "$target/"
  for mutable in asset-manifest.json index.html; do
    if [[ -f "$backup/$mutable" ]]; then
      install -m 644 "$backup/$mutable" "$target/.dtb-rollback-$mutable"
      mv -f "$target/.dtb-rollback-$mutable" "$target/$mutable"
    else
      echo "Snapshot missing critical $mutable; manual recovery required" >&2
      exit 5
    fi
  done
  printf '%s\n' "$previous" > "$private/.next-current-release"
  mv -f "$private/.next-current-release" "$state"
  echo "Rolled back staging release: $release"
fi
