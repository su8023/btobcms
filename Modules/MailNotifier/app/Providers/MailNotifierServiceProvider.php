<?php

namespace Modules\MailNotifier\Providers;

use App\Events\InquiryCreated;
use Illuminate\Support\Facades\Event;
use Modules\MailNotifier\Console\Commands\MailTestCommand;
use Modules\MailNotifier\Listeners\SendInquiryEmails;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MailNotifierServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'MailNotifier';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'mailnotifier';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        MailTestCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [];

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'mail-notifier');

        // 插件通过监听核心事件接入，不改动任何核心代码
        Event::listen(InquiryCreated::class, SendInquiryEmails::class);
    }
}
