@extends('layouts.app')

@section('title', 'Setup guide')

@section('content')
    <h1 class="page-title">Setup guide</h1>
    <p class="page-sub">Get Reach tracking your Shopify store in a few minutes.</p>

    <div class="card mb-16">
        <h3>1. Connect your OpenAI Ads Pixel</h3>
        <p class="sub">Copy the Pixel ID from your OpenAI Ads account and save it in Reach Settings.</p>
        <p class="small">Reach activates the Shopify Customer Events pixel automatically. No theme editing or Liquid code is required.</p>
        <a class="btn btn-primary mt-16" href="{{ route('settings') }}">Open Settings</a>
    </div>

    <div class="card mb-16">
        <h3>2. Add the Conversions API key</h3>
        <p class="sub">Create or copy the Conversions API key from OpenAI Ads, then paste it into Settings.</p>
        <p class="small">The key is stored per store and is used only for server-side event delivery. The API endpoint is managed by Reach and is not entered by clients.</p>
    </div>

    <div class="card">
        <h3>3. Verify events</h3>
        <p class="sub">Visit your storefront in a new tab and perform a few actions.</p>
        <ul class="small">
            <li>Open a product page — View content</li>
            <li>Add a product to cart — Add to cart</li>
            <li>Start checkout — Checkout started</li>
            <li>Complete an order — Purchase</li>
        </ul>
        <p class="hint mt-16">Events can take a short time to appear while the queue worker processes them.</p>
    </div>
@endsection
