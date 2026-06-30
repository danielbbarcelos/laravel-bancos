<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Shared;

use InvalidArgumentException;

/**
 * Valor monetário canônico. Internamente guardamos centavos (int) para evitar
 * imprecisão de ponto flutuante; expomos a string "0.00" exigida pelo BACEN.
 */
final readonly class Valor
{
    private function __construct(
        public int $centavos,
    ) {
        if ($centavos < 0) {
            throw new InvalidArgumentException('Valor não pode ser negativo.');
        }
    }

    /** Ex.: Valor::reais('150.00') ou Valor::reais(150.0) */
    public static function reais(string|float|int $reais): self
    {
        $normalizado = is_string($reais) ? str_replace(',', '.', $reais) : (string) $reais;

        return new self((int) round(((float) $normalizado) * 100));
    }

    public static function centavos(int $centavos): self
    {
        return new self($centavos);
    }

    /** Formato exigido pelas APIs Pix: "150.00" (ponto, 2 casas). */
    public function paraApi(): string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }

    /** Valor numérico em reais — algumas APIs (ex.: boleto) esperam número, não string. */
    public function emReais(): float
    {
        return round($this->centavos / 100, 2);
    }

    public function __toString(): string
    {
        return $this->paraApi();
    }
}
