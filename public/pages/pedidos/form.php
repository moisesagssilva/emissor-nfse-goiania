<?php

declare(strict_types=1);

$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$pedido = $id !== null ? $nfeStorage->buscarPedido($id) : null;
$itens  = $id !== null ? $nfeStorage->listarItens($id) : [];
$flash  = null;

if ($pedido !== null && $pedido['status'] !== 'rascunho') {
    header('Location: ?p=pedidos/ver&id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $erros  = [];
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        if ($clienteId === 0) {
            $erros[] = 'Cliente é obrigatório.';
        }

        $itensPost = $_POST['itens'] ?? [];
        if (empty($itensPost)) {
            $erros[] = 'Adicione pelo menos um item.';
        }

        $itensValidos = [];
        foreach ($itensPost as $idx => $item) {
            $ncm  = preg_replace('/\D/', '', $item['ncm'] ?? '');
            $cfop = preg_replace('/\D/', '', $item['cfop'] ?? '');
            $qtd  = (float) ($item['quantidade'] ?? 0);
            $vUnit = (float) ($item['valor_unitario'] ?? 0);
            if (strlen($ncm) !== 8) {
                $erros[] = "Item " . ($idx + 1) . ": NCM deve ter 8 dígitos.";
            }
            if (!preg_match('/^[56]\d{3}$/', $cfop)) {
                $erros[] = "Item " . ($idx + 1) . ": CFOP deve ter 4 dígitos iniciando com 5 ou 6.";
            }
            if ($qtd <= 0) {
                $erros[] = "Item " . ($idx + 1) . ": Quantidade deve ser maior que zero.";
            }
            if ($vUnit <= 0) {
                $erros[] = "Item " . ($idx + 1) . ": Valor unitário deve ser maior que zero.";
            }
            if ($erros === []) {
                $vDesc = (float) ($item['valor_desconto'] ?? 0);
                $itensValidos[] = [
                    'numero_item'                => $idx + 1,
                    'codigo_produto'             => trim($item['codigo_produto'] ?? ''),
                    'descricao'                  => trim($item['descricao'] ?? ''),
                    'ncm'                        => $ncm,
                    'cfop'                       => $cfop,
                    'unidade'                    => trim($item['unidade'] ?? 'UN') ?: 'UN',
                    'quantidade'                 => number_format($qtd, 4, '.', ''),
                    'valor_unitario'             => number_format($vUnit, 2, '.', ''),
                    'valor_desconto'             => $vDesc > 0 ? number_format($vDesc, 2, '.', '') : null,
                    'csosn'                      => trim($item['csosn'] ?? '400') ?: '400',
                    'pis_cst'                    => trim($item['pis_cst'] ?? '07') ?: '07',
                    'cofins_cst'                 => trim($item['cofins_cst'] ?? '07') ?: '07',
                    'informacoes_adicionais_item' => trim($item['obs'] ?? '') ?: null,
                ];
            }
        }

        if ($erros !== []) {
            $flash = ['tipo' => 'danger', 'msg' => implode(' ', $erros)];
        } else {
            $usuario   = $auth->usuarioAtual();
            $usuarioId = (int) ($usuario['id'] ?? 0);
            $dados = [
                'cliente_id'            => $clienteId,
                'natureza_operacao'     => trim($_POST['natureza_operacao'] ?? 'Venda de mercadoria') ?: 'Venda de mercadoria',
                'consumidor_final'      => isset($_POST['consumidor_final']) ? 1 : 0,
                'presenca'              => (int) ($_POST['presenca'] ?? 1),
                'informacoes_adicionais' => trim($_POST['informacoes_adicionais'] ?? '') ?: null,
                'criado_por'            => $usuarioId,
            ];

            if ($id !== null) {
                $nfeStorage->atualizarPedido($id, $dados);
                $nfeStorage->substituirItens($id, $itensValidos);
                $pedido = $nfeStorage->buscarPedido($id);
                $itens  = $nfeStorage->listarItens($id);
                $flash  = ['tipo' => 'success', 'msg' => 'Pedido atualizado.'];
            } else {
                $novoId = $nfeStorage->inserirPedido($dados);
                $nfeStorage->substituirItens($novoId, $itensValidos);
                header('Location: ?p=pedidos/ver&id=' . $novoId);
                exit;
            }
        }
    }
} elseif (isset($_GET['salvo'])) {
    $flash = ['tipo' => 'success', 'msg' => 'Pedido criado.'];
}

$clientes  = $cadastro->listarClientes();
$pageTitle = $id !== null ? 'Editar Pedido #' . $id : 'Novo Pedido NF-e';
require PAGES_DIR . '/_head.php';

$v           = $pedido ?? [];
$presencaOpt = [
    1 => '1 — Presencial',
    2 => '2 — Internet',
    3 => '3 — Teleatendimento',
    4 => '4 — Entrega domiciliar',
    9 => '9 — Outros',
];
$presAtual = (int) ($v['presenca'] ?? 1);
?>
<div class="d-flex justify-content-between mb-3">
    <h2><?= h($pageTitle) ?></h2>
    <a href="?p=pedidos" class="btn btn-outline-secondary">← Voltar</a>
</div>
<form method="post" id="pedidoForm" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

    <div class="col-md-6">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" class="form-select" required>
            <option value="">— selecione —</option>
            <?php foreach ($clientes as $c) : ?>
            <option value="<?= (int) $c['id'] ?>"
                <?= (int) ($v['cliente_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= h($c['razao_social']) ?> (<?= h($c['cpf_cnpj']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Natureza da Operação</label>
        <input type="text" name="natureza_operacao" class="form-control"
               value="<?= h((string) ($v['natureza_operacao'] ?? 'Venda de mercadoria')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Indicador de Presença</label>
        <select name="presenca" class="form-select">
            <?php foreach ($presencaOpt as $val => $label) : ?>
            <option value="<?= $val ?>" <?= $presAtual === $val ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check mb-2">
            <input type="checkbox" name="consumidor_final" id="consumidorFinal"
                   class="form-check-input" value="1"
                   <?= !empty($v['consumidor_final']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="consumidorFinal">Consumidor Final</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label">Informações Adicionais</label>
        <textarea name="informacoes_adicionais" class="form-control" rows="2"><?= h(
            (string) ($v['informacoes_adicionais'] ?? '')
        ) ?></textarea>
    </div>

    <div class="col-12 mt-4">
        <h5>Itens</h5>
        <table class="table table-bordered table-sm" id="itensTable">
            <thead class="table-light">
            <tr>
                <th style="width:3%">#</th>
                <th>Descrição *</th>
                <th style="width:10%">NCM *</th>
                <th style="width:8%">CFOP *</th>
                <th style="width:6%">Unid.</th>
                <th style="width:8%">Qtd *</th>
                <th style="width:10%">V. Unit. *</th>
                <th style="width:9%">Desconto</th>
                <th style="width:10%">Total</th>
                <th style="width:3%"></th>
            </tr>
            </thead>
            <tbody id="itensBody">
            <?php foreach ($itens as $i => $item) : ?>
            <tr class="item-row" data-idx="<?= $i ?>">
                <td class="text-center item-num"><?= $i + 1 ?></td>
                <td>
                    <input type="text" name="itens[<?= $i ?>][descricao]"
                           class="form-control form-control-sm" required
                           value="<?= h($item['descricao']) ?>">
                    <details class="mt-1">
                        <summary class="text-muted small" style="cursor:pointer">Dados fiscais</summary>
                        <div class="row g-1 mt-1">
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][codigo_produto]"
                                       class="form-control form-control-sm"
                                       placeholder="Cód. Produto"
                                       value="<?= h($item['codigo_produto'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][csosn]"
                                       class="form-control form-control-sm"
                                       placeholder="CSOSN"
                                       value="<?= h($item['csosn'] ?? '400') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][pis_cst]"
                                       class="form-control form-control-sm"
                                       placeholder="PIS CST"
                                       value="<?= h($item['pis_cst'] ?? '07') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][cofins_cst]"
                                       class="form-control form-control-sm"
                                       placeholder="COFINS CST"
                                       value="<?= h($item['cofins_cst'] ?? '07') ?>">
                            </div>
                            <div class="col-12">
                                <input type="text" name="itens[<?= $i ?>][obs]"
                                       class="form-control form-control-sm"
                                       placeholder="Obs. do item"
                                       value="<?= h($item['informacoes_adicionais_item'] ?? '') ?>">
                            </div>
                        </div>
                    </details>
                </td>
                <td><input type="text" name="itens[<?= $i ?>][ncm]"
                           class="form-control form-control-sm" required maxlength="8"
                           value="<?= h($item['ncm']) ?>"></td>
                <td><input type="text" name="itens[<?= $i ?>][cfop]"
                           class="form-control form-control-sm" required maxlength="4"
                           value="<?= h($item['cfop']) ?>"></td>
                <td><input type="text" name="itens[<?= $i ?>][unidade]"
                           class="form-control form-control-sm" maxlength="6"
                           value="<?= h($item['unidade'] ?? 'UN') ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][quantidade]"
                           class="form-control form-control-sm item-qtd" step="0.0001" min="0.0001" required
                           value="<?= h($item['quantidade']) ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][valor_unitario]"
                           class="form-control form-control-sm item-vunit" step="0.01" min="0.01" required
                           value="<?= h($item['valor_unitario']) ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][valor_desconto]"
                           class="form-control form-control-sm item-vdesc" step="0.01" min="0"
                           value="<?= h($item['valor_desconto'] ?? '') ?>"></td>
                <td class="text-end item-total fw-bold">
                    R$ <?= h(number_format(
                        (float) $item['quantidade'] * (float) $item['valor_unitario']
                        - (float) ($item['valor_desconto'] ?? 0),
                        2,
                        ',',
                        '.'
                    )) ?>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remover">✕</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="8" class="text-end fw-bold">Total Geral:</td>
                <td class="text-end fw-bold" id="totalGeral">R$ 0,00</td>
                <td></td>
            </tr>
            </tfoot>
        </table>
        <button type="button" id="btnAddItem" class="btn btn-outline-secondary btn-sm">
            ＋ Adicionar Item
        </button>
    </div>

    <div class="col-12 d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Salvar Rascunho</button>
        <a href="?p=pedidos" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<script>
(function () {
    let idx = <?= max(count($itens) - 1, -1) ?>;

    function novaLinha(n) {
        idx++;
        const i = idx;
        return `<tr class="item-row" data-idx="${i}">
            <td class="text-center item-num">${n}</td>
            <td>
                <input type="text" name="itens[${i}][descricao]"
                       class="form-control form-control-sm" required placeholder="Descrição">
                <details class="mt-1">
                    <summary class="text-muted small" style="cursor:pointer">Dados fiscais</summary>
                    <div class="row g-1 mt-1">
                        <div class="col-6"><input type="text" name="itens[${i}][codigo_produto]"
                            class="form-control form-control-sm" placeholder="Cód. Produto"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][csosn]"
                            class="form-control form-control-sm" placeholder="CSOSN" value="400"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][pis_cst]"
                            class="form-control form-control-sm" placeholder="PIS CST" value="07"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][cofins_cst]"
                            class="form-control form-control-sm" placeholder="COFINS CST" value="07"></div>
                        <div class="col-12"><input type="text" name="itens[${i}][obs]"
                            class="form-control form-control-sm" placeholder="Obs. do item"></div>
                    </div>
                </details>
            </td>
            <td><input type="text" name="itens[${i}][ncm]"
                class="form-control form-control-sm" required maxlength="8"></td>
            <td><input type="text" name="itens[${i}][cfop]"
                class="form-control form-control-sm" required maxlength="4" value="5102"></td>
            <td><input type="text" name="itens[${i}][unidade]"
                class="form-control form-control-sm" maxlength="6" value="UN"></td>
            <td><input type="number" name="itens[${i}][quantidade]"
                class="form-control form-control-sm item-qtd" step="0.0001" min="0.0001" required value="1"></td>
            <td><input type="number" name="itens[${i}][valor_unitario]"
                class="form-control form-control-sm item-vunit" step="0.01" min="0.01" required value="0.00"></td>
            <td><input type="number" name="itens[${i}][valor_desconto]"
                class="form-control form-control-sm item-vdesc" step="0.01" min="0" value=""></td>
            <td class="text-end item-total fw-bold">R$ 0,00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remover">✕</button>
            </td>
        </tr>`;
    }

    function fmt(n) {
        return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function recalcular() {
        let total = 0;
        document.querySelectorAll('#itensBody .item-row').forEach(function (tr, pos) {
            tr.querySelector('.item-num').textContent = pos + 1;
            const qtd   = parseFloat(tr.querySelector('.item-qtd').value)   || 0;
            const vunit = parseFloat(tr.querySelector('.item-vunit').value)  || 0;
            const vdesc = parseFloat(tr.querySelector('.item-vdesc').value)  || 0;
            const linha = Math.max(qtd * vunit - vdesc, 0);
            tr.querySelector('.item-total').textContent = fmt(linha);
            total += linha;
        });
        document.getElementById('totalGeral').textContent = fmt(total);
    }

    const body = document.getElementById('itensBody');

    document.getElementById('btnAddItem').addEventListener('click', function () {
        const n = body.querySelectorAll('.item-row').length + 1;
        body.insertAdjacentHTML('beforeend', novaLinha(n));
        body.querySelector('.item-row:last-child .item-qtd').focus();
        recalcular();
    });

    body.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remover')) {
            e.target.closest('.item-row').remove();
            recalcular();
        }
    });

    body.addEventListener('input', recalcular);

    recalcular();
}());
</script>
<?php require PAGES_DIR . '/_foot.php'; ?>
