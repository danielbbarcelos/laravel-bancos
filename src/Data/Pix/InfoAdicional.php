<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/** Par nome/valor exibido ao pagador no app do banco (infoAdicionais do BACEN). */
final readonly class InfoAdicional
{
    public function __construct(
        public string $nome,
        public string $valor,
    ) {
    }
}
