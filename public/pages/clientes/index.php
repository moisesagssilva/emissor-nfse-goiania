<?php

declare(strict_types=1);

$busca    = trim($_GET['busca'] ?? '');
$clientes = $cadastro->listarClientes($busca);
$pageTitle = 'Clientes';
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Clientes</h2>
    <a href="?p=clientes/form" class="btn btn-primary">+ Novo</a>
</div>
<form class="row g-2 mb-4">
    <input type="hidden" name="p" value="clientes">
    <div class="col-auto">
        <input type="text" name="busca" class="form-control"
               placeholder="Buscar por nome ou CPF/CNPJ"
               value="<?= h($busca) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">Buscar</button>
        <?php if ($busca !== '') : ?>
        <a href="?p=clientes" class="btn btn-link">Limpar</a>
        <?php endif; ?>
    </div>
</form>
<?php if (empty($clientes)) : ?>
<p class="text-muted">Nenhum cliente encontrado.</p>
<?php else : ?>
<table class="table table-hover">
    <thead>
    <tr>
        <th>Razão Social</th><th>CPF/CNPJ</th><th>Email</th><th>UF</th><th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($clientes as $c) : ?>
    <tr>
        <td><?= h($c['razao_social']) ?></td>
        <td><?= h($c['cpf_cnpj']) ?></td>
        <td><?= h($c['email'] ?? '') ?></td>
        <td><?= h($c['uf'] ?? '') ?></td>
        <td>
            <a href="?p=clientes/form&amp;id=<?= (int) $c['id'] ?>"
               class="btn btn-sm btn-outline-primary">Editar</a>
            <a href="?p=orcamentos/form&amp;cliente_id=<?= (int) $c['id'] ?>"
               class="btn btn-sm btn-outline-success">+ Orçamento</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
