<?php

declare(strict_types=1);

use EmissorGyn\Config;
use EmissorGyn\NfseClient;
use EmissorGyn\ResponseParser;
use EmissorGyn\Storage;
use EmissorGyn\XmlFactory;

$id        = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$orcamento = $id > 0 ? $cadastro->buscarOrcamento($id) : null;

if ($orcamento === null) {
    http_response_code(404);
    exit('Orçamento não encontrado.');
}

$flash   = null;
$usuario = $auth->usuarioAtual();

// ── DANFS-e via redirect GET (não altera estado) ──────────────────────────────
if (($_GET['acao'] ?? '') === 'danfse' && $orcamento['status'] === 'emitido') {
    $nfse = (string) ($orcamento['nfse_numero'] ?? '');
    if ($nfse !== '') {
        try {
            $cfg     = new Config(dirname(__DIR__, 3));
            $factory = new XmlFactory($cfg);
            $client  = new NfseClient($cfg, $factory);
            $xmlRet  = $client->consultarUrlNfse($nfse);
            $url     = ResponseParser::parseUrlNfse($xmlRet);
            if ($url !== null) {
                header('Location: ' . $url);
                exit;
            }
            $flash = ['tipo' => 'warning', 'msg' => 'URL do DANFS-e não encontrada no retorno do SGISS.'];
        } catch (\Throwable $e) {
            $flash = ['tipo' => 'danger', 'msg' => 'Erro ao obter DANFS-e: ' . $e->getMessage()];
        }
    }
}

// ── Ações POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $acao = trim($_POST['acao'] ?? '');

        if ($acao === 'aprovar' && $orcamento['status'] === 'rascunho') {
            $cadastro->aprovarOrcamento($id, (int) ($usuario['id'] ?? 0));
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'success', 'msg' => 'Orçamento aprovado.'];
        } elseif (
            $acao === 'cancelar'
            && in_array($orcamento['status'], ['rascunho', 'aprovado'], true)
        ) {
            $cadastro->cancelarOrcamento($id);
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'warning', 'msg' => 'Orçamento cancelado.'];
        } elseif ($acao === 'emitir' && $orcamento['status'] === 'aprovado') {
            try {
                $cfg     = new Config(dirname(__DIR__, 3));
                $factory = new XmlFactory($cfg);
                $client  = new NfseClient($cfg, $factory);
                $storage = new Storage($cfg->path('DB_PATH', 'storage/nfse.sqlite'));

                $serie     = $cfg->get('SERIE_RPS', '1');
                $numeroRps = $storage->proximoRps($serie);

                $nota = [
                    'competencia' => $orcamento['competencia'],
                    'servico'     => [
                        'valor_servicos'              => $orcamento['valor_servicos'],
                        'iss_retido'                  => $orcamento['iss_retido'],
                        'item_lista_servico'          => $orcamento['item_lista_servico'],
                        'codigo_cnae'                 => $orcamento['codigo_cnae'] ?? '',
                        'codigo_tributacao_municipio' => $orcamento['codigo_tributacao_municipio'] ?? '',
                        'discriminacao'               => $orcamento['discriminacao'],
                        'exigibilidade_iss'           => $orcamento['exigibilidade_iss'],
                        'aliquota'                    => $orcamento['aliquota'] ?? '',
                        'valor_deducoes'              => $orcamento['valor_deducoes'] ?? '',
                        'valor_pis'                   => $orcamento['valor_pis'] ?? '',
                        'valor_cofins'                => $orcamento['valor_cofins'] ?? '',
                        'valor_inss'                  => $orcamento['valor_inss'] ?? '',
                        'valor_ir'                    => $orcamento['valor_ir'] ?? '',
                        'valor_csll'                  => $orcamento['valor_csll'] ?? '',
                        'desconto_incondicionado'     => $orcamento['desconto_incondicionado'] ?? '',
                        'desconto_condicionado'       => $orcamento['desconto_condicionado'] ?? '',
                    ],
                    'tomador' => [
                        'cpf_cnpj'     => $orcamento['cpf_cnpj'],
                        'razao_social' => $orcamento['razao_social'],
                        'email'        => $orcamento['cliente_email'] ?? '',
                        'telefone'     => $orcamento['telefone'] ?? '',
                        'endereco'     => [
                            'logradouro'       => $orcamento['logradouro'] ?? '',
                            'numero'           => $orcamento['numero'] ?? '',
                            'complemento'      => $orcamento['complemento'] ?? '',
                            'bairro'           => $orcamento['bairro'] ?? '',
                            'codigo_municipio' => $orcamento['codigo_municipio'] ?? '',
                            'uf'               => $orcamento['uf'] ?? '',
                            'cep'              => $orcamento['cep'] ?? '',
                        ],
                    ],
                ];

                $registroId = $storage->registrarEnvio(
                    $numeroRps,
                    $serie,
                    $orcamento['valor_servicos'],
                    $orcamento['cpf_cnpj'],
                    $orcamento['razao_social'],
                    ''
                );

                try {
                    $retorno = $client->gerarNfse($numeroRps, $nota);
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
                    $cadastro->emitirOrcamento($id, $registroId, (string) $res['nfse_numero']);
                    $orcamento = $cadastro->buscarOrcamento($id);
                    $flash     = [
                        'tipo' => 'success',
                        'msg'  => 'NFS-e emitida! Número: ' . $res['nfse_numero'],
                    ];
                } else {
                    $erroMsg = ResponseParser::formatarErros($res['erros']);
                    $storage->registrarErro($registroId, $erroMsg, $retorno);
                    $flash = ['tipo' => 'danger', 'msg' => 'Erro na emissão: ' . $erroMsg];
                }
            } catch (\Throwable $e) {
                $flash = ['tipo' => 'danger', 'msg' => 'Erro: ' . $e->getMessage()];
            }
        }
    }
}

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
$status    = $orcamento['status'];
$pageTitle = 'Orçamento #' . $id;
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        Orçamento #<?= $id ?>
        <span class="badge bg-<?= $statusCores[$status] ?? 'secondary' ?> fs-6">
            <?= h($status) ?>
        </span>
    </h2>
    <a href="?p=orcamentos" class="btn btn-outline-secondary">← Voltar</a>
</div>

<?php if (!empty($flash)) : ?>
<div class="alert alert-<?= h($flash['tipo']) ?> alert-dismissible fade show">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Cliente</strong></div>
            <div class="card-body">
                <p class="mb-1"><strong><?= h($orcamento['razao_social']) ?></strong></p>
                <p class="mb-1 text-muted"><?= h($orcamento['cpf_cnpj']) ?></p>
                <?php if (!empty($orcamento['cliente_email'])) : ?>
                <p class="mb-1"><?= h($orcamento['cliente_email']) ?></p>
                <?php endif; ?>
                <?php if (!empty($orcamento['logradouro'])) : ?>
                <p class="mb-0 small text-muted">
                    <?= h($orcamento['logradouro']) ?>, <?= h($orcamento['numero'] ?? '') ?>
                    <?= !empty($orcamento['bairro']) ? ' — ' . h($orcamento['bairro']) : '' ?>
                    / <?= h($orcamento['uf'] ?? '') ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Serviço</strong></div>
            <div class="card-body">
                <p class="mb-1">
                    <strong>Competência:</strong> <?= h($orcamento['competencia']) ?>
                </p>
                <p class="mb-1">
                    <strong>Valor:</strong>
                    R$ <?= h(number_format((float) $orcamento['valor_servicos'], 2, ',', '.')) ?>
                </p>
                <p class="mb-1">
                    <strong>Item Lista:</strong> <?= h($orcamento['item_lista_servico']) ?>
                    <?php if (!empty($orcamento['codigo_cnae'])) : ?>
                    | <strong>CNAE:</strong> <?= h($orcamento['codigo_cnae']) ?>
                    <?php endif; ?>
                </p>
                <p class="mb-1">
                    <strong>ISS Retido:</strong>
                    <?= (int) $orcamento['iss_retido'] === 1 ? 'Sim' : 'Não' ?>
                    <?php if (!empty($orcamento['aliquota'])) : ?>
                    | <strong>Alíquota:</strong> <?= h($orcamento['aliquota']) ?>%
                    <?php endif; ?>
                </p>
                <p class="mb-0 small text-muted">
                    <?= nl2br(h($orcamento['discriminacao'])) ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($orcamento['status'] === 'emitido' && !empty($orcamento['nfse_numero'])) : ?>
<div class="alert alert-success mt-4">
    <strong>NFS-e emitida:</strong> <?= h($orcamento['nfse_numero']) ?>
    <a href="?p=orcamentos/ver&amp;id=<?= $id ?>&amp;acao=danfse"
       class="btn btn-sm btn-success ms-3">
        Abrir DANFS-e Oficial
    </a>
</div>
<?php endif; ?>

<div class="mt-4 d-flex gap-2 flex-wrap">
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

        <?php if ($status === 'rascunho') : ?>
        <a href="?p=orcamentos/form&amp;id=<?= $id ?>"
           class="btn btn-outline-primary">Editar</a>
        <button type="submit" name="acao" value="aprovar"
                class="btn btn-warning">Aprovar</button>
        <button type="submit" name="acao" value="cancelar"
                class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>

        <?php elseif ($status === 'aprovado') : ?>
        <button type="submit" name="acao" value="emitir"
                class="btn btn-success"
                onclick="return confirm('Emitir NFS-e agora? Esta ação não pode ser desfeita.')">
            Emitir NFS-e
        </button>
        <button type="submit" name="acao" value="cancelar"
                class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>
        <?php endif; ?>
    </form>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
