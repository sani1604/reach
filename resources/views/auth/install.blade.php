<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install Reach</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="landing">
    <div class="wrap">
        <nav class="nav">
            <div class="brand"><span class="logo">R</span> Reach</div>
        </nav>
        <div class="hero" style="padding-top: 64px;">
            <span class="eyebrow">🇮🇳 OpenAI Ads Pixel for Shopify India</span>
            <h1 style="font-size: 34px;">Install Reach on your store</h1>
            <p class="lede">Connect your Shopify store to install Reach or open your existing dashboard. Shopify securely handles sign-in — we never ask for your Shopify password.</p>
            <form method="GET" action="{{ route('auth.install') }}" target="_top" style="max-width: 420px; margin: 0 auto;">
                <div class="field" style="text-align: left;">
                    <label for="shop">Your store URL</label>
                    <input type="text" id="shop" name="shop" placeholder="your-store.myshopify.com" required>
                </div>
                <button class="btn btn-primary btn-lg" type="submit" style="width: 100%; justify-content: center;">Continue with Shopify</button>
            </form>
            <p class="muted small mt-16">Already installed? Enter the same store URL to log in.</p>
            @if (config('app.env') === 'local')
                <p class="muted small mt-16">Local demo: <a href="{{ route('auth.login', ['shop' => 'demo-store.myshopify.com']) }}">open the demo dashboard</a></p>
            @endif
        </div>
    </div>
</body>
</html>
