<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Config;
use EmissorGyn\NfeXmlFactory;
use PHPUnit\Framework\TestCase;

final class NfeXmlFactoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lumina_nfe_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/.env', implode("\n", [
            'PRESTADOR_CNPJ=11222333000181',
            'PRESTADOR_RAZAO_SOCIAL=Empresa Teste LTDA',
            'PRESTADOR_IE=1234567890123',
            'PRESTADOR_LOGRADOURO=Rua A',
            'PRESTADOR_NUMERO=100',
            'PRESTADOR_BAIRRO=Centro',
            'PRESTADOR_CODIGO_MUNICIPIO=5208707',
            'PRESTADOR_MUNICIPIO=Goiania',
            'PRESTADOR_UF=GO',
            'PRESTADOR_CEP=74000000',
            'NFE_AMBIENTE=2',
            'NFE_SERIE=1',
            'CERT_PATH=/dev/null',
            'CERT_PASS=senha',
            'DB_PATH=:memory:',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/.env');
        @rmdir($this->tmpDir);
    }

    public function testBuildRetornaXmlNfe(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = [
            'natureza_operacao'     => 'Venda de mercadoria',
            'consumidor_final'      => 0,
            'presenca'              => 1,
            'informacoes_adicionais' => 'Teste de emissao',
        ];
        $itens = [[
            'numero_item'                => 1,
            'codigo_produto'             => 'PROD001',
            'descricao'                  => 'Notebook Dell Inspiron',
            'ncm'                        => '84713012',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '1.0000',
            'valor_unitario'             => '3500.00',
            'valor_desconto'             => null,
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social'     => 'Cliente Pessoa Física',
            'cpf_cnpj'         => '12345678909',
            'logradouro'       => 'Av B',
            'cliente_numero'   => '200',
            'bairro'           => 'Setor Sul',
            'codigo_municipio' => '5208707',
            'uf'               => 'GO',
            'cep'              => '74000001',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 1, '1', '12345678');

        $this->assertIsString($xml);
        $this->assertStringContainsString('<NFe', $xml);
        $this->assertStringContainsString('Notebook Dell Inspiron', $xml);
        $this->assertStringContainsString('11222333000181', $xml);
        $this->assertStringContainsString('84713012', $xml);
    }

    public function testBuildComDescontoCalculaTotalCorreto(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = ['natureza_operacao' => 'Venda', 'consumidor_final' => 0, 'presenca' => 1, 'informacoes_adicionais' => ''];
        $itens  = [[
            'numero_item'                => 1,
            'codigo_produto'             => 'P2',
            'descricao'                  => 'Painel Solar 450W',
            'ncm'                        => '85414090',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '4.0000',
            'valor_unitario'             => '1000.00',
            'valor_desconto'             => '200.00',
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social' => 'Empresa GO', 'cpf_cnpj' => '11222333000181',
            'logradouro' => 'Rua X', 'cliente_numero' => '1',
            'bairro' => 'B', 'codigo_municipio' => '5208707',
            'uf' => 'GO', 'cep' => '74000002',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 2, '1', '87654321');
        $this->assertStringContainsString('3800', $xml); // 4*1000 - 200
    }

    public function testBuildComCodigoProdutoVazioUsaNumeroDoItemComoFallback(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = ['natureza_operacao' => 'Venda', 'consumidor_final' => 0, 'presenca' => 1, 'informacoes_adicionais' => ''];
        $itens  = [[
            'numero_item'                => 1,
            'codigo_produto'             => '',
            'descricao'                  => 'Item sem codigo de produto informado',
            'ncm'                        => '85414090',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '1.0000',
            'valor_unitario'             => '100.00',
            'valor_desconto'             => null,
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social' => 'Empresa GO', 'cpf_cnpj' => '11222333000181',
            'logradouro' => 'Rua X', 'cliente_numero' => '1',
            'bairro' => 'B', 'codigo_municipio' => '5208707',
            'uf' => 'GO', 'cep' => '74000003',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 3, '1', '11112222');

        $this->assertStringContainsString('<cProd>0001</cProd>', $xml);
    }

    public function testDhEmiUsaOffsetDeBrasiliaMesmoComTimezonePadraoUtc(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $config  = new Config($this->tmpDir);
            $factory = new NfeXmlFactory($config);

            $pedido = ['natureza_operacao' => 'Venda', 'consumidor_final' => 0, 'presenca' => 1, 'informacoes_adicionais' => ''];
            $itens  = [[
                'numero_item'                => 1,
                'codigo_produto'             => 'P4',
                'descricao'                  => 'Item qualquer',
                'ncm'                        => '85414090',
                'cfop'                       => '5102',
                'unidade'                    => 'UN',
                'quantidade'                 => '1.0000',
                'valor_unitario'             => '100.00',
                'valor_desconto'             => null,
                'csosn'                      => '400',
                'pis_cst'                    => '07',
                'cofins_cst'                 => '07',
                'informacoes_adicionais_item' => null,
            ]];
            $cliente = [
                'razao_social' => 'Empresa GO', 'cpf_cnpj' => '11222333000181',
                'logradouro' => 'Rua X', 'cliente_numero' => '1',
                'bairro' => 'B', 'codigo_municipio' => '5208707',
                'uf' => 'GO', 'cep' => '74000004',
            ];

            $xml = $factory->build($pedido, $itens, $cliente, 4, '1', '33334444');

            $this->assertMatchesRegularExpression('/<dhEmi>[^<]*-03:00<\/dhEmi>/', $xml);
            $this->assertStringNotContainsString('+00:00', $xml);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testDetPagComTPag90NaoInformaVPag(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = ['natureza_operacao' => 'Venda', 'consumidor_final' => 0, 'presenca' => 1, 'informacoes_adicionais' => ''];
        $itens  = [[
            'numero_item'                => 1,
            'codigo_produto'             => 'P5',
            'descricao'                  => 'Item qualquer',
            'ncm'                        => '85414090',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '1.0000',
            'valor_unitario'             => '73338.65',
            'valor_desconto'             => null,
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social' => 'Empresa GO', 'cpf_cnpj' => '11222333000181',
            'logradouro' => 'Rua X', 'cliente_numero' => '1',
            'bairro' => 'Setor Central', 'codigo_municipio' => '5208707', 'municipio' => 'Goiania',
            'uf' => 'GO', 'cep' => '74000005',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 5, '1', '55556666');

        $this->assertStringContainsString('<tPag>90</tPag>', $xml);
        $this->assertStringContainsString('<vPag>0.00</vPag>', $xml);

        // A tag vPag é obrigatória no XSD oficial (sem minOccurs=0) em toda versão de schema —
        // omiti-la (como uma correção anterior fez) produz um XML tecnicamente inválido, mesmo
        // que satisfaça a regra de negócio do SEFAZ. Validamos contra o XSD real usado em
        // produção (o mesmo que rejeitou a correção anterior) para não repetir esse erro
        // especificamente. Este teste valida o XML sem assinatura (a assinatura é aplicada depois,
        // por Signer::sign() em NfeClient), então ignoramos apenas o erro esperado de Signature
        // ausente — qualquer outro erro, principalmente sobre vPag/detPag, deve falhar o teste.
        $xsd = realpath(
            __DIR__ . '/../vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd'
        );
        $this->assertNotFalse($xsd, 'XSD de referência não encontrado');

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $dom->schemaValidate($xsd);
        $errors = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();

        $errosRelevantes = array_filter(
            $errors,
            static fn (string $e) => !str_contains($e, 'infNFeSupl') && !str_contains($e, 'Signature')
        );
        $this->assertSame(
            [],
            array_values($errosRelevantes),
            "XML inválido contra o XSD oficial:\n" . implode("\n", $errors)
        );
    }
}
