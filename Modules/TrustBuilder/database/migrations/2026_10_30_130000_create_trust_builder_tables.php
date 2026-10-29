<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 客户案例：slug 定位详情页 /case-studies/{slug}，
        // title/body 多语言（spatie Translatable），published 控制前台可见
        Schema::create('trust_cases', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();            // 详情页地址，全语言共用
            $table->string('customer_name');             // 客户名称（如 Acme GmbH）
            $table->string('industry')->nullable();      // 所属行业（相关案例按此匹配）
            $table->string('country', 8)->nullable();    // 国家/地区 ISO 码（如 DE）
            $table->json('title');                       // 多语言标题
            $table->json('body');                        // 多语言富文本 HTML
            $table->string('cover')->nullable();         // 封面图相对路径（public 盘）
            $table->json('images')->nullable();          // 图集相对路径列表（public 盘）
            $table->boolean('published')->default(false); // 下线后列表/详情均 404
            $table->unsignedInteger('sort')->default(0); // 越小越靠前
            $table->timestamps();

            $table->index(['published', 'sort']); // 列表页查询：已发布 + 排序
            $table->index(['industry', 'published']); // 相关案例查询：同行业已发布
        });

        // 认证证书：名称多语言，其余为元数据；仅在全站信任条展示，无前台详情页
        Schema::create('trust_certifications', function (Blueprint $table) {
            $table->id();
            $table->json('name');                        // 多语言证书名（ISO 9001:2015 …）
            $table->string('issuer')->nullable();        // 颁发机构
            $table->string('cert_no')->nullable();       // 证书编号
            $table->date('issued_at')->nullable();       // 签发日期
            $table->date('expires_at')->nullable();      // 到期日期
            $table->string('image')->nullable();         // 证书扫描件相对路径（public 盘）
            $table->unsignedInteger('sort')->default(0); // 信任条展示顺序
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_certifications');
        Schema::dropIfExists('trust_cases');
    }
};
