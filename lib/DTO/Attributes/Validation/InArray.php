<?php

namespace Local\Lib\DTO\Attributes\Validation;

use Attribute;
use Local\Lib\DTO\Validation\ValidationError;

#[Attribute(Attribute::TARGET_PROPERTY)]
class InArray implements ValidationRuleInterface
{
    public function __construct(
        public array $allowedValues,
        public bool $strict = true,
        public ?string $message = null
    ) {}

    public function validate(mixed $value): ?ValidationError
    {
        if ($value === null) {
            return null;
        }

        if (!in_array($value, $this->allowedValues, $this->strict)) {
            $allowedStr = implode(', ', $this->allowedValues);
            $msg = $this->message ?? sprintf('Value must be one of: %s.', $allowedStr);
            return new ValidationError($msg, 'VALIDATION_IN_ARRAY');
        }

        return null;
    }
}