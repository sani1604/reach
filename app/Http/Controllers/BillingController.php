<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

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
        $charge = $service->createCharge($shop, $plan);

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
