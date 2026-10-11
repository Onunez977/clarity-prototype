<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\DropInvalidBroadcastSocketId;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DropInvalidBroadcastSocketIdTest extends TestCase
{
    #[Group('middleware')]
    public function test_drops_undefined_socket_id_header(): void
    {
        $request = Request::create('/socket-id-probe', 'POST');
        $request->headers->set('X-Socket-ID', 'undefined');

        $response = $this->runMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'header' => null,
            'input' => null,
        ], json_decode($response->getContent(), true));
    }

    #[Group('middleware')]
    public function test_drops_undefined_socket_id_input(): void
    {
        $request = Request::create('/socket-id-probe', 'POST', ['socket_id' => 'undefined']);

        $response = $this->runMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'header' => null,
            'input' => null,
        ], json_decode($response->getContent(), true));
    }

    #[Group('middleware')]
    public function test_keeps_valid_pusher_socket_id(): void
    {
        $request = Request::create('/socket-id-probe', 'POST', ['socket_id' => '1234.5678']);
        $request->headers->set('X-Socket-ID', '1234.5678');

        $response = $this->runMiddleware($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'header' => '1234.5678',
            'input' => '1234.5678',
        ], json_decode($response->getContent(), true));
    }

    private function runMiddleware(Request $request): Response
    {
        return (new DropInvalidBroadcastSocketId)->handle(
            $request,
            fn (Request $request): Response => response()->json([
                'header' => $request->header('X-Socket-ID'),
                'input' => $request->input('socket_id'),
            ]),
        );
    }
}
