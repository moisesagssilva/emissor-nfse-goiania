<?php

declare(strict_types=1);

$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;
$cliente = $id !== null ? $cadastro->buscarCliente($id) : null;
$flash   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $dados = [
            'razao_social'     => trim($_POST['razao_social'] ?? ''),
            'cpf_cnpj'         => preg_replace('/\D/', '', $_POST['cpf_cnpj'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'telefone'         => trim($_POST['telefone'] ?? ''),
            'logradouro'       => trim($_POST['logradouro'] ?? ''),
            'numero'           => trim($_POST['numero'] ?? ''),
            'complemento'      => trim($_POST['complemento'] ?? ''),
            'bairro'           => trim($_POST['bairro'] ?? ''),
            'codigo_municipio' => trim($_POST['codigo_municipio'] ?? ''),
            'municipio'        => trim($_POST['municipio'] ?? ''),
            'uf'               => strtoupper(trim($_POST['uf'] ?? '')),
            'cep'              => preg_replace('/\D/', '', $_POST['cep'] ?? ''),
        ];

        $erros = [];
        if ($dados['razao_social'] === '') {
            $erros[] = 'Razão social é obrigatória.';
        }
        if ($dados['cpf_cnpj'] === '') {
            $erros[] = 'CPF/CNPJ é obrigatório.';
        }

        if ($erros !== []) {
            $flash = ['tipo' => 'danger', 'msg' => implode(' ', $erros)];
        } else {
            try {
                if ($id !== null) {
                    $cadastro->atualizarCliente($id, $dados);
                    $cliente = $cadastro->buscarCliente($id);
                    $flash   = ['tipo' => 'success', 'msg' => 'Cliente atualizado.'];
                } else {
                    $novoId = $cadastro->inserirCliente($dados);
                    header('Location: ?p=clientes/form&id=' . $novoId . '&salvo=1');
                    exit;
                }
            } catch (\PDOException) {
                $flash = ['tipo' => 'danger', 'msg' => 'CPF/CNPJ já cadastrado.'];
            }
        }
    }
} elseif (isset($_GET['salvo'])) {
    $flash = ['tipo' => 'success', 'msg' => 'Cliente criado com sucesso.'];
}

$pageTitle = $id !== null ? 'Editar Cliente' : 'Novo Cliente';
require PAGES_DIR . '/_head.php';

$v = $cliente ?? [];
?>
<div class="d-flex justify-content-between mb-3">
    <h2><?= h($pageTitle) ?></h2>
    <a href="?p=clientes" class="btn btn-outline-secondary">← Voltar</a>
</div>
<form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

    <div class="col-md-8">
        <label class="form-label">Razão Social *</label>
        <input type="text" name="razao_social" class="form-control" required
               value="<?= h((string) ($v['razao_social'] ?? '')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">CPF/CNPJ *</label>
        <input type="text" name="cpf_cnpj" class="form-control" required
               value="<?= h((string) ($v['cpf_cnpj'] ?? '')) ?>">
    </div>
    <div class="col-md-5">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control"
               value="<?= h((string) ($v['email'] ?? '')) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Telefone</label>
        <input type="text" name="telefone" class="form-control"
               value="<?= h((string) ($v['telefone'] ?? '')) ?>">
    </div>

    <div class="col-12"><hr><h6>Endereço</h6></div>

    <div class="col-md-6">
        <label class="form-label">Logradouro</label>
        <input type="text" name="logradouro" class="form-control"
               value="<?= h((string) ($v['logradouro'] ?? '')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">Número</label>
        <input type="text" name="numero" class="form-control"
               value="<?= h((string) ($v['numero'] ?? '')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Complemento</label>
        <input type="text" name="complemento" class="form-control"
               value="<?= h((string) ($v['complemento'] ?? '')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Bairro</label>
        <input type="text" name="bairro" class="form-control"
               value="<?= h((string) ($v['bairro'] ?? '')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Município</label>
        <input type="text" name="municipio" class="form-control"
               value="<?= h((string) ($v['municipio'] ?? '')) ?>"
               placeholder="Goiânia">
    </div>
    <div class="col-md-4">
        <label class="form-label">Município (código IBGE)</label>
        <input type="text" name="codigo_municipio" class="form-control"
               value="<?= h((string) ($v['codigo_municipio'] ?? '')) ?>"
               placeholder="5208707">
    </div>
    <div class="col-md-2">
        <label class="form-label">UF</label>
        <input type="text" name="uf" class="form-control" maxlength="2"
               value="<?= h((string) ($v['uf'] ?? '')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">CEP</label>
        <input type="text" name="cep" class="form-control"
               value="<?= h((string) ($v['cep'] ?? '')) ?>">
    </div>

    <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <?php if ($id !== null) : ?>
        <a href="?p=orcamentos/form&amp;cliente_id=<?= $id ?>"
           class="btn btn-outline-success">+ Novo Orçamento</a>
        <?php endif; ?>
        <a href="?p=clientes" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
