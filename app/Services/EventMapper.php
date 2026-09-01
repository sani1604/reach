<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Maps Shopify customer events onto the OpenAI Ads / Meta CAPI event taxonomy.
 *
 * Standard events: PageView, ViewContent, AddToCart, InitiateCheckout, Purchase.
 */
class EventMapper
{
    public function build(string $eventName, array $data = []): array
    {
        $event = [
            'event_name'    => $eventName,
            'event_time'    => (int) ($data['event_time'] ?? time()),
            'event_id'      => (string) ($data['event_id'] ?? Str::uuid()),
            'action_source' => $data['action_source'] ?? 'website',
        ];

        $custom = [];
        if (! empty($data['currency'])) {
            $custom['currency'] = $data['currency'];
        }
        if (isset($data['value'])) {
            $custom['value'] = round((float) $data['value'], 2);
        }
        if (! empty($data['content_ids'])) {
            $custom['content_ids'] = (array) $data['content_ids'];
        }
        if (! empty($data['content_type'])) {
            $custom['content_type'] = $data['content_type'];
        }
        if (! empty($data['num_items'])) {
            $custom['num_items'] = (int) $data['num_items'];
        }
        if (! empty($data['order_id'])) {
            $custom['order_id'] = (string) $data['order_id'];
        }
        if (! empty($data['products'])) {
            $custom['contents'] = $this->contents((array) $data['products']);
        }
        if ($custom) {
            $event['custom_data'] = $custom;
        }

        if (! empty($data['user_data'])) {
            $event['user_data'] = $this->userData((array) $data['user_data']);
        }

        return $event;
    }

    protected function contents(array $products): array
    {
        return array_map(function ($p) {
            return [
                'id'         => (string) ($p['id'] ?? ''),
                'quantity'   => (int) ($p['quantity'] ?? 1),
                'item_price' => round((float) ($p['price'] ?? 0), 2),
                'title'      => (string) ($p['title'] ?? ''),
            ];
        }, $products);
    }

    /**
     * Normalize + SHA-256 hash user identifiers, Meta CAPI-style.
     */
    protected function userData(array $u): array
    {
        $out = [];

        if (! empty($u['email'])) {
            $out['em'] = hash('sha256', strtolower(trim($u['email'])));
        }
        if (! empty($u['phone'])) {
            $out['ph'] = hash('sha256', preg_replace('/\D/', '', $u['phone']));
        }
        if (! empty($u['client_ip_address'])) {
            $out['client_ip_address'] = $u['client_ip_address'];
        }
        if (! empty($u['client_user_agent'])) {
            $out['client_user_agent'] = $u['client_user_agent'];
        }
        if (! empty($u['fbc'])) {
            $out['fbc'] = $u['fbc'];
        }
        if (! empty($u['fbp'])) {
            $out['fbp'] = $u['fbp'];
        }

        return $out;
    }
}
