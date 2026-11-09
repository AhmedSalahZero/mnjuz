<?php

namespace App\Events\Concerns;

use App\Services\Broadcasting\BroadcastProvider;

/**
 * الحدث يُبثّ عبر مزوّد منشأته (Pusher أو Reverb) لا مزوّد المنصّة.
 *
 * BroadcastEvent يسأل الحدث عن اتصالاته عند التنفيذ في العامل، فتصل أحداث
 * منشأتين بمزوّدين مختلفين كلٌّ إلى مستمعيه وإن نفّذها العامل نفسه متتالية.
 * دالّة لا خاصية: لا يتسرّب اسم الاتصال إلى حمولة الحدث.
 *
 * يشترط خاصية $organizationId في الحدث.
 */
trait BroadcastsOnOrganizationProvider
{
    /** @return array<int, string> */
    public function broadcastConnections(): array
    {
        return [BroadcastProvider::connectionNameForOrganization((int) $this->organizationId)];
    }
}
