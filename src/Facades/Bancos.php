<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Facades;

use DanielBBarcelos\Bancos\BancoManager;
use DanielBBarcelos\Bancos\Contracts\Banco;
use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Contracts\PixGateway;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Banco         banco(?string $nome = null)
 * @method static Banco         driver(?string $nome = null)
 * @method static Banco         build(string $driver, array $config = [])
 * @method static PixGateway    pix()
 * @method static BoletoGateway boleto()
 * @method static BancoManager  extend(string $driver, \Closure $factory)
 * @method static string        bancoPadrao()
 *
 * @see \DanielBBarcelos\Bancos\BancoManager
 */
class Bancos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BancoManager::class;
    }
}
