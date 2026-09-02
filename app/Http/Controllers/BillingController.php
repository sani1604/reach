<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        // Sync the plan from Shopify (covers Shopify App Pricing / managed
        // pricing subscriptions, which don't send us a webhook).
        try {
            app(BillingService::class)->syncFromShopify($shop);
            $shop->refresh();
        } catch (\Throwable $e) {
            // Billing page must render even when Shopify is unreachable.
        }

        return view('billing', compact('shop'));
    }

    public function upgrade(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $plan = $request->input('plan', 'basic');
        if (! in_array($plan, ['basic', 'growth'], true)) {
            $plan = 'basic';
        }

        if ($shop->isOnPaidPlan() || $shop->onTrial()) {
            return redirect()->route('billing');
        }

        $service = app(BillingService::class);

        try {
            $charge = $service->createCharge($shop, $plan);
        } catch (\Throwable $e) {
            // Apps on 2026 Shopify App Pricing (managed pricing) can't create
            // charges themselves — plan selection happens on Shopify's page.
            logger()->warning('Legacy billing createCharge failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            return back()->with(
                'error',
                'This app uses Shopify App Pricing — pick the plan on the Pricing page in the app listing, then reopen Billing to see it applied.'
            );
        }

        if (! $charge) {
            return back()->with('error', 'Could not start the upgrade. Please try again.');
        }

        return redirect()->away($service->confirmationUrl($shop, $charge));
    }

    public function confirm(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $chargeId = $request->query('charge_id');

        if (! $chargeId) {
            return redirect()->route('billing');
        }

        $ok = app(BillingService::class)->confirm($shop, $chargeId);

        return redirect()->route('billing')->with(
            $ok ? 'saved' : 'error',
            $ok ? 'Welcome aboard — your plan is active. 🎉' : 'Your charge is not active yet. Refresh in a moment.'
        );
    }

    public function cancel(Request $request)
    {
        $shop = $request->attributes->get('shop');

        app(BillingService::class)->cancel($shop);

        return back()->with('saved', true);
    }
}
