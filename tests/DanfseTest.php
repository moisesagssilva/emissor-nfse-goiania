<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Danfse;
use PHPUnit\Framework\TestCase;

final class DanfseTest extends TestCase
{
    private string $xmlComRetencao;

    protected function setUp(): void
    {
        $this->xmlComRetencao = (string) file_get_contents(__DIR__ . '/fixtures/nfse-retorno.xml');
    }

    public function testCamposBasicos(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('1', $d['numero']);
        $this->assertSame('ABC123XYZ', $d['codigo_verificacao']);
        $this->assertSame('12/08/2026 16:13:07', $d['data_emissao']);
        $this->assertSame('12/08/2026', $d['competencia']);
        $this->assertSame('1', $d['rps_numero']);
        $this->assertSame('1', $d['rps_serie']);
        $this->assertSame('12/08/2026', $d['rps_data_emissao']);
        $this->assertSame('Belo Horizonte - Minas Gerais', $d['local_servicos']);
        $this->assertSame('Belo Horizonte - Minas Gerais', $d['municipio_incidencia']);
        $this->assertSame([], $d['intermediario']);
    }

    public function testDadosDoPrestador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('Empresa Exemplo Ltda', $d['prestador']['razao_social']);
        $this->assertSame('Exemplo Solar', $d['prestador']['nome_fantasia']);
        $this->assertSame('11.222.333/0001-81', $d['prestador']['cnpj']);
        $this->assertSame('123456', $d['prestador']['inscricao_municipal']);
        $this->assertSame('Avenida Portugal,1148 Sala C2501 - Setor Marista', $d['prestador']['endereco']);
        $this->assertSame('74150-030', $d['prestador']['cep']);
        $this->assertSame('Goiânia/ GO', $d['prestador']['municipio_uf']);
        $this->assertSame('(62)98127-4500', $d['prestador']['telefone']);
        $this->assertSame('contato@exemplosolar.com.br', $d['prestador']['email']);
    }

    public function testDadosDoTomador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('17.452.871/0001-49', $d['tomador']['cnpj_cpf']);
        $this->assertSame('Cliente Exemplo LTDA', $d['tomador']['razao_social']);
        $this->assertSame('R Exemplo', $d['tomador']['endereco']);
        $this->assertSame('138', $d['tomador']['numero']);
        $this->assertSame('', $d['tomador']['complemento']);
        $this->assertSame('Vila Paris', $d['tomador']['bairro']);
        $this->assertSame('Belo Horizonte/ MG', $d['tomador']['cidade_uf']);
        $this->assertSame('30380-780', $d['tomador']['cep']);
        $this->assertSame('(31)99613-5712', $d['tomador']['telefone']);
        $this->assertSame('cliente@exemplo.com.br', $d['tomador']['email']);
    }

    public function testServicoEValores(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame(
            'Elaboracao de projeto eletrico, instalacao da expansao fotovoltaica.',
            $d['discriminacao']
        );
        $this->assertSame(
            '702 - 07.02 - Execucao, por administracao, empreitada ou subempreitada, de obras de construcao civil.',
            $d['atividade_municipio']
        );
        $this->assertSame('2,00', $d['aliquota']);
        $this->assertSame('07.02', $d['item_lista_servico']);
        $this->assertSame('', $d['codigo_nbs']);
        $this->assertSame('4321500', $d['codigo_cnae']);
        $this->assertSame('R$ 37.209,00', $d['valor_total_servicos']);
        $this->assertSame('R$ 0,00', $d['desconto_incondicionado']);
        $this->assertSame('R$ 0,00', $d['deducoes_base_calculo']);
        $this->assertSame('R$ 37.209,00', $d['base_calculo']);
        $this->assertSame('R$ 0,00', $d['desconto_condicionado']);
        $this->assertSame('R$ 0,00', $d['pis']);
        $this->assertSame('R$ 36.464,82', $d['valor_liquido_nota']);
    }

    public function testRegraRetencaoIssRetidoPeloTomador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('Sim', $d['issqn_retido']);
        $this->assertSame('Exigível', $d['natureza_operacao']);
        $this->assertSame('Tomador', $d['responsavel_retencao']);
        $this->assertSame('R$ 0,00', $d['total_issqn']);
        $this->assertSame('R$ 744,18', $d['valor_issqn_retido']);
    }

    public function testRegraRetencaoIssNaoRetido(): void
    {
        $xmlSemRetencao = str_replace(
            ['<IssRetido>1</IssRetido>', '<ResponsavelRetencao>1</ResponsavelRetencao>'],
            ['<IssRetido>2</IssRetido>', ''],
            $this->xmlComRetencao
        );

        $d = Danfse::extrair($xmlSemRetencao);

        $this->assertSame('Não', $d['issqn_retido']);
        $this->assertSame('', $d['responsavel_retencao']);
        $this->assertSame('R$ 744,18', $d['total_issqn']);
        $this->assertSame('R$ 0,00', $d['valor_issqn_retido']);
    }

    public function testXmlSemInfNfseLancaExcecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Danfse::extrair('<foo></foo>');
    }
}
