<?php

declare(strict_types=1);

namespace Tests\Arch;

use App\Payroll\Application\EventStore;
use App\Payroll\Domain\Adjustment;
use App\Payroll\Domain\EarningLine;
use App\Payroll\Domain\EarningLineRepository;
use App\Payroll\Domain\Event\DomainEvent;
use App\Payroll\Infrastructure\Persistence\DatabaseEventStore;
use App\Payroll\Infrastructure\Persistence\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Rules that the README promises, checked by reflection so they cannot rot:
 * the model is framework-free, dependencies point inwards, history is immutable.
 */
final class ArchitectureTest extends TestCase
{
    private const string APP = __DIR__.'/../../app';

    private static function appPath(): string
    {
        return realpath(self::APP) ?: self::APP;
    }

    /** Namespaces the domain and application layers must never mention, not even fully qualified. */
    private const array FRAMEWORK = ['Illuminate\\', 'Laravel\\', 'Carbon\\', 'Symfony\\'];

    #[Test]
    #[DataProvider('layers')]
    public function the_domain_and_application_layers_are_pure_php(string $layer): void
    {
        foreach (self::sourcesIn($layer) as $file => $source) {
            foreach (self::FRAMEWORK as $namespace) {
                self::assertStringNotContainsString($namespace, $source, "$file mentions $namespace");
            }

            self::assertDoesNotMatchRegularExpression(
                '/(?<![\w$>:])(app|config|env|resolve)\(/',
                $source,
                "$file calls a framework helper function",
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function layers(): iterable
    {
        yield 'domain' => ['Payroll/Domain'];
        yield 'application' => ['Payroll/Application'];
    }

    #[Test]
    public function dependencies_point_inwards_only(): void
    {
        $forbidden = [
            'Payroll/Domain' => ['App\\Payroll\\Application', 'App\\Payroll\\Infrastructure', 'App\\Console', 'App\\Providers'],
            'Payroll/Application' => ['App\\Payroll\\Infrastructure', 'App\\Console', 'App\\Providers'],
            'Payroll/Infrastructure' => ['App\\Console', 'App\\Providers'],
        ];

        foreach ($forbidden as $layer => $namespaces) {
            foreach (self::sourcesIn($layer) as $file => $source) {
                foreach ($namespaces as $namespace) {
                    self::assertStringNotContainsString($namespace, $source, "$file depends outward on $namespace");
                }
            }
        }
    }

    #[Test]
    public function every_domain_class_is_final_and_every_value_object_and_event_is_readonly(): void
    {
        foreach (self::classesIn('Payroll/Domain') as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isEnum() || $reflection->isAbstract()) {
                continue;
            }

            self::assertTrue($reflection->isFinal(), "$class must be final");

            $isAggregate = $class === EarningLine::class;
            $isException = $reflection->isSubclassOf(\Throwable::class);

            if (! $isAggregate && ! $isException) {
                self::assertTrue($reflection->isReadOnly(), "$class must be readonly");
            }
        }
    }

    #[Test]
    public function every_event_is_a_readonly_final_class_implementing_domain_event(): void
    {
        $events = array_filter(
            self::classesIn('Payroll/Domain/Event'),
            static fn (string $class): bool => ! (new ReflectionClass($class))->isInterface(),
        );

        self::assertCount(4, $events);

        foreach ($events as $event) {
            $reflection = new ReflectionClass($event);
            self::assertTrue($reflection->isReadOnly() && $reflection->isFinal(), "$event must be final readonly");
            self::assertTrue($reflection->implementsInterface(DomainEvent::class));
        }
    }

    /** @param  class-string  $class */
    #[Test]
    #[DataProvider('appendOnlyTypes')]
    public function there_is_no_api_to_edit_or_delete_history(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/^(set|remove|delete|update|edit|unset|clear|reset|rollback|truncate|replace)/i',
                $method->getName(),
                "$class::{$method->getName()}() looks like a mutator",
            );
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function appendOnlyTypes(): iterable
    {
        yield 'aggregate' => [EarningLine::class];
        yield 'adjustment' => [Adjustment::class];
        yield 'repository contract' => [EarningLineRepository::class];
        yield 'event store contract' => [EventStore::class];
        yield 'in-memory event store' => [InMemoryEventStore::class];
        yield 'database event store' => [DatabaseEventStore::class];
    }

    #[Test]
    public function an_adjustment_exposes_no_behaviour_that_could_change_it(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Adjustment::class))->getMethods(),
        );

        self::assertSame(['__construct'], $methods);
    }

    #[Test]
    public function coverage_is_never_excluded_and_strict_types_are_always_declared(): void
    {
        foreach (self::sourcesIn('') as $file => $source) {
            self::assertStringNotContainsString('@codeCoverageIgnore', $source, "$file hides code from coverage");
            self::assertStringContainsString('declare(strict_types=1);', $source, "$file must declare strict types");
        }
    }

    /**
     * @return iterable<string, string> path => source
     */
    private static function sourcesIn(string $directory): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::appPath().'/'.$directory));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname() => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /** @return list<class-string> */
    private static function classesIn(string $directory): array
    {
        $classes = [];

        foreach (array_keys(iterator_to_array(self::sourcesIn($directory))) as $path) {
            $relative = substr($path, strlen(self::appPath()) + 1, -4);
            $class = 'App\\'.str_replace('/', '\\', $relative);

            self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), "$path does not define $class");
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
