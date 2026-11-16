<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelAccountConnector;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\ChannelSettingsCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * ربط حسابات المنصّات وفصلها.
 *
 * واتساب لا تمرّ من هنا: لها صفحتها ومسارها و EmbeddedSignup، ولم نلمسها.
 * هذه الصفحة تعرضها للاطّلاع فقط وتوجّه إلى صفحتها للتعديل.
 */
class ChannelAccountController extends Controller
{
    private readonly ChannelSettingsCatalog $catalog;

    private readonly ChannelAccountConnector $connector;

    /**
     * التوقيع القديم (ChannelManager وحده) باقٍ: من يبني المتحكّم بيده — ومنهم
     * الاختبارات — يمرّر سائق القنوات فقط، والخدمتان تُبنيان عليه.
     */
    public function __construct(
        private readonly ChannelManager $channels,
        ?ChannelSettingsCatalog $catalog = null,
        ?ChannelAccountConnector $connector = null,
    ) {
        $this->catalog = $catalog ?? new ChannelSettingsCatalog($channels);
        $this->connector = $connector ?? new ChannelAccountConnector($channels);
    }

    /**
     * قائمة القنوات: بطاقة لكل منصّة (أيقونة، حالة، «إدارة») — بالشكل نفسه
     * الذي كانت به واتساب والمحادثة المباشرة. نموذج الربط انتقل إلى صفحة
     * القناة (show)؛ هنا ما يكفي لسطر الحالة فقط.
     */
    public function index(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        return Inertia::render('User/Settings/Channels', [
            'title' => 'Channels',
            'channels' => $this->catalog->cards($organizationId),
            'whatsappConnected' => $this->channels->whatsappPseudoAccount($organizationId) !== null,
            'webChat' => $this->catalog->webChatSummary($organizationId),
            'emailChannel' => $this->catalog->emailSummary($organizationId),
            // بدونها يختفي رابط «إعدادات الأتمتة» من الشريط الجانبي هنا.
            'modules' => Addon::get(),
        ]);
    }

    /**
     * صفحة إدارة قناة واحدة: الحساب المربوط، والربط أو إعادة الربط، وقيود
     * المنصّة. القناة مقيّدة في المسار بـ Channel::connectable().
     */
    public function show(Request $request, string $channel)
    {
        $organizationId = (int) session()->get('current_organization');

        $card = $this->catalog->card($organizationId, $channel);

        abort_if($card === null, 404);

        return Inertia::render('User/Settings/Channel', [
            'title' => $card['label'],
            'card' => $card,
            'webhookBaseUrl' => rtrim(config('app.url'), '/') . '/webhook/channel',
            'pendingSelection' => $this->pendingSelectionFor($organizationId, $channel),
            'modules' => Addon::get(),
        ]);
    }

    /**
     * ربط حساب. التحقّق لدى المنصّة والحفظ وتسجيل الـ webhook في
     * ChannelAccountConnector، والتطبيق يمرّ منها كذلك.
     */
    public function store(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'channel' => ['required', Rule::in(Channel::connectable())],
            'credentials' => ['required', 'array'],
        ]);

        $result = $this->connector->connect(
            $organizationId,
            $validated['channel'],
            $validated['credentials'],
            auth()->id()
        );

        return back()->with('status', $result->toFlash());
    }

    /** فصل حساب — حذف ناعم يُبقي محادثاته وسجلّه. */
    public function destroy(Request $request, string $uuid)
    {
        $organizationId = (int) session()->get('current_organization');

        return back()->with('status', $this->connector->disconnect($organizationId, $uuid)->toFlash());
    }

    /**
     * اختيار الحساب المعلّق بعد تفويض أعاد عدّة صفحات — لصفحة قناته فقط،
     * ولمنشأته فقط. لا نُخرج التوكنات: toChoiceList تُسقطها.
     */
    private function pendingSelectionFor(int $organizationId, string $channel): ?array
    {
        $pending = session()->get('channel_oauth_pending');

        if (!is_array($pending)
            || (int) ($pending['organization_id'] ?? 0) !== $organizationId
            || ($pending['channel'] ?? null) !== $channel) {
            return null;
        }

        return [
            'channel' => $pending['channel'],
            'accounts' => \App\Services\Messaging\Contracts\OAuthResult::choices($pending['accounts'])
                ->toChoiceList(),
        ];
    }
}
