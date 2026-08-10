#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI do Emissor NFS-e Goiânia (SGISS / ABRASF 2.04 / ISSNet)
 *
 * Uso:
 *   bin/nfse emitir --arquivo nota.json [--dry-run]
 *   bin/nfse consultar-rps --numero 15
 *   bin/nfse listar [--limite 20]
 *   bin/nfse notas --inicio 2026-07-01 --fim 2026-07-31 [--pagina 1]
 *   bin/nfse cancelar --nfse 123 [--codigo 1]
 *   bin/nfse url --nfse 123
 *   bin/nfse rps-disponivel
 *   bin/nfse dados-cadastrais
 *   bin/nfse set-rps --numero 100        (ajusta o contador local de RPS)
 */

use EmissorGyn\Config;
use EmissorGyn\NfseClient;
use EmissorGyn\ResponseParser;
use EmissorGyn\Storage;
use EmissorGyn\XmlFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

function argOpt(array $argv, string $nome, ?string $default = null): ?string
{
    foreach ($argv as $i => $a) {
        if ($a === "--{$nome}") {
            return $argv[$i + 1] ?? $default;
        }
        if (str_starts_with($a, "--{$nome}=")) {
            return substr($a, strlen($nome) + 3);
        }
    }
    return $default;
}

function flag(array $argv, string $nome): bool
{
    return in_array("--{$nome}", $argv, true);
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERRO: {$msg}" . PHP_EOL);
    exit($code);
}

$comando = $argv[1] ?? 'ajuda';

try {
    $config = new Config();
    $factory = new XmlFactory($config);
    $storage = new Storage($config->path('DB_PATH', 'storage/nfse.sqlite'));

    switch ($comando) {
        case 'emitir': {
            $arquivo = argOpt($argv, 'arquivo') ?? fail('informe --arquivo nota.json');
            if (!is_file($arquivo)) {
                fail("arquivo não encontrado: {$arquivo}");
            }
            $dados = json_decode((string) file_get_contents($arquivo), true, 32, JSON_THROW_ON_ERROR);

            $client = new NfseClient($config, $factory);
            $serie = $config->get('SERIE_RPS', '1');

            if (flag($argv, 'dry-run')) {
                // Gera e assina sem consumir numeração nem enviar
                $xml = $client->gerarXmlSemEnviar(999999, $dados);
                out('--- XML assinado (dry-run, RPS fictício 999999) ---');
                out($xml);
                break;
            }

            $numeroRps = $storage->proximoRps($serie);
            out("RPS reservado: {$numeroRps} (série {$serie})");

            $xmlAssinado = null;
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
                $retorno = $client->gerarNfse($numeroRps, $dados, $xmlAssinado);
            } catch (\Throwable $e) {
                $storage->registrarErro($registroId, $e->getMessage());
                throw $e;
            }

            $res = ResponseParser::parseGerarNfse($retorno);
            if ($res['sucesso']) {
                $storage->registrarSucesso(
                    $registroId,
                    (string) $res['nfse_numero'],
                    (string) $res['codigo_verificacao'],
                    $retorno
                );
                out('NFS-e AUTORIZADA!');
                out("  Número:              {$res['nfse_numero']}");
                out("  Código verificação:  {$res['codigo_verificacao']}");
                out("  Data de emissão:     {$res['data_emissao']}");
            } else {
                $erros = ResponseParser::formatarErros($res['erros']);
                $storage->registrarErro($registroId, $erros ?: 'retorno sem número de NFS-e', $retorno);
                fail("emissão rejeitada pelo SGISS:" . PHP_EOL . ($erros ?: $retorno));
            }
            break;
        }

        case 'consultar-rps': {
            $numero = (int) (argOpt($argv, 'numero') ?? fail('informe --numero'));
            $client = new NfseClient($config, $factory);
            $retorno = $client->consultarNfsePorRps($numero);
            $res = ResponseParser::parseGerarNfse($retorno);
            if ($res['nfse_numero'] !== null) {
                out("NFS-e: {$res['nfse_numero']} | Verificação: {$res['codigo_verificacao']} | Emissão: {$res['data_emissao']}");
            } elseif (!empty($res['erros'])) {
                out(ResponseParser::formatarErros($res['erros']));
            } else {
                out($retorno);
            }
            break;
        }

        case 'listar': {
            $limite = (int) (argOpt($argv, 'limite') ?? '20');
            foreach ($storage->listar($limite) as $r) {
                out(sprintf(
                    '#%d %s | RPS %s/%s | %s | NFS-e %s | R$ %s | %s %s',
                    $r['id'],
                    $r['criado_em'],
                    $r['rps_numero'],
                    $r['rps_serie'],
                    strtoupper((string) $r['status']),
                    $r['nfse_numero'] ?? '-',
                    $r['valor_servicos'] ?? '-',
                    $r['tomador_doc'] ?? '',
                    $r['tomador_razao'] ?? ''
                ));
            }
            break;
        }

        case 'notas': {
            $inicio = argOpt($argv, 'inicio') ?? fail('informe --inicio AAAA-MM-DD');
            $fim = argOpt($argv, 'fim') ?? fail('informe --fim AAAA-MM-DD');
            $pagina = (int) (argOpt($argv, 'pagina') ?? '1');
            $client = new NfseClient($config, $factory);
            out($client->consultarServicoPrestado($inicio, $fim, $pagina));
            break;
        }

        case 'cancelar': {
            $nfse = argOpt($argv, 'nfse') ?? fail('informe --nfse <numero>');
            $codigo = argOpt($argv, 'codigo') ?? '1';
            out('ATENÇÃO: cancelamento via WebService só é aceito até o 5º dia do mês');
            out('subsequente à emissão; após isso, é preciso processo administrativo (SEI).');
            $client = new NfseClient($config, $factory);
            $retorno = $client->cancelarNfse($nfse, $codigo);
            $res = ResponseParser::parseCancelamento($retorno);
            if ($res['sucesso']) {
                out("NFS-e {$nfse} CANCELADA em {$res['data_hora']}");
            } else {
                fail('cancelamento rejeitado:' . PHP_EOL . (ResponseParser::formatarErros($res['erros']) ?: $retorno));
            }
            break;
        }

        case 'url': {
            $nfse = argOpt($argv, 'nfse') ?? fail('informe --nfse <numero>');
            $client = new NfseClient($config, $factory);
            out($client->consultarUrlNfse($nfse));
            break;
        }

        case 'rps-disponivel': {
            $client = new NfseClient($config, $factory);
            out($client->consultarRpsDisponivel());
            break;
        }

        case 'dados-cadastrais': {
            $client = new NfseClient($config, $factory);
            out($client->consultarDadosCadastrais());
            break;
        }

        case 'set-rps': {
            $numero = (int) (argOpt($argv, 'numero') ?? fail('informe --numero'));
            $serie = $config->get('SERIE_RPS', '1');
            $storage->definirUltimoRps($serie, $numero);
            out("Contador local ajustado: último RPS da série {$serie} = {$numero}");
            break;
        }

        default:
            out('Emissor NFS-e Goiânia (SGISS / ABRASF 2.04)');
            out('');
            out('Comandos:');
            out('  emitir --arquivo nota.json [--dry-run]   Emite uma NFS-e (GerarNfse)');
            out('  consultar-rps --numero N                 Consulta NFS-e pelo RPS');
            out('  listar [--limite 20]                     Histórico local de emissões');
            out('  notas --inicio D --fim D [--pagina N]    NFS-e emitidas no período');
            out('  cancelar --nfse N [--codigo 1]           Cancela NFS-e (1=erro, 2=não prestado, 4=duplicidade)');
            out('  url --nfse N                             URL de impressão da NFS-e (DANFS-e)');
            out('  rps-disponivel                           Faixa de RPS liberada pelo SGISS');
            out('  dados-cadastrais                         Dados cadastrais do prestador');
            out('  set-rps --numero N                       Ajusta o contador local de RPS');
    }
} catch (\Throwable $e) {
    fail($e->getMessage());
}
