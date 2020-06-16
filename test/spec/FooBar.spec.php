<?php

use Projek\FooBar;
use function Kahlan\describe;
use function Kahlan\expect;
use function Kahlan\given;

describe(FooBar::class, function () {
    given('foobar', function () {
        return new FooBar;
    });

    it('could handle array callable', function () {
        expect($this->foobar)->toBeAnInstanceOf(FooBar::class);
    });
});
