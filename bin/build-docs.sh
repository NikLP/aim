#!/usr/bin/env bash
# Build the docs site into public/. Source docs stay next to the code; they
# are copied (not symlinked) into docs/ so relative cross-links resolve.
# Internal notes (CLAUDE.md, TODO.md, TESTS.md) are deliberately not copied.
set -euo pipefail
cd "$(dirname "$0")/.."

cp README.md DEVELOPING.md docs/
for dir in modules/aim_*/ recipes/aim_*/; do
  mkdir -p "docs/$dir"
  for f in README.md DEVELOPING.md; do
    [ -f "$dir$f" ] && cp "$dir$f" "docs/$dir"
  done
done
mkdir -p docs/adr
cp -r adr/. docs/adr/

mkdocs build --site-dir public
