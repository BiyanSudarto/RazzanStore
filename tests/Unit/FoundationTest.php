<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FoundationTest extends TestCase
{
    public function test_basic_runtime_assertion_passes(): void
    {
        self::assertTrue(true);
    }
}
