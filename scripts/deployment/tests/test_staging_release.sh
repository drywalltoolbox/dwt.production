#!/usr/bin/env bash
# Non-production regression test for publish and SHA-free rollback.
set -euo pipefail

script="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/staging-release.sh"
sandbox="$(mktemp -d)"
trap 'rm -rf "$sandbox"' EXIT
target="$sandbox/site/public_html/staging"
private="$sandbox/site/releases"
old_sha="1111111111111111111111111111111111111111"
new_sha="2222222222222222222222222222222222222222"
mkdir -p "$target" "$private/incoming/$new_sha"
printf '<html>old</html>\n' > "$target/index.html"
printf '{"old":true}\n' > "$target/asset-manifest.json"
printf 'old asset\n' > "$target/obsolete.js"
printf '%s\n' "$old_sha" > "$private/current-release"

incoming="$private/incoming/$new_sha"
printf '<html>new</html>\n' > "$incoming/index.html"
printf '{"new":true}\n' > "$incoming/asset-manifest.json"
printf 'new asset\n' > "$incoming/new.js"
printf 'Options -Indexes\n' > "$incoming/.htaccess"
printf '{}\n' > "$incoming/site.webmanifest"
(cd "$incoming"; find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum) > "$incoming/.dtb-file-sha256"

bash "$script" publish "$target" "$private" "$new_sha"
[[ "$(cat "$private/current-release")" == "$new_sha" ]]
[[ "$(cat "$target/index.html")" == "<html>new</html>" ]]
[[ -f "$target/new.js" && ! -e "$target/obsolete.js" ]]
bash "$script" rollback "$target" "$private" auto
[[ "$(cat "$private/current-release")" == "$old_sha" ]]
[[ "$(cat "$target/index.html")" == "<html>old</html>" ]]
[[ -f "$target/obsolete.js" && ! -e "$target/new.js" ]]
if bash "$script" rollback "$target" "$private" auto; then
  echo "Unexpected successful rollback without a previous snapshot" >&2
  exit 1
fi
printf 'Staging publish + automatic rollback regression passed.\n'
