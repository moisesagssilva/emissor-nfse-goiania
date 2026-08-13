<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage(':memory:');
    }

    public function testBuscarEmissaoInexistenteDevolveNull(): void
    {
        $this->assertNull($this->storage->buscarEmissao(999));
    }

    public function testBuscarEmissaoDevolveXmlRetorno(): void
    {
        $id = $this->storage->registrarEnvio(1, '1', '100.00', '11222333000181', 'Cliente Exemplo', '<xml/>');
        $this->storage->registrarSucesso($id, '1', 'ABC123', '<InfNfse>...</InfNfse>');

        $emissao = $this->storage->buscarEmissao($id);

        $this->assertNotNull($emissao);
        $this->assertSame('<InfNfse>...</InfNfse>', $emissao['xml_retorno']);
        $this->assertSame('autorizada', $emissao['status']);
    }
}
