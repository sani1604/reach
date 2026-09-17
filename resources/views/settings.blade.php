@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Connect your OpenAI Ads account to start tracking. Paste the values from Ads Manager → Conversions.</p>

    @if (session('test_ok'))
        <div class="alert success">✓ {{ session('test_ok') }}</div>
    @endif
    @if (session('test_error'))
        <div class="alert error">{{ session('test_error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="card mb-16">
        <h3>OpenAI Ads credentials</h3>
        <p class="sub">
            Find these in <span class="mono">ads.openai.com</span> → Tools → Conversions.
            Setup takes about 30 seconds. Events start flowing on the next storefront visit.
        </p>

        <form method="POST" action="{{ route('settings.save') }}">
            @csrf
            <div class="field">
                <label for="pixel_id">Pixel ID <span class="req">*</span></label>
                <input type="text" id="pixel_id" name="pixel_id" value="{{ old('pixel_id', $shop->pixel_id) }}"
                       placeholder="e.g. 134534…" class="input" autocomplete="off">
                <div class="hint">
                    Create a <strong>Web</strong> data source under Conversions. Copy the Pixel ID shown on the data source.
                    Used by the browser Measurement Pixel (<span class="mono">oaiq</span>) and as the <span class="mono">pid</span> on every Conversions API call.
                </div>
            </div>

            <div class="field">
                <label for="capi_token">Conversions API key <span class="req">*</span></label>
                <textarea id="capi_token" name="capi_token" rows="3"
                          placeholder="sk-svc-… (shown once when you create it)">{{ old('capi_token', $shop->capi_token) }}</textarea>
                <div class="hint">
                    Create under Conversions → Conversion keys. Starts with <span class="mono">sk-svc-</span>.
                    Shown only once — store it safely. Used server-side only; never exposed in the pixel.
                </div>
            </div>

            <div class="field">
                <label for="advertiser_api_key">Advertiser API key <span class="tag amber" style="font-size:11px;vertical-align:middle;">optional</span></label>
                <textarea id="advertiser_api_key" name="advertiser_api_key" rows="2"
                          placeholder="Ads Manager / api.ads.openai.com key">{{ old('advertiser_api_key', $shop->advertiser_api_key) }}</textarea>
                <div class="hint">
                    From Ads Manager → Settings. Powers account-level tooling (ad account probe, future campaign insights).
                    Not required for event delivery — Pixel ID + Conversions API key are enough to track.
                </div>
            </div>

            <div class="field">
                <label for="capi_url">Conversions API endpoint <span class="tag amber" style="font-size:11px;vertical-align:middle;">optional</span></label>
                <input type="url" id="capi_url" name="capi_url" value="{{ old('capi_url', $shop->capi_url) }}"
                       placeholder="{{ config('ads.capi_url') }}" class="input">
                <div class="hint">Leave blank to use the standard OpenAI endpoint (<span class="mono">https://bzr.openai.com/v1/events</span>). The Pixel ID is appended as <span class="mono">?pid=</span> automatically.</div>
            </div>

            <button class="btn btn-primary" type="submit">Save</button>
            <button class="btn btn-ghost" type="submit" formaction="{{ route('settings.test') }}">Test connection</button>
        </form>
    </div>

    <div class="card">
        <h3>Pixel status</h3>
        <p class="sub">Current state of your storefront + server-side integration.</p>
        <table class="list">
            <tr>
                <td>OpenAI Pixel ID</td>
                <td>
                    @if ($shop->pixelConfigured())
                        <span class="tag green">Configured</span>
                        <span class="mono" style="margin-left:8px;">{{ \Illuminate\Support\Str::limit($shop->pixel_id, 24) }}</span>
                    @else
                        <span class="tag amber">Waiting for Pixel ID</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Conversions API key</td>
                <td>
                    @if ($shop->capi_token)
                        <span class="tag green">Configured</span>
                    @else
                        <span class="tag amber">Waiting for CAPI key</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Advertiser API key</td>
                <td>
                    @if ($shop->advertiserKeyConfigured())
                        <span class="tag green">Configured</span>
                    @else
                        <span class="tag amber">Optional — not set</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Server-side delivery ready</td>
                <td>
                    @if ($shop->capiReady())
                        <span class="tag green">Live</span>
                    @else
                        <span class="tag amber">Needs Pixel ID + CAPI key</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Shopify web pixel (Customer Events)</td>
                <td>
                    @if ($shop->webPixelActive())
                        <span class="tag green">Active</span>
                        <span class="mono" style="margin-left:8px;font-size:11px;">{{ \Illuminate\Support\Str::limit($shop->web_pixel_id, 36) }}</span>
                    @else
                        <span class="tag amber">Activating…</span>
                        <div class="hint" style="margin-top:4px;">Installed automatically after app install. Re-save settings to retry.</div>
                    @endif
                </td>
            </tr>
            <tr>
                <td>CAPI endpoint</td>
                <td class="mono">{{ $shop->capiEndpoint() }}?pid=…</td>
            </tr>
            <tr>
                <td>Store</td>
                <td class="mono">{{ $shop->shopify_domain }}</td>
            </tr>
        </table>
        <p class="hint mt-16">
            Events tracked: <span class="mono">page_viewed</span>, <span class="mono">contents_viewed</span>,
            <span class="mono">items_added</span>, <span class="mono">checkout_started</span>, <span class="mono">order_created</span>.
            Browser + server dual-fire with a shared <span class="mono">event_id</span> for OpenAI-side deduplication.
        </p>
    </div>
@endsection
