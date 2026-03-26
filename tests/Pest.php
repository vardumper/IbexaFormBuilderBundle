<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/
uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/
expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeTwo', function () {
    return $this->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Create a PHPUnit mock from global scope.
 */
function testMock(string $class): PHPUnit\Framework\MockObject\MockObject
{
    static $factory;
    if ($factory === null) {
        $factory = new class('test') extends TestCase {
            public function mock(string $class): PHPUnit\Framework\MockObject\MockObject
            {
                return $this->createMock($class);
            }
        };
    }

    return $factory->mock($class);
}

/**
 * Bootstrap a fresh in-memory SQLite EntityManager (with FormSubmission schema).
 */
function sqliteEm(): Doctrine\ORM\EntityManagerInterface
{
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration(
        [__DIR__ . '/../src/Entity/'],
        true,
        sys_get_temp_dir() . '/doctrine_proxies_form_builder',
    );
    $config->setNamingStrategy(new Doctrine\ORM\Mapping\UnderscoreNamingStrategy());

    $connection = Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);

    $em = new Doctrine\ORM\EntityManager($connection, $config);

    $schemaTool = new Doctrine\ORM\Tools\SchemaTool($em);
    $schemaTool->createSchema([
        $em->getClassMetadata(vardumper\IbexaFormBuilderBundle\Entity\FormSubmission::class),
    ]);

    return $em;
}
