# NF-e de Produto — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar módulo de emissão de NF-e modelo 55 (produto) ao sistema Lumina, com persistência SQLite, comunicação SEFAZ-GO via sped-nfe, DANFE on-demand e interface web Bootstrap 5.

**Architecture:** Três novas classes PHP (`NfeStorage`, `NfeXmlFactory`, `NfeClient`) mais três páginas web (`pedidos/index`, `form`, `ver`). NfeStorage cria suas próprias tabelas no mesmo SQLite. NfeXmlFactory constrói o XML usando `NFePHP\NFe\Make`. NfeClient assina e transmite via `NFePHP\NFe\Tools`. O fluxo de status é: rascunho → aprovado → emitido (ou cancelado em qualquer estado pré-emissão).

**Tech Stack:** PHP 8.2, SQLite (PDO), `nfephp-org/sped-nfe ^5.2`, `nfephp-org/sped-da ^1.2`, Bootstrap 5.3.3 CDN, PHPUnit 11.

## Global Constraints

- PHP 8.2, PSR-12, `declare(strict_types=1)` em todos os arquivos PHP.
- Namespace `EmissorGyn\` (src/), `EmissorGynTest\` (tests/).
- `h(string $v): string` = `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` para todo output HTML.
- CSRF token em todos os POSTs via `$auth->csrfToken()` / `$auth->validarCsrf()`.
- `$auth->guard()` no início de toda página protegida.
- Nenhum arquivo PHP em `public/pages/` deve ser acessível diretamente (nginx bloqueia).
- SQLite WAL + foreign_keys ON em todas as conexões PDO.
- Nenhum PDF gerado em disco; DANFE sempre on-demand via `NFePHP\DA\NFe\Danfe`.
- Nunca incluir `ext-soap` em `require` (já está em `require-dev` implícito via CI).

---

### Task 1: Dependências, CI e .env

**Files:**
- Modify: `composer.json`
- Modify: `.github/workflows/ci.yml`
- Modify: `.env.example`
- Modify: `.env` (local — não commitado)

**Interfaces:**
- Produces: pacotes `nfephp-org/sped-nfe`, `nfephp-org/sped-da` disponíveis via autoload; chaves `NFE_AMBIENTE`, `NFE_SERIE`, `PRESTADOR_RAZAO_SOCIAL`, `PRESTADOR_IE`, `PRESTADOR_LOGRADOURO`, `PRESTADOR_NUMERO`, `PRESTADOR_BAIRRO`, `PRESTADOR_CODIGO_MUNICIPIO`, `PRESTADOR_MUNICIPIO`, `PRESTADOR_UF`, `PRESTADOR_CEP` no `.env`.

- [ ] **Step 1: Adicionar dependências ao composer.json**

Adicionar à seção `require` (antes do fechamento `}`):

```json
        "ext-soap": "*",
        "nfephp-org/sped-da": "^1.2",
        "nfephp-org/sped-nfe": "^5.2"
```

O arquivo final de `require` deve ficar:
```json
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
        "ext-soap": "*",
        "nfephp-org/sped-common": "^5.1",
        "nfephp-org/sped-da": "^1.2",
        "nfephp-org/sped-nfe": "^5.2"
    },
```

- [ ] **Step 2: Instalar dependências**

```bash
composer install --ignore-platform-reqs --prefer-dist --no-progress
```

Esperado: `nfephp-org/sped-nfe` e `nfephp-org/sped-da` aparecem em `vendor/`.

- [ ] **Step 3: Adicionar soap ao CI**

Em `.github/workflows/ci.yml`, alterar a linha `extensions:`:

```yaml
          extensions: curl, dom, json, libxml, openssl, pdo, pdo_sqlite, simplexml, soap
```

- [ ] **Step 4: Atualizar .env.example com chaves NF-e**

Adicionar ao final do arquivo `.env.example`:

```dotenv

# NF-e de produto
NFE_AMBIENTE=2
NFE_SERIE=1

# Dados do emitente para NF-e (além de PRESTADOR_CNPJ já existente)
PRESTADOR_RAZAO_SOCIAL=Lumina Energia Solar LTDA
PRESTADOR_IE=1234567890123
PRESTADOR_LOGRADOURO=Rua das Palmeiras
PRESTADOR_NUMERO=100
PRESTADOR_BAIRRO=Setor Bueno
PRESTADOR_CODIGO_MUNICIPIO=5208707
PRESTADOR_MUNICIPIO=Goiânia
PRESTADOR_UF=GO
PRESTADOR_CEP=74210015
```

- [ ] **Step 5: Atualizar .env local com os mesmos campos**

Abrir `.env` (não commitado) e adicionar as mesmas chaves com valores reais ou de teste.

- [ ] **Step 6: Verificar syntax**

```bash
find src/ public/ -name "*.php" | xargs php -l
```

Esperado: `No syntax errors detected` em todos os arquivos.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock .github/workflows/ci.yml .env.example
git commit -m "feat(nfe): add sped-nfe/sped-da deps, soap to CI, env keys"
```

---

### Task 2: NfeStorage

**Files:**
- Create: `src/NfeStorage.php`
- Modify: `tests/CadastroTest.php` (adicionar testes NfeStorage ao final)

**Interfaces:**
- Consumes: nothing — apenas PDO com o mesmo `storage/nfse.sqlite`.
- Produces:
  - `new NfeStorage(string $dbPath): NfeStorage`
  - `proximoNfe(string $serie): int`
  - `definirUltimoNfe(string $serie, int $ultimo): void`
  - `listarPedidos(string $status = '', string $busca = ''): array`
  - `buscarPedido(int $id): ?array` — join com clientes
  - `inserirPedido(array $dados): int`
  - `atualizarPedido(int $id, array $dados): void`
  - `aprovarPedido(int $id, int $usuarioId): void`
  - `emitirPedido(int $id, string $chave, int $numero, string $serie, string $protocolo, string $xmlAutorizado): void`
  - `cancelarPedido(int $id): void`
  - `listarItens(int $pedidoId): array`
  - `substituirItens(int $pedidoId, array $itens): void`
  - `registrarEvento(int $pedidoId, string $tipo, string $protocolo, string $descricao, string $xmlEvento, string $xmlRetorno): void`
  - `listarEventos(int $pedidoId): array`
  - `estatisticas(): array`

- [ ] **Step 1: Escrever testes (vão falhar antes da implementação)**

Adicionar ao final de `tests/CadastroTest.php` (antes do `}` final da classe):

```php
    // ─── NfeStorage ──────────────────────────────────────────────────────────

    public function testNfeStorageMigrateIdempotente(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $storage2 = new \EmissorGyn\NfeStorage(':memory:');
        $this->assertInstanceOf(\EmissorGyn\NfeStorage::class, $storage2);
    }

    public function testProximoNfeIncrementa(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $this->assertSame(1, $storage->proximoNfe('1'));
        $this->assertSame(2, $storage->proximoNfe('1'));
        $this->assertSame(1, $storage->proximoNfe('2'));
    }

    public function testDefinirUltimoNfeFazRollback(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $n = $storage->proximoNfe('1');
        $this->assertSame(1, $n);
        $storage->definirUltimoNfe('1', $n - 1);
        $this->assertSame(1, $storage->proximoNfe('1'));
    }

    public function testCrudPedidos(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');

        // Precisa de um cliente na tabela clientes do mesmo SQLite — NfeStorage
        // cria as tabelas de pedidos, mas clientes já existe em Cadastro.
        // Para isolar o teste, criamos o cliente direto via PDO refletido.
        // NfeStorage expõe o método de acesso ao pdo para fins de teste.
        $pdo = $storage->getPdoForTest();
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS clientes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                razao_social TEXT NOT NULL,
                cpf_cnpj TEXT NOT NULL,
                uf TEXT,
                cep TEXT,
                logradouro TEXT,
                numero TEXT,
                bairro TEXT,
                codigo_municipio TEXT,
                municipio TEXT,
                ativo INTEGER NOT NULL DEFAULT 1
            )
        SQL);
        $pdo->exec(
            "INSERT INTO clientes (razao_social, cpf_cnpj, uf) VALUES ('Teste LTDA', '11222333000181', 'GO')"
        );
        $clienteId = (int) $pdo->lastInsertId();

        $dados = [
            'cliente_id'           => $clienteId,
            'natureza_operacao'    => 'Venda de mercadoria',
            'consumidor_final'     => 0,
            'presenca'             => 1,
            'informacoes_adicionais' => '',
            'criado_por'           => 1,
        ];
        $id = $storage->inserirPedido($dados);
        $this->assertGreaterThan(0, $id);

        $pedido = $storage->buscarPedido($id);
        $this->assertNotNull($pedido);
        $this->assertSame('rascunho', $pedido['status']);
        $this->assertSame('Teste LTDA', $pedido['razao_social']);

        $storage->aprovarPedido($id, 1);
        $pedido = $storage->buscarPedido($id);
        $this->assertSame('aprovado', $pedido['status']);

        $storage->emitirPedido($id, 'CHAVE123', 1, '1', 'PROT123', '<xml/>');
        $pedido = $storage->buscarPedido($id);
        $this->assertSame('emitido', $pedido['status']);
        $this->assertSame('CHAVE123', $pedido['nfe_chave']);
        $this->assertSame('PROT123', $pedido['nfe_protocolo']);
    }

    public function testSubstituirItens(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $pdo = $storage->getPdoForTest();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, razao_social TEXT NOT NULL, cpf_cnpj TEXT NOT NULL)"
        );
        $pdo->exec("INSERT INTO clientes (razao_social, cpf_cnpj) VALUES ('C','11222333000181')");
        $clienteId = (int) $pdo->lastInsertId();

        $pedidoId = $storage->inserirPedido([
            'cliente_id' => $clienteId, 'natureza_operacao' => 'Venda',
            'consumidor_final' => 0, 'presenca' => 1,
            'informacoes_adicionais' => '', 'criado_por' => 1,
        ]);

        $itens = [
            [
                'numero_item' => 1,
                'codigo_produto' => 'P001',
                'descricao' => 'Produto A',
                'ncm' => '84713012',
                'cfop' => '5102',
                'unidade' => 'UN',
                'quantidade' => '2.0000',
                'valor_unitario' => '50.00',
                'valor_desconto' => null,
                'csosn' => '400',
                'pis_cst' => '07',
                'cofins_cst' => '07',
                'informacoes_adicionais_item' => null,
            ],
        ];
        $storage->substituirItens($pedidoId, $itens);

        $lista = $storage->listarItens($pedidoId);
        $this->assertCount(1, $lista);
        $this->assertSame('Produto A', $lista[0]['descricao']);

        // Substituir com 2 itens
        $itens[] = array_merge($itens[0], ['numero_item' => 2, 'descricao' => 'Produto B']);
        $storage->substituirItens($pedidoId, $itens);
        $this->assertCount(2, $storage->listarItens($pedidoId));
    }

    public function testCancelarPedidoEmitidoRegistraEvento(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $pdo = $storage->getPdoForTest();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, razao_social TEXT NOT NULL, cpf_cnpj TEXT NOT NULL)"
        );
        $pdo->exec("INSERT INTO clientes (razao_social, cpf_cnpj) VALUES ('E','11222333000181')");
        $clienteId = (int) $pdo->lastInsertId();
        $pedidoId = $storage->inserirPedido([
            'cliente_id' => $clienteId, 'natureza_operacao' => 'Venda',
            'consumidor_final' => 0, 'presenca' => 1,
            'informacoes_adicionais' => '', 'criado_por' => 1,
        ]);
        $storage->aprovarPedido($pedidoId, 1);
        $storage->emitirPedido($pedidoId, 'CH', 1, '1', 'PR', '<x/>');
        $storage->registrarEvento($pedidoId, 'cancelamento', 'PROT-CANC', 'Cancelado', '<ev/>', '<ret/>');
        $storage->cancelarPedido($pedidoId);

        $pedido = $storage->buscarPedido($pedidoId);
        $this->assertSame('cancelado', $pedido['status']);

        $eventos = $storage->listarEventos($pedidoId);
        $this->assertCount(1, $eventos);
        $this->assertSame('cancelamento', $eventos[0]['tipo']);
    }

    public function testNfeEstatisticas(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $stats = $storage->estatisticas();
        $this->assertArrayHasKey('rascunho', $stats);
        $this->assertArrayHasKey('emitido', $stats);
    }
```

- [ ] **Step 2: Rodar testes — confirmar falha**

```bash
vendor/bin/phpunit --colors=always --filter NfeStorage 2>&1 | head -30
```

Esperado: `Error: Class "EmissorGyn\NfeStorage" not found`.

- [ ] **Step 3: Criar src/NfeStorage.php**

```php
<?php

declare(strict_types=1);

namespace EmissorGyn;

final class NfeStorage
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

    /** Somente para testes — permite criar tabelas de dependências in-memory. */
    public function getPdoForTest(): \PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rps_sequencia (
                serie         TEXT PRIMARY KEY,
                ultimo_numero INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS pedidos (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                cliente_id             INTEGER NOT NULL,
                status                 TEXT    NOT NULL DEFAULT 'rascunho',
                natureza_operacao      TEXT    NOT NULL DEFAULT 'Venda de mercadoria',
                consumidor_final       INTEGER NOT NULL DEFAULT 0,
                presenca               INTEGER NOT NULL DEFAULT 1,
                informacoes_adicionais TEXT,
                nfe_chave              TEXT,
                nfe_numero             INTEGER,
                nfe_serie              TEXT,
                nfe_protocolo          TEXT,
                nfe_xml_autorizado     TEXT,
                criado_por             INTEGER,
                aprovado_por           INTEGER,
                criado_em              TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                aprovado_em            TEXT,
                emitido_em             TEXT,
                cancelado_em           TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_pedidos_cliente ON pedidos (cliente_id);
            CREATE INDEX IF NOT EXISTS idx_pedidos_status  ON pedidos (status);
            CREATE INDEX IF NOT EXISTS idx_pedidos_chave   ON pedidos (nfe_chave);
            CREATE TABLE IF NOT EXISTS pedido_itens (
                id                          INTEGER PRIMARY KEY AUTOINCREMENT,
                pedido_id                   INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
                numero_item                 INTEGER NOT NULL,
                codigo_produto              TEXT,
                descricao                   TEXT    NOT NULL,
                ncm                         TEXT    NOT NULL,
                cfop                        TEXT    NOT NULL,
                unidade                     TEXT    NOT NULL DEFAULT 'UN',
                quantidade                  TEXT    NOT NULL,
                valor_unitario              TEXT    NOT NULL,
                valor_desconto              TEXT,
                csosn                       TEXT    NOT NULL DEFAULT '400',
                pis_cst                     TEXT    NOT NULL DEFAULT '07',
                cofins_cst                  TEXT    NOT NULL DEFAULT '07',
                informacoes_adicionais_item TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_pedido_itens_pedido ON pedido_itens (pedido_id);
            CREATE TABLE IF NOT EXISTS pedido_eventos (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                pedido_id   INTEGER NOT NULL REFERENCES pedidos(id),
                tipo        TEXT    NOT NULL,
                protocolo   TEXT,
                descricao   TEXT,
                xml_evento  TEXT,
                xml_retorno TEXT,
                criado_em   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
        SQL);
    }

    // ─── Numeração ───────────────────────────────────────────────────────────

    public function proximoNfe(string $serie): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO rps_sequencia (serie, ultimo_numero) VALUES (?, 1)
                 ON CONFLICT(serie) DO UPDATE SET ultimo_numero = ultimo_numero + 1'
            )->execute(["nfe:{$serie}"]);
            $n = (int) $this->pdo->prepare(
                'SELECT ultimo_numero FROM rps_sequencia WHERE serie = ?'
            )->execute(["nfe:{$serie}"]) ? $this->pdo->query(
                "SELECT ultimo_numero FROM rps_sequencia WHERE serie = 'nfe:{$serie}'"
            )->fetchColumn() : 0;
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function definirUltimoNfe(string $serie, int $ultimo): void
    {
        $this->pdo->prepare(
            'UPDATE rps_sequencia SET ultimo_numero = ? WHERE serie = ?'
        )->execute([$ultimo, "nfe:{$serie}"]);
    }

    // ─── Pedidos ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarPedidos(string $status = '', string $busca = ''): array
    {
        $where  = [];
        $params = [];
        if ($status !== '') {
            $where[]  = 'p.status = ?';
            $params[] = $status;
        }
        if ($busca !== '') {
            $where[]  = 'c.razao_social LIKE ?';
            $params[] = "%{$busca}%";
        }
        $sql = <<<'SQL'
            SELECT p.*,
                   c.razao_social, c.cpf_cnpj,
                   ROUND(COALESCE((
                       SELECT SUM(CAST(pi.quantidade AS REAL) * CAST(pi.valor_unitario AS REAL)
                                  - COALESCE(CAST(pi.valor_desconto AS REAL), 0))
                       FROM pedido_itens pi WHERE pi.pedido_id = p.id
                   ), 0), 2) AS valor_total,
                   (SELECT COUNT(*) FROM pedido_itens pi WHERE pi.pedido_id = p.id) AS total_itens
              FROM pedidos p
              JOIN clientes c ON c.id = p.cliente_id
        SQL;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarPedido(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.razao_social, c.cpf_cnpj,
                    c.logradouro, c.numero AS cliente_numero, c.complemento,
                    c.bairro, c.codigo_municipio, c.uf, c.cep,
                    c.email AS cliente_email, c.telefone
               FROM pedidos p
               JOIN clientes c ON c.id = p.cliente_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirPedido(array $dados): int
    {
        $this->pdo->prepare(
            'INSERT INTO pedidos
                (cliente_id, natureza_operacao, consumidor_final, presenca,
                 informacoes_adicionais, criado_por)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $dados['cliente_id'],
            $dados['natureza_operacao'] ?? 'Venda de mercadoria',
            (int) ($dados['consumidor_final'] ?? 0),
            (int) ($dados['presenca'] ?? 1),
            $dados['informacoes_adicionais'] ?? null,
            (int) $dados['criado_por'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarPedido(int $id, array $dados): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos SET
                cliente_id = ?, natureza_operacao = ?, consumidor_final = ?,
                presenca = ?, informacoes_adicionais = ?
             WHERE id = ? AND status = 'rascunho'"
        )->execute([
            (int) $dados['cliente_id'],
            $dados['natureza_operacao'] ?? 'Venda de mercadoria',
            (int) ($dados['consumidor_final'] ?? 0),
            (int) ($dados['presenca'] ?? 1),
            $dados['informacoes_adicionais'] ?? null,
            $id,
        ]);
    }

    public function aprovarPedido(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status = 'aprovado',
                    aprovado_por = ?,
                    aprovado_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'rascunho'"
        )->execute([$usuarioId, $id]);
    }

    public function emitirPedido(
        int $id,
        string $chave,
        int $numero,
        string $serie,
        string $protocolo,
        string $xmlAutorizado
    ): void {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status             = 'emitido',
                    nfe_chave          = ?,
                    nfe_numero         = ?,
                    nfe_serie          = ?,
                    nfe_protocolo      = ?,
                    nfe_xml_autorizado = ?,
                    emitido_em         = datetime('now','localtime')
              WHERE id = ? AND status = 'aprovado'"
        )->execute([$chave, $numero, $serie, $protocolo, $xmlAutorizado, $id]);
    }

    public function cancelarPedido(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status = 'cancelado',
                    cancelado_em = datetime('now','localtime')
              WHERE id = ?"
        )->execute([$id]);
    }

    // ─── Itens ───────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarItens(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pedido_itens WHERE pedido_id = ? ORDER BY numero_item'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    /** @param array<int,array<string,mixed>> $itens */
    public function substituirItens(int $pedidoId, array $itens): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM pedido_itens WHERE pedido_id = ?'
            )->execute([$pedidoId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO pedido_itens
                    (pedido_id, numero_item, codigo_produto, descricao, ncm, cfop,
                     unidade, quantidade, valor_unitario, valor_desconto,
                     csosn, pis_cst, cofins_cst, informacoes_adicionais_item)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($itens as $item) {
                $stmt->execute([
                    $pedidoId,
                    (int) $item['numero_item'],
                    $item['codigo_produto'] ?? null,
                    $item['descricao'],
                    $item['ncm'],
                    $item['cfop'],
                    $item['unidade'] ?? 'UN',
                    $item['quantidade'],
                    $item['valor_unitario'],
                    $item['valor_desconto'] ?? null,
                    $item['csosn'] ?? '400',
                    $item['pis_cst'] ?? '07',
                    $item['cofins_cst'] ?? '07',
                    $item['informacoes_adicionais_item'] ?? null,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ─── Eventos ─────────────────────────────────────────────────────────────

    public function registrarEvento(
        int $pedidoId,
        string $tipo,
        string $protocolo,
        string $descricao,
        string $xmlEvento,
        string $xmlRetorno
    ): void {
        $this->pdo->prepare(
            'INSERT INTO pedido_eventos
                (pedido_id, tipo, protocolo, descricao, xml_evento, xml_retorno)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pedidoId, $tipo, $protocolo, $descricao, $xmlEvento, $xmlRetorno]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listarEventos(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pedido_eventos WHERE pedido_id = ? ORDER BY id'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    // ─── Estatísticas ────────────────────────────────────────────────────────

    /** @return array<string,int> */
    public function estatisticas(): array
    {
        $map = ['rascunho' => 0, 'aprovado' => 0, 'emitido' => 0, 'cancelado' => 0];
        try {
            $rows = $this->pdo->query(
                'SELECT status, COUNT(*) AS total FROM pedidos GROUP BY status'
            )->fetchAll();
            foreach ($rows as $row) {
                $map[(string) $row['status']] = (int) $row['total'];
            }
        } catch (\PDOException) {
        }
        return $map;
    }
}
```

**Nota:** No código acima, substitua o método `proximoNfe` pela versão correta abaixo (a versão gerada acima tem bug de sintaxe no encadeamento de chamadas):

```php
    public function proximoNfe(string $serie): int
    {
        $chave = "nfe:{$serie}";
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO rps_sequencia (serie, ultimo_numero) VALUES (?, 1)
                 ON CONFLICT(serie) DO UPDATE SET ultimo_numero = ultimo_numero + 1'
            )->execute([$chave]);
            $stmt = $this->pdo->prepare(
                'SELECT ultimo_numero FROM rps_sequencia WHERE serie = ?'
            );
            $stmt->execute([$chave]);
            $n = (int) $stmt->fetchColumn();
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
```

- [ ] **Step 4: Rodar testes**

```bash
vendor/bin/phpunit --colors=always --filter NfeStorage
```

Esperado: todos os testes NfeStorage passam.

- [ ] **Step 5: PSR-12 lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/NfeStorage.php
```

Esperado: sem erros. Se houver, corrigir com `vendor/bin/phpcbf --standard=PSR12 -n src/NfeStorage.php`.

- [ ] **Step 6: Todos os testes passam**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos os testes passam (os 14 existentes + os novos NfeStorage).

- [ ] **Step 7: Commit**

```bash
git add src/NfeStorage.php tests/CadastroTest.php
git commit -m "feat(nfe): add NfeStorage with pedidos/itens/eventos/numeração"
```

---

### Task 3: NfeXmlFactory

**Files:**
- Create: `src/NfeXmlFactory.php`
- Create: `tests/NfeXmlFactoryTest.php`

**Interfaces:**
- Consumes: `Config::get()`, `NFePHP\NFe\Make`
- Produces:
  - `new NfeXmlFactory(Config $config): NfeXmlFactory`
  - `build(array $pedido, array $itens, array $cliente, int $nNF, string $serie, string $cNF): string`

- [ ] **Step 1: Escrever teste**

Criar `tests/NfeXmlFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Config;
use EmissorGyn\NfeXmlFactory;
use PHPUnit\Framework\TestCase;

final class NfeXmlFactoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lumina_nfe_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/.env', implode("\n", [
            'PRESTADOR_CNPJ=11222333000181',
            'PRESTADOR_RAZAO_SOCIAL=Empresa Teste LTDA',
            'PRESTADOR_IE=1234567890123',
            'PRESTADOR_LOGRADOURO=Rua A',
            'PRESTADOR_NUMERO=100',
            'PRESTADOR_BAIRRO=Centro',
            'PRESTADOR_CODIGO_MUNICIPIO=5208707',
            'PRESTADOR_MUNICIPIO=Goiania',
            'PRESTADOR_UF=GO',
            'PRESTADOR_CEP=74000000',
            'NFE_AMBIENTE=2',
            'NFE_SERIE=1',
            'CERT_PATH=/dev/null',
            'CERT_PASS=senha',
            'DB_PATH=:memory:',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/.env');
        @rmdir($this->tmpDir);
    }

    public function testBuildRetornaXmlNfe(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = [
            'natureza_operacao'     => 'Venda de mercadoria',
            'consumidor_final'      => 0,
            'presenca'              => 1,
            'informacoes_adicionais' => 'Teste de emissao',
        ];
        $itens = [[
            'numero_item'                => 1,
            'codigo_produto'             => 'PROD001',
            'descricao'                  => 'Notebook Dell Inspiron',
            'ncm'                        => '84713012',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '1.0000',
            'valor_unitario'             => '3500.00',
            'valor_desconto'             => null,
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social'     => 'Cliente Pessoa Física',
            'cpf_cnpj'         => '12345678909',
            'logradouro'       => 'Av B',
            'cliente_numero'   => '200',
            'bairro'           => 'Setor Sul',
            'codigo_municipio' => '5208707',
            'uf'               => 'GO',
            'cep'              => '74000001',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 1, '1', '12345678');

        $this->assertIsString($xml);
        $this->assertStringContainsString('<NFe', $xml);
        $this->assertStringContainsString('Notebook Dell Inspiron', $xml);
        $this->assertStringContainsString('11222333000181', $xml);
        $this->assertStringContainsString('84713012', $xml);
    }

    public function testBuildComDescontoCalculaTotalCorreto(): void
    {
        $config  = new Config($this->tmpDir);
        $factory = new NfeXmlFactory($config);

        $pedido = ['natureza_operacao' => 'Venda', 'consumidor_final' => 0, 'presenca' => 1, 'informacoes_adicionais' => ''];
        $itens  = [[
            'numero_item'                => 1,
            'codigo_produto'             => 'P2',
            'descricao'                  => 'Painel Solar 450W',
            'ncm'                        => '85414090',
            'cfop'                       => '5102',
            'unidade'                    => 'UN',
            'quantidade'                 => '4.0000',
            'valor_unitario'             => '1000.00',
            'valor_desconto'             => '200.00',
            'csosn'                      => '400',
            'pis_cst'                    => '07',
            'cofins_cst'                 => '07',
            'informacoes_adicionais_item' => null,
        ]];
        $cliente = [
            'razao_social' => 'Empresa GO', 'cpf_cnpj' => '11222333000181',
            'logradouro' => 'Rua X', 'cliente_numero' => '1',
            'bairro' => 'B', 'codigo_municipio' => '5208707',
            'uf' => 'GO', 'cep' => '74000002',
        ];

        $xml = $factory->build($pedido, $itens, $cliente, 2, '1', '87654321');
        $this->assertStringContainsString('3800', $xml); // 4*1000 - 200
    }
}
```

- [ ] **Step 2: Rodar teste — confirmar falha**

```bash
vendor/bin/phpunit --colors=always tests/NfeXmlFactoryTest.php 2>&1 | head -20
```

Esperado: `Error: Class "EmissorGyn\NfeXmlFactory" not found`.

- [ ] **Step 3: Criar src/NfeXmlFactory.php**

```php
<?php

declare(strict_types=1);

namespace EmissorGyn;

use NFePHP\NFe\Make;

final class NfeXmlFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function build(
        array $pedido,
        array $itens,
        array $cliente,
        int $nNF,
        string $serie,
        string $cNF
    ): string {
        $make = new Make();

        $cnpjEmit = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CNPJ'));
        $tpAmb    = $this->config->getInt('NFE_AMBIENTE', 2);
        $uf       = strtoupper(trim($cliente['uf'] ?? 'GO'));
        $idDest   = $uf === 'GO' ? 1 : 2;

        // IDE
        $std              = new \stdClass();
        $std->cUF         = 52;
        $std->cNF         = str_pad($cNF, 8, '0', STR_PAD_LEFT);
        $std->natOp       = $pedido['natureza_operacao'] ?? 'Venda de mercadoria';
        $std->mod         = 55;
        $std->serie       = (int) $serie;
        $std->nNF         = $nNF;
        $std->dhEmi       = date('Y-m-d\TH:i:sP');
        $std->dhSaiEnt    = date('Y-m-d\TH:i:sP');
        $std->tpNF        = 1;
        $std->idDest      = $idDest;
        $std->cMunFG      = (int) $this->config->get('PRESTADOR_CODIGO_MUNICIPIO');
        $std->tpImp       = 1;
        $std->tpEmis      = 1;
        $std->tpAmb       = $tpAmb;
        $std->finNFe      = 1;
        $std->indFinal    = (int) ($pedido['consumidor_final'] ?? 0);
        $std->indPres     = (int) ($pedido['presenca'] ?? 1);
        $std->procEmi     = 0;
        $std->verProc     = '1.0';
        $make->tagide($std);

        // EMIT
        $std        = new \stdClass();
        $std->CNPJ  = $cnpjEmit;
        $std->xNome = $this->config->get('PRESTADOR_RAZAO_SOCIAL');
        $std->IE    = preg_replace('/\D/', '', $this->config->get('PRESTADOR_IE', ''));
        $std->CRT   = 1;
        $make->tagemit($std);

        // ENDER EMIT
        $std         = new \stdClass();
        $std->xLgr   = $this->config->get('PRESTADOR_LOGRADOURO');
        $std->nro    = $this->config->get('PRESTADOR_NUMERO');
        $std->xBairro = $this->config->get('PRESTADOR_BAIRRO');
        $std->cMun   = (int) $this->config->get('PRESTADOR_CODIGO_MUNICIPIO');
        $std->xMun   = $this->config->get('PRESTADOR_MUNICIPIO');
        $std->UF     = $this->config->get('PRESTADOR_UF');
        $std->CEP    = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CEP'));
        $std->cPais  = 1058;
        $std->xPais  = 'Brasil';
        $make->tagenderEmit($std);

        // DEST
        $cpfCnpj = preg_replace('/\D/', '', $cliente['cpf_cnpj'] ?? '');
        $std     = new \stdClass();
        if (strlen($cpfCnpj) === 14) {
            $std->CNPJ = $cpfCnpj;
        } else {
            $std->CPF = $cpfCnpj;
        }
        $std->xNome    = $cliente['razao_social'];
        $std->indIEDest = strlen($cpfCnpj) === 14 ? 1 : 9;
        $make->tagdest($std);

        // ENDER DEST
        $std          = new \stdClass();
        $std->xLgr    = $cliente['logradouro'] ?? '';
        $std->nro     = $cliente['cliente_numero'] ?? 'S/N';
        $std->xBairro = $cliente['bairro'] ?? '';
        $std->cMun    = (int) ($cliente['codigo_municipio'] ?? 9999999);
        $std->xMun    = $cliente['municipio'] ?? '';
        $std->UF      = $uf;
        $std->CEP     = preg_replace('/\D/', '', $cliente['cep'] ?? '');
        $std->cPais   = 1058;
        $std->xPais   = 'Brasil';
        $make->tagenderDest($std);

        // ITENS
        $totalProd = 0.0;
        $totalDesc = 0.0;

        foreach ($itens as $item) {
            $n    = (int) $item['numero_item'];
            $qtd  = (float) $item['quantidade'];
            $vUnit = (float) $item['valor_unitario'];
            $vDesc = (float) ($item['valor_desconto'] ?? 0);
            $vProd = round($qtd * $vUnit, 2);
            $vItem = round($vProd - $vDesc, 2);

            $totalProd += $vProd;
            $totalDesc += $vDesc;

            $std       = new \stdClass();
            $std->item = $n;
            $make->tagdet($std);

            $std              = new \stdClass();
            $std->item        = $n;
            $std->cProd       = $item['codigo_produto'] ?? str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $std->cEAN        = 'SEM GTIN';
            $std->xProd       = $item['descricao'];
            $std->NCM         = preg_replace('/\D/', '', $item['ncm']);
            $std->CFOP        = $item['cfop'];
            $std->uCom        = $item['unidade'] ?? 'UN';
            $std->qCom        = $qtd;
            $std->vUnCom      = $vUnit;
            $std->vProd       = $vProd;
            $std->cEANTrib    = 'SEM GTIN';
            $std->uTrib       = $item['unidade'] ?? 'UN';
            $std->qTrib       = $qtd;
            $std->vUnTrib     = $vUnit;
            $std->vDesc       = $vDesc > 0 ? $vDesc : null;
            $std->indTot      = 1;
            $make->tagprod($std);

            // ICMS CSOSN 400
            $std       = new \stdClass();
            $std->item = $n;
            $std->orig = 0;
            $std->CSOSN = 400;
            $make->tagICMSSN400($std);

            // PIS CST 07 (operação isenta)
            $std             = new \stdClass();
            $std->item       = $n;
            $std->CST        = '07';
            $std->qBCProd    = 0.0;
            $std->vAliqProd  = 0.0;
            $std->vPIS       = 0.0;
            $make->tagPISOutr($std);

            // COFINS CST 07
            $std             = new \stdClass();
            $std->item       = $n;
            $std->CST        = '07';
            $std->qBCProd    = 0.0;
            $std->vAliqProd  = 0.0;
            $std->vCOFINS    = 0.0;
            $make->tagCOFINSOutr($std);

            if (!empty($item['informacoes_adicionais_item'])) {
                $std           = new \stdClass();
                $std->item     = $n;
                $std->infAdProd = $item['informacoes_adicionais_item'];
                $make->tagobsItem($std);
            }
        }

        // TOTAL
        $totalNF  = round($totalProd - $totalDesc, 2);
        $std           = new \stdClass();
        $std->vBC      = 0.00;
        $std->vICMS    = 0.00;
        $std->vICMSDeson = 0.00;
        $std->vFCP     = 0.00;
        $std->vBCST    = 0.00;
        $std->vST      = 0.00;
        $std->vFCPST   = 0.00;
        $std->vFCPSTRet = 0.00;
        $std->vProd    = round($totalProd, 2);
        $std->vFrete   = 0.00;
        $std->vSeg     = 0.00;
        $std->vDesc    = round($totalDesc, 2);
        $std->vII      = 0.00;
        $std->vIPI     = 0.00;
        $std->vIPIDevol = 0.00;
        $std->vPIS     = 0.00;
        $std->vCOFINS  = 0.00;
        $std->vOutro   = 0.00;
        $std->vNF      = $totalNF;
        $make->tagICMSTot($std);

        // TRANSP
        $std          = new \stdClass();
        $std->modFrete = 9;
        $make->tagtransp($std);

        // INF ADIC
        if (!empty($pedido['informacoes_adicionais'])) {
            $std         = new \stdClass();
            $std->infCpl = $pedido['informacoes_adicionais'];
            $make->taginfAdic($std);
        }

        return $make->getXML();
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
vendor/bin/phpunit --colors=always tests/NfeXmlFactoryTest.php
```

Esperado: 2 testes passam. Se `tagICMSSN400`, `tagPISOutr`, ou `tagCOFINSOutr` não existirem na versão instalada do sped-nfe, verificar os métodos disponíveis com:

```bash
grep -r "function tag" vendor/nfephp-org/sped-nfe/src/Make.php | head -40
```

E ajustar os nomes de método conforme necessário.

- [ ] **Step 5: PSR-12 lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/NfeXmlFactory.php
vendor/bin/phpcbf --standard=PSR12 -n src/NfeXmlFactory.php
```

- [ ] **Step 6: Commit**

```bash
git add src/NfeXmlFactory.php tests/NfeXmlFactoryTest.php
git commit -m "feat(nfe): add NfeXmlFactory (Make wrapper, CSOSN 400, PIS/COFINS CST 07)"
```

---

### Task 4: NfeClient

**Files:**
- Create: `src/NfeClient.php`

**Interfaces:**
- Consumes: `Config`, `NfeXmlFactory`, `NFePHP\NFe\Tools`, `NFePHP\NFe\Complements`, `NFePHP\Common\Certificate`
- Produces:
  - `new NfeClient(Config $config, NfeXmlFactory $factory): NfeClient`
  - `emitir(array $pedido, array $itens, array $cliente, int $nNF, string $serie): array`
    — retorna `['chave'=>string, 'numero'=>int, 'protocolo'=>string, 'xml_autorizado'=>string]`
  - `cancelar(string $chave, string $xJust, string $nProt): string`
    — retorna protocolo do cancelamento

Não há testes unitários para esta classe (requer certificado A1 e acesso SEFAZ). O comportamento é testado via testes de integração manuais.

- [ ] **Step 1: Criar src/NfeClient.php**

```php
<?php

declare(strict_types=1);

namespace EmissorGyn;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;

final class NfeClient
{
    public function __construct(
        private readonly Config $config,
        private readonly NfeXmlFactory $factory
    ) {
    }

    public function emitir(
        array $pedido,
        array $itens,
        array $cliente,
        int $nNF,
        string $serie
    ): array {
        $cNF   = str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
        $xml   = $this->factory->build($pedido, $itens, $cliente, $nNF, $serie, $cNF);
        $tools = $this->buildTools();
        $tools->model('55');

        $signed  = $tools->signNFe($xml);
        $idLote  = str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT);
        $resp    = $tools->sefazEnviaLote([$signed], $idLote, 1);

        $dom = new \DOMDocument();
        $dom->loadXML($resp);

        $infProt = $dom->getElementsByTagName('infProt')->item(0);
        if ($infProt === null) {
            throw new \RuntimeException('Resposta SEFAZ sem infProt: ' . substr($resp, 0, 500));
        }
        $cStat   = $infProt->getElementsByTagName('cStat')->item(0)?->textContent ?? '';
        $xMotivo = $infProt->getElementsByTagName('xMotivo')->item(0)?->textContent ?? '';
        if ($cStat !== '100') {
            throw new \RuntimeException("SEFAZ rejeitou NF-e (cStat={$cStat}): {$xMotivo}");
        }
        $nProt  = $infProt->getElementsByTagName('nProt')->item(0)?->textContent ?? '';
        $chNFe  = $infProt->getElementsByTagName('chNFe')->item(0)?->textContent ?? '';

        $xmlAutorizado = Complements::toAuthorize($signed, $resp);

        return [
            'chave'          => $chNFe,
            'numero'         => $nNF,
            'protocolo'      => $nProt,
            'xml_autorizado' => $xmlAutorizado,
        ];
    }

    public function cancelar(string $chave, string $xJust, string $nProt): string
    {
        if (strlen($xJust) < 15) {
            throw new \InvalidArgumentException('Justificativa deve ter no mínimo 15 caracteres.');
        }
        $tools = $this->buildTools();
        $tools->model('55');
        $resp = $tools->sefazCancela($chave, $xJust, $nProt);

        $dom = new \DOMDocument();
        $dom->loadXML($resp);
        $cStat   = $dom->getElementsByTagName('cStat')->item(0)?->textContent ?? '';
        $xMotivo = $dom->getElementsByTagName('xMotivo')->item(0)?->textContent ?? '';

        if (!in_array($cStat, ['101', '155'], true)) {
            throw new \RuntimeException("Cancelamento rejeitado (cStat={$cStat}): {$xMotivo}");
        }

        $nProtCanc = $dom->getElementsByTagName('nProt')->item(0)?->textContent ?? '';
        return $nProtCanc;
    }

    private function buildTools(): Tools
    {
        $certPath = $this->config->path('CERT_PATH', '');
        $certPass = $this->config->get('CERT_PASS');
        $cnpj     = preg_replace('/\D/', '', $this->config->get('PRESTADOR_CNPJ'));
        $tpAmb    = $this->config->getInt('NFE_AMBIENTE', 2);

        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new \RuntimeException("Certificado não encontrado em: {$certPath}");
        }
        $certificate = Certificate::readPfx($certContent, $certPass);

        $configJson = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => $tpAmb,
            'razaoSocial' => $this->config->get('PRESTADOR_RAZAO_SOCIAL'),
            'siglaUF'     => 'GO',
            'tokenIBPT'   => '',
            'CSC'         => '',
            'CSCid'       => '',
            'cnpj'        => $cnpj,
            'CPF'         => '',
            'schemes'     => 'PL_009_V4',
            'versao'      => '4.00',
        ], JSON_THROW_ON_ERROR);

        return new Tools($configJson, $certificate);
    }
}
```

- [ ] **Step 2: Verificar syntax**

```bash
php -l src/NfeClient.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 3: PSR-12 lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/NfeClient.php
vendor/bin/phpcbf --standard=PSR12 -n src/NfeClient.php
```

- [ ] **Step 4: Rodar todos os testes**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos passam.

- [ ] **Step 5: Commit**

```bash
git add src/NfeClient.php
git commit -m "feat(nfe): add NfeClient (sign + SEFAZ envio/cancelamento)"
```

---

### Task 5: Rotas, wiring e coluna municipio

**Files:**
- Modify: `public/web.php`
- Modify: `public/pages/_head.php`
- Modify: `src/Cadastro.php` (adicionar coluna `municipio` ao migrate)
- Modify: `public/pages/clientes/form.php` (campo municipio)

**Interfaces:**
- Consumes: `NfeStorage`, `NfeClient`, `NfeXmlFactory` (criados nas tasks anteriores)
- Produces: variáveis `$nfeStorage`, `$nfeClient` disponíveis em todas as páginas de pedidos; rota `pedidos`, `pedidos/form`, `pedidos/ver` mapeadas; link "Pedidos" na navbar.

- [ ] **Step 1: Adicionar migração da coluna municipio ao Cadastro**

Em `src/Cadastro.php`, adicionar ao final do método `migrate()` (antes do fechamento `}`), após a instrução `SQL);`:

```php
        try {
            $this->pdo->exec('ALTER TABLE clientes ADD COLUMN municipio TEXT');
        } catch (\PDOException) {
        }
```

- [ ] **Step 2: Adicionar campo municipio ao form de clientes**

Em `public/pages/clientes/form.php`, após o campo `bairro` (procure `name="bairro"`), adicionar:

```php
    <div class="col-md-4">
        <label class="form-label">Município</label>
        <input type="text" name="municipio" class="form-control"
               value="<?= h((string) ($v['municipio'] ?? '')) ?>"
               placeholder="Goiânia">
    </div>
```

E nos métodos `inserirCliente` e `atualizarCliente` de `src/Cadastro.php`, adicionar `municipio` ao INSERT/UPDATE. Em `inserirCliente`:

```php
        $stmt = $this->pdo->prepare(
            'INSERT INTO clientes
                (razao_social, cpf_cnpj, email, telefone, logradouro, numero,
                 complemento, bairro, codigo_municipio, municipio, uf, cep)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $dados['municipio'] ?? null,
            $dados['uf'] ?? null,
            $dados['cep'] ?? null,
        ]);
```

Em `atualizarCliente`, o mesmo padrão: adicionar `municipio = ?` na query e `$dados['municipio'] ?? null` nos parâmetros antes de `$id`.

- [ ] **Step 3: Atualizar public/web.php**

Substituir o conteúdo de `public/web.php`:

```php
<?php

/**
 * Entry point da interface web Lumina — router e bootstrap.
 */

declare(strict_types=1);

use EmissorGyn\Auth;
use EmissorGyn\Cadastro;
use EmissorGyn\Config;
use EmissorGyn\NfeClient;
use EmissorGyn\NfeStorage;
use EmissorGyn\NfeXmlFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$config   = new Config(dirname(__DIR__));
$dbPath   = $config->path('DB_PATH', 'storage/nfse.sqlite');
$cadastro = new Cadastro($dbPath);
$auth     = new Auth($cadastro);
$auth->iniciarSessao();

$nfeStorage = new NfeStorage($dbPath);
$nfeFactory = new NfeXmlFactory($config);
$nfeClient  = new NfeClient($config, $nfeFactory);

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
    'pedidos'        => 'pedidos/index',
    'pedidos/form'   => 'pedidos/form',
    'pedidos/ver'    => 'pedidos/ver',
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

- [ ] **Step 4: Adicionar link Pedidos na navbar**

Em `public/pages/_head.php`, na div `navbar-nav`, adicionar após `Orçamentos`:

```php
            <a class="nav-link" href="?p=pedidos">Pedidos NF-e</a>
```

- [ ] **Step 5: Criar diretório das páginas de pedidos**

```bash
mkdir -p public/pages/pedidos
```

- [ ] **Step 6: Syntax check**

```bash
find src/ public/ -name "*.php" | xargs php -l
```

- [ ] **Step 7: PSR-12 lint**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/Cadastro.php public/web.php public/pages/_head.php public/pages/clientes/form.php
vendor/bin/phpcbf --standard=PSR12 -n src/Cadastro.php public/web.php public/pages/_head.php public/pages/clientes/form.php
```

- [ ] **Step 8: Todos os testes passam**

```bash
vendor/bin/phpunit --colors=always
```

- [ ] **Step 9: Commit**

```bash
git add public/web.php public/pages/_head.php src/Cadastro.php public/pages/clientes/form.php
git commit -m "feat(nfe): add pedidos routes, NfeStorage/Client wiring, municipio column"
```

---

### Task 6: pedidos/index.php

**Files:**
- Create: `public/pages/pedidos/index.php`

**Interfaces:**
- Consumes: `$nfeStorage->listarPedidos(string $status, string $busca): array`, `$auth->guard()`

- [ ] **Step 1: Criar public/pages/pedidos/index.php**

```php
<?php

declare(strict_types=1);

$status  = $_GET['status'] ?? '';
$busca   = trim($_GET['busca'] ?? '');
$pedidos = $nfeStorage->listarPedidos($status, $busca);

$pageTitle = 'Pedidos NF-e';
require PAGES_DIR . '/_head.php';

$statusCores = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Pedidos NF-e</h2>
    <a href="?p=pedidos/form" class="btn btn-primary">+ Novo Pedido</a>
</div>
<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="p" value="pedidos">
    <div class="col-md-4">
        <input type="text" name="busca" class="form-control" placeholder="Buscar cliente..."
               value="<?= h($busca) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">Todos os status</option>
            <?php foreach (['rascunho', 'aprovado', 'emitido', 'cancelado'] as $s) : ?>
            <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                <?= ucfirst(h($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">Filtrar</button>
        <a href="?p=pedidos" class="btn btn-link">Limpar</a>
    </div>
</form>
<?php if (empty($pedidos)) : ?>
<p class="text-muted">Nenhum pedido encontrado.</p>
<?php else : ?>
<table class="table table-hover align-middle">
    <thead>
    <tr>
        <th>#</th>
        <th>Cliente</th>
        <th class="text-end">Valor Total</th>
        <th class="text-center">Itens</th>
        <th class="text-center">Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($pedidos as $p) : ?>
    <tr>
        <td><?= (int) $p['id'] ?></td>
        <td>
            <?= h($p['razao_social']) ?>
            <small class="text-muted d-block"><?= h($p['cpf_cnpj']) ?></small>
        </td>
        <td class="text-end">R$ <?= h(number_format((float) $p['valor_total'], 2, ',', '.')) ?></td>
        <td class="text-center"><?= (int) $p['total_itens'] ?></td>
        <td class="text-center">
            <?php $cor = $statusCores[$p['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $cor ?>"><?= ucfirst(h($p['status'])) ?></span>
        </td>
        <td>
            <a href="?p=pedidos/ver&amp;id=<?= (int) $p['id'] ?>"
               class="btn btn-sm btn-outline-primary">Ver</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 2: Syntax check e lint**

```bash
php -l public/pages/pedidos/index.php
vendor/bin/phpcs --standard=PSR12 -n public/pages/pedidos/index.php
vendor/bin/phpcbf --standard=PSR12 -n public/pages/pedidos/index.php
```

- [ ] **Step 3: Testar no browser**

```bash
php -S localhost:8000 -t public public/web.php &
```

Acessar `http://localhost:8000/?p=pedidos` (após login). Deve exibir a listagem vazia com formulário de busca.

- [ ] **Step 4: Commit**

```bash
git add public/pages/pedidos/index.php
git commit -m "feat(nfe): add pedidos/index.php listing page"
```

---

### Task 7: pedidos/form.php

**Files:**
- Create: `public/pages/pedidos/form.php`

**Interfaces:**
- Consumes: `$nfeStorage`, `$cadastro->listarClientes()`, `$auth->usuarioAtual()`
- Validates server-side: cliente obrigatório, ≥1 item, NCM 8 dígitos, CFOP 4 dígitos (5xxx ou 6xxx), qtd > 0, vUnit > 0.

- [ ] **Step 1: Criar public/pages/pedidos/form.php**

```php
<?php

declare(strict_types=1);

$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$pedido = $id !== null ? $nfeStorage->buscarPedido($id) : null;
$itens  = $id !== null ? $nfeStorage->listarItens($id) : [];
$flash  = null;

if ($pedido !== null && $pedido['status'] !== 'rascunho') {
    header('Location: ?p=pedidos/ver&id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $erros  = [];
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        if ($clienteId === 0) {
            $erros[] = 'Cliente é obrigatório.';
        }

        $itensPost = $_POST['itens'] ?? [];
        if (empty($itensPost)) {
            $erros[] = 'Adicione pelo menos um item.';
        }

        $itensValidos = [];
        foreach ($itensPost as $idx => $item) {
            $ncm  = preg_replace('/\D/', '', $item['ncm'] ?? '');
            $cfop = preg_replace('/\D/', '', $item['cfop'] ?? '');
            $qtd  = (float) ($item['quantidade'] ?? 0);
            $vUnit = (float) ($item['valor_unitario'] ?? 0);
            if (strlen($ncm) !== 8) {
                $erros[] = "Item " . ($idx + 1) . ": NCM deve ter 8 dígitos.";
            }
            if (!preg_match('/^[56]\d{3}$/', $cfop)) {
                $erros[] = "Item " . ($idx + 1) . ": CFOP deve ter 4 dígitos iniciando com 5 ou 6.";
            }
            if ($qtd <= 0) {
                $erros[] = "Item " . ($idx + 1) . ": Quantidade deve ser maior que zero.";
            }
            if ($vUnit <= 0) {
                $erros[] = "Item " . ($idx + 1) . ": Valor unitário deve ser maior que zero.";
            }
            if ($erros === []) {
                $vDesc = (float) ($item['valor_desconto'] ?? 0);
                $itensValidos[] = [
                    'numero_item'                => $idx + 1,
                    'codigo_produto'             => trim($item['codigo_produto'] ?? ''),
                    'descricao'                  => trim($item['descricao'] ?? ''),
                    'ncm'                        => $ncm,
                    'cfop'                       => $cfop,
                    'unidade'                    => trim($item['unidade'] ?? 'UN') ?: 'UN',
                    'quantidade'                 => number_format($qtd, 4, '.', ''),
                    'valor_unitario'             => number_format($vUnit, 2, '.', ''),
                    'valor_desconto'             => $vDesc > 0 ? number_format($vDesc, 2, '.', '') : null,
                    'csosn'                      => trim($item['csosn'] ?? '400') ?: '400',
                    'pis_cst'                    => trim($item['pis_cst'] ?? '07') ?: '07',
                    'cofins_cst'                 => trim($item['cofins_cst'] ?? '07') ?: '07',
                    'informacoes_adicionais_item' => trim($item['obs'] ?? '') ?: null,
                ];
            }
        }

        if ($erros !== []) {
            $flash = ['tipo' => 'danger', 'msg' => implode(' ', $erros)];
        } else {
            $usuario   = $auth->usuarioAtual();
            $usuarioId = (int) ($usuario['id'] ?? 0);
            $dados = [
                'cliente_id'            => $clienteId,
                'natureza_operacao'     => trim($_POST['natureza_operacao'] ?? 'Venda de mercadoria') ?: 'Venda de mercadoria',
                'consumidor_final'      => isset($_POST['consumidor_final']) ? 1 : 0,
                'presenca'              => (int) ($_POST['presenca'] ?? 1),
                'informacoes_adicionais' => trim($_POST['informacoes_adicionais'] ?? '') ?: null,
                'criado_por'            => $usuarioId,
            ];

            if ($id !== null) {
                $nfeStorage->atualizarPedido($id, $dados);
                $nfeStorage->substituirItens($id, $itensValidos);
                $pedido = $nfeStorage->buscarPedido($id);
                $itens  = $nfeStorage->listarItens($id);
                $flash  = ['tipo' => 'success', 'msg' => 'Pedido atualizado.'];
            } else {
                $novoId = $nfeStorage->inserirPedido($dados);
                $nfeStorage->substituirItens($novoId, $itensValidos);
                header('Location: ?p=pedidos/ver&id=' . $novoId);
                exit;
            }
        }
    }
} elseif (isset($_GET['salvo'])) {
    $flash = ['tipo' => 'success', 'msg' => 'Pedido criado.'];
}

$clientes  = $cadastro->listarClientes();
$pageTitle = $id !== null ? 'Editar Pedido #' . $id : 'Novo Pedido NF-e';
require PAGES_DIR . '/_head.php';

$v           = $pedido ?? [];
$presencaOpt = [
    1 => '1 — Presencial',
    2 => '2 — Internet',
    3 => '3 — Teleatendimento',
    4 => '4 — Entrega domiciliar',
    9 => '9 — Outros',
];
$presAtual = (int) ($v['presenca'] ?? 1);
?>
<div class="d-flex justify-content-between mb-3">
    <h2><?= h($pageTitle) ?></h2>
    <a href="?p=pedidos" class="btn btn-outline-secondary">← Voltar</a>
</div>
<form method="post" id="pedidoForm" class="row g-3">
    <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

    <div class="col-md-6">
        <label class="form-label">Cliente *</label>
        <select name="cliente_id" class="form-select" required>
            <option value="">— selecione —</option>
            <?php foreach ($clientes as $c) : ?>
            <option value="<?= (int) $c['id'] ?>"
                <?= (int) ($v['cliente_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                <?= h($c['razao_social']) ?> (<?= h($c['cpf_cnpj']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Natureza da Operação</label>
        <input type="text" name="natureza_operacao" class="form-control"
               value="<?= h((string) ($v['natureza_operacao'] ?? 'Venda de mercadoria')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Indicador de Presença</label>
        <select name="presenca" class="form-select">
            <?php foreach ($presencaOpt as $val => $label) : ?>
            <option value="<?= $val ?>" <?= $presAtual === $val ? 'selected' : '' ?>>
                <?= h($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check mb-2">
            <input type="checkbox" name="consumidor_final" id="consumidorFinal"
                   class="form-check-input" value="1"
                   <?= !empty($v['consumidor_final']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="consumidorFinal">Consumidor Final</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label">Informações Adicionais</label>
        <textarea name="informacoes_adicionais" class="form-control" rows="2"><?= h(
            (string) ($v['informacoes_adicionais'] ?? '')
        ) ?></textarea>
    </div>

    <div class="col-12 mt-4">
        <h5>Itens</h5>
        <table class="table table-bordered table-sm" id="itensTable">
            <thead class="table-light">
            <tr>
                <th style="width:3%">#</th>
                <th>Descrição *</th>
                <th style="width:10%">NCM *</th>
                <th style="width:8%">CFOP *</th>
                <th style="width:6%">Unid.</th>
                <th style="width:8%">Qtd *</th>
                <th style="width:10%">V. Unit. *</th>
                <th style="width:9%">Desconto</th>
                <th style="width:10%">Total</th>
                <th style="width:3%"></th>
            </tr>
            </thead>
            <tbody id="itensBody">
            <?php foreach ($itens as $i => $item) : ?>
            <tr class="item-row" data-idx="<?= $i ?>">
                <td class="text-center item-num"><?= $i + 1 ?></td>
                <td>
                    <input type="text" name="itens[<?= $i ?>][descricao]"
                           class="form-control form-control-sm" required
                           value="<?= h($item['descricao']) ?>">
                    <details class="mt-1">
                        <summary class="text-muted small" style="cursor:pointer">Dados fiscais</summary>
                        <div class="row g-1 mt-1">
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][codigo_produto]"
                                       class="form-control form-control-sm"
                                       placeholder="Cód. Produto"
                                       value="<?= h($item['codigo_produto'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][csosn]"
                                       class="form-control form-control-sm"
                                       placeholder="CSOSN"
                                       value="<?= h($item['csosn'] ?? '400') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][pis_cst]"
                                       class="form-control form-control-sm"
                                       placeholder="PIS CST"
                                       value="<?= h($item['pis_cst'] ?? '07') ?>">
                            </div>
                            <div class="col-6">
                                <input type="text" name="itens[<?= $i ?>][cofins_cst]"
                                       class="form-control form-control-sm"
                                       placeholder="COFINS CST"
                                       value="<?= h($item['cofins_cst'] ?? '07') ?>">
                            </div>
                            <div class="col-12">
                                <input type="text" name="itens[<?= $i ?>][obs]"
                                       class="form-control form-control-sm"
                                       placeholder="Obs. do item"
                                       value="<?= h($item['informacoes_adicionais_item'] ?? '') ?>">
                            </div>
                        </div>
                    </details>
                </td>
                <td><input type="text" name="itens[<?= $i ?>][ncm]"
                           class="form-control form-control-sm" required maxlength="8"
                           value="<?= h($item['ncm']) ?>"></td>
                <td><input type="text" name="itens[<?= $i ?>][cfop]"
                           class="form-control form-control-sm" required maxlength="4"
                           value="<?= h($item['cfop']) ?>"></td>
                <td><input type="text" name="itens[<?= $i ?>][unidade]"
                           class="form-control form-control-sm" maxlength="6"
                           value="<?= h($item['unidade'] ?? 'UN') ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][quantidade]"
                           class="form-control form-control-sm item-qtd" step="0.0001" min="0.0001" required
                           value="<?= h($item['quantidade']) ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][valor_unitario]"
                           class="form-control form-control-sm item-vunit" step="0.01" min="0.01" required
                           value="<?= h($item['valor_unitario']) ?>"></td>
                <td><input type="number" name="itens[<?= $i ?>][valor_desconto]"
                           class="form-control form-control-sm item-vdesc" step="0.01" min="0"
                           value="<?= h($item['valor_desconto'] ?? '') ?>"></td>
                <td class="text-end item-total fw-bold">
                    R$ <?= h(number_format(
                        (float) $item['quantidade'] * (float) $item['valor_unitario']
                        - (float) ($item['valor_desconto'] ?? 0),
                        2, ',', '.'
                    )) ?>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remover">✕</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="8" class="text-end fw-bold">Total Geral:</td>
                <td class="text-end fw-bold" id="totalGeral">R$ 0,00</td>
                <td></td>
            </tr>
            </tfoot>
        </table>
        <button type="button" id="btnAddItem" class="btn btn-outline-secondary btn-sm">
            ＋ Adicionar Item
        </button>
    </div>

    <div class="col-12 d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Salvar Rascunho</button>
        <a href="?p=pedidos" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
<script>
(function () {
    let idx = <?= max(count($itens) - 1, -1) ?>;

    function novaLinha(n) {
        idx++;
        const i = idx;
        return `<tr class="item-row" data-idx="${i}">
            <td class="text-center item-num">${n}</td>
            <td>
                <input type="text" name="itens[${i}][descricao]"
                       class="form-control form-control-sm" required placeholder="Descrição">
                <details class="mt-1">
                    <summary class="text-muted small" style="cursor:pointer">Dados fiscais</summary>
                    <div class="row g-1 mt-1">
                        <div class="col-6"><input type="text" name="itens[${i}][codigo_produto]"
                            class="form-control form-control-sm" placeholder="Cód. Produto"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][csosn]"
                            class="form-control form-control-sm" placeholder="CSOSN" value="400"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][pis_cst]"
                            class="form-control form-control-sm" placeholder="PIS CST" value="07"></div>
                        <div class="col-6"><input type="text" name="itens[${i}][cofins_cst]"
                            class="form-control form-control-sm" placeholder="COFINS CST" value="07"></div>
                        <div class="col-12"><input type="text" name="itens[${i}][obs]"
                            class="form-control form-control-sm" placeholder="Obs. do item"></div>
                    </div>
                </details>
            </td>
            <td><input type="text" name="itens[${i}][ncm]"
                class="form-control form-control-sm" required maxlength="8"></td>
            <td><input type="text" name="itens[${i}][cfop]"
                class="form-control form-control-sm" required maxlength="4" value="5102"></td>
            <td><input type="text" name="itens[${i}][unidade]"
                class="form-control form-control-sm" maxlength="6" value="UN"></td>
            <td><input type="number" name="itens[${i}][quantidade]"
                class="form-control form-control-sm item-qtd" step="0.0001" min="0.0001" required value="1"></td>
            <td><input type="number" name="itens[${i}][valor_unitario]"
                class="form-control form-control-sm item-vunit" step="0.01" min="0.01" required value="0.00"></td>
            <td><input type="number" name="itens[${i}][valor_desconto]"
                class="form-control form-control-sm item-vdesc" step="0.01" min="0" value=""></td>
            <td class="text-end item-total fw-bold">R$ 0,00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remover">✕</button>
            </td>
        </tr>`;
    }

    function fmt(n) {
        return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function recalcular() {
        let total = 0;
        document.querySelectorAll('#itensBody .item-row').forEach(function (tr, pos) {
            tr.querySelector('.item-num').textContent = pos + 1;
            const qtd   = parseFloat(tr.querySelector('.item-qtd').value)   || 0;
            const vunit = parseFloat(tr.querySelector('.item-vunit').value)  || 0;
            const vdesc = parseFloat(tr.querySelector('.item-vdesc').value)  || 0;
            const linha = Math.max(qtd * vunit - vdesc, 0);
            tr.querySelector('.item-total').textContent = fmt(linha);
            total += linha;
        });
        document.getElementById('totalGeral').textContent = fmt(total);
    }

    const body = document.getElementById('itensBody');

    document.getElementById('btnAddItem').addEventListener('click', function () {
        const n = body.querySelectorAll('.item-row').length + 1;
        body.insertAdjacentHTML('beforeend', novaLinha(n));
        body.querySelector('.item-row:last-child .item-qtd').focus();
        recalcular();
    });

    body.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remover')) {
            e.target.closest('.item-row').remove();
            recalcular();
        }
    });

    body.addEventListener('input', recalcular);

    recalcular();
}());
</script>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 2: Syntax check e lint**

```bash
php -l public/pages/pedidos/form.php
vendor/bin/phpcs --standard=PSR12 -n public/pages/pedidos/form.php
vendor/bin/phpcbf --standard=PSR12 -n public/pages/pedidos/form.php
```

- [ ] **Step 3: Testar no browser**

Acessar `http://localhost:8000/?p=pedidos/form`. Deve exibir o formulário. Clicar em "＋ Adicionar Item" deve adicionar uma linha na tabela. O total deve calcular em tempo real ao editar quantidade/valor.

- [ ] **Step 4: Commit**

```bash
git add public/pages/pedidos/form.php
git commit -m "feat(nfe): add pedidos/form.php with dynamic items table"
```

---

### Task 8: pedidos/ver.php (workflow + DANFE + cancelamento)

**Files:**
- Create: `public/pages/pedidos/ver.php`

**Interfaces:**
- Consumes: `$nfeStorage->buscarPedido()`, `$nfeStorage->listarItens()`, `$nfeStorage->listarEventos()`, `$nfeStorage->proximoNfe()`, `$nfeStorage->definirUltimoNfe()`, `$nfeStorage->aprovarPedido()`, `$nfeStorage->emitirPedido()`, `$nfeStorage->registrarEvento()`, `$nfeStorage->cancelarPedido()`, `$nfeClient->emitir()`, `$nfeClient->cancelar()`

- [ ] **Step 1: Criar public/pages/pedidos/ver.php**

```php
<?php

declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id === 0) {
    header('Location: ?p=pedidos');
    exit;
}

$pedido = $nfeStorage->buscarPedido($id);
if ($pedido === null) {
    http_response_code(404);
    exit('Pedido não encontrado.');
}

$flash = null;

// DANFE — servir PDF inline
if (($_GET['acao'] ?? '') === 'danfe' && $pedido['status'] === 'emitido') {
    $xml = $pedido['nfe_xml_autorizado'] ?? '';
    if ($xml === '') {
        exit('XML autorizado não encontrado.');
    }
    $danfe = new NFePHP\DA\NFe\Danfe($xml);
    $danfe->monta();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="nfe-' . $pedido['nfe_chave'] . '.pdf"');
    echo $danfe->render();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validarCsrf($_POST['_csrf'] ?? '')) {
        $flash = ['tipo' => 'danger', 'msg' => 'Token inválido.'];
    } else {
        $acao    = $_POST['acao'] ?? '';
        $usuario = $auth->usuarioAtual();
        $uid     = (int) ($usuario['id'] ?? 0);

        if ($acao === 'aprovar' && $pedido['status'] === 'rascunho') {
            $nfeStorage->aprovarPedido($id, $uid);
            $pedido = $nfeStorage->buscarPedido($id);
            $flash  = ['tipo' => 'success', 'msg' => 'Pedido aprovado.'];
        } elseif ($acao === 'cancelar_rascunho'
            && in_array($pedido['status'], ['rascunho', 'aprovado'], true)
        ) {
            $nfeStorage->cancelarPedido($id);
            $pedido = $nfeStorage->buscarPedido($id);
            $flash  = ['tipo' => 'warning', 'msg' => 'Pedido cancelado.'];
        } elseif ($acao === 'emitir' && $pedido['status'] === 'aprovado') {
            $serie   = $config->get('NFE_SERIE', '1');
            $nNF     = $nfeStorage->proximoNfe($serie);
            $itens   = $nfeStorage->listarItens($id);
            try {
                $resultado = $nfeClient->emitir($pedido, $itens, $pedido, $nNF, $serie);
                $nfeStorage->emitirPedido(
                    $id,
                    $resultado['chave'],
                    $resultado['numero'],
                    $serie,
                    $resultado['protocolo'],
                    $resultado['xml_autorizado']
                );
                $pedido = $nfeStorage->buscarPedido($id);
                $flash  = ['tipo' => 'success', 'msg' => 'NF-e autorizada! Chave: ' . $resultado['chave']];
            } catch (\Throwable $e) {
                $nfeStorage->definirUltimoNfe($serie, $nNF - 1);
                $flash = ['tipo' => 'danger', 'msg' => 'Erro ao emitir: ' . $e->getMessage()];
            }
        } elseif ($acao === 'cancelar_nfe' && $pedido['status'] === 'emitido') {
            $xJust = trim($_POST['xjust'] ?? '');
            if (strlen($xJust) < 15) {
                $flash = ['tipo' => 'danger', 'msg' => 'Justificativa deve ter no mínimo 15 caracteres.'];
            } else {
                try {
                    $protCanc = $nfeClient->cancelar(
                        $pedido['nfe_chave'],
                        $xJust,
                        $pedido['nfe_protocolo']
                    );
                    $nfeStorage->registrarEvento(
                        $id, 'cancelamento', $protCanc, $xJust, '', ''
                    );
                    $nfeStorage->cancelarPedido($id);
                    $pedido = $nfeStorage->buscarPedido($id);
                    $flash  = ['tipo' => 'warning', 'msg' => 'NF-e cancelada. Protocolo: ' . $protCanc];
                } catch (\Throwable $e) {
                    $flash = ['tipo' => 'danger', 'msg' => 'Erro ao cancelar: ' . $e->getMessage()];
                }
            }
        }
    }
}

$itens   = $nfeStorage->listarItens($id);
$eventos = $nfeStorage->listarEventos($id);

$totalGeral = array_reduce($itens, static function (float $carry, array $item): float {
    return $carry
        + (float) $item['quantidade'] * (float) $item['valor_unitario']
        - (float) ($item['valor_desconto'] ?? 0);
}, 0.0);

$statusCor = [
    'rascunho'  => 'secondary',
    'aprovado'  => 'warning',
    'emitido'   => 'success',
    'cancelado' => 'danger',
];

$pageTitle = 'Pedido NF-e #' . $id;
require PAGES_DIR . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        Pedido #<?= $id ?>
        <span class="badge bg-<?= $statusCor[$pedido['status']] ?? 'secondary' ?> fs-6 ms-2">
            <?= ucfirst(h($pedido['status'])) ?>
        </span>
    </h2>
    <div class="d-flex gap-2">
        <a href="?p=pedidos" class="btn btn-outline-secondary">← Pedidos</a>
        <?php if ($pedido['status'] === 'rascunho') : ?>
        <a href="?p=pedidos/form&amp;id=<?= $id ?>" class="btn btn-outline-primary">Editar</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Cliente</div>
            <div class="card-body">
                <strong><?= h($pedido['razao_social']) ?></strong><br>
                <?= h($pedido['cpf_cnpj']) ?><br>
                <?php if ($pedido['logradouro']) : ?>
                <?= h($pedido['logradouro']) ?>, <?= h($pedido['cliente_numero'] ?? '') ?>
                <?php if ($pedido['complemento'] ?? '') : ?>
                    — <?= h($pedido['complemento']) ?>
                <?php endif; ?><br>
                <?= h($pedido['bairro'] ?? '') ?> — <?= h($pedido['uf'] ?? '') ?>
                <?= h($pedido['cep'] ?? '') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Dados da Operação</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Natureza</dt>
                    <dd class="col-sm-7"><?= h($pedido['natureza_operacao']) ?></dd>
                    <dt class="col-sm-5">Presença</dt>
                    <dd class="col-sm-7"><?= (int) $pedido['presenca'] ?></dd>
                    <dt class="col-sm-5">Consumidor final</dt>
                    <dd class="col-sm-7"><?= $pedido['consumidor_final'] ? 'Sim' : 'Não' ?></dd>
                    <?php if ($pedido['nfe_chave']) : ?>
                    <dt class="col-sm-5">Chave NF-e</dt>
                    <dd class="col-sm-7 text-break">
                        <code class="small"><?= h(implode(' ', str_split($pedido['nfe_chave'], 4))) ?></code>
                    </dd>
                    <dt class="col-sm-5">Protocolo</dt>
                    <dd class="col-sm-7"><?= h($pedido['nfe_protocolo'] ?? '') ?></dd>
                    <?php endif; ?>
                    <?php if ($pedido['informacoes_adicionais']) : ?>
                    <dt class="col-sm-5">Inf. Adicionais</dt>
                    <dd class="col-sm-7"><?= h($pedido['informacoes_adicionais']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<h5>Itens</h5>
<table class="table table-sm table-hover mb-4">
    <thead>
    <tr>
        <th>#</th><th>Descrição</th><th>NCM</th><th>CFOP</th>
        <th class="text-center">Unid.</th><th class="text-end">Qtd</th>
        <th class="text-end">V. Unit.</th><th class="text-end">Desconto</th>
        <th class="text-end">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($itens as $item) : ?>
    <?php
    $vProd  = (float) $item['quantidade'] * (float) $item['valor_unitario'];
    $vDesc  = (float) ($item['valor_desconto'] ?? 0);
    $vItem  = $vProd - $vDesc;
    ?>
    <tr>
        <td><?= (int) $item['numero_item'] ?></td>
        <td>
            <?= h($item['descricao']) ?>
            <?php if ($item['codigo_produto']) : ?>
            <small class="text-muted">(<?= h($item['codigo_produto']) ?>)</small>
            <?php endif; ?>
        </td>
        <td><?= h($item['ncm']) ?></td>
        <td><?= h($item['cfop']) ?></td>
        <td class="text-center"><?= h($item['unidade']) ?></td>
        <td class="text-end"><?= h(number_format((float) $item['quantidade'], 4, ',', '.')) ?></td>
        <td class="text-end">R$ <?= h(number_format((float) $item['valor_unitario'], 2, ',', '.')) ?></td>
        <td class="text-end"><?= $vDesc > 0 ? 'R$ ' . h(number_format($vDesc, 2, ',', '.')) : '—' ?></td>
        <td class="text-end fw-bold">R$ <?= h(number_format($vItem, 2, ',', '.')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr>
        <td colspan="8" class="text-end fw-bold">Total Geral</td>
        <td class="text-end fw-bold">R$ <?= h(number_format($totalGeral, 2, ',', '.')) ?></td>
    </tr>
    </tfoot>
</table>

<?php // Ações por status ?>
<div class="d-flex gap-2 flex-wrap">
    <?php if ($pedido['status'] === 'rascunho') : ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="aprovar">
        <button type="submit" class="btn btn-warning"
                onclick="return confirm('Aprovar este pedido?')">Aprovar</button>
    </form>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="cancelar_rascunho">
        <button type="submit" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar pedido?')">Cancelar</button>
    </form>
    <?php elseif ($pedido['status'] === 'aprovado') : ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="emitir">
        <button type="submit" class="btn btn-success"
                onclick="return confirm('Emitir NF-e junto à SEFAZ?')">
            &#128196; Emitir NF-e
        </button>
    </form>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="cancelar_rascunho">
        <button type="submit" class="btn btn-outline-danger"
                onclick="return confirm('Cancelar pedido?')">Cancelar</button>
    </form>
    <?php elseif ($pedido['status'] === 'emitido') : ?>
    <a href="?p=pedidos/ver&amp;id=<?= $id ?>&amp;acao=danfe"
       class="btn btn-outline-primary" target="_blank">&#11015; DANFE PDF</a>
    <button type="button" class="btn btn-outline-danger"
            data-bs-toggle="collapse" data-bs-target="#cancelNfeForm">
        Cancelar NF-e ▼
    </button>
    <div class="collapse w-100 mt-2" id="cancelNfeForm">
        <form method="post" class="card card-body">
            <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
            <input type="hidden" name="acao" value="cancelar_nfe">
            <div class="mb-2">
                <label class="form-label">Justificativa (mín. 15 caracteres) *</label>
                <textarea name="xjust" class="form-control" rows="2" required minlength="15"></textarea>
            </div>
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Confirmar cancelamento da NF-e?')">
                Confirmar Cancelamento
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($eventos)) : ?>
<h5 class="mt-4">Eventos</h5>
<table class="table table-sm">
    <thead><tr><th>Tipo</th><th>Protocolo</th><th>Descrição</th><th>Data</th></tr></thead>
    <tbody>
    <?php foreach ($eventos as $ev) : ?>
    <tr>
        <td><?= ucfirst(h($ev['tipo'])) ?></td>
        <td><?= h($ev['protocolo'] ?? '') ?></td>
        <td><?= h($ev['descricao'] ?? '') ?></td>
        <td><?= h($ev['criado_em']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 2: Adicionar use declaration para NFePHP\DA em web.php**

Em `public/web.php`, a classe `NFePHP\DA\NFe\Danfe` é referenciada com o namespace completo na página — não precisa de `use`. Verificar que o autoload resolve.

```bash
php -r "require 'vendor/autoload.php'; new NFePHP\DA\NFe\Danfe('');" 2>&1 | head -5
```

Esperado: erro de argumento inválido (não "class not found") — confirma que o autoload funciona.

- [ ] **Step 3: Syntax check e lint**

```bash
php -l public/pages/pedidos/ver.php
vendor/bin/phpcs --standard=PSR12 -n public/pages/pedidos/ver.php
vendor/bin/phpcbf --standard=PSR12 -n public/pages/pedidos/ver.php
```

- [ ] **Step 4: Todos os testes passam**

```bash
vendor/bin/phpunit --colors=always
```

- [ ] **Step 5: Commit**

```bash
git add public/pages/pedidos/ver.php
git commit -m "feat(nfe): add pedidos/ver.php with workflow, DANFE and cancelamento"
```

---

### Task 9: Dashboard + Estatísticas NF-e

**Files:**
- Modify: `public/pages/dashboard.php`

**Interfaces:**
- Consumes: `$nfeStorage->estatisticas(): array<string,int>`

- [ ] **Step 1: Atualizar public/pages/dashboard.php**

Substituir o conteúdo do arquivo:

```php
<?php

declare(strict_types=1);

$stats    = $cadastro->estatisticas();
$nfeStats = $nfeStorage->estatisticas();

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
    <div class="d-flex gap-2">
        <a href="?p=orcamentos/form" class="btn btn-outline-secondary">+ Orçamento NFS-e</a>
        <a href="?p=pedidos/form" class="btn btn-primary">+ Pedido NF-e</a>
    </div>
</div>

<h5 class="text-muted">NFS-e (Serviços)</h5>
<div class="row g-3 mb-4">
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

<h5 class="text-muted">NF-e (Produtos)</h5>
<div class="row g-3 mb-5">
    <?php foreach ($statusCores as $status => $cor) : ?>
    <div class="col-md-2">
        <div class="card text-center h-100 border-<?= $cor ?>">
            <div class="card-body">
                <div class="display-5 fw-bold">
                    <?= (int) $nfeStats[$status] ?>
                </div>
                <div class="text-muted small"><?= ucfirst(h($status)) ?></div>
                <a href="?p=pedidos&amp;status=<?= h($status) ?>"
                   class="btn btn-sm btn-outline-<?= $cor ?> mt-2">Ver</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<h5>Últimas emissões NFS-e autorizadas</h5>
<?php if (empty($stats['ultimas_emissoes'])) : ?>
<p class="text-muted">Nenhuma emissão ainda.</p>
<?php else : ?>
<table class="table table-sm table-hover mb-5">
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

<div class="mt-2 d-flex gap-2">
    <a href="?p=clientes/form" class="btn btn-outline-secondary">+ Novo Cliente</a>
    <a href="?p=servicos/form" class="btn btn-outline-secondary">+ Novo Serviço</a>
</div>
<?php require PAGES_DIR . '/_foot.php'; ?>
```

- [ ] **Step 2: Syntax check e lint**

```bash
php -l public/pages/dashboard.php
vendor/bin/phpcs --standard=PSR12 -n public/pages/dashboard.php
vendor/bin/phpcbf --standard=PSR12 -n public/pages/dashboard.php
```

- [ ] **Step 3: Todos os testes passam**

```bash
vendor/bin/phpunit --colors=always
```

Esperado: todos os testes passam.

- [ ] **Step 4: Testar no browser**

```bash
php -S localhost:8000 -t public public/web.php &
```

Verificar:
- Dashboard mostra seções NFS-e e NF-e separadas.
- Link "Pedidos NF-e" na navbar leva para listagem.
- Criar novo pedido: preencher cliente, adicionar ≥1 item, salvar.
- Ver pedido → Aprovar → botão "Emitir NF-e" aparece.
- Em homologação, o botão de emissão retornará erro de certificado inválido (esperado até ter cert real).

- [ ] **Step 5: Lint final de todos os arquivos**

```bash
vendor/bin/phpcs --standard=PSR12 -n src/ public/
```

Corrigir qualquer erro com `phpcbf`.

- [ ] **Step 6: Commit final**

```bash
git add public/pages/dashboard.php
git commit -m "feat(nfe): update dashboard with NF-e stats and Pedidos link"
```
