<?php

declare(strict_types=1);

/**
 * API HTTP JSON do Emissor NFS-e Goiânia.
 *
 * Suba com o servidor embutido do PHP (uso local/rede interna):
 *   php -S 127.0.0.1:8080 public/api.php
 *
 * Endpoints (todos exigem header "Authorization: Bearer <API_TOKEN>"):
 *   POST /emitir            corpo = JSON da nota (mesmo formato de examples/nota.json)
 *   GET  /notas?inicio=AAAA-MM-DD&fim=AAAA-MM-DD
 *   GET  /rps/{numero}      consulta NFS-e pelo número do RPS
 *   POST /cancelar          {"nfse": "123", "codigo": "1"}
 *   GET  /url/{nfse}        URL de impressão (DANFS-e)
 *   GET  /historico?limite=20
 */

use EmissorGyn\Config;
use EmissorGyn\NfseClient;
use EmissorGyn\ResponseParser;
use EmissorGyn\Storage;
use EmissorGyn\XmlFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $config = new Config();

    // --- autenticação simples por token ---
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if ($token === '' || !hash_equals($config->get('API_TOKEN'), $token)) {
        json_out(['erro' => 'não autorizado'], 401);
    }

    $factory = new XmlFactory($config);
    $storage = new Storage($config->path('DB_PATH', 'storage/nfse.sqlite'));

    $metodo = $_SERVER['REQUEST_METHOD'];
    $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $partes = array_values(array_filter(explode('/', $caminho)));
    $rota = $partes[0] ?? '';

    switch (true) {
        case $metodo === 'POST' && $rota === 'emitir': {
            $dados = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
            $client = new NfseClient($config, $factory);
            $serie = $config->get('SERIE_RPS', '1');
            $numeroRps = $storage->proximoRps($serie);

            $tomador = $dados['tomador'] ?? [];
            $registroId = $storage->registrarEnvio(
                $numeroRps,
                $serie,
                (string) ($dados['servico']['valor_servicos'] ?? $dados['valor_servicos'] ?? ''),
                (string) ($tomador['cpf_cnpj'] ?? ''),
                (string) ($tomador['razao_social'] ?? ''),
                ''
            );

            try {
                $retorno = $client->gerarNfse($numeroRps, $dados);
            } catch (\Throwable $e) {
                $storage->registrarErro($registroId, $e->getMessage());
                json_out(['erro' => $e->getMessage(), 'rps' => $numeroRps], 502);
            }

            $res = ResponseParser::parseGerarNfse($retorno);
            if ($res['sucesso']) {
                $storage->registrarSucesso($registroId, (string) $res['nfse_numero'], (string) $res['codigo_verificacao'], $retorno);
                json_out([
                    'sucesso' => true,
                    'rps' => $numeroRps,
                    'nfse_numero' => $res['nfse_numero'],
                    'codigo_verificacao' => $res['codigo_verificacao'],
                    'data_emissao' => $res['data_emissao'],
                ]);
            }
            $storage->registrarErro($registroId, ResponseParser::formatarErros($res['erros']), $retorno);
            json_out(['sucesso' => false, 'rps' => $numeroRps, 'erros' => $res['erros']], 422);
        }

        case $metodo === 'GET' && $rota === 'notas': {
            $inicio = $_GET['inicio'] ?? json_out(['erro' => 'parâmetro inicio obrigatório'], 400);
            $fim = $_GET['fim'] ?? json_out(['erro' => 'parâmetro fim obrigatório'], 400);
            $client = new NfseClient($config, $factory);
            $xml = $client->consultarServicoPrestado((string) $inicio, (string) $fim, (int) ($_GET['pagina'] ?? 1));
            json_out(['xml' => $xml]);
        }

        case $metodo === 'GET' && $rota === 'rps' && isset($partes[1]): {
            $client = new NfseClient($config, $factory);
            $retorno = $client->consultarNfsePorRps((int) $partes[1]);
            $res = ResponseParser::parseGerarNfse($retorno);
            json_out($res + ['xml' => $retorno]);
        }

        case $metodo === 'POST' && $rota === 'cancelar': {
            $body = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
            $nfse = (string) ($body['nfse'] ?? json_out(['erro' => 'campo nfse obrigatório'], 400));
            $client = new NfseClient($config, $factory);
            $retorno = $client->cancelarNfse($nfse, (string) ($body['codigo'] ?? '1'));
            json_out(ResponseParser::parseCancelamento($retorno) + ['xml' => $retorno]);
        }

        case $metodo === 'GET' && $rota === 'url' && isset($partes[1]): {
            $client = new NfseClient($config, $factory);
            json_out(['retorno' => $client->consultarUrlNfse($partes[1])]);
        }

        case $metodo === 'GET' && $rota === 'historico': {
            json_out(['emissoes' => $storage->listar((int) ($_GET['limite'] ?? 20))]);
        }

        default:
            json_out(['erro' => 'rota não encontrada'], 404);
    }
} catch (\JsonException $e) {
    json_out(['erro' => 'JSON inválido: ' . $e->getMessage()], 400);
} catch (\Throwable $e) {
    json_out(['erro' => $e->getMessage()], 500);
}
