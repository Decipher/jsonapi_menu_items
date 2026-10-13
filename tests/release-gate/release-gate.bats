#!/usr/bin/env bats
##
# Tests for scripts/release-gate.sh. A fake curl on PATH answers from fixture
# files, so the tests run offline. See bin/curl for the file names.

setup() {
  GATE="$BATS_TEST_DIRNAME/../../scripts/release-gate.sh"
  export FIXTURES="$BATS_TEST_TMPDIR/fixtures"
  export PATH="$BATS_TEST_DIRNAME/bin:$PATH"
  export _RELEASE_GATE_PROJECT=project/example
  export CI_MERGE_REQUEST_TARGET_BRANCH_NAME=1.2.x
  # Clear the CI variables the gate reads, so a test never sees the real job's.
  unset CI_MERGE_REQUEST_TITLE CI_MERGE_REQUEST_DESCRIPTION CI_MERGE_REQUEST_DIFF_BASE_SHA CI_MERGE_REQUEST_SOURCE_BRANCH_SHA CI_COMMIT_TAG
  mkdir -p "$FIXTURES"
  # The gate diffs against a base commit, so each test runs in its own repo.
  cd "$BATS_TEST_TMPDIR"
  git init -q repo
  cd repo
  printf 'tests export-ignore\n' >.gitattributes
  git add .gitattributes
  git -c user.name=test -c user.email=test@example.com commit -qm base
  # No planned work items unless a test adds some.
  echo '[]' >"$FIXTURES/planned-1.json"
}

# Writes a work item fixture: work_item <id> [milestone] [state].
work_item() {
  if [ -n "${2:-}" ]; then
    printf '{"iid":%s,"milestone":{"title":"%s","state":"%s"}}' "$1" "$2" "${3:-active}" >"$FIXTURES/issue-$1.json"
  else
    printf '{"iid":%s,"milestone":null}' "$1" >"$FIXTURES/issue-$1.json"
  fi
}

# Writes the planned work items list, one item per milestone, open or closed:
# planned_items <milestone> [<milestone>...].
planned_items() {
  local list="" title
  for title in "$@"; do
    list="${list:+$list,}{\"milestone\":{\"title\":\"$title\",\"state\":\"active\"}}"
  done
  echo "[$list]" >"$FIXTURES/planned-1.json"
}

# Commits a change to a file and sets the MR diff base to the commit before.
change() {
  CI_MERGE_REQUEST_DIFF_BASE_SHA="$(git rev-parse HEAD)"
  export CI_MERGE_REQUEST_DIFF_BASE_SHA
  mkdir -p "$(dirname "$1")"
  echo change >>"$1"
  git add "$1"
  git -c user.name=test -c user.email=test@example.com commit -qm change
}

@test "an MR that names no work item fails" {
  export CI_MERGE_REQUEST_TITLE="chore: tidy up"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"doesn't name a Drupal.org work item"* ]]
}

@test "a work item in the next release passes" {
  work_item 3000001 1.3.0
  planned_items 1.3.0 1.4.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 0 ]
}

@test "a work item URL in the description counts" {
  work_item 3000001 1.3.0
  export CI_MERGE_REQUEST_TITLE="fix: something"
  export CI_MERGE_REQUEST_DESCRIPTION="Closes https://git.drupalcode.org/project/example/-/work_items/3000001"
  run "$GATE" mr
  [ "$status" -eq 0 ]
}

@test "a work item with no milestone fails" {
  work_item 3000001
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"has no milestone"* ]]
}

@test "a work item in a closed milestone fails" {
  work_item 3000001 1.2.9 closed
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"which is closed"* ]]
}

@test "a work item for another major fails" {
  work_item 3000001 2.0.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"1.2.x is the 1.x line"* ]]
}

@test "a work item for a later release of the same major fails" {
  work_item 3000001 1.4.0
  planned_items 1.3.0 1.4.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"1.3.0 is the next 1.x release"* ]]
}

@test "a milestone whose items are all closed is still the next release" {
  # 1.3.0 is done but not yet tagged, and 1.4.0 has open work. The planned
  # list carries both, because it includes closed items.
  work_item 3000001 1.4.0
  planned_items 1.3.0 1.4.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"1.3.0 is the next 1.x release"* ]]
}

@test "the planned list is read across pages" {
  work_item 3000001 1.4.0
  php -r 'echo json_encode(array_fill(0, 100, ["milestone" => ["title" => "1.4.0", "state" => "active"]]));' >"$FIXTURES/planned-1.json"
  planned_items 1.3.0
  mv "$FIXTURES/planned-1.json" "$FIXTURES/planned-2.json"
  php -r 'echo json_encode(array_fill(0, 100, ["milestone" => ["title" => "1.4.0", "state" => "active"]]));' >"$FIXTURES/planned-1.json"
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"1.3.0 is the next 1.x release"* ]]
}

@test "an unreadable planned list fails" {
  work_item 3000001 1.3.0
  echo 503 >"$FIXTURES/planned-1.status"
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"could not read the planned work items"* ]]
}

@test "an id longer than eight digits is not read as a work item" {
  work_item 30000011 1.3.0
  export CI_MERGE_REQUEST_TITLE="fix(#300000112): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
}

@test "the next release is compared by version, not as text" {
  work_item 3000001 1.10.0
  planned_items 1.9.0 1.10.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"1.9.0 is the next 1.x release"* ]]
}

@test "the old 8.x-1.x branch form reads as major 1" {
  work_item 3000001 1.3.0
  export CI_MERGE_REQUEST_TARGET_BRANCH_NAME=8.x-1.x
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 0 ]
}

@test "a target branch with no version skips the major checks" {
  work_item 3000001 2.0.0
  export CI_MERGE_REQUEST_TARGET_BRANCH_NAME=feature/3000001-stacked
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 0 ]
}

@test "an id from another project is ignored with a note" {
  work_item 3000001 1.3.0
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): see https://www.drupal.org/i/3000002"
  run "$GATE" mr
  [ "$status" -eq 0 ]
  [[ $output == *"#3000002 is not a work item"* ]]
}

@test "an MR that names only an id from another project fails" {
  export CI_MERGE_REQUEST_TITLE="fix(#3000002): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
}

@test "an unreadable work item fails and names it" {
  work_item 3000001 1.3.0
  echo 503 >"$FIXTURES/issue-3000001.status"
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"could not read #3000001 (HTTP 503)"* ]]
}

@test "an MR that only changes export-ignored files skips the milestone" {
  work_item 3000001
  change tests/ExampleTest.php
  export CI_MERGE_REQUEST_TITLE="test(#3000001): add coverage"
  run "$GATE" mr
  [ "$status" -eq 0 ]
  [[ $output == *"milestone isn't checked"* ]]
}

@test "an MR with no diff base counts as shipping" {
  work_item 3000001
  export CI_MERGE_REQUEST_DIFF_BASE_SHA=0000000000000000000000000000000000000000
  export CI_MERGE_REQUEST_TITLE="test(#3000001): add coverage"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"has no milestone"* ]]
}

@test "an MR that changes a shipped file checks the milestone" {
  work_item 3000001
  change src/Example.php
  export CI_MERGE_REQUEST_TITLE="fix(#3000001): something"
  run "$GATE" mr
  [ "$status" -eq 1 ]
  [[ $output == *"has no milestone"* ]]
}

@test "a tag whose milestone is complete passes" {
  echo 5 >"$FIXTURES/count-1.3.0-all.total"
  echo 0 >"$FIXTURES/count-1.3.0-opened.total"
  export CI_COMMIT_TAG=1.3.0
  run "$GATE" tag
  [ "$status" -eq 0 ]
}

@test "a tag whose milestone has open work items fails" {
  echo 5 >"$FIXTURES/count-1.3.0-all.total"
  echo 2 >"$FIXTURES/count-1.3.0-opened.total"
  export CI_COMMIT_TAG=1.3.0
  run "$GATE" tag
  [ "$status" -eq 1 ]
  [[ $output == *"2 work item(s) are still open in 1.3.0"* ]]
}

@test "a tag with no milestone fails" {
  echo 0 >"$FIXTURES/count-1.3.0-all.total"
  echo 0 >"$FIXTURES/count-1.3.0-opened.total"
  export CI_COMMIT_TAG=1.3.0
  run "$GATE" tag
  [ "$status" -eq 1 ]
  [[ $output == *"nothing planned this release"* ]]
}

@test "a tag whose milestone can't be read fails" {
  echo 5 >"$FIXTURES/count-1.3.0-all.total"
  echo 503 >"$FIXTURES/count-1.3.0-opened.status"
  export CI_COMMIT_TAG=1.3.0
  run "$GATE" tag
  [ "$status" -eq 1 ]
  [[ $output == *"could not read the 1.3.0 milestone"* ]]
}

@test "a tag whose milestone count can't be read fails" {
  export CI_COMMIT_TAG=1.3.0
  run "$GATE" tag
  [ "$status" -eq 1 ]
  [[ $output == *"could not read the 1.3.0 milestone"* ]]
}
