<?php

/**
 * @file
 * Tests for release-gate.php. They read fixtures and use no network.
 *
 * Run them as `php .gitlab/release-gate-test.php`.
 */

declare(strict_types=1);

namespace ReleaseGate;

require __DIR__ . '/release-gate.php';

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/release-gate-fixtures.json'), TRUE);
$items = $fixtures['work_items'];
$milestone = $fixtures['milestone'];
$count = 0;
$failures = 0;

// Compares a value with the expected one and reports a mismatch.
$check = function (string $name, mixed $actual, mixed $expected) use (&$count, &$failures): void {
  $count++;
  if ($actual !== $expected) {
    $failures++;
    echo "FAILED: {$name}\n  expected: ", var_export($expected, TRUE), "\n  actual:   ", var_export($actual, TRUE), "\n";
  }
};

// Returns the levels of a list of results.
$levels = fn(array $results): array => array_column($results, 0);

// References.
$check('title scope', references('fix(#3591568): tidy'), [3591568]);
$check('hash in text', references('Issue #3350524 by someone'), [3350524]);
$check('work item URL', references('Closes https://git.drupalcode.org/project/jsonapi_menu_items/-/work_items/3591566'), [3591566]);
$check('duplicates once', references('fix(#1): a #1 b #2'), [1, 2]);
$check('no reference', references('chore: merge request !31'), []);

// Major versions.
$check('branch', major('1.3.x'), 1);
$check('milestone', major('2.0.0'), 2);
$check('minor tag milestone', milestone_for('1.3.0'), '1.3.0');
$check('patch tag milestone', milestone_for('1.2.9'), '1.2.0');
$check('short branch', major('2.x'), 2);
$check('core prefixed branch', major('8.x-1.x'), 1);
$check('not a version', major('main'), NULL);

// Work item mode.
$check('no work item fails', $levels(check_work_item([], '1.3.x')), ['fail']);
$check('unknown work item fails', $levels(check_work_item([12 => NULL], '1.3.x')), ['note', 'fail']);
$check('same major passes', $levels(check_work_item([3591568 => $items['3591568']], '1.3.x')), ['ok']);
$check('stacked MR into a feature branch passes', $levels(check_work_item([3591568 => $items['3591568']], 'feature/3591568-eddy-5')), ['ok']);
$check('other major warns', $levels(check_work_item([3591565 => $items['3591565']], '1.3.x')), ['warn']);
$check('closed milestone warns', $levels(check_work_item([3554198 => $items['3554198']], '1.2.x')), ['warn']);
$check('no milestone warns', $levels(check_work_item([3562736 => $items['3562736']], '1.2.x')), ['warn']);

// Release mode.
$commits = [
  'aaaa1111' => 'fix: #3591566 Menu items resource 500s',
  'bbbb2222' => "ci(#3591567): run phpunit concurrently\n\nBy: deciphered",
];
$check('clean release passes', check_release('1.3.0', $milestone, ['1.3.x'], $commits), []);
$check('not a release tag', $levels(check_release('1.3.0-beta1', $milestone, ['1.3.x'], [])), ['fail']);
$check('empty milestone fails', $levels(check_release('1.3.0', [], ['1.3.x'], [])), ['fail']);
$open = [...$milestone, ['iid' => 3591568, 'state' => 'opened', 'title' => 'Gate', 'labels' => []]];
$check('open work item fails', $levels(check_release('1.3.0', $open, ['1.3.x'], [])), ['fail']);
$check('wrong major branch fails', $levels(check_release('1.3.0', $milestone, ['2.0.x', 'feature/x'], [])), ['fail']);
$check('core prefixed branch passes', check_release('1.3.0', $milestone, ['8.x-1.x'], []), []);
$check('commit with no work item fails', $levels(check_release('1.3.0', $milestone, ['1.3.x'], ['cccc3333' => 'chore: tidy'])), ['fail']);
$check('commit with an open work item fails', $levels(check_release('1.3.0', $open, ['1.3.x'], ['cccc3333' => 'ci(#3591568): gate'])), ['fail', 'fail']);

// Patch release checks.
$breaking = ['dddd4444' => 'feat(#3591566)!: rename the resource'];
$check('breaking change in a minor passes', check_release('1.3.0', $milestone, ['1.3.x'], $breaking), []);
$check('breaking change in a patch fails', $levels(check_release('1.3.1', $milestone, ['1.3.x'], $breaking)), ['fail']);
$diff = "-core_version_requirement: ^10 || ^11\n+core_version_requirement: ^11";
$check('core requirement in a minor passes', check_release('1.3.0', $milestone, ['1.3.x'], [], $diff), []);
$check('core requirement in a patch fails', $levels(check_release('1.3.1', $milestone, ['1.3.x'], [], $diff)), ['fail']);
$check('gate change in a patch fails', $levels(check_release('1.3.1', $milestone, ['1.3.x'], [], '', ['.gitlab/release-gate.php'])), ['fail']);

// Release notes.
$check('notes', release_notes($open), "Bug:\n- #3591566 Menu items resource 500s\n- #3591563 Fix 11.4 hasDefinition(NULL)\nOther:\n- #3350524 Document sparse fieldsets\nTask:\n- #3591567 Run phpunit concurrently\n");

// Exit codes and the override.
// Previous release tag.
$check('previous tag is the highest release', previous_tag(['1.2.8', '1.3.0', '1.2.10']), '1.3.0');
$check('previous tag skips prereleases', previous_tag(['1.3.0', '1.3.1rc1', '1.3.1-rc1', '8.x-1.9']), '1.3.0');
$check('no previous tag', previous_tag(['1.3.0-beta1']), '');

// API failures.
$check('API failure is a fail result', results(fn() => throw new \RuntimeException('could not read the API')), [['fail', 'could not read the API']]);
$check('API failure can be overridden', exit_code(results(fn() => throw new \RuntimeException('down')), 'API outage'), 3);

$check('pass', exit_code([['ok', '']], ''), 0);
$check('warn', exit_code([['warn', '']], ''), 3);
$check('fail', exit_code([['warn', ''], ['fail', '']], ''), 1);
$check('override makes a failure a warning', exit_code([['fail', '']], 'Hotfix for SA-CONTRIB'), 3);
$check('override leaves a pass alone', exit_code([['ok', '']], 'Hotfix'), 0);

echo "{$count} assertions, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
