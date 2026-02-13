<?php

namespace Local\Lib\DTO\Attributes\Validation;

use Attribute;
use Local\Lib\DTO\Validation\ValidationError;
use Local\Lib\DTO\BaseCollection;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Min implements ValidationRuleInterface
{
    public function __construct(
        public int|float $minValue,
        public ?string $message = null
    ) {}

    public function validate(mixed $value): ?ValidationError
    {
        // Null значения пропускаем. Для проверки на null DTO уже использует строгую типизацию PHP.
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && $value < $this->minValue) {
            $msg = $this->message ?? sprintf('Value must be at least %s.', $this->minValue);
            return new ValidationError($msg, 'VALIDATION_MIN');
        }

        if (is_string($value) && mb_strlen($value) < $this->minValue) {
            $msg = $this->message ?? sprintf('String length must be at least %s characters.', $this->minValue);
            return new ValidationError($msg, 'VALIDATION_MIN_LENGTH');
        }

        if (is_array($value) && count($value) < $this->minValue) {
            $msg = $this->message ?? sprintf('Array must contain at least %s items.', $this->minValue);
            return new ValidationError($msg, 'VALIDATION_MIN_ITEMS');
        }

        if ($value instanceof BaseCollection && count($value) < $this->minValue) {
            $msg = $this->message ?? sprintf('Collection must contain at least %s items.', $this->minValue);
            return new ValidationError($msg, 'VALIDATION_MIN_ITEMS');
        }

        return null;
    }
}