<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderSuccessController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('order-success', [
            'language' => $request->string('language')->toString() === 'en' ? 'en' : 'vi',
        ]);
    }
}
