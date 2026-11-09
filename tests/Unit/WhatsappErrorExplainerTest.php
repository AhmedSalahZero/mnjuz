<?php

namespace Tests\Unit;

use App\Support\WhatsappErrorExplainer;
use Tests\TestCase;

/**
 * شرح أخطاء واتساب في الخادم (لتطبيق الجوال) — نظير whatsappErrors.js.
 */
class WhatsappErrorExplainerTest extends TestCase
{
    public function test_it_matches_the_web_list_exactly(): void
    {
        $js = file_get_contents(base_path('resources/js/Composables/whatsappErrors.js'));
        preg_match_all("/^\s+(\d{6}): '((?:[^'\\\\]|\\\\.)*)',$/m", $js, $matches, PREG_SET_ORDER);

        $web = [];
        foreach ($matches as [, $code, $text]) {
            $web[(int) $code] = stripslashes($text);
        }

        $this->assertNotEmpty($web);
        $this->assertSame($web, WhatsappErrorExplainer::EXPLANATIONS, 'the PHP and JS explanations drifted apart');
    }

    public function test_every_explanation_is_translated(): void
    {
        $ar = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach (WhatsappErrorExplainer::EXPLANATIONS as $code => $text) {
            $this->assertArrayHasKey($text, $ar, "code {$code} has no Arabic translation");
        }
    }

    public function test_status_log_and_graph_api_shapes(): void
    {
        $statusLog = json_encode(['status' => 'failed', 'errors' => [['code' => 131049, 'title' => 'x']]]);
        $this->assertSame(131049, WhatsappErrorExplainer::explain($statusLog)['code']);
        $this->assertSame(__(WhatsappErrorExplainer::EXPLANATIONS[131049]), WhatsappErrorExplainer::explain($statusLog)['reason']);

        $graph = ['data' => ['error' => ['code' => 100, 'error_user_title' => 'Bad', 'message' => 'Invalid parameter', 'error_data' => ['details' => 'Invalid parameter']]]];
        $explained = WhatsappErrorExplainer::explain($graph);
        $this->assertSame(100, $explained['code']);
        $this->assertSame('Bad — Invalid parameter', $explained['reason'], 'unknown codes keep WhatsApp\'s text, without repeats');

        $this->assertNull(WhatsappErrorExplainer::explain(null));
        $this->assertNull(WhatsappErrorExplainer::explain('not json'));
        $this->assertNull(WhatsappErrorExplainer::explain(['status' => 'sent']));
    }
}
