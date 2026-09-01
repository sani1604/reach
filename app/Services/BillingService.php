<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Shop;
use Carbon\Carbon;

class BillingService
{
    public function __construct(private ShopifyClient $client)
    {
    }

    public function createCharge(Shop $shop, string $plan = 'basic'): ?Charge
    {
        $cfg = config('ads.plans.'.$plan, []);
        if (! $cfg || (float) $cfg['price'] <= 0) {
            return null;
        }

        $charge = $this->client->createRecurringCharge($shop, $plan);

        if (empty($charge['id'])) {
            return null;
        }

        return $shop->charges()->create([
            'charge_id'        => $charge['id'],
            'confirmation_url' => $charge['confirmation_url'] ?? null,
            'type'             => 'recurring',
            'plan'          => $plan,
            'status'        => $charge['status'] ?? 'pending',
            'amount'        => $charge['price'] ?? $cfg['price'],
            'currency'      => $charge['currency'] ?? $cfg['currency'],
            'billing_on'    => isset($charge['billing_on']) ? Carbon::parse($charge['billing_on']) : null,
            'trial_ends_on' => isset($charge['trial_ends_on']) ? Carbon::parse($charge['trial_ends_on']) : null,
        ]);
    }

    /**
     * URL to send the merchant to so they can approve the charge.
     */
    public function confirmationUrl(Shop $shop, Charge $charge): string
    {
        if ($charge->confirmation_url) {
            return $charge->confirmation_url;
        }

        return "https://{$shop->shopify_domain}/admin/charges/{$charge->charge_id}/"
            .config('shopify.app_handle', 'reach').'/recurring_application_charge';
    }

    /**
     * After the merchant accepts the charge, Shopify bounces back to our
     * return_url with ?charge_id=. Verify it is active, then upgrade.
     */
    public function confirm(Shop $shop, string $chargeId): bool
    {
        $charge = $this->client->getCharge($shop, $chargeId);

        if (($charge['status'] ?? null) === 'active') {
            $stored = $shop->charges()->where('charge_id', $chargeId)->first();
            $plan = $stored?->plan ?: 'basic';

            $shop->update(['plan' => $plan, 'plan_status' => 'active']);
            $shop->charges()->where('charge_id', $chargeId)->update([
                'status'       => 'active',
                'activated_on' => now(),
            ]);

            return true;
        }

        return false;
    }

    public function cancel(Shop $shop): bool
    {
        $charge = $shop->charges()
            ->where('type', 'recurring')
            ->where('status', 'active')
            ->first();

        if ($charge && $charge->charge_id) {
            $this->client->delete($shop, "/recurring_application_charges/{$charge->charge_id}.json");
            $charge->update(['status' => 'cancelled', 'cancelled_on' => now()]);
        }

        $shop->update(['plan' => 'free', 'plan_status' => null]);

        return true;
    }

    /**
     * Handle the app_subscriptions/update webhook.
     */
    public function handleSubscriptionUpdate(Shop $shop, array $data): void
    {
        $status = $data['status'] ?? null;

        if ($status === 'active') {
            // Derive the plan from the charge we created (fallback: basic).
            $stored = $shop->charges()
                ->whereIn('status', ['active', 'pending'])
                ->latest()
                ->first();
            $plan = $stored?->plan ?: 'basic';

            $shop->update(['plan' => $plan, 'plan_status' => 'active']);
        } elseif (in_array($status, ['cancelled', 'expired', 'frozen', 'declined'], true)) {
            $shop->update(['plan' => 'free', 'plan_status' => null]);
        }
    }
}
