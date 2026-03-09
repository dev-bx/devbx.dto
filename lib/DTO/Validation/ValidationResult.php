<?php

namespace DevBX\DTO\Validation;

class ValidationResult
{
    /** @var ValidationError[] */
    protected array $errors = [];

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function addError(ValidationError $error): static
    {
        $this->errors[] = $error;
        return $this;
    }

    /**
     * @return ValidationError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}