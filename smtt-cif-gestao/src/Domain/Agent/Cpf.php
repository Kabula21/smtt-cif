<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Domain\Agent;

use InvalidArgumentException;

final class Cpf
{
    private function __construct(private readonly string $digits)
    {
    }

    public static function fromInput(string $value): self
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            throw new InvalidArgumentException('CPF inválido.');
        }

        for ($digit = 9; $digit <= 10; ++$digit) {
            $sum = 0;
            $weight = $digit + 1;
            for ($i = 0; $i < $digit; ++$i) {
                $sum += ((int) $digits[$i]) * $weight--;
            }
            $check = (11 - ($sum % 11));
            $check = $check >= 10 ? 0 : $check;
            if ($check !== (int) $digits[$digit]) {
                throw new InvalidArgumentException('CPF inválido.');
            }
        }

        return new self($digits);
    }

    public function digits(): string
    {
        return $this->digits;
    }

    public function formatted(): string
    {
        return substr($this->digits, 0, 3) . '.' .
            substr($this->digits, 3, 3) . '.' .
            substr($this->digits, 6, 3) . '-' .
            substr($this->digits, 9, 2);
    }

    public function masked(): string
    {
        return '***.***.***-' . substr($this->digits, -2);
    }
}
