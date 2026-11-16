<?php

namespace Tests\Unit;

use Tests\TestCase;

class SodiumExtensionTest extends TestCase
{
    public function test_sodium_is_loaded(): void
    {
        $this->assertTrue(extension_loaded('sodium'), 'ext-sodium must be loaded');
    }
}
