<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;
use App\Models\ContactField;
use App\Models\Organization;
use App\Services\WebChat\WebChatSettings;
use Illuminate\Support\Str;

/**
 * منشئ أسئلة البداية في صفحة إعدادات المحادثة المباشرة: يحدّد التاجر ما
 * يُسأل، وفي أيّ حقل من جهة الاتصال تُحفظ الإجابة، وبأيّ تحقّق.
 */
class QuestionBuilderTest extends WebChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Addon::factory()->create(['name' => 'Google Authenticator']);
        $this->actAsOwner();
    }

    /** الصفحة تعرض ما يسأله الـ widget الآن (مشتقّاً هنا)، والافتراضي، والحقول. */
    public function test_the_page_offers_the_current_questions_and_the_contact_fields(): void
    {
        $city = $this->field('المدينة', 'select', 'الرياض, جدة');

        $widget = $this->inertiaProps('/settings/webchat')['widget'];

        $this->assertSame(['name', 'phone'], array_column($widget['questions'], 'id'), 'derived from the old form');
        $this->assertSame(['name', 'email', 'phone'], array_column($widget['default_questions'], 'id'));
        $this->assertSame([['uuid' => $city->uuid, 'name' => 'المدينة', 'type' => 'select', 'value' => 'الرياض, جدة']], $widget['contact_fields']);
    }

    public function test_questions_with_custom_fields_are_saved_and_asked(): void
    {
        $city = $this->field('المدينة', 'select', 'الرياض, جدة');
        $nationalId = $this->field('رقم الهوية', 'input', 'text');

        $this->save([
            ['target' => 'name', 'required' => true, 'label_ar' => 'اسمك الكريم؟', 'label_en' => 'Your name?'],
            ['target' => 'field', 'field' => $city->uuid, 'validation' => 'select', 'required' => true],
            ['target' => 'field', 'field' => $nationalId->uuid, 'validation' => 'saudi_id', 'required' => false],
            ['target' => 'phone', 'required' => false],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $prechat = WebChatSettings::for($this->widget->fresh())['prechat'];
        $this->assertSame(['name', 'f_' . $city->uuid, 'f_' . $nationalId->uuid, 'phone'], array_column($prechat['questions'], 'id'));
        $this->assertSame('اسمك الكريم؟', $prechat['questions'][0]['label_ar']);
        // مفاتيح النموذج القديم تتبع الأسئلة (app.js قديم في ذاكرة متصفّح).
        $this->assertSame(['optional', 'off'], [$prechat['phone'], $prechat['email']]);

        $asked = $this->getJson($this->url('config'))->json('widget.prechat.questions');
        $this->assertSame(['الرياض', 'جدة'], $asked[1]['options']);
        $this->assertSame('saudi_id', $asked[2]['validation']);
    }

    public function test_a_field_cannot_be_asked_twice(): void
    {
        $this->save([
            ['target' => 'name', 'required' => true],
            ['target' => 'name', 'required' => false],
        ])->assertSessionHasErrors('prechat.questions.1.target');
    }

    public function test_another_organizations_field_is_refused(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->organization->created_by]);
        $foreign = $this->field('حقل غيرنا', 'input', 'text', $other);

        $this->save([['target' => 'field', 'field' => $foreign->uuid, 'required' => true]])
            ->assertSessionHasErrors('prechat.questions.0.field');
    }

    public function test_a_checkbox_field_is_refused(): void
    {
        $agree = $this->field('موافق', 'checkbox', null);

        $this->save([['target' => 'field', 'field' => $agree->uuid, 'required' => true]])
            ->assertSessionHasErrors('prechat.questions.0.field');
    }

    public function test_at_most_twelve_questions(): void
    {
        $fields = array_map(fn ($i) => $this->field("حقل $i", 'input', 'text'), range(1, 13));

        $this->save(array_map(fn ($field) => ['target' => 'field', 'field' => $field->uuid, 'required' => false], $fields))
            ->assertSessionHasErrors('prechat.questions');
    }

    /** التاجر حرّ: بلا أسئلة يفتح الـ widget المحادثة مباشرة. */
    public function test_every_question_can_be_removed(): void
    {
        $this->save([])->assertSessionHasNoErrors();

        $this->assertSame([], WebChatSettings::for($this->widget->fresh())['prechat']['questions']);
        $this->assertSame([], $this->getJson($this->url('config'))->json('widget.prechat.questions'));
    }

    /** صفحة إعدادات فُتحت قبل منشئ الأسئلة تحفظ بدونه: لا تمحو الأسئلة. */
    public function test_a_page_without_the_builder_keeps_the_questions(): void
    {
        $this->save([['target' => 'email', 'required' => true]])->assertSessionHasNoErrors();

        $this->post('/settings/webchat', $this->form(null))->assertSessionHasNoErrors();

        $this->assertSame(['email'], array_column(WebChatSettings::for($this->widget->fresh())['prechat']['questions'], 'id'));
    }

    // ------------------------------------------------- أدوات

    private function save(array $questions)
    {
        return $this->from('/settings/webchat')->post('/settings/webchat', $this->form($questions));
    }

    private function form(?array $questions): array
    {
        $prechat = [
            'default_country' => 'SA', 'phone' => 'required', 'email' => 'off',
            'name_label' => '', 'phone_label' => '', 'consent_text' => '',
        ];

        if ($questions !== null) {
            $prechat['questions'] = $questions;
        }

        return [
            'enabled' => true,
            'allowed_domains' => [],
            'appearance' => [
                'color' => '#16a34a', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 20,
                'title' => '', 'subtitle' => '', 'welcome_message' => '', 'language' => 'ar',
            ],
            'prechat' => $prechat,
            'behavior' => ['show_agent_names' => true, 'sound' => true, 'uploads' => true],
        ];
    }

    private function field(string $name, string $type, ?string $value, ?Organization $organization = null): ContactField
    {
        // fresh(): HasUuid يترك الـ uuid كائناً على النموذج المنشأ للتوّ، والصفحة ترسله نصّاً.
        return ContactField::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'position' => ContactField::count() + 1,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'required' => 0,
        ])->fresh();
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'no Inertia payload on ' . $url);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }
}
