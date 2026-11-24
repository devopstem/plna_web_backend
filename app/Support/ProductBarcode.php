<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Product barcodes: digits only, no minimum length, max GTIN-14.
 */
final class ProductBarcode
{
    public const MIN_LENGTH = 1;

    public const MAX_LENGTH = 14;

    public static function normalize(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
    }

    public static function isValid(string $barcode): bool
    {
        $length = strlen($barcode);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && ctype_digit($barcode);
    }

    /**
     * @return list<string>
     */
    public static function rules(bool $required = true): array
    {
        $rules = $required ? ['required'] : ['sometimes'];

        $rules[] = 'string';
        $rules[] = 'min:'.self::MIN_LENGTH;
        $rules[] = 'max:'.self::MAX_LENGTH;
        $rules[] = 'regex:/^\d{'.self::MIN_LENGTH.','.self::MAX_LENGTH.'}$/';

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'barcode.required' => 'Штрихкод обязателен.',
            'barcode.min' => 'Укажите штрихкод (только цифры, до '.self::MAX_LENGTH.').',
            'barcode.max' => 'Штрихкод не должен быть длиннее '.self::MAX_LENGTH.' цифр.',
            'barcode.regex' => 'Штрихкод: только цифры, максимум '.self::MAX_LENGTH.' символов.',
        ];
    }
}
