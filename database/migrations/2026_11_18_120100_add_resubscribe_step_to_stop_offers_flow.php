<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Validators\FlowValidator;

return new class extends Migration
{
    /** «مجمع تجديد الصحة والجمال الطبي» (id 295 على الإنتاج). */
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';

    /** مسار «ايقاف الاعلانات» (id 217 على الإنتاج). */
    private const FLOW_UUID = 'c3b35d4a-71a8-4dad-9256-edaeb937c2cb';

    private const RESUBSCRIBE_BUTTON = 'إعادة الإشتراك';
    private const ACTION = 'resubscribe_marketing';

    /** مقبض كل زرّ في عقدة الأزرار، كما يقرؤه المنفّذ (FlowExecutionService::choiceHandle). */
    private const BUTTON_HANDLES = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];

    /**
     * إضافة خطوة «إعادة الاشتراك في العروض» بعد زرّ «إعادة الإشتراك» في مسار
     * المنشأة.
     *
     * الزرّ كان يضيف العميل إلى مجموعة فقط، والإضافة لا تمسح الانسحاب، فيُقال
     * للعميل «لن يفوتك أي عرض» ويبقى خارج كل حملة. الخطوة تُدرج بين الزرّ وما
     * يليه، فيبقى ما بعدها كما بناه التاجر.
     *
     * لا يُلمس المسار إن لم يكن بالشكل المعروف: فيه الإجراء أصلاً (أضافه التاجر
     * بنفسه)، أو لا زرّ «إعادة الإشتراك» موصول، أو أكثر من زرّ. والنتيجة تمرّ
     * بمدقّق المسارات نفسه قبل الحفظ.
     */
    public function up(): void
    {
        $organizationId = DB::table('organizations')->where('uuid', self::ORGANIZATION_UUID)->value('id');
        if ($organizationId === null) {
            return;
        }

        $flow = DB::table('flows')
            ->where('uuid', self::FLOW_UUID)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->first(['id', 'metadata', 'updated_at']);

        if ($flow === null) {
            return;
        }

        $metadata = $this->withResubscribeStep((string) $flow->metadata);
        if ($metadata === null) {
            return;
        }

        // لا يُكتب فوق تعديلٍ حفظه التاجر بين القراءة والكتابة.
        $updated = DB::table('flows')
            ->where('id', $flow->id)
            ->where('updated_at', $flow->updated_at)
            ->update(['metadata' => $metadata, 'updated_at' => now()]);

        Log::info('Added the resubscribe step to the stop-offers flow', ['flow_id' => $flow->id, 'updated' => $updated]);
    }

    /** المسار بعد إدراج الخطوة، أو null إن لم يكن بالشكل المعروف. */
    private function withResubscribeStep(string $json): ?string
    {
        $metadata = json_decode($json);
        if (!is_object($metadata) || !is_array($metadata->nodes ?? null) || !is_array($metadata->edges ?? null)) {
            return $this->skip('the flow metadata is not a graph');
        }

        if ($this->hasResubscribeStep($metadata)) {
            return $this->skip('the flow already has the resubscribe step');
        }

        $matches = array_values(array_filter($metadata->edges, fn ($edge) => $this->leavesResubscribeButton($edge)));
        if (count($matches) !== 1) {
            return $this->skip('expected one edge from the resubscribe button, found ' . count($matches));
        }

        $edge = $matches[0];
        $source = $this->node($metadata, (string) $edge->source) ?? $edge->sourceNode;
        $target = $this->node($metadata, (string) $edge->target);
        if ($target === null) {
            return $this->skip('the resubscribe button leads to a missing step');
        }

        $stepId = (string) ($this->highestNodeId($metadata) + 1);
        $step = (object) [
            'id' => $stepId,
            'type' => 'action',
            'label' => self::ACTION . ' action',
            'position' => (object) [
                'x' => (($source->position->x ?? 0) + ($target->position->x ?? 0)) / 2,
                'y' => (($source->position->y ?? 0) + ($target->position->y ?? 0)) / 2,
            ],
            'data' => (object) [
                'uuid' => self::FLOW_UUID,
                'title' => self::ACTION,
                'actionType' => self::ACTION,
                'config' => new \stdClass(),
                'is_active' => true,
            ],
        ];
        $metadata->nodes[] = $step;

        // الزرّ يقود إلى الخطوة، والخطوة إلى ما كان يقود إليه الزرّ.
        $edge->id = 'vueflow__edge-' . $edge->source . ($edge->sourceHandle ?? '') . '-' . $stepId;
        $edge->target = $stepId;
        $edge->targetNode = $step;
        unset($edge->targetX, $edge->targetY);

        $metadata->edges[] = (object) [
            'id' => 'vueflow__edge-' . $stepId . '-' . $target->id,
            'type' => $edge->type ?? 'default',
            'source' => $stepId,
            'target' => (string) $target->id,
            'sourceHandle' => null,
            'targetHandle' => null,
            'data' => new \stdClass(),
            'events' => new \stdClass(),
            'label' => '',
            'sourceNode' => $step,
            'targetNode' => $target,
        ];
        $metadata->lastNodeId = (int) $stepId;

        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $valid = (new FlowValidator())->validateMessageNodes(json_decode($encoded, true));
        if ($valid !== true) {
            return $this->skip('the edited flow does not validate: ' . json_encode($valid, JSON_UNESCAPED_UNICODE));
        }

        return $encoded;
    }

    private function hasResubscribeStep(object $metadata): bool
    {
        $nodes = $metadata->nodes;
        foreach ($metadata->edges as $edge) {
            $nodes[] = $edge->sourceNode ?? null;
            $nodes[] = $edge->targetNode ?? null;
        }

        foreach ($nodes as $node) {
            if (str_replace('-', '_', (string) ($node->data->actionType ?? '')) === self::ACTION) {
                return true;
            }
        }

        return false;
    }

    private function leavesResubscribeButton(object $edge): bool
    {
        $source = $edge->sourceNode ?? null;
        $fields = $source->data->metadata->fields ?? null;
        if (($source->type ?? null) !== 'buttons' || !is_object($fields)) {
            return false;
        }

        foreach ((array) ($fields->buttons ?? []) as $key => $title) {
            if (trim((string) $title) === self::RESUBSCRIBE_BUTTON
                && (self::BUTTON_HANDLES[$key] ?? null) === ($edge->sourceHandle ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function node(object $metadata, string $id): ?object
    {
        foreach ($metadata->nodes as $node) {
            if ((string) ($node->id ?? '') === $id) {
                return $node;
            }
        }

        return null;
    }

    private function highestNodeId(object $metadata): int
    {
        $ids = array_map(fn ($node) => (int) ($node->id ?? 0), $metadata->nodes);

        return max([(int) ($metadata->lastNodeId ?? 0), ...$ids]);
    }

    private function skip(string $reason): ?string
    {
        Log::warning('Did not add the resubscribe step to the stop-offers flow: ' . $reason);

        return null;
    }

    /** مسار التاجر بعد الترحيل قد يتغيّر بيده؛ إزالة الخطوة تكون من المحرّر. */
    public function down(): void
    {
    }
};
