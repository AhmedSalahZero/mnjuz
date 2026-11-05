<?php

namespace App\Services\ChatImport;

/**
 * ترتيب الرسالة بين نظيراتها المتطابقة داخل سلسلة متّصلة من الصفوف لنفس
 * الرقم في نفس الثانية. يبدأ العدّ من جديد مع كل سلسلة.
 */
final class RunTracker
{
    private ?string $runKey = null;

    /** @var array<string, int> */
    private array $counts = [];

    public function occurrence(ParsedRow $row): int
    {
        if ($row->runKey() !== $this->runKey) {
            $this->runKey = $row->runKey();
            $this->counts = [];
        }

        $content = $row->direction . '|' . sha1($row->text);
        $occurrence = $this->counts[$content] ?? 0;
        $this->counts[$content] = $occurrence + 1;

        return $occurrence;
    }
}
