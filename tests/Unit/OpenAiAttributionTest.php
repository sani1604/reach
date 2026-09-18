<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Services\OpenAiAttribution;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class OpenAiAttributionTest extends TestCase
{
    public function test_rejects_empty_or_organic_payloads(): void
    {
        $this->assertFalse(OpenAiAttribution::isAttributed(null));
        $this->assertFalse(OpenAiAttribution::isAttributed([]));
        $this->assertFalse(OpenAiAttribution::isAttributed([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]));
        $this->assertFalse(OpenAiAttribution::isAttributed([
            'user_data' => ['email' => 'hash', 'phone' => 'hash'],
        ]));
    }

    public function test_accepts_oppref_and_click_id_aliases(): void
    {
        $this->assertTrue(OpenAiAttribution::isAttributed(['oppref' => 'opp-1']));
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'user_data' => ['oppref' => 'opp-2'],
        ]));
        $this->assertTrue(OpenAiAttribution::isAttributed(['oai_click_id' => 'clk-1']));
        $this->assertTrue(OpenAiAttribution::isAttributed(['chatgpt_aid' => 'aid-1']));
        $this->assertTrue(OpenAiAttribution::isAttributed(['oai_cid' => 'cid-1']));
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'user_data' => ['oai_click_id' => 'clk-2'],
        ]));
    }

    public function test_accepts_chatgpt_and_openai_utm(): void
    {
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'utm_source' => 'chatgpt',
        ]));
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'utm_source' => 'OpenAI',
            'utm_medium' => 'cpc',
        ]));
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'utm_medium' => 'chatgpt_ads',
        ]));
        $this->assertTrue(OpenAiAttribution::isAttributed([
            'user_data' => ['utm_source' => 'chatgpt'],
        ]));
    }

    public function test_filter_sum_and_orders(): void
    {
        $events = new Collection([
            $this->fakeEvent(1001, 5000, ['oppref' => 'a']),
            $this->fakeEvent(1002, 3000, ['utm_source' => 'google']),
            $this->fakeEvent(1003, 2000, ['utm_source' => 'chatgpt']),
            $this->fakeEvent(null, 100, []),
        ]);

        $attributed = OpenAiAttribution::filterAttributed($events);
        $this->assertCount(2, $attributed);
        $this->assertSame(7000.0, OpenAiAttribution::sumValue($events));
        $this->assertSame(2, OpenAiAttribution::distinctOrders($events));
    }

    public function test_attributed_refunds_match_order_or_signal(): void
    {
        $purchases = new Collection([
            $this->fakeEvent(1001, 5000, ['oppref' => 'a']),
            $this->fakeEvent(1002, 3000, []), // organic
        ]);
        $refunds = new Collection([
            $this->fakeEvent(1001, 500, []), // refund of attributed order
            $this->fakeEvent(1002, 300, []), // organic refund — excluded
            $this->fakeEvent(1009, 100, ['oppref' => 'x']), // signal on refund
        ]);

        $out = OpenAiAttribution::attributedRefunds($purchases, $refunds);
        $this->assertCount(2, $out);
        $this->assertSame(600.0, (float) $out->sum(fn ($e) => (float) $e->value));
    }

    private function fakeEvent(?int $orderId, float $value, array $payload): Event
    {
        $event = new Event;
        $event->order_id = $orderId !== null ? (string) $orderId : null;
        $event->value = $value;
        $event->payload = $payload;

        return $event;
    }
}
