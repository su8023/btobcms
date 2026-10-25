<?php

namespace App\Http\Controllers\Api;

use App\Events\InquiryCreated;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InquiryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
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
            'landing_url' => ['nullable', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'utm' => ['nullable', 'array'],
            // honeypot：正常用户不会填写
            'website' => ['nullable', 'string', 'max:191'],
        ]);

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

        return response()->json([
            'ok' => true,
            'inquiry_id' => $inquiry->id,
        ], 201);
    }
}
