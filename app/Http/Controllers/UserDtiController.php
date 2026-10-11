<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserDtiController extends Controller
{
    public function __invoke(Request $request)
    {
        if (auth()->check() && $request->has('dti')) {
            if (auth()->user()->dti !== $request->get('dti')) {
                auth()->user()->update([
                    'dti' => $request->get('dti'),
                ]);
            }
        }

        return response()->noContent();
    }
}
