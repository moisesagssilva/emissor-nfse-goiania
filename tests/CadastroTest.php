<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Cadastro;
use EmissorGyn\ResponseParser;
use PHPUnit\Framework\TestCase;

final class CadastroTest extends TestCase
{
    private Cadastro $db;

    protected function setUp(): void
    {
        $this->db = new Cadastro(':memory:');
    }

    public function testMigrateCreatesTablesIdempotently(): void
    {
        $db2 = new Cadastro(':memory:');
        $this->assertInstanceOf(Cadastro::class, $db2);
    }

    public function testContarUsuariosVazio(): void
    {
        $this->assertSame(0, $this->db->contarUsuarios());
    }

    public function testInserirEBuscarUsuario(): void
    {
        $id = $this->db->inserirUsuario('Ana', 'ana@lumina.com', 'hash123');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->db->contarUsuarios());

        $usuario = $this->db->buscarUsuarioPorEmail('ana@lumina.com');
        $this->assertNotNull($usuario);
        $this->assertSame('Ana', $usuario['nome']);
    }

    public function testBuscarUsuarioInexistente(): void
    {
        $this->assertNull($this->db->buscarUsuarioPorEmail('nao@existe.com'));
    }

    public function testRateLimit(): void
    {
        $this->assertFalse($this->db->verificarBloqueio('1.2.3.4'));

        for ($i = 0; $i < 5; $i++) {
            $this->db->registrarTentativa('1.2.3.4');
        }

        $this->assertTrue($this->db->verificarBloqueio('1.2.3.4'));
    }

    public function testLimparTentativasDesbloqueia(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->db->registrarTentativa('9.9.9.9');
        }
        $this->assertTrue($this->db->verificarBloqueio('9.9.9.9'));
        $this->db->limparTentativas('9.9.9.9');
        $this->assertFalse($this->db->verificarBloqueio('9.9.9.9'));
    }

    public function testCrudClientes(): void
    {
        $dados = [
            'razao_social'     => 'Empresa Teste LTDA',
            'cpf_cnpj'         => '11222333000181',
            'email'            => 'teste@empresa.com',
            'telefone'         => '62999990000',
            'logradouro'       => 'Rua A',
            'numero'           => '100',
            'complemento'      => '',
            'bairro'           => 'Centro',
            'codigo_municipio' => '5208707',
            'uf'               => 'GO',
            'cep'              => '74000000',
        ];

        $id = $this->db->inserirCliente($dados);
        $this->assertGreaterThan(0, $id);

        $cliente = $this->db->buscarCliente($id);
        $this->assertNotNull($cliente);
        $this->assertSame('Empresa Teste LTDA', $cliente['razao_social']);

        $dados['razao_social'] = 'Empresa Atualizada LTDA';
        $this->db->atualizarCliente($id, $dados);
        $cliente = $this->db->buscarCliente($id);
        $this->assertSame('Empresa Atualizada LTDA', $cliente['razao_social']);

        $lista = $this->db->listarClientes();
        $this->assertCount(1, $lista);

        $this->db->desativarCliente($id);
        $this->assertCount(0, $this->db->listarClientes());
    }

    public function testListarClientesComBusca(): void
    {
        $this->db->inserirCliente([
            'razao_social' => 'Solar Engenharia',
            'cpf_cnpj'     => '11111111000111',
        ]);
        $this->db->inserirCliente([
            'razao_social' => 'Outro Negocio',
            'cpf_cnpj'     => '22222222000122',
        ]);

        $resultado = $this->db->listarClientes('Solar');
        $this->assertCount(1, $resultado);
        $this->assertSame('Solar Engenharia', $resultado[0]['razao_social']);
    }

    public function testCrudServicos(): void
    {
        $dados = [
            'nome'                          => 'Instalação Fotovoltaica 5kWp',
            'item_lista_servico'            => '7.02',
            'codigo_cnae'                   => '4321500',
            'codigo_tributacao_municipio'   => '',
            'discriminacao'                 => 'Instalação sistema fotovoltaico',
            'aliquota'                      => '2.00',
            'exigibilidade_iss'             => 1,
            'iss_retido'                    => 2,
        ];

        $id = $this->db->inserirServico($dados);
        $this->assertGreaterThan(0, $id);

        $servico = $this->db->buscarServico($id);
        $this->assertNotNull($servico);
        $this->assertSame('7.02', $servico['item_lista_servico']);

        $lista = $this->db->listarServicos();
        $this->assertCount(1, $lista);

        $this->db->desativarServico($id);
        $this->assertCount(0, $this->db->listarServicos());
    }

    public function testCrudOrcamentos(): void
    {
        $clienteId = $this->db->inserirCliente([
            'razao_social' => 'Cliente Orcamento',
            'cpf_cnpj'     => '33333333000133',
        ]);
        $usuarioId = $this->db->inserirUsuario('Operador', 'op@lumina.com', 'hash');

        $dados = [
            'cliente_id'          => $clienteId,
            'servico_id'          => '',
            'competencia'         => '2026-07-01',
            'valor_servicos'      => '5000.00',
            'item_lista_servico'  => '7.02',
            'codigo_cnae'         => '4321500',
            'codigo_tributacao_municipio' => '',
            'discriminacao'       => 'Serviço de instalação',
            'aliquota'            => '',
            'exigibilidade_iss'   => 1,
            'iss_retido'          => 2,
            'criado_por'          => $usuarioId,
        ];

        $id = $this->db->inserirOrcamento($dados);
        $this->assertGreaterThan(0, $id);

        $orc = $this->db->buscarOrcamento($id);
        $this->assertNotNull($orc);
        $this->assertSame('rascunho', $orc['status']);
        $this->assertSame('Cliente Orcamento', $orc['razao_social']);

        $this->db->aprovarOrcamento($id, $usuarioId);
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('aprovado', $orc['status']);

        $this->db->emitirOrcamento($id, 99, '123');
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('emitido', $orc['status']);
        $this->assertSame('123', $orc['nfse_numero']);
    }

    public function testCancelarOrcamento(): void
    {
        $clienteId = $this->db->inserirCliente([
            'razao_social' => 'Cancel Test',
            'cpf_cnpj'     => '44444444000144',
        ]);
        $usuarioId = $this->db->inserirUsuario('Op2', 'op2@lumina.com', 'hash');

        $id = $this->db->inserirOrcamento([
            'cliente_id'         => $clienteId,
            'servico_id'         => '',
            'competencia'        => '2026-07-01',
            'valor_servicos'     => '1000.00',
            'item_lista_servico' => '7.02',
            'discriminacao'      => 'Teste',
            'exigibilidade_iss'  => 1,
            'iss_retido'         => 2,
            'criado_por'         => $usuarioId,
        ]);

        $this->db->cancelarOrcamento($id);
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('cancelado', $orc['status']);
    }

    public function testEstatisticas(): void
    {
        $stats = $this->db->estatisticas();
        $this->assertSame(0, $stats['total_clientes']);
        $this->assertArrayHasKey('rascunho', $stats['orcamentos']);
        $this->assertIsArray($stats['ultimas_emissoes']);
    }

    public function testParseUrlNfse(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<ConsultarUrlNfseResposta>'
            . '<Url>https://nfse.goiania.go.gov.br/danfse/123</Url>'
            . '</ConsultarUrlNfseResposta>';
        $url = ResponseParser::parseUrlNfse($xml);
        $this->assertSame('https://nfse.goiania.go.gov.br/danfse/123', $url);
    }

    public function testParseUrlNfseRetornaNullQuandoAusente(): void
    {
        $url = ResponseParser::parseUrlNfse('<Resposta><Erro>falha</Erro></Resposta>');
        $this->assertNull($url);
    }

    // ─── NfeStorage ──────────────────────────────────────────────────────────

    public function testNfeStorageMigrateIdempotente(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $storage2 = new \EmissorGyn\NfeStorage(':memory:');
        $this->assertInstanceOf(\EmissorGyn\NfeStorage::class, $storage2);
    }

    public function testProximoNfeIncrementa(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $this->assertSame(1, $storage->proximoNfe('1'));
        $this->assertSame(2, $storage->proximoNfe('1'));
        $this->assertSame(1, $storage->proximoNfe('2'));
    }

    public function testDefinirUltimoNfeFazRollback(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $n = $storage->proximoNfe('1');
        $this->assertSame(1, $n);
        $storage->definirUltimoNfe('1', $n - 1);
        $this->assertSame(1, $storage->proximoNfe('1'));
    }

    public function testCrudPedidos(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');

        // Precisa de um cliente na tabela clientes do mesmo SQLite — NfeStorage
        // cria as tabelas de pedidos, mas clientes já existe em Cadastro.
        // Para isolar o teste, criamos o cliente direto via PDO refletido.
        // NfeStorage expõe o método de acesso ao pdo para fins de teste.
        $pdo = $storage->getPdoForTest();
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS clientes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                razao_social TEXT NOT NULL,
                cpf_cnpj TEXT NOT NULL,
                uf TEXT,
                cep TEXT,
                logradouro TEXT,
                numero TEXT,
                bairro TEXT,
                codigo_municipio TEXT,
                municipio TEXT,
                ativo INTEGER NOT NULL DEFAULT 1
            )
        SQL);
        $pdo->exec(
            "INSERT INTO clientes (razao_social, cpf_cnpj, uf) VALUES ('Teste LTDA', '11222333000181', 'GO')"
        );
        $clienteId = (int) $pdo->lastInsertId();

        $dados = [
            'cliente_id'           => $clienteId,
            'natureza_operacao'    => 'Venda de mercadoria',
            'consumidor_final'     => 0,
            'presenca'             => 1,
            'informacoes_adicionais' => '',
            'criado_por'           => 1,
        ];
        $id = $storage->inserirPedido($dados);
        $this->assertGreaterThan(0, $id);

        $pedido = $storage->buscarPedido($id);
        $this->assertNotNull($pedido);
        $this->assertSame('rascunho', $pedido['status']);
        $this->assertSame('Teste LTDA', $pedido['razao_social']);

        $storage->aprovarPedido($id, 1);
        $pedido = $storage->buscarPedido($id);
        $this->assertSame('aprovado', $pedido['status']);

        $storage->emitirPedido($id, 'CHAVE123', 1, '1', 'PROT123', '<xml/>');
        $pedido = $storage->buscarPedido($id);
        $this->assertSame('emitido', $pedido['status']);
        $this->assertSame('CHAVE123', $pedido['nfe_chave']);
        $this->assertSame('PROT123', $pedido['nfe_protocolo']);
    }

    public function testSubstituirItens(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $pdo = $storage->getPdoForTest();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, razao_social TEXT NOT NULL, cpf_cnpj TEXT NOT NULL)"
        );
        $pdo->exec("INSERT INTO clientes (razao_social, cpf_cnpj) VALUES ('C','11222333000181')");
        $clienteId = (int) $pdo->lastInsertId();

        $pedidoId = $storage->inserirPedido([
            'cliente_id' => $clienteId, 'natureza_operacao' => 'Venda',
            'consumidor_final' => 0, 'presenca' => 1,
            'informacoes_adicionais' => '', 'criado_por' => 1,
        ]);

        $itens = [
            [
                'numero_item' => 1,
                'codigo_produto' => 'P001',
                'descricao' => 'Produto A',
                'ncm' => '84713012',
                'cfop' => '5102',
                'unidade' => 'UN',
                'quantidade' => '2.0000',
                'valor_unitario' => '50.00',
                'valor_desconto' => null,
                'csosn' => '400',
                'pis_cst' => '07',
                'cofins_cst' => '07',
                'informacoes_adicionais_item' => null,
            ],
        ];
        $storage->substituirItens($pedidoId, $itens);

        $lista = $storage->listarItens($pedidoId);
        $this->assertCount(1, $lista);
        $this->assertSame('Produto A', $lista[0]['descricao']);

        // Substituir com 2 itens
        $itens[] = array_merge($itens[0], ['numero_item' => 2, 'descricao' => 'Produto B']);
        $storage->substituirItens($pedidoId, $itens);
        $this->assertCount(2, $storage->listarItens($pedidoId));
    }

    public function testCancelarPedidoEmitidoRegistraEvento(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $pdo = $storage->getPdoForTest();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, razao_social TEXT NOT NULL, cpf_cnpj TEXT NOT NULL)"
        );
        $pdo->exec("INSERT INTO clientes (razao_social, cpf_cnpj) VALUES ('E','11222333000181')");
        $clienteId = (int) $pdo->lastInsertId();
        $pedidoId = $storage->inserirPedido([
            'cliente_id' => $clienteId, 'natureza_operacao' => 'Venda',
            'consumidor_final' => 0, 'presenca' => 1,
            'informacoes_adicionais' => '', 'criado_por' => 1,
        ]);
        $storage->aprovarPedido($pedidoId, 1);
        $storage->emitirPedido($pedidoId, 'CH', 1, '1', 'PR', '<x/>');
        $storage->registrarEvento($pedidoId, 'cancelamento', 'PROT-CANC', 'Cancelado', '<ev/>', '<ret/>');
        $storage->cancelarPedido($pedidoId);

        $pedido = $storage->buscarPedido($pedidoId);
        $this->assertSame('cancelado', $pedido['status']);

        $eventos = $storage->listarEventos($pedidoId);
        $this->assertCount(1, $eventos);
        $this->assertSame('cancelamento', $eventos[0]['tipo']);
    }

    public function testNfeEstatisticas(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $stats = $storage->estatisticas();
        $this->assertArrayHasKey('rascunho', $stats);
        $this->assertArrayHasKey('emitido', $stats);
    }
}
