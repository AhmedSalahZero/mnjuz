<?php

namespace Modules\FlowBuilder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\FlowExecutionService;

class ProcessDelayedFlowJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $contactId;
    protected $flowDataId;
    protected $flowId;
    protected $currentStep;
    protected $organizationId;


    /**
     * Create a new job instance.
     */
    public function __construct(int $flowDataId , $contactId, $flowId, $currentStep, $organizationId)
    {
        $this->contactId = $contactId;
        $this->flowDataId = $flowDataId;
        $this->flowId = $flowId;
        $this->currentStep = $currentStep;
        $this->organizationId = $organizationId;
    }

    /**
     * الـ unique ID بناءً على contactId و flowId
     */
    public function uniqueId()
    {
        // الجلسة جزءٌ من المفتاح: من أعاد المسار بكلمة مفتاحية يحصل على جلسة
        // جديدة، وكان قفل المهمة القديمة يُسقط مهمته بصمت فلا يصله ما بعد التأخير.
        return "flow_{$this->flowId}_contact_{$this->contactId}_session_{$this->flowDataId}";
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $flowData = FlowUserData::find($this->flowDataId);

            if (!$flowData) {
                Log::info("Delayed flow skipped - FlowUserData no longer exists", [
                    'flow_data_id' => $this->flowDataId,
                    'contact_id' => $this->contactId,
                    'flow_id' => $this->flowId,
                ]);
                return;
            }

            // مسارٌ أُوقف نشره أثناء الانتظار لا يُكمل الإرسال.
            if (!Flow::whereKey($this->flowId)->where('status', 'active')->exists()) {
                Log::info("Delayed flow skipped - flow is no longer active", [
                    'flow_data_id' => $this->flowDataId,
                    'flow_id' => $this->flowId,
                ]);
                return;
            }

            // لا يُعاد ضبط الخطوة هنا: continueDelayedFlow يُكمل فقط إن كان العميل
            // ما زال واقفاً على عقدة التأخير ($currentStep).
            $flowExecutionService = new FlowExecutionService($this->organizationId);
            $flowExecutionService->continueDelayedFlow($this->contactId, $this->flowId, $this->currentStep);
        } catch (\Exception $e) {
            Log::error("Error processing delayed flow: " . $e->getMessage(), [
                'flow_data_id' => $this->flowDataId,
                'contact_id' => $this->contactId,
                'flow_id' => $this->flowId,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
