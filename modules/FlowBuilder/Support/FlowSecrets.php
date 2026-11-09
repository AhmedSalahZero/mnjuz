<?php

namespace Modules\FlowBuilder\Support;

/**
 * كلمة مرور SMTP في عقد «إرسال بريد» لا تغادر الخادم.
 *
 * كانت ميتاداتا المسار تُرسَل كاملةً إلى المتصفح (props صفحة المحرّر وقائمة
 * المسارات)، فيقرأ كلمة المرور أيّ مستخدم يفتح الصفحة من مصدرها. الآن تُحذف
 * قبل الإرسال ويحلّ محلّها علمٌ يقول إنها محفوظة، وعند الحفظ تُعاد من النسخة
 * المخزّنة لكل عقدة جاءت بلا كلمة مرور وما زال علمها قائماً.
 *
 * العقدة تتكرّر داخل الحواف (sourceNode/targetNode)، والمنفّذ يقرأ إعداد الإجراء
 * من targetNode، فيُعالَج الموضعان معاً.
 *
 * يُفكّ الـ JSON كائناتٍ لا مصفوفات: {} يبقى {} ولا يصير [] عند إعادة الترميز.
 */
final class FlowSecrets
{
    private const SECRET = 'smtp_password';
    private const FLAG = 'has_smtp_password';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function redact(?string $metadata): ?string
    {
        $graph = self::decode($metadata);

        if ($graph === null) {
            return $metadata;
        }

        $changed = false;

        self::eachEmailConfig($graph, function (object $config) use (&$changed) {
            if (!property_exists($config, self::SECRET)) {
                return;
            }

            if ((string) $config->{self::SECRET} !== '') {
                $config->{self::FLAG} = true;
            }

            unset($config->{self::SECRET});
            $changed = true;
        });

        return $changed ? json_encode($graph, self::JSON_FLAGS) : $metadata;
    }

    public static function restore(?string $incoming, ?string $stored): ?string
    {
        $graph = self::decode($incoming);

        if ($graph === null) {
            return $incoming;
        }

        $saved = self::storedPasswords($stored);
        $changed = false;

        self::eachEmailConfig($graph, function (object $config, string $nodeId) use ($saved, &$changed) {
            if (!property_exists($config, self::FLAG)) {
                return;
            }

            if ((string) ($config->{self::SECRET} ?? '') === '' && !empty($config->{self::FLAG}) && isset($saved[$nodeId])) {
                $config->{self::SECRET} = $saved[$nodeId];
            }

            unset($config->{self::FLAG});
            $changed = true;
        });

        return $changed ? json_encode($graph, self::JSON_FLAGS) : $incoming;
    }

    /** @return array<string, string> كلمة المرور المخزّنة لكل عقدة بريد، بمعرّفها. */
    private static function storedPasswords(?string $stored): array
    {
        $graph = self::decode($stored);
        $saved = [];

        if ($graph === null) {
            return $saved;
        }

        self::eachEmailConfig($graph, function (object $config, string $nodeId) use (&$saved) {
            $password = (string) ($config->{self::SECRET} ?? '');

            if ($password !== '' && !isset($saved[$nodeId])) {
                $saved[$nodeId] = $password;
            }
        });

        return $saved;
    }

    private static function decode(?string $metadata): ?object
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        $graph = json_decode($metadata);

        return is_object($graph) ? $graph : null;
    }

    /** العقد أولاً ثم نسخها داخل الحواف. */
    private static function eachEmailConfig(object $graph, callable $callback): void
    {
        $nodes = is_array($graph->nodes ?? null) ? $graph->nodes : [];

        foreach ($nodes as $node) {
            self::visit($node, $callback);
        }

        $edges = is_array($graph->edges ?? null) ? $graph->edges : [];

        foreach ($edges as $edge) {
            if (!is_object($edge)) {
                continue;
            }

            self::visit($edge->sourceNode ?? null, $callback);
            self::visit($edge->targetNode ?? null, $callback);
        }
    }

    private static function visit($node, callable $callback): void
    {
        if (!is_object($node) || ($node->type ?? null) !== 'action') {
            return;
        }

        $data = $node->data ?? null;

        if (!is_object($data) || str_replace('-', '_', (string) ($data->actionType ?? '')) !== 'send_email') {
            return;
        }

        if (!is_object($data->config ?? null)) {
            return;
        }

        $callback($data->config, (string) ($node->id ?? ''));
    }
}
