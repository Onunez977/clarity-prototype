<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire sends Echo.socketId() as X-Socket-ID. When Echo is not connected
 * that value is the string "undefined", and Pusher\\Pusher::trigger() throws
 * "Invalid socket ID undefined" on broadcast()->toOthers() (Otto send, etc.).
 */
class DropInvalidBroadcastSocketId
{
    /**
     * Pusher socket IDs are `{number}.{number}` (e.g. 1234.5678).
     */
    private const SOCKET_ID_PATTERN = '/^\d+\.\d+$/';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (['X-Socket-ID', 'X-Socket-Id'] as $header) {
            $socketId = $request->headers->get($header);
            if (is_string($socketId) && $socketId !== '' && ! $this->isValidPusherSocketId($socketId)) {
                $request->headers->remove($header);
            }
        }

        $inputSocketId = $request->input('socket_id');
        if (is_string($inputSocketId) && $inputSocketId !== '' && ! $this->isValidPusherSocketId($inputSocketId)) {
            $request->request->remove('socket_id');
        }

        return $next($request);
    }

    private function isValidPusherSocketId(string $socketId): bool
    {
        return preg_match(self::SOCKET_ID_PATTERN, $socketId) === 1;
    }
}
