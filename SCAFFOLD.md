# Scaffold differences

The development tooling in this repository comes from
[Drupal Extension Scaffold](https://github.com/AlexSkrypnyk/drupal_extension_scaffold)
4.18.0. None of it is needed to contribute: a Drupal.org merge request is
enough. None of it is in a release either, because `.gitattributes` marks
it `export-ignore`.

This file lists every place where the repository differs from the scaffold's
own output, and why. Read it before you update the scaffold, because an update
overwrites these files and the changes below have to be put back.

## Init answers

To reproduce the starting point, run `php init.php` from the scaffold release
with these answers:

| Prompt | Answer |
| --- | --- |
| Name | `JSON:API Menu items` |
| Machine name | `jsonapi_menu_items` |
| Type | `module` |
| CI provider | `gha` |
| Drupal versions | `10,11` |
| Command wrapper | `ahoy,makefile` |
| Tools | `phpcs,phpstan,rector,cspell,phpunit,renovate` |
| Cloudflare | `true` |

Run `php init.php --help` first. The environment variable prefix has changed
between scaffold releases, and a wrong prefix waits for input that never
comes.

## Files that belong to the module

The scaffold does not own these files. Restore them from git after an update:

- `src/`, `tests/`, `modules/`
- `composer.json`, `jsonapi_menu_items.*.yml`, `jsonapi_menu_items.install`
- `README.md`
- `.gitlab-ci.yml` and `phpunit.gitlab-ci.xml`
- The release gate: `scripts/release-gate.sh`, `tests/release-gate/` and `.github/workflows/release-gate.yml`. The script's header says what it checks and what it leaves to the release checklist.
- `SCAFFOLD.md` and `.skip_npm_build`

Delete what the scaffold adds as examples: `config/`, `css/`, `js/`,
`logo.png`, `src/Form/`, `src/JsonapiMenuItemsService.php`, the example tests,
`scripts/*-example.sh`, and the example `.libraries.yml`, `.links.menu.yml`,
`.module` and `.services.yml` files.

## Changes to scaffold files

### `.github/workflows/`

- `test.yml` and `draft-release-notes.yml` run on `'*.x'`, not `1.x`. The pattern matches every release branch (`1.2.x`, `2.x`, `8.x-1.x`), so nothing needs changing when a new branch is cut.
- `deploy.yml` is removed. Drupal.org is the canonical repository, so nothing
  is mirrored to it from GitHub.

### `composer.dev.json`

Adds `behat/mink`, `behat/mink-browserkit-driver`, `symfony/browser-kit`,
`symfony/css-selector` and `symfony/dom-crawler`. The scaffold only adds them
with the `functional_javascript` tool. The functional tests need them, and
without them every functional test fails in the bootstrap with
`Class "Behat\Mink\Element\TraversableElement" not found`.

### `phpunit.xml` and `phpunit.d10.xml`

Each test suite also reads `web/modules/custom/*/modules/*/tests/src/...`, so
the tests of `jsonapi_menu_items_hypermedia` run too.

### `phpcs.xml`

DrupalCI finds `phpcs.xml` in the repository root and uses it. It does not
install `composer.dev.json`, so:

- The `DrevOps` and `PHPCompatibility` rules and the `testVersion` setting
  are removed. Those standards are not installed on Drupal.org.
- `.devtools/` is excluded. DrupalCI passes the whole module folder on the
  command line, which overrides the `<file>` paths.

### `phpstan.neon`

- `treatPhpDocTypesAsCertain: false`. Core types many properties only in
  docblocks, so PHPStan otherwise reports defensive checks as dead code.
- `*rector.php` is excluded. DrupalCI analyses the module without the Rector
  classes that file imports. There is no leading `*/`, because DrupalCI runs
  from the module root, where the file has no directory prefix.
- New `ignoreErrors` entries cover type differences between the Drupal 10
  and 11 stubs, and plugin definition types. Each entry has a comment with
  the reason.
- The scaffold's hook entry also ignores `has parameter .* with no type
  specified`.

### `rector.php`

Skips four rules:

- `AddArrayFunctionClosureParamTypeRector` writes the Drupal 10 type of
  `Data::toArray()` into a closure, which then fails PHPStan on Drupal 11.
- `UnwrapFutureCompatibleIfPhpVersionRector`,
  `VersionCompareFuncCallToConstantRector` and `RemovePhpVersionIdCheckRector`
  remove the PHP version guard in the hypermedia test. The test would then be
  skipped on every PHP version.

### `.cspell.json`

Adds `fieldsets`, `Gabilondo`, `pathmenu` and `pathuser`. The same words are
in `_CSPELL_WORDS` in `.gitlab-ci.yml`, for Drupal.org's spelling job.

### `package.json` and `package-lock.json`

- The description is the module's, not the generated placeholder.
- The `is-positive` dependency is removed. The scaffold adds it as a sample,
  and this module does not use it.
- The `build` script is removed, and `.skip_npm_build` turns off the npm build
  step. The module has no front-end assets to build. `npm run lint-spell`
  still works.
- `bats` is added as a dev dependency, with a `test-gate` script that runs the
  release gate's tests.

Regenerate the lock file with `npm install --package-lock-only` after you edit
`package.json`.

### `AGENTS.md`

The CI section names GitHub Actions and DrupalCI, and drops the CircleCI and
deployment lines.

### `.gitattributes`

Adds `SCAFFOLD.md` and `.gitlab-ci.yml` to the `export-ignore` list. Drupal.org packages don't need the CI config, and the release gate then treats a CI-only MR as one that doesn't ship.

## Left as the scaffold makes them

The scaffold leaves some blank lines in `.ahoy.yml`, `Makefile` and
`test.yml` where it strips the FunctionalJavascript sections. They are left
alone, so a fresh `init.php` run compares cleanly with these files.
