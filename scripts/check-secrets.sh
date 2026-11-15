#!/usr/bin/env bash
# Fails when secret files or key material are tracked in git. Run from anywhere; checks the repository root.
# The repository is public: this scan runs first in every CI run.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
files=$(git ls-files)

# 1. Forbidden file names
bad=$(printf '%s\n' "$files" | grep -E '(^|/)(\.env(\.[^/]+)?|app\.env|migrate\.env|cloudflare\.ini|auth\.json)$|(^|/)secrets/|\.(pem|key|dump)$' \
  | grep -vE '(^|/)\.env\.example$' || true)
if [ -n "$bad" ]; then
  echo "CHECK-SECRETS FAIL: forbidden files are tracked:" >&2
  printf '%s\n' "$bad" >&2
  fail=1
fi

# 2. Forbidden content (names of offending files only, never the matched values)
scan() {
  local label="$1" pattern="$2" hits
  hits=$(printf '%s\n' "$files" \
    | grep -vE '^(ops/env/[^/]*\.example|\.env\.example|\.planning/|scripts/check-secrets\.sh$|scripts/server-provision\.sh$|node_modules/|vendor/)' \
    | while IFS= read -r f; do [ -f "$f" ] && grep -IlE "$pattern" -- "$f" 2>/dev/null || true; done)
  if [ -n "$hits" ]; then
    echo "CHECK-SECRETS FAIL: $label found in:" >&2
    printf '%s\n' "$hits" >&2
    fail=1
  fi
}
scan "private key block" 'BEGIN (RSA|OPENSSH|EC) PRIVATE KEY'
scan "Laravel APP_KEY value" 'APP_KEY=base64:[A-Za-z0-9+/]{20,}'
scan "GitHub token" '(ghp_|github_pat_|gho_)[A-Za-z0-9_]{10,}'
scan "API key assignment" '^[[:space:]]*[A-Z_]*_API_KEY=[^[:space:]]+'

[ "$fail" = 0 ] || exit 1
echo "CHECK-SECRETS OK"
