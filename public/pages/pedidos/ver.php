<?php

declare(strict_types=1);

// ── Early DANFE path — must run before any HTML output ────────────────────────
if (($_GET['acao'] ?? '') === 'danfe') {
    $danfeId     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $danfePedido = $danfeId > 0 ? $nfeStorage->buscarPedido($danfeId) : null;
    if (
        $danfePedido !== null
        && $danfePedido['status'] === 'emitido'
        && $danfePedido['nfe_xml_autorizado'] !== null
    ) {
        $xml      = (string) $danfePedido['nfe_xml_autorizado'];
        $danfe    = new NFePHP\DA\NFe\Danfe($xml);
        $logoPath = $config->path('LOGO_PATH', '');
        header('Content-Type: application/pdf');
        header(
            'Content-Disposition: inline; filename="nfe-' . $danfePedido['nfe_chave'] . '.pdf"'
        );
        echo $danfe->render(is_file($logoPath) ? $logoPath : '');
        exit;
    }
    exit('XML autorizado não encontrado.');
}

// ── Load order ────────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id === 0) {
    header('Location: ?p=pedidos');
    exit;
}

$pedido = $nfeStorage->buscarPedido($id);
if ($pedido === null) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}

$flash = null;

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $acao    = (string) ($_POST['acao'] ?? '');
        $usuario = $auth->usuarioAtual();
        $uid     = (int) ($usuario['id'] ?? 0);

        if ($acao === 'aprovar' && $pedido['status'] === 'rascunho') {
            $nfeStorage->aprovarPedido($id, $uid);
            $pedido = $nfeStorage->buscarPedido($id);
            $flash  = ['tipo' => 'success', 'msg' => 'Pedido aprovado.'];
        } elseif (
            $acao === 'cancelar_rascunho'
            && in_array($pedido['status'], ['rascunho', 'aprovado'], true)
        ) {
            $nfeStorage->cancelarPedido($id);
            $pedido = $nfeStorage->buscarPedido($id);
            $flash  = ['tipo' => 'warning', 'msg' => 'Pedido cancelado.'];
        } elseif ($acao === 'emitir' && $pedido['status'] === 'aprovado') {
            $serie = $config->get('NFE_SERIE', '1');
            $nNF   = $nfeStorage->proximoNfe($serie);
            $itens = $nfeStorage->listarItens($id);
            try {
                $resultado = $nfeClient->emitir($pedido, $itens, $pedido, $nNF, $serie);
                $nfeStorage->emitirPedido(
                    $id,
                    $resultado['chave'],
                    $resultado['numero'],
                    $serie,
                    $resultado['protocolo'],
                    $resultado['xml_autorizado']
                );
                $pedido = $nfeStorage->buscarPedido($id);
                $flash  = [
                    'tipo' => 'success',
                    'msg'  => 'NF-e autorizada! Chave: ' . $resultado['chave'],
                ];
            } catch (\Throwable $e) {
                // Safe to roll back only if SEFAZ did not authorize (cStat≠100).
                // If the exception occurred after SEFAZ authorization (e.g., during
                // Complements::toAuthorize or the DB write), the sequence counter
                // may be out of sync. In that case, recover manually via SEFAZ portal.
                $nfeStorage->definirUltimoNfe($serie, $nNF - 1);
                $flash = ['tipo' => 'danger', 'msg' => 'Erro ao emitir: ' . $e->getMessage()];
            }
        } elseif ($acao === 'cancelar_nfe' && $pedido['status'] === 'emitido') {
            $xJust = trim((string) ($_POST['xJust'] ?? ''));
            if (strlen($xJust) < 15) {
                $flash = [
                    'tipo' => 'danger',
                    'msg'  => 'Justificativa deve ter no mínimo 15 caracteres.',
                ];
            } else {
                try {
                    $protocolo = $nfeClient->cancelar(
                        (string) $pedido['nfe_chave'],
                        $xJust,
                        (string) $pedido['nfe_protocolo']
                    );
                    $nfeStorage->registrarEvento(
                        $id,
                        'cancelamento',
                        $protocolo,
                        $xJust,
                        '',
                        ''
                    );
                    $nfeStorage->cancelarPedido($id);
                    $pedido = $nfeStorage->buscarPedido($id);
                    $flash  = [
                        'tipo' => 'warning',
                        'msg'  => 'NF-e cancelada na SEFAZ. Protocolo: ' . $protocolo,
                    ];
                } catch (\Throwable $e) {
                    $flash = [
                        'tipo' => 'danger',
                        'msg'  => 'Erro ao cancelar NF-e: ' . $e->getMessage(),
                    ];
                }
            }
        }
    }
}

// ── Prepare view data ─────────────────────────────────────────────────────────
$itens   = $nfeStorage->listarItens($id);
$eventos = $nfeStorage->listarEventos($id);

$totalGeral = array_reduce($itens, static function (float $carry, array $item): float {
    return $carry
        + (float) $item['quantidade'] * (float) $item['valor_unitario']
        - (float) ($item['valor_desconto'] ?? 0);
}, 0.0);

$statusCor = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];

$pageTitle = 'Pedido NF-e #' . $id;
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        Pedido #<?= $id ?>
        <span class="badge bg-<?= h($statusCor[$pedido['status']] ?? 'secondary') ?> fs-6 ms-2">
            <?= ucfirst(h((string) $pedido['status'])) ?>
        </span>
    </h2>
    <div class="d-flex gap-2">
        <a href="?p=pedidos" class="btn btn-outline-secondary">&#8592; Pedidos</a>
        <?php if ($pedido['status'] === 'rascunho') : ?>
        <a href="?p=pedidos/form&amp;id=<?= $id ?>" class="btn btn-outline-primary">Editar</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Cliente</strong></div>
            <div class="card-body">
                <p class="mb-1"><strong><?= h((string) $pedido['razao_social']) ?></strong></p>
                <p class="mb-1 text-muted"><?= h((string) $pedido['cpf_cnpj']) ?></p>
                <?php if (!empty($pedido['logradouro'])) : ?>
                <p class="mb-1 small">
                    <?= h((string) $pedido['logradouro']) ?>, <?= h((string) ($pedido['cliente_numero'] ?? '')) ?>
                    <?php if (!empty($pedido['complemento'])) : ?>
                    &#8212; <?= h((string) $pedido['complemento']) ?>
                    <?php endif; ?><br>
                    <?= h((string) ($pedido['bairro'] ?? '')) ?>
                    &#8212; <?= h((string) ($pedido['uf'] ?? '')) ?>
                    <?= h((string) ($pedido['cep'] ?? '')) ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($pedido['cliente_email'])) : ?>
                <p class="mb-0 small text-muted"><?= h((string) $pedido['cliente_email']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Dados da Operação</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Natureza</dt>
                    <dd class="col-sm-7"><?= h((string) $pedido['natureza_operacao']) ?></dd>
                    <dt class="col-sm-5">Presença</dt>
                    <dd class="col-sm-7"><?= (int) $pedido['presenca'] ?></dd>
                    <dt class="col-sm-5">Consumidor final</dt>
                    <dd class="col-sm-7"><?= $pedido['consumidor_final'] ? 'Sim' : 'Não' ?></dd>
                    <?php if (!empty($pedido['nfe_chave'])) : ?>
                    <dt class="col-sm-5">Chave NF-e</dt>
                    <dd class="col-sm-7 text-break">
                        <code class="small"><?= h(implode(' ', str_split((string) $pedido['nfe_chave'], 4))) ?></code>
                    </dd>
                    <dt class="col-sm-5">Protocolo</dt>
                    <dd class="col-sm-7"><?= h((string) ($pedido['nfe_protocolo'] ?? '')) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($pedido['informacoes_adicionais'])) : ?>
                    <dt class="col-sm-5">Inf. Adicionais</dt>
                    <dd class="col-sm-7"><?= h((string) $pedido['informacoes_adicionais']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<h5>Itens</h5>
<table class="table table-sm table-hover mb-4">
    <thead>
    <tr>
        <th>#</th><th>Descrição</th><th>NCM</th><th>CFOP</th>
        <th class="text-center">Unid.</th><th class="text-end">Qtd</th>
        <th class="text-end">V. Unit.</th><th class="text-end">Desconto</th>
        <th class="text-end">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($itens as $item) : ?>
        <?php
        $vProd = (float) $item['quantidade'] * (float) $item['valor_unitario'];
        $vDesc = (float) ($item['valor_desconto'] ?? 0);
        $vItem = $vProd - $vDesc;
        ?>
    <tr>
        <td><?= (int) $item['numero_item'] ?></td>
        <td>
            <?= h((string) $item['descricao']) ?>
            <?php if (!empty($item['codigo_produto'])) : ?>
            <small class="text-muted">(<?= h((string) $item['codigo_produto']) ?>)</small>
            <?php endif; ?>
        </td>
        <td><?= h((string) $item['ncm']) ?></td>
        <td><?= h((string) $item['cfop']) ?></td>
        <td class="text-center"><?= h((string) $item['unidade']) ?></td>
        <td class="text-end"><?= h(number_format((float) $item['quantidade'], 4, ',', '.')) ?></td>
        <td class="text-end">R$&nbsp;<?= h(number_format((float) $item['valor_unitario'], 2, ',', '.')) ?></td>
        <td class="text-end">
            <?= $vDesc > 0 ? 'R$&nbsp;' . h(number_format($vDesc, 2, ',', '.')) : '&#8212;' ?>
        </td>
        <td class="text-end fw-bold">R$&nbsp;<?= h(number_format($vItem, 2, ',', '.')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr>
        <td colspan="8" class="text-end fw-bold">Total Geral</td>
        <td class="text-end fw-bold">R$&nbsp;<?= h(number_format($totalGeral, 2, ',', '.')) ?></td>
    </tr>
    </tfoot>
</table>

<?php // ── Workflow actions by status ─────────────────────────────────────────── ?>
<div class="d-flex gap-2 flex-wrap align-items-start">
    <?php if ($pedido['status'] === 'rascunho') : ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="aprovar">
        <button type="submit" class="btn btn-warning"
                onclick="return confirm('Aprovar este pedido?')">Aprovar</button>
    </form>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="cancelar_rascunho">
        <button type="submit" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este pedido?')">Cancelar</button>
    </form>

    <?php elseif ($pedido['status'] === 'aprovado') : ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="emitir">
        <button type="submit" class="btn btn-success"
                onclick="return confirm('Emitir NF-e junto à SEFAZ? Esta ação não pode ser desfeita.')">
            Emitir NF-e
        </button>
    </form>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="cancelar_rascunho">
        <button type="submit" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este pedido?')">Cancelar</button>
    </form>

    <?php elseif ($pedido['status'] === 'emitido') : ?>
    <a href="?p=pedidos/ver&amp;id=<?= $id ?>&amp;acao=danfe"
       class="btn btn-outline-primary" target="_blank">&#11015; DANFE PDF</a>
    <button type="button" class="btn btn-outline-danger"
            data-bs-toggle="collapse" data-bs-target="#cancelNfeForm">
        Cancelar NF-e &#9660;
    </button>
    <div class="collapse w-100 mt-2" id="cancelNfeForm">
        <form method="post" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
            <input type="hidden" name="acao" value="cancelar_nfe">
            <div class="mb-2">
                <label class="form-label fw-semibold">
                    Justificativa do cancelamento (mín. 15 caracteres) *
                </label>
                <textarea name="xJust" class="form-control" rows="2"
                          required minlength="15" maxlength="255"></textarea>
            </div>
            <div>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Confirmar cancelamento da NF-e na SEFAZ?')">
                    Confirmar Cancelamento
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($eventos)) : ?>
<h5 class="mt-4">Eventos</h5>
<table class="table table-sm">
    <thead>
    <tr>
        <th>Tipo</th><th>Protocolo</th><th>Descrição</th><th>Data</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($eventos as $ev) : ?>
    <tr>
        <td><?= ucfirst(h((string) $ev['tipo'])) ?></td>
        <td><?= h((string) ($ev['protocolo'] ?? '')) ?></td>
        <td><?= h((string) ($ev['descricao'] ?? '')) ?></td>
        <td><?= h((string) $ev['criado_em']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
