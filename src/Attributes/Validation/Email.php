<?php

namespace DevBX\DTO\Attributes\Validation;

use Attribute;
use DevBX\DTO\Validation\ValidationError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Email implements ValidationRuleInterface
{
    public function __construct(
        public ?string $message = null
    ) {}

    public function validate(mixed $value): ?ValidationError
    {
        // Пустые значения игнорируем (для обязательности есть сама типизация PHP и null)
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $msg = $this->message ?? 'Invalid email format.';
            return new ValidationError($msg, 'VALIDATION_EMAIL');
        }

        return null;
    }
}