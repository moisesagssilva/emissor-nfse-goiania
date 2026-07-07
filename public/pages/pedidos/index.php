<?php

declare(strict_types=1);

$status  = $_GET['status'] ?? '';
$busca   = trim($_GET['busca'] ?? '');
$pedidos = $nfeStorage->listarPedidos($status, $busca);

$pageTitle = 'Pedidos NF-e';
require PAGES_DIR . '/_head.php';

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Pedidos NF-e</h2>
    <a href="?p=pedidos/form" class="btn btn-primary">+ Novo Pedido</a>
</div>
<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="p" value="pedidos">
    <div class="col-md-4">
        <input type="text" name="busca" class="form-control" placeholder="Buscar cliente..."
               value="<?= h($busca) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">Todos os status</option>
            <?php foreach (['rascunho', 'aprovado', 'emitido', 'cancelado'] as $s) : ?>
            <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                <?= ucfirst(h($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">Filtrar</button>
        <a href="?p=pedidos" class="btn btn-link">Limpar</a>
    </div>
</form>
<?php if (empty($pedidos)) : ?>
<p class="text-muted">Nenhum pedido encontrado.</p>
<?php else : ?>
<table class="table table-hover align-middle">
    <thead>
    <tr>
        <th>#</th>
        <th>Cliente</th>
        <th class="text-end">Valor Total</th>
        <th class="text-center">Itens</th>
        <th class="text-center">Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($pedidos as $p) : ?>
    <tr>
        <td><?= (int) $p['id'] ?></td>
        <td>
            <?= h($p['razao_social']) ?>
            <small class="text-muted d-block"><?= h($p['cpf_cnpj']) ?></small>
        </td>
        <td class="text-end">R$ <?= h(number_format((float) $p['valor_total'], 2, ',', '.')) ?></td>
        <td class="text-center"><?= (int) $p['total_itens'] ?></td>
        <td class="text-center">
            <?php $cor = $statusCores[$p['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $cor ?>"><?= ucfirst(h($p['status'])) ?></span>
        </td>
        <td>
            <a href="?p=pedidos/ver&amp;id=<?= (int) $p['id'] ?>"
               class="btn btn-sm btn-outline-primary">Ver</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
