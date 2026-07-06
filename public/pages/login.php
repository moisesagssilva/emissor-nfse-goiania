<?php

declare(strict_types=1);

$flash = null;

if (($_GET['acao'] ?? '') === 'logout') {
    $auth->logout();
    header('Location: ?p=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido. Tente novamente.'];
    } elseif (($_POST['acao'] ?? '') === 'login') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($auth->login(trim($_POST['email'] ?? ''), $_POST['senha'] ?? '', $ip)) {
            header('Location: ?p=');
            exit;
        }
        $flash = [
            'tipo' => 'danger',
            'msg'  => 'Email ou senha incorretos, ou IP bloqueado por excesso de tentativas.',
        ];
    } elseif (($_POST['acao'] ?? '') === 'registrar') {
        try {
            $auth->registrar(
                trim($_POST['nome'] ?? ''),
                trim($_POST['email'] ?? ''),
                $_POST['senha'] ?? ''
            );
            $flash = ['tipo' => 'success', 'msg' => 'Usuário criado. Faça login.'];
        } catch (\Throwable $e) {
            $flash = ['tipo' => 'danger', 'msg' => $e->getMessage()];
        }
    }
}

$podeRegistrar = $auth->podeRegistrar();
$pageTitle = 'Login';
require PAGES_DIR . '/_head.php';
?>
<div class="row justify-content-center mt-4">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h4 class="card-title mb-4 text-center">&#9889; Lumina NFS-e</h4>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
                    <input type="hidden" name="acao" value="login">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Senha</label>
                        <input type="password" name="senha" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>

                <?php if ($podeRegistrar) : ?>
                <hr class="my-4">
                <details>
                    <summary class="text-muted small text-center" style="cursor:pointer">
                        Criar nova conta
                    </summary>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
                        <input type="hidden" name="acao" value="registrar">
                        <div class="mb-3">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Senha (mín. 8 caracteres)</label>
                            <input type="password" name="senha" class="form-control"
                                   minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            Criar Conta
                        </button>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
