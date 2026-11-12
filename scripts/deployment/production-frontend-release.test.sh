#!/usr/bin/env bash
set -euo pipefail

script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/production-frontend-release.sh"
temporary="$(mktemp -d)"
trap 'rm -rf -- "$temporary"' EXIT
site="$temporary/site"
target="$site/public_html"
private="$site/private/frontend-releases"
mkdir -p "$target/assets/email" "$target/assets/js" "$target/wp" "$target/staging" \
  "$target/brands/Asgard/Schematics/AT01" "$target/brands/Columbia/Logos"
printf 'signup shell\n' > "$target/index.html"
printf 'old rewrite contract\n' > "$target/.htaccess"
printf 'old llms file\n' > "$target/llms.txt"
printf 'old email asset\n' > "$target/assets/email/logo.png"
printf 'old app asset\n' > "$target/assets/js/legacy.js"
printf 'wordpress runtime\n' > "$target/wp/index.php"
printf 'staging app\n' > "$target/staging/index.html"
printf 'original schematic data\n' > "$target/brands/Asgard/Schematics/AT01/schematic_data.json"
printf 'unmanaged brand logo\n' > "$target/brands/Columbia/Logos/columbia.svg"

stage_release() {
  local release="$1" version="$2" payload="$temporary/artifact-$1"
  mkdir -p "$payload/assets/js" "$payload/assets/css" "$payload/errors" "$payload/brands/Asgard/Schematics/AT01"
  printf 'React storefront %s\n' "$version" > "$payload/index.html"
  printf 'production rewrite payment_intent=pi_\nHeader always set Strict-Transport-Security "max-age=31536000"\nRewriteRule ^$ index.html [L]\nRewriteRule ^products/?$ index.html [QSA,L]\nRewriteRule ^products/([a-zA-Z0-9_-]+)(/variations/[0-9]+)?/?$ wp/index.php [QSA,L]\n' > "$payload/.htaccess"
  printf '{"files":{}}\n' > "$payload/asset-manifest.json"
  printf '# Drywall Toolbox %s\n' "$version" > "$payload/llms.txt"
  printf '{"start_url":"/"}\n' > "$payload/site.webmanifest"
  printf 'User-agent: *\nAllow: /\n' > "$payload/robots.txt"
  printf 'release asset %s\n' "$version" > "$payload/assets/js/main.$version.js"
  printf 'release css %s\n' "$version" > "$payload/assets/css/main.$version.css"
  printf 'not found %s\n' "$version" > "$payload/errors/404.html"
  printf 'error css %s\n' "$version" > "$payload/errors/error.css"
  printf 'error page script %s\n' "$version" > "$payload/errors/error-page.js"
  printf 'brand mark %s\n' "$version" > "$payload/errors/dtb-logo-black.webp"
  printf 'schematic data %s\n' "$version" > "$payload/brands/Asgard/Schematics/AT01/schematic_data.json"
  if [[ "$version" == "v2" ]]; then
    mkdir -p "$payload/brands/Columbia/Schematics/CT01"
    printf 'new schematic data %s\n' "$version" > "$payload/brands/Columbia/Schematics/CT01/schematic_data.json"
  fi
  (cd "$payload" && find . -type f ! -name .dtb-file-sha256 -print0 | sort -z | xargs -0 sha256sum > .dtb-file-sha256)
  bash "$script" prepare "$target" "$private" "$release"
  bash "$script" prepare "$target" "$private" "$release"
  cp -a "$payload/." "$private/incoming/$release/"
}

first="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
second="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
stage_release "$first" v1
bash "$script" publish "$target" "$private" "$first"
[[ "$(cat "$target/index.html")" == 'React storefront v1' ]]
[[ "$(cat "$target/llms.txt")" == '# Drywall Toolbox v1' ]]
[[ "$(cat "$target/wp/index.php")" == 'wordpress runtime' ]]
[[ "$(cat "$target/staging/index.html")" == 'staging app' ]]
[[ "$(cat "$target/brands/Asgard/Schematics/AT01/schematic_data.json")" == 'schematic data v1' ]]
[[ "$(cat "$target/brands/Columbia/Logos/columbia.svg")" == 'unmanaged brand logo' ]]
[[ "$(cat "$target/assets/email/logo.png")" == 'old email asset' ]]
[[ -f "$target/assets/js/main.v1.js" && -f "$target/assets/css/main.v1.css" ]]
[[ -f "$target/errors/error.css" && -f "$target/errors/error-page.js" && -f "$target/errors/dtb-logo-black.webp" ]]
bash "$script" verify "$target" "$private" "$first" >/dev/null

stage_release "$second" v2
bash "$script" publish "$target" "$private" "$second"
[[ "$(cat "$target/index.html")" == 'React storefront v2' ]]
[[ "$(cat "$target/llms.txt")" == '# Drywall Toolbox v2' ]]
[[ "$(cat "$target/brands/Asgard/Schematics/AT01/schematic_data.json")" == 'schematic data v2' ]]
[[ -f "$target/brands/Columbia/Schematics/CT01/schematic_data.json" ]]
[[ -f "$target/assets/js/main.v1.js" && -f "$target/assets/js/main.v2.js" ]]
[[ -f "$target/errors/dtb-logo-black.webp" ]]

bash "$script" rollback "$target" "$private" "$second"
[[ "$(cat "$target/index.html")" == 'React storefront v1' ]]
[[ "$(cat "$target/llms.txt")" == '# Drywall Toolbox v1' ]]
[[ "$(cat "$target/brands/Asgard/Schematics/AT01/schematic_data.json")" == 'schematic data v1' ]]
[[ ! -e "$target/brands/Columbia/Schematics/CT01/schematic_data.json" ]]
[[ -f "$target/assets/js/main.v1.js" && ! -f "$target/assets/js/main.v2.js" ]]
[[ -f "$target/errors/error.css" && -f "$target/errors/error-page.js" && -f "$target/errors/dtb-logo-black.webp" ]]
[[ "$(cat "$target/assets/email/logo.png")" == 'old email asset' ]]
[[ "$(cat "$target/brands/Columbia/Logos/columbia.svg")" == 'unmanaged brand logo' ]]
[[ "$(cat "$target/wp/index.php")" == 'wordpress runtime' ]]
[[ "$(cat "$target/staging/index.html")" == 'staging app' ]]
bash "$script" verify "$target" "$private" "$first" >/dev/null

bash "$script" rollback "$target" "$private" "$first"
[[ "$(cat "$target/index.html")" == 'signup shell' ]]
[[ "$(cat "$target/llms.txt")" == 'old llms file' ]]
[[ "$(cat "$target/.htaccess")" == 'old rewrite contract' ]]
[[ "$(cat "$target/brands/Asgard/Schematics/AT01/schematic_data.json")" == 'original schematic data' ]]
[[ "$(cat "$target/assets/email/logo.png")" == 'old email asset' ]]
[[ "$(cat "$target/wp/index.php")" == 'wordpress runtime' ]]
[[ "$(cat "$target/staging/index.html")" == 'staging app' ]]
printf 'Production frontend release and rollback checks passed.\n'
