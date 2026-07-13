# Clonar Pedidos e Orçamentos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user clone an existing pedido (NF-e) or orçamento (NFS-e) into a new draft, copying its business data, so they don't have to re-type everything for a similar sale/service.

**Architecture:** Two independent, parallel features — `NfeStorage::clonarPedido()` for pedidos, `Cadastro::clonarOrcamento()` for orçamentos — each fetching the original row (+ items, for pedidos) and reusing the module's existing `inserir*()`/`substituirItens()` methods to create the copy. Each is wired into its `ver.php` page as a new POST action that redirects to the new record's own `ver.php` on success.

**Tech Stack:** PHP 8.5, PDO/SQLite (existing `NfeStorage`/`Cadastro` classes), PHPUnit.

## Global Constraints

- Clone button visible on `ver.php` for **any** status (rascunho, aprovado, emitido, cancelado) — not on the list pages.
- Clicking clone creates the new record immediately (no "preview, then save" step) and redirects straight to the new record's `ver.php`.
- New record: `status='rascunho'`, `criado_por` = the user who clicked clone, all numbering/emission/approval/cancellation fields `null`.
- Orçamento clone resets `competencia` to today's date (`date('Y-m-d')`, matching the field's existing default-on-create convention in `form.php`/`XmlFactory.php` — it is a full date, not a year-month string) — the original's competência is never copied.
- Not-found source id → `\RuntimeException`, defensive-only (the `ver.php` page that renders the clone button has already loaded the record via its own `buscarPedido()`/`buscarOrcamento()` 404 guard).

Spec: `docs/superpowers/specs/2026-07-13-clonar-pedidos-orcamentos-design.md`

---

### Task 1: `NfeStorage::clonarPedido()`

**Files:**
- Modify: `src/NfeStorage.php` (add method after `cancelarPedido()`, currently ending around line 251)
- Test: `tests/CadastroTest.php` (add to the existing "─── NfeStorage ───" section, after `testSubstituirItens()`)

**Interfaces:**
- Consumes: `NfeStorage::buscarPedido(int $id): ?array` (existing), `NfeStorage::listarItens(int $pedidoId): array` (existing), `NfeStorage::inserirPedido(array $dados): int` (existing), `NfeStorage::substituirItens(int $pedidoId, array $itens): void` (existing).
- Produces: `NfeStorage::clonarPedido(int $pedidoId, int $usuarioId): int` — returns the new pedido's id. Throws `\RuntimeException` if `$pedidoId` doesn't exist.

- [ ] **Step 1: Write the failing test**

Add to `tests/CadastroTest.php`, in the `// ─── NfeStorage ───` section (after `testSubstituirItens`):

```php
    public function testClonarPedidoCopiaCamposEItensEResetaEstado(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $pdo = $storage->getPdoForTest();
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS clientes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                razao_social TEXT NOT NULL,
                cpf_cnpj TEXT NOT NULL,
                logradouro TEXT,
                numero TEXT,
                complemento TEXT,
                bairro TEXT,
                municipio TEXT,
                codigo_municipio TEXT,
                uf TEXT,
                cep TEXT,
                email TEXT,
                telefone TEXT,
                ativo INTEGER NOT NULL DEFAULT 1
            )
        SQL);
        $pdo->exec("INSERT INTO clientes (razao_social, cpf_cnpj) VALUES ('Cliente Clone','11222333000181')");
        $clienteId = (int) $pdo->lastInsertId();

        $originalId = $storage->inserirPedido([
            'cliente_id'             => $clienteId,
            'natureza_operacao'      => 'Venda de mercadoria',
            'consumidor_final'       => 1,
            'presenca'               => 1,
            'informacoes_adicionais' => 'Observação original',
            'criado_por'             => 1,
        ]);
        $storage->substituirItens($originalId, [[
            'numero_item'                 => 1,
            'codigo_produto'              => 'P001',
            'descricao'                   => 'Produto Clonável',
            'ncm'                         => '84713012',
            'cfop'                        => '5102',
            'unidade'                     => 'UN',
            'quantidade'                  => '3.0000',
            'valor_unitario'              => '150.00',
            'valor_desconto'              => null,
            'csosn'                       => '400',
            'pis_cst'                     => '07',
            'cofins_cst'                  => '07',
            'informacoes_adicionais_item' => null,
        ]]);
        $storage->aprovarPedido($originalId, 1);
        $storage->emitirPedido($originalId, 'CHAVE-ORIG', 1, '1', 'PROT-ORIG', '<xml/>');

        $novoId = $storage->clonarPedido($originalId, 42);
        $this->assertGreaterThan(0, $novoId);
        $this->assertNotSame($originalId, $novoId);

        $novo = $storage->buscarPedido($novoId);
        $this->assertNotNull($novo);
        $this->assertSame($clienteId, (int) $novo['cliente_id']);
        $this->assertSame('Venda de mercadoria', $novo['natureza_operacao']);
        $this->assertSame(1, (int) $novo['consumidor_final']);
        $this->assertSame('Observação original', $novo['informacoes_adicionais']);
        $this->assertSame('rascunho', $novo['status']);
        $this->assertSame(42, (int) $novo['criado_por']);
        $this->assertNull($novo['nfe_chave']);
        $this->assertNull($novo['nfe_numero']);
        $this->assertNull($novo['nfe_protocolo']);
        $this->assertNull($novo['aprovado_por']);
        $this->assertNull($novo['emitido_em']);

        $itensClonados = $storage->listarItens($novoId);
        $this->assertCount(1, $itensClonados);
        $this->assertSame('Produto Clonável', $itensClonados[0]['descricao']);
        $this->assertSame('P001', $itensClonados[0]['codigo_produto']);
        $this->assertSame('150.00', $itensClonados[0]['valor_unitario']);
    }

    public function testClonarPedidoInexistenteLancaExcecao(): void
    {
        $storage = new \EmissorGyn\NfeStorage(':memory:');
        $this->expectException(\RuntimeException::class);
        $storage->clonarPedido(999, 1);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/CadastroTest.php --filter "testClonarPedido"`
Expected: FAIL — `Call to undefined method EmissorGyn\NfeStorage::clonarPedido()`

- [ ] **Step 3: Implement `clonarPedido()`**

In `src/NfeStorage.php`, add this method immediately after `cancelarPedido()` (which currently ends with the closing `}` right before the `// ─── Itens ───` comment):

```php
    public function clonarPedido(int $pedidoId, int $usuarioId): int
    {
        $original = $this->buscarPedido($pedidoId);
        if ($original === null) {
            throw new \RuntimeException('Pedido não encontrado.');
        }

        $novoId = $this->inserirPedido([
            'cliente_id'             => (int) $original['cliente_id'],
            'natureza_operacao'      => $original['natureza_operacao'],
            'consumidor_final'       => (int) $original['consumidor_final'],
            'presenca'               => (int) $original['presenca'],
            'informacoes_adicionais' => $original['informacoes_adicionais'],
            'criado_por'             => $usuarioId,
        ]);

        $this->substituirItens($novoId, $this->listarItens($pedidoId));

        return $novoId;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/CadastroTest.php --filter "testClonarPedido"`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Run full suite and lint**

Run: `composer test`
Expected: all tests pass (existing count + 2 new).

Run: `composer lint`
Expected: no output (clean).

- [ ] **Step 6: Commit**

```bash
git add src/NfeStorage.php tests/CadastroTest.php
git commit -m "feat(nfe): add NfeStorage::clonarPedido() to duplicate a pedido and its itens"
```

---

### Task 2: Wire "Clonar" into `pedidos/ver.php`

**Files:**
- Modify: `public/pages/pedidos/ver.php`

**Interfaces:**
- Consumes: `NfeStorage::clonarPedido(int $pedidoId, int $usuarioId): int` (Task 1). `$auth->usuarioAtual(): array` and `$auth->csrfToken(): string` / `$auth->validarCsrf(string $token): bool` (existing, already used elsewhere in this file). `$flash` (existing array-or-null convention, rendered by `public/pages/_head.php`).

No automated test — this page has no unit test coverage (verification is `php -l` + `composer lint` + manual click-through, same as the DANFE-logo work earlier in this project).

- [ ] **Step 1: Show a flash message after redirect from a successful clone**

In `public/pages/pedidos/ver.php`, right after the line `$flash = null;` (currently line 40), add:

```php
if (($_GET['clonado'] ?? '') === '1') {
    $flash = ['tipo' => 'success', 'msg' => 'Pedido clonado com sucesso — revise antes de aprovar.'];
}
```

- [ ] **Step 2: Handle the `clonar` POST action**

Inside the existing `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }` block, `$usuario`/`$uid` are already computed once at the top (right after `$acao = (string) ($_POST['acao'] ?? '');`) — reuse them, don't redeclare. Add a new `elseif` branch right after the `aprovar` branch's closing `}`, wrapped in `try/catch` (matching how `emitir`/`cancelar_nfe` already handle `\Throwable` a few branches down in this same file — the `\RuntimeException` from `clonarPedido()` is defensive, for the rare case the pedido was deleted between page load and this POST):

```php
        if ($acao === 'aprovar' && $pedido['status'] === 'rascunho') {
            $nfeStorage->aprovarPedido($id, $uid);
            $pedido = $nfeStorage->buscarPedido($id);
            $flash  = ['tipo' => 'success', 'msg' => 'Pedido aprovado.'];
        } elseif ($acao === 'clonar') {
            try {
                $novoId = $nfeStorage->clonarPedido($id, $uid);
                header('Location: ?p=pedidos/ver&id=' . $novoId . '&clonado=1');
                exit;
            } catch (\RuntimeException $e) {
                $flash = ['tipo' => 'danger', 'msg' => 'Erro ao clonar: ' . $e->getMessage()];
            }
        } elseif (
            $acao === 'cancelar_rascunho'
            && in_array($pedido['status'], ['rascunho', 'aprovado'], true)
        ) {
```

(Everything else in the `if`/`elseif` chain — `cancelar_rascunho`, `emitir`, `cancelar_nfe` — stays exactly as it is today; only the new `clonar` branch is inserted.)

- [ ] **Step 3: Add the "Clonar" button, visible for any status**

In `public/pages/pedidos/ver.php`, find the end of the status-conditional actions block:

```php
    </div>
    <?php endif; ?>
</div>
```

(the `<?php endif; ?>` that closes the `if ($pedido['status'] === 'rascunho') : ... elseif (...) : ... elseif ($pedido['status'] === 'emitido') : ...` chain, right before the closing `</div>` of `<div class="d-flex gap-2 flex-wrap align-items-start">`). Insert the clone form immediately after that `endif` and before the closing `</div>`:

```php
    <?php endif; ?>
    <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <input type="hidden" name="acao" value="clonar">
        <button type="submit" class="btn btn-outline-secondary"
                onclick="return confirm('Clonar este pedido para um novo rascunho?')">
            Clonar
        </button>
    </form>
</div>
```

- [ ] **Step 4: Syntax and lint check**

Run: `php -l public/pages/pedidos/ver.php`
Expected: `No syntax errors detected in public/pages/pedidos/ver.php`

Run: `composer lint`
Expected: no output (clean).

- [ ] **Step 5: Manual verification**

Start the app (`php -S 127.0.0.1:8080 public/web.php`), open any pedido's `ver.php` page (any status), click "Clonar", confirm the dialog. Expected: redirected to the new pedido's `ver.php`, status "Rascunho", green flash message "Pedido clonado com sucesso — revise antes de aprovar.", same cliente/itens as the original.

- [ ] **Step 6: Commit**

```bash
git add public/pages/pedidos/ver.php
git commit -m "feat(nfe): add Clonar button to pedidos/ver.php"
```

---

### Task 3: `Cadastro::clonarOrcamento()`

**Files:**
- Modify: `src/Cadastro.php` (add method after `cancelarOrcamento()`, currently ending around line 507)
- Test: `tests/CadastroTest.php` (add near `testCancelarOrcamento()`)

**Interfaces:**
- Consumes: `Cadastro::buscarOrcamento(int $id): ?array` (existing), `Cadastro::inserirOrcamento(array $dados): int` (existing).
- Produces: `Cadastro::clonarOrcamento(int $orcamentoId, int $usuarioId): int` — returns the new orçamento's id. Throws `\RuntimeException` if `$orcamentoId` doesn't exist.

- [ ] **Step 1: Write the failing test**

Add to `tests/CadastroTest.php`, after `testCancelarOrcamento()`:

```php
    public function testClonarOrcamentoCopiaCamposEResetaCompetenciaEEstado(): void
    {
        $clienteId = $this->db->inserirCliente([
            'razao_social' => 'Cliente Clone Orcamento',
            'cpf_cnpj'     => '55555555000155',
        ]);
        $usuarioId = $this->db->inserirUsuario('Op3', 'op3@lumina.com', 'hash');

        $originalId = $this->db->inserirOrcamento([
            'cliente_id'                  => $clienteId,
            'servico_id'                  => '',
            'competencia'                 => '2020-01-01',
            'valor_servicos'              => '2500.00',
            'item_lista_servico'          => '7.02',
            'codigo_cnae'                 => '4321500',
            'codigo_tributacao_municipio' => '5208707',
            'discriminacao'               => 'Instalação original',
            'aliquota'                    => '2.00',
            'exigibilidade_iss'           => 1,
            'iss_retido'                  => 2,
            'valor_deducoes'              => '0.00',
            'criado_por'                  => $usuarioId,
        ]);
        $this->db->aprovarOrcamento($originalId, $usuarioId);
        $this->db->emitirOrcamento($originalId, 1, 'NFSE-ORIG');

        $novoId = $this->db->clonarOrcamento($originalId, 99);
        $this->assertGreaterThan(0, $novoId);
        $this->assertNotSame($originalId, $novoId);

        $novo = $this->db->buscarOrcamento($novoId);
        $this->assertNotNull($novo);
        $this->assertSame($clienteId, (int) $novo['cliente_id']);
        $this->assertSame('2500.00', $novo['valor_servicos']);
        $this->assertSame('Instalação original', $novo['discriminacao']);
        $this->assertSame('rascunho', $novo['status']);
        $this->assertSame(99, (int) $novo['criado_por']);
        $this->assertSame(date('Y-m-d'), $novo['competencia']);
        $this->assertNull($novo['nfse_numero']);
        $this->assertNull($novo['aprovado_por']);
        $this->assertNull($novo['emitido_em']);
    }

    public function testClonarOrcamentoInexistenteLancaExcecao(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->db->clonarOrcamento(999, 1);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/CadastroTest.php --filter "testClonarOrcamento"`
Expected: FAIL — `Call to undefined method EmissorGyn\Cadastro::clonarOrcamento()`

- [ ] **Step 3: Implement `clonarOrcamento()`**

In `src/Cadastro.php`, add this method immediately after `cancelarOrcamento()`:

```php
    public function clonarOrcamento(int $orcamentoId, int $usuarioId): int
    {
        $original = $this->buscarOrcamento($orcamentoId);
        if ($original === null) {
            throw new \RuntimeException('Orçamento não encontrado.');
        }

        return $this->inserirOrcamento([
            'cliente_id'                  => (int) $original['cliente_id'],
            'servico_id'                  => $original['servico_id'],
            'competencia'                 => date('Y-m-d'),
            'valor_servicos'              => $original['valor_servicos'],
            'item_lista_servico'          => $original['item_lista_servico'],
            'codigo_cnae'                 => $original['codigo_cnae'],
            'codigo_tributacao_municipio' => $original['codigo_tributacao_municipio'],
            'discriminacao'               => $original['discriminacao'],
            'aliquota'                    => $original['aliquota'],
            'exigibilidade_iss'           => (int) $original['exigibilidade_iss'],
            'iss_retido'                  => (int) $original['iss_retido'],
            'valor_deducoes'              => $original['valor_deducoes'],
            'valor_pis'                   => $original['valor_pis'],
            'valor_cofins'                => $original['valor_cofins'],
            'valor_inss'                  => $original['valor_inss'],
            'valor_ir'                    => $original['valor_ir'],
            'valor_csll'                  => $original['valor_csll'],
            'desconto_incondicionado'     => $original['desconto_incondicionado'],
            'desconto_condicionado'       => $original['desconto_condicionado'],
            'criado_por'                  => $usuarioId,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/CadastroTest.php --filter "testClonarOrcamento"`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: Run full suite and lint**

Run: `composer test`
Expected: all tests pass (existing count + 2 new).

Run: `composer lint`
Expected: no output (clean).

- [ ] **Step 6: Commit**

```bash
git add src/Cadastro.php tests/CadastroTest.php
git commit -m "feat(nfse): add Cadastro::clonarOrcamento() to duplicate an orcamento"
```

---

### Task 4: Wire "Clonar" into `orcamentos/ver.php`

**Files:**
- Modify: `public/pages/orcamentos/ver.php`

**Interfaces:**
- Consumes: `Cadastro::clonarOrcamento(int $orcamentoId, int $usuarioId): int` (Task 3). `$auth->usuarioAtual()`, `$auth->csrfToken()`, `$auth->validarCsrf()` (existing, already used in this file). `$flash` (existing convention).

No automated test — same rationale as Task 2 (this is a thin HTTP view, no unit test coverage exists for it in this codebase).

- [ ] **Step 1: Show a flash message after redirect from a successful clone**

In `public/pages/orcamentos/ver.php`, right after the line `$flash   = null;` (currently near line 18, just before `$usuario = $auth->usuarioAtual();`), add:

```php
if (($_GET['clonado'] ?? '') === '1') {
    $flash = ['tipo' => 'success', 'msg' => 'Orçamento clonado com sucesso — revise antes de aprovar.'];
}
```

- [ ] **Step 2: Handle the `clonar` POST action**

In the existing POST block:

```php
        if ($acao === 'aprovar' && $orcamento['status'] === 'rascunho') {
            $cadastro->aprovarOrcamento($id, (int) ($usuario['id'] ?? 0));
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'success', 'msg' => 'Orçamento aprovado.'];
        } elseif (
```

Add a new `elseif` branch right after the `aprovar` branch's closing `}`, wrapped in `try/catch` (same rationale as pedidos/ver.php — `clonarOrcamento()`'s `\RuntimeException` is defensive, for the rare case the orçamento was deleted between page load and this POST):

```php
        if ($acao === 'aprovar' && $orcamento['status'] === 'rascunho') {
            $cadastro->aprovarOrcamento($id, (int) ($usuario['id'] ?? 0));
            $orcamento = $cadastro->buscarOrcamento($id);
            $flash     = ['tipo' => 'success', 'msg' => 'Orçamento aprovado.'];
        } elseif ($acao === 'clonar') {
            try {
                $novoId = $cadastro->clonarOrcamento($id, (int) ($usuario['id'] ?? 0));
                header('Location: ?p=orcamentos/ver&id=' . $novoId . '&clonado=1');
                exit;
            } catch (\RuntimeException $e) {
                $flash = ['tipo' => 'danger', 'msg' => 'Erro ao clonar: ' . $e->getMessage()];
            }
        } elseif (
```

- [ ] **Step 3: Add the "Clonar" button, visible for any status**

In `public/pages/orcamentos/ver.php`, the actions form currently reads:

```php
<div class="mt-4 d-flex gap-2 flex-wrap">
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">

        <?php if ($status === 'rascunho') : ?>
        <a href="?p=orcamentos/form&amp;id=<?= $id ?>"
           class="btn btn-outline-primary">Editar</a>
        <button type="submit" name="acao" value="aprovar"
                class="btn btn-warning">Aprovar</button>
        <button type="submit" name="acao" value="cancelar"
                class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>

        <?php elseif ($status === 'aprovado') : ?>
        <button type="submit" name="acao" value="emitir"
                class="btn btn-success"
                onclick="return confirm('Emitir NFS-e agora? Esta ação não pode ser desfeita.')">
            Emitir NFS-e
        </button>
        <button type="submit" name="acao" value="cancelar"
                class="btn btn-outline-danger"
                onclick="return confirm('Cancelar este orçamento?')">Cancelar</button>
        <?php endif; ?>
    </form>
</div>
```

Add the clone button right after the CSRF hidden input, before the `<?php if ($status === 'rascunho') : ?>` — so it's unconditional (renders for every status, including `emitido`/`cancelado` which currently render nothing in this block):

```php
<div class="mt-4 d-flex gap-2 flex-wrap">
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h($auth->csrfToken()) ?>">
        <button type="submit" name="acao" value="clonar"
                class="btn btn-outline-secondary"
                onclick="return confirm('Clonar este orçamento para um novo rascunho?')">
            Clonar
        </button>

        <?php if ($status === 'rascunho') : ?>
```

(everything from `<?php if ($status === 'rascunho') : ?>` onward stays exactly as-is).

- [ ] **Step 4: Syntax and lint check**

Run: `php -l public/pages/orcamentos/ver.php`
Expected: `No syntax errors detected in public/pages/orcamentos/ver.php`

Run: `composer lint`
Expected: no output (clean).

- [ ] **Step 5: Manual verification**

Open any orçamento's `ver.php` page (any status), click "Clonar", confirm the dialog. Expected: redirected to the new orçamento's `ver.php`, status "Rascunho", green flash message "Orçamento clonado com sucesso — revise antes de aprovar.", same cliente/serviço/valores as the original, competência = data de hoje.

- [ ] **Step 6: Commit**

```bash
git add public/pages/orcamentos/ver.php
git commit -m "feat(nfse): add Clonar button to orcamentos/ver.php"
```
