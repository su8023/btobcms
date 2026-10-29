<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OAuth 凭据：一个站点属性一行（site_url 唯一）。
        // access/refresh_token 均为 Google 明文令牌，text 存储以便容纳超长 JWT。
        Schema::create('gsc_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('site_url', 191)->unique(); // GSC 站点属性，如 https://www.aples.xyz/
            $table->text('access_token')->nullable();  // 短期令牌（约 1 小时过期，可自动刷新）
            $table->text('refresh_token')->nullable(); // 长期令牌（prompt=consent 才下发，断开重连可换取新值）
            $table->datetime('token_expires_at')->nullable(); // access_token 过期时刻，据此提前刷新
            $table->timestamps();
        });

        // GSC 每日搜索表现流水：page × query × country × date 粒度。
        //
        // upsert 策略说明：GSC 的唯一自然键是 site_url + date + page + query + country 五元组，
        // 直接做复合唯一索引在 string(500) 字段上会超出 MySQL utf8mb4 索引长度上限，
        // 因此把五元组拼接后取 md5 存入 fingerprint（32 位定长），以 fingerprint 作为
        // updateOrCreate 的唯一键——语义与五元组唯一完全等价，且对 SQLite/MySQL 都稳。
        Schema::create('gsc_daily', function (Blueprint $table) {
            $table->id();
            $table->string('site_url', 191);          // 与 gsc_credentials.site_url 对应
            $table->date('date');                     // 数据归属日（GSC 返回日期）
            $table->string('page', 500);              // 落地页完整 URL
            $table->string('query', 500);             // 搜索词
            $table->string('country', 8)->nullable(); // ISO 3166 国家码（如 us），可为空
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 6, 2)->nullable(); // 平均排名
            $table->string('fingerprint', 32)->unique();   // 五元组 md5，upsert 唯一键
            $table->timestamps();

            $table->index(['site_url', 'date']); // 看板窗口聚合的主要过滤维度
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_daily');
        Schema::dropIfExists('gsc_credentials');
    }
};
