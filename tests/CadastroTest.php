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
}
