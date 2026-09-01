@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="status-banner">
        <div class="left">
            <div class="icon-box">✓</div>
            <div>
                <h4>OpenAI Ads connection verified</h4>
                <p>Your pixel and server-side events are ready</p>
            </div>
        </div>
        <div class="right">
            <span class="live-dot"></span> Active &nbsp;·&nbsp; Verified 2 min ago
        </div>
    </div>

    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Connect and verify your OpenAI Ads tracking.</p>

    @if (session('test_ok'))
        <div class="alert success">✓ {{ session('test_ok') }}</div>
    @endif
    @if (session('test_error'))
        <div class="alert error">{{ session('test_error') }}</div>
    @endif
    @if (session('saved'))
        <div class="alert success">✓ Credentials updated successfully.</div>
    @endif

    <div class="grid settings-grid">
        <!-- Left: Credentials Form -->
        <div class="card">
            <h3>OpenAI Ads credentials</h3>
            <p class="sub">Add the two values from OpenAI Ads Manager.</p>

            <form method="POST" action="{{ route('settings.save') }}">
                @csrf
                <div class="field">
                    <label for="pixel_id">OpenAI Pixel ID</label>
                    <div class="input-wrap">
                        <input type="text" id="pixel_id" name="pixel_id" value="{{ old('pixel_id', $shop->pixel_id) }}"
                               placeholder="px_7f92b6c48a3e41" class="input">
                        @if ($shop->pixelConfigured())
                            <span class="check-icon">✓</span>
                        @endif
                    </div>
                    <div class="hint">Loaded on your storefront by the web pixel extension.</div>
                </div>

                <div class="field">
                    <label for="capi_token">Conversions API key</label>
                    <div class="input-wrap">
                        <textarea id="capi_token" name="capi_token" rows="3"
                                  placeholder="Paste your OpenAI Conversions API token">{{ old('capi_token', $shop->capi_token) }}</textarea>
                        @if ($shop->capi_token)
                            <span class="check-icon" style="top: 28px;">✓</span>
                        @endif
                    </div>
                    <div class="hint">Used for server-side event delivery. Stored encrypted, never exposed in the pixel.</div>
                </div>

                <div class="field">
                    <label for="capi_url">Conversions API endpoint (optional)</label>
                    <input type="url" id="capi_url" name="capi_url" value="{{ old('capi_url', $shop->capi_url) }}"
                           placeholder="https://reach.whatify.in/api/capi (or default)" class="input">
                    <div class="hint">Your project endpoint or default OpenAI CAPI URL.</div>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px;">
                    <button class="btn btn-primary" type="submit" formaction="{{ route('settings.test') }}">Verify connection</button>
                    <button class="btn btn-ghost" type="submit">Update credentials</button>
                </div>
            </form>
        </div>

        <!-- Right: Configured automatically sidebar -->
        <div class="card">
            <h3>Configured automatically</h3>
            <p class="sub">Reach handles the technical setup.</p>

            <div class="check-list mt-16">
                <div class="check-item">
                    <div class="cbox">✓</div>
                    <div class="ctext">
                        <strong>Browser pixel installed</strong>
                        <span>No theme code required</span>
                    </div>
                </div>
                <div class="check-item">
                    <div class="cbox">✓</div>
                    <div class="ctext">
                        <strong>Server-side events active</strong>
                        <span>Conversions API connected</span>
                    </div>
                </div>
                <div class="check-item">
                    <div class="cbox">✓</div>
                    <div class="ctext">
                        <strong>Event deduplication enabled</strong>
                        <span>Shared event IDs prevent duplicates</span>
                    </div>
                </div>
                <div class="check-item">
                    <div class="cbox">✓</div>
                    <div class="ctext">
                        <strong>Five events mapped</strong>
                        <span>OpenAI taxonomy verified</span>
                    </div>
                </div>
            </div>

            <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--line);">
                <p class="small muted mb-0">Connected store: <span class="mono">{{ $shop->shopify_domain }}</span></p>
                <p class="small muted mt-8 mb-0">Tracker script: <span class="mono">{{ url('/pixel.js') }}</span></p>
            </div>
        </div>
    </div>
@endsection
