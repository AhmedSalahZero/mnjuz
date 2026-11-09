<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * زائر المحادثة المباشرة: من ملأ النموذج في widget موقع التاجر.
 *
 * هويّته توكن عشوائي يحمله متصفّحه (localStorage) ولا نخزّن منه إلا
 * بصمته. uuid هو المعرّف العامّ، ويصير contacts.channel_identifier لجهة
 * الاتصال التي تُنشأ مع أول رسالة.
 */
class WebChatVisitor extends Model
{
    use SoftDeletes;

    protected $table = 'webchat_visitors';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /** طول التوكن بالبايت قبل الترميز: ٢٥٦ بت. */
    public const TOKEN_BYTES = 32;

    protected static function booted(): void
    {
        static::creating(function (self $visitor) {
            if (empty($visitor->uuid)) {
                $visitor->uuid = (string) Str::uuid();
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * ما يراه الموظّف عن الزائر (كارت «الزائر» في الـ inbox والجوال): من أين
     * جاء وبماذا يتصفّح. لا توكن ولا IP.
     */
    public function agentView(): array
    {
        return [
            'phone' => $this->phone,
            'email' => $this->email,
            'first_page_url' => $this->first_page_url,
            'last_page_url' => $this->last_page_url,
            'referrer' => $this->referrer,
            'country' => $this->country,
            'browser' => self::describeBrowser($this->user_agent),
            'locale' => $this->locale,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at ? \Illuminate\Support\Carbon::parse($this->last_seen_at)->toIso8601String() : null,
        ];
    }

    /** «Chrome · Android» من سلسلة المتصفّح — يكفي الموظّف، والخام لا يقرؤه أحد. */
    public static function describeBrowser(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $parts = array_filter([$browser, $os]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** توكن جديد للمتصفّح. يُعاد مرّة واحدة؛ المخزَّن بصمته فقط. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** الزائر صاحب التوكن على هذا الـ widget وحده. */
    public static function findByToken(ChannelAccount $account, string $token): ?self
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return static::query()
            ->where('channel_account_id', $account->id)
            ->where('token_hash', self::hashToken($token))
            ->first();
    }

    /**
     * قصّ الـ IP قبل حفظه: يكفي لكشف الإساءة من شبكة واحدة ولا يحفظ عنواناً
     * يُعرّف شخصاً بعينه.
     */
    public static function truncateIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            return $packed === false ? null : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
        }

        return null;
    }
}
