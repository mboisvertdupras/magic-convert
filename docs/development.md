# Development

This document covers local development of the **Magic Convert** fork.

## Requirements

- PHP **8.1+** (the plugin no longer supports PHP 5.6–8.0; 8.1 is required for GD `imageavif()` and the modern conversion core).
- [Composer](https://getcomposer.org/) 2.x.
- WordPress **5.9+** for running the plugin itself.

## Getting started

```console
git clone https://github.com/mboisvertdupras/magic-convert.git
cd magic-convert
composer install
```

`composer install` pulls in both the production dependencies and the dev
dependencies (PHPUnit). It is reproducible from the committed `composer.lock`.

## Running the tests

The test suite is a set of **WordPress-independent** unit tests: they do not
load WordPress, the WP test suite, or a database. They exercise pure-PHP logic
in classes designed to run standalone (e.g. `PathHelper`, `SanityCheck`, the
"Independent" conversion helpers).

```console
vendor/bin/phpunit
# or
composer test
```

The PHPUnit config lives in `phpunit.xml.dist`. To override it locally without
touching version control, copy it to `phpunit.xml` (which is git-ignored by
PHPUnit's own conventions — add it to `.gitignore` if you create one).

Tests live in `tests/`. `tests/bootstrap.php` registers an autoloader for the
`MagicConvert\` namespace pointing at `lib/classes/` (mirroring the plugin's own
`spl_autoload_register` in `magic-convert.php`) and requires
`vendor/autoload.php` for the `webp-convert` library classes.

When adding a new WordPress-independent class, add PHPUnit coverage for it (see
the working agreements in `docs/ROADMAP.md`). Only pure logic that runs without
WordPress belongs here — methods that call WP functions, `realpath()`, or read
`$_SERVER` are out of scope for these unit tests.

## The committed `vendor/` directory (important)

Like most WordPress plugins distributed as a `.zip`, this repo **commits its
production `vendor/` directory** so the plugin works without a build step on the
target site.

**Dev dependencies are NOT committed.** PHPUnit and its transitive packages are
installed locally / in CI via `composer install` and are reproducible from
`composer.lock`. The `.gitignore` therefore excludes only the dev-dependency
vendor paths:

```
/vendor/bin/
/vendor/phpunit/
/vendor/sebastian/
/vendor/myclabs/
/vendor/nikic/
/vendor/phar-io/
/vendor/theseer/
```

Keep that list in sync with whatever `composer install` actually pulls in for
`require-dev`.

### A note on `vendor/composer/` autoload maps

The committed `vendor/composer/` autoload maps are generated for the
**production** dependency set with an optimized (`-o`) classmap. Running a plain
`composer install` (which also installs dev packages) regenerates those maps to
include dev-package entries and drops the optimized production classmap in favor
of lazy PSR-4 resolution. That regeneration is a *local working-tree* artifact —
**do not commit the dev-modified `vendor/composer/` maps.** After running the
tests, the committed maps stay as-is; CI regenerates them fresh from
`composer.lock` on every run, so reproducibility is guaranteed.

If you need to refresh the committed production autoload maps (e.g. after bumping
a production dependency), regenerate them without dev packages:

```console
composer install --no-dev
composer dump-autoload -o --no-dev
```

## Release builds

A distributable build must NOT contain dev dependencies. Build the release
vendor tree with:

```console
composer install --no-dev --optimize-autoloader
```

This installs only production dependencies and writes an optimized classmap.

## Updating the production `vendor/` dir

1. Run `composer update` in the plugin root (updates `composer.lock`).
2. Run `composer dump-autoload -o` to regenerate the optimized classmap.
3. Remove files not needed at runtime, e.g.:

```console
rm -rf vendor/rosell-dk/webp-convert/docs
rm -f  vendor/rosell-dk/webp-convert/src/Helpers/*.txt
```

4. Re-run `composer install` (with dev) and confirm `vendor/bin/phpunit` is
   still green.
5. Commit `composer.json`, `composer.lock`, and the production `vendor/` changes
   (but not the dev-modified `vendor/composer/` maps — see the note above).

## Continuous integration

`.github/workflows/ci.yml` runs on every push to `master` and on every pull
request:

- **lint** (PHP 8.1 and 8.3): a `php -l` sweep over all non-`vendor` `.php`
  files (fails on any syntax error) plus `composer validate --no-check-all
  --strict`.
- **test** (PHP 8.1 and 8.3): `composer install` followed by
  `vendor/bin/phpunit`.

## Upstream

Magic Convert is a fork of [WebP Express](https://github.com/rosell-dk/webp-express)
by Bjørn Rosell. Keep the `upstream` remote configured to pull in security
fixes (especially `webp-convert` bumps):

```console
git remote add upstream https://github.com/rosell-dk/webp-express.git
```

`vendor/rosell-dk/*` is never modified in place — generalizations happen at the
plugin layer or in code forked into `lib/`.
