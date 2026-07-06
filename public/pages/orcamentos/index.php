<?php

declare(strict_types=1);

$statusFiltro = trim($_GET['status'] ?? '');
$busca        = trim($_GET['busca'] ?? '');
$orcamentos   = $cadastro->listarOrcamentos($statusFiltro, $busca);
$pageTitle    = 'Orçamentos';

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Orçamentos</h2>
    <a href="?p=orcamentos/form" class="btn btn-primary">+ Novo</a>
</div>
<form class="row g-2 mb-4">
    <input type="hidden" name="p" value="orcamentos">
    <div class="col-auto">
        <select name="status" class="form-select">
            <option value="">Todos os status</option>
            <?php foreach (array_keys($statusCores) as $s) : ?>
            <option value="<?= h($s) ?>" <?= $statusFiltro === $s ? 'selected' : '' ?>>
                <?= ucfirst(h($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="busca" class="form-control"
               placeholder="Buscar cliente" value="<?= h($busca) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">Filtrar</button>
    </div>
</form>
<?php if (empty($orcamentos)) : ?>
<p class="text-muted">Nenhum orçamento encontrado.</p>
<?php else : ?>
<table class="table table-hover">
    <thead>
    <tr>
        <th>#</th><th>Cliente</th><th>Valor</th><th>Competência</th><th>Status</th><th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orcamentos as $o) : ?>
    <tr>
        <td><?= (int) $o['id'] ?></td>
        <td><?= h($o['razao_social']) ?></td>
        <td>R$ <?= h(number_format((float) $o['valor_servicos'], 2, ',', '.')) ?></td>
        <td><?= h($o['competencia']) ?></td>
        <td>
            <span class="badge bg-<?= $statusCores[$o['status']] ?? 'secondary' ?>">
                <?= h($o['status']) ?>
            </span>
        </td>
        <td>
            <a href="?p=orcamentos/ver&amp;id=<?= (int) $o['id'] ?>"
               class="btn btn-sm btn-outline-primary">Ver</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
