<?php

namespace App\Providers;

use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\ContactResolver;
use App\Services\Messaging\InboundMessageRecorder;
use App\Services\Messaging\OutboundMessageRecorder;
use App\Services\Messaging\StatusRecorder;
use Illuminate\Support\ServiceProvider;

/**
 * تسجيل طبقة القنوات.
 *
 * كلّها singletons: السائقون بلا حالة، والمُسجِّلون كذلك، فبناء نسخة لكل
 * نداء هدرٌ بلا فائدة — وخاصّةً في وظيفة تعالج دفعة من عشرات الأحداث.
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(ContactResolver::class);
        $this->app->singleton(OutboundMessageRecorder::class);
        $this->app->singleton(StatusRecorder::class);

        $this->app->singleton(InboundMessageRecorder::class, function ($app) {
            return new InboundMessageRecorder($app->make(ContactResolver::class));
        });
    }
}
