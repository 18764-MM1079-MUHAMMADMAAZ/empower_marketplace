<?php

namespace App\Support;

/**
 * Validation for card fields that are passed straight to a Livewire action as arguments (never
 * bound properties, so the card number is never serialized into the page). Same rules as the
 * portal checkout's cardRules().
 */
class CardRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $withName = true): array
    {
        return array_filter([
            'cardName' => $withName ? 'required|string|max:255|regex:/^[\p{L}\s.\'-]+$/u' : null,
            'cardNumber' => 'required|digits:16',
            'cardExpiry' => [
                'required',
                'string',
                function (string $attribute, $value, $fail) {
                    if (! preg_match('/^(\d{2})\/(\d{2})$/', (string) $value, $matches)) {
                        $fail('The card expiry field must be in MM/YY format.');

                        return;
                    }

                    [, $month, $year] = $matches;

                    if ($month < '01' || $month > '12') {
                        $fail('The card expiry month must be between 01 and 12.');

                        return;
                    }

                    $expiryYear = (int) ('20'.$year);
                    $expiryMonth = (int) $month;

                    if ($expiryYear < (int) date('Y') || ($expiryYear === (int) date('Y') && $expiryMonth < (int) date('n'))) {
                        $fail('The card has expired.');
                    }
                },
            ],
            'cardCvc' => 'required|digits_between:3,4',
        ]);
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['cardName.regex' => 'Please enter a valid name using letters only.'];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['cardName' => 'name on card', 'cardNumber' => 'card number', 'cardExpiry' => 'expiry date', 'cardCvc' => 'security code'];
    }
}
