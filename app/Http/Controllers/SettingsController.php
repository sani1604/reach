<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\EventMapper;
use App\Services\OpenAiCapiClient;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return view('settings', compact('shop'));
    }

    public function save(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $data = $this->validated($request);

        $shop->update([
            'pixel_id'   => $data['pixel_id'] ?: null,
            'capi_token' => $data['capi_token'] ?: null,
        ]);

        return back()->with('saved', true);
    }

    /**
     * Send a TestEvent to the configured Conversions API endpoint using the
     * submitted values (not yet saved), and report the result.
     */
    public function testCapi(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $data = $this->validated($request);

        $probe = new Shop([
            'pixel_id'   => $data['pixel_id'] ?: $shop->pixel_id,
            'capi_url'   => $shop->capi_url,
            'capi_token' => $data['capi_token'] ?: $shop->capi_token,
        ]);

        if (! $probe->capi_token) {
            return back()->withInput()->with(
                'test_error',
                'Add a Conversions API key first, then test.'
            );
        }

        $event = app(EventMapper::class)->build('TestEvent', ['event_time' => time()]);
        $result = app(OpenAiCapiClient::class)->send($probe, $event);

        if ($result['ok']) {
            return back()->withInput()->with(
                'test_ok',
                'Connection OK — the Conversions API accepted a test event.'
            );
        }

        $detail = is_array($result['body'] ?? null)
            ? json_encode(array_slice($result['body'], 0, 3))
            : ($result['error'] ?? 'unknown error');

        return back()->withInput()->with(
            'test_error',
            'Connection failed (HTTP '.($result['status'] ?? 'n/a').'): '.$detail
        );
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'pixel_id'   => ['nullable', 'string', 'max:255'],
            'capi_token' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
