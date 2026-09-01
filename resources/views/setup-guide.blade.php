@extends('layouts.app')

@section('title', 'Setup guide')

@section('content')
    <h1 class="page-title">Setup guide</h1>
    <p class="page-sub">How to set up OpenAI Ads tracking and Conversions API on your Shopify store.</p>

    <div class="card mb-16">
        <h3>Step 1: Get your OpenAI Ads credentials</h3>
        <p class="sub">Log into your OpenAI Ads Manager to retrieve your Pixel ID and Conversions API key.</p>
        <ol style="margin: 0 0 16px; padding-left: 20px; color: var(--muted); font-size: 14px; line-height: 1.6;">
            <li>Navigate to your OpenAI Ads Manager dashboard.</li>
            <li>Go to <strong>Pixels &amp; Tracking</strong> to copy your <strong>Pixel ID</strong> (starts with <span class="mono">px_</span>).</li>
            <li>Go to <strong>Conversions API</strong> to generate and copy your secret <strong>CAPI access token</strong>.</li>
        </ol>
    </div>

    <div class="card mb-16">
        <h3>Step 2: Connect your store</h3>
        <p class="sub">Paste your credentials into the app settings so Reach can start sending events.</p>
        <p class="muted small mb-16">Go to the <a href="{{ route('settings') }}">Settings</a> tab, paste your <strong>Pixel ID</strong> and <strong>CAPI key</strong>, and click <strong>Verify connection</strong>.</p>
        <a href="{{ route('settings') }}" class="btn btn-primary btn-sm">Go to Settings →</a>
    </div>

    <div class="card mb-16">
        <h3>Step 3: Automatic web pixel installation</h3>
        <p class="sub">No theme code edits required.</p>
        <p class="muted small mb-0">Reach automatically installs its storefront web pixel through Shopify's Customer Events system. As soon as your Pixel ID is saved, page views, product views, add-to-carts, and checkout starts begin streaming automatically to OpenAI Ads.</p>
    </div>

    <div class="card">
        <h3>Step 4: Verify server-side events &amp; CAPI</h3>
        <p class="sub">Double-fire attribution with deduplication.</p>
        <p class="muted small mb-0">Revenue-critical events (Purchases and Refunds) are sent securely from Shopify's servers via webhooks and the Conversions API. Shared event IDs ensure browser and server events are automatically deduplicated by OpenAI Ads.</p>
    </div>
@endsection
