<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Extrai e formata os dados de uma NFS-e (InfNfse, resposta do GerarNfse)
 * para exibição em PDF. Ver docs/superpowers/specs/2026-08-13-danfse-pdf-design.md §4
 * para o mapeamento completo de campos.
 */
final class Danfse
{
    private const NATUREZA_OPERACAO = [
        '1' => 'Exigível',
        '2' => 'Não incidência',
        '3' => 'Isenção',
        '4' => 'Exportação',
        '5' => 'Imunidade',
        '6' => 'Exigibilidade suspensa por decisão judicial',
        '7' => 'Exigibilidade suspensa por processo administrativo',
    ];

    /** @return array<string,mixed> */
    public static function extrair(string $xmlRetorno): array
    {
        $dom = new \DOMDocument();
        $ok = @$dom->loadXML($xmlRetorno, LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        $infNfse = $ok ? self::el($dom, 'InfNfse') : null;
        if ($infNfse === null) {
            throw new \InvalidArgumentException(
                'XML de retorno não contém InfNfse — nota não encontrada ou XML mal formado.'
            );
        }

        $prestadorServico = self::el($infNfse, 'PrestadorServico');
        $enderecoPrestador = self::el($prestadorServico, 'Endereco');
        $contatoPrestador = self::el($prestadorServico, 'Contato');

        $declaracao = self::el($infNfse, 'InfDeclaracaoPrestacaoServico');
        $rps = self::el($declaracao, 'Rps');
        $identRps = self::el($rps, 'IdentificacaoRps');
        $servico = self::el($declaracao, 'Servico');
        $valores = self::el($servico, 'Valores');
        $prestador = self::el($declaracao, 'Prestador');
        $tomador = self::el($declaracao, 'TomadorServico');
        $enderecoTomador = self::el($tomador, 'Endereco');
        $contatoTomador = self::el($tomador, 'Contato');
        $intermediarioNo = self::el($declaracao, 'Intermediario');
        $valoresNfse = self::el($infNfse, 'ValoresNfse');

        $issRetido = self::txt($servico, 'IssRetido');
        $responsavelRetencao = self::txt($servico, 'ResponsavelRetencao');
        $valorIss = self::txt($valores, 'ValorIss');

        if ($issRetido === '1') {
            $responsavelRetencaoTexto = $responsavelRetencao === '2' ? 'Intermediário' : 'Tomador';
            $totalIssqn = self::moeda('0');
            $valorIssqnRetido = self::moeda($valorIss);
        } else {
            $responsavelRetencaoTexto = '';
            $totalIssqn = self::moeda($valorIss);
            $valorIssqnRetido = self::moeda('0');
        }

        $codigoMunicipioServico = self::txt($servico, 'CodigoMunicipio');
        $municipioIncidencia = self::txt($servico, 'MunicipioIncidencia');

        return [
            'numero' => self::txt($infNfse, 'Numero'),
            'codigo_verificacao' => self::txt($infNfse, 'CodigoVerificacao'),
            'data_emissao' => self::dataHora(self::txt($infNfse, 'DataEmissao')),
            'competencia' => self::data(self::txt($declaracao, 'Competencia')),
            'informacoes_adicionais' => self::txt($infNfse, 'OutrasInformacoes'),

            'rps_numero' => self::txt($identRps, 'Numero'),
            'rps_serie' => self::txt($identRps, 'Serie'),
            'rps_data_emissao' => self::data(self::txt($rps, 'DataEmissao')),

            'local_servicos' => $codigoMunicipioServico !== '' ? Municipios::cidadeEstado($codigoMunicipioServico) : '',
            'municipio_incidencia' => $municipioIncidencia !== '' ? Municipios::cidadeEstado($municipioIncidencia) : '',

            'prestador' => [
                'razao_social' => self::txt($prestadorServico, 'RazaoSocial'),
                'nome_fantasia' => self::txt($prestadorServico, 'NomeFantasia'),
                'cnpj' => self::mascaraCnpjCpf(self::txt($prestador, 'Cnpj')),
                'inscricao_municipal' => self::txt($prestador, 'InscricaoMunicipal'),
                'endereco' => self::enderecoConcatenado($enderecoPrestador),
                'cep' => self::mascaraCep(self::txt($enderecoPrestador, 'Cep')),
                'municipio_uf' => self::cidadeUf($enderecoPrestador),
                'telefone' => self::mascaraTelefone(self::txt($contatoPrestador, 'Telefone')),
                'email' => self::txt($contatoPrestador, 'Email'),
            ],

            'tomador' => [
                'cnpj_cpf' => self::mascaraCnpjCpf(
                    self::txt($tomador, 'Cnpj') !== '' ? self::txt($tomador, 'Cnpj') : self::txt($tomador, 'Cpf')
                ),
                'inscricao_municipal' => self::txt($tomador, 'InscricaoMunicipal'),
                'razao_social' => self::txt($tomador, 'RazaoSocial'),
                'endereco' => self::txt($enderecoTomador, 'Endereco'),
                'numero' => self::txt($enderecoTomador, 'Numero'),
                'complemento' => self::txt($enderecoTomador, 'Complemento'),
                'bairro' => self::txt($enderecoTomador, 'Bairro'),
                'cidade_uf' => self::cidadeUf($enderecoTomador),
                'cep' => self::mascaraCep(self::txt($enderecoTomador, 'Cep')),
                'telefone' => self::mascaraTelefone(self::txt($contatoTomador, 'Telefone')),
                'email' => self::txt($contatoTomador, 'Email'),
            ],

            'intermediario' => $intermediarioNo !== null ? [
                'cnpj_cpf' => self::mascaraCnpjCpf(
                    self::txt($intermediarioNo, 'Cnpj') !== ''
                        ? self::txt($intermediarioNo, 'Cnpj')
                        : self::txt($intermediarioNo, 'Cpf')
                ),
                'inscricao_municipal' => self::txt($intermediarioNo, 'InscricaoMunicipal'),
                'razao_social' => self::txt($intermediarioNo, 'RazaoSocial'),
            ] : [],

            'discriminacao' => self::txt($servico, 'Discriminacao'),
            'atividade_municipio' => trim(
                self::txt($infNfse, 'CodigoTributacaoMunicipio') . ' - '
                . self::txt($infNfse, 'DescricaoCodigoTributacaoMunicípio'),
                ' -'
            ),
            'aliquota' => self::moedaSemPrefixo(self::txt($valores, 'Aliquota')),
            'item_lista_servico' => self::txt($servico, 'ItemListaServico'),
            'codigo_nbs' => self::txt($servico, 'CodigoNbs'),
            'codigo_cnae' => self::txt($servico, 'CodigoCnae'),

            'valor_total_servicos' => self::moeda(self::txt($valores, 'ValorServicos')),
            'desconto_incondicionado' => self::moeda(self::txt($valores, 'DescontoIncondicionado')),
            'deducoes_base_calculo' => self::moeda(self::txt($valores, 'ValorDeducoes')),
            'base_calculo' => self::moeda(self::txt($valoresNfse, 'BaseCalculo')),
            'desconto_condicionado' => self::moeda(self::txt($valores, 'DescontoCondicionado')),

            'pis' => self::moeda(self::txt($valores, 'ValorPis')),
            'cofins' => self::moeda(self::txt($valores, 'ValorCofins')),
            'inss' => self::moeda(self::txt($valores, 'ValorInss')),
            'irrf' => self::moeda(self::txt($valores, 'ValorIr')),
            'csll' => self::moeda(self::txt($valores, 'ValorCsll')),
            'outras_retencoes' => self::moeda(self::txt($valores, 'OutrasRetencoes')),

            'valor_liquido_nota' => self::moeda(self::txt($valoresNfse, 'ValorLiquidoNfse')),

            'issqn_retido' => $issRetido === '1' ? 'Sim' : 'Não',
            'natureza_operacao' => self::NATUREZA_OPERACAO[self::txt($servico, 'ExigibilidadeISS')] ?? '',
            'responsavel_retencao' => $responsavelRetencaoTexto,
            'total_issqn' => $totalIssqn,
            'valor_issqn_retido' => $valorIssqnRetido,
        ];
    }

    /** Renderiza o template com os dados já extraídos e devolve o HTML como string. */
    public static function renderizar(array $dados, string $logoPath = ''): string
    {
        $d = $dados;
        $logoDataUri = '';
        if ($logoPath !== '' && is_file($logoPath)) {
            $tipo = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
            $logoDataUri = 'data:' . $tipo . ';base64,' . base64_encode((string) file_get_contents($logoPath));
        }

        ob_start();
        try {
            require __DIR__ . '/templates/danfse.php';
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    // ------------------------------------------------------------------ utils

    private static function el(\DOMDocument|\DOMElement|null $ctx, string $tag): ?\DOMElement
    {
        if ($ctx === null) {
            return null;
        }
        $node = $ctx->getElementsByTagName($tag)->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    private static function txt(\DOMDocument|\DOMElement|null $ctx, string $tag): string
    {
        return trim(self::el($ctx, $tag)?->textContent ?? '');
    }

    private static function moeda(string $valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    private static function moedaSemPrefixo(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    private static function data(string $isoData): string
    {
        if ($isoData === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', substr($isoData, 0, 10));
        return $dt !== false ? $dt->format('d/m/Y') : '';
    }

    private static function dataHora(string $isoDataHora): string
    {
        if ($isoDataHora === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $isoDataHora);
        if ($dt === false) {
            $dt = \DateTime::createFromFormat('Y-m-d', substr($isoDataHora, 0, 10));
        }
        return $dt !== false ? $dt->format('d/m/Y H:i:s') : '';
    }

    private static function mascaraCnpjCpf(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        if (strlen($digitos) === 14) {
            return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3)
                . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
        }
        if (strlen($digitos) === 11) {
            return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3)
                . '-' . substr($digitos, 9, 2);
        }
        return $digitos;
    }

    private static function mascaraCep(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        return strlen($digitos) === 8 ? substr($digitos, 0, 5) . '-' . substr($digitos, 5, 3) : $digitos;
    }

    private static function mascaraTelefone(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        $tamanho = strlen($digitos);
        if ($tamanho === 11) {
            return '(' . substr($digitos, 0, 2) . ')' . substr($digitos, 2, 5) . '-' . substr($digitos, 7, 4);
        }
        if ($tamanho === 10) {
            return '(' . substr($digitos, 0, 2) . ')' . substr($digitos, 2, 4) . '-' . substr($digitos, 6, 4);
        }
        return $digitos;
    }

    private static function enderecoConcatenado(?\DOMElement $endereco): string
    {
        if ($endereco === null) {
            return '';
        }
        $linha1 = self::txt($endereco, 'Endereco') . ',' . self::txt($endereco, 'Numero');
        $sufixo = trim(self::txt($endereco, 'Complemento') . ' - ' . self::txt($endereco, 'Bairro'), ' -');
        return trim($linha1 . ' ' . $sufixo);
    }

    private static function cidadeUf(?\DOMElement $endereco): string
    {
        if ($endereco === null) {
            return '';
        }
        $nome = Municipios::nome(self::txt($endereco, 'CodigoMunicipio'));
        return $nome !== '' ? $nome . '/ ' . self::txt($endereco, 'Uf') : '';
    }
}
