<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redirecting…</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="landing">
    <div class="wrap">
        <div class="hero" style="padding-top: 96px; text-align:center;">
            <div class="brand" style="justify-content:center; margin-bottom:16px;">
                <span class="logo">R</span> Reach
            </div>
            <p class="lede">Connecting to Shopify…</p>
            <p class="muted small">If nothing happens, <a href="{{ $url }}" @if($top) target="_top" @endif>continue here</a>.</p>
        </div>
    </div>

    <script>
    (function () {
        var url = @json($url);

        @if ($top)
            // OAuth must happen in the top-level window — Shopify blocks the
            // authorize screen inside the admin app iframe.
            try {
                if (window.top && window.top !== window.self) {
                    window.top.location.href = url;
                } else {
                    window.location.href = url;
                }
            } catch (e) {
                window.location.href = url;
            }
        @else
            window.location.href = url;
        @endif
    })();
    </script>
</body>
</html>
