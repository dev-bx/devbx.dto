<?php

namespace DevBX\DTO\Attributes\Validation;

use Attribute;
use DevBX\DTO\Validation\ValidationError;
use DevBX\DTO\BaseCollection;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Max implements ValidationRuleInterface
{
    public function __construct(
        public int|float $maxValue,
        public ?string $message = null
    ) {}

    public function validate(mixed $value): ?ValidationError
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && $value > $this->maxValue) {
            $msg = $this->message ?? sprintf('Value must be at most %s.', $this->maxValue);
            return new ValidationError($msg, 'VALIDATION_MAX');
        }

        if (is_string($value) && mb_strlen($value) > $this->maxValue) {
            $msg = $this->message ?? sprintf('String length must be at most %s characters.', $this->maxValue);
            return new ValidationError($msg, 'VALIDATION_MAX_LENGTH');
        }

        if (is_array($value) && count($value) > $this->maxValue) {
            $msg = $this->message ?? sprintf('Array must contain at most %s items.', $this->maxValue);
            return new ValidationError($msg, 'VALIDATION_MAX_ITEMS');
        }

        if ($value instanceof BaseCollection && count($value) > $this->maxValue) {
            $msg = $this->message ?? sprintf('Collection must contain at most %s items.', $this->maxValue);
            return new ValidationError($msg, 'VALIDATION_MAX_ITEMS');
        }

        return null;
    }
}