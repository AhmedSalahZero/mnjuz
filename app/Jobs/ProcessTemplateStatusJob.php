<?php

namespace App\Jobs;

use App\Models\Template;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTemplateStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 3;

    protected $templateData;
    protected $organizationId;

    public function __construct($templateData, $organizationId)
    {
        $this->templateData = $templateData;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
            $template = Template::where('meta_id', $this->templateData['message_template_id'])
                ->first();

            if($template) {
                $template->status = $this->templateData['event'];
                $template->save();

                Log::info('Template status updated', [
                    'template_id' => $template->id,
                    'status' => $this->templateData['event']
                ]);
            } else {
                Log::warning('Template not found', [
                    'meta_id' => $this->templateData['message_template_id']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ProcessTemplateStatusJob failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
