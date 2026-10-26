<?php

namespace Modules\AiContentWriter\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AiContentWriter\Services\ArticleStudio;
use Throwable;

/**
 * AI 写作室 API：plan（出方案）→ write（确认后成文）。
 * 认证中间件在 routes/studio.php 的路由组上声明；LLM 调用耗时，超时放宽到 180s。
 */
class StudioController extends Controller
{
    /**
     * POST /admin/ai-studio/plan  {topic, prompt, lang}
     */
    public function plan(Request $request, ArticleStudio $studio): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'lang' => ['nullable', 'in:en,es,ar,ru,pt,fr,de,it'],
        ]);

        set_time_limit(180);

        try {
            $plan = $studio->plan(
                $data['topic'],
                (string) ($data['prompt'] ?? ''),
                $data['lang'] ?? 'en',
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'plan' => $plan]);
    }

    /**
     * POST /admin/ai-studio/write  {plan, lang, words}
     */
    public function write(Request $request, ArticleStudio $studio): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'array'],
            'plan.title' => ['required', 'string', 'max:300'],
            'plan.outline' => ['required', 'array', 'min:1'],
            'plan.outline.*.h2' => ['required', 'string', 'max:300'],
            'plan.keywords' => ['nullable', 'array'],
            'plan.faq' => ['nullable', 'array'],
            'lang' => ['nullable', 'in:en,es,ar,ru,pt,fr,de,it'],
            'words' => ['nullable', 'integer', 'between:300,4000'],
        ]);

        set_time_limit(300);

        try {
            $post = $studio->write($data['plan'], $data['lang'] ?? 'en', $data['words'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'post_id' => $post->id,
            'slug' => $post->slug,
            // 编辑页（草稿态，可内链替换/一键发布）
            'edit_url' => "/admin/posts/{$post->id}/edit",
        ]);
    }
}
