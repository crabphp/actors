<?php

declare(strict_types=1);

namespace Crab\Actors\Tests;

use Crab\Actors\Actors;
use Crab\Container\Container;
use Crab\Contracts\Actors\Inbox;
use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class ActorLifetimeTest extends TestCase
{
    public function testAnIdleActorKeepsItsKeyUntilHandleHasReturned(): void
    {
        $instance = new FinishingActor();
        $container = new Container();
        $container->instance(FinishingActor::class, $instance);
        $actors = new Actors($container, idle: 0.01);
        try {
            self::assertSame(1, $actors->ask(FinishingActor::class, 'key', 'first'));
            $deadline = (hrtime(true) / 1e9) + 2.0;
            while ($instance->finish === null) {
                self::assertLessThan($deadline, hrtime(true) / 1e9, 'Waiting for handle() to finish its inbox.');
                Async::sleep(EventLoop::current(), 0.001);
            }

            // The old handle() is suspended after its inbox ended. Its successor must wait for it.
            $actors->tell(FinishingActor::class, 'key', 'second');
            Async::sleep(EventLoop::current(), 0.0);
            self::assertSame(1, $instance->runs, 'Two handle() calls overlapped for one key.');

            ($instance->finish)();
            self::assertSame(2, $actors->ask(FinishingActor::class, 'key', 'third'));
            self::assertSame(['1:first', 'end:1', '2:second', '2:third'], $instance->seen);
        } finally {
            $instance->finish?->__invoke();
            $actors->stop();
            Async::sleep(EventLoop::current(), 0.0);
        }
    }
}

final class FinishingActor
{
    public int $runs = 0;
    public ?\Closure $finish = null;
    /**
     * @var list<string>
     */
    public array $seen = [];

    public function handle(Inbox $inbox, string $key): void
    {
        $run = ++$this->runs;
        foreach ($inbox as $message) {
            $this->seen[] = "{$run}:{$message->data}";
            $message->reply($run);
        }
        if ($run === 1) {
            Async::await(
                EventLoop::current(),
                function (\Closure $resume): null {
                    $this->finish = $resume;
                    return null;
                },
                $inbox->cancellation(),
            );
        }
        $this->seen[] = "end:{$run}";
    }
}
