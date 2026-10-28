<?php

namespace Modules\LeadLens\Services;

use App\Models\Inquiry;
use Modules\LeadLens\Models\InquiryEnrichment;

/**
 * 线索评分器：询盘基础信息 → 0~100 分 + 热/温/冷分层。
 *
 * 规则（各项独立、可解释，明细存 details 供后台展示）：
 *   公司名已填            +20
 *   企业邮箱（非免费域）    +20（免费邮箱 +5）
 *   询盘商品数 ×10         上限 30
 *   留言 ≥ 100 字符        +15
 *   国家命中目标市场        +15
 *   电话已填               +5
 */
class LeadScorer
{
    /** 常见免费邮箱域（命中即视为个人邮箱而非企业采购） */
    protected const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com',
        'yahoo.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com',
        'mail.ru', 'yandex.com', 'yandex.ru', 'gmx.com',
        '163.com', '126.com', 'qq.com', 'foxmail.com', 'sina.com', 'yeah.net',
    ];

    /**
     * 评分并落库（upsert）：新询盘监听与历史补评共用。
     */
    public function enrich(Inquiry $inquiry): InquiryEnrichment
    {
        return InquiryEnrichment::updateOrCreate(
            ['inquiry_id' => $inquiry->id],
            $this->score($inquiry),
        );
    }

    /**
     * @return array{score: int, tier: string, email_domain: ?string, is_free_email: bool, company_site: ?string, details: list<array{item: string, points: int}>}
     */
    public function score(Inquiry $inquiry): array
    {
        $details = [];
        $score = 0;

        // 公司名
        if (filled($inquiry->company)) {
            $score += 20;
            $details[] = ['item' => '公司名已填', 'points' => 20];
        }

        // 邮箱域分析
        $emailDomain = $this->domainOf((string) $inquiry->email);
        $isFree = $emailDomain !== null && in_array($emailDomain, self::FREE_EMAIL_DOMAINS, true);
        if ($emailDomain !== null) {
            if ($isFree) {
                $score += 5;
                $details[] = ['item' => "免费邮箱（{$emailDomain}）", 'points' => 5];
            } else {
                $score += 20;
                $details[] = ['item' => "企业邮箱（{$emailDomain}）", 'points' => 20];
            }
        }

        // 询盘商品数
        $productCount = $inquiry->items()->count();
        if ($productCount > 0) {
            $points = min($productCount * 10, 30);
            $score += $points;
            $details[] = ['item' => "指定 {$productCount} 个产品", 'points' => $points];
        }

        // 留言质量
        if (mb_strlen((string) $inquiry->message) >= 100) {
            $score += 15;
            $details[] = ['item' => '留言详尽（≥100 字符）', 'points' => 15];
        }

        // 目标市场
        $country = strtoupper(trim((string) $inquiry->country));
        if ($country !== '' && in_array($country, (array) config('lead-lens.target_countries'), true)) {
            $score += 15;
            $details[] = ['item' => "目标市场（{$country}）", 'points' => 15];
        }

        // 电话
        if (filled($inquiry->phone)) {
            $score += 5;
            $details[] = ['item' => '电话已填', 'points' => 5];
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'tier' => $this->tierOf($score),
            'email_domain' => $emailDomain,
            'is_free_email' => $isFree,
            'company_site' => ($emailDomain !== null && ! $isFree) ? "https://{$emailDomain}" : null,
            'details' => $details,
        ];
    }

    protected function tierOf(int $score): string
    {
        return match (true) {
            $score >= (int) config('lead-lens.hot_threshold', 70) => 'hot',
            $score >= (int) config('lead-lens.warm_threshold', 40) => 'warm',
            default => 'cold',
        };
    }

    protected function domainOf(string $email): ?string
    {
        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        return $domain !== '' ? $domain : null;
    }
}
