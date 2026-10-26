#!/usr/bin/env bash
set -euo pipefail

url="${1:?staging URL required}"
for attempt in 1 2 3; do
  headers="$(mktemp)"
  body="$(mktemp)"
  curl_exit=0
  status="$(curl --silent --show-error --location --connect-timeout 10 --max-time 30 \
    --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$url")" || curl_exit=$?

  if [[ "$curl_exit" -ne 0 || "$status" == 000 ]]; then
    echo "Staging TLS/transport probe failed (attempt $attempt/3, curl $curl_exit, HTTP ${status:-000})." >&2
    rm -f "$headers" "$body"
    if [[ "$attempt" -lt 3 ]]; then
      sleep "$((attempt * 5))"
      continue
    fi
    echo "Staging transport probe failed after 3 attempts." >&2
    exit 2
  fi

  if [[ "$status" != 200 ]]; then
    echo "Staging URL returned HTTP $status; expected 200." >&2
    cat "$headers" >&2
    rm -f "$headers" "$body"
    exit 2
  fi
  if ! grep -qi '<html' "$body"; then
    echo "Staging response is not HTML." >&2
    rm -f "$headers" "$body"
    exit 2
  fi
  if ! grep -qi 'x-robots-tag:.*noindex' "$headers"; then
    echo "Staging noindex header is missing." >&2
    rm -f "$headers" "$body"
    exit 2
  fi

  rm -f "$headers" "$body"
  echo "Staging HTTP shell and noindex response verified."
  exit 0
done

exit 2
