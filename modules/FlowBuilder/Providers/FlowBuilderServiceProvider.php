<?php

namespace Modules\FlowBuilder\Providers;

use App\Models\Chat;
use Illuminate\Support\ServiceProvider;
use Modules\FlowBuilder\Support\FlowSessions;


class FlowBuilderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
        
        // Register config files
        $this->publishes([
            __DIR__ . '/../config/actions.php' => config_path('FlowBuilder/actions.php'),
        ], 'flowbuilder-config');
        
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/actions.php', 'FlowBuilder.actions');

        // موظّفٌ ردّ من المنصّة: المحادثة صارت بيده فلا يُكمل الـ flow فوقه.
        // رسائل الـ flow تُرسَل بـ user_id = 0 والردود الآلية بلا مستخدم،
        // فـ user_id > 0 هو الإنسان. (رسائل تطبيق الأعمال: ProcessMessageEchoJob.)
        Chat::created(function (Chat $chat) {
            if ($chat->type === 'outbound' && (int) $chat->user_id > 0) {
                FlowSessions::endForContact((int) $chat->contact_id);
            }
        });
    }
}