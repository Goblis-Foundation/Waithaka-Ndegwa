#!/usr/bin/env bash
set -euo pipefail

PERSONAL_OWNER="iwaithakaw"
ORG_OWNER="Goblis-Foundation"
LEGACY_REPO="${PERSONAL_OWNER}/WaithakaNdegwa"
RENAMED_PERSONAL_REPO="${PERSONAL_OWNER}/Waithaka-Ndegwa"
TARGET_REPO="${ORG_OWNER}/Waithaka-Ndegwa"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTER="$(cd "$ROOT/.." && pwd)"
HISTORY_BUNDLE="$OUTER/history/recovery-and-implementation.bundle"

fail(){ echo "ERROR: $*" >&2; exit 1; }
command -v gh >/dev/null 2>&1 || fail "GitHub CLI (gh) is required. Install it from the official GitHub CLI package repository, authenticate, then rerun."
gh auth status --hostname github.com >/dev/null 2>&1 || fail "Authenticate first with: gh auth login --hostname github.com --git-protocol https --web"

wait_for_repo(){
  local repo="$1"
  local i
  for i in $(seq 1 30); do
    if gh repo view "$repo" >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  return 1
}

if ! gh repo view "$TARGET_REPO" >/dev/null 2>&1; then
  SOURCE_REPO=""
  if gh repo view "$RENAMED_PERSONAL_REPO" >/dev/null 2>&1; then
    SOURCE_REPO="$RENAMED_PERSONAL_REPO"
  elif gh repo view "$LEGACY_REPO" >/dev/null 2>&1; then
    SOURCE_REPO="$LEGACY_REPO"
  fi

  [[ -n "$SOURCE_REPO" ]] || fail "Neither $TARGET_REPO nor a transferable Waithaka repository under $PERSONAL_OWNER is accessible."

  echo "Transferring $SOURCE_REPO -> $TARGET_REPO"
  gh api \
    --method POST \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "/repos/${SOURCE_REPO}/transfer" \
    -f new_owner="$ORG_OWNER" \
    -f new_name="Waithaka-Ndegwa" >/dev/null

  wait_for_repo "$TARGET_REPO" || fail "GitHub accepted the transfer request but $TARGET_REPO did not become accessible within the expected window. Check organization transfer approval/settings, then rerun."
fi

REPO_ID="$(gh api "repos/$TARGET_REPO" --jq .id)"
REPO_OWNER_ID="$(gh api "orgs/$ORG_OWNER" --jq .id)"
[[ "$REPO_ID" =~ ^[0-9]+$ ]] || fail "Could not resolve the GitHub repository ID."
[[ "$REPO_OWNER_ID" =~ ^[0-9]+$ ]] || fail "Could not resolve the Goblis Foundation organization ID."
echo "Repository: $TARGET_REPO"
echo "Repository ID: $REPO_ID"
echo "Repository owner ID: $REPO_OWNER_ID"

export GITHUB_REPOSITORY_ID="$REPO_ID"
export GITHUB_REPOSITORY_OWNER_ID="$REPO_OWNER_ID"
export GITHUB_REPOSITORY_FULL_NAME="$TARGET_REPO"
export GITHUB_DEPLOY_BRANCH="main"
bash "$ROOT/deploy/aws/bootstrap-github-oidc.sh"

gh auth setup-git >/dev/null
[[ -f "$HISTORY_BUNDLE" ]] || fail "Recovery/implementation Git bundle is missing from this release."

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
git clone -q "$HISTORY_BUNDLE" "$WORK/repo"
cd "$WORK/repo"
git checkout -q main

# Overlay the reviewed release source while preserving the recovered implementation history.
rsync -a --delete --exclude='.git/' "$ROOT/" "$WORK/repo/"
git config user.name "Waithaka Ndegwa"
git config user.email "157243149+iwaithakaw@users.noreply.github.com"
git add -A
if ! git diff --cached --quiet; then
  git commit -m "Move deployment automation under Goblis Foundation organization" >/dev/null
fi

git remote remove origin 2>/dev/null || true
git remote add origin "https://github.com/${TARGET_REPO}.git"
if git ls-remote --exit-code origin refs/heads/main >/dev/null 2>&1; then
  fail "Remote main already has history. No force push was attempted. Review the remote before publishing."
fi

git push -u origin main

echo
echo "Repository transferred/published with recovered history. The push to main will start CI and production deployment."
echo "Follow it with: gh run list -R $TARGET_REPO --limit 10"
