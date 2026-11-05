<?php

declare(strict_types=1);

namespace Crab\Actors\Tests;

use Crab\Actors\Actors;
use Crab\Actors\ActorsPlugin;
use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Actors\Actors as ActorsContract;
use Crab\Contracts\Actors\Inbox;
use Crab\Contracts\Actors\NoReply;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Engine\Async\Async;
use Crab\Engine\Crab;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActorsPluginTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private static function app(array $settings = []): App
    {
        $app = new App(new Config($settings));
        $app->register(new ActorsPlugin());
        return $app;
    }

    public function testThePluginDeclaresUsableDefaultsAndAliasesItsService(): void
    {
        $app = self::app();

        self::assertSame([], $app->config->problems());
        self::assertSame(['workers' => 2, 'idle' => 60, 'max_inbox' => 1000], $app->config->get('actors'));
        self::assertSame($app->container->get(Actors::class), $app->container->get(ActorsContract::class));
        self::assertSame($app->container->get(ConfigContract::class), $app->config);
        $app->container->get(Actors::class)->stop();
    }

    public function testTheConcreteNameFollowsAReplacementOfTheContract(): void
    {
        $app = self::app();
        $original = $app->container->get(ActorsContract::class);
        $replacement = new Actors();
        try {
            $app->container->instance(ActorsContract::class, $replacement);
            self::assertSame($replacement, $app->container->get(Actors::class));
        } finally {
            $original->stop();
            $replacement->stop();
        }
    }

    public function testAReusedPluginStopsOnlyTheActorsOfItsContainer(): void
    {
        $plugin = new ActorsPlugin();
        $first = new App(new Config(['log' => ['path' => 'php://memory']]));
        $second = new App(new Config(['log' => ['path' => 'php://memory']]));
        $plugin->register($first->container);
        $plugin->register($second->container);
        $a = $first->container->get(ActorsContract::class);
        $b = $second->container->get(ActorsContract::class);
        try {
            self::assertSame(1, $a->ask(PluginCounter::class, 'a', 'add'));
            self::assertSame(1, $b->ask(PluginCounter::class, 'b', 'add'));

            $worker = new Worker(EventLoop::current(), new Crab('test'), 0, ['tcp' => []], 1.0);
            $plugin->stop($worker, $first->container);

            self::assertSame(2, $b->ask(PluginCounter::class, 'b', 'add'));
            $this->expectException(NoReply::class);
            $a->ask(PluginCounter::class, 'a', 'add');
        } finally {
            $a->stop();
            $b->stop();
            Async::sleep(EventLoop::current(), 0.0);
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidSettings(): iterable
    {
        foreach (self::app()->config->rules()['actors'] as $key => $rule) {
            $wrong = match ($key) {
                'workers', 'max_inbox' => 'many',
                'idle' => 'soon',
                default => throw new \LogicException("Unknown actors setting {$key}."),
            };
            yield "{$key} type" => ["actors.{$key}", $wrong];
            if (isset($rule['minimum'])) {
                yield "{$key} minimum" => ["actors.{$key}", $rule['minimum'] - 1];
            }
            if (isset($rule['exclusiveMinimum'])) {
                yield "{$key} exclusive minimum" => ["actors.{$key}", $rule['exclusiveMinimum']];
            }
        }
    }

    #[DataProvider('invalidSettings')]
    public function testEverySettingRefusesAValueOutsideItsRule(string $key, mixed $value): void
    {
        [$section, $name] = explode('.', $key, 2);
        $problems = self::app([$section => [$name => $value]])->config->problems();

        self::assertCount(1, $problems, implode("\n", $problems));
        self::assertMatchesRegularExpression('/^' . preg_quote($key, '/') . ' is .+; it takes .+\.$/', $problems[0]);
    }
}

final class PluginCounter
{
    public function handle(Inbox $inbox, string $key): void
    {
        $count = 0;
        foreach ($inbox as $message) {
            $message->reply(++$count);
        }
    }
}
