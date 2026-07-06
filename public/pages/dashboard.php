<?php

declare(strict_types=1);

$stats = $cadastro->estatisticas();
$pageTitle = 'Dashboard';

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Dashboard</h2>
    <a href="?p=orcamentos/form" class="btn btn-primary">+ Novo Orçamento</a>
</div>

<div class="row g-3 mb-5">
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="display-5 fw-bold"><?= (int) $stats['total_clientes'] ?></div>
                <div class="text-muted small">Clientes</div>
                <a href="?p=clientes" class="btn btn-sm btn-outline-primary mt-2">Ver</a>
            </div>
        </div>
    </div>
    <?php foreach ($statusCores as $status => $cor) : ?>
    <div class="col-md-2">
        <div class="card text-center h-100 border-<?= $cor ?>">
            <div class="card-body">
                <div class="display-5 fw-bold">
                    <?= (int) $stats['orcamentos'][$status] ?>
                </div>
                <div class="text-muted small"><?= ucfirst(h($status)) ?></div>
                <a href="?p=orcamentos&amp;status=<?= h($status) ?>"
                   class="btn btn-sm btn-outline-<?= $cor ?> mt-2">Ver</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h5>Últimas emissões autorizadas</h5>
<?php if (empty($stats['ultimas_emissoes'])) : ?>
<p class="text-muted">Nenhuma emissão ainda.</p>
<?php else : ?>
<table class="table table-sm table-hover">
    <thead>
    <tr><th>NFS-e</th><th>Tomador</th><th>Valor</th><th>Data</th></tr>
    </thead>
    <tbody>
    <?php foreach ($stats['ultimas_emissoes'] as $e) : ?>
    <tr>
        <td><?= h((string) ($e['nfse_numero'] ?? '')) ?></td>
        <td><?= h((string) ($e['tomador_razao'] ?? '')) ?></td>
        <td>R$ <?= h(number_format((float) ($e['valor_servicos'] ?? 0), 2, ',', '.')) ?></td>
        <td><?= h((string) ($e['criado_em'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="mt-4 d-flex gap-2">
    <a href="?p=clientes/form" class="btn btn-outline-secondary">+ Novo Cliente</a>
    <a href="?p=servicos/form" class="btn btn-outline-secondary">+ Novo Serviço</a>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
