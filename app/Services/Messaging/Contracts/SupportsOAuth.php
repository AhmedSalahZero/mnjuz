<?php

namespace App\Services\Messaging\Contracts;

/**
 * تُنفّذها القنوات التي تُربَط بتفويض OAuth لا بلصق توكن.
 *
 * واجهة مستقلّة عن ChannelDriver: تيليجرام تُربَط بتوكن من @BotFather ولا
 * OAuth فيها إطلاقاً، وإقحام دوالّ التفويض في العقد العامّ يُجبرها على
 * دالّتين فارغتين تُخفيان السؤال «هل لهذه القناة تفويض؟».
 */
interface SupportsOAuth
{
    /**
     * عنوان صفحة التفويض التي نُحوّل المستخدم إليها.
     *
     * @param  string  $state        الحالة الموقَّعة — تعود كما هي
     * @param  string  $redirectUri  عنوان الـ callback عندنا
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * تبديل الـ code بتوكن.
     *
     * يرمي ChannelConfigurationException برسالة صالحة للعرض عند الفشل —
     * فما يصل من المنصّة هنا يراه المستخدم مباشرةً.
     *
     * @return OAuthResult
     */
    public function exchangeCode(string $code, string $redirectUri): OAuthResult;
}
