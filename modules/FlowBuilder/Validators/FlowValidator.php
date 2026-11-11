<?php

namespace Modules\FlowBuilder\Validators;

use Modules\FlowBuilder\Support\KeywordMatcher;

/**
 * ما يمنع نشر المسار أو حفظ تعديلٍ على مسارٍ منشور.
 *
 * كانت الأخطاء تُجمع بمفتاحٍ لكل نوع عقدة، فيمحو خطأُ العقدة الأخيرة ما قبله،
 * ولا يقول أيّها المقصودة، ويصل المحرّرَ نصّاً إنجليزياً خاماً. الآن قائمةٌ
 * بكل خطأ مع معرّف عقدته ونوعها ورسالةٍ مترجمة.
 *
 * والحدود حدود واتساب: ما يتجاوزها كان يُنشر ثم يُقصّ أو يُرفض عند الإرسال.
 */
class FlowValidator
{
    /** @var array<int, array{node_id: ?string, node_type: string, message: string}> */
    private array $errors = [];

    /**
     * @return true|array<int, array{node_id: ?string, node_type: string, message: string}>
     */
    public function validateMessageNodes(array $graph)
    {
        $this->errors = [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        $hasStart = false;
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                $hasStart = true;
                break;
            }
        }

        if (!$hasStart) {
            $this->fail(null, 'start', __('The flow has no start step.'));
        }

        foreach ($nodes as $node) {
            $id = isset($node['id']) ? (string) $node['id'] : null;
            $type = (string) ($node['type'] ?? '');
            $fields = $node['data']['metadata']['fields'] ?? [];
            $fields = is_array($fields) ? $fields : [];

            switch ($type) {
                case 'start':
                    $this->validateStart($id, $fields);
                    break;

                case 'text':
                    if (trim((string) ($fields['body'] ?? '')) === '') {
                        $this->fail($id, $type, __('The message text is required.'));
                    }
                    break;

                case 'media':
                    $this->validateMedia($id, $fields);
                    break;

                case 'buttons':
                    $this->validateButtons($id, $fields);
                    break;

                case 'list':
                    $this->validateList($id, $fields);
                    break;

                case 'action':
                    $this->validateAction($id, is_array($node['data'] ?? null) ? $node['data'] : []);
                    break;

                default:
                    $this->fail($id, $type, __('Unknown step type: :type', ['type' => $type]));
                    break;
            }
        }

        return $this->errors === [] ? true : $this->errors;
    }

    private function fail(?string $nodeId, string $nodeType, string $message): void
    {
        $this->errors[] = ['node_id' => $nodeId, 'node_type' => $nodeType, 'message' => $message];
    }

    /** مسارٌ بلا محفّز كان يُنشر ولا يعمل أبداً. */
    private function validateStart(?string $id, array $fields): void
    {
        $trigger = $fields['type'] ?? null;

        if (empty($trigger)) {
            $this->fail($id, 'start', __('Choose what starts the flow.'));
            return;
        }

        if ($trigger === 'keywords' && KeywordMatcher::split($fields['keywords'] ?? null) === []) {
            $this->fail($id, 'start', __('Add at least one keyword.'));
        }

        // مسارٌ بلا قنوات لا يبدأ في أيّ مكان. وغياب المفتاح (مسار حُفظ قبل
        // اختيار القنوات) يُبقي ما في العمود.
        if (array_key_exists('channels', $fields)
            && \App\Services\Messaging\AutomationChannels::normalize($fields['channels']) === []) {
            $this->fail($id, 'start', __('Choose at least one channel.'));
        }
    }

    private function validateMedia(?string $id, array $fields): void
    {
        if (empty($fields['media']) || empty($fields['media']['path'])) {
            $this->fail($id, 'media', __('Upload the media file.'));
        }

        if (empty($fields['mediaType'])) {
            $this->fail($id, 'media', __('Choose the media type.'));
            return;
        }

        $mimeType = $this->mimeOf($fields['media'] ?? null);
        if ($mimeType !== null && ($message = $this->mediaFormatError($mimeType, $fields['mediaType'])) !== null) {
            $this->fail($id, 'media', $message);
        }

        if (mb_strlen((string) ($fields['caption'] ?? '')) > 1024) {
            $this->fail($id, 'media', __('The caption cannot be longer than :max characters.', ['max' => 1024]));
        }
    }

    private function validateButtons(?string $id, array $fields): void
    {
        $this->validateHeader($id, 'buttons', $fields);
        $this->validateBody($id, 'buttons', $fields);

        $buttonType = $fields['buttonType'] ?? '';

        if ($buttonType === 'buttons') {
            $titles = array_values(array_filter(
                array_map(fn ($title) => trim((string) $title), [
                    $fields['buttons']['button1'] ?? '',
                    $fields['buttons']['button2'] ?? '',
                    $fields['buttons']['button3'] ?? '',
                ]),
                fn ($title) => $title !== ''
            ));

            if ($titles === []) {
                $this->fail($id, 'buttons', __('Add at least one button.'));
            }

            foreach ($titles as $title) {
                if (mb_strlen($title) > 20) {
                    $this->fail($id, 'buttons', __('The button ":title" is longer than :max characters.', ['title' => $title, 'max' => 20]));
                }
            }

            $this->failOnDuplicates($id, 'buttons', $titles);
        }

        if ($buttonType === 'cta_url') {
            $text = trim((string) ($fields['ctaUrlButton']['displayText'] ?? ''));
            $url = trim((string) ($fields['ctaUrlButton']['url'] ?? ''));

            if ($text === '' || $url === '') {
                $this->fail($id, 'buttons', __('The link button needs both a text and a URL.'));
            } elseif (!preg_match('/^(https?:\/\/)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?(\/\S*)?$/', $url)) {
                $this->fail($id, 'buttons', __('The link button URL is not valid.'));
            }

            if (mb_strlen($text) > 20) {
                $this->fail($id, 'buttons', __('The button ":title" is longer than :max characters.', ['title' => $text, 'max' => 20]));
            }
        }
    }

    private function validateList(?string $id, array $fields): void
    {
        $this->validateHeader($id, 'list', $fields);
        $this->validateBody($id, 'list', $fields);

        $label = trim((string) ($fields['buttonLabel'] ?? ''));
        if ($label === '') {
            $this->fail($id, 'list', __('The list button label is required.'));
        } elseif (mb_strlen($label) > 20) {
            $this->fail($id, 'list', __('The list button label cannot be longer than :max characters.', ['max' => 20]));
        }

        $sections = $fields['sections'] ?? null;
        if (empty($sections) || !is_array($sections)) {
            $this->fail($id, 'list', __('Add at least one section to the list.'));
            return;
        }

        $titles = [];
        $rowCount = 0;

        foreach ($sections as $section) {
            $sectionTitle = trim((string) ($section['title'] ?? ''));

            if ($sectionTitle === '') {
                $this->fail($id, 'list', __('Every list section needs a title.'));
            } elseif (mb_strlen($sectionTitle) > 24) {
                $this->fail($id, 'list', __('The section ":title" is longer than :max characters.', ['title' => $sectionTitle, 'max' => 24]));
            }

            $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
            $hasRow = false;

            foreach ($rows as $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $rowCount++;
                $titles[] = $title;

                if (!empty($row['id'])) {
                    $hasRow = true;
                }

                if (mb_strlen($title) > 24) {
                    $this->fail($id, 'list', __('The row ":title" is longer than :max characters.', ['title' => $title, 'max' => 24]));
                }

                if (mb_strlen(trim((string) ($row['description'] ?? ''))) > 72) {
                    $this->fail($id, 'list', __('The description of ":title" is longer than :max characters.', ['title' => $title, 'max' => 72]));
                }
            }

            if (!$hasRow) {
                $this->fail($id, 'list', __('Every list section needs at least one row with a title and an ID.'));
            }
        }

        // واتساب يقبل عشرة صفوف في القائمة كلها، والزائد كان يُحذف عند الإرسال بصمت.
        if ($rowCount > 10) {
            $this->fail($id, 'list', __('A list can have at most :max rows in total.', ['max' => 10]));
        }

        $this->failOnDuplicates($id, 'list', $titles);
    }

    private function validateHeader(?string $id, string $nodeType, array $fields): void
    {
        $headerType = $fields['headerType'] ?? 'none';

        switch ($headerType) {
            case 'text':
                if (trim((string) ($fields['headerText'] ?? '')) === '') {
                    $this->fail($id, $nodeType, __('The header text is required.'));
                }
                break;

            // واتساب لا يقبل الصوت ترويسةً للرسائل التفاعلية؛ كان يُنشر ثم يُرفض.
            case 'audio':
                $this->fail($id, $nodeType, __('WhatsApp does not allow an audio header here. Use an image, video or document.'));
                break;

            case 'image':
            case 'video':
            case 'document':
                if (empty($fields['headerMedia'])) {
                    $this->fail($id, $nodeType, __('Upload the header file.'));
                    break;
                }

                $mimeType = $this->mimeOf($fields['headerMedia']);
                if ($mimeType !== null && ($message = $this->mediaFormatError($mimeType, $headerType)) !== null) {
                    $this->fail($id, $nodeType, $message);
                }
                break;
        }
    }

    private function validateBody(?string $id, string $nodeType, array $fields): void
    {
        $body = trim((string) ($fields['body'] ?? ''));

        if ($body === '') {
            $this->fail($id, $nodeType, __('The message text is required.'));
        } elseif (mb_strlen($body) > 1024) {
            $this->fail($id, $nodeType, __('The message text cannot be longer than :max characters.', ['max' => 1024]));
        }
    }

    /**
     * عنوانان متطابقان: الردّ يطابق الأوّل دائماً فيصير فرع الثاني غير قابل للوصول.
     *
     * @param string[] $titles
     */
    private function failOnDuplicates(?string $id, string $nodeType, array $titles): void
    {
        $seen = [];

        foreach ($titles as $title) {
            $key = mb_strtolower($title);

            if (isset($seen[$key])) {
                $this->fail($id, $nodeType, __('Two options have the same title ":title". Each option needs its own title.', ['title' => $title]));
                continue;
            }

            $seen[$key] = true;
        }
    }

    private function validateAction(?string $id, array $data): void
    {
        $actionType = $data['actionType'] ?? '';
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $isActive = $data['is_active'] ?? true;

        // If action is not active, skip validation
        if (!$isActive) {
            return;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);
        $fail = fn (string $message) => $this->fail($id, 'action', $message);

        // Validate based on action type
        switch ($actionType) {
            case 'add_to_group':
                if (empty($config['group_id'])) {
                    $fail(__('Choose the group to add the contact to.'));
                }
                break;

            // remove_from_group لم يعد يستقبل مجموعة: صار ينزع جهة الاتصال من
            // كل مجموعات المنشأة ويعلّمها منسحبةً من التسويق. اشتراط مجموعة
            // كان يجعل كل عقدة تخدم مجموعة واحدة — مئة مجموعة، مئة عقدة.
            case 'remove_from_group':
                break;

            case 'update_contact':
                if (empty($config['target_field'])) {
                    $fail(__('Choose the contact field to update.'));
                }
                break;

            case 'send_email':
                foreach ([
                    'subject' => __('The email subject is required.'),
                    'body' => __('The email body is required.'),
                    'smtp_host' => __('The SMTP host is required.'),
                    'smtp_username' => __('The SMTP username is required.'),
                    'smtp_password' => __('The SMTP password is required.'),
                ] as $key => $message) {
                    if (empty($config[$key])) {
                        $fail($message);
                    }
                }
                break;

            case 'delay':
                if (!isset($config['duration']) || (float) $config['duration'] <= 0) {
                    $fail(__('The delay must be longer than zero minutes.'));
                }
                break;

            case 'webhook':
                if (empty($config['url'])) {
                    $fail(__('The webhook URL is required.'));
                }
                break;

            case 'ai_response':
                if (empty($config['prompt'])) {
                    $fail(__('The AI prompt is required.'));
                }
                break;

            // معطّل في config/actions.php، لكن المسارات القديمة تحمله: ?? يمنع
            // خطأ المفتاح الغائب الذي كان يُسقط الحفظ كلّه بخطأ 500.
            case 'conditional':
                if (empty($config['condition_type'])) {
                    $fail(__('Choose the condition type.'));
                }
                if (empty($config['conditions']) || !is_array($config['conditions'])) {
                    $fail(__('Add at least one condition.'));
                }
                if (($config['condition_type'] ?? null) === 'contact_field' && empty($config['field_name'])) {
                    $fail(__('Choose the contact field for the condition.'));
                }
                break;

            default:
                $fail(empty($actionType)
                    ? __('Choose the action type.')
                    : __('Unknown action type: :type', ['type' => $actionType]));
                break;
        }
    }

    private function mimeOf($media): ?string
    {
        if (!is_array($media) || !isset($media['metadata'])) {
            return null;
        }

        $metadata = is_string($media['metadata']) ? json_decode($media['metadata'], true) : $media['metadata'];

        return is_array($metadata) ? (string) ($metadata['extension'] ?? '') : null;
    }

    /**
     * Validate the format of the media type based on its MIME type.
     */
    private function mediaFormatError(string $mimeType, string $mediaType): ?string
    {
        $validFormats = [
            'image' => ['image/jpeg', 'image/png'],
            'audio' => ['audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/amr', 'audio/mp4', 'audio/ogg'],
            'document' => [
                'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'video' => ['video/mp4', 'video/3gpp'],
        ];

        $messages = [
            'image' => __('Invalid image format. Allowed types: JPEG, PNG.'),
            'audio' => __('Invalid audio format. Allowed types: MP3, AAC, AMR, MP4, OGG.'),
            'document' => __('Invalid document format. Allowed types: PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX.'),
            'video' => __('Invalid video format. Allowed types: MP4, 3GPP.'),
        ];

        if (isset($validFormats[$mediaType]) && !in_array($mimeType, $validFormats[$mediaType], true)) {
            return $messages[$mediaType];
        }

        return null;
    }
}
