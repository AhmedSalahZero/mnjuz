<?php

namespace App\Logging;

use Monolog\Level;

/** تحذيرات فقط — لا error ولا critical معها في ملفّها. */
final class WarningsOnly extends LevelFilter
{
    protected function accepted(): array
    {
        return [Level::Warning];
    }
}
