<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Enums;

/** Como um encargo (juros/multa/desconto) de boleto é expresso. */
enum TipoValor: string
{
    case Valor = 'VALOR';
    case Percentual = 'PERCENTUAL';
}
