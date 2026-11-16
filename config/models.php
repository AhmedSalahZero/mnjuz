<?php

/*
 * موديلات OpenAI المتاحة لمساعد الذكاء الاصطناعي (Chat Completions).
 *
 * حُذف كل ما أوقفته OpenAI حسب صفحة الإيقافات الرسمية
 * (developers.openai.com/api/docs/deprecations، مراجعة 2026-11-14):
 * o1-mini و o1-preview و gpt-4 و gpt-4-turbo و gpt-3.5-turbo و
 * gpt-4o-2024-05-13 و chatgpt-4o-latest و gpt-4o-audio-preview وأخواتها.
 * موديل موقوف يُسكت المساعد على كل القنوات، والحفظ الآن يرفض ما ليس هنا.
 *
 * gpt-4o-mini-2024-07-18 و gpt-4o-2024-08-06 تتوقّف في 2027-01-14.
 * gpt-6-luna يدعم Chat Completions بلا تاريخ إيقاف، وهو موديل استدلال
 * (reasoning) فقد يتأخّر ردّه قليلاً.
 */
return [
    [ 'value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini' ],
    [ 'value' => 'gpt-4o', 'label' => 'gpt-4o' ],
    [ 'value' => 'gpt-6-luna', 'label' => 'gpt-6-luna' ],
    [ 'value' => 'gpt-4o-mini-2024-07-18', 'label' => 'gpt-4o-mini-2024-07-18' ],
    [ 'value' => 'gpt-4o-2024-08-06', 'label' => 'gpt-4o-2024-08-06' ],
];
