<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackendErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 后台请求失败的诊断（v1.4.29）：
 * Filament 默认只弹「页面加载出错」，管理员与排障都无从下手——
 * 这里保证真实异常（类名/消息/位置）一定写进日志，并能在诊断页读到。
 */
class BackendErrorDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
    }

    public function test_backend_exception_is_logged_with_diagnostics(): void
    {
        Log::spy();

        $this->app['router']->get('/admin/__diagnostics_probe', function () {
            throw new \RuntimeException('诊断探针异常：用于验证后台错误留痕');
        })->middleware('web');

        $this->get('/admin/__diagnostics_probe')->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, '[backend-request-failed]')
                    && ($context['exception'] ?? '') === \RuntimeException::class
                    && str_contains((string) ($context['message'] ?? ''), '诊断探针异常');
            })
            ->once();
    }

    public function test_frontend_failure_is_not_logged_as_backend(): void
    {
        Log::spy();

        $this->get('/__frontend-probe-'.uniqid())->assertNotFound();

        Log::shouldNotHaveReceived('error');
    }

    public function test_diagnostics_page_lists_recent_failures(): void
    {
        // 写入一条真实日志（走 Log::error 而非 spy）
        Log::error('[backend-request-failed] GET /admin/probe-url', [
            'exception' => \RuntimeException::class,
            'message' => '探针消息',
            'status' => 500,
            'at' => '/app/Foo.php:10',
        ]);

        $entries = BackendErrorLog::recent(10);

        $this->assertNotEmpty($entries, '诊断记录应能从日志读出');
        $this->assertSame('/admin/probe-url', $entries[0]['url']);
        $this->assertSame(\RuntimeException::class, $entries[0]['exception']);
        $this->assertSame('探针消息', $entries[0]['message']);

        // 诊断页可访问（管理员）
        $this->get('/admin/backend-errors')->assertOk()->assertSee('后台错误诊断');
    }

    public function test_diagnostics_page_hidden_from_non_admin(): void
    {
        // 普通用户不可访问
        $user = \App\Models\User::where('role', '!=', 'admin')->first()
            ?? \App\Models\User::query()->create([
                'name' => 'tester', 'email' => 'tester'.uniqid().'@example.com',
                'password' => bcrypt('x'), 'role' => 'editor',
            ]);

        $this->actingAs($user)->get('/admin/backend-errors')->assertForbidden();
    }
}
