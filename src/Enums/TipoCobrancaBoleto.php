<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Enums;

/** Tipo de cobrança do boleto: tradicional ou híbrido (boleto + QR Code Pix). */
enum TipoCobrancaBoleto: string
{
    case Normal = 'NORMAL';
    case Hibrido = 'HIBRIDO';
}
