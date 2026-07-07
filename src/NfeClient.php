<?php

declare(strict_types=1);

namespace EmissorGyn;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;

final class NfeClient
{
    public function __construct(
        private readonly Config $config,
        private readonly NfeXmlFactory $factory
    ) {
    }

    public function emitir(
        array $pedido,
        array $itens,
        array $cliente,
        int $nNF,
        string $serie
    ): array {
        $cNF   = str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
        $xml   = $this->factory->build($pedido, $itens, $cliente, $nNF, $serie, $cNF);
        $tools = $this->buildTools();
        $tools->model(55);

        $signed  = $tools->signNFe($xml);
        $idLote  = str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT);
        $resp    = $tools->sefazEnviaLote([$signed], $idLote, 1);

        $dom = new \DOMDocument();
        $dom->loadXML($resp);

        $infProt = $dom->getElementsByTagName('infProt')->item(0);
        if ($infProt === null) {
            throw new \RuntimeException('Resposta SEFAZ sem infProt: ' . substr($resp, 0, 500));
        }
        $cStat   = $infProt->getElementsByTagName('cStat')->item(0)?->textContent ?? '';
        $xMotivo = $infProt->getElementsByTagName('xMotivo')->item(0)?->textContent ?? '';
        if ($cStat !== '100') {
            throw new \RuntimeException("SEFAZ rejeitou NF-e (cStat={$cStat}): {$xMotivo}");
        }
        $nProt  = $infProt->getElementsByTagName('nProt')->item(0)?->textContent ?? '';
        $chNFe  = $infProt->getElementsByTagName('chNFe')->item(0)?->textContent ?? '';

        $xmlAutorizado = Complements::toAuthorize($signed, $resp);

        return [
            'chave'          => $chNFe,
            'numero'         => $nNF,
            'protocolo'      => $nProt,
            'xml_autorizado' => $xmlAutorizado,
        ];
    }

    public function cancelar(string $chave, string $xJust, string $nProt): string
    {
        if (strlen($xJust) < 15) {
            throw new \InvalidArgumentException('Justificativa deve ter no mínimo 15 caracteres.');
        }
        $tools = $this->buildTools();
        $tools->model(55);
        $resp = $tools->sefazCancela($chave, $xJust, $nProt);

        $dom = new \DOMDocument();
        $dom->loadXML($resp);
        $cStat   = $dom->getElementsByTagName('cStat')->item(0)?->textContent ?? '';
        $xMotivo = $dom->getElementsByTagName('xMotivo')->item(0)?->textContent ?? '';

        if (!in_array($cStat, ['101', '155'], true)) {
            throw new \RuntimeException("Cancelamento rejeitado (cStat={$cStat}): {$xMotivo}");
        }

        $nProtCanc = $dom->getElementsByTagName('nProt')->item(0)?->textContent ?? '';
        return $nProtCanc;
    }

    private function buildTools(): Tools
    {
        $certPath = $this->config->path('CERT_PATH', '');
        $certPass = $this->config->get('CERT_PASS');
        $cnpj     = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CNPJ'));
        $tpAmb    = $this->config->getInt('NFE_AMBIENTE', 2);

        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new \RuntimeException("Certificado não encontrado em: {$certPath}");
        }
        $certificate = Certificate::readPfx($certContent, $certPass);

        $configJson = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => $tpAmb,
            'razaoSocial' => $this->config->get('PRESTADOR_RAZAO_SOCIAL'),
            'siglaUF'     => 'GO',
            'tokenIBPT'   => '',
            'CSC'         => '',
            'CSCid'       => '',
            'cnpj'        => $cnpj,
            'CPF'         => '',
            'schemes'     => 'PL_009_V4',
            'versao'      => '4.00',
        ], JSON_THROW_ON_ERROR);

        return new Tools($configJson, $certificate);
    }
}
