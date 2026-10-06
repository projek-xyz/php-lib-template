<?php

declare(strict_types=1);

use function Kahlan\{afterEach, beforeEach, describe, expect, it};
use function Projek\bootstrapProject;
use function Projek\defaultPackageName;
use function Projek\initializeGitRepository;
use function Projek\isCommandAvailable;
use function Projek\promptGitInit;
use function Projek\promptPackageName;
use function Projek\removeSelfFromComposerJson;
use function Projek\removeTemplateOnlyFiles;
use function Projek\resolvePackageInput;
use function Projek\resetPackageVersions;
use function Projek\rewriteTemplateReferences;
use function Projek\runCommand;
use function Projek\templateUsername;
use function Projek\uncommentExportIgnoreList;
use function Projek\verifyTransformation;

require_once dirname(__DIR__, 3) . '/scripts/init.php';

/**
 * Builds a throwaway template tree mirroring the real repository shape.
 */
$makeFixture = function (): string {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'init-spec-' . bin2hex(random_bytes(6));

    foreach (['', '.github', '.github/workflows', '.agents/rules', 'src', 'vendor', 'node_modules', '.git'] as $dir) {
        mkdir($root . DIRECTORY_SEPARATOR . $dir, 0777, true);
    }

    $files = [
        'composer.json' => <<<'JSON'
{
    "name": "projek-xyz/template",
    "description": "Project Template",
    "scripts": {
        "post-create-project-cmd": [
            "@php scripts/init.php"
        ],
        "format": "phpcbf --standard=PSR12 src scripts",
        "lint": "phpcs --standard=PSR12 src scripts -p -n",
        "spec": "kahlan --config=tests/config.php"
    },
    "support": {
        "source": "https://github.com/projek-xyz/php-lib-template"
    },
    "require": {
        "php": ">=7.2"
    }
}
JSON,
        'package.json' => <<<'JSON'
{
    "name": "@projek-xyz/template",
    "private": true,
    "version": "0.6.0",
    "repository": "github:projek-xyz/template"
}
JSON,
        'package-lock.json' => <<<'JSON'
{
    "name": "@projek-xyz/template",
    "version": "0.6.0",
    "lockfileVersion": 3,
    "packages": {
        "": {
            "name": "@projek-xyz/template",
            "version": "0.6.0"
        },
        "node_modules/kahlan": {
            "version": "6.1.0"
        }
    }
}
JSON,
        'composer.lock' => <<<'JSON'
{
    "content-hash": "abc123",
    "packages": [
        {
            "name": "symfony/console",
            "version": "v6.4.0"
        }
    ]
}
JSON,
        'README.md' => <<<'MD'
[![Version](https://img.shields.io/packagist/v/projek-xyz/template?style=flat-square)](https://packagist.org/packages/projek-xyz/template)
[![License](https://img.shields.io/github/license/projek-xyz/php-lib-template?style=flat-square)](https://github.com/projek-xyz/php-lib-template/blob/main/LICENSE)

# Our new awesome project

> [!NOTE]
> If you see this message, means our owner forgot to update this file.
MD,
        '.github/README.md' => <<<'MD'
# Our lib template

2. Via Composer `create-project`

   ```sh
   composer create-project projek-xyz/template
   ```
MD,
        '.github/workflows/init.yml' => <<<'YML'
name: Init Repo
on:
  push:
    branches: [main]
jobs:
  init:
    if: github.event.created == true
YML,
        '.github/workflows/tests.yml' => <<<'YML'
name: Tests
jobs:
  prepare:
    uses: projek-xyz/actions/.github/workflows/prepare.yml@ce09c15
YML,
        'CHANGELOG.md' => "## [0.6.0]\n\n- https://github.com/projek-xyz/php-lib-template/compare/v0.5.0...v0.6.0\n",
        '.gitattributes' => "# Enforce Unix newlines\n* text=lf\n\n# .agents            export-ignore\n# .github            export-ignore\nCHANGELOG.md       export-ignore\ncomposer.lock      export-ignore\n",
        '.gitignore' => "vendor\nnode_modules\n.env\n",
        '.agents/rules/bootstrap.md' => "# Agent rules\n\nNo template references in here.\n",
        'src/FooBar.php' => "<?php\n\nnamespace Projek;\n\nclass FooBar\n{\n}\n",
        'vendor/autoload.php' => "<?php // vendor stub for projek-xyz/template\n",
        'node_modules/.stub.js' => "// node stub for projek-xyz/template\n",
        '.git/config' => "[core]\n[remote \"origin\"]\n\turl = https://github.com/projek-xyz/php-lib-template.git\n",
    ];

    foreach ($files as $path => $content) {
        file_put_contents($root . DIRECTORY_SEPARATOR . $path, $content);
    }

    file_put_contents(
        $root . DIRECTORY_SEPARATOR . 'logo.png',
        "\x89PNG\x00\x0D\x0A\x1A\x0Aprojek-xyz/template\x00\x00IEND"
    );

    return $root;
};

$removeFixture = function (string $root): void {
    exec('rm -rf ' . escapeshellarg($root));
};

describe('defaultPackageName', function () {
    it('combines the host username and directory name in lower case', function () {
        expect(defaultPackageName('my-lib', 'fery'))->toEqual('fery/my-lib');
    });

    it('sanitizes directory names into composer-safe segments', function () {
        expect(defaultPackageName('My Project', 'fery'))->toEqual('fery/my-project');
    });

    it('falls back to the template name when no username can be determined', function () {
        expect(defaultPackageName('my-lib', null))->toEqual('projek-xyz/template');
    });
});

describe('templateUsername', function () {
    $saved = [];

    beforeEach(function () use (&$saved) {
        $saved = [
            'USER' => getenv('USER'),
            'LOGNAME' => getenv('LOGNAME'),
            'USERNAME' => getenv('USERNAME'),
        ];
    });

    afterEach(function () use (&$saved) {
        foreach ($saved as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $value);
        }
    });

    it('reads the username from USER', function () {
        putenv('USER=fery-test');
        putenv('LOGNAME=fery-logname');

        expect(templateUsername())->toEqual('fery-test');
    });

    it('falls back to LOGNAME when USER is missing', function () {
        putenv('USER=');
        putenv('LOGNAME=fery-logname');

        expect(templateUsername())->toEqual('fery-logname');
    });

    it('falls back to USERNAME on systems without USER or LOGNAME', function () {
        putenv('USER');
        putenv('LOGNAME');
        putenv('USERNAME=fery-windows');

        expect(templateUsername())->toEqual('fery-windows');
    });

    it('returns null when no username variable is set', function () {
        putenv('USER');
        putenv('LOGNAME');
        putenv('USERNAME');

        expect(templateUsername())->toBeNull();
    });
});

describe('resolvePackageInput', function () {
    $default = 'fery/my-lib';

    it('uses the default on an empty answer', function () use ($default) {
        expect(resolvePackageInput('', $default))->toEqual(['fery/my-lib', null]);
    });

    it('falls back to the default with a warning on invalid format', function () use ($default) {
        expect(resolvePackageInput('vendor-name', $default))->toEqual([
            'fery/my-lib',
            'Invalid package name format, fallback to default fery/my-lib',
        ]);
    });

    it('accepts and lower-cases a valid package name', function () use ($default) {
        expect(resolvePackageInput('  Fery/My-Lib' . "\n", $default))->toEqual(['fery/my-lib', null]);
    });

    it('returns no package on end of input', function () use ($default) {
        expect(resolvePackageInput(null, $default))->toEqual([null, null]);
    });
});

describe('rewriteTemplateReferences', function () use ($makeFixture, $removeFixture) {
    $root = null;

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('replaces both template references across text files', function () use (&$root) {
        rewriteTemplateReferences($root, 'fery/my-lib');

        $composer = file_get_contents($root . '/composer.json');
        $package = file_get_contents($root . '/package.json');
        $readme = file_get_contents($root . '/README.md');
        $githubReadme = file_get_contents($root . '/.github/README.md');

        expect($composer)->toContain('"name": "fery/my-lib"');
        expect($composer)->toContain('https://github.com/fery/my-lib');
        expect($package)->toContain('"name": "@fery/my-lib"');
        expect($package)->toContain('"repository": "github:fery/my-lib"');
        expect($readme)->toContain('https://packagist.org/packages/fery/my-lib');
        expect($readme)->not->toContain('projek-xyz');
        expect($githubReadme)->not->toContain('projek-xyz');
    });

    it('reports every changed file relative to the root', function () use (&$root) {
        $changed = rewriteTemplateReferences($root, 'fery/my-lib');

        expect($changed)->toEqual([
            '.github/README.md',
            'CHANGELOG.md',
            'README.md',
            'composer.json',
            'package-lock.json',
            'package.json',
        ]);
    });

    it('leaves ignored directories alone', function () use (&$root) {
        rewriteTemplateReferences($root, 'fery/my-lib');

        expect(file_get_contents($root . '/vendor/autoload.php'))
            ->toContain('projek-xyz/template');
        expect(file_get_contents($root . '/node_modules/.stub.js'))
            ->toContain('projek-xyz/template');
        expect(file_get_contents($root . '/.git/config'))
            ->toContain('projek-xyz/php-lib-template');
    });

    it('leaves binary files alone', function () use (&$root) {
        rewriteTemplateReferences($root, 'fery/my-lib');

        $logo = file_get_contents($root . '/logo.png');

        expect($logo)->toEqual("\x89PNG\x00\x0D\x0A\x1A\x0Aprojek-xyz/template\x00\x00IEND");
    });

    it('leaves files without references untouched', function () use (&$root) {
        $changed = rewriteTemplateReferences($root, 'fery/my-lib');

        expect($changed)->not->toContain('src/FooBar.php');
        expect(file_get_contents($root . '/src/FooBar.php'))
            ->toEqual("<?php\n\nnamespace Projek;\n\nclass FooBar\n{\n}\n");
    });
});

describe('resetPackageVersions', function () use ($makeFixture, $removeFixture) {
    $root = null;

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('resets the root version in package.json', function () use (&$root) {
        resetPackageVersions($root);

        $package = file_get_contents($root . '/package.json');

        expect($package)->toContain('"version": "0.0.0"');
        expect($package)->not->toContain('0.6.0');
    });

    it('resets both root versions in package-lock.json but keeps dependency versions', function () use (&$root) {
        resetPackageVersions($root);

        $lock = file_get_contents($root . '/package-lock.json') ?: '';

        expect(substr_count($lock, '"version": "0.0.0"'))->toEqual(2);
        expect($lock)->not->toContain('"version": "0.6.0"');
        expect($lock)->toContain('"version": "6.1.0"');
    });

    it('leaves composer.lock alone', function () use (&$root) {
        resetPackageVersions($root);

        $composerLock = file_get_contents($root . '/composer.lock');

        expect($composerLock)->toContain('"version": "v6.4.0"');
        expect($composerLock)->not->toContain('0.0.0');
    });
});

describe('removeTemplateOnlyFiles', function () use ($makeFixture, $removeFixture) {
    $root = null;
    $bothCaps = ['composer' => true, 'npm' => true];

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();

        // the spec that requires scripts/init.php must never outlive it
        mkdir($root . '/tests/spec/scripts', 0777, true);
        file_put_contents($root . '/tests/spec/scripts/init.spec.php', '<?php // template infra');
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('removes template-only files including both locks in github mode', function () use (&$root, $bothCaps) {
        removeTemplateOnlyFiles($root, true, $bothCaps);

        expect(file_exists($root . '/.github/README.md'))->toBe(false);
        expect(file_exists($root . '/.github/workflows/init.yml'))->toBe(false);
        expect(file_exists($root . '/.agents/rules'))->toBe(false);
        expect(file_exists($root . '/CHANGELOG.md'))->toBe(false);
        expect(file_exists($root . '/composer.lock'))->toBe(false);
        expect(file_exists($root . '/package-lock.json'))->toBe(false);
        expect(file_exists($root . '/tests/spec/scripts/init.spec.php'))->toBe(false);

        // The instantiation gate must survive.
        expect(file_exists($root . '/.github/workflows/tests.yml'))->toBe(true);
    });

    it('keeps both locks on the composer path', function () use (&$root, $bothCaps) {
        removeTemplateOnlyFiles($root, false, $bothCaps);

        expect(file_exists($root . '/composer.lock'))->toBe(true);
        expect(file_exists($root . '/package-lock.json'))->toBe(true);
        expect(file_exists($root . '/CHANGELOG.md'))->toBe(false);
        expect(file_exists($root . '/.github/README.md'))->toBe(false);
        expect(file_exists($root . '/tests/spec/scripts/init.spec.php'))->toBe(false);
    });

    it('keeps locks whose regenerating tool is unavailable', function () use (&$root) {
        removeTemplateOnlyFiles($root, true, ['composer' => false, 'npm' => false]);

        expect(file_exists($root . '/composer.lock'))->toBe(true);
        expect(file_exists($root . '/package-lock.json'))->toBe(true);
        expect(file_exists($root . '/CHANGELOG.md'))->toBe(false);
    });

    it('reports the removed files', function () use (&$root, $bothCaps) {
        $removed = removeTemplateOnlyFiles($root, true, $bothCaps);

        expect($removed)->toEqual([
            '.agents/rules',
            '.github/README.md',
            '.github/workflows/init.yml',
            'CHANGELOG.md',
            'composer.lock',
            'package-lock.json',
            'tests/spec/scripts',
        ]);
    });
});

describe('uncommentExportIgnoreList', function () use ($makeFixture, $removeFixture) {
    $root = null;

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('activates commented export-ignore entries', function () use (&$root) {
        uncommentExportIgnoreList($root);

        $attributes = file_get_contents($root . '/.gitattributes');

        expect($attributes)->toContain('.agents            export-ignore');
        expect($attributes)->toContain('.github            export-ignore');
        expect($attributes)->not->toContain('# .agents');
        expect($attributes)->not->toContain('# .github');
    });

    it('leaves unrelated comments and active entries alone', function () use (&$root) {
        uncommentExportIgnoreList($root);

        $attributes = file_get_contents($root . '/.gitattributes');

        expect($attributes)->toContain('# Enforce Unix newlines');
        expect($attributes)->toContain('* text=lf');
        expect(substr_count($attributes ?: '', 'composer.lock      export-ignore'))->toEqual(1);
        expect(substr_count($attributes ?: '', 'CHANGELOG.md       export-ignore'))->toEqual(1);
    });

    it('reports how many entries it activated', function () use (&$root) {
        expect(uncommentExportIgnoreList($root))->toEqual(2);
    });
});

describe('verifyTransformation', function () use ($makeFixture, $removeFixture) {
    $root = null;

    /** Runs the transformation steps in main() order, optionally skipping some. */
    $transform = function (string $root, ?string $target, bool $githubMode, array $skip = []): void {
        if (! in_array('rewrite', $skip, true) && $target !== null) {
            rewriteTemplateReferences($root, $target);
        }

        resetPackageVersions($root);
        removeTemplateOnlyFiles($root, $githubMode, ['composer' => true, 'npm' => true]);

        if (! in_array('uncomment', $skip, true)) {
            uncommentExportIgnoreList($root);
        }
    };

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('passes on a fully transformed tree', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', false);

        expect(verifyTransformation($root, 'fery/my-lib', false))->toEqual([]);
    });

    it('passes without a target when no name was chosen', function () use (&$root, $transform) {
        $transform($root, null, false);

        expect(verifyTransformation($root, null, false))->toEqual([]);
    });

    it('reports residual template references', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', true);
        file_put_contents($root . '/README.md', "badge projek-xyz/template badge\n");

        expect(verifyTransformation($root, 'fery/my-lib', true))
            ->toContain('residual template reference in README.md');
    });

    it('reports unreset versions', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', true);
        file_put_contents($root . '/package.json', "{\n    \"version\": \"0.6.0\"\n}\n");

        expect(verifyTransformation($root, 'fery/my-lib', true))
            ->toContain('package.json version is not 0.0.0');
    });

    it('reports template files still present', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', true);
        file_put_contents($root . '/CHANGELOG.md', "## [0.6.0]\n");

        $failures = verifyTransformation($root, 'fery/my-lib', true);

        expect($failures)->toContain('CHANGELOG.md still present');
    });

    it('reports a still-commented export-ignore list', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', true, ['uncomment']);

        expect(verifyTransformation($root, 'fery/my-lib', true))
            ->toContain('export-ignore entries still commented out');
    });

    it('checks package names against the target', function () use (&$root, $transform) {
        $transform($root, 'other/lib', true);

        expect(verifyTransformation($root, 'fery/my-lib', true))
            ->toContain('composer.json name is not fery/my-lib');
    });

    it('requires fresh locks on the composer path', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', false);
        unlink($root . '/composer.lock');

        expect(verifyTransformation($root, 'fery/my-lib', false))
            ->toContain('composer.lock missing');
    });

    it('does not require locks during github instantiation', function () use (&$root, $transform) {
        $transform($root, 'fery/my-lib', true);

        expect(verifyTransformation($root, 'fery/my-lib', true))->toEqual([]);
    });
});

describe('removeSelfFromComposerJson', function () use ($makeFixture, $removeFixture) {
    $root = null;

    beforeEach(function () use (&$root, $makeFixture) {
        $root = $makeFixture();
    });

    afterEach(function () use (&$root, $removeFixture) {
        $removeFixture($root);
        $root = null;
    });

    it('removes the bootstrap hook but keeps other scripts', function () use (&$root) {
        expect(removeSelfFromComposerJson($root))->toBe(true);

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);

        expect($data['scripts'])->not->toContainKey('post-create-project-cmd');
        expect($data['scripts'])->toContainKey('format');
        expect($data['scripts'])->toContainKey('spec');
    });

    it('rewrites format and lint so they no longer point at the removed scripts dir', function () use (&$root) {
        removeSelfFromComposerJson($root);

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);

        // phpcs/phpcbf exit non-zero on a missing path, which would fail composer test
        expect($data['scripts']['format'])->toEqual('phpcbf --standard=PSR12 src');
        expect($data['scripts']['lint'])->toEqual('phpcs --standard=PSR12 src -p -n');
    });

    it('writes composer-style pretty json with unescaped slashes', function () use (&$root) {
        removeSelfFromComposerJson($root);

        $composer = file_get_contents($root . '/composer.json');

        expect(substr($composer ?: '', 0, 14))->toEqual("{\n    \"name\": ");
        expect(substr($composer ?: '', -2))->toEqual("}\n");
        expect($composer)->toContain('"https://github.com/projek-xyz/php-lib-template"');
    });

    it('is idempotent and reports a missing hook', function () use (&$root) {
        removeSelfFromComposerJson($root);

        expect(removeSelfFromComposerJson($root))->toBe(false);
    });

    it('drops the scripts section when the hook was its only entry', function () use (&$root) {
        $minimal = "{\n    \"name\": \"projek-xyz/template\",\n    \"scripts\": {\n"
            . "        \"post-create-project-cmd\": [\n            \"@php scripts/init.php\"\n        ]\n    }\n}\n";
        file_put_contents($root . '/composer.json', $minimal);

        expect(removeSelfFromComposerJson($root))->toBe(true);

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);

        expect($data)->not->toContainKey('scripts');
    });
});

describe('promptPackageName', function () {
    $ask = function (string $input): array {
        $in = fopen('php://memory', 'r+');

        if (!$in) {
            return [];
        }

        fwrite($in, $input);
        rewind($in);
        $out = fopen('php://memory', 'r+');

        if (!$out) {
            return [];
        }

        $package = promptPackageName($in, $out, 'fery/my-lib');

        rewind($out);
        $output = stream_get_contents($out);
        fclose($in);
        fclose($out);

        return [$package, $output];
    };

    it('asks with the default shown', function () use ($ask) {
        [$package, $output] = $ask("\n");

        expect($package)->toEqual('fery/my-lib');
        expect($output)->toContain('Package name [fery/my-lib]:');
    });

    it('accepts a valid package name in lower case', function () use ($ask) {
        [$package] = $ask("Acme/Lib\n");

        expect($package)->toEqual('acme/lib');
    });

    it('warns with a yellow fallback on invalid input', function () use ($ask) {
        [$package, $output] = $ask("vendor-name\n");

        expect($package)->toEqual('fery/my-lib');
        expect($output)->toContain('Invalid package name format, fallback to default');
        expect($output)->toContain("\033[33mfery/my-lib\033[39m");
    });

    it('returns null on end of input', function () use ($ask) {
        [$package] = $ask('');

        expect($package)->toBeNull();
    });
});

describe('promptGitInit', function () {
    $ask = function (string $input): array {
        $in = fopen('php://memory', 'r+');

        if (!$in) {
            return [];
        }

        fwrite($in, $input);
        rewind($in);
        $out = fopen('php://memory', 'r+');

        if (!$out) {
            return [];
        }

        $init = promptGitInit($in, $out, 'fery/my-lib');

        rewind($out);
        $output = stream_get_contents($out);
        fclose($in);
        fclose($out);

        return [$init, $output];
    };

    it('shows the repository name and default answer', function () use ($ask) {
        [$init, $output] = $ask("\n");

        expect($init)->toBe(false);
        expect($output)->toContain("\033[33mfery/my-lib\033[39m repo? [y/N]");
    });

    it('accepts yes in any case', function () use ($ask) {
        [$yes] = $ask("y\n");
        [$upper] = $ask("YES\n");

        expect($yes)->toBe(true);
        expect($upper)->toBe(true);
    });

    it('rejects explicit no', function () use ($ask) {
        [$init] = $ask("N\n");

        expect($init)->toBe(false);
    });

    it('returns false on end of input', function () use ($ask) {
        [$init] = $ask('');

        expect($init)->toBe(false);
    });
});

describe('isCommandAvailable', function () {
    it('finds commands on the path', function () {
        expect(isCommandAvailable('git'))->toBe(true);
    });

    it('rejects unknown commands', function () {
        expect(isCommandAvailable('definitely-not-a-command-xyz'))->toBe(false);
    });
});

describe('initializeGitRepository', function () {
    $savedGitEnv = [];

    beforeEach(function () use (&$savedGitEnv) {
        $savedGitEnv = [
            'GIT_AUTHOR_NAME' => getenv('GIT_AUTHOR_NAME'),
            'GIT_AUTHOR_EMAIL' => getenv('GIT_AUTHOR_EMAIL'),
            'GIT_COMMITTER_NAME' => getenv('GIT_COMMITTER_NAME'),
            'GIT_COMMITTER_EMAIL' => getenv('GIT_COMMITTER_EMAIL'),
        ];
    });

    afterEach(function () use (&$savedGitEnv) {
        foreach ($savedGitEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $value);
        }
    });

    $removeFixture = function (string $root): void {
        exec('rm -rf ' . escapeshellarg($root));
    };

    $makeRepo = function (): string {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'init-git-spec-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/hello.txt', "hello\n");

        return $root;
    };

    $identity = [
        'GIT_AUTHOR_NAME' => 'Spec Bot',
        'GIT_AUTHOR_EMAIL' => 'spec@example.com',
        'GIT_COMMITTER_NAME' => 'Spec Bot',
        'GIT_COMMITTER_EMAIL' => 'spec@example.com',
    ];

    $withoutIdentity = function (): array {
        $home = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'init-home-spec-' . bin2hex(random_bytes(6));
        mkdir($home, 0777, true);

        return [
            'HOME' => $home,
            'GIT_CONFIG_GLOBAL' => $home . '/gitconfig',
            'GIT_CONFIG_SYSTEM' => $home . '/systemconfig',
        ];
    };

    it(
        'initializes, stages, and commits when identity is available',
        function () use ($makeRepo, $removeFixture, $identity) {
            $root = $makeRepo();

            expect(initializeGitRepository($root, 'chore: initial commit :fire:', $identity))
                ->toEqual('committed');

            expect(is_dir($root . '/.git'))->toBe(true);

            [$code, $output] = runCommand('git -C ' . escapeshellarg($root) . ' log -1 --format=%s');
            expect($code)->toEqual(0);
            expect($output)->toEqual('chore: initial commit :fire:');

            [$code, $tracked] = runCommand('git -C ' . escapeshellarg($root) . ' ls-files');
            expect($tracked)->toContain('src/hello.txt');

            [$code, $status] = runCommand('git -C ' . escapeshellarg($root) . ' status --porcelain');
            expect($status)->toEqual('');

            $removeFixture($root);
        }
    );

    it(
        'honors git identity provided through the environment',
        function () use ($makeRepo, $removeFixture, $withoutIdentity) {
            $root = $makeRepo();
            putenv('GIT_AUTHOR_NAME=Spec Bot');
            putenv('GIT_AUTHOR_EMAIL=spec@example.com');
            putenv('GIT_COMMITTER_NAME=Spec Bot');
            putenv('GIT_COMMITTER_EMAIL=spec@example.com');

            // config chain is neutralised, only the environment can supply identity
            expect(initializeGitRepository($root, 'chore: initial commit :fire:', $withoutIdentity()))
                ->toEqual('committed');

            $removeFixture($root);
        }
    );

    it(
        'keeps the repository initialized but skips the commit without identity',
        function () use ($makeRepo, $removeFixture, $withoutIdentity) {
            $root = $makeRepo();

            expect(initializeGitRepository($root, 'chore: initial commit :fire:', $withoutIdentity()))
                ->toEqual('no-identity');

            expect(is_dir($root . '/.git'))->toBe(true);

            [$code] = runCommand('git -C ' . escapeshellarg($root) . ' log -1');
            expect($code)->not->toEqual(0);

            $removeFixture($root);
        }
    );

    it('leaves an existing repository alone', function () use ($makeRepo, $removeFixture) {
        $root = $makeRepo();
        runCommand('git -C ' . escapeshellarg($root) . ' init --quiet');

        expect(initializeGitRepository($root, 'chore: initial commit :fire:', null))
            ->toEqual('already-git');

        [$code] = runCommand('git -C ' . escapeshellarg($root) . ' log -1');
        expect($code)->not->toEqual(0);

        $removeFixture($root);
    });

    it('reports when git is unavailable', function () use ($makeRepo, $removeFixture) {
        $root = $makeRepo();
        $emptyBin = $root . '/empty-bin';
        mkdir($emptyBin);

        expect(initializeGitRepository($root, 'chore: initial commit :fire:', ['PATH' => $emptyBin]))
            ->toEqual('git-not-found');

        expect(is_dir($root . '/.git'))->toBe(false);

        $removeFixture($root);
    });
});

describe('bootstrapProject', function () use ($makeFixture, $removeFixture) {
    $savedGitEnv = [];

    beforeEach(function () use (&$savedGitEnv) {
        $savedGitEnv = [
            'GIT_AUTHOR_NAME' => getenv('GIT_AUTHOR_NAME'),
            'GIT_AUTHOR_EMAIL' => getenv('GIT_AUTHOR_EMAIL'),
            'GIT_COMMITTER_NAME' => getenv('GIT_COMMITTER_NAME'),
            'GIT_COMMITTER_EMAIL' => getenv('GIT_COMMITTER_EMAIL'),
        ];
        putenv('GIT_AUTHOR_NAME=Spec Bot');
        putenv('GIT_AUTHOR_EMAIL=spec@example.com');
        putenv('GIT_COMMITTER_NAME=Spec Bot');
        putenv('GIT_COMMITTER_EMAIL=spec@example.com');
    });

    afterEach(function () use (&$savedGitEnv) {
        foreach ($savedGitEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $value);
        }
    });

    it('commits the bootstrapped state as the initial commit', function () use ($makeFixture, $removeFixture) {
        $root = $makeFixture();
        // the dist tree ships without git metadata
        exec('rm -rf ' . escapeshellarg($root . '/.git'));

        $in = fopen('php://memory', 'r+');

        if (!$in) {
            return;
        }

        fwrite($in, "e2e/my-lib\ny\n");
        rewind($in);
        $out = fopen('php://memory', 'r+');

        if (!$out) {
            return;
        }

        $code = bootstrapProject($root, $in, $out, true, ['GITHUB_REPOSITORY' => '']);

        expect($code)->toEqual(0);
        expect(is_dir($root . '/.git'))->toBe(true);

        [$logCode, $message] = runCommand('git -C ' . escapeshellarg($root) . ' log -1 --format=%s');
        expect($logCode)->toEqual(0);
        expect($message)->toEqual('chore: initial commit :fire:');

        [, $committedComposer] = runCommand('git -C ' . escapeshellarg($root) . ' show HEAD:composer.json');
        expect($committedComposer)->toContain('"name": "e2e/my-lib"');
        expect($committedComposer)->not->toContain('post-create-project-cmd');

        [, $committedTree] = runCommand(
            'git -C ' . escapeshellarg($root) . ' show --pretty=format: --name-only HEAD'
        );
        expect($committedTree)->not->toContain('CHANGELOG.md');

        [, $worktree] = runCommand('git -C ' . escapeshellarg($root) . ' status --porcelain');
        expect($worktree)->toEqual('');

        fclose($in);
        fclose($out);
        $removeFixture($root);
    });

    it('skips all interaction when not attached to a terminal', function () use ($makeFixture, $removeFixture) {
        $root = $makeFixture();
        exec('rm -rf ' . escapeshellarg($root . '/.git'));

        mkdir($root . '/tests/spec/scripts', 0777, true);
        file_put_contents($root . '/tests/spec/scripts/init.spec.php', '<?php // template infra');

        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');

        if (!$in || !$out) {
            return;
        }

        $code = bootstrapProject($root, $in, $out, false, ['GITHUB_REPOSITORY' => '']);

        expect($code)->toEqual(0);
        expect(is_dir($root . '/.git'))->toBe(false);
        expect(file_exists($root . '/tests/spec/scripts/init.spec.php'))->toBe(false);

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);
        expect($data['name'])->toEqual('projek-xyz/template');

        fclose($in);
        fclose($out);
        $removeFixture($root);
    });
});

describe('main', function () use ($makeFixture, $removeFixture) {
    $script = dirname(__DIR__, 3) . '/scripts/init.php';

    /** Fixture plus a copy of the real bootstrap script. */
    $makeScriptedFixture = function () use ($makeFixture, $script): string {
        $root = $makeFixture();
        mkdir($root . '/scripts');
        copy($script, $root . '/scripts/init.php');

        return $root;
    };

    $run = function (string $root, array $overrides = []): array {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/init.php');

        return runCommand($command, $overrides);
    };

    it('bootstraps fully in github mode', function () use ($makeScriptedFixture, $removeFixture, $run) {
        $root = $makeScriptedFixture();

        [$code, $output] = $run($root, ['GITHUB_REPOSITORY' => 'fery/my-lib']);

        expect($code)->toEqual(0);
        expect($output)->toContain('removed CHANGELOG.md');
        expect(verifyTransformation($root, 'fery/my-lib', true))->toEqual([]);

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);
        expect($data['name'])->toEqual('fery/my-lib');
        expect($data['scripts'])->toContainKey('format');
        expect($data['scripts'])->not->toContainKey('post-create-project-cmd');

        expect(file_exists($root . '/.github/workflows/tests.yml'))->toBe(true);
        expect(file_exists($root . '/scripts/init.php'))->toBe(false);
        expect(file_exists($root . '/scripts'))->toBe(false);

        $removeFixture($root);
    });

    it('skips naming and keeps locks on the composer path without a terminal', function () use ($makeScriptedFixture, $removeFixture, $run) {
        $root = $makeScriptedFixture();

        [$code, $output] = $run($root, ['GITHUB_REPOSITORY' => '']);

        expect($code)->toEqual(0);
        expect($output)->not->toContain('Package name');
        expect($output)->not->toContain('removed composer.lock');

        $data = json_decode(file_get_contents($root . '/composer.json') ?: '{}', true);
        expect($data['name'])->toEqual('projek-xyz/template');
        expect($data['scripts'])->not->toContainKey('post-create-project-cmd');

        expect(file_exists($root . '/composer.lock'))->toBe(true);
        expect(file_exists($root . '/package-lock.json'))->toBe(true);
        expect(file_exists($root . '/CHANGELOG.md'))->toBe(false);
        expect(file_exists($root . '/scripts/init.php'))->toBe(false);

        $removeFixture($root);
    });

    it('fails loudly when verification fails', function () use ($makeScriptedFixture, $removeFixture, $run) {
        $root = $makeScriptedFixture();
        chmod($root . '/README.md', 0444);

        [$code, $output] = $run($root, ['GITHUB_REPOSITORY' => 'fery/my-lib']);

        expect($code)->toEqual(1);
        expect($output)->toContain('FAIL: residual template reference in README.md');

        $removeFixture($root);
    });
});
