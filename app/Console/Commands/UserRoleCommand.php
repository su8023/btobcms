<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * 调整后台账号角色。
 *
 * 背景：后台已按角色分层（admin 全量 / editor 仅内容运营）。
 * 安装向导与种子创建的账号是 admin；后台本身没有"用户管理"资源，
 * 新增账号默认落在 editor（最小权限），需要提权时用本命令。
 *
 * 用法：
 *   php artisan user:role admin@btobcms.local admin
 *   php artisan user:role newhire@example.com editor
 */
class UserRoleCommand extends Command
{
    protected $signature = 'user:role
        {email : 账号邮箱}
        {role : 目标角色（admin / editor）}';

    protected $description = '调整后台账号角色（admin=全量权限，editor=仅内容运营）';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $role = strtolower(trim((string) $this->argument('role')));

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_EDITOR], true)) {
            $this->error("角色无效：{$role}（可选：".User::ROLE_ADMIN.' / '.User::ROLE_EDITOR.'）');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("账号不存在：{$email}");

            return self::FAILURE;
        }

        $before = $user->role;
        $user->role = $role;
        $user->save();

        $this->info("{$email}：{$before} → {$role}");

        return self::SUCCESS;
    }
}
