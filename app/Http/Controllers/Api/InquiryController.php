<?php

namespace App\Http\Controllers\Api;

use App\Events\InquiryCreated;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InquiryController extends Controller
{
    public function store(Request $request): JsonResponse|Response
    {
        // landing_url 兜底归一化：旧版前端把首访落地页存成相对 pathname（历史访客的
        // localStorage），直接校验绝对 URL 会 422 拒掉整条询盘（转化漏斗实伤）。
        // 相对路径补上当前 scheme+host 后再进校验；绝对 URL 原样通过。
        if (is_string($request->input('landing_url')) && str_starts_with($request->input('landing_url'), '/')) {
            $request->merge(['landing_url' => rtrim($request->getSchemeAndHttpHost(), '/').$request->input('landing_url')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:64'],
            'company' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:8'],
            'message' => ['required', 'string', 'max:5000'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'source' => ['nullable', 'string', 'max:32'],
            'landing_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'utm' => ['nullable', 'array'],
            // utm 各值限长：否则匿名请求可写入超大 JSON，撑爆 inquiries 表
            'utm.*' => ['nullable', 'string', 'max:255'],
            // honeypot：正常用户不会填写
            'website' => ['nullable', 'string', 'max:191'],
        ]);

        // 无 JS 降级：浏览器直接提交表单（Accept: text/html）时，
        // 回跳来源页并带 flash 提示，而不是把 JSON 甩给访客看
        $wantsJson = $request->expectsJson();

        $isSpam = filled($data['website'] ?? null);

        $inquiry = DB::transaction(function () use ($data, $request, $isSpam) {
            $inquiry = Inquiry::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'country' => $data['country'] ?? null,
                'message' => $data['message'],
                'status' => $isSpam ? Inquiry::STATUS_SPAM : Inquiry::STATUS_NEW,
                'spam_score' => $isSpam ? 100 : 0,
                'source' => $data['source'] ?? 'form',
                'utm' => collect($data['utm'] ?? [])->only([
                    'source', 'medium', 'campaign', 'term', 'content',
                ])->all() ?: null,
                'landing_url' => $data['landing_url'] ?? null,
                'referrer' => $data['referrer'] ?? null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);

            foreach ($data['product_ids'] ?? [] as $productId) {
                InquiryItem::create([
                    'inquiry_id' => $inquiry->id,
                    'product_id' => $productId,
                ]);
            }

            return $inquiry;
        });

        if (! $isSpam) {
            InquiryCreated::dispatch($inquiry);
        }

        if (! $wantsJson) {
            // 无 JS 降级路径：回来源页并提示成功
            return back()->with('inquiry_success', true);
        }

        return response()->json([
            'ok' => true,
            'inquiry_id' => $inquiry->id,
        ], 201);
    }
}
