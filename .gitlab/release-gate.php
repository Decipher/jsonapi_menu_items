<?php

/**
 * @file
 * Checks merge requests and tags against Drupal.org work items and milestones.
 *
 * Run it as `php .gitlab/release-gate.php mr` or `... tag`. It reads the
 * public GitLab API of Drupal.org, so it needs no token.
 *
 * Exit codes: 0 pass, 1 fail, 2 usage, 3 warning.
 *
 * Environment:
 * - RELEASE_GATE_API: the API base. Defaults to the Drupal.org GitLab API.
 * - RELEASE_GATE_PROJECT: the project path. Defaults to this module.
 * - RELEASE_GATE_OVERRIDE: a reason. Failures become warnings.
 */

declare(strict_types=1);

namespace ReleaseGate;

/**
 * Returns the work item ids named in a text.
 *
 * @return int[]
 *   The ids, in the order they first appear.
 */
function references(string $text): array {
  preg_match_all('~(?:#|/work_items/|/issues/)(\d+)\b~', $text, $matches);
  return array_values(array_unique(array_map(intval(...), $matches[1])));
}

/**
 * Returns the major version of a branch, tag or milestone name.
 *
 * The names 1.2.x, 1.3.0 and 2.x give 1, 1 and 2. The old 8.x-1.x form
 * gives 1, because the 8 is the core version.
 */
function major(string $name): ?int {
  if (preg_match('/^\d+\.x-(\d+)(\.|$)/', $name, $match) || preg_match('/^(\d+)(\.|$)/', $name, $match)) {
    return (int) $match[1];
  }
  return NULL;
}

/**
 * Checks the work items an MR names against its target branch.
 *
 * @param array<int, array<string, mixed>|null> $items
 *   The work items by id. NULL means the API has no such item.
 * @param string $target
 *   The target branch.
 *
 * @return list<array{string, string}>
 *   The results, each a level and a message.
 */
function check_work_item(array $items, string $target): array {
  $results = [];
  // A feature branch, the target in a stack of MRs, has no major version.
  $target_major = major($target);
  foreach ($items as $id => $item) {
    $milestone = $item['milestone']['title'] ?? NULL;
    if ($item === NULL) {
      $results[] = ['note', "#{$id} is not a work item of this project, so it is ignored."];
    }
    elseif ($milestone === NULL || ($item['milestone']['state'] ?? '') !== 'active') {
      $results[] = ['warn', "#{$id} has no active milestone, so no release plans for it."];
    }
    elseif ($target_major !== NULL && major($milestone) !== $target_major) {
      $results[] = ['warn', "#{$id} is in milestone {$milestone}, but {$target} is a different major version."];
    }
    else {
      $results[] = ['ok', "#{$id} is in milestone {$milestone}."];
    }
  }
  if (array_filter($items) === []) {
    $results[] = ['fail', "This MR names no work item. Put one in the title, for example 'fix(#3591566): ...'."];
  }
  return $results;
}

/**
 * Returns the milestone for a tag.
 *
 * Milestones are named x.y.0. A patch release sits in its minor's milestone.
 *
 * @param string $tag
 *   The tag, such as 1.3.1.
 *
 * @return string
 *   The milestone name, such as 1.3.0.
 */
function milestone_for(string $tag): string {
  return preg_match('/^(\d+)\.(\d+)\./', $tag, $m) ? "{$m[1]}.{$m[2]}.0" : $tag;
}

/**
 * Checks a tag against its milestone and the commits since the last tag.
 *
 * @param string $tag
 *   The tag, such as 1.3.1. Its milestone is the minor's x.y.0, so 1.3.0.
 * @param array<mixed> $items
 *   The work items in the milestone, open and closed.
 * @param string[] $branches
 *   The branches that contain the tagged commit.
 * @param array<string, string> $commits
 *   The commit messages since the previous tag, keyed by hash.
 * @param string $diff
 *   The diff of the *.info.yml files since the previous tag.
 * @param string[] $gate_files
 *   The gate files changed since the previous tag.
 *
 * @return list<array{string, string}>
 *   The results, each a level and a message.
 */
function check_release(string $tag, array $items, array $branches, array $commits, string $diff = '', array $gate_files = []): array {
  if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $tag, $version)) {
    return [['fail', "{$tag} is not a release tag like 1.3.0."]];
  }
  $patch = $version[3] !== '0';
  $milestone = milestone_for($tag);
  $results = [];
  $closed = [];
  foreach ($items as $item) {
    if ($item['state'] === 'closed') {
      $closed[] = $item['iid'];
    }
    else {
      $results[] = ['fail', "#{$item['iid']} is still open in milestone {$milestone}."];
    }
  }
  if ($items === []) {
    $results[] = ['fail', "Milestone {$milestone} has no work items, or doesn't exist."];
  }
  $release_branches = array_filter($branches, fn(string $branch): bool => preg_match('/^(\d+\.x-)?\d+(\.\d+)?\.x$/', $branch) && major($branch) === (int) $version[1]);
  if ($release_branches === []) {
    $results[] = ['fail', "The tagged commit is on no {$version[1]}.x release branch."];
  }
  foreach ($commits as $hash => $message) {
    $commit = substr($hash, 0, 8) . ' (' . strtok($message, "\n") . ')';
    if (array_intersect(references($message), $closed) === []) {
      $results[] = ['fail', "{$commit} names no closed work item in milestone {$milestone}."];
    }
    if ($patch && preg_match('/^\w+(\([^)]*\))?!:|^BREAKING[ -]CHANGE:/m', $message)) {
      $results[] = ['fail', "{$commit} is a breaking change in a patch release."];
    }
  }
  if ($patch && preg_match('/^[+-]\s*core_version_requirement/m', $diff)) {
    $results[] = ['fail', 'core_version_requirement changed in a patch release.'];
  }
  if ($patch && $gate_files !== []) {
    $results[] = ['fail', 'The release gate changed in a patch release: ' . implode(', ', $gate_files)];
  }
  return $results;
}

/**
 * Returns release notes for the closed work items, grouped by category.
 *
 * @param array<mixed> $items
 *   The work items in the milestone.
 */
function release_notes(array $items): string {
  $groups = [];
  foreach ($items as $item) {
    if ($item['state'] !== 'closed') {
      continue;
    }
    $category = 'Other';
    foreach ($item['labels'] ?? [] as $label) {
      if (str_starts_with($label, 'category::')) {
        $category = ucfirst(substr($label, 10));
      }
    }
    $groups[$category][] = "- #{$item['iid']} {$item['title']}";
  }
  ksort($groups);
  $notes = '';
  foreach ($groups as $category => $lines) {
    $notes .= "{$category}:\n" . implode("\n", $lines) . "\n";
  }
  return $notes;
}

/**
 * Returns the highest release tag in a list of tags.
 *
 * A glob such as [0-9]*.[0-9]*.[0-9]* also matches 1.3.1rc1, so the tags are
 * checked with the same pattern as release tags.
 *
 * @param string[] $tags
 *   The tags, in any order.
 *
 * @return string
 *   The tag, or an empty string when there is none.
 */
function previous_tag(array $tags): string {
  $releases = preg_grep('/^\d+\.\d+\.\d+$/', $tags);
  usort($releases, version_compare(...));
  return (string) end($releases);
}

/**
 * Runs a check and turns an API failure into a fail result.
 *
 * Then a failure goes through exit_code(), so RELEASE_GATE_OVERRIDE applies.
 *
 * @param callable(): list<array{string, string}> $run
 *   The check.
 *
 * @return list<array{string, string}>
 *   The results.
 */
function results(callable $run): array {
  try {
    return $run();
  }
  catch (\RuntimeException $e) {
    return [['fail', $e->getMessage()]];
  }
}

/**
 * Returns the exit code for a list of results.
 *
 * @param list<array{string, string}> $results
 *   The results.
 * @param string $override
 *   The override reason, or an empty string.
 */
function exit_code(array $results, string $override): int {
  $levels = array_column($results, 0);
  if (in_array('fail', $levels, TRUE)) {
    return $override === '' ? 1 : 3;
  }
  return in_array('warn', $levels, TRUE) ? 3 : 0;
}

/**
 * Reads an API path of the project.
 *
 * @return array<mixed>|null
 *   The decoded JSON, or NULL when the API answers 404.
 *
 * @throws \RuntimeException
 *   When the API can't be read after three tries.
 */
function api(string $path): ?array {
  $api = rtrim(getenv('RELEASE_GATE_API') ?: 'https://git.drupalcode.org/api/v4', '/');
  $project = getenv('RELEASE_GATE_PROJECT') ?: 'project/jsonapi_menu_items';
  $url = "{$api}/projects/" . urlencode($project) . "/{$path}";
  // The API refuses a request with no user agent.
  $options = ['ignore_errors' => TRUE, 'timeout' => 30, 'user_agent' => 'release-gate'];
  $context = stream_context_create(['http' => $options]);
  $status = 0;
  // Retry, so a slow or rate limited API doesn't fail the job.
  for ($try = 1; $try <= 3; $try++) {
    $body = @file_get_contents($url, FALSE, $context);
    $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
    if ($status === 404) {
      return NULL;
    }
    if ($status === 200 && is_array($data = json_decode((string) $body, TRUE))) {
      return $data;
    }
    if ($try < 3) {
      sleep($try * 2);
    }
  }
  throw new \RuntimeException("Could not read {$url} (HTTP {$status}). Run the job again.");
}

/**
 * Runs git and returns its output lines.
 *
 * @return string[]
 *   The output lines.
 */
function git(string ...$args): array {
  exec('git ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>/dev/null', $output);
  return $output;
}

/**
 * Runs the work item check for the current MR pipeline.
 *
 * @return list<array{string, string}>
 *   The results.
 */
function run_work_item(): array {
  $items = [];
  foreach (references(getenv('CI_MERGE_REQUEST_TITLE') . "\n" . getenv('CI_MERGE_REQUEST_DESCRIPTION')) as $id) {
    $items[$id] = api("issues/{$id}");
  }
  return check_work_item($items, (string) getenv('CI_MERGE_REQUEST_TARGET_BRANCH_NAME'));
}

/**
 * Runs the release check for the current tag pipeline.
 *
 * @return list<array{string, string}>
 *   The results.
 */
function run_release(): array {
  $tag = (string) getenv('CI_COMMIT_TAG');
  $commit = getenv('CI_COMMIT_SHA') ?: $tag;
  $items = [];
  for ($page = 1; $batch = api("issues?state=all&per_page=100&page={$page}&milestone=" . urlencode(milestone_for($tag))); $page++) {
    $items = array_merge($items, $batch);
  }
  $branches = array_map(fn(string $branch): string => (string) preg_replace('~^origin/~', '', $branch), git('branch', '-a', '--contains', $commit, '--format=%(refname:short)'));
  $previous = previous_tag(git('tag', '--merged', "{$commit}^"));
  $commits = [];
  if ($previous === '') {
    echo "NOTE: there is no previous tag, so no commits are checked.\n";
  }
  else {
    $log = implode("\n", git('log', '--no-merges', '--format=%H%x1f%B%x1e', "{$previous}..{$commit}"));
    foreach (array_filter(array_map(trim(...), explode("\x1e", $log))) as $entry) {
      [$hash, $message] = explode("\x1f", $entry, 2) + [1 => ''];
      $commits[$hash] = trim($message);
    }
    echo 'Checking ', count($commits), " commits since {$previous}.\n";
  }
  $range = [$previous ?: $commit, $commit];
  $diff = implode("\n", git('diff', ...[...$range, '--', '*.info.yml']));
  $gate_files = git('diff', '--name-only', ...[...$range, '--', '.gitlab']);
  echo "Release notes for {$tag}:\n", release_notes($items), "\n";
  return check_release($tag, $items, $branches, $commits, $diff, $gate_files);
}

/**
 * Runs a mode, prints the results and returns the exit code.
 */
function main(string $mode): int {
  $results = match ($mode) {
    'mr' => results(run_work_item(...)),
    'tag' => results(run_release(...)),
    default => NULL,
  };
  if ($results === NULL) {
    fwrite(STDERR, "Usage: php .gitlab/release-gate.php mr|tag\n");
    return 2;
  }
  foreach ($results as [$level, $message]) {
    echo strtoupper($level), ': ', $message, "\n";
  }
  $override = trim((string) getenv('RELEASE_GATE_OVERRIDE'));
  if ($override !== '') {
    echo "OVERRIDE: failures are warnings because: {$override}\n";
  }
  return exit_code($results, $override);
}

// Run only when called directly, so the tests can include this file.
if (realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
  exit(main($_SERVER['argv'][1] ?? ''));
}
