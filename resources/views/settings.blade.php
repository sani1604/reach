@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Connect your OpenAI Ads account to start tracking.</p>

    @if (session('test_ok'))
        <div class="alert success">✓ {{ session('test_ok') }}</div>
    @endif
    @if (session('test_error'))
        <div class="alert error">{{ session('test_error') }}</div>
    @endif

    <div class="card mb-16">
        <h3>OpenAI Ads credentials</h3>
        <p class="sub">Find these in your OpenAI Ads dashboard (Pixel ID) and Conversions API settings (CAPI key).</p>

        <form method="POST" action="{{ route('settings.save') }}">
            @csrf
            <div class="field">
                <label for="pixel_id">Pixel ID</label>
                <input type="text" id="pixel_id" name="pixel_id" value="{{ old('pixel_id', $shop->pixel_id) }}"
                       placeholder="e.g. OA-1234567890" class="input">
                <div class="hint">Loaded on your storefront by the web pixel extension.</div>
            </div>

            <div class="field">
                <label for="capi_token">Conversions API key</label>
                <textarea id="capi_token" name="capi_token" rows="3"
                          placeholder="Paste your OpenAI Conversions API token">{{ old('capi_token', $shop->capi_token) }}</textarea>
                <div class="hint">Used for server-side event delivery. Stored encrypted, never exposed in the pixel.</div>
            </div>

            <div class="field">
                <label for="capi_url">Conversions API endpoint (optional)</label>
                <input type="url" id="capi_url" name="capi_url" value="{{ old('capi_url', $shop->capi_url) }}"
                       placeholder="{{ config('ads.capi_url') }}" class="input">
                <div class="hint">Override the default endpoint. Leave blank to use the standard OpenAI Ads URL.</div>
            </div>

            <button class="btn btn-primary" type="submit">Save</button>
            <button class="btn btn-ghost" type="submit" formaction="{{ route('settings.test') }}">Test connection</button>
        </form>
    </div>

    <div class="card">
        <h3>Pixel status</h3>
        <p class="sub">Current state of your storefront integration.</p>
        <table class="list">
            <tr>
                <td>Web pixel (Customer Events)</td>
                <td>
                    @if ($shop->pixelConfigured())
                        <span class="tag green">Active</span>
                    @else
                        <span class="tag amber">Waiting for Pixel ID</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Server-side CAPI</td>
                <td>
                    @if ($shop->capi_token)
                        <span class="tag green">Configured</span>
                    @else
                        <span class="tag amber">Waiting for CAPI key</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>CAPI endpoint</td>
                <td class="mono">{{ $shop->capiEndpoint() }}</td>
            </tr>
            <tr>
                <td>Store</td>
                <td class="mono">{{ $shop->shopify_domain }}</td>
            </tr>
        </table>
        <p class="hint mt-16">Tracker script: <span class="mono">{{ url('/pixel.js') }}</span></p>
    </div>
@endsection
