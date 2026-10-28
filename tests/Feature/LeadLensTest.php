<?php

namespace Tests\Feature;

use App\Events\InquiryCreated;
use App\Models\Inquiry;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\LeadLens\Models\InquiryEnrichment;
use Tests\TestCase;

/**
 * LeadLens 询盘雷达：创建事件 → 自动评分分层 + 邮箱域增强。
 */
class LeadLensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeInquiry(array $overrides = [], array $productIds = []): Inquiry
    {
        $inquiry = Inquiry::create(array_merge([
            'name' => 'John',
            'email' => 'john@gmail.com',
            'message' => 'Hi',
            'status' => Inquiry::STATUS_NEW,
            'source' => 'form',
        ], $overrides));

        foreach ($productIds as $pid) {
            \App\Models\InquiryItem::create(['inquiry_id' => $inquiry->id, 'product_id' => $pid]);
        }

        return $inquiry;
    }

    public function test_hot_lead_gets_full_score_and_tier(): void
    {
        $productIds = Product::query()->limit(3)->pluck('id')->all();

        $inquiry = $this->makeInquiry([
            'email' => 'john@acme-industrial.com',
            'company' => 'ACME Industrial',
            'country' => 'US',
            'phone' => '+1 555 0100',
            'message' => str_repeat('We need a quote for industrial valves with detailed specifications. ', 4),
        ], $productIds);

        event(new InquiryCreated($inquiry));

        $enrichment = InquiryEnrichment::where('inquiry_id', $inquiry->id)->first();
        $this->assertNotNull($enrichment);
        // 20+20+30+15+15+5 = 105 → 封顶 100
        $this->assertSame(100, $enrichment->score);
        $this->assertSame('hot', $enrichment->tier);
        $this->assertSame('acme-industrial.com', $enrichment->email_domain);
        $this->assertFalse($enrichment->is_free_email);
        $this->assertSame('https://acme-industrial.com', $enrichment->company_site);
    }

    public function test_cold_lead_with_free_email(): void
    {
        $inquiry = $this->makeInquiry();

        event(new InquiryCreated($inquiry));

        $enrichment = InquiryEnrichment::where('inquiry_id', $inquiry->id)->first();
        // gmail +5，其余全空 → 5 分冷线
        $this->assertSame(5, $enrichment->score);
        $this->assertSame('cold', $enrichment->tier);
        $this->assertTrue($enrichment->is_free_email);
        $this->assertNull($enrichment->company_site);
    }

    public function test_company_plus_free_email_only_stays_cold(): void
    {
        $inquiry = $this->makeInquiry([
            'email' => 'buyer@outlook.com',
            'company' => 'Some Trading Co',
        ]);

        event(new InquiryCreated($inquiry));

        $enrichment = InquiryEnrichment::where('inquiry_id', $inquiry->id)->first();
        // 公司 20 + 免费邮箱 5 = 25：信息太少仍是冷线
        $this->assertSame(25, $enrichment->score);
        $this->assertSame('cold', $enrichment->tier);
    }

    public function test_target_market_bonus(): void
    {
        $inquiry = $this->makeInquiry([
            'email' => 'a@web.de',
            'country' => 'DE',
        ]);
        // 企业邮箱 20 + 目标市场 15 = 35
        event(new InquiryCreated($inquiry));

        $this->assertSame(35, InquiryEnrichment::where('inquiry_id', $inquiry->id)->value('score'));
    }

    public function test_enrichment_is_upserted_not_duplicated(): void
    {
        $inquiry = $this->makeInquiry();

        event(new InquiryCreated($inquiry));
        event(new InquiryCreated($inquiry->refresh()));

        $this->assertSame(1, InquiryEnrichment::where('inquiry_id', $inquiry->id)->count());
    }

    public function test_product_count_capped(): void
    {
        $productIds = Product::query()->limit(3)->pluck('id')->all();
        $inquiry = $this->makeInquiry(['email' => 'a@corp.com'], $productIds);

        event(new InquiryCreated($inquiry));

        $enrichment = InquiryEnrichment::where('inquiry_id', $inquiry->id)->first();
        // 3 商品 ×10 = 30 恰好上限
        $this->assertSame(30 + 20, $enrichment->score);
    }
}
