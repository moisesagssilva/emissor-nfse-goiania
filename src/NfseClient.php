<?php

declare(strict_types=1);

namespace EmissorGyn;

use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

/**
 * Cliente do WebService ABRASF 2.04 do SGISS de Goiânia (provedor ISSNet).
 *
 * Contrato confirmado no WSDL de produção
 * (https://nfse.issnetonline.com.br/abrasf204/goiania/nfse.asmx):
 *   - namespace das operações: http://nfse.abrasf.org.br
 *   - entrada: nfseCabecMsg + nfseDadosMsg (strings XML)
 *   - saída:   outputXML
 *   - SOAPAction: http://nfse.abrasf.org.br/{Operacao}
 *   - SOAP 1.1, document/literal
 */
final class NfseClient
{
    private const SOAP_NS = 'http://nfse.abrasf.org.br';

    private Certificate $certificate;
    private string $wsUrl;
    private int $timeout;
    private bool $clientCert;
    private string $xmlDir;

    public function __construct(
        private readonly Config $config,
        private readonly XmlFactory $factory,
    ) {
        $certPath = $config->get('CERT_PATH');
        if (!is_file($certPath)) {
            throw new \RuntimeException("Certificado não encontrado: {$certPath}");
        }
        $this->certificate = Certificate::readPfx(
            (string) file_get_contents($certPath),
            $config->get('CERT_PASS')
        );
        if ($this->certificate->isExpired()) {
            throw new \RuntimeException('O certificado digital está VENCIDO. Renove o e-CNPJ A1 antes de emitir.');
        }
        $this->wsUrl = $config->get('WS_URL', 'https://nfse.issnetonline.com.br/abrasf204/goiania/nfse.asmx');
        $this->timeout = $config->getInt('WS_TIMEOUT', 60);
        $this->clientCert = $config->getBool('WS_CLIENT_CERT', false);
        $this->xmlDir = $config->path('XML_DIR', 'storage/xml');
        if (!is_dir($this->xmlDir)) {
            mkdir($this->xmlDir, 0770, true);
        }
    }

    // ------------------------------------------------------------ operações

    /**
     * Emite uma NFS-e (GerarNfse, síncrono).
     * Assina o RPS (InfDeclaracaoPrestacaoServico) com o certificado A1.
     *
     * @return string XML de retorno (outputXML) do SGISS
     */
    public function gerarNfse(int $numeroRps, array $dados, ?string &$xmlAssinado = null): string
    {
        $xml = $this->factory->gerarNfseEnvio($numeroRps, $dados);
        $xmlAssinado = Signer::sign(
            $this->certificate,
            $xml,
            'InfDeclaracaoPrestacaoServico',
            'Id',
            OPENSSL_ALGO_SHA1,
            [false, false, null, null],
            'Rps'
        );
        $this->salvarXml("rps{$numeroRps}-envio.xml", $xmlAssinado);
        $retorno = $this->send('GerarNfse', $xmlAssinado);
        $this->salvarXml("rps{$numeroRps}-retorno.xml", $retorno);
        return $retorno;
    }

    public function consultarNfsePorRps(int $numeroRps): string
    {
        return $this->send('ConsultarNfsePorRps', $this->factory->consultarNfsePorRpsEnvio($numeroRps));
    }

    public function consultarServicoPrestado(string $dataInicial, string $dataFinal, int $pagina = 1): string
    {
        return $this->send(
            'ConsultarNfseServicoPrestado',
            $this->factory->consultarServicoPrestadoEnvio($dataInicial, $dataFinal, $pagina)
        );
    }

    public function cancelarNfse(string $numeroNfse, string $codigo = '1'): string
    {
        $xml = $this->factory->cancelarNfseEnvio($numeroNfse, $codigo);
        $assinado = Signer::sign(
            $this->certificate,
            $xml,
            'InfPedidoCancelamento',
            'Id',
            OPENSSL_ALGO_SHA1,
            [false, false, null, null],
            'Pedido'
        );
        $this->salvarXml("cancelamento-{$numeroNfse}-envio.xml", $assinado);
        $retorno = $this->send('CancelarNfse', $assinado);
        $this->salvarXml("cancelamento-{$numeroNfse}-retorno.xml", $retorno);
        return $retorno;
    }

    public function consultarUrlNfse(string $numeroNfse): string
    {
        return $this->send('ConsultarUrlNfse', $this->factory->consultarUrlNfseEnvio($numeroNfse));
    }

    public function consultarRpsDisponivel(): string
    {
        return $this->send('ConsultarRpsDisponivel', $this->factory->consultarRpsDisponivelEnvio());
    }

    public function consultarDadosCadastrais(): string
    {
        return $this->send('ConsultarDadosCadastrais', $this->factory->consultarDadosCadastraisEnvio());
    }

    /** Gera e assina o XML sem enviar (validação local / conferência). */
    public function gerarXmlSemEnviar(int $numeroRps, array $dados): string
    {
        $xml = $this->factory->gerarNfseEnvio($numeroRps, $dados);
        return Signer::sign(
            $this->certificate,
            $xml,
            'InfDeclaracaoPrestacaoServico',
            'Id',
            OPENSSL_ALGO_SHA1,
            [false, false, null, null],
            'Rps'
        );
    }

    // ------------------------------------------------------------ transporte

    /**
     * Envia a mensagem SOAP 1.1 e devolve o conteúdo de <outputXML>.
     */
    private function send(string $operation, string $dadosXml): string
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<' . $operation . ' xmlns="' . self::SOAP_NS . '">'
            . '<nfseCabecMsg>' . htmlspecialchars($this->factory->cabecalho(), ENT_XML1, 'UTF-8') . '</nfseCabecMsg>'
            . '<nfseDadosMsg>' . htmlspecialchars($dadosXml, ENT_XML1, 'UTF-8') . '</nfseDadosMsg>'
            . '</' . $operation . '>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . self::SOAP_NS . '/' . $operation . '"',
            'Content-Length: ' . strlen($envelope),
        ];

        $ch = curl_init($this->wsUrl);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $tmpFiles = [];
        if ($this->clientCert) {
            // Alguns provedores exigem TLS mútuo; o ISSNet normalmente não.
            $certPem = tempnam(sys_get_temp_dir(), 'crt');
            $keyPem = tempnam(sys_get_temp_dir(), 'key');
            file_put_contents($certPem, (string) $this->certificate->publicKey);
            file_put_contents($keyPem, (string) $this->certificate->privateKey);
            chmod($keyPem, 0600);
            $opts[CURLOPT_SSLCERT] = $certPem;
            $opts[CURLOPT_SSLKEY] = $keyPem;
            $tmpFiles = [$certPem, $keyPem];
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        foreach ($tmpFiles as $f) {
            @unlink($f);
        }

        if ($response === false) {
            throw new \RuntimeException("Falha de comunicação com o SGISS: {$curlErr}");
        }
        if ($httpCode >= 500) {
            throw new \RuntimeException("Erro HTTP {$httpCode} do SGISS. Resposta: " . substr((string) $response, 0, 1000));
        }

        return $this->extractOutputXml((string) $response, $operation);
    }

    /** Extrai e decodifica o conteúdo de <outputXML> do envelope de resposta. */
    private function extractOutputXml(string $soapResponse, string $operation): string
    {
        $dom = new \DOMDocument();
        $ok = @$dom->loadXML($soapResponse, LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$ok) {
            throw new \RuntimeException('Resposta SOAP inválida: ' . substr($soapResponse, 0, 500));
        }

        // SOAP Fault?
        $faults = $dom->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault');
        if ($faults->length > 0) {
            throw new \RuntimeException("SOAP Fault em {$operation}: " . trim($faults->item(0)->textContent));
        }

        $nodes = $dom->getElementsByTagName('outputXML');
        if ($nodes->length === 0) {
            // fallback: alguns servidores devolvem {Operacao}Result
            $nodes = $dom->getElementsByTagName($operation . 'Result');
        }
        if ($nodes->length === 0) {
            throw new \RuntimeException("Resposta sem outputXML em {$operation}: " . substr($soapResponse, 0, 500));
        }
        return trim($nodes->item(0)->textContent);
    }

    private function salvarXml(string $nome, string $conteudo): void
    {
        @file_put_contents($this->xmlDir . '/' . date('Ymd-His') . '-' . $nome, $conteudo);
    }
}
