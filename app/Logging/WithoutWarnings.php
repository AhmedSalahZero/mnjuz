<?php

namespace App\Logging;

use Monolog\Level;

/**
 * كل شيء عدا التحذيرات.
 *
 * مكمّلة لـ{@see WarningsOnly}: بلا هذه يُكتب التحذير في الملفّين معاً، فلا
 * يكون فصلاً بل تكراراً.
 */
final class WithoutWarnings extends LevelFilter
{
    protected function accepted(): array
    {
        return array_values(array_filter(
            Level::cases(),
            static fn (Level $level) => $level !== Level::Warning
        ));
    }
}
