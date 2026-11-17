<?php

namespace App\Services\Media;

/** تعذّر تحويل صورة إلى JPG: صيغة غير مدعومة، أو ملف تالف، أو أبعاد أكبر من الحدّ. */
class ImageConversionFailed extends \RuntimeException
{
}
