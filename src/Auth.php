<?php

/**
 * Gerenciamento de sessão, autenticação e CSRF para a interface web.
 */

declare(strict_types=1);

namespace EmissorGyn;

final class Auth
{
    public function __construct(private readonly Cadastro $cadastro)
    {
    }

    public function iniciarSessao(): void
    {
        session_name('lumina_sid');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        session_start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function guard(): void
    {
        if (empty($_SESSION['usuario_id'])) {
            header('Location: ?p=login');
            exit;
        }
    }

    /** @return array{id:int,nome:string}|null */
    public function usuarioAtual(): ?array
    {
        if (empty($_SESSION['usuario_id'])) {
            return null;
        }
        return [
            'id'   => (int) $_SESSION['usuario_id'],
            'nome' => (string) $_SESSION['usuario_nome'],
        ];
    }

    public function login(string $email, string $senha, string $ip): bool
    {
        if ($this->cadastro->verificarBloqueio($ip)) {
            return false;
        }

        $usuario = $this->cadastro->buscarUsuarioPorEmail($email);
        if ($usuario === null || !password_verify($senha, (string) $usuario['senha_hash'])) {
            $this->cadastro->registrarTentativa($ip);
            return false;
        }

        $this->cadastro->limparTentativas($ip);
        session_regenerate_id(true);
        $_SESSION['usuario_id']   = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        return true;
    }

    public function logout(): void
    {
        session_unset();
        session_destroy();
    }

    public function podeRegistrar(): bool
    {
        return $this->cadastro->contarUsuarios() === 0 || !empty($_SESSION['usuario_id']);
    }

    /**
     * @throws \RuntimeException se não autorizado
     * @throws \InvalidArgumentException se dados inválidos
     */
    public function registrar(string $nome, string $email, string $senha): void
    {
        if (!$this->podeRegistrar()) {
            throw new \RuntimeException('Acesso negado: faça login para criar novos usuários.');
        }
        if (trim($nome) === '' || trim($email) === '') {
            throw new \InvalidArgumentException('Nome e email são obrigatórios.');
        }
        if (strlen($senha) < 8) {
            throw new \InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
        }
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $this->cadastro->inserirUsuario(trim($nome), trim($email), $hash);
    }

    public function csrfToken(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    public function validarCsrf(string $token): bool
    {
        return isset($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}
