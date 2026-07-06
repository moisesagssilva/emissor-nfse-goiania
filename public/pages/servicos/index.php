<?php

declare(strict_types=1);

$servicos  = $cadastro->listarServicos();
$pageTitle = 'Templates de Serviço';
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Templates de Serviço</h2>
    <a href="?p=servicos/form" class="btn btn-primary">+ Novo</a>
</div>
<?php if (empty($servicos)) : ?>
<p class="text-muted">Nenhum template cadastrado.</p>
<?php else : ?>
<table class="table table-hover">
    <thead>
    <tr>
        <th>Nome</th><th>Item Lista</th><th>CNAE</th><th>ISS Retido</th><th>Alíquota</th><th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($servicos as $s) : ?>
    <tr>
        <td><?= h($s['nome']) ?></td>
        <td><?= h($s['item_lista_servico']) ?></td>
        <td><?= h($s['codigo_cnae'] ?? '') ?></td>
        <td><?= (int) $s['iss_retido'] === 1 ? 'Sim' : 'Não' ?></td>
        <td><?= h($s['aliquota'] ?? '') ?></td>
        <td>
            <a href="?p=servicos/form&amp;id=<?= (int) $s['id'] ?>"
               class="btn btn-sm btn-outline-primary">Editar</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
