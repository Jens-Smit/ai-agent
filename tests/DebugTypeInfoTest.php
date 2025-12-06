<?php
namespace App\Tests;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

class DebugTypeInfoTest extends KernelTestCase
{
    public function testUserTypeResolution(): void
    {
        self::bootKernel();
        
        $resolver = new TypeResolver();
        $reflection = new \ReflectionClass(User::class);
        
        foreach ($reflection->getProperties() as $property) {
            echo "Testing property: {$property->getName()}\n";
            
            try {
                $type = $resolver->resolve($property);
                echo "  ✅ OK\n";
            } catch (\Throwable $e) {
                echo "  ❌ ERROR: {$e->getMessage()}\n";
                throw $e;
            }
        }
    }
}