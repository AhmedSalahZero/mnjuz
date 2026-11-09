<?php

namespace App\Services\ChatImport;

use RuntimeException;

/**
 * خطأ على مستوى الملف كلّه: ترميز، أعمدة، حجم. الرسالة مترجمة وجاهزة للعرض.
 */
class ChatImportException extends RuntimeException
{
}
