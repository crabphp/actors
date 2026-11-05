<?php

declare(strict_types=1);

namespace Crab\Actors\Tests;

use Crab\Contracts\Actors\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testReplyingToATellDoesNothingEvenWhenTheValueCannotBeJson(): void
    {
        $message = new Message('told');

        $message->reply("\xff");
        $message->reply(INF);

        self::assertFalse($message->asked());
    }

    public function testAnAskReplyStillTravelsAsJsonAndCanBeSentOnlyOnce(): void
    {
        $received = [];
        $message = new Message('asked', static function (bool $ok, mixed $value) use (&$received): void {
            $received[] = [$ok, $value];
        });

        $message->reply((object) ['number' => 1.0]);

        self::assertSame([[true, ['number' => 1.0]]], $received);
        $this->expectException(\LogicException::class);
        $message->reply('again');
    }
}
