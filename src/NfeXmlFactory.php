<?php

declare(strict_types=1);

namespace EmissorGyn;

use NFePHP\Common\TimeZoneByUF;
use NFePHP\NFe\Make;

final class NfeXmlFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function build(
        array $pedido,
        array $itens,
        array $cliente,
        int $nNF,
        string $serie,
        string $cNF
    ): string {
        $make = new Make();

        $cnpjEmit = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CNPJ'));
        $tpAmb    = $this->config->getInt('NFE_AMBIENTE', 2);
        $uf       = strtoupper(trim($cliente['uf'] ?? 'GO'));
        $idDest   = $uf === 'GO' ? 1 : 2;

        // INF NFe (obrigatório — inicializa a estrutura raiz)
        $std         = new \stdClass();
        $std->versao = '4.00';
        $make->taginfNFe($std);

        // IDE
        $std           = new \stdClass();
        $std->cUF      = 52;
        $std->cNF      = str_pad($cNF, 8, '0', STR_PAD_LEFT);
        $std->natOp    = $pedido['natureza_operacao'] ?? 'Venda de mercadoria';
        $std->mod      = 55;
        $std->serie    = (int) $serie;
        $std->nNF      = $nNF;
        $tz            = new \DateTimeZone(TimeZoneByUF::get($this->config->get('PRESTADOR_UF', 'GO')));
        $agora         = (new \DateTime('now', $tz))->format('Y-m-d\TH:i:sP');
        $std->dhEmi    = $agora;
        $std->dhSaiEnt = $agora;
        $std->tpNF     = 1;
        $std->idDest   = $idDest;
        $std->cMunFG   = (int) $this->config->get('PRESTADOR_CODIGO_MUNICIPIO');
        $std->tpImp    = 1;
        $std->tpEmis   = 1;
        $std->tpAmb    = $tpAmb;
        $std->finNFe   = 1;
        $std->indFinal = (int) ($pedido['consumidor_final'] ?? 0);
        $std->indPres  = (int) ($pedido['presenca'] ?? 1);
        $std->procEmi  = 0;
        $std->verProc  = '1.0';
        $make->tagide($std);

        // EMIT
        $std        = new \stdClass();
        $std->CNPJ  = $cnpjEmit;
        $std->xNome = $this->config->get('PRESTADOR_RAZAO_SOCIAL');
        $std->IE    = preg_replace('/\D/', '', $this->config->get('PRESTADOR_IE', ''));
        $std->CRT   = 1;
        $make->tagEmit($std);

        // ENDER EMIT
        $std          = new \stdClass();
        $std->xLgr    = $this->config->get('PRESTADOR_LOGRADOURO');
        $std->nro     = $this->config->get('PRESTADOR_NUMERO');
        $std->xBairro = $this->config->get('PRESTADOR_BAIRRO');
        $std->cMun    = (int) $this->config->get('PRESTADOR_CODIGO_MUNICIPIO');
        $std->xMun    = $this->config->get('PRESTADOR_MUNICIPIO');
        $std->UF      = $this->config->get('PRESTADOR_UF');
        $std->CEP     = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CEP'));
        $std->cPais   = 1058;
        $std->xPais   = 'Brasil';
        $make->tagenderEmit($std);

        // DEST
        $cpfCnpj = preg_replace('/\D/', '', $cliente['cpf_cnpj'] ?? '');
        $std     = new \stdClass();
        if (strlen($cpfCnpj) === 14) {
            $std->CNPJ = $cpfCnpj;
        } else {
            $std->CPF = $cpfCnpj;
        }
        $std->xNome     = $cliente['razao_social'];
        $std->indIEDest = strlen($cpfCnpj) === 14 ? 1 : 9;
        $make->tagdest($std);

        // ENDER DEST
        $std          = new \stdClass();
        $std->xLgr    = $cliente['logradouro'] ?? '';
        $std->nro     = $cliente['cliente_numero'] ?? 'S/N';
        $std->xBairro = $cliente['bairro'] ?? '';
        $std->cMun    = (int) ($cliente['codigo_municipio'] ?? 9999999);
        $std->xMun    = $cliente['municipio'] ?? '';
        $std->UF      = $uf;
        $std->CEP     = preg_replace('/\D/', '', $cliente['cep'] ?? '');
        $std->cPais   = 1058;
        $std->xPais   = 'Brasil';
        $make->tagenderDest($std);

        // ITENS
        $totalProd = 0.0;
        $totalDesc = 0.0;

        foreach ($itens as $item) {
            $n     = (int) $item['numero_item'];
            $qtd   = (float) $item['quantidade'];
            $vUnit = (float) $item['valor_unitario'];
            $vDesc = (float) ($item['valor_desconto'] ?? 0);
            $vProd = round($qtd * $vUnit, 2);

            $totalProd += $vProd;
            $totalDesc += $vDesc;

            $std           = new \stdClass();
            $std->item     = $n;
            $std->cProd    = !empty($item['codigo_produto'])
                ? $item['codigo_produto']
                : str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $std->cEAN     = 'SEM GTIN';
            $std->xProd    = $item['descricao'];
            $std->NCM      = preg_replace('/\D/', '', $item['ncm']);
            $std->CFOP     = $item['cfop'];
            $std->uCom     = $item['unidade'] ?? 'UN';
            $std->qCom     = $qtd;
            $std->vUnCom   = $vUnit;
            $std->vProd    = $vProd;
            $std->cEANTrib = 'SEM GTIN';
            $std->uTrib    = $item['unidade'] ?? 'UN';
            $std->qTrib    = $qtd;
            $std->vUnTrib  = $vUnit;
            $std->vDesc    = $vDesc > 0 ? $vDesc : null;
            $std->indTot   = 1;
            $make->tagprod($std);

            // ICMS Simples Nacional
            $std        = new \stdClass();
            $std->item  = $n;
            $std->orig  = 0;
            $std->CSOSN = (string) ($item['csosn'] ?? '400');
            $make->tagICMSSN($std);

            // PIS
            $std       = new \stdClass();
            $std->item = $n;
            $std->CST  = (string) ($item['pis_cst'] ?? '07');
            $make->tagPIS($std);

            // COFINS
            $std       = new \stdClass();
            $std->item = $n;
            $std->CST  = (string) ($item['cofins_cst'] ?? '07');
            $make->tagCOFINS($std);

            if (!empty($item['informacoes_adicionais_item'])) {
                $std             = new \stdClass();
                $std->item       = $n;
                $std->infAdProd  = $item['informacoes_adicionais_item'];
                $make->taginfAdProd($std);
            }
        }

        // TOTAL
        $totalNF          = round($totalProd - $totalDesc, 2);
        $std              = new \stdClass();
        $std->vBC         = 0.00;
        $std->vICMS       = 0.00;
        $std->vICMSDeson  = 0.00;
        $std->vFCP        = 0.00;
        $std->vBCST       = 0.00;
        $std->vST         = 0.00;
        $std->vFCPST      = 0.00;
        $std->vFCPSTRet   = 0.00;
        $std->vProd       = round($totalProd, 2);
        $std->vFrete      = 0.00;
        $std->vSeg        = 0.00;
        $std->vDesc       = round($totalDesc, 2);
        $std->vII         = 0.00;
        $std->vIPI        = 0.00;
        $std->vIPIDevol   = 0.00;
        $std->vPIS        = 0.00;
        $std->vCOFINS     = 0.00;
        $std->vOutro      = 0.00;
        $std->vNF         = $totalNF;
        $make->tagICMSTot($std);

        // TRANSP
        $std           = new \stdClass();
        $std->modFrete = 9;
        $make->tagtransp($std);

        // PAG (obrigatório para NF-e modelo 55)
        $std = new \stdClass();
        $make->tagpag($std);

        $std       = new \stdClass();
        $std->tPag = '90';
        $std->vPag = $totalNF;
        $make->tagdetPag($std);

        // INF ADIC
        if (!empty($pedido['informacoes_adicionais'])) {
            $std         = new \stdClass();
            $std->infCpl = $pedido['informacoes_adicionais'];
            $make->taginfAdic($std);
        }

        return $make->getXML();
    }
}
