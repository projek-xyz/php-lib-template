<?php

/**
 * One-shot bootstrap for projects created from this template.
 *
 * Runs either from GitHub's init workflow (instantiation push) or from
 * composer's post-create-project-cmd hook, transforms the project tree
 * in place, verifies the result, then removes itself.
 */

declare(strict_types=1);

namespace Projek;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Yellow foreground escape for highlighted input. */
const ANSI_YELLOW = "\033[33m";

/** Restore the default foreground color. */
const ANSI_RESET = "\033[39m";

/** Commit message for an interactively initialized repository. */
const GIT_INITIAL_COMMIT = 'chore: initial commit :fire:';

/**
 * Builds the default package name from host username and directory name.
 *
 * Falls back to the template name when no username can be determined.
 */
function defaultPackageName(string $dirName, ?string $username): string
{
    if ($username === null || $username === '') {
        return 'projek-xyz/template';
    }

    $package = \strtolower($username . '/' . $dirName);
    $package = \preg_replace('/[^a-z0-9\/_.-]+/', '-', $package);
    $package = \preg_replace('/-+/', '-', $package ?: '');

    return \trim($package ?: '', '-');
}

/**
 * Resolves the host username from the environment.
 *
 * Checks USER, LOGNAME, then USERNAME for cross-platform coverage.
 */
function templateUsername(): ?string
{
    foreach (['USER', 'LOGNAME', 'USERNAME'] as $variable) {
        $value = \getenv($variable);

        if ($value !== false && $value !== '') {
            return $value;
        }
    }

    return null;
}

/**
 * Interprets a typed package name answer.
 *
 * Returns [package, warning]: null package means end of input (skip),
 * an invalid answer falls back to the default with a warning message.
 *
 * @return array{0: ?string, 1: ?string}
 */
function resolvePackageInput(?string $line, string $default): array
{
    if ($line === null) {
        return [null, null];
    }

    $answer = \trim($line);

    if ($answer === '') {
        return [$default, null];
    }

    $package = \strtolower($answer);
    $valid = \preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $package) === 1;

    if (! $valid) {
        return [$default, 'Invalid package name format, fallback to default ' . $default];
    }

    return [$package, null];
}

/**
 * Asks for the package name on an interactive stream.
 *
 * Returns null on end of input, otherwise the chosen or default name.
 *
 * @param resource $in
 * @param resource $out
 */
function promptPackageName($in, $out, string $default): ?string
{
    \fwrite($out, 'Package name [' . $default . ']: ');

    $line = \fgets($in);
    [$package, $warning] = resolvePackageInput($line === false ? null : $line, $default);

    if ($warning !== null) {
        $colored = \str_replace($default, ANSI_YELLOW . $default . ANSI_RESET, $warning);
        \fwrite($out, $colored . "\n");
    }

    return $package;
}

/**
 * Asks whether to initialize a git repository.
 *
 * Defaults to no on an empty answer or end of input.
 *
 * @param resource $in
 * @param resource $out
 */
function promptGitInit($in, $out, string $package): bool
{
    \fwrite($out, 'Do you want to git init your ' . ANSI_YELLOW . $package . ANSI_RESET . ' repo? [y/N] ');

    $line = \fgets($in);

    if ($line === false) {
        return false;
    }

    $answer = \strtolower(\trim($line));

    return $answer === 'y' || $answer === 'yes';
}

/**
 * Runs a command, returning its exit code and combined output.
 *
 * Overrides are merged over the current environment; null inherits it.
 *
 * @param ?array<string, string> $overrides
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, ?array $overrides = null): array
{
    $environment = null;

    if ($overrides !== null) {
        $environment = \array_merge((array) \getenv(), $overrides);
    }

    $process = \proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        $environment
    );

    if (! is_resource($process)) {
        return [1, ''];
    }

    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);

    return [\proc_close($process), \trim($stdout . $stderr)];
}

/**
 * Checks whether a command can be executed.
 *
 * @param ?array<string, string> $overrides
 */
function isCommandAvailable(string $command, ?array $overrides = null): bool
{
    [$code] = runCommand($command . ' --version', $overrides);

    return $code === 0;
}

/**
 * Initializes, stages, and commits the project root.
 *
 * Returns a status word: git-not-found, already-git, no-identity,
 * committed, init-failed, or commit-failed.
 *
 * @param ?array<string, string> $overrides
 */
function initializeGitRepository(string $root, string $message, ?array $overrides = null): string
{
    if (! isCommandAvailable('git', $overrides)) {
        return 'git-not-found';
    }

    if (\is_dir($root . '/.git')) {
        return 'already-git';
    }

    $prefix = 'git -C ' . \escapeshellarg($root);

    [$code] = runCommand($prefix . ' init --quiet', $overrides);

    if ($code !== 0) {
        return 'init-failed';
    }

    runCommand($prefix . ' add -A', $overrides);

    $envName = $overrides['GIT_AUTHOR_NAME'] ?? \getenv('GIT_AUTHOR_NAME');
    $name = '';

    if (\is_string($envName) && $envName !== '') {
        $name = $envName;
    } else {
        [$configCode, $configName] = runCommand($prefix . ' config user.name', $overrides);
        $name = $configCode === 0 ? $configName : '';
    }

    $envEmail = $overrides['GIT_AUTHOR_EMAIL'] ?? \getenv('GIT_AUTHOR_EMAIL');
    $email = '';

    if (\is_string($envEmail) && $envEmail !== '') {
        $email = $envEmail;
    } else {
        [$configCode, $configEmail] = runCommand($prefix . ' config user.email', $overrides);
        $email = $configCode === 0 ? $configEmail : '';
    }

    if ($name === '' || $email === '') {
        return 'no-identity';
    }

    [$code] = runCommand($prefix . ' commit --quiet -m ' . \escapeshellarg($message), $overrides);

    return $code === 0 ? 'committed' : 'commit-failed';
}

/**
 * Bootstraps a generated project tree.
 *
 * Interacts only when $interactive is true and github mode is off, then
 * transforms, verifies, strips the bootstrap itself, and finally
 * initializes git when requested — so the single initial commit contains
 * the bootstrapped state rather than the template state.
 */
function bootstrapProject(string $root, $stdin, $stdout, bool $interactive, array $env): int
{
    $target = $env['GITHUB_REPOSITORY'] ?? null;
    $githubMode = \is_string($target) && $target !== '';
    $initGit = false;

    if (! $githubMode) {
        $target = null;

        if ($interactive) {
            $default = defaultPackageName(\basename($root), templateUsername());
            $target = promptPackageName($stdin, $stdout, $default);
            \fwrite($stdout, PHP_EOL);

            if ($target !== null) {
                $initGit = promptGitInit($stdin, $stdout, $target);
            }

            \fwrite($stdout, PHP_EOL);
        }
    }

    if ($target !== null) {
        rewriteTemplateReferences($root, $target);
    }

    resetPackageVersions($root);

    $removed = removeTemplateOnlyFiles($root, $githubMode, [
        'composer' => isCommandAvailable('composer'),
        'npm' => isCommandAvailable('npm'),
    ]);
    uncommentExportIgnoreList($root);

    foreach ($removed as $file) {
        \fwrite($stdout, 'removed ' . $file . PHP_EOL);
    }

    $failures = verifyTransformation($root, $target, $githubMode);

    if ($failures !== []) {
        foreach ($failures as $failure) {
            \fwrite($stdout, 'FAIL: ' . $failure . PHP_EOL);
        }

        return 1;
    }

    \fwrite($stdout, 'verified' . PHP_EOL);

    removeSelfFromComposerJson($root);
    removePath($root . '/scripts/init.php');
    @\rmdir($root . '/scripts');

    if ($initGit) {
        $messages = [
            'committed' => 'git: initial commit created',
            'no-identity' => 'git: identity not configured, skipping commit',
            'git-not-found' => 'git: not found, skipping',
            'already-git' => 'git: repository already exists, skipping',
            'init-failed' => 'git: init failed',
            'commit-failed' => 'git: commit failed',
        ];
        $status = initializeGitRepository($root, GIT_INITIAL_COMMIT);
        \fwrite($stdout, ($messages[$status] ?? 'git: ' . $status) . PHP_EOL);
    }

    return 0;
}

/**
 * Entry point when the script is executed directly.
 *
 * composer forks script hooks onto a real terminal only while it is
 * interactive (EventDispatcher::executeTty); with --no-interaction it
 * runs them on a pipe, so stream_isatty() mirrors composer's own mode.
 */
function main(): int
{
    return bootstrapProject(
        \dirname(__DIR__),
        STDIN,
        STDOUT,
        \stream_isatty(STDIN),
        (array) \getenv()
    );
}

/**
 * Visits every template-relevant text file below the root.
 *
 * Skips dependency directories, binary files, and this script itself.
 * The visitor receives the relative path and the file content.
 *
 * @param callable(string, string): void $visitor
 */
function walkTemplateFiles(string $root, callable $visitor): void
{
    $skipped = ['/vendor/', '/node_modules/', '/.git/'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = \str_replace('\\', '/', \ltrim(\substr($path, \strlen($root)), '/'));
        $skip = $path === __FILE__;

        foreach ($skipped as $directory) {
            if (\str_contains('/' . $relative . '/', $directory)) {
                $skip = true;
                break;
            }
        }

        if ($skip) {
            continue;
        }

        $content = \file_get_contents($path);

        if ($content === false || \str_contains(\substr($content, 0, 8192), "\0")) {
            continue;
        }

        $visitor($relative, $content);
    }
}

/**
 * Replaces template references with the target package name.
 *
 * Returns the sorted list of changed files relative to the root.
 *
 * @return string[]
 */
function rewriteTemplateReferences(string $root, string $target): array
{
    $patterns = ['projek-xyz/php-lib-template', 'projek-xyz/template'];
    $changed = [];

    walkTemplateFiles($root, function (
        string $relative,
        string $content
    ) use (
        $root,
        $target,
        $patterns,
        &$changed
    ): void {
        if (! \str_contains($content, 'projek-xyz/')) {
            return;
        }

        $replaced = \str_replace($patterns, $target, $content);

        if ($replaced !== $content) {
            \file_put_contents($root . '/' . $relative, $replaced);
            $changed[] = $relative;
        }
    });

    \sort($changed);

    return $changed;
}

/**
 * Resets root package versions to 0.0.0.
 *
 * Touches package.json and the two root entries of package-lock.json;
 * dependency versions and composer.lock stay untouched.
 */
function resetPackageVersions(string $root): void
{
    $targets = ['package.json', 'package-lock.json'];

    foreach ($targets as $file) {
        $path = $root . '/' . $file;

        if (! \is_file($path)) {
            continue;
        }

        $content = \file_get_contents($path);

        if ($content === false) {
            continue;
        }

        $content = \preg_replace('/("version":\s*")[^"]*(")/', '${1}0.0.0${2}', $content, 1);

        if ($file === 'package-lock.json' && $content) {
            $content = \preg_replace(
                '/("packages":\s*\{\s*"":\s*\{[^}]*?"version":\s*")[^"]*(")/',
                '${1}0.0.0${2}',
                $content,
                1
            );
        }

        \file_put_contents($path, $content);
    }
}

/**
 * Removes a file or directory tree, tolerating missing paths.
 */
function removePath(string $path): bool
{
    if (\is_file($path)) {
        return \unlink($path);
    }

    if (! \is_dir($path)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    /** @var \SplFileInfo $item */
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            \rmdir($item->getPathname());
            continue;
        }

        \unlink($item->getPathname());
    }

    return \rmdir($path);
}

/**
 * Removes template-only files, mode- and capability-aware.
 *
 * Lock files are only deleted on the github path, and only when the tool
 * that regenerates them is available. Returns the sorted removed paths.
 *
 * @param array{composer?: bool, npm?: bool} $capabilities
 * @return string[]
 */
function removeTemplateOnlyFiles(string $root, bool $githubMode, array $capabilities): array
{
    $targets = [
        '.github/README.md',
        '.github/workflows/init.yml',
        '.agents/rules',
        'CHANGELOG.md',
        // the spec mirrors scripts/, which self-deletes: never ship it alone
        'tests/spec/scripts',
    ];

    if ($githubMode) {
        if (! empty($capabilities['composer'])) {
            $targets[] = 'composer.lock';
        }

        if (! empty($capabilities['npm'])) {
            $targets[] = 'package-lock.json';
        }
    }

    $removed = [];

    foreach ($targets as $target) {
        if (removePath($root . '/' . $target)) {
            $removed[] = $target;
        }
    }

    \sort($removed);

    return $removed;
}

/**
 * Activates the commented export-ignore list in .gitattributes.
 *
 * Returns the number of entries activated.
 */
function uncommentExportIgnoreList(string $root): int
{
    $path = $root . '/.gitattributes';

    if (! \is_file($path)) {
        return 0;
    }

    $content = \file_get_contents($path);

    if ($content === false) {
        return 0;
    }

    $activated = 0;
    $content = \preg_replace_callback(
        '/^# (.*export-ignore)$/m',
        function (array $matches) use (&$activated): string {
            $activated++;

            return $matches[1];
        },
        $content
    );

    \file_put_contents($path, $content);

    return $activated;
}

/**
 * Verifies the transformation, returning human-readable failures.
 *
 * An empty array means the tree is clean. With no target (interactive
 * name prompt skipped) reference and name checks are omitted.
 *
 * @return string[]
 */
function verifyTransformation(string $root, ?string $target, bool $githubMode): array
{
    $failures = [];

    $mustBeGone = [
        '.github/README.md',
        '.github/workflows/init.yml',
        '.agents/rules',
        'CHANGELOG.md',
    ];

    foreach ($mustBeGone as $file) {
        if (\file_exists($root . '/' . $file)) {
            $failures[] = $file . ' still present';
        }
    }

    $packageJson = \file_get_contents($root . '/package.json');

    if ($packageJson === false || ! \str_contains($packageJson, '"version": "0.0.0"')) {
        $failures[] = 'package.json version is not 0.0.0';
    }

    $attributes = \file_get_contents($root . '/.gitattributes');
    $totalEntries = $attributes === false ? 0 : \preg_match_all('/^.*export-ignore$/m', $attributes);
    $activeEntries = $attributes === false ? 0 : \preg_match_all('/^[^#\s].*export-ignore$/m', $attributes);

    if ($totalEntries === 0 || $activeEntries !== $totalEntries) {
        $failures[] = 'export-ignore entries still commented out';
    }

    if ($target !== null) {
        $composerJson = \file_get_contents($root . '/composer.json');

        if (
            $composerJson === false
            || \preg_match('/"name":\s*"' . \preg_quote($target, '/') . '"/', $composerJson) !== 1
        ) {
            $failures[] = 'composer.json name is not ' . $target;
        }

        if (
            $packageJson === false
            || \preg_match('/"name":\s*"@' . \preg_quote($target, '/') . '"/', $packageJson) !== 1
        ) {
            $failures[] = 'package.json name is not @' . $target;
        }

        $patterns = ['projek-xyz/template', 'projek-xyz/php-lib-template'];

        walkTemplateFiles($root, function (
            string $relative,
            string $content
        ) use (
            &$failures,
            $patterns
        ): void {
            if (\str_contains($content, $patterns[0]) || \str_contains($content, $patterns[1])) {
                $failures[] = 'residual template reference in ' . $relative;
            }
        });
    }

    if (! $githubMode) {
        if (! \is_file($root . '/composer.lock')) {
            $failures[] = 'composer.lock missing';
        }

        if (! \is_file($root . '/package-lock.json')) {
            $failures[] = 'package-lock.json missing';
        }
    }

    return $failures;
}

/**
 * Removes the post-create-project-cmd hook from the project composer.json.
 *
 * Returns true when the hook was present and has been stripped.
 */
function removeSelfFromComposerJson(string $root): bool
{
    $path = $root . '/composer.json';

    if (! \is_file($path)) {
        return false;
    }

    $content = \file_get_contents($path);

    if ($content === false) {
        return false;
    }

    $data = \json_decode($content, true);

    if (
        ! \is_array($data)
        || ! isset($data['scripts'])
        || ! \is_array($data['scripts'])
        || ! \array_key_exists('post-create-project-cmd', $data['scripts'])
    ) {
        return false;
    }

    unset($data['scripts']['post-create-project-cmd']);

    // scripts/ is about to self-delete; phpcs/phpcbf exit non-zero on missing paths
    foreach (['format', 'lint'] as $name) {
        if (isset($data['scripts'][$name]) && \is_string($data['scripts'][$name])) {
            $data['scripts'][$name] = \preg_replace('/\sscripts(?=\s|$)/', '', $data['scripts'][$name], 1);
        }
    }

    if ($data['scripts'] === []) {
        unset($data['scripts']);
    }

    $encoded = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return false;
    }

    \file_put_contents($path, $encoded . "\n");

    return true;
}

if (\realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
