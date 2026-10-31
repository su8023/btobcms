<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 后台角色：区分「管理员」与「内容编辑」。
 *
 * 为什么需要：此前 canAccessPanel() 对任何登录账号都返回 true，
 * 而后台同时暴露了插件 ZIP 上传（等价 RCE）、主题安装（任意文件写）、
 * 站点设置（自定义 CSS → 存储型 XSS）与插件设置（LLM base_url → 密钥外泄），
 * 一旦账号泄露即等于整站失守。
 *
 * 取值：
 *   admin  —— 全量权限（含插件/主题/更新中心/站点设置/属性 code）
 *   editor —— 内容运营（产品/分类/文章/询盘），不可触碰高危面板
 *
 * 存量账号统一回填为 admin：避免升级瞬间把在用管理员挡在门外。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 20)->default('editor')->after('locale');
            });
        }

        // 存量账号（含安装向导与种子创建的默认管理员）升级为 admin
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
