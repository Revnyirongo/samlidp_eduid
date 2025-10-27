<?php

namespace Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AppBootTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();

        $this->assertNotNull(self::$kernel);
        $this->assertNotNull(self::$kernel->getContainer());
        $this->assertTrue(self::$kernel->getContainer()->has('router'));
    }
}
