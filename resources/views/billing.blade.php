@extends('layouts.app')

@section('title', 'Billing')

@section('content')
    <h1 class="page-title">Billing</h1>
    <p class="page-sub">Simple, affordable pricing — built for Indian Shopify brands.</p>

    @if (! $shop->pixelConfigured())
        <div class="alert info">💡 Add your Pixel ID in <a href="{{ route('settings') }}">Settings</a> first, then come back to pick a plan.</div>
    @endif

    <div class="pricing" style="max-width: none; margin: 0; grid-template-columns: repeat(3, 1fr);">
        <div class="price-card">
            <div class="name">Free</div>
            <div class="price">₹0</div>
            <div class="per">up to 50,000 events / month</div>
            <ul>
                <li>OpenAI Ads pixel (1-click install)</li>
                <li>Product feed for OpenAI Ads</li>
                <li>Phone-first EMQ (+91 hashing)</li>
                <li>COD &amp; prepaid order tracking</li>
                <li>Live events dashboard &amp; funnel</li>
            </ul>
            @if (($shop->plan ?? 'free') === 'free')
                <span class="btn btn-ghost" style="cursor: default;">Your current plan</span>
            @endif
        </div>

        <div class="price-card featured">
            <span class="ribbon">Most popular</span>
            <div class="name">Basic</div>
            <div class="price">₹1,499 <small>/ month</small></div>
            <div class="per">7-day free trial · up to 1,000,000 events / month</div>
            <ul>
                <li>Everything in Free</li>
                <li>Performance &amp; EMQ dashboard</li>
                <li>COD / RTO conversion adjustments</li>
                <li>GoKwik · Shopflo · Fastrr · Magic</li>
                <li>Top products via ChatGPT Ads</li>
            </ul>
            @if ($shop->plan === 'basic' && ($shop->isOnPaidPlan() || $shop->onTrial()))
                <span class="btn btn-ghost" style="cursor: default;">✓ Active</span>
            @elseif (($shop->plan ?? 'free') === 'free' || $shop->plan === 'growth')
                <form method="POST" action="{{ route('billing.upgrade') }}">
                    @csrf
                    <input type="hidden" name="plan" value="basic">
                    <button class="btn btn-primary" type="submit" style="width: 100%; justify-content: center;">Start 7-day free trial</button>
                </form>
            @endif
        </div>

        <div class="price-card">
            <span class="ribbon">Power users</span>
            <div class="name">Growth</div>
            <div class="price">₹2,499 <small>/ month</small></div>
            <div class="per">7-day free trial · up to 5,000,000 events / month</div>
            <ul>
                <li>Everything in Basic</li>
                <li>WhatsApp + multi-channel UTMs</li>
                <li>Campaign attribution breakdown</li>
                <li>Priority event delivery &amp; support</li>
            </ul>
            @if ($shop->plan === 'growth' && ($shop->isOnPaidPlan() || $shop->onTrial()))
                <span class="btn btn-ghost" style="cursor: default;">✓ Active</span>
            @elseif (($shop->plan ?? 'free') !== 'growth')
                <form method="POST" action="{{ route('billing.upgrade') }}">
                    @csrf
                    <input type="hidden" name="plan" value="growth">
                    <button class="btn btn-ghost" type="submit" style="width: 100%; justify-content: center;">Start 7-day free trial</button>
                </form>
            @endif
        </div>
    </div>

    @if ($shop->isOnPaidPlan() || $shop->onTrial())
        <div class="card mt-16">
            <h3>Manage subscription</h3>
            <p class="sub">
                Billed through Shopify at {{ $shop->plan === 'growth' ? '₹2,499' : '₹1,499' }}/month.
                Cancel anytime — your store keeps working on Free.
            </p>
            <form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm('Cancel your plan? You will be moved back to Free.');">
                @csrf
                <button class="btn btn-danger" type="submit">Cancel {{ ucfirst($shop->plan) }} plan</button>
            </form>
        </div>
    @endif
@endsection
