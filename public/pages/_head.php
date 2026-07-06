<?php
// $pageTitle deve ser definido pela página antes de incluir este arquivo.
// $flash pode ser ['tipo' => 'success|danger|warning', 'msg' => '...']
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle ?? 'Lumina NFS-e') ?> — Lumina</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php $usuario = $auth->usuarioAtual(); if ($usuario !== null) : ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="?p=">&#9889; Lumina NFS-e</a>
        <div class="navbar-nav ms-auto flex-row gap-3">
            <a class="nav-link" href="?p=clientes">Clientes</a>
            <a class="nav-link" href="?p=servicos">Serviços</a>
            <a class="nav-link" href="?p=orcamentos">Orçamentos</a>
            <a class="nav-link text-warning" href="?p=login&amp;acao=logout">
                Sair (<?= h($usuario['nome']) ?>)
            </a>
        </div>
    </div>
</nav>
<?php endif; ?>
<div class="container pb-5">
<?php if (!empty($flash)) : ?>
<div class="alert alert-<?= h($flash['tipo']) ?> alert-dismissible fade show" role="alert">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
