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
        $this->authorizeAdmin();

        $data = $request->validate([
            'topic' => ['required', 'string', 'max:300'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'lang' => ['nullable', 'in:en,es,ar,ru,pt,fr,de,it'],
        ]);

        set_time_limit($this->llmTimeLimit());

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
        $this->authorizeAdmin();

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

        set_time_limit($this->llmTimeLimit());

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

    /**
     * 仅管理员可调用：这两个接口直接消耗运营方的 LLM 额度。
     */
    protected function authorizeAdmin(): void
    {
        abort_unless(user_is_admin(), 403);
    }

    /**
     * 本次请求的执行时限。
     *
     * 必须覆盖 LLM 层的最坏情况：retry(N) 会发起 N+1 次尝试，
     * 每次最多 timeout 秒，外加退避等待。
     * 旧实现写死 180s，而最坏情况是 2×120+2=242s ——
     * 供应商变慢时（恰恰最需要这个保护的时候）反而先被 PHP 掐断，
     * 客户端拿到 500 而不是干净的 422。
     */
    protected function llmTimeLimit(): int
    {
        $timeout = (int) config('ai.timeout', 120);
        $retries = (int) config('ai.retries', 2);

        return ($timeout * ($retries + 1)) + (2 * $retries) + 30;
    }
}
