<?php

namespace DevBX\DTO\Schema\Model;

class ValidationRule
{
    public readonly string $rule;
    public readonly int|float|null $value;
    public readonly ?array $values;
    public readonly ?string $pattern;
    public readonly ?bool $strict;
    public readonly ?string $message;

    public function __construct(
        string $rule,
        int|float|null $value = null,
        ?array $values = null,
        ?string $pattern = null,
        ?bool $strict = null,
        ?string $message = null
    ) {
        $this->rule = $rule;
        $this->value = $value;
        $this->values = $values;
        $this->pattern = $pattern;
        $this->strict = $strict;
        $this->message = $message;
    }

    public function toArray(): array
    {
        $data = ['rule' => $this->rule];

        if ($this->value !== null) $data['value'] = $this->value;
        if ($this->values !== null) $data['values'] = $this->values;
        if ($this->pattern !== null) $data['pattern'] = $this->pattern;
        if ($this->strict !== null) $data['strict'] = $this->strict;
        if ($this->message !== null) $data['message'] = $this->message;

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            rule: $data['rule'],
            value: $data['value'] ?? null,
            values: $data['values'] ?? null,
            pattern: $data['pattern'] ?? null,
            strict: $data['strict'] ?? null,
            message: $data['message'] ?? null
        );
    }
}
