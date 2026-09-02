<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SetupGuideController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return view('setup-guide', compact('shop'));
    }
}
