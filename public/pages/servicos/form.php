<?php

declare(strict_types=1);

$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;
$servico = $id !== null ? $cadastro->buscarServico($id) : null;
$flash   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $dados = [
            'nome'                        => trim($_POST['nome'] ?? ''),
            'item_lista_servico'          => trim($_POST['item_lista_servico'] ?? ''),
            'codigo_cnae'                 => trim($_POST['codigo_cnae'] ?? ''),
            'codigo_tributacao_municipio' => trim($_POST['codigo_tributacao_municipio'] ?? ''),
            'discriminacao'               => trim($_POST['discriminacao'] ?? ''),
            'aliquota'                    => trim($_POST['aliquota'] ?? ''),
            'exigibilidade_iss'           => (int) ($_POST['exigibilidade_iss'] ?? 1),
            'iss_retido'                  => (int) ($_POST['iss_retido'] ?? 2),
        ];

        if (
            $dados['nome'] === ''
            || $dados['item_lista_servico'] === ''
            || $dados['discriminacao'] === ''
        ) {
            $flash = [
                'tipo' => 'danger',
                'msg'  => 'Nome, item de lista e discriminação são obrigatórios.',
            ];
        } elseif ($id !== null) {
            $cadastro->atualizarServico($id, $dados);
            $servico = $cadastro->buscarServico($id);
            $flash   = ['tipo' => 'success', 'msg' => 'Template atualizado.'];
        } else {
            $novoId = $cadastro->inserirServico($dados);
            header('Location: ?p=servicos/form&id=' . $novoId . '&salvo=1');
            exit;
        }
    }
} elseif (isset($_GET['salvo'])) {
    $flash = ['tipo' => 'success', 'msg' => 'Template criado com sucesso.'];
}

$pageTitle = $id !== null ? 'Editar Serviço' : 'Novo Serviço';
require PAGES_DIR . '/_head.php';

$v      = $servico ?? [];
$exig   = (int) ($v['exigibilidade_iss'] ?? 1);
$issRet = (int) ($v['iss_retido'] ?? 2);

$exigOpcoes = [
    1 => '1 — Exigível',
    2 => '2 — Não incidência',
    3 => '3 — Isenção',
    4 => '4 — Exportação',
    5 => '5 — Imunidade',
    6 => '6 — Exig. suspensa (decisão judicial)',
    7 => '7 — Exig. suspensa (proc. administrativo)',
];
?>
<div class="d-flex justify-content-between mb-3">
    <h2><?= h($pageTitle) ?></h2>
    <a href="?p=servicos" class="btn btn-outline-secondary">← Voltar</a>
</div>
<form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

    <div class="col-md-6">
        <label class="form-label">Nome do Template *</label>
        <input type="text" name="nome" class="form-control" required
               value="<?= h((string) ($v['nome'] ?? '')) ?>"
               placeholder="Ex: Instalação fotovoltaica 5kWp">
    </div>
    <div class="col-md-3">
        <label class="form-label">Item Lista Serviço *</label>
        <input type="text" name="item_lista_servico" class="form-control" required
               value="<?= h((string) ($v['item_lista_servico'] ?? '')) ?>"
               placeholder="7.02">
    </div>
    <div class="col-md-3">
        <label class="form-label">Código CNAE</label>
        <input type="text" name="codigo_cnae" class="form-control"
               value="<?= h((string) ($v['codigo_cnae'] ?? '')) ?>"
               placeholder="4321500">
    </div>
    <div class="col-md-4">
        <label class="form-label">Cód. Tributação Município</label>
        <input type="text" name="codigo_tributacao_municipio" class="form-control"
               value="<?= h((string) ($v['codigo_tributacao_municipio'] ?? '')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">Alíquota (%)</label>
        <input type="text" name="aliquota" class="form-control"
               value="<?= h((string) ($v['aliquota'] ?? '')) ?>"
               placeholder="2.00">
    </div>
    <div class="col-md-3">
        <label class="form-label">ISS Retido</label>
        <select name="iss_retido" class="form-select">
            <option value="2" <?= $issRet === 2 ? 'selected' : '' ?>>Não (2)</option>
            <option value="1" <?= $issRet === 1 ? 'selected' : '' ?>>Sim (1)</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Exigibilidade ISS</label>
        <select name="exigibilidade_iss" class="form-select">
            <?php foreach ($exigOpcoes as $val => $label) : ?>
            <option value="<?= $val ?>" <?= $exig === $val ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Discriminação do Serviço *</label>
        <textarea name="discriminacao" class="form-control" rows="4" required><?= h(
            (string) ($v['discriminacao'] ?? '')
        ) ?></textarea>
    </div>
    <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <a href="?p=servicos" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
