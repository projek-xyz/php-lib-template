<?php

use Projek\FooBar;
use function Kahlan\{describe, expect, given};

describe(FooBar::class, function () {
    given('foobar', function () {
        return new FooBar;
    });

    it('Should be an instance of', function () {
        expect($this->foobar)->toBeAnInstanceOf(FooBar::class);
    });

    it('Should be equal', function () {
        expect($this->foobar->lorem())->toEqual('Lorem ipsum');
    });
});
