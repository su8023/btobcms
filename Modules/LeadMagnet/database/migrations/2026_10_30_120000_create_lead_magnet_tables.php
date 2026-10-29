<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 资料磁铁：一份可下载的资料（PDF/ZIP/DOCX），访客留邮箱换取下载链接
        Schema::create('lead_magnets', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();            // 落地页地址 /download/{slug}
            $table->json('title');                       // 多语言标题（spatie Translatable）
            $table->json('description')->nullable();     // 多语言描述
            $table->string('file_path');                 // 文件相对路径（local private 盘）
            $table->boolean('active')->default(true);    // 下线后落地页/表单均 404
            $table->unsignedInteger('downloads_count')->default(0); // 冗余计数：文件被实际下载次数
            $table->timestamps();
        });

        // 下载记录：谁（邮箱）在什么语言/IP/渠道下索取了资料
        Schema::create('magnet_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magnet_id')->constrained('lead_magnets')->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('locale', 8);                 // 提交表单时的站点语言
            $table->string('ip', 45)->nullable();        // 兼容 IPv6
            $table->json('utm')->nullable();             // 落地页 URL 上的 utm_* 参数
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magnet_downloads');
        Schema::dropIfExists('lead_magnets');
    }
};
