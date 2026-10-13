#!/usr/bin/env bash
##
# Release gate: checks merge requests and tags against Drupal.org milestones.
#
#   release-gate.sh mr   Fails when the MR names no Drupal.org work item, or when
#                        an MR that changes shipped files names a work item that
#                        isn't in the next release of the target branch's major.
#   release-gate.sh tag  Fails when no milestone is named like the tag, or when
#                        that milestone still has open work items.
#
# It reads the public Drupal.org API, so it needs no token and gives the same
# answer on Drupal.org, gitlab.local and GitHub. Nothing is set per branch.
#
# It doesn't check that every commit names a planned work item, that a patch
# release has no breaking change, or where a tag was cut, and it doesn't write
# release notes. Those are part of the release checklist. For the notes, run
# `maintainer:release-notes <previous tag> <tag>` from the Drupal.org CLI.
# The name has no hook prefix, so the scaffold never runs it as a hook.

set -eu

api="${_RELEASE_GATE_API:-https://git.drupalcode.org/api/v4}"
project="${_RELEASE_GATE_PROJECT:?Set _RELEASE_GATE_PROJECT, for example project/jsonapi_menu_items}"
base_url="${api%/api/v4}/${project}"
project_api="${api}/projects/${project//\//%2F}"
body="$(mktemp)"
trap 'rm -f "$body"' EXIT

# Fetches an API path into $body and prints the HTTP status. curl retries
# timeouts, 429 and 5xx, so a status other than 200 or 404 is a real failure.
get() {
  # On a connection failure curl prints 000 itself and exits non-zero.
  curl -s --retry 3 --retry-connrefused -o "$body" -w '%{http_code}' "${project_api}/$1" || true
}

# Prints the X-Total header for an API path, or nothing when it can't be read.
count() {
  curl -s --retry 3 --retry-connrefused -o /dev/null -D - "${project_api}/$1&per_page=1" |
    tr -d '\r' | sed -n 's/^x-total: *//Ip'
}

# Prints the major version of a branch, tag or milestone name, or nothing.
# 1.2.x, 1.3.0 and 2.x give 1, 1 and 2. The old 8.x-1.x form gives 1, because
# the 8 is the core version.
major() {
  if [[ $1 =~ ^[0-9]+\.x-([0-9]+)(\.|$) ]] || [[ $1 =~ ^([0-9]+)(\.|$) ]]; then
    echo "${BASH_REMATCH[1]}"
  fi
}

# Prints "milestone<TAB>milestone state" for the work item JSON in $body.
milestone_of() {
  # shellcheck disable=SC2016 # The PHP code is meant to stay single-quoted.
  php -r '$i = json_decode(file_get_contents($argv[1]), TRUE); echo ($i["milestone"]["title"] ?? ""), "\t", ($i["milestone"]["state"] ?? ""), "\n";' "$body"
}

# Prints the titles of active milestones that have work items, open or closed.
# Closed items count, so a milestone whose items are all done is still the next
# release until it is tagged and closed.
# shellcheck disable=SC2016 # The PHP code is meant to stay single-quoted.
planned_milestones() {
  local page=1 status
  while :; do
    status="$(get "issues?milestone=Any&state=all&per_page=100&page=${page}")"
    [ "$status" = 200 ] || return 1
    php -r 'foreach (json_decode(file_get_contents($argv[1]), TRUE) as $i) { if (($i["milestone"]["state"] ?? "") === "active") { echo $i["milestone"]["title"], "\n"; } }' "$body"
    [ "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), TRUE));' "$body")" -eq 100 ] || return 0
    page=$((page + 1))
  done
}

# Succeeds when a path is export-ignored. .gitattributes marks folders such as
# `tests`, and git check-attr doesn't pass a folder's attribute down to the
# files in it, so each parent folder is checked too.
ignored() {
  local path="$1"
  while [ "$path" != . ] && [ "$path" != / ]; do
    git check-attr export-ignore -- "$path" | grep -q ': set$' && return 0
    path="$(dirname "$path")"
  done
  return 1
}

# Succeeds when the MR changes a file that is not export-ignored. It diffs the
# MR's own commit, because a merged-result pipeline's HEAD also holds the
# target branch's changes. Without a diff base every MR counts as shipping, so
# the check errs on the strict side.
ships() {
  local base="${CI_MERGE_REQUEST_DIFF_BASE_SHA:-}" head="${CI_MERGE_REQUEST_SOURCE_BRANCH_SHA:-HEAD}" file
  [ -n "$base" ] && git cat-file -e "${base}^{commit}" 2>/dev/null || return 0
  while IFS= read -r file; do
    [ -n "$file" ] || continue
    ignored "$file" || return 0
  done < <(git diff --name-only "$base" "$head")
  return 1
}

check_mr() {
  local text ids id status title state target target_major item_major next
  local found=0 unreadable="" result=0 milestones=""
  text="${CI_MERGE_REQUEST_TITLE:-} ${CI_MERGE_REQUEST_DESCRIPTION:-}"
  ids="$(grep -oE '(#|/work_items/|/issues/|/i/)[0-9]{6,8}\b' <<<"$text" | grep -oE '[0-9]+' | sort -u || true)"
  target="${CI_MERGE_REQUEST_TARGET_BRANCH_NAME:-}"
  target_major="$(major "$target")"

  for id in $ids; do
    status="$(get "issues/${id}")"
    case "$status" in
      200) ;;
      404) echo "NOTE: #${id} is not a work item in ${project}, so it is ignored."; continue ;;
      *) unreadable="${unreadable} #${id} (HTTP ${status})"; continue ;;
    esac
    found=1
    IFS=$'\t' read -r title state < <(milestone_of)
    echo "#${id}: milestone ${title:-none}"
    milestones="${milestones}${id}"$'\t'"${title}"$'\t'"${state}"$'\n'
  done

  if [ -n "$unreadable" ]; then
    echo "FAIL: could not read${unreadable} from the Drupal.org API. Run the job again."
    return 1
  fi
  if [ "$found" = 0 ]; then
    echo "FAIL: this MR doesn't name a Drupal.org work item."
    echo "Put the id in the title, for example 'fix(#3591566): ...', or 'Closes #3591566' in the description."
    return 1
  fi
  if ! ships; then
    echo "Only export-ignored files change, so the milestone isn't checked."
    return 0
  fi

  # The next release of the target branch's major is the lowest active
  # milestone with that major, among planned work items and the ones named here.
  if [ -n "$target_major" ]; then
    if ! next="$(planned_milestones)"; then
      echo "FAIL: could not read the planned work items from the Drupal.org API. Run the job again."
      return 1
    fi
    next="$( { echo "$next"; awk -F'\t' '$3 == "active" { print $2 }' <<<"$milestones"; } |
      while IFS= read -r title; do [ -n "$title" ] && [ "$(major "$title")" = "$target_major" ] && echo "$title"; done |
      sort -uV | head -n 1 || true)"
  fi

  while IFS=$'\t' read -r id title state; do
    [ -n "$id" ] || continue
    item_major="$(major "$title")"
    if [ -z "$title" ]; then
      echo "FAIL: #${id} has no milestone, so no release plans for it."
    elif [ "$state" != active ]; then
      echo "FAIL: #${id} is in ${title}, which is closed."
    elif [ -n "$target_major" ] && [ -n "$item_major" ] && [ "$item_major" != "$target_major" ]; then
      echo "FAIL: #${id} is in ${title}, but ${target} is the ${target_major}.x line."
    elif [ -n "${next:-}" ] && [ "$title" != "$next" ]; then
      echo "FAIL: #${id} is in ${title}, but ${next} is the next ${target_major}.x release."
    else
      continue
    fi
    result=1
  done <<<"$milestones"
  return "$result"
}

check_tag() {
  local tag="${CI_COMMIT_TAG:?CI_COMMIT_TAG is not set}" total open
  total="$(count "issues?milestone=${tag}&state=all")"
  open="$(count "issues?milestone=${tag}&state=opened")"
  if [ -z "$total" ] || [ -z "$open" ]; then
    echo "FAIL: could not read the ${tag} milestone from the Drupal.org API. Run the job again."
    return 1
  fi
  if [ "$total" = 0 ]; then
    echo "FAIL: no milestone named ${tag} has any work items, so nothing planned this release."
    echo "Delete the tag and don't create the release."
    return 1
  fi
  if [ "$open" != 0 ]; then
    echo "FAIL: ${open} work item(s) are still open in ${tag}: ${base_url}/-/issues?milestone_title=${tag}&state=opened"
    echo "Delete the tag and don't create the release."
    return 1
  fi
  echo "All ${total} work items in ${tag} are closed."
}

case "${1:-}" in
  mr) check_mr ;;
  tag) check_tag ;;
  *) echo "Usage: $0 mr|tag" >&2; exit 2 ;;
esac
