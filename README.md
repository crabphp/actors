# Crab Actors

Actors for the [Crab framework](https://github.com/crabphp/framework): one for each key, taking its messages one at a time and keeping its memory while it runs. They became a separate package in framework 0.6.0 and keep their classes, plugin, command and settings. This package requires PHP 8.4 and framework 0.7.x.

- [Introduction](#introduction)
- [Installation](#installation)
- [Writing an actor](#writing-an-actor)
    - [Replying](#replying)
    - [When an actor ends](#when-an-actor-ends)
- [Sending to an actor](#sending-to-an-actor)
- [Where actors run](#where-actors-run)
    - [Across processes](#across-processes)
- [What an actor is not](#what-an-actor-is-not)
- [In AI applications](#in-ai-applications)
- [Configuration](#configuration)
- [In tests](#in-tests)

## Introduction

An actor is a piece of your application with a key, an inbox and a memory: one runs for each key, takes its messages one at a time and remembers what it was told while it runs. Use one for something that many requests talk to and that must see them one by one: a customer's conversation with an assistant, a game's table, a document several people edit, a device that must not be sent two commands at once.

```php
use App\Llm;
use Crab\Contracts\Actors\Inbox;

final readonly class Assistant
{
    public function __construct(private Llm $llm)
    {
    }

    public function handle(Inbox $inbox, string $key): void
    {
        $history = [];
        foreach ($inbox as $message) {
            $history[] = ['role' => 'user', 'content' => $message->data['text']];
            $answer = $this->llm->chat($history);
            $history[] = ['role' => 'assistant', 'content' => $answer];
            $message->reply(['answer' => $answer]);
        }
    }
}
```

```php
use Crab\Contracts\Actors\Actors;

$reply = $actors->ask(Assistant::class, "user:{$user->id}", ['text' => 'and in blue?']);
```

Two requests from one customer reach the same actor, which answers the second knowing the first. Two customers have an actor each, and neither waits for the other.

`Llm` is your application's service for calling a model. Messages sent by one process to the same actor keep their order. Messages from different processes are handled in the order they reach the actor; there is no shared sending order between processes.

## Installation

Require the package beside the framework, and list broadcast's plugin before actors' in `app.php`:

```sh
composer require crabphp/actors
```

```php
// app.php
use App\Setup;
use Crab\Actors\ActorsPlugin;
use Crab\App;
use Crab\Broadcast\BroadcastPlugin;

return App::configure(__DIR__)
    ->withPlugins([
        BroadcastPlugin::class,
        ActorsPlugin::class,
    ])
    ->withSetup(Setup::class)
    ->create();
```

That is all an application in one process needs: an actor then runs in the process that sends to it. For actors shared by several workers or machines, see [Across processes](#across-processes).

`Setup` is your application's class implementing `Crab\Contracts\Plugin`, where you bind services such as `Llm`; see [Application setup](https://github.com/crabphp/framework/blob/main/docs/plugins.md#your-applications-setup). Leave out `withSetup()` if you have no setup to run.

## Writing an actor

An actor is a class with `handle(Inbox $inbox, string $key)`, services in its constructor as a job's are. `handle()` runs once for each key, from the first message until it returns, and keeps its memory in its variables. The object itself is made by the container and may be shared between keys, so keep nothing about one key in its properties.

Iterating the inbox gives each `Crab\Contracts\Actors\Message` as it comes, and waits while there is none. `$message->data` is what was sent, as JSON reads it. `$inbox->receive()` gives one message, or null once the inbox has ended.

While an actor handles a message, the next ones wait in its inbox. It may wait on anything in between, a query, a model, another actor, and the worker serves other requests and other actors meanwhile.

### Replying

`$message->reply($value)` answers an `ask()`; `$message->asked()` says whether anyone waits for an answer. Replying to a message sent with `tell()` does nothing. A message is replied to once, and the reply travels as JSON too.

An actor may keep a message and reply to it later, after other messages, while it runs.

### When an actor ends

- **When it waits on an empty inbox for `actors.idle` seconds,** 60 by default, iterating it ends, and so does the actor when `handle()` returns. Time spent handling a message does not count as idle. Its memory goes with it: the next message starts it again from the beginning, once the previous `handle()` has returned. Messages arriving during cleanup wait for that next run. Keep what must last longer in the database.
- **When `handle()` returns or throws,** the asks it took and did not reply to are refused, and a failure is logged as an error. The messages it had not taken yet are given to its next run, which starts at once. If it took no messages, those waiting are dropped and their asks refused, so a handler that never reads its inbox cannot restart forever.
- **When its process stops,** its inbox ends and `$inbox->cancellation()` is cancelled. Pass that cancellation to what the actor waits on, so that a stop does not wait for it. Messages not yet taken are dropped.

## Sending to an actor

Ask for `Crab\Contracts\Actors\Actors` in a constructor. Services do not go in an HTTP action's `handle()` parameters:

```php
$actors->tell(Assistant::class, "user:{$id}", ['text' => 'hi']);       // does not wait for a reply

$reply = $actors->ask(Assistant::class, "user:{$id}", ['text' => 'hi'],  // waits for reply()
    timeout: 30,
    cancellation: $request->cancellation(),
);
```

- **`tell()` does not wait for the actor to handle the message.** With a remote carrier, it waits for publication, behind earlier sends, and throws if publication fails. Returning does not confirm the actor got it.
- **`ask()` waits for the actor's reply** and returns it. It throws `Crab\Contracts\Actors\NoReply` when none came within `timeout` seconds, or the actor ended or failed before it replied, and a `CancelledException` when its `cancellation` was cancelled first.
- **`timeout` must be finite and nonnegative.** Cancellation is checked before the message is serialized or sent.
- **A message travels as JSON:** numbers, text, booleans, null and arrays of them. One that JSON cannot carry is refused where it is sent.
- **A message is delivered at most once.** One that cannot be, to a process that is stopping or an inbox already holding `actors.max_inbox` messages, is dropped and logged, and an ask for it is refused.

## Where actors run

With the local broadcast carrier, the default, an actor runs in the process that sends to it. Each HTTP worker or command process has its own actors and memory, even for the same key. Use one sending process for development and tests, or a carrier between processes when requests must share an actor.

### Across processes

With several HTTP workers, or another machine, two requests from one customer may land in different processes, and each would have an actor of its own. To have one actor for each key wherever the messages come from, install [crabphp/redis](https://github.com/crabphp/redis) and run the actors in a server of their own:

```sh
composer require crabphp/redis
```

In the installation example's `withPlugins()` list, put `Crab\Redis\RedisPlugin::class` between `BroadcastPlugin::class` and `ActorsPlugin::class`. Set the carrier and a prefix unique to this application:

```php
// config/broadcast.php
return ['carrier' => 'redis', 'prefix' => 'my-app:broadcast:'];
```

With `ActorsPlugin` listed, `php cua start` then runs the `actors` server beside HTTP, in `actors.workers` processes, 2 by default; `php cua actors:work` runs it on its own. `php cua actors:work --single` serves all configured shards in one process, without requiring `pcntl` or `posix`. The keys are shared out by a hash of the actor's class and key. Messages to another process and their replies travel through Redis; a send to a key served by the sending process stays local. Give every sender and server the same `actors.workers` and `broadcast.prefix`. `redis.prefix` does not isolate broadcast channels.

Run exactly one complete set of actor workers for that carrier and prefix. Two servers subscribing to the same shard both handle its messages. On several web machines, or when using a separate `actors:work` service, replace the `actors` server registration in the setup used by the web machines:

```php
// app/Setup.php
namespace App;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Crab\Contracts\Servers;

final readonly class Setup implements Plugin
{
    public function register(Container $container): void
    {
        $container->get(Servers::class)->add('actors', static fn(): array => []);
    }
}
```

Keep `ActorsPlugin` registered there so HTTP requests can send messages. The replacement makes `start` launch no actor workers. Run `php cua actors:work` once, on the machine or service that owns them, and restart that service after deployment. Do not also run it alongside a `start` that already runs the actors server.

Without the `actors` server running, nothing takes remote messages: a `tell()` is lost and an `ask()` ends at its timeout. With the local carrier, `start` launches no actor workers and `actors:work` refuses to run, as it would have nothing to do.

The `actors` server is reloaded with the rest, and a reload ends its actors as a stop does. While one is reloading, messages to it are lost.

## What an actor is not

- **Not durable.** An actor's memory is in its process, gone when it ends, crashes or is reloaded, and its messages are delivered at most once. For work that must go on after a crash, have the actor start a [run](https://github.com/crabphp/runs), or keep its state in the database.
- **Not supervised.** After a failure, another run needs a message, already waiting or newly sent; there is no retry of a message the actor took, and no tree of actors watching each other.
- **Not moved.** A key's shard follows `actors.workers`; no memory moves when that number changes. Stop the old actor workers and change every sender and server together, so old and new shard assignments do not run the same key at once.

## In AI applications

A chat whose every request loads the conversation from the database, calls the model and saves it back works, until a customer sends two messages at once and one answer is written over the other. An actor for each customer takes their messages one at a time and keeps the conversation in its variables:

```php
use App\Llm;
use Crab\Contracts\Actors\Inbox;

final readonly class Assistant
{
    public function __construct(private Llm $llm)
    {
    }

    public function handle(Inbox $inbox, string $key): void
    {
        $history = [];
        foreach ($inbox as $message) {
            $history[] = ['role' => 'user', 'content' => $message->data['text']];
            $answer = $this->llm->chat($history, cancellation: $inbox->cancellation());
            $history[] = ['role' => 'assistant', 'content' => $answer];
            $message->reply(['answer' => $answer]);
        }
    }
}
```

The action asks the customer's actor and answers with its reply:

```php
use App\User;
use Crab\Contracts\Actors\Actors;
use Crab\Contracts\Actors\NoReply;
use Crab\Contracts\Http\Request;
use Crab\Http\HttpException;
use Crab\Validation\Data;
use Crab\Validation\Fields;

final readonly class Chat
{
    public function __construct(private Actors $actors)
    {
    }

    public function handle(User $user, #[Fields(['text' => 'string'])] Data $input, Request $request): array
    {
        try {
            return $this->actors->ask(Assistant::class, "user:{$user->id}", ['text' => $input->text],
                timeout: 60,
                cancellation: $request->cancellation(),
            );
        } catch (NoReply) {
            throw new HttpException(503, 'The assistant is not available.');
        }
    }
}
```

`User` is your application's user class, implementing `Crab\Contracts\Auth\Identity`; see [Authentication](https://github.com/crabphp/framework/blob/main/docs/authentication.md). Register `Chat` as a POST action at `/assistant/chat`.

The conversation lasts while the actor runs, and it ends after waiting on an empty inbox for `actors.idle` seconds. To keep a conversation for good, save each turn to the database as well, and have the actor load it when it starts. With several HTTP workers, carry the messages over Redis so that one customer has one actor; see [Across processes](#across-processes).

An actor and a run go together: the actor takes the conversation, and starts a run for work that must not be lost, such as an order the customer asked it to place.

## Configuration

The plugins in [Installation](#installation) declare every setting and its default. To change one, add `config/actors.php`:

```php
// config/actors.php
return [
    'workers' => 2,
    'idle' => 60,
    'max_inbox' => 1000,
];
```

| Setting | Default | |
| --- | --- | --- |
| `actors.workers` | `2` | Processes of the `actors` server; an integer of at least 1 |
| `actors.idle` | `60` | Seconds waiting on an empty inbox before it ends; a finite number greater than 0 |
| `actors.max_inbox` | `1000` | The most messages waiting in one actor's inbox; an integer of at least 1; more are dropped |

Unknown keys and values outside these rules are refused before the server starts or the actors service is made. The framework's `config:publish` does not publish package settings; create this file yourself.

## In tests

With the local carrier, as in tests, an actor runs in the test's process, started by the first message. `ask()` waits for it as it would in a request:

```php
use Crab\Contracts\Actors\Actors;

$actors = $app->container->get(Actors::class);

self::assertSame(['answer' => 'Hi'], $actors->ask(Assistant::class, 'user:1', ['text' => 'hello']));
```

Through the API, as a request would, with the services the actor calls bound as fakes:

```php
$response = $api->actingAs($user)->post('/assistant/chat', ['text' => 'a mug'])->assertStatus(200);
```

An actor keeps running after its test, waiting for its idle time; end them all in `tearDown()` with `$app->container->get(\Crab\Actors\Actors::class)->stop()`. Stopping ends the inboxes and cancels their waits; handlers finish when they next run. A stopped actors service cannot be used again, so make a fresh application for each test. See the framework's [testing guide](https://github.com/crabphp/framework/blob/main/docs/testing.md).
