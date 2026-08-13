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
        $this->assertSame('Rua das Amostras,500 Sala 10 - Centro', $d['prestador']['endereco']);
        $this->assertSame('74000-000', $d['prestador']['cep']);
        $this->assertSame('Goiânia/ GO', $d['prestador']['municipio_uf']);
        $this->assertSame('(62)99999-0000', $d['prestador']['telefone']);
        $this->assertSame('contato@exemplosolar.com.br', $d['prestador']['email']);
    }

    public function testDadosDoTomador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('22.333.444/0001-55', $d['tomador']['cnpj_cpf']);
        $this->assertSame('Cliente Exemplo LTDA', $d['tomador']['razao_social']);
        $this->assertSame('R Exemplo', $d['tomador']['endereco']);
        $this->assertSame('138', $d['tomador']['numero']);
        $this->assertSame('', $d['tomador']['complemento']);
        $this->assertSame('Bairro Exemplo', $d['tomador']['bairro']);
        $this->assertSame('Belo Horizonte/ MG', $d['tomador']['cidade_uf']);
        $this->assertSame('30190-000', $d['tomador']['cep']);
        $this->assertSame('(31)98888-7777', $d['tomador']['telefone']);
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

    public function testAtividadeMunicipioSemCodigoTributacaoMunicipio(): void
    {
        // Caso real: o XmlFactory não envia CodigoTributacaoMunicipio na
        // Servico — só a Descrição fica disponível. O trim(..., ' -') deve
        // eliminar o traço/espaço à esquerda que sobraria sem o código.
        $xmlSemCodigo = str_replace(
            '<CodigoTributacaoMunicipio>702</CodigoTributacaoMunicipio>',
            '',
            $this->xmlComRetencao
        );

        $d = Danfse::extrair($xmlSemCodigo);

        $this->assertSame(
            '07.02 - Execucao, por administracao, empreitada ou subempreitada, de obras de construcao civil.',
            $d['atividade_municipio']
        );
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

    public function testRenderizarProduzHtmlComOsDadosDaNota(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);
        $html = Danfse::renderizar($d);

        $this->assertStringContainsString('Empresa Exemplo Ltda', $html);
        $this->assertStringContainsString('Cliente Exemplo LTDA', $html);
        $this->assertStringContainsString('R$ 37.209,00', $html);
        $this->assertStringContainsString('R$ 36.464,82', $html);
        $this->assertStringContainsString('ABC123XYZ', $html);
        $this->assertStringContainsString('Belo Horizonte - Minas Gerais', $html);
        // QR code e chave ADN nunca devem aparecer — decisão do spec §1.
        $this->assertStringNotContainsString('qrcode', strtolower($html));
        $this->assertStringNotContainsString('Ambiente de Dados Nacional', $html);
    }

    public function testRenderizarEscapaHtmlNosDados(): void
    {
        // XML entities, não a tag crua: uma tag crua vira elemento filho válido do
        // XML (o DOMDocument descarta as tags do textContent), o que mascararia o
        // teste. Entidades preservam "<script>" como texto literal após o parse,
        // exercitando de fato o escaping do template.
        $xmlComTagNoNome = str_replace(
            'Cliente Exemplo LTDA',
            'Cliente &lt;script&gt;alert(1)&lt;/script&gt;',
            $this->xmlComRetencao
        );
        $d = Danfse::extrair($xmlComTagNoNome);
        $html = Danfse::renderizar($d);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
