<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PixelController extends Controller
{
    /**
     * Serve the storefront tracker (pixel.js). Injected by the web pixel
     * extension; loads merchant config and POSTs events to /api/track.
     */
    public function script(Request $request)
    {
        $content = view('pixel.script')->render();

        return response($content, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
