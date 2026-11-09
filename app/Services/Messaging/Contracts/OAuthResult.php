<?php

namespace App\Services\Messaging\Contracts;

/**
 * نتيجة تبديل الـ code.
 *
 * حالتان:
 *   ١. **حساب واحد جاهز** — تيك توك: التفويض يخصّ حساب أعمال بعينه،
 *      فنحفظه فوراً.
 *   ٢. **عدّة حسابات للاختيار** — ميتا: المستخدم قد يُدير عشر صفحات،
 *      والتفويض يُعطينا توكناً لكلّها. اختيار واحدة نيابةً عنه يربط
 *      الصفحة الخطأ بصمت.
 */
class OAuthResult
{
    /**
     * @param  array<int, array{external_id: string, name: ?string, username: ?string, avatar: ?string, credentials: array}>  $accounts
     */
    private function __construct(
        public readonly array $accounts,
        /** بيانات اعتماد عامّة لا تخصّ حساباً بعينه (توكن المستخدم مثلاً). */
        public readonly array $context = [],
    ) {
    }

    /** حساب واحد جاهز للحفظ. */
    public static function single(
        string $externalId,
        array $credentials,
        ?string $name = null,
        ?string $username = null,
        ?string $avatar = null
    ): self {
        return new self([[
            'external_id' => $externalId,
            'name' => $name,
            'username' => $username,
            'avatar' => $avatar,
            'credentials' => $credentials,
        ]]);
    }

    /** عدّة حسابات — المستخدم يختار. */
    public static function choices(array $accounts, array $context = []): self
    {
        return new self(array_values($accounts), $context);
    }

    public function isEmpty(): bool
    {
        return $this->accounts === [];
    }

    public function needsSelection(): bool
    {
        return count($this->accounts) > 1;
    }

    public function first(): ?array
    {
        return $this->accounts[0] ?? null;
    }

    /** الحساب بمعرّفه، أو null إن لم يكن ضمن ما فوّضه المستخدم. */
    public function find(string $externalId): ?array
    {
        foreach ($this->accounts as $account) {
            if ((string) $account['external_id'] === $externalId) {
                return $account;
            }
        }

        return null;
    }

    /** ما يُعرَض للمستخدم ليختار — بلا أي توكن. */
    public function toChoiceList(): array
    {
        return array_map(static fn ($account) => [
            'external_id' => $account['external_id'],
            'name' => $account['name'],
            'username' => $account['username'],
            'avatar' => $account['avatar'],
        ], $this->accounts);
    }
}
