<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Municipios;
use PHPUnit\Framework\TestCase;

final class MunicipiosTest extends TestCase
{
    public function testNomeGoiania(): void
    {
        $this->assertSame('Goiânia', Municipios::nome('5208707'));
    }

    public function testNomeBeloHorizonte(): void
    {
        $this->assertSame('Belo Horizonte', Municipios::nome('3106200'));
    }

    public function testCidadeEstadoBeloHorizonte(): void
    {
        $this->assertSame('Belo Horizonte - Minas Gerais', Municipios::cidadeEstado('3106200'));
    }

    public function testCodigoDesconhecidoDevolveOProprioCodigo(): void
    {
        $this->assertSame('0000000', Municipios::nome('0000000'));
        $this->assertSame('0000000', Municipios::cidadeEstado('0000000'));
    }
}
