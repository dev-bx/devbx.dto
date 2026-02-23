<?php

namespace Local\Lib\DTO\Attributes\Validation;

use Attribute;
use Local\Lib\DTO\Validation\ValidationError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Regex implements ValidationRuleInterface
{
    public function __construct(
        public string $pattern,
        public ?string $message = null
    ) {}

    public function validate(mixed $value): ?ValidationError
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return new ValidationError('Value must be a string or numeric for regex validation.', 'VALIDATION_REGEX_TYPE');
        }

        if (preg_match($this->pattern, (string)$value) !== 1) {
            $msg = $this->message ?? 'Value does not match the required pattern.';
            return new ValidationError($msg, 'VALIDATION_REGEX');
        }

        return null;
    }
}