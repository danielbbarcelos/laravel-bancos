<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen;

use Illuminate\Http\Client\Response;

/**
 * Contrato da camada HTTP de um PSP que segue o padrão BACEN. O BacenPixGateway
 * depende apenas disto; cada banco fornece sua própria autenticação implementando
 * esta interface (normalmente estendendo ClienteHttpBacen).
 */
interface BacenConnector
{
    public function get(string $caminho, array $query = []): Response;

    public function post(string $caminho, array $corpo): Response;

    public function put(string $caminho, array $corpo): Response;

    public function patch(string $caminho, array $corpo): Response;

    public function delete(string $caminho): Response;
}
