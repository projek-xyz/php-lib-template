# AGENTS.md: projek-xyz/template

PHP library template (`projek-xyz/template`), a single Composer package.

## Quickstart Commands

| Action | Command |
|--------|---------|
| Run all checks (lint + test) | `composer test` |
| Format code (Pint, Laravel preset) | `composer format` |
| Lint code (Pint, Laravel preset) | `composer lint` |
| Run specs/Kahlan tests | `composer spec` |

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

- Run `composer format -- --format agent` to fix any formatting issues
- Run `composer format -- --test --format agent` to verify the changes matches the project's expected style
- PHP version requirement: `>=8.3` (defined in `composer.json`). Keep `src/` and `tests/` compatible with declared minimum PHP version.

## Testing

- Test framework: **Kahlan** (v6.x)
- Spec files live in `tests/spec/**/*.spec.php` and use `describe`/`it` + `expect()` syntax
- Spec files should mirrors `src/` files structures: `src/Foo.php` -> `tests/spec/Foo.spec.php`; `src/Foo/Bar.php` -> `tests/spec/Foo/Bar.spec.php`
- Run all specs with `composer spec`; run a single spec file: `composer spec -- --spec=tests/spec/filename.spec.php`
- Exception: `tests/spec/scripts/init.spec.php` mirrors `scripts/init.php` (both template-only, removed on instantiation)
- Stub file live in `tests/stub/` with `Stubs\` PSR-4 prefix (autoload-dev)
- CI runs tests on PHP 8.3–8.5 matrix (`.github/workflows/tests.yml`), local dev pins PHP 8.4 via `mise` (see `.tool-versions`)

## Git workflow

- **Commit messages must be Conventional Commits** (`feat:`, `fix:`, `chore:`, ...) — enforced by the `commit-msg` hook (commitlint); non-conforming messages are rejected.
- `pre-commit` runs lint-staged, which rewrites staged `*.php` through `composer format`
- Hooks are installed by the `prepare` script on `npm install` (`simple-git-hooks`); `opencode.json`/`.opencode`/`.ai` are gitignored

## Conventions to Note

- All `*.php` source files use `declare(strict_types=1)`, enforced by the `composer format`
- Files are namespaced under `Projek\` (or `Stubs\` for test stubs, fixtures and helpers)
- Classes and functions from other namespaces **must be explicitly qualified** via a `use` import (e.g. `use function sprintf;`) — never an inline `\` prefix on the call; Pint's `fully_qualified_strict_types` converts inline FQCNs into imports
- Native function calls follow Pint's `native_function_invocation`: compiler-optimized functions stay bare (`sprintf()`, `trim()`), the rest keep the `\` prefix (`\is_resource()`). Don't hand-pick the form — `composer format` decides
- Documentation templates available in:
  - `docs/` is a Jekyll GitHub Pages site (remote theme `just-the-docs`)
  - `.github/wiki/` is synced to the GitHub wiki by the `wiki` job in `.github/workflows/publish.yml` when a `v*.*.*` tag is pushed.
- Sample PHPMD config is available in `tests/phpmd.xml`;
