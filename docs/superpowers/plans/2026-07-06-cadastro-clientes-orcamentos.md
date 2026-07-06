# Cadastro de Clientes e Orçamentos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Módulo web PHP puro com cadastro de clientes, templates de serviço e orçamentos com fluxo rascunho→aprovado→emitido, integrado ao NfseClient existente.

**Architecture:** Router único `public/web.php` despacha para páginas em `public/pages/`. Duas novas classes em `src/` (`Cadastro` para DB, `Auth` para sessão). Bootstrap 5 via CDN para UI. Mesmo SQLite do projeto, novas tabelas via `migrate()` idempotente.

**Tech Stack:** PHP 8.2, SQLite (PDO), Bootstrap 5.3 CDN, PHPUnit 11, Nginx + PHP-FPM para produção.

## Global Constraints

- PHP ≥ 8.1 (`declare(strict_types=1)` em todo arquivo PHP puro)
- PSR-12 sem erros (`vendor/bin/phpcs --standard=PSR12 -n src/ public/`)
- Namespace `EmissorGyn\` para classes em `src/`
- Zero dependências novas de produção; PHPUnit apenas em `require-dev`
- Senhas: `password_hash(PASSWORD_BCRYPT)`, mínimo 8 caracteres
- CSRF: token na sessão, validado em todo POST
- Sessão: `lumina_sid`, `Secure`, `HttpOnly`, `SameSite=Strict`

---

### Task 1: PHPUnit setup + `src/Cadastro.php`

**Files:**
- Create: `src/Cadastro.php`
- Create: `tests/CadastroTest.php`
- Create: `phpunit.xml`
- Modify: `composer.json`
- Modify: `.github/workflows/ci.yml`

**Interfaces — Produces:**
```php
new Cadastro(string $dbPath)          // ':memory:' suportado para testes

// Usuários
Cadastro::contarUsuarios(): int
Cadastro::buscarUsuarioPorEmail(string $email): ?array  // ['id','nome','email','senha_hash']
Cadastro::inserirUsuario(string $nome, string $email, string $senhaHash): int

// Rate limit
Cadastro::registrarTentativa(string $ip): void
Cadastro::verificarBloqueio(string $ip): bool   // true = bloqueado
Cadastro::limparTentativas(string $ip): void

// Clientes
Cadastro::listarClientes(string $busca = ''): array
Cadastro::buscarCliente(int $id): ?array
Cadastro::inserirCliente(array $dados): int
Cadastro::atualizarCliente(int $id, array $dados): void
Cadastro::desativarCliente(int $id): void

// Serviços
Cadastro::listarServicos(): array
Cadastro::buscarServico(int $id): ?array
Cadastro::inserirServico(array $dados): int
Cadastro::atualizarServico(int $id, array $dados): void
Cadastro::desativarServico(int $id): void

// Orçamentos
Cadastro::listarOrcamentos(string $status = '', string $busca = ''): array
Cadastro::buscarOrcamento(int $id): ?array  // joins clientes; inclui nfse_numero
Cadastro::inserirOrcamento(array $dados): int
Cadastro::atualizarOrcamento(int $id, array $dados): void
Cadastro::aprovarOrcamento(int $id, int $usuarioId): void
Cadastro::emitirOrcamento(int $id, int $emissaoId, string $nfseNumero): void
Cadastro::cancelarOrcamento(int $id): void
Cadastro::estatisticas(): array  // ['total_clientes', 'orcamentos'=>[status=>count], 'ultimas_emissoes']
```

- [ ] **Step 1: Adicionar PHPUnit ao composer.json**

Editar `composer.json` — substituir o bloco completo:

```json
{
    "name": "pedro/emissor-nfse-goiania",
    "description": "Emissor de NFS-e ABRASF 2.04 para o SGISS da Prefeitura de Goiânia (provedor ISSNet), construído sobre componentes NFePHP",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.1",
        "ext-curl": "*",
        "ext-dom": "*",
        "ext-json": "*",
        "ext-libxml": "*",
        "ext-openssl": "*",
        "ext-pdo": "*",
        "ext-pdo_sqlite": "*",
        "ext-simplexml": "*",
        "nfephp-org/sped-common": "^5.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "squizlabs/php_codesniffer": "^3.10"
    },
    "autoload": {
        "psr-4": {
            "EmissorGyn\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "EmissorGynTest\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit --colors=always",
        "lint": "phpcs --standard=PSR12 -n src/ public/"
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Criar `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Instalar dependências**

```bash
composer install
```

Esperado: instalação do phpunit/phpunit e dependências.

- [ ] **Step 4: Criar `tests/CadastroTest.php` com os testes**

```php
<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Cadastro;
use PHPUnit\Framework\TestCase;

final class CadastroTest extends TestCase
{
    private Cadastro $db;

    protected function setUp(): void
    {
        $this->db = new Cadastro(':memory:');
    }

    public function testMigrateCreatesTablesIdempotently(): void
    {
        // second instantiation on same file should not throw
        $db2 = new Cadastro(':memory:');
        $this->assertInstanceOf(Cadastro::class, $db2);
    }

    public function testContarUsuariosVazio(): void
    {
        $this->assertSame(0, $this->db->contarUsuarios());
    }

    public function testInserirEBuscarUsuario(): void
    {
        $id = $this->db->inserirUsuario('Ana', 'ana@lumina.com', 'hash123');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->db->contarUsuarios());

        $usuario = $this->db->buscarUsuarioPorEmail('ana@lumina.com');
        $this->assertNotNull($usuario);
        $this->assertSame('Ana', $usuario['nome']);
    }

    public function testBuscarUsuarioInexistente(): void
    {
        $this->assertNull($this->db->buscarUsuarioPorEmail('nao@existe.com'));
    }

    public function testRateLimit(): void
    {
        $this->assertFalse($this->db->verificarBloqueio('1.2.3.4'));

        for ($i = 0; $i < 5; $i++) {
            $this->db->registrarTentativa('1.2.3.4');
        }

        $this->assertTrue($this->db->verificarBloqueio('1.2.3.4'));
    }

    public function testLimparTentativasDesbloqueia(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->db->registrarTentativa('9.9.9.9');
        }
        $this->assertTrue($this->db->verificarBloqueio('9.9.9.9'));
        $this->db->limparTentativas('9.9.9.9');
        $this->assertFalse($this->db->verificarBloqueio('9.9.9.9'));
    }

    public function testCrudClientes(): void
    {
        $dados = [
            'razao_social' => 'Empresa Teste LTDA',
            'cpf_cnpj' => '11222333000181',
            'email' => 'teste@empresa.com',
            'telefone' => '62999990000',
            'logradouro' => 'Rua A',
            'numero' => '100',
            'complemento' => '',
            'bairro' => 'Centro',
            'codigo_municipio' => '5208707',
            'uf' => 'GO',
            'cep' => '74000000',
        ];

        $id = $this->db->inserirCliente($dados);
        $this->assertGreaterThan(0, $id);

        $cliente = $this->db->buscarCliente($id);
        $this->assertNotNull($cliente);
        $this->assertSame('Empresa Teste LTDA', $cliente['razao_social']);

        $dados['razao_social'] = 'Empresa Atualizada LTDA';
        $this->db->atualizarCliente($id, $dados);
        $cliente = $this->db->buscarCliente($id);
        $this->assertSame('Empresa Atualizada LTDA', $cliente['razao_social']);

        $lista = $this->db->listarClientes();
        $this->assertCount(1, $lista);

        $this->db->desativarCliente($id);
        $this->assertCount(0, $this->db->listarClientes());
    }

    public function testListarClientesComBusca(): void
    {
        $this->db->inserirCliente([
            'razao_social' => 'Solar Engenharia',
            'cpf_cnpj' => '11111111000111',
        ]);
        $this->db->inserirCliente([
            'razao_social' => 'Outro Negocio',
            'cpf_cnpj' => '22222222000122',
        ]);

        $resultado = $this->db->listarClientes('Solar');
        $this->assertCount(1, $resultado);
        $this->assertSame('Solar Engenharia', $resultado[0]['razao_social']);
    }

    public function testCrudServicos(): void
    {
        $dados = [
            'nome' => 'Instalação Fotovoltaica 5kWp',
            'item_lista_servico' => '7.02',
            'codigo_cnae' => '4321500',
            'codigo_tributacao_municipio' => '',
            'discriminacao' => 'Instalação sistema fotovoltaico',
            'aliquota' => '2.00',
            'exigibilidade_iss' => 1,
            'iss_retido' => 2,
        ];

        $id = $this->db->inserirServico($dados);
        $this->assertGreaterThan(0, $id);

        $servico = $this->db->buscarServico($id);
        $this->assertNotNull($servico);
        $this->assertSame('7.02', $servico['item_lista_servico']);

        $lista = $this->db->listarServicos();
        $this->assertCount(1, $lista);

        $this->db->desativarServico($id);
        $this->assertCount(0, $this->db->listarServicos());
    }

    public function testCrudOrcamentos(): void
    {
        $clienteId = $this->db->inserirCliente([
            'razao_social' => 'Cliente Orcamento',
            'cpf_cnpj' => '33333333000133',
        ]);
        $usuarioId = $this->db->inserirUsuario('Operador', 'op@lumina.com', 'hash');

        $dados = [
            'cliente_id' => $clienteId,
            'servico_id' => '',
            'competencia' => '2026-07-01',
            'valor_servicos' => '5000.00',
            'item_lista_servico' => '7.02',
            'codigo_cnae' => '4321500',
            'codigo_tributacao_municipio' => '',
            'discriminacao' => 'Serviço de instalação',
            'aliquota' => '',
            'exigibilidade_iss' => 1,
            'iss_retido' => 2,
            'criado_por' => $usuarioId,
        ];

        $id = $this->db->inserirOrcamento($dados);
        $this->assertGreaterThan(0, $id);

        $orc = $this->db->buscarOrcamento($id);
        $this->assertNotNull($orc);
        $this->assertSame('rascunho', $orc['status']);
        $this->assertSame('Cliente Orcamento', $orc['razao_social']);

        $this->db->aprovarOrcamento($id, $usuarioId);
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('aprovado', $orc['status']);

        $this->db->emitirOrcamento($id, 99, '123');
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('emitido', $orc['status']);
        $this->assertSame('123', $orc['nfse_numero']);
    }

    public function testCancelarOrcamento(): void
    {
        $clienteId = $this->db->inserirCliente([
            'razao_social' => 'Cancel Test',
            'cpf_cnpj' => '44444444000144',
        ]);
        $usuarioId = $this->db->inserirUsuario('Op2', 'op2@lumina.com', 'hash');

        $id = $this->db->inserirOrcamento([
            'cliente_id' => $clienteId,
            'servico_id' => '',
            'competencia' => '2026-07-01',
            'valor_servicos' => '1000.00',
            'item_lista_servico' => '7.02',
            'discriminacao' => 'Teste',
            'exigibilidade_iss' => 1,
            'iss_retido' => 2,
            'criado_por' => $usuarioId,
        ]);

        $this->db->cancelarOrcamento($id);
        $orc = $this->db->buscarOrcamento($id);
        $this->assertSame('cancelado', $orc['status']);
    }

    public function testEstatisticas(): void
    {
        $stats = $this->db->estatisticas();
        $this->assertSame(0, $stats['total_clientes']);
        $this->assertArrayHasKey('rascunho', $stats['orcamentos']);
        $this->assertIsArray($stats['ultimas_emissoes']);
    }
}
```

- [ ] **Step 5: Rodar testes — esperar FALHA (classe não existe)**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: `Error: Class "EmissorGyn\Cadastro" not found`

- [ ] **Step 6: Criar `src/Cadastro.php`**

```php
<?php

/**
 * Persistência das entidades do módulo web: usuários, clientes, serviços e orçamentos.
 */

declare(strict_types=1);

namespace EmissorGyn;

final class Cadastro
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        if ($dbPath !== ':memory:') {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
        }
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS usuarios (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                nome        TEXT    NOT NULL,
                email       TEXT    NOT NULL UNIQUE,
                senha_hash  TEXT    NOT NULL,
                criado_em   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS login_tentativas (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ip            TEXT    NOT NULL,
                tentativas    INTEGER NOT NULL DEFAULT 1,
                bloqueado_ate TEXT,
                ultima_em     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_login_ip ON login_tentativas (ip);
            CREATE TABLE IF NOT EXISTS clientes (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                razao_social      TEXT NOT NULL,
                cpf_cnpj          TEXT NOT NULL UNIQUE,
                email             TEXT,
                telefone          TEXT,
                logradouro        TEXT,
                numero            TEXT,
                complemento       TEXT,
                bairro            TEXT,
                codigo_municipio  TEXT,
                uf                TEXT,
                cep               TEXT,
                ativo             INTEGER NOT NULL DEFAULT 1,
                criado_em         TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS servicos (
                id                            INTEGER PRIMARY KEY AUTOINCREMENT,
                nome                          TEXT NOT NULL,
                item_lista_servico            TEXT NOT NULL,
                codigo_cnae                   TEXT,
                codigo_tributacao_municipio   TEXT,
                discriminacao                 TEXT NOT NULL,
                aliquota                      TEXT,
                exigibilidade_iss             INTEGER NOT NULL DEFAULT 1,
                iss_retido                    INTEGER NOT NULL DEFAULT 2,
                ativo                         INTEGER NOT NULL DEFAULT 1,
                criado_em                     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS rps_sequencia (
                serie         TEXT PRIMARY KEY,
                ultimo_numero INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS emissoes (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                criado_em           TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                rps_numero          INTEGER NOT NULL,
                rps_serie           TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT 'enviando',
                nfse_numero         TEXT,
                codigo_verificacao  TEXT,
                valor_servicos      TEXT,
                tomador_doc         TEXT,
                tomador_razao       TEXT,
                xml_envio           TEXT,
                xml_retorno         TEXT,
                erro                TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_emissoes_nfse ON emissoes (nfse_numero);
            CREATE INDEX IF NOT EXISTS idx_emissoes_rps  ON emissoes (rps_numero, rps_serie);
            CREATE TABLE IF NOT EXISTS orcamentos (
                id                            INTEGER PRIMARY KEY AUTOINCREMENT,
                cliente_id                    INTEGER NOT NULL REFERENCES clientes(id),
                servico_id                    INTEGER REFERENCES servicos(id),
                status                        TEXT NOT NULL DEFAULT 'rascunho',
                competencia                   TEXT NOT NULL,
                valor_servicos                TEXT NOT NULL,
                item_lista_servico            TEXT NOT NULL,
                codigo_cnae                   TEXT,
                codigo_tributacao_municipio   TEXT,
                discriminacao                 TEXT NOT NULL,
                aliquota                      TEXT,
                exigibilidade_iss             INTEGER NOT NULL DEFAULT 1,
                iss_retido                    INTEGER NOT NULL DEFAULT 2,
                valor_deducoes                TEXT,
                valor_pis                     TEXT,
                valor_cofins                  TEXT,
                valor_inss                    TEXT,
                valor_ir                      TEXT,
                valor_csll                    TEXT,
                desconto_incondicionado       TEXT,
                desconto_condicionado         TEXT,
                nfse_numero                   TEXT,
                emissao_id                    INTEGER REFERENCES emissoes(id),
                criado_por                    INTEGER REFERENCES usuarios(id),
                aprovado_por                  INTEGER REFERENCES usuarios(id),
                criado_em                     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                aprovado_em                   TEXT,
                emitido_em                    TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_orcamentos_cliente ON orcamentos (cliente_id);
            CREATE INDEX IF NOT EXISTS idx_orcamentos_status  ON orcamentos (status);
        SQL);
    }

    // ─── Usuários ────────────────────────────────────────────────────────────

    public function contarUsuarios(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function buscarUsuarioPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function inserirUsuario(string $nome, string $email, string $senhaHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$nome, $email, $senhaHash]);
        return (int) $this->pdo->lastInsertId();
    }

    // ─── Rate limiting ───────────────────────────────────────────────────────

    public function registrarTentativa(string $ip): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO login_tentativas (ip, tentativas, ultima_em)
            VALUES (?, 1, datetime('now','localtime'))
            ON CONFLICT(ip) DO UPDATE SET
                tentativas    = tentativas + 1,
                bloqueado_ate = CASE
                    WHEN tentativas + 1 >= 5
                    THEN datetime('now', '+15 minutes', 'localtime')
                    ELSE bloqueado_ate
                END,
                ultima_em = datetime('now','localtime')
        SQL)->execute([$ip]);
    }

    public function verificarBloqueio(string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM login_tentativas
              WHERE ip = ? AND bloqueado_ate > datetime('now','localtime')"
        );
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() !== false;
    }

    public function limparTentativas(string $ip): void
    {
        $this->pdo->prepare(
            'DELETE FROM login_tentativas WHERE ip = ?'
        )->execute([$ip]);
    }

    // ─── Clientes ────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarClientes(string $busca = ''): array
    {
        if ($busca !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM clientes WHERE ativo = 1
                  AND (razao_social LIKE ? OR cpf_cnpj LIKE ?)
                  ORDER BY razao_social'
            );
            $stmt->execute(["%{$busca}%", "%{$busca}%"]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT * FROM clientes WHERE ativo = 1 ORDER BY razao_social'
            );
        }
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarCliente(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirCliente(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clientes
                (razao_social, cpf_cnpj, email, telefone, logradouro, numero,
                 complemento, bairro, codigo_municipio, uf, cep)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dados['razao_social'],
            $dados['cpf_cnpj'],
            $dados['email'] ?? null,
            $dados['telefone'] ?? null,
            $dados['logradouro'] ?? null,
            $dados['numero'] ?? null,
            $dados['complemento'] ?? null,
            $dados['bairro'] ?? null,
            $dados['codigo_municipio'] ?? null,
            $dados['uf'] ?? null,
            $dados['cep'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarCliente(int $id, array $dados): void
    {
        $this->pdo->prepare(
            'UPDATE clientes SET
                razao_social = ?, cpf_cnpj = ?, email = ?, telefone = ?,
                logradouro = ?, numero = ?, complemento = ?, bairro = ?,
                codigo_municipio = ?, uf = ?, cep = ?
             WHERE id = ?'
        )->execute([
            $dados['razao_social'],
            $dados['cpf_cnpj'],
            $dados['email'] ?? null,
            $dados['telefone'] ?? null,
            $dados['logradouro'] ?? null,
            $dados['numero'] ?? null,
            $dados['complemento'] ?? null,
            $dados['bairro'] ?? null,
            $dados['codigo_municipio'] ?? null,
            $dados['uf'] ?? null,
            $dados['cep'] ?? null,
            $id,
        ]);
    }

    public function desativarCliente(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE clientes SET ativo = 0 WHERE id = ?'
        )->execute([$id]);
    }

    // ─── Serviços ────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarServicos(): array
    {
        return $this->pdo->query(
            'SELECT * FROM servicos WHERE ativo = 1 ORDER BY nome'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarServico(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM servicos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirServico(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO servicos
                (nome, item_lista_servico, codigo_cnae, codigo_tributacao_municipio,
                 discriminacao, aliquota, exigibilidade_iss, iss_retido)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dados['nome'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarServico(int $id, array $dados): void
    {
        $this->pdo->prepare(
            'UPDATE servicos SET
                nome = ?, item_lista_servico = ?, codigo_cnae = ?,
                codigo_tributacao_municipio = ?, discriminacao = ?, aliquota = ?,
                exigibilidade_iss = ?, iss_retido = ?
             WHERE id = ?'
        )->execute([
            $dados['nome'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $id,
        ]);
    }

    public function desativarServico(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE servicos SET ativo = 0 WHERE id = ?'
        )->execute([$id]);
    }

    // ─── Orçamentos ──────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarOrcamentos(string $status = '', string $busca = ''): array
    {
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($busca !== '') {
            $where[] = 'c.razao_social LIKE ?';
            $params[] = "%{$busca}%";
        }
        $sql = 'SELECT o.*, c.razao_social, c.cpf_cnpj FROM orcamentos o
                JOIN clientes c ON c.id = o.cliente_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarOrcamento(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.razao_social, c.cpf_cnpj,
                    c.email AS cliente_email, c.telefone,
                    c.logradouro, c.numero, c.complemento,
                    c.bairro, c.codigo_municipio, c.uf, c.cep
               FROM orcamentos o
               JOIN clientes c ON c.id = o.cliente_id
              WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirOrcamento(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orcamentos
                (cliente_id, servico_id, competencia, valor_servicos,
                 item_lista_servico, codigo_cnae, codigo_tributacao_municipio,
                 discriminacao, aliquota, exigibilidade_iss, iss_retido,
                 valor_deducoes, valor_pis, valor_cofins, valor_inss,
                 valor_ir, valor_csll, desconto_incondicionado, desconto_condicionado,
                 criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $svcId = (isset($dados['servico_id']) && $dados['servico_id'] !== '')
            ? (int) $dados['servico_id']
            : null;
        $stmt->execute([
            (int) $dados['cliente_id'],
            $svcId,
            $dados['competencia'],
            $dados['valor_servicos'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $dados['valor_deducoes'] ?? null,
            $dados['valor_pis'] ?? null,
            $dados['valor_cofins'] ?? null,
            $dados['valor_inss'] ?? null,
            $dados['valor_ir'] ?? null,
            $dados['valor_csll'] ?? null,
            $dados['desconto_incondicionado'] ?? null,
            $dados['desconto_condicionado'] ?? null,
            (int) $dados['criado_por'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarOrcamento(int $id, array $dados): void
    {
        $svcId = (isset($dados['servico_id']) && $dados['servico_id'] !== '')
            ? (int) $dados['servico_id']
            : null;
        $this->pdo->prepare(
            "UPDATE orcamentos SET
                cliente_id = ?, servico_id = ?, competencia = ?, valor_servicos = ?,
                item_lista_servico = ?, codigo_cnae = ?, codigo_tributacao_municipio = ?,
                discriminacao = ?, aliquota = ?, exigibilidade_iss = ?, iss_retido = ?,
                valor_deducoes = ?, valor_pis = ?, valor_cofins = ?, valor_inss = ?,
                valor_ir = ?, valor_csll = ?, desconto_incondicionado = ?,
                desconto_condicionado = ?
             WHERE id = ? AND status = 'rascunho'"
        )->execute([
            (int) $dados['cliente_id'],
            $svcId,
            $dados['competencia'],
            $dados['valor_servicos'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $dados['valor_deducoes'] ?? null,
            $dados['valor_pis'] ?? null,
            $dados['valor_cofins'] ?? null,
            $dados['valor_inss'] ?? null,
            $dados['valor_ir'] ?? null,
            $dados['valor_csll'] ?? null,
            $dados['desconto_incondicionado'] ?? null,
            $dados['desconto_condicionado'] ?? null,
            $id,
        ]);
    }

    public function aprovarOrcamento(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos
                SET status = 'aprovado',
                    aprovado_por = ?,
                    aprovado_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'rascunho'"
        )->execute([$usuarioId, $id]);
    }

    public function emitirOrcamento(int $id, int $emissaoId, string $nfseNumero): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos
                SET status      = 'emitido',
                    emissao_id  = ?,
                    nfse_numero = ?,
                    emitido_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'aprovado'"
        )->execute([$emissaoId, $nfseNumero, $id]);
    }

    public function cancelarOrcamento(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos SET status = 'cancelado'
              WHERE id = ? AND status IN ('rascunho','aprovado')"
        )->execute([$id]);
    }

    /** @return array<string,mixed> */
    public function estatisticas(): array
    {
        $porStatus = $this->pdo->query(
            'SELECT status, COUNT(*) AS total FROM orcamentos GROUP BY status'
        )->fetchAll();

        $statusMap = ['rascunho' => 0, 'aprovado' => 0, 'emitido' => 0, 'cancelado' => 0];
        foreach ($porStatus as $row) {
            $statusMap[(string) $row['status']] = (int) $row['total'];
        }

        $totalClientes = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM clientes WHERE ativo = 1'
        )->fetchColumn();

        try {
            $ultimasEmissoes = $this->pdo->query(
                "SELECT id, criado_em, nfse_numero, valor_servicos, tomador_razao, status
                   FROM emissoes WHERE status = 'autorizada' ORDER BY id DESC LIMIT 5"
            )->fetchAll();
        } catch (\PDOException) {
            $ultimasEmissoes = [];
        }

        return [
            'total_clientes'   => $totalClientes,
            'orcamentos'       => $statusMap,
            'ultimas_emissoes' => $ultimasEmissoes,
        ];
    }
}
```

- [ ] **Step 7: Rodar testes — esperar PASS**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos os testes verdes.

- [ ] **Step 8: Verificar lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/Cadastro.php
```

Esperado: nenhuma saída (zero erros).

- [ ] **Step 9: Atualizar CI para rodar PHPUnit**

Editar `.github/workflows/ci.yml`, adicionar step após lint:

```yaml
      - name: Tests
        run: vendor/bin/phpunit --colors=always
```

- [ ] **Step 10: Commit**

```bash
git add composer.json phpunit.xml src/Cadastro.php tests/CadastroTest.php .github/workflows/ci.yml
git commit -m "feat: add Cadastro data layer with PHPUnit tests"
```

---

### Task 2: `src/Auth.php`

**Files:**
- Create: `src/Auth.php`
- Modify: `tests/CadastroTest.php` (sem alterações — Auth é testado via integração)

**Interfaces — Consumes:** `Cadastro` (task 1)

**Interfaces — Produces:**
```php
new Auth(Cadastro $cadastro)
Auth::iniciarSessao(): void        // configura e inicia sessão PHP
Auth::guard(): void                // redireciona para ?p=login se não autenticado
Auth::usuarioAtual(): ?array       // ['id'=>int,'nome'=>string] ou null
Auth::login(string $email, string $senha, string $ip): bool
Auth::logout(): void
Auth::podeRegistrar(): bool        // true se banco vazio OU sessão ativa
Auth::registrar(string $nome, string $email, string $senha): void  // throws
Auth::csrfToken(): string
Auth::validarCsrf(string $token): bool
```

- [ ] **Step 1: Criar `src/Auth.php`**

```php
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
```

- [ ] **Step 2: Verificar lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/Auth.php
```

Esperado: sem saída.

- [ ] **Step 3: Commit**

```bash
git add src/Auth.php
git commit -m "feat: add Auth class for session management and CSRF"
```

---

### Task 3: `ResponseParser::parseUrlNfse()`

**Files:**
- Modify: `src/ResponseParser.php`

**Interfaces — Produces:**
```php
ResponseParser::parseUrlNfse(string $xml): ?string  // extrai URL do retorno ConsultarUrlNfse
```

- [ ] **Step 1: Adicionar teste em `tests/CadastroTest.php`**

Adicionar ao final da classe `CadastroTest`, antes do `}` final:

```php
    public function testParseUrlNfse(): void
    {
        $xml = '<?xml version="1.0"?><ConsultarUrlNfseResposta><Url>https://nfse.goiania.go.gov.br/danfse/123</Url></ConsultarUrlNfseResposta>';
        $url = \EmissorGyn\ResponseParser::parseUrlNfse($xml);
        $this->assertSame('https://nfse.goiania.go.gov.br/danfse/123', $url);
    }

    public function testParseUrlNfseRetornaNullQuandoAusente(): void
    {
        $url = \EmissorGyn\ResponseParser::parseUrlNfse('<Resposta><Erro>falha</Erro></Resposta>');
        $this->assertNull($url);
    }
```

- [ ] **Step 2: Rodar testes — esperar FALHA**

```bash
vendor/bin/phpunit --colors=always --filter testParseUrl
```

Esperado: `Error: Call to undefined method EmissorGyn\ResponseParser::parseUrlNfse()`

- [ ] **Step 3: Adicionar `parseUrlNfse` em `src/ResponseParser.php`**

Adicionar antes do método `load()`:

```php
    public static function parseUrlNfse(string $xml): ?string
    {
        try {
            $dom = self::load($xml);
        } catch (\RuntimeException) {
            return null;
        }
        $el = $dom->getElementsByTagName('Url')->item(0)
            ?? $dom->getElementsByTagName('ConsultarUrlNfseResult')->item(0);
        if ($el === null) {
            return null;
        }
        $url = trim($el->textContent);
        return $url !== '' ? $url : null;
    }
```

- [ ] **Step 4: Rodar testes — esperar PASS**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos os testes verdes.

- [ ] **Step 5: Commit**

```bash
git add src/ResponseParser.php tests/CadastroTest.php
git commit -m "feat: add ResponseParser::parseUrlNfse for DANFS-e URL extraction"
```

---

### Task 4: Router + layout compartilhado

**Files:**
- Create: `public/web.php`
- Create: `public/pages/_head.php`
- Create: `public/pages/_foot.php`
- Modify: `.gitignore`

- [ ] **Step 1: Criar `public/web.php`**

```php
<?php

/**
 * Entry point da interface web Lumina — router e bootstrap.
 */

declare(strict_types=1);

use EmissorGyn\Auth;
use EmissorGyn\Cadastro;
use EmissorGyn\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$config   = new Config(dirname(__DIR__));
$cadastro = new Cadastro($config->path('DB_PATH', 'storage/nfse.sqlite'));
$auth     = new Auth($cadastro);
$auth->iniciarSessao();

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$p = preg_replace('/[^a-z\/]/', '', strtolower(trim($_GET['p'] ?? '')));

$rotas = [
    ''               => 'dashboard',
    'login'          => 'login',
    'clientes'       => 'clientes/index',
    'clientes/form'  => 'clientes/form',
    'servicos'       => 'servicos/index',
    'servicos/form'  => 'servicos/form',
    'orcamentos'     => 'orcamentos/index',
    'orcamentos/form' => 'orcamentos/form',
    'orcamentos/ver' => 'orcamentos/ver',
];

$pagina = $rotas[$p] ?? null;
if ($pagina === null) {
    http_response_code(404);
    exit('Página não encontrada.');
}

if ($pagina !== 'login') {
    $auth->guard();
}

define('PAGES_DIR', __DIR__ . '/pages');

require __DIR__ . '/pages/' . $pagina . '.php';
```

- [ ] **Step 2: Criar `public/pages/_head.php`**

```php
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
<?php $usuario = $auth->usuarioAtual(); if ($usuario !== null): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="?p=">⚡ Lumina NFS-e</a>
        <div class="navbar-nav ms-auto flex-row gap-3">
            <a class="nav-link" href="?p=clientes">Clientes</a>
            <a class="nav-link" href="?p=servicos">Serviços</a>
            <a class="nav-link" href="?p=orcamentos">Orçamentos</a>
            <a class="nav-link text-warning" href="?p=login&acao=logout">
                Sair (<?= h($usuario['nome']) ?>)
            </a>
        </div>
    </div>
</nav>
<?php endif; ?>
<div class="container pb-5">
<?php if (!empty($flash)): ?>
<div class="alert alert-<?= h($flash['tipo']) ?> alert-dismissible fade show" role="alert">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Criar `public/pages/_foot.php`**

```php
</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

- [ ] **Step 4: Atualizar `.gitignore`**

Adicionar ao final do arquivo:

```
/vendor/
/.env
/storage/*.sqlite*
/storage/xml/*
!storage/xml/.gitkeep
*.pfx
*.p12
/public/pages/
```

Espera — não queremos ignorar `public/pages/`. Não alterar `.gitignore` para `public/pages/`. Apenas verificar que o `.gitignore` atual não ignora esses arquivos.

- [ ] **Step 5: Testar o router manualmente**

```bash
php -S 127.0.0.1:8080 public/web.php
```

Abrir `http://127.0.0.1:8080/?p=login` — deve exibir página em branco ou erro de arquivo não encontrado (pois `login.php` ainda não existe). Confirme que não há erro de PHP fatal no servidor.

Parar o servidor com Ctrl+C.

- [ ] **Step 6: Commit**

```bash
git add public/web.php public/pages/_head.php public/pages/_foot.php
git commit -m "feat: add web router and shared layout (head/foot)"
```

---

### Task 5: Página de login e cadastro de usuário

**Files:**
- Create: `public/pages/login.php`

- [ ] **Step 1: Criar diretório e arquivo**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/public/pages
```

```php
<?php
// public/pages/login.php

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
        $flash = ['tipo' => 'danger', 'msg' => 'Email ou senha incorretos, ou IP bloqueado por excesso de tentativas.'];
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
                <h4 class="card-title mb-4 text-center">⚡ Lumina NFS-e</h4>
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

                <?php if ($podeRegistrar): ?>
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
                            <input type="password" name="senha" class="form-control" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary w-100">Criar Conta</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 2: Testar manualmente**

```bash
cp .env.example .env   # se ainda não existir
php -S 127.0.0.1:8080 public/web.php
```

- Abrir `http://127.0.0.1:8080/?p=login`
- Deve exibir o formulário com Bootstrap
- Criar o primeiro usuário via "Criar nova conta"
- Logar — deve redirecionar para `?p=` (dashboard, ainda sem página)
- Verificar que "Criar nova conta" some após login

- [ ] **Step 3: Commit**

```bash
git add public/pages/login.php
git commit -m "feat: add login and user registration page"
```

---

### Task 6: Dashboard

**Files:**
- Create: `public/pages/dashboard.php`

- [ ] **Step 1: Criar `public/pages/dashboard.php`**

```php
<?php
// public/pages/dashboard.php

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
    <?php foreach ($statusCores as $status => $cor): ?>
    <div class="col-md-2">
        <div class="card text-center h-100 border-<?= $cor ?>">
            <div class="card-body">
                <div class="display-5 fw-bold"><?= (int) $stats['orcamentos'][$status] ?></div>
                <div class="text-muted small"><?= ucfirst(h($status)) ?></div>
                <a href="?p=orcamentos&status=<?= h($status) ?>"
                   class="btn btn-sm btn-outline-<?= $cor ?> mt-2">Ver</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h5>Últimas emissões autorizadas</h5>
<?php if (empty($stats['ultimas_emissoes'])): ?>
<p class="text-muted">Nenhuma emissão ainda.</p>
<?php else: ?>
<table class="table table-sm table-hover">
    <thead>
    <tr><th>NFS-e</th><th>Tomador</th><th>Valor</th><th>Data</th></tr>
    </thead>
    <tbody>
    <?php foreach ($stats['ultimas_emissoes'] as $e): ?>
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
```

- [ ] **Step 2: Testar manualmente**

```bash
php -S 127.0.0.1:8080 public/web.php
```

Abrir `http://127.0.0.1:8080/?p=` — deve exibir os cartões de resumo.

- [ ] **Step 3: Commit**

```bash
git add public/pages/dashboard.php
git commit -m "feat: add dashboard with stats cards"
```

---

### Task 7: Páginas de Clientes

**Files:**
- Create: `public/pages/clientes/index.php`
- Create: `public/pages/clientes/form.php`

- [ ] **Step 1: Criar diretório**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/public/pages/clientes
```

- [ ] **Step 2: Criar `public/pages/clientes/index.php`**

```php
<?php
// public/pages/clientes/index.php

$busca = trim($_GET['busca'] ?? '');
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
        <?php if ($busca !== ''): ?>
        <a href="?p=clientes" class="btn btn-link">Limpar</a>
        <?php endif; ?>
    </div>
</form>
<?php if (empty($clientes)): ?>
<p class="text-muted">Nenhum cliente encontrado.</p>
<?php else: ?>
<table class="table table-hover">
    <thead>
    <tr><th>Razão Social</th><th>CPF/CNPJ</th><th>Email</th><th>UF</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
    <tr>
        <td><?= h($c['razao_social']) ?></td>
        <td><?= h($c['cpf_cnpj']) ?></td>
        <td><?= h($c['email'] ?? '') ?></td>
        <td><?= h($c['uf'] ?? '') ?></td>
        <td>
            <a href="?p=clientes/form&id=<?= (int) $c['id'] ?>"
               class="btn btn-sm btn-outline-primary">Editar</a>
            <a href="?p=orcamentos/form&cliente_id=<?= (int) $c['id'] ?>"
               class="btn btn-sm btn-outline-success">+ Orçamento</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 3: Criar `public/pages/clientes/form.php`**

```php
<?php
// public/pages/clientes/form.php

$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;
$cliente = $id !== null ? $cadastro->buscarCliente($id) : null;
$flash   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $dados = [
            'razao_social'      => trim($_POST['razao_social'] ?? ''),
            'cpf_cnpj'          => preg_replace('/\D/', '', $_POST['cpf_cnpj'] ?? ''),
            'email'             => trim($_POST['email'] ?? ''),
            'telefone'          => trim($_POST['telefone'] ?? ''),
            'logradouro'        => trim($_POST['logradouro'] ?? ''),
            'numero'            => trim($_POST['numero'] ?? ''),
            'complemento'       => trim($_POST['complemento'] ?? ''),
            'bairro'            => trim($_POST['bairro'] ?? ''),
            'codigo_municipio'  => trim($_POST['codigo_municipio'] ?? ''),
            'uf'                => strtoupper(trim($_POST['uf'] ?? '')),
            'cep'               => preg_replace('/\D/', '', $_POST['cep'] ?? ''),
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
        <?php if ($id !== null): ?>
        <a href="?p=orcamentos/form&cliente_id=<?= $id ?>"
           class="btn btn-outline-success">+ Novo Orçamento</a>
        <?php endif; ?>
        <a href="?p=clientes" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 4: Testar manualmente**

```bash
php -S 127.0.0.1:8080 public/web.php
```

- Navegar para `?p=clientes` — tabela vazia
- Criar um cliente via `?p=clientes/form`
- Editar o cliente criado
- Verificar busca por nome

- [ ] **Step 5: Commit**

```bash
git add public/pages/clientes/
git commit -m "feat: add client list and form pages"
```

---

### Task 8: Páginas de Serviços

**Files:**
- Create: `public/pages/servicos/index.php`
- Create: `public/pages/servicos/form.php`

- [ ] **Step 1: Criar diretório**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/public/pages/servicos
```

- [ ] **Step 2: Criar `public/pages/servicos/index.php`**

```php
<?php
// public/pages/servicos/index.php

$servicos  = $cadastro->listarServicos();
$pageTitle = 'Templates de Serviço';
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Templates de Serviço</h2>
    <a href="?p=servicos/form" class="btn btn-primary">+ Novo</a>
</div>
<?php if (empty($servicos)): ?>
<p class="text-muted">Nenhum template cadastrado.</p>
<?php else: ?>
<table class="table table-hover">
    <thead>
    <tr><th>Nome</th><th>Item Lista</th><th>CNAE</th><th>ISS Retido</th><th>Alíquota</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($servicos as $s): ?>
    <tr>
        <td><?= h($s['nome']) ?></td>
        <td><?= h($s['item_lista_servico']) ?></td>
        <td><?= h($s['codigo_cnae'] ?? '') ?></td>
        <td><?= (int) $s['iss_retido'] === 1 ? 'Sim' : 'Não' ?></td>
        <td><?= h($s['aliquota'] ?? '') ?></td>
        <td>
            <a href="?p=servicos/form&id=<?= (int) $s['id'] ?>"
               class="btn btn-sm btn-outline-primary">Editar</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 3: Criar `public/pages/servicos/form.php`**

```php
<?php
// public/pages/servicos/form.php

$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;
$servico = $id !== null ? $cadastro->buscarServico($id) : null;
$flash   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $dados = [
            'nome'                          => trim($_POST['nome'] ?? ''),
            'item_lista_servico'            => trim($_POST['item_lista_servico'] ?? ''),
            'codigo_cnae'                   => trim($_POST['codigo_cnae'] ?? ''),
            'codigo_tributacao_municipio'   => trim($_POST['codigo_tributacao_municipio'] ?? ''),
            'discriminacao'                 => trim($_POST['discriminacao'] ?? ''),
            'aliquota'                      => trim($_POST['aliquota'] ?? ''),
            'exigibilidade_iss'             => (int) ($_POST['exigibilidade_iss'] ?? 1),
            'iss_retido'                    => (int) ($_POST['iss_retido'] ?? 2),
        ];

        if ($dados['nome'] === '' || $dados['item_lista_servico'] === '' || $dados['discriminacao'] === '') {
            $flash = ['tipo' => 'danger', 'msg' => 'Nome, item de lista e discriminação são obrigatórios.'];
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

$v = $servico ?? [];
$exig = (int) ($v['exigibilidade_iss'] ?? 1);
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
               value="<?= h((string) ($v['item_lista_servico'] ?? '')) ?>" placeholder="7.02">
    </div>
    <div class="col-md-3">
        <label class="form-label">Código CNAE</label>
        <input type="text" name="codigo_cnae" class="form-control"
               value="<?= h((string) ($v['codigo_cnae'] ?? '')) ?>" placeholder="4321500">
    </div>
    <div class="col-md-4">
        <label class="form-label">Cód. Tributação Município</label>
        <input type="text" name="codigo_tributacao_municipio" class="form-control"
               value="<?= h((string) ($v['codigo_tributacao_municipio'] ?? '')) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label">Alíquota (%)</label>
        <input type="text" name="aliquota" class="form-control"
               value="<?= h((string) ($v['aliquota'] ?? '')) ?>" placeholder="2.00">
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
            <?php foreach ($exigOpcoes as $val => $label): ?>
            <option value="<?= $val ?>" <?= $exig === $val ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Discriminação do Serviço *</label>
        <textarea name="discriminacao" class="form-control" rows="4" required><?= h((string) ($v['discriminacao'] ?? '')) ?></textarea>
    </div>
    <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <a href="?p=servicos" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 4: Testar manualmente**

```bash
php -S 127.0.0.1:8080 public/web.php
```

- Criar um template de serviço em `?p=servicos/form`
- Editar o template criado
- Verificar listagem em `?p=servicos`

- [ ] **Step 5: Commit**

```bash
git add public/pages/servicos/
git commit -m "feat: add service template list and form pages"
```

---

### Task 9: Páginas de Orçamentos (index + form + ver + emissão)

**Files:**
- Create: `public/pages/orcamentos/index.php`
- Create: `public/pages/orcamentos/form.php`
- Create: `public/pages/orcamentos/ver.php`

- [ ] **Step 1: Criar diretório**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/public/pages/orcamentos
```

- [ ] **Step 2: Criar `public/pages/orcamentos/index.php`**

```php
<?php
// public/pages/orcamentos/index.php

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
            <?php foreach (array_keys($statusCores) as $s): ?>
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
<?php if (empty($orcamentos)): ?>
<p class="text-muted">Nenhum orçamento encontrado.</p>
<?php else: ?>
<table class="table table-hover">
    <thead>
    <tr><th>#</th><th>Cliente</th><th>Valor</th><th>Competência</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($orcamentos as $o): ?>
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
            <a href="?p=orcamentos/ver&id=<?= (int) $o['id'] ?>"
               class="btn btn-sm btn-outline-primary">Ver</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 3: Criar `public/pages/orcamentos/form.php`**

```php
<?php
// public/pages/orcamentos/form.php

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
            'criado_por'                  => $usuario['id'],
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

$v    = $orcamento ?? [];
$tpl  = $tpl ?? [];

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

<?php if (!empty($servicos) && $id === null): ?>
<div class="card mb-4 border-info">
    <div class="card-body">
        <h6 class="card-title text-info">Carregar Template de Serviço</h6>
        <form class="row g-2 align-items-end">
            <input type="hidden" name="p" value="orcamentos/form">
            <?php if ($clienteIdParam): ?>
            <input type="hidden" name="cliente_id" value="<?= $clienteIdParam ?>">
            <?php endif; ?>
            <div class="col-auto">
                <select name="servico_id" class="form-select">
                    <option value="">— Escolha um template —</option>
                    <?php foreach ($servicos as $s): ?>
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
    <?php if (!empty($tpl['id'])): ?>
    <input type="hidden" name="servico_id" value="<?= (int) $tpl['id'] ?>">
    <?php endif; ?>

    <div class="col-md-6">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" class="form-select" required>
            <option value="">— Selecione —</option>
            <?php foreach ($clientes as $c): ?>
            <?php $sel = ((int) ($v['cliente_id'] ?? $clienteIdParam ?? 0)) === (int) $c['id'] ? 'selected' : ''; ?>
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
               value="<?= h((string) ($v['valor_servicos'] ?? '')) ?>" placeholder="3500.00">
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
               value="<?= h((string) ($v['codigo_tributacao_municipio'] ?? $tpl['codigo_tributacao_municipio'] ?? '')) ?>">
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
            <?php foreach (range(1, 7) as $n): ?>
            <option value="<?= $n ?>" <?= $exig === $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Discriminação *</label>
        <textarea name="discriminacao" class="form-control" rows="4" required><?= h((string) ($v['discriminacao'] ?? $tpl['discriminacao'] ?? '')) ?></textarea>
    </div>

    <div class="col-12"><hr><h6 class="text-muted">Deduções e retenções (opcional)</h6></div>
    <?php foreach ($camposOpc as $campo => $label): ?>
    <div class="col-md-3">
        <label class="form-label"><?= h($label) ?></label>
        <input type="text" name="<?= $campo ?>" class="form-control"
               value="<?= h((string) ($v[$campo] ?? '')) ?>" placeholder="0.00">
    </div>
    <?php endforeach; ?>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">Salvar Rascunho</button>
    </div>
</form>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 4: Criar `public/pages/orcamentos/ver.php`**

```php
<?php
// public/pages/orcamentos/ver.php

use EmissorGyn\Config;
use EmissorGyn\NfseClient;
use EmissorGyn\ResponseParser;
use EmissorGyn\Storage;
use EmissorGyn\XmlFactory;

$id        = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$orcamento = $id > 0 ? $cadastro->buscarOrcamento($id) : null;

if ($orcamento === null) {
    http_response_code(404);
    exit('Orçamento não encontrado.');
}

$flash   = null;
$usuario = $auth->usuarioAtual();

// ── Ações GET (DANFS-e — não altera estado) ──────────────────────────────────
if (($_GET['acao'] ?? '') === 'danfse' && $orcamento['status'] === 'emitido') {
    $nfse = (string) ($orcamento['nfse_numero'] ?? '');
    if ($nfse !== '') {
        try {
            $cfg     = new Config(dirname(__DIR__, 3));
            $factory = new XmlFactory($cfg);
            $client  = new NfseClient($cfg, $factory);
            $xmlRet  = $client->consultarUrlNfse($nfse);
            $url     = ResponseParser::parseUrlNfse($xmlRet);
            if ($url !== null) {
                header('Location: ' . $url);
                exit;
            }
            $flash = ['tipo' => 'warning', 'msg' => 'URL do DANFS-e não encontrada na resposta do SGISS.'];
        } catch (\Throwable $e) {
            $flash = ['tipo' => 'danger', 'msg' => 'Erro ao obter DANFS-e: ' . $e->getMessage()];
        }
    }
}

// ── Ações POST (alteram estado) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $acao = trim($_POST['acao'] ?? '');

        if ($acao === 'aprovar' && $orcamento['status'] === 'rascunho') {
            $cadastro->aprovarOrcamento($id, $usuario['id']);
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'success', 'msg' => 'Orçamento aprovado.'];

        } elseif ($acao === 'cancelar' && in_array($orcamento['status'], ['rascunho', 'aprovado'], true)) {
            $cadastro->cancelarOrcamento($id);
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'warning', 'msg' => 'Orçamento cancelado.'];

        } elseif ($acao === 'emitir' && $orcamento['status'] === 'aprovado') {
            try {
                $cfg     = new Config(dirname(__DIR__, 3));
                $factory = new XmlFactory($cfg);
                $client  = new NfseClient($cfg, $factory);
                $storage = new Storage($cfg->path('DB_PATH', 'storage/nfse.sqlite'));

                $serie      = $cfg->get('SERIE_RPS', '1');
                $numeroRps  = $storage->proximoRps($serie);

                $nota = [
                    'competencia' => $orcamento['competencia'],
                    'servico'     => [
                        'valor_servicos'              => $orcamento['valor_servicos'],
                        'iss_retido'                  => $orcamento['iss_retido'],
                        'item_lista_servico'          => $orcamento['item_lista_servico'],
                        'codigo_cnae'                 => $orcamento['codigo_cnae'] ?? '',
                        'codigo_tributacao_municipio' => $orcamento['codigo_tributacao_municipio'] ?? '',
                        'discriminacao'               => $orcamento['discriminacao'],
                        'exigibilidade_iss'           => $orcamento['exigibilidade_iss'],
                        'aliquota'                    => $orcamento['aliquota'] ?? '',
                        'valor_deducoes'              => $orcamento['valor_deducoes'] ?? '',
                        'valor_pis'                   => $orcamento['valor_pis'] ?? '',
                        'valor_cofins'                => $orcamento['valor_cofins'] ?? '',
                        'valor_inss'                  => $orcamento['valor_inss'] ?? '',
                        'valor_ir'                    => $orcamento['valor_ir'] ?? '',
                        'valor_csll'                  => $orcamento['valor_csll'] ?? '',
                        'desconto_incondicionado'     => $orcamento['desconto_incondicionado'] ?? '',
                        'desconto_condicionado'       => $orcamento['desconto_condicionado'] ?? '',
                    ],
                    'tomador' => [
                        'cpf_cnpj'     => $orcamento['cpf_cnpj'],
                        'razao_social' => $orcamento['razao_social'],
                        'email'        => $orcamento['cliente_email'] ?? '',
                        'telefone'     => $orcamento['telefone'] ?? '',
                        'endereco'     => [
                            'logradouro'       => $orcamento['logradouro'] ?? '',
                            'numero'           => $orcamento['numero'] ?? '',
                            'complemento'      => $orcamento['complemento'] ?? '',
                            'bairro'           => $orcamento['bairro'] ?? '',
                            'codigo_municipio' => $orcamento['codigo_municipio'] ?? '',
                            'uf'               => $orcamento['uf'] ?? '',
                            'cep'              => $orcamento['cep'] ?? '',
                        ],
                    ],
                ];

                $registroId = $storage->registrarEnvio(
                    $numeroRps,
                    $serie,
                    $orcamento['valor_servicos'],
                    $orcamento['cpf_cnpj'],
                    $orcamento['razao_social'],
                    ''
                );

                try {
                    $retorno = $client->gerarNfse($numeroRps, $nota);
                } catch (\Throwable $e) {
                    $storage->registrarErro($registroId, $e->getMessage());
                    throw $e;
                }

                $res = ResponseParser::parseGerarNfse($retorno);
                if ($res['sucesso']) {
                    $storage->registrarSucesso(
                        $registroId,
                        (string) $res['nfse_numero'],
                        (string) $res['codigo_verificacao'],
                        $retorno
                    );
                    $cadastro->emitirOrcamento($id, $registroId, (string) $res['nfse_numero']);
                    $orcamento = $cadastro->buscarOrcamento($id);
                    $flash     = ['tipo' => 'success', 'msg' => 'NFS-e emitida! Número: ' . $res['nfse_numero']];
                } else {
                    $erroMsg = ResponseParser::formatarErros($res['erros']);
                    $storage->registrarErro($registroId, $erroMsg, $retorno);
                    $flash = ['tipo' => 'danger', 'msg' => 'Erro na emissão: ' . $erroMsg];
                }
            } catch (\Throwable $e) {
                $flash = ['tipo' => 'danger', 'msg' => 'Erro: ' . $e->getMessage()];
            }
        }
    }
}

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
$status    = $orcamento['status'];
$pageTitle = 'Orçamento #' . $id;
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        Orçamento #<?= $id ?>
        <span class="badge bg-<?= $statusCores[$status] ?? 'secondary' ?> fs-6">
            <?= h($status) ?>
        </span>
    </h2>
    <a href="?p=orcamentos" class="btn btn-outline-secondary">← Voltar</a>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= h($flash['tipo']) ?> alert-dismissible fade show">
    <?= h($flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Cliente</strong></div>
            <div class="card-body">
                <p class="mb-1"><strong><?= h($orcamento['razao_social']) ?></strong></p>
                <p class="mb-1 text-muted"><?= h($orcamento['cpf_cnpj']) ?></p>
                <?php if (!empty($orcamento['cliente_email'])): ?>
                <p class="mb-1"><?= h($orcamento['cliente_email']) ?></p>
                <?php endif; ?>
                <?php if (!empty($orcamento['logradouro'])): ?>
                <p class="mb-0 small text-muted">
                    <?= h($orcamento['logradouro']) ?>, <?= h($orcamento['numero'] ?? '') ?>
                    <?= !empty($orcamento['bairro']) ? ' — ' . h($orcamento['bairro']) : '' ?>
                    / <?= h($orcamento['uf'] ?? '') ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><strong>Serviço</strong></div>
            <div class="card-body">
                <p class="mb-1">
                    <strong>Competência:</strong> <?= h($orcamento['competencia']) ?>
                </p>
                <p class="mb-1">
                    <strong>Valor:</strong>
                    R$ <?= h(number_format((float) $orcamento['valor_servicos'], 2, ',', '.')) ?>
                </p>
                <p class="mb-1">
                    <strong>Item Lista:</strong> <?= h($orcamento['item_lista_servico']) ?>
                    <?php if (!empty($orcamento['codigo_cnae'])): ?>
                    | <strong>CNAE:</strong> <?= h($orcamento['codigo_cnae']) ?>
                    <?php endif; ?>
                </p>
                <p class="mb-1">
                    <strong>ISS Retido:</strong> <?= (int) $orcamento['iss_retido'] === 1 ? 'Sim' : 'Não' ?>
                    <?php if (!empty($orcamento['aliquota'])): ?>
                    | <strong>Alíquota:</strong> <?= h($orcamento['aliquota']) ?>%
                    <?php endif; ?>
                </p>
                <p class="mb-0 small text-muted"><?= nl2br(h($orcamento['discriminacao'])) ?></p>
            </div>
        </div>
    </div>
</div>

<?php if ($orcamento['status'] === 'emitido' && !empty($orcamento['nfse_numero'])): ?>
<div class="alert alert-success mt-4">
    <strong>NFS-e emitida:</strong> <?= h($orcamento['nfse_numero']) ?>
    <a href="?p=orcamentos/ver&id=<?= $id ?>&acao=danfse"
       class="btn btn-sm btn-success ms-3" target="_blank">
        📄 DANFS-e Oficial
    </a>
</div>
<?php endif; ?>

<div class="mt-4 d-flex gap-2 flex-wrap">
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

        <?php if ($status === 'rascunho'): ?>
        <a href="?p=orcamentos/form&id=<?= $id ?>" class="btn btn-outline-primary">Editar</a>
        <button type="submit" name="acao" value="aprovar" class="btn btn-warning">Aprovar</button>
        <button type="submit" name="acao" value="cancelar" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>

        <?php elseif ($status === 'aprovado'): ?>
        <button type="submit" name="acao" value="emitir" class="btn btn-success"
                onclick="return confirm('Emitir NFS-e agora? Esta ação não pode ser desfeita.')">
            ⚡ Emitir NFS-e
        </button>
        <button type="submit" name="acao" value="cancelar" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>
        <?php endif; ?>
    </form>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 5: Testar o fluxo completo**

```bash
php -S 127.0.0.1:8080 public/web.php
```

Teste o fluxo completo:
1. Criar cliente em `?p=clientes/form`
2. Criar serviço em `?p=servicos/form`
3. Criar orçamento em `?p=orcamentos/form` (carregar template via "Carregar")
4. Acessar `?p=orcamentos/ver&id=1` — verificar botão "Aprovar"
5. Aprovar — verificar status muda para "aprovado"
6. Verificar botão "Emitir NFS-e" (não clicar em produção sem `.env` real)
7. Verificar botão "Cancelar" funciona

- [ ] **Step 6: Commit**

```bash
git add public/pages/orcamentos/
git commit -m "feat: add budget pages with rascunho→aprovado→emitido workflow and NFS-e emission"
```

---

### Task 10: Nginx config + CI final + push

**Files:**
- Create: `nginx/lumina-nfse.conf`
- Modify: `.github/workflows/ci.yml`
- Modify: `.gitignore`

- [ ] **Step 1: Criar diretório e `nginx/lumina-nfse.conf`**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/nginx
```

```nginx
# nginx/lumina-nfse.conf
# Copiar para /etc/nginx/sites-available/lumina-nfse e habilitar:
#   sudo ln -s /etc/nginx/sites-available/lumina-nfse /etc/nginx/sites-enabled/
#   sudo nginx -t && sudo systemctl reload nginx

server {
    listen 443 ssl http2;
    server_name seu-dominio.com.br;

    ssl_certificate     /etc/letsencrypt/live/seu-dominio.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seu-dominio.com.br/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    root  /opt/Lumina/emissor-nfse-goiania/public;
    index web.php;

    location / {
        try_files $uri /web.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    # Bloqueia arquivos sensíveis fora de public/
    location ~* \.(env|sqlite|pfx|p12|pem|key)$ {
        deny all;
    }

    # Bloqueia acesso direto a pages/ (só via router)
    location ^~ /pages/ {
        deny all;
    }
}

server {
    listen      80;
    server_name seu-dominio.com.br;
    return      301 https://$host$request_uri;
}
```

- [ ] **Step 2: Verificar lint nos novos arquivos PHP**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/ public/
```

Corrigir qualquer erro antes de continuar. Warnings são ignorados pelo `-n`.

- [ ] **Step 3: Rodar todos os testes**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos os testes passando.

- [ ] **Step 4: Commit final e push**

```bash
git add nginx/ public/ src/ tests/ phpunit.xml composer.json composer.lock .github/workflows/ci.yml
git commit -m "feat: complete client/budget web module with Nginx config"
git push origin master
```

- [ ] **Step 5: Verificar CI no GitHub**

```bash
gh run list --repo moisesagssilva/emissor-nfse-goiania --limit 3
```

Aguardar status `success`.
