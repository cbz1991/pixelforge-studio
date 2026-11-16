<?php

namespace App\Pixelforge;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /** @param array<string, int> $usage the `usage` field of a generation */
    public static function fromArray(array $usage): self
    {
        return new self(
            (int) ($usage[TokenType::InputText->value] ?? 0),
            (int) ($usage[TokenType::InputImage->value] ?? 0),
            (int) ($usage[TokenType::Output->value] ?? 0),
            (int) ($usage[TokenType::Reasoning->value] ?? 0),
        );
    }

    public function of(TokenType $type): int
    {
        return match ($type) {
            TokenType::InputText => $this->inputText,
            TokenType::InputImage => $this->inputImage,
            TokenType::Output => $this->output,
            TokenType::Reasoning => $this->reasoning,
        };
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        $usage = [];

        foreach (TokenType::cases() as $type) {
            $usage[$type->value] = $this->of($type);
        }

        return $usage;
    }
}
