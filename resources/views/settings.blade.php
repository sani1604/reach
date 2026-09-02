@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="page-heading-row">
        <div>
            <h1 class="page-title">Settings</h1>
            <p class="page-sub">Connect your OpenAI Ads account to start tracking.</p>
        </div>
        @if ($shop->pixelConfigured() && $shop->capi_token)
            <span class="connection-badge ready"><span>●</span> Connected</span>
        @else
            <span class="connection-badge pending"><span>●</span> Setup needed</span>
        @endif
    </div>

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
                <div class="secret-input">
                    <input type="password" id="capi_token" name="capi_token"
                           value="{{ old('capi_token', $shop->capi_token) }}"
                           placeholder="Paste your OpenAI Conversions API token" autocomplete="off">
                    <button type="button" class="secret-toggle" aria-label="Show or hide API key" data-target="capi_token">Show</button>
                </div>
                <div class="hint">Used for server-side event delivery. Stored encrypted, never exposed in the pixel.</div>
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
                <td>Store</td>
                <td class="mono">{{ $shop->shopify_domain }}</td>
            </tr>
        </table>
        <p class="hint mt-16">Tracker script: <span class="mono">{{ url('/pixel.js') }}</span></p>
    </div>

    <script>
        document.querySelectorAll('.secret-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.target);
                var visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.textContent = visible ? 'Show' : 'Hide';
            });
        });
    </script>
@endsection
