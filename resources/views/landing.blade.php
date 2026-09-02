<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reach — OpenAI Ads Pixel for Shopify India</title>
    <meta name="description" content="Track ChatGPT Ads conversions, Shopify purchases and revenue with Reach — the OpenAI Ads Pixel and Conversions API built for Shopify merchants.">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="landing">
    <div class="wrap">
        <nav class="nav">
            <div class="brand"><span class="logo">R</span> Reach</div>
            <a class="btn btn-ghost btn-sm" href="{{ route('auth.login') }}">Log in with Shopify</a>
            <a class="btn btn-primary btn-sm" href="{{ route('auth.install') }}">Install Free</a>
        </nav>
    </div>

    <header class="hero wrap">
        <span class="eyebrow">🇮🇳 Built for Indian Shopify brands</span>
        <h1>From ChatGPT click to Shopify purchase — <span class="grad">track the complete journey.</span></h1>
        <p class="lede">Reach installs the OpenAI Ads pixel and server-side Conversions API on your store in one click. See every PageView, Add to Cart, Checkout and Purchase — with accurate attribution, even when ad-blockers get in the way. No theme edits.</p>
        <div class="cta-row">
            <a class="btn btn-saffron btn-lg" href="{{ route('auth.install') }}">Install with Shopify</a>
            <a class="btn btn-ghost btn-lg" href="{{ route('auth.login') }}">Log in with Shopify</a>
            <a class="btn btn-ghost btn-lg" href="#how">See How It Works</a>
        </div>
        <div class="trust">
            <span>⚡ 30-second setup</span>
            <span>📊 5 events tracked</span>
            <span>🔄 Browser + server</span>
            <span>🚫 Survives ad-blockers</span>
            <span>🧩 No theme-code changes</span>
        </div>
    </header>

    <section id="how">
        <div class="wrap">
            <h2>Live in three steps</h2>
            <p class="sec-lede">No developers, no theme edits, no waiting. Install, paste, done.</p>
            <div class="steps">
                <div class="step">
                    <div class="num">1</div>
                    <h4>Install Reach</h4>
                    <p>One click installs the app on your Shopify store and auto-loads the OpenAI Ads pixel on every page.</p>
                </div>
                <div class="step">
                    <div class="num">2</div>
                    <h4>Paste your keys</h4>
                    <p>Add your Pixel ID and Conversions API key. Takes about 30 seconds.</p>
                </div>
                <div class="step">
                    <div class="num">3</div>
                    <h4>Track &amp; attribute</h4>
                    <p>Watch a live funnel of PageView → Product View → Add to Cart → Checkout → Purchase, with revenue tied to real orders.</p>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="wrap">
            <h2>Every step of the customer journey</h2>
            <p class="sec-lede">Five Shopify events, mapped to the OpenAI Ads taxonomy — browser and server.</p>
            <div class="funnel-strip">
                <span class="chip">PageView</span><span class="arrow">→</span>
                <span class="chip">Product View</span><span class="arrow">→</span>
                <span class="chip">Add to Cart</span><span class="arrow">→</span>
                <span class="chip">Checkout</span><span class="arrow">→</span>
                <span class="chip dark">Purchase</span>
            </div>
        </div>
    </section>

    <section>
        <div class="wrap">
            <h2>Why server-side tracking matters</h2>
            <p class="sec-lede">Browser pixels get blocked by ad-blockers and Safari. Reach forwards your Shopify orders server-side through the OpenAI Conversions API — with a shared event ID so nothing is double-counted.</p>
            <div class="steps">
                <div class="step">
                    <h4>🔒 Survives ad-blockers</h4>
                    <p>Orders are sent from Shopify's servers, not the browser, so events aren't lost to blockers or Intelligent Tracking Prevention.</p>
                </div>
                <div class="step">
                    <h4>🪪 One event, one count</h4>
                    <p>Browser and server events share an <span class="mono">event_id</span>, so every conversion is attributed exactly once.</p>
                </div>
                <div class="step">
                    <h4>🛒 COD-ready for India</h4>
                    <p>Cash-on-delivery and prepaid orders are both captured correctly, tied to real Shopify orders and revenue.</p>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="wrap">
            <h2>Simple, affordable pricing</h2>
            <p class="sec-lede">Start free. Upgrade when you want revenue attribution and alerts.</p>
            <div class="pricing">
                <div class="price-card">
                    <div class="name">Free</div>
                    <div class="price">₹0</div>
                    <div class="per">forever, up to 50,000 events / month</div>
                    <ul>
                        <li>OpenAI Ads pixel (1-click install)</li>
                        <li>100% server-side event delivery</li>
                        <li>Live events dashboard &amp; funnel</li>
                        <li>5 events tracked, ad-blocker-proof</li>
                    </ul>
                    <a class="btn btn-ghost" href="{{ route('auth.install') }}">Install Free</a>
                </div>
                <div class="price-card featured">
                    <span class="ribbon">Most popular</span>
                    <div class="name">Basic</div>
                    <div class="price">₹499 <small>/ month</small></div>
                    <div class="per">7-day free trial · up to 1,000,000 events / month</div>
                    <ul>
                        <li>Everything in Free</li>
                        <li>Revenue from OpenAI Ads</li>
                        <li>Top products sold via ChatGPT Ads</li>
                        <li>Attribution OpenAI can't show</li>
                        <li>Email alerts if your pixel breaks</li>
                    </ul>
                    <a class="btn btn-primary" href="{{ route('auth.install') }}">Install Free</a>
                </div>
            </div>
            <p class="sec-lede" style="margin-top: 20px;">Need more? The <strong>Growth</strong> plan adds campaign &amp; UTM attribution, priority delivery and up to 5,000,000 events/month for ₹1,999.</p>
        </div>
    </section>

    <section>
        <div class="wrap">
            <h2>Questions, answered</h2>
            <div class="faq">
                <details>
                    <summary>Does OpenAI actually have ads?</summary>
                    <p>Yes — OpenAI Ads lets brands reach users on ChatGPT. Reach plugs your Shopify store into that traffic so you can see what those clicks actually buy.</p>
                </details>
                <details>
                    <summary>Will this slow down my store?</summary>
                    <p>No. The pixel loads asynchronously and server-side events are sent from our servers in the background, never from your customer's browser.</p>
                </details>
                <details>
                    <summary>Do I need to edit my theme code?</summary>
                    <p>No. Reach uses Shopify's Customer Events system to install the pixel automatically. No Liquid, no theme changes.</p>
                </details>
                <details>
                    <summary>Is my store data safe?</summary>
                    <p>We forward only the events and order data needed for attribution, over HTTPS, and store your credentials encrypted. We never touch your customer list.</p>
                </details>
                <details>
                    <summary>What happens after 50,000 events?</summary>
                    <p>Your pixel keeps firing and your dashboard keeps working. For unlimited scale plus revenue reporting and alerts, upgrade to Basic.</p>
                </details>
            </div>
        </div>
    </section>

    <footer class="footer wrap">
        <span>© {{ date('Y') }} Reach — The OpenAI Ads Pixel for Shopify India.</span>
        <span>Made for Indian Shopify brands. Built for accurate attribution.</span>
    </footer>
</body>
</html>
