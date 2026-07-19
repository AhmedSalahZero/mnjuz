<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayResource extends JsonResource
{
    /**
     * Secret metadata fields per gateway that must never be sent to the browser.
     */
    public const SECRET_METADATA_FIELDS = [
        'paypal' => ['secret'],
        'stripe' => ['secret_key', 'webhook_secret'],
        'flutterwave' => ['secret_key'],
        'paystack' => ['secret_key'],
        'myfatoorah' => ['api_key', 'webhook_secret'],
    ];

    public static function secretFields(?string $name): array
    {
        return self::SECRET_METADATA_FIELDS[strtolower((string) $name)] ?? [];
    }

    /**
     * Return the gateway metadata as a client-safe JSON string: secret fields
     * are blanked and a companion "<field>_is_set" flag is added.
     */
    public static function redactMetadata(?string $name, $metadata): ?string
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (!is_array($decoded)) {
            return is_string($metadata) ? $metadata : null;
        }

        foreach (self::secretFields($name) as $field) {
            if (array_key_exists($field, $decoded)) {
                $decoded[$field . '_is_set'] = filled($decoded[$field]);
                $decoded[$field] = '';
            }
        }

        return json_encode($decoded);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = self::redactMetadata($this->name ?? null, $data['metadata']);
        }

        return $data;
    }
}
