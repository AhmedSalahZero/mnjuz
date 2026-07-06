<?php

namespace App\Resolvers;

class PaymentPlatformResolver
{
    public function resolveService(string $paymentPlatform)
    {
        $configKey = $this->resolveConfigKey($paymentPlatform);

        if ($configKey !== null) {
            return resolve(config("services.{$configKey}.class"));
        }

        throw new \Exception(__('The selected payment method is not available. Please choose another method or contact support.'));
    }

    public function isSupported(?string $paymentPlatform): bool
    {
        if ($paymentPlatform === null || trim($paymentPlatform) === '') {
            return false;
        }

        return $this->resolveConfigKey($paymentPlatform) !== null;
    }

    /**
     * @param  array<int, array{name: string}>  $methods
     * @return array<int, array{name: string}>
     */
    public function filterSupportedMethods(array $methods): array
    {
        return array_values(array_filter(
            $methods,
            fn (array $method): bool => $this->isSupported($method['name'] ?? null)
        ));
    }

    private function resolveConfigKey(string $paymentPlatform): ?string
    {
        $normalized = strtolower(trim($paymentPlatform));

        $candidates = array_unique([
            str_replace('-', ' ', $normalized),
            str_replace(['-', '_'], ' ', $normalized),
            str_replace(['-', '_', ' '], '', $normalized),
        ]);

        $aliases = [
            'paystack' => 'paystack',
            'paypal' => 'paypal',
            'stripe' => 'stripe',
            'flutterwave' => 'flutterwave',
            'myfatoorah' => 'myfatoorah',
            'razorpay' => 'razorpay',
            'pabblysubscriptions' => 'pabbly subscriptions',
        ];

        foreach ($candidates as $candidate) {
            $candidate = $aliases[$candidate] ?? $candidate;

            if (config("services.{$candidate}.class")) {
                return $candidate;
            }
        }

        return null;
    }
}
