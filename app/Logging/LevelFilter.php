<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;

/**
 * قصر قناة تسجيل على مستويات بعينها.
 *
 * إعداد `level` في القناة يقبل المستوى **وما فوقه** — فقناة عند warning تبتلع
 * error و critical معها. ولفصل نوعٍ واحد في ملفّه لا يكفي ذلك: نحتاج قبولاً
 * بقائمة صريحة، وهو ما يفعله FilterHandler حين يُمرَّر مصفوفةَ مستويات.
 *
 * نلفّ المعالِجات القائمة ولا نستبدلها: التنسيق ومسار الملف يبقيان كما ضبطهما
 * سائق القناة، ونضيف إليهما شرط القبول وحده.
 */
abstract class LevelFilter
{
    /** @return array<int, Level> */
    abstract protected function accepted(): array;

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        $accepted = $this->accepted();

        $monolog->setHandlers(array_map(
            static fn (HandlerInterface $handler) => new FilterHandler($handler, $accepted),
            $monolog->getHandlers()
        ));
    }
}
