<?php

namespace Tests\Unit;

use App\Services\WebChat\WebChatAnswers;
use App\Services\WebChat\WebChatQuestions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * إجابات أسئلة البداية. الحالات في tests/Fixtures/webchat/answers.json
 * يمرّ بها الـ widget أيضاً (tests/js/webchat-intake.mjs): قاعدة واحدة في الطرفين.
 */
class WebChatAnswersTest extends TestCase
{
    /** @return array<string, array{0: array}> */
    public static function fixtureCases(): array
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../Fixtures/webchat/answers.json'), true);

        return array_combine(array_column($cases, 'why'), array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('fixtureCases')]
    public function test_the_shared_cases(array $case): void
    {
        [$ok, $value, $reason] = WebChatAnswers::check($case['question'], $case['input'], $case['country'] ?? 'SA');

        $this->assertSame($case['ok'], $ok, 'accepted');
        $this->assertSame($case['value'] ?? null, $value, 'stored value');
        $this->assertSame($case['reason'] ?? null, $reason, 'reason');
    }

    /** جوال في حقل مخصّص يُحفظ بصيغة العرض، وسؤال الجوال الأساسي دولياً. */
    public function test_a_custom_phone_field_is_stored_for_display(): void
    {
        $this->assertSame([true, '+966 50 123 4567', null], WebChatAnswers::check(
            ['target' => 'field', 'validation' => 'phone', 'required' => true], '0501234567', 'SA'
        ));
    }

    public function test_answers_are_keyed_by_question_and_errors_too(): void
    {
        $questions = WebChatQuestions::defaults();

        $result = WebChatAnswers::validate($questions, [
            'name' => 'سارة',
            'email' => 'not-an-email',
            'phone' => '0501234567',
            'unknown' => 'ignored',
        ], [], 'SA');

        $this->assertSame(['name' => 'سارة', 'phone' => '+966501234567'], $result['values']);
        $this->assertSame(['email'], array_keys($result['errors']));
        $this->assertSame('Enter a valid email address.', $result['errors']['email'][0]);
    }

    /** دولة سؤال الجوال من اختيار الزائر، وإلّا دولة الـ widget. */
    public function test_each_phone_question_uses_its_chosen_country(): void
    {
        $questions = [['id' => 'phone', 'target' => 'phone', 'validation' => 'phone', 'required' => true]];

        $egypt = WebChatAnswers::validate($questions, ['phone' => '01001234567'], ['phone' => 'EG'], 'SA');
        $this->assertSame('+201001234567', $egypt['values']['phone']);

        $default = WebChatAnswers::validate($questions, ['phone' => '0501234567'], [], 'SA');
        $this->assertSame('+966501234567', $default['values']['phone']);
    }

    /** إجابة ليست نصّاً (مصفوفة مثلاً) تُعامل فارغة لا تُحفظ. */
    public function test_a_non_text_answer_counts_as_empty(): void
    {
        $result = WebChatAnswers::validate(WebChatQuestions::defaults(), ['name' => ['x']], [], 'SA');

        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertArrayNotHasKey('name', $result['values']);
    }

    public function test_every_reason_has_a_message(): void
    {
        foreach (['required', 'too_long', 'email', 'number', 'date', 'select', 'saudi_id', 'phone_letters', 'phone_landline', 'phone_invalid'] as $reason) {
            $this->assertNotSame('', WebChatAnswers::message($reason), $reason);
        }
    }
}
