<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps "a component was rendered" in static state, which would leak from one test's Livewire::test()
        // into the next test's plain page and inject assets into pages that must stay free of them.
        Livewire::flushState();
    }
}
