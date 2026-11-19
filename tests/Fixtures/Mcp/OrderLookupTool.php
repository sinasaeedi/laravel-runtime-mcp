<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;

/**
 * 01-38: a tool written the official laravel/mcp way, as `php artisan make:mcp-tool` makes it: it validates its own
 * input in handle(), and `mode` picks what it returns. Its own #[IsDestructive] must never reach tools/list (spec 4.3).
 * Its shouldRegister() reads two container flags, so a test can hide it or make it throw.
 */
#[Description('Shows one order the way support sees it. order: the order id. mode: the shape of the answer.')]
#[IsDestructive]
final class OrderLookupTool extends Tool
{
    public const NAME = 'app_order_lookup';

    public const MODES = ['json', 'structured', 'text', 'image', 'error', 'insert', 'jwt', 'generator', 'nested', 'user'];

    /** Bound: shouldRegister() says false. */
    public const HIDE = 'rmcp.fixture.order_lookup.hide';

    /** Bound: shouldRegister() throws. */
    public const THROW = 'rmcp.fixture.order_lookup.throw';

    /** Bound by handle(): the tool's own code ran. */
    public const RAN = 'rmcp.fixture.order_lookup.ran';

    public function shouldRegister(): bool
    {
        if (app()->bound(self::THROW)) {
            throw new RuntimeException('The feature flag store is down.');
        }

        return ! app()->bound(self::HIDE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->integer()->min(1)->required(),
            'mode' => $schema->string()->enum(self::MODES),
        ];
    }

    /** @return Response|ResponseFactory|Generator<int, Response>|list<Response> */
    public function handle(Request $request): Response|ResponseFactory|Generator|array
    {
        app()->instance(self::RAN, true);

        $request->validate([
            'order' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:'.implode(',', self::MODES)],
        ]);

        $order = $request->integer('order');

        return match ($request->string('mode', 'json')->value()) {
            'structured' => Response::structured(['id' => $order, 'state' => 'shipped']),
            'text' => Response::text("Order {$order} shipped"),
            'image' => [Response::text("Order {$order}"), Response::image('iVBORw0KGgo=')],
            'error' => Response::error('Not found'),
            'insert' => Response::json(['inserted' => DB::table('ro_orders')->insert(['n' => 'mcp'])]),
            'jwt' => Response::json(['id' => $order, 'token' => SecretTool::JWT]),
            'generator' => $this->stream($order),
            // A service that resolves the Request again, as app code may do.
            'nested' => Response::json(['nested' => Container::getInstance()->make(Request::class)->get('order')]),
            'user' => Response::json(['user' => $request->user()?->getAuthIdentifier()]),
            default => Response::json(['id' => $order]),
        };
    }

    /** @return Generator<int, Response> */
    private function stream(int $order): Generator
    {
        yield Response::notification('notifications/progress', ['progress' => 1, 'total' => 2]);
        yield Response::text("Order {$order} shipped");
    }
}
