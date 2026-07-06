<?php

declare(strict_types=1);

$id        = isset($_GET['id']) ? (int) $_GET['id'] : null;
$orcamento = $id !== null ? $cadastro->buscarOrcamento($id) : null;

if ($orcamento !== null && $orcamento['status'] !== 'rascunho') {
    header('Location: ?p=orcamentos/ver&id=' . $id);
    exit;
}

$clientes  = $cadastro->listarClientes();
$servicos  = $cadastro->listarServicos();
$usuario   = $auth->usuarioAtual();
$flash     = null;

$clienteIdParam = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null;
$servicoIdParam = isset($_GET['servico_id']) ? (int) $_GET['servico_id'] : null;
$tpl            = $servicoIdParam !== null ? $cadastro->buscarServico($servicoIdParam) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $dados = [
            'cliente_id'                  => (int) ($_POST['cliente_id'] ?? 0),
            'servico_id'                  => trim($_POST['servico_id'] ?? ''),
            'competencia'                 => trim($_POST['competencia'] ?? ''),
            'valor_servicos'              => trim($_POST['valor_servicos'] ?? ''),
            'item_lista_servico'          => trim($_POST['item_lista_servico'] ?? ''),
            'codigo_cnae'                 => trim($_POST['codigo_cnae'] ?? ''),
            'codigo_tributacao_municipio' => trim($_POST['codigo_tributacao_municipio'] ?? ''),
            'discriminacao'               => trim($_POST['discriminacao'] ?? ''),
            'aliquota'                    => trim($_POST['aliquota'] ?? ''),
            'exigibilidade_iss'           => (int) ($_POST['exigibilidade_iss'] ?? 1),
            'iss_retido'                  => (int) ($_POST['iss_retido'] ?? 2),
            'valor_deducoes'              => trim($_POST['valor_deducoes'] ?? ''),
            'valor_pis'                   => trim($_POST['valor_pis'] ?? ''),
            'valor_cofins'                => trim($_POST['valor_cofins'] ?? ''),
            'valor_inss'                  => trim($_POST['valor_inss'] ?? ''),
            'valor_ir'                    => trim($_POST['valor_ir'] ?? ''),
            'valor_csll'                  => trim($_POST['valor_csll'] ?? ''),
            'desconto_incondicionado'     => trim($_POST['desconto_incondicionado'] ?? ''),
            'desconto_condicionado'       => trim($_POST['desconto_condicionado'] ?? ''),
            'criado_por'                  => $usuario['id'] ?? 0,
        ];

        $erros = [];
        if ($dados['cliente_id'] === 0) {
            $erros[] = 'Selecione um cliente.';
        }
        if ($dados['valor_servicos'] === '') {
            $erros[] = 'Valor dos serviços é obrigatório.';
        }
        if ($dados['item_lista_servico'] === '') {
            $erros[] = 'Item de lista é obrigatório.';
        }
        if ($dados['discriminacao'] === '') {
            $erros[] = 'Discriminação é obrigatória.';
        }
        if ($dados['competencia'] === '') {
            $erros[] = 'Competência é obrigatória.';
        }

        if ($erros !== []) {
            $flash = ['tipo' => 'danger', 'msg' => implode(' ', $erros)];
        } elseif ($id !== null) {
            $cadastro->atualizarOrcamento($id, $dados);
            header('Location: ?p=orcamentos/ver&id=' . $id);
            exit;
        } else {
            $novoId = $cadastro->inserirOrcamento($dados);
            header('Location: ?p=orcamentos/ver&id=' . $novoId);
            exit;
        }
    }
}

$pageTitle = $id !== null ? 'Editar Orçamento' : 'Novo Orçamento';
require PAGES_DIR . '/_head.php';

$v   = $orcamento ?? [];
$tpl = $tpl ?? [];

$camposOpc = [
    'valor_deducoes'          => 'Deduções',
    'valor_pis'               => 'PIS',
    'valor_cofins'            => 'COFINS',
    'valor_inss'              => 'INSS',
    'valor_ir'                => 'IR',
    'valor_csll'              => 'CSLL',
    'desconto_incondicionado' => 'Desc. Incondicionado',
    'desconto_condicionado'   => 'Desc. Condicionado',
];
$issRet = (int) ($v['iss_retido'] ?? $tpl['iss_retido'] ?? 2);
$exig   = (int) ($v['exigibilidade_iss'] ?? $tpl['exigibilidade_iss'] ?? 1);
?>
<div class="d-flex justify-content-between mb-3">
    <h2><?= h($pageTitle) ?></h2>
    <a href="?p=orcamentos" class="btn btn-outline-secondary">← Voltar</a>
</div>

<?php if (!empty($servicos) && $id === null) : ?>
<div class="card mb-4 border-info">
    <div class="card-body">
        <h6 class="card-title text-info">Carregar Template de Serviço</h6>
        <form class="row g-2 align-items-end">
            <input type="hidden" name="p" value="orcamentos/form">
            <?php if ($clienteIdParam) : ?>
            <input type="hidden" name="cliente_id" value="<?= $clienteIdParam ?>">
            <?php endif; ?>
            <div class="col-auto">
                <select name="servico_id" class="form-select">
                    <option value="">— Escolha um template —</option>
                    <?php foreach ($servicos as $s) : ?>
                    <option value="<?= (int) $s['id'] ?>"
                        <?= isset($tpl['id']) && $tpl['id'] == $s['id'] ? 'selected' : '' ?>>
                        <?= h($s['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-info btn-sm">Carregar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
    <?php if (!empty($tpl['id'])) : ?>
    <input type="hidden" name="servico_id" value="<?= (int) $tpl['id'] ?>">
    <?php endif; ?>

    <div class="col-md-6">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" class="form-select" required>
            <option value="">— Selecione —</option>
            <?php foreach ($clientes as $c) : ?>
                <?php $sel = ((int) ($v['cliente_id'] ?? $clienteIdParam ?? 0)) === (int) $c['id']
                ? 'selected' : ''; ?>
            <option value="<?= (int) $c['id'] ?>" <?= $sel ?>>
                <?= h($c['razao_social']) ?> — <?= h($c['cpf_cnpj']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Competência *</label>
        <input type="date" name="competencia" class="form-control" required
               value="<?= h((string) ($v['competencia'] ?? date('Y-m-d'))) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Valor dos Serviços (R$) *</label>
        <input type="text" name="valor_servicos" class="form-control" required
               value="<?= h((string) ($v['valor_servicos'] ?? '')) ?>"
               placeholder="3500.00">
    </div>
    <div class="col-md-3">
        <label class="form-label">Item Lista Serviço *</label>
        <input type="text" name="item_lista_servico" class="form-control" required
               value="<?= h((string) ($v['item_lista_servico'] ?? $tpl['item_lista_servico'] ?? '')) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Código CNAE</label>
        <input type="text" name="codigo_cnae" class="form-control"
               value="<?= h((string) ($v['codigo_cnae'] ?? $tpl['codigo_cnae'] ?? '')) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Cód. Tributação Município</label>
        <input type="text" name="codigo_tributacao_municipio" class="form-control"
               value="<?= h(
                   (string) ($v['codigo_tributacao_municipio']
                       ?? $tpl['codigo_tributacao_municipio'] ?? '')
               ) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">Alíquota (%)</label>
        <input type="text" name="aliquota" class="form-control"
               value="<?= h((string) ($v['aliquota'] ?? $tpl['aliquota'] ?? '')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">ISS Retido</label>
        <select name="iss_retido" class="form-select">
            <option value="2" <?= $issRet === 2 ? 'selected' : '' ?>>Não (2)</option>
            <option value="1" <?= $issRet === 1 ? 'selected' : '' ?>>Sim (1)</option>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Exigibilidade ISS</label>
        <select name="exigibilidade_iss" class="form-select">
            <?php foreach (range(1, 7) as $n) : ?>
            <option value="<?= $n ?>" <?= $exig === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Discriminação *</label>
        <textarea name="discriminacao" class="form-control" rows="4" required><?= h(
            (string) ($v['discriminacao'] ?? $tpl['discriminacao'] ?? '')
        ) ?></textarea>
    </div>

    <div class="col-12"><hr><h6 class="text-muted">Deduções e retenções (opcional)</h6></div>
    <?php foreach ($camposOpc as $campo => $label) : ?>
    <div class="col-md-3">
        <label class="form-label"><?= h($label) ?></label>
        <input type="text" name="<?= $campo ?>" class="form-control"
               value="<?= h((string) ($v[$campo] ?? '')) ?>"
               placeholder="0.00">
    </div>
    <?php endforeach; ?>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">Salvar Rascunho</button>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
