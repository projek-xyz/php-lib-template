<?php
/**
 * @link https://kahlan.github.io/docs/config-file.html
 */

use Kahlan\Filter\Filters;
use Kahlan\Reporter\Coverage\Exporter;

/** @var Kahlan\Cli\CommandLine $cli */
$cli = $this->commandLine();
$cli->option('coverage', 'default', 3);
$cli->option('spec', 'default', ['tests/spec']);
$cli->option('lcov', 'default', 'tests/lcov.info');

Filters::apply($this, 'reporting', function ($next) {
    if (! $reporter = $this->reporters()->get('coverage')) {
        return;
    }

    $lcov_file = getenv('LCOV_REPORT') ?: $this->reporters()->get('lcov');

    if ($lcov_file) {
        Exporter\Lcov::write([
            'collector' => $reporter,
            'file'      => $lcov_file,
        ]);
    }

    return $next();
});

Filters::apply($this, 'run', function($next) {
    /** @var \Kahlan\Block\Group $group */
    $group = $this->suite()->root();
    /** @var \Kahlan\Scope $scope */
    $scope = $group->scope(); // The top most describe scope.

    $scope->stubsDir = function (string ...$paths) {
        array_unshift($paths, 'stubs');

        return __DIR__ . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $paths);
    };

    return $next();
});
