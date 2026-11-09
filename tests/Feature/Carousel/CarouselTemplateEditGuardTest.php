<?php

namespace Tests\Feature\Carousel;

/**
 * محرّر القوالب لا يعرف البطاقات: حفظ قالب carousel منه كان سيرسله إلى
 * Meta بلا مكوّن CAROUSEL. فالتعديل مغلق لهذا النوع وحده.
 */
class CarouselTemplateEditGuardTest extends CarouselTestCase
{
    private function asOwner()
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization' => $this->organization->id]);
    }

    public function test_opening_the_editor_for_a_carousel_redirects_back(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->asOwner()
            ->get('/templates/' . $template->uuid)
            ->assertRedirect('/templates')
            ->assertSessionHas('status.type', 'error');
    }

    public function test_saving_a_carousel_from_the_editor_never_reaches_meta(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->asOwner()
            ->post('/templates/' . $template->uuid, [
                'name' => 'shoes',
                'category' => 'MARKETING',
                'language' => 'ar',
                'body' => ['text' => 'changed'],
            ], ['Accept' => 'application/json'])
            ->assertJsonPath('success', false);

        $this->assertSame([], $this->graphRequests);
        $this->assertSame($this->carouselPayload(), json_decode($template->fresh()->metadata, true));
    }

    public function test_a_standard_template_still_opens_the_editor(): void
    {
        $template = $this->makeStandardTemplate();

        $response = $this->asOwner()->get('/templates/' . $template->uuid);

        $response->assertOk();
        $this->assertStringContainsString('User\/Templates\/Edit', $response->getContent());
    }

    /** واجهة القائمة تقرأ القالب كاملاً بالـ JSON، ومعه نسخنا من الوسائط. */
    public function test_the_template_details_include_our_media_copies(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->asOwner()
            ->getJson('/templates/' . $template->uuid)
            ->assertOk()
            ->assertJsonPath('uuid', (string) $template->uuid)
            ->assertJsonPath('carousel_media', $template->carousel_media);
    }
}
