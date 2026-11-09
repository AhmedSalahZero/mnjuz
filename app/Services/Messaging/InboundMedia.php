<?php

namespace App\Services\Messaging;

/**
 * إشارة إلى ملف وارد لم يُنزَّل بعد.
 *
 * كل منصّة تُعطي الملف بطريقة: واتساب تعطي id يُستبدل برابط عبر Graph،
 * وتيليجرام تعطي file_id يُمرّر على getFile، وميتا (Messenger/Instagram)
 * تعطي رابطاً مباشراً مؤقّتاً، وتيك توك تعطي media_id يحتاج نداءً خاصاً.
 *
 * لذلك نحفظ ما يكفي كل سائق ليُنزّل بنفسه، ولا نفترض شكلاً واحداً.
 */
class InboundMedia
{
    public function __construct(
        /** نوع الوسائط كما يفهمه النظام: image|video|audio|document|sticker */
        public readonly string $type,

        /** المعرّف لدى المنصّة (media id / file_id)، إن وُجد. */
        public readonly ?string $providerId = null,

        /** رابط مباشر إن أعطته المنصّة (ميتا تفعل). */
        public readonly ?string $url = null,

        public readonly ?string $mimeType = null,
        public readonly ?string $fileName = null,
        public readonly ?int $size = null,

        /** تعليق مرفق بالوسائط. */
        public readonly ?string $caption = null,

        /** رسالة صوتية لا ملف صوت (واتساب وتيليجرام يميّزان). */
        public readonly bool $isVoice = false,

        /** ما تحتاجه المنصّة عند التنزيل ولا يُعبّر عنه ما سبق. */
        public readonly array $extra = [],
    ) {
    }
}
