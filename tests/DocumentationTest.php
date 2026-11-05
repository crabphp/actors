<?php

declare(strict_types=1);

namespace Crab\Actors\Tests;

use Crab\Actors\Actors;
use Crab\Actors\ActorsPlugin;
use Crab\App;
use Crab\Broadcast\BroadcastPlugin;
use Crab\Contracts\Actors\Actors as ActorsContract;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Testing\Api;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__) . '/.phpunit.cache/docs-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testTheReadmeApplicationAndChatKeepEachUsersConversation(): void
    {
        // Run the examples themselves, with only the application's services replaced by fixtures.
        require $this->example('In AI applications', 0);
        require $this->example('In AI applications', 1);
        $app = require $this->example('Installation');
        $app->config->check();
        $app->router->post('/assistant/chat', Readme\Chat::class);
        $api = new Api($app);
        $user = new ReadmeUser(2);

        try {
            $settings = require $this->example('Configuration');
            self::assertSame($settings, $app->config->get('actors'));
            self::assertSame($app->container->get(ActorsContract::class), $app->container->get(Actors::class));
            require $this->example('In tests', 0);
            require $this->example('In tests', 1);
            self::assertSame(['answer' => 'Hi'], $response->json());
            $api->actingAs($user)->post('/assistant/chat', ['text' => 'and in blue?'])->assertStatus(200);
            $api->actingAs(new ReadmeUser(3))->post('/assistant/chat', ['text' => 'a plate'])->assertStatus(200);

            self::assertSame(
                [
                    [['role' => 'user', 'content' => 'hello']],
                    [['role' => 'user', 'content' => 'a mug']],
                    [
                        ['role' => 'user', 'content' => 'a mug'],
                        ['role' => 'assistant', 'content' => 'Hi'],
                        ['role' => 'user', 'content' => 'and in blue?'],
                    ],
                    [['role' => 'user', 'content' => 'a plate']],
                ],
                $app->container->get(ReadmeLlm::class)->histories,
            );
        } finally {
            $app->container->get(Actors::class)->stop();
            Async::sleep(EventLoop::current(), 0.0);
        }
    }

    public function testTheWebSetupLeavesActorsToTheSeparateServer(): void
    {
        require $this->example('Across processes', 1);
        $app = App::configure()
            ->withPlugins([BroadcastPlugin::class, ActorsPlugin::class])
            ->withSetup(Readme\Setup::class)
            ->create();
        $app->container->instance(Carrier::class, new ReadmeCarrier());

        self::assertSame([], $app->alongside(['actors']));
        self::assertTrue($app->console->has('actors:work'));
        self::assertInstanceOf(ActorsContract::class, $app->container->get(ActorsContract::class));
        $app->container->get(Actors::class)->stop();
    }

    private function example(string $section, int $index = 0): string
    {
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertSame(1, preg_match(
            '/^#{2,3} ' . preg_quote($section, '/') . '\n(.*?)(?=^#{2,3} |\z)/ms',
            $readme,
            $body,
        ));
        preg_match_all('/```php\n(.*?)\n```/s', $body[1], $examples);
        self::assertArrayHasKey($index, $examples[1], "PHP example {$index} in {$section}.");
        $code = str_replace(
            ['use App\\Llm;', 'use App\\User;', 'use App\\Setup;'],
            [
                'use ' . ReadmeLlm::class . ' as Llm;',
                'use ' . ReadmeUser::class . ' as User;',
                'use ' . ReadmeSetup::class . ' as Setup;',
            ],
            $examples[1][$index],
        );
        $namespace = 'namespace ' . __NAMESPACE__ . '\\Readme;';
        $code = str_contains($code, 'namespace App;')
            ? str_replace('namespace App;', $namespace, $code)
            : $namespace . "\n" . $code;
        $path = $this->directory . '/' . str_replace(' ', '-', $section) . "-{$index}.php";
        file_put_contents($path, "<?php\n" . $code);
        return $path;
    }
}

final readonly class ReadmeSetup implements Plugin
{
    public function register(Container $container): void
    {
        $container->instance(ReadmeLlm::class, new ReadmeLlm());
    }
}

final class ReadmeLlm
{
    /**
     * @var list<list<array{role: string, content: string}>>
     */
    public array $histories = [];

    /**
     * @param list<array{role: string, content: string}> $history
     */
    public function chat(array $history, ?Cancellation $cancellation = null): string
    {
        $cancellation?->throwIfCancelled();
        $this->histories[] = $history;
        return 'Hi';
    }
}

final readonly class ReadmeUser implements Identity
{
    public function __construct(
        public int $id,
    ) {}
}

final class ReadmeCarrier implements Carrier
{
    public function publish(array $channels, string $message): void {}

    public function subscribe(string $channel, \Closure $receive, \Closure $gap): void {}

    public function unsubscribe(string $channel): void {}

    public function close(): void {}
}
