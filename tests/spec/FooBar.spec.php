<?php

declare(strict_types=1);

use Projek\FooBar;

use function Kahlan\{describe, expect, it};

describe(FooBar::class, function () {
    it('should be an instance of', function () {
        expect(new FooBar())->toBeAnInstanceOf(FooBar::class);
    });

    it('should be equal', function () {
        expect((new FooBar())->lorem())->toEqual('Lorem ipsum');
    });
});
