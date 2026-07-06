<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Monta os XMLs do layout ABRASF 2.04 (namespace http://www.abrasf.org.br/nfse.xsd)
 * aceitos pelo SGISS da Prefeitura de Goiânia (provedor ISSNet).
 *
 * A ordem dos elementos segue o XSD oficial — não reordene.
 */
final class XmlFactory
{
    public const XMLNS = 'http://www.abrasf.org.br/nfse.xsd';
    public const VERSAO = '2.04';

    public function __construct(private readonly Config $config)
    {
    }

    /** Cabeçalho padrão exigido em toda mensagem (nfseCabecMsg). */
    public function cabecalho(): string
    {
        return '<cabecalho versao="' . self::VERSAO . '" xmlns="' . self::XMLNS . '">'
            . '<versaoDados>' . self::VERSAO . '</versaoDados>'
            . '</cabecalho>';
    }

    /**
     * GerarNfseEnvio — emissão síncrona de uma NFS-e a partir de um RPS.
     *
     * @param int   $numeroRps número sequencial do RPS
     * @param array $dados     payload da nota (ver examples/nota.json)
     */
    public function gerarNfseEnvio(int $numeroRps, array $dados): string
    {
        $cfg = $this->config;
        $serie = $cfg->get('SERIE_RPS', '1');
        $tipo = $cfg->get('TIPO_RPS', '1');
        $codMun = $cfg->get('CODIGO_MUNICIPIO', '5208707');
        $id = 'rps' . $numeroRps . 's' . $serie;

        $dataEmissao = $dados['data_emissao'] ?? date('Y-m-d\TH:i:s');
        $competencia = $dados['competencia'] ?? date('Y-m-d');

        $xml = '<GerarNfseEnvio xmlns="' . self::XMLNS . '">'
            . '<Rps>'
            . '<InfDeclaracaoPrestacaoServico Id="' . $id . '">'
            . '<Rps>'
            .   '<IdentificacaoRps>'
            .     '<Numero>' . $numeroRps . '</Numero>'
            .     '<Serie>' . self::esc($serie) . '</Serie>'
            .     '<Tipo>' . self::esc($tipo) . '</Tipo>'
            .   '</IdentificacaoRps>'
            .   '<DataEmissao>' . self::esc($dataEmissao) . '</DataEmissao>'
            .   '<Status>1</Status>'
            . '</Rps>'
            . '<Competencia>' . self::esc($competencia) . '</Competencia>'
            . $this->tagServico($dados, $codMun)
            . $this->tagPrestador()
            . $this->tagTomador($dados['tomador'] ?? [])
            . $this->tagRegimeEOpcoes()
            . '</InfDeclaracaoPrestacaoServico>'
            . '</Rps>'
            . '</GerarNfseEnvio>';

        return $xml;
    }

    private function tagServico(array $dados, string $codMun): string
    {
        $s = $dados['servico'] ?? $dados;

        $valorServicos = self::dec($s['valor_servicos'] ?? null, 'valor_servicos é obrigatório');
        $issRetido = (string) ($s['iss_retido'] ?? 2); // 1=retido pelo tomador, 2=não retido
        $itemLista = (string) ($s['item_lista_servico'] ?? '');
        if ($itemLista === '') {
            throw new \InvalidArgumentException('item_lista_servico (subitem LC 116/2003, ex.: 7.02) é obrigatório');
        }
        $discriminacao = (string) ($s['discriminacao'] ?? '');
        if ($discriminacao === '') {
            throw new \InvalidArgumentException('discriminacao do serviço é obrigatória');
        }

        $valores = '<ValorServicos>' . $valorServicos . '</ValorServicos>';
        foreach ([
            'valor_deducoes' => 'ValorDeducoes',
            'valor_pis' => 'ValorPis',
            'valor_cofins' => 'ValorCofins',
            'valor_inss' => 'ValorInss',
            'valor_ir' => 'ValorIr',
            'valor_csll' => 'ValorCsll',
            'outras_retencoes' => 'OutrasRetencoes',
            'valor_iss' => 'ValorIss',
        ] as $k => $tag) {
            if (isset($s[$k]) && $s[$k] !== '' && $s[$k] !== null) {
                $valores .= "<{$tag}>" . self::dec($s[$k]) . "</{$tag}>";
            }
        }
        // Simples Nacional sem retenção: alíquota pode ficar em branco (FAQ SGISS, item 24).
        // Com ISS retido pelo tomador, a alíquota é OBRIGATÓRIA.
        if (isset($s['aliquota']) && $s['aliquota'] !== '' && $s['aliquota'] !== null) {
            $valores .= '<Aliquota>' . self::dec($s['aliquota']) . '</Aliquota>';
        } elseif ($issRetido === '1') {
            throw new \InvalidArgumentException('aliquota é obrigatória quando o ISS é retido pelo tomador');
        }
        foreach ([
            'desconto_incondicionado' => 'DescontoIncondicionado',
            'desconto_condicionado' => 'DescontoCondicionado',
        ] as $k => $tag) {
            if (isset($s[$k]) && $s[$k] !== '' && $s[$k] !== null) {
                $valores .= "<{$tag}>" . self::dec($s[$k]) . "</{$tag}>";
            }
        }

        $xml = '<Servico>'
            . '<Valores>' . $valores . '</Valores>'
            . '<IssRetido>' . self::esc($issRetido) . '</IssRetido>';

        if ($issRetido === '1') {
            // 1 = tomador, 2 = intermediário
            $xml .= '<ResponsavelRetencao>' . self::esc((string) ($s['responsavel_retencao'] ?? '1')) . '</ResponsavelRetencao>';
        }

        $xml .= '<ItemListaServico>' . self::esc($itemLista) . '</ItemListaServico>';

        if (!empty($s['codigo_cnae'])) {
            $xml .= '<CodigoCnae>' . self::esc((string) $s['codigo_cnae']) . '</CodigoCnae>';
        }
        if (!empty($s['codigo_tributacao_municipio'])) {
            $xml .= '<CodigoTributacaoMunicipio>' . self::esc((string) $s['codigo_tributacao_municipio']) . '</CodigoTributacaoMunicipio>';
        }

        $xml .= '<Discriminacao>' . self::esc($discriminacao) . '</Discriminacao>'
            . '<CodigoMunicipio>' . self::esc((string) ($s['codigo_municipio_prestacao'] ?? $codMun)) . '</CodigoMunicipio>'
            . '<ExigibilidadeISS>' . self::esc((string) ($s['exigibilidade_iss'] ?? '1')) . '</ExigibilidadeISS>';

        $municipioIncidencia = (string) ($s['municipio_incidencia'] ?? $codMun);
        if ($municipioIncidencia !== '') {
            $xml .= '<MunicipioIncidencia>' . self::esc($municipioIncidencia) . '</MunicipioIncidencia>';
        }

        $xml .= '</Servico>';
        return $xml;
    }

    private function tagPrestador(): string
    {
        return '<Prestador>'
            . '<CpfCnpj><Cnpj>' . self::digits($this->config->get('PRESTADOR_CNPJ')) . '</Cnpj></CpfCnpj>'
            . '<InscricaoMunicipal>' . self::esc($this->config->get('PRESTADOR_IM')) . '</InscricaoMunicipal>'
            . '</Prestador>';
    }

    private function tagTomador(array $t): string
    {
        if (empty($t)) {
            return '';
        }
        $doc = self::digits((string) ($t['cpf_cnpj'] ?? $t['documento'] ?? ''));
        if ($doc === '') {
            throw new \InvalidArgumentException('tomador.cpf_cnpj é obrigatório (identificação do tomador PJ é exigida em Goiânia)');
        }
        $docTag = strlen($doc) === 11
            ? '<Cpf>' . $doc . '</Cpf>'
            : '<Cnpj>' . $doc . '</Cnpj>';

        $xml = '<Tomador>'
            . '<IdentificacaoTomador>'
            . '<CpfCnpj>' . $docTag . '</CpfCnpj>';
        if (!empty($t['inscricao_municipal'])) {
            $xml .= '<InscricaoMunicipal>' . self::esc((string) $t['inscricao_municipal']) . '</InscricaoMunicipal>';
        }
        $xml .= '</IdentificacaoTomador>'
            . '<RazaoSocial>' . self::esc((string) ($t['razao_social'] ?? '')) . '</RazaoSocial>';

        $e = $t['endereco'] ?? [];
        if (!empty($e)) {
            $xml .= '<Endereco>'
                . '<Endereco>' . self::esc((string) ($e['logradouro'] ?? '')) . '</Endereco>'
                . '<Numero>' . self::esc((string) ($e['numero'] ?? 'S/N')) . '</Numero>';
            if (!empty($e['complemento'])) {
                $xml .= '<Complemento>' . self::esc((string) $e['complemento']) . '</Complemento>';
            }
            $xml .= '<Bairro>' . self::esc((string) ($e['bairro'] ?? '')) . '</Bairro>'
                . '<CodigoMunicipio>' . self::esc((string) ($e['codigo_municipio'] ?? $this->config->get('CODIGO_MUNICIPIO', '5208707'))) . '</CodigoMunicipio>'
                . '<Uf>' . self::esc((string) ($e['uf'] ?? 'GO')) . '</Uf>'
                . '<Cep>' . self::digits((string) ($e['cep'] ?? '')) . '</Cep>'
                . '</Endereco>';
        }

        $telefone = self::digits((string) ($t['telefone'] ?? ''));
        $email = (string) ($t['email'] ?? '');
        if ($telefone !== '' || $email !== '') {
            $xml .= '<Contato>';
            if ($telefone !== '') {
                $xml .= '<Telefone>' . $telefone . '</Telefone>';
            }
            if ($email !== '') {
                $xml .= '<Email>' . self::esc($email) . '</Email>';
            }
            $xml .= '</Contato>';
        }

        $xml .= '</Tomador>';
        return $xml;
    }

    private function tagRegimeEOpcoes(): string
    {
        $xml = '';
        $regime = $this->config->get('REGIME_ESPECIAL', '');
        if ($regime !== '') {
            $xml .= '<RegimeEspecialTributacao>' . self::esc($regime) . '</RegimeEspecialTributacao>';
        }
        $xml .= '<OptanteSimplesNacional>' . self::esc($this->config->get('OPTANTE_SIMPLES', '2')) . '</OptanteSimplesNacional>'
            . '<IncentivoFiscal>' . self::esc($this->config->get('INCENTIVO_FISCAL', '2')) . '</IncentivoFiscal>';
        return $xml;
    }

    /** ConsultarNfseRpsEnvio — localiza a NFS-e gerada a partir de um RPS. */
    public function consultarNfsePorRpsEnvio(int $numeroRps): string
    {
        return '<ConsultarNfseRpsEnvio xmlns="' . self::XMLNS . '">'
            . '<IdentificacaoRps>'
            . '<Numero>' . $numeroRps . '</Numero>'
            . '<Serie>' . self::esc($this->config->get('SERIE_RPS', '1')) . '</Serie>'
            . '<Tipo>' . self::esc($this->config->get('TIPO_RPS', '1')) . '</Tipo>'
            . '</IdentificacaoRps>'
            . $this->tagPrestador()
            . '</ConsultarNfseRpsEnvio>';
    }

    /** ConsultarNfseServicoPrestadoEnvio — notas emitidas em um período. */
    public function consultarServicoPrestadoEnvio(string $dataInicial, string $dataFinal, int $pagina = 1): string
    {
        return '<ConsultarNfseServicoPrestadoEnvio xmlns="' . self::XMLNS . '">'
            . $this->tagPrestador()
            . '<PeriodoEmissao>'
            . '<DataInicial>' . self::esc($dataInicial) . '</DataInicial>'
            . '<DataFinal>' . self::esc($dataFinal) . '</DataFinal>'
            . '</PeriodoEmissao>'
            . '<Pagina>' . $pagina . '</Pagina>'
            . '</ConsultarNfseServicoPrestadoEnvio>';
    }

    /**
     * CancelarNfseEnvio — cancelamento (permitido até o 5º dia do mês
     * subsequente à emissão; depois disso, só via processo administrativo/SEI).
     * Códigos usuais: 1=erro na emissão, 2=serviço não prestado, 4=duplicidade.
     */
    public function cancelarNfseEnvio(string $numeroNfse, string $codigoCancelamento = '1'): string
    {
        $codMun = $this->config->get('CODIGO_MUNICIPIO', '5208707');
        $id = 'canc' . preg_replace('/\D/', '', $numeroNfse);
        return '<CancelarNfseEnvio xmlns="' . self::XMLNS . '">'
            . '<Pedido>'
            . '<InfPedidoCancelamento Id="' . $id . '">'
            . '<IdentificacaoNfse>'
            . '<Numero>' . self::esc($numeroNfse) . '</Numero>'
            . '<CpfCnpj><Cnpj>' . self::digits($this->config->get('PRESTADOR_CNPJ')) . '</Cnpj></CpfCnpj>'
            . '<InscricaoMunicipal>' . self::esc($this->config->get('PRESTADOR_IM')) . '</InscricaoMunicipal>'
            . '<CodigoMunicipio>' . self::esc($codMun) . '</CodigoMunicipio>'
            . '</IdentificacaoNfse>'
            . '<CodigoCancelamento>' . self::esc($codigoCancelamento) . '</CodigoCancelamento>'
            . '</InfPedidoCancelamento>'
            . '</Pedido>'
            . '</CancelarNfseEnvio>';
    }

    /** ConsultarUrlNfse — método adicional do município: retorna a URL de impressão. */
    public function consultarUrlNfseEnvio(string $numeroNfse): string
    {
        return '<ConsultarUrlNfseEnvio xmlns="' . self::XMLNS . '">'
            . $this->tagPrestador()
            . '<NumeroNfse>' . self::esc($numeroNfse) . '</NumeroNfse>'
            . '</ConsultarUrlNfseEnvio>';
    }

    /** ConsultarRpsDisponivelEnvio — método adicional: RPS liberados pelo SGISS. */
    public function consultarRpsDisponivelEnvio(): string
    {
        return '<ConsultarRpsDisponivelEnvio xmlns="' . self::XMLNS . '">'
            . $this->tagPrestador()
            . '</ConsultarRpsDisponivelEnvio>';
    }

    /** ConsultarDadosCadastraisEnvio — método adicional: dados cadastrais do prestador. */
    public function consultarDadosCadastraisEnvio(): string
    {
        return '<ConsultarDadosCadastraisEnvio xmlns="' . self::XMLNS . '">'
            . $this->tagPrestador()
            . '</ConsultarDadosCadastraisEnvio>';
    }

    // ------------------------------------------------------------------ utils

    private static function esc(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    /** Normaliza valores decimais para o formato 0.00 exigido pelo XSD. */
    private static function dec(mixed $value, ?string $msgObrigatorio = null): string
    {
        if ($value === null || $value === '') {
            if ($msgObrigatorio !== null) {
                throw new \InvalidArgumentException($msgObrigatorio);
            }
            return '0.00';
        }
        if (is_string($value)) {
            $v = trim($value);
            if (str_contains($v, ',')) {
                // formato brasileiro: 1.234,56 -> 1234.56
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            }
            $value = $v;
        }
        return number_format((float) $value, 2, '.', '');
    }
}
