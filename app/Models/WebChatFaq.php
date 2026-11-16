<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سؤال شائع في المحادثة المباشرة (WebChatFaqs). نصّ عربي، وإنجليزي اختياري،
 * وإجابة نصّاً أو صورةً أو كليهما.
 */
class WebChatFaq extends Model
{
    protected $table = 'webchat_faqs';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];
}
