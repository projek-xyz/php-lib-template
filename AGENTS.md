# AGENTS.md: projek-xyz/template

PHP library template (`projek-xyz/template`), a single Composer package.

## Quickstart Commands

| Action | Command |
|--------|---------|
| Run all checks (lint + test) | `composer test` |
| Format code (Pint, Laravel preset) | `composer format` |
| Lint code (Pint, Laravel preset) | `composer lint` |
| Run specs/Kahlan tests | `composer spec` |
| Install dependencies | `composer install` |

**Notes:**
- Run a single spec file: `composer spec -- --spec=tests/spec/Container.spec.php` (or `--grep='*Container*'`).

## Project Structure

```
src/                  # Source code (PSR-4: Projek\):
  FooBar.php          # A dummy entry point
tests/
  spec/               # Kahlan specifications dir
    FooBar.spec.php   # A dummy spec file
  stub/               # Test stubs dir
  config.php          # Kahlan config (coverage, stubs dir)
scripts/
  init.php            # One-shot bootstrap, self-deletes on instantiation
vendor/               # Composer dependencies
```

## Coding Standards

- **Formatter**: `composer format` runs Pint with the Laravel preset
- **Linter**: `composer lint` runs Pint with the Laravel preset (`--test` mode fails on discrepancies)
- Both use the project's `composer.json` scripts
- PHP version requirement: `>=8.0` (defined in `composer.json`). Keep `src/` and `tests/` compatible with declared minimum PHP version.

## Testing

- Test framework: **Kahlan** (v6.x)
- Spec files live in `tests/spec/**/*.spec.php` and use `describe`/`it` + `expect()` syntax
- Spec files should mirrors `src/` files structures: `src/Foo.php` -> `tests/spec/Foo.spec.php`; `src/Foo/Bar.php` -> `tests/spec/Foo/Bar.spec.php`
- Exception: `tests/spec/scripts/init.spec.php` mirrors `scripts/init.php` (both template-only, removed on instantiation)
- Stub file live in `tests/stub/` with `Stubs\` PSR-4 prefix (autoload-dev)
- Run all tests with `composer spec`
- CI runs tests on PHP 8.0–8.5 matrix (`.github/workflows/tests.yml`), local dev pins PHP 8.4 via `.tool-versions` (asdf/mise)

## Git workflow

- **Commit messages must be Conventional Commits** (`feat:`, `fix:`, `chore:`, ...) — enforced by the `commit-msg` hook (commitlint); non-conforming messages are rejected. Skip hooks with `--no-verify` flag.
- `pre-commit` runs lint-staged, which rewrites staged `*.php` through `composer format`
- Hooks are installed by the `prepare` script on `npm install` (`simple-git-hooks`); `opencode.json`/`.opencode`/`.ai` are gitignored
- Release: `npm run release` (commit-and-tag-version); pushing a `v*.*.*` tag triggers the `publish` workflow (release + wiki sync)

## Conventions to Note

- All `*.php` source files use `declare(strict_types=1)`, enforced by the `composer format`
- Files are namespaced under `Projek\` (or `Stubs\` for test stubs, fixtures and helpers)
- Classes and functions **must be explicitly qualified**: either a `use` import or a `\` prefix — **never a bare call in a `strict_types` file** (e.g. DO `use function sprintf;` + `sprintf();`, or `\sprintf();`; DON'T call `sprintf();` unqualified)
- Documentation templates available in:
  - `docs/` is a Jekyll GitHub Pages site (remote theme `just-the-docs`)
  - `.github/wiki/` is synced to the GitHub wiki by the `wiki` job in `.github/workflows/publish.yml` when a `v*.*.*` tag is pushed.
- Sample PHPMD config is available in `tests/phpmd.xml`;
