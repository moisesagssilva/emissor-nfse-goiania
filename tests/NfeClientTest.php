<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Config;
use EmissorGyn\NfeClient;
use EmissorGyn\NfeXmlFactory;
use PHPUnit\Framework\TestCase;

final class NfeClientTest extends TestCase
{
    private string $tmpDir;
    private string $certPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lumina_nfeclient_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        $this->certPath = $this->tmpDir . '/test.pfx';
        $this->generateSelfSignedPfx($this->certPath, 'test-pass');

        file_put_contents($this->tmpDir . '/.env', implode("\n", [
            'PRESTADOR_CNPJ=11222333000181',
            'PRESTADOR_RAZAO_SOCIAL=Empresa Teste LTDA',
            'NFE_AMBIENTE=2',
            'CERT_PATH=' . $this->certPath,
            'CERT_PASS=test-pass',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/.env');
        @unlink($this->certPath);
        @rmdir($this->tmpDir);
    }

    private function generateSelfSignedPfx(string $path, string $password): void
    {
        $privKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['commonName' => 'Empresa Teste LTDA'], $privKey);
        $cert = openssl_csr_sign($csr, null, $privKey, 365);
        openssl_pkcs12_export_to_file($cert, $path, $privKey, $password);
    }

    public function testBuildToolsConfigJsonPassesSefazSchemaValidation(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);
        $client  = new NfeClient($config, $factory);

        $method = new \ReflectionMethod(NfeClient::class, 'buildTools');

        $tools = $method->invoke($client);

        $this->assertInstanceOf(\NFePHP\NFe\Tools::class, $tools);
    }
}
