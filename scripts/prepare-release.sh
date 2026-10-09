#!/usr/bin/env bash
set -euo pipefail
if [[ "$GITHUB_EVENT_NAME" == workflow_dispatch ]]; then
  [[ "$GITHUB_REF" == refs/heads/main ]] || { echo 'Manual releases must run from main.' >&2; exit 1; }
  git config user.name 'github-actions[bot]'
  git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
  git fetch origin main --tags
  # A retry of the same workflow run must build the same bump commit.
  PRIOR=$(git log origin/main --format='%H' --fixed-strings --grep="(release run ${GITHUB_RUN_ID})" -1)
  if [[ -n "$PRIOR" ]]; then
    git checkout --detach "$PRIOR"
  else
    git checkout -B main origin/main
    if [[ "$BUMP" != none ]]; then
      VERSION=$(python3 scripts/release.py bump "$BUMP")
      git add eventwillow-public.php
      git commit -m "Bump version to v${VERSION} (release run ${GITHUB_RUN_ID})"
      git push origin HEAD:main
    fi
  fi
  VERSION=$(python3 scripts/release.py version)
  TAG="v${VERSION}"
  if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
    [[ "$(git rev-parse "${TAG}^{commit}")" == "$(git rev-parse HEAD)" ]] || { echo 'Existing tag points to a different commit; bump the version.' >&2; exit 1; }
  else
    git tag "$TAG"
    git push origin "refs/tags/${TAG}"
  fi
else
  TAG="$GITHUB_REF_NAME"
  VERSION="${TAG#v}"
  python3 scripts/release.py version "$VERSION"
fi
printf 'version=%s\ntag=%s\n' "$VERSION" "$TAG" >> "$GITHUB_OUTPUT"
