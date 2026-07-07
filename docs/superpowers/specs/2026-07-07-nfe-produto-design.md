# NF-e de Produto — Design Spec
**Projeto:** emissor-nfse-goiania (Lumina)
**Data:** 2026-07-07
**Status:** Aprovado

---

## 1. Visão Geral

Módulo independente para emissão de NF-e modelo 55 (nota fiscal de produto) integrado ao sistema existente. Convive com o módulo NFS-e atual sem alterações no fluxo de serviços. Usa `nfephp-org/sped-nfe` para XML e comunicação SEFAZ, e `nfephp-org/sped-da` para geração de DANFE PDF.

**Contexto da Lumina:**
- Regime: Simples Nacional (`OPTANTE_SIMPLES=1`)
- Estado: Goiás (cUF = 52) → SEFAZ Virtual RS (SVRS) para autorização
- Operações: intrastate (CFOP 5.xxx) e interstate (CFOP 6.xxx)
- Clientes: B2B (CNPJ) e B2C (CPF)
- ICMS: CSOSN 400 (sem débito — Simples Nacional). DIFAL não calculado automaticamente.

---

## 2. Modelo de Dados

Três novas tabelas no mesmo `storage/nfse.sqlite`. Criadas via `NfeStorage::migrate()`.

### 2.1 `pedidos`

```sql
CREATE TABLE IF NOT EXISTS pedidos (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id            INTEGER NOT NULL REFERENCES clientes(id),
    status                TEXT    NOT NULL DEFAULT 'rascunho',
    natureza_operacao     TEXT    NOT NULL DEFAULT 'Venda de mercadoria',
    consumidor_final      INTEGER NOT NULL DEFAULT 0,
    presenca              INTEGER NOT NULL DEFAULT 1,
    informacoes_adicionais TEXT,
    nfe_chave             TEXT,
    nfe_numero            INTEGER,
    nfe_serie             TEXT,
    nfe_protocolo         TEXT,
    nfe_xml_autorizado    TEXT,
    criado_por            INTEGER REFERENCES usuarios(id),
    aprovado_por          INTEGER REFERENCES usuarios(id),
    criado_em             TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    aprovado_em           TEXT,
    emitido_em            TEXT,
    cancelado_em          TEXT
);
CREATE INDEX IF NOT EXISTS idx_pedidos_cliente ON pedidos (cliente_id);
CREATE INDEX IF NOT EXISTS idx_pedidos_status  ON pedidos (status);
CREATE INDEX IF NOT EXISTS idx_pedidos_chave   ON pedidos (nfe_chave);
```

**Valores válidos de `status`:** `rascunho | aprovado | emitido | cancelado`

**`presenca`:** 1=presencial, 2=operação não presencial internet, 3=operação não presencial teleatendimento, 4=NFC-e entrega domiciliar, 9=operação não presencial outros.

### 2.2 `pedido_itens`

```sql
CREATE TABLE IF NOT EXISTS pedido_itens (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id                 INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    numero_item               INTEGER NOT NULL,
    codigo_produto            TEXT,
    descricao                 TEXT    NOT NULL,
    ncm                       TEXT    NOT NULL,
    cfop                      TEXT    NOT NULL,
    unidade                   TEXT    NOT NULL DEFAULT 'UN',
    quantidade                TEXT    NOT NULL,
    valor_unitario            TEXT    NOT NULL,
    valor_desconto            TEXT,
    csosn                     TEXT    NOT NULL DEFAULT '400',
    pis_cst                   TEXT    NOT NULL DEFAULT '07',
    cofins_cst                TEXT    NOT NULL DEFAULT '07',
    informacoes_adicionais_item TEXT
);
CREATE INDEX IF NOT EXISTS idx_pedido_itens_pedido ON pedido_itens (pedido_id);
```

`valor_total` é calculado em tempo real (`quantidade × valor_unitario − valor_desconto`) e não é armazenado.

### 2.3 `pedido_eventos`

```sql
CREATE TABLE IF NOT EXISTS pedido_eventos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id     INTEGER NOT NULL REFERENCES pedidos(id),
    tipo          TEXT    NOT NULL,
    protocolo     TEXT,
    descricao     TEXT,
    xml_evento    TEXT,
    xml_retorno   TEXT,
    criado_em     TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
```

**`tipo`:** `cancelamento` (únicos tipo implementado nesta versão).

### 2.4 Numeração NF-e

Reutiliza a tabela `rps_sequencia` existente com chave `nfe:{serie}` (ex.: `nfe:1`). Garante atomicidade via transação — mesmo mecanismo já usado pelo módulo NFS-e.

---

## 3. Estrutura de Arquivos

```
src/
  NfeXmlFactory.php    ← monta XML NF-e via NFePHP\NFe\Make
  NfeClient.php        ← assina e comunica com SEFAZ via NFePHP\NFe\Tools
  NfeStorage.php       ← persistência: pedidos, itens, eventos, numeração

public/pages/pedidos/
  index.php            ← listagem com filtro por status e busca por cliente
  form.php             ← cabeçalho + tabela dinâmica de itens (JS vanilla)
  ver.php              ← detalhe + workflow + DANFE PDF + cancelamento

Modificados:
  composer.json                  ← + sped-nfe, + sped-da
  .env.example                   ← + NFE_AMBIENTE, + NFE_SERIE
  public/web.php                 ← + rotas pedidos, pedidos/form, pedidos/ver
  public/pages/dashboard.php     ← + cartões e tabela de pedidos
  src/Cadastro.php               ← estatisticas() inclui pedidos por status
  .github/workflows/ci.yml       ← + ext-soap nas extensões PHP
  tests/CadastroTest.php         ← testes de NfeStorage (in-memory)
```

---

## 4. Classes

### 4.1 `NfeStorage`

```php
new NfeStorage(string $dbPath)

// Numeração
NfeStorage::proximoNfe(string $serie): int                   // atômico via rps_sequencia
NfeStorage::definirUltimoNfe(string $serie, int $ultimo): void // rollback se SEFAZ recusar

// Pedidos
NfeStorage::listarPedidos(string $status, string $busca): array
NfeStorage::buscarPedido(int $id): ?array           // join com clientes e itens
NfeStorage::inserirPedido(array $dados): int
NfeStorage::atualizarPedido(int $id, array $dados): void
NfeStorage::aprovarPedido(int $id, int $usuarioId): void
NfeStorage::emitirPedido(int $id, string $chave, int $numero, string $serie,
                          string $protocolo, string $xmlAutorizado): void
NfeStorage::cancelarPedido(int $id): void

// Itens
NfeStorage::listarItens(int $pedidoId): array
NfeStorage::substituirItens(int $pedidoId, array $itens): void  // delete + insert

// Eventos
NfeStorage::registrarEvento(int $pedidoId, string $tipo, string $protocolo,
                              string $descricao, string $xmlEvento,
                              string $xmlRetorno): void

// Estatísticas
NfeStorage::estatisticas(): array  // ['rascunho'=>N, 'aprovado'=>N, 'emitido'=>N, ...]
```

### 4.2 `NfeXmlFactory`

`Config` é a classe existente `EmissorGyn\Config` que carrega variáveis do `.env` (CERT_PATH, CERT_PASS, PRESTADOR_CNPJ, NFE_AMBIENTE, NFE_SERIE, etc.).

```php
new NfeXmlFactory(Config $config)

NfeXmlFactory::build(array $pedido, array $itens, array $cliente,
                      int $nNF, string $serie, string $cNF): string
// Retorna XML não assinado do NF-e.
// Internamente chama NFePHP\NFe\Make:
//   tagide(), tagemit(), tagdest(), tagenderDest(),
//   tagdet() × N, tagprod(), tagICMS() (CSOSN 400),
//   tagPIS() (CST 07), tagCOFINS() (CST 07),
//   tagICMSTot(), tagtransp(), taginfAdic()
```

**ICMS (CSOSN 400 — Simples Nacional sem débito):**
- Grupo `<ICMSSN400>` com `<orig>0</orig><CSOSN>400</CSOSN>`
- Sem cálculo de alíquota, base de cálculo ou valor de ICMS
- Adequado para todas as operações de saída no Simples Nacional sem substituição tributária

**PIS / COFINS (CST 07 — operação isenta):**
- `<PISOutr>` e `<COFINSOutr>` com CST 07 (operação isenta de contribuição)
- Sem valor de PIS/COFINS calculado

**Chave de acesso (44 dígitos):**
- Gerada automaticamente pelo `NFePHP\NFe\Make` com base em cUF + AAMM + CNPJ + mod + série + nNF + tpEmis + cNF
- `cNF`: 8 dígitos aleatórios (gerados na chamada de `build()`)

### 4.3 `NfeClient`

```php
new NfeClient(Config $config, NfeXmlFactory $factory)

// Configura NFePHP\NFe\Tools com:
//   cnpj, certificado (base64 do .pfx), senha, tpAmb (NFE_AMBIENTE)
//   cUF = 52 (Goiás → SVRS automaticamente)

NfeClient::emitir(array $pedido, array $itens, array $cliente,
                   int $nNF, string $serie): array
// Retorna: ['chave'=>string, 'numero'=>int, 'protocolo'=>string, 'xml_autorizado'=>string]
// Fluxo interno:
//   1. NfeXmlFactory::build()
//   2. Tools->signNFe($xml)
//   3. Tools->sefazEnviaLote([$signed], $idLote, indSinc=1)
//   4. Parse cStat 100 → Complements::toAuthorize($xml, $response)
//   5. Retorna dados da autorização
// Lança \RuntimeException em caso de rejeição SEFAZ (cStat ≠ 100)

NfeClient::cancelar(string $chave, string $xJust, string $nProt): string
// Retorna protocolo do cancelamento
// Tools->sefazCancela($chave, $xJust, $nProt)
// xJust: mínimo 15 caracteres
```

---

## 5. Fluxo de Emissão

```
[Criar pedido]
  → escolhe cliente
  → preenche cabeçalho
  → adiciona itens (JS: + linha, cálculo de totais em tempo real)
  → salva como rascunho

[rascunho] ──Aprovar──► [aprovado] ──Emitir NF-e──► [emitido]
    │                       │
    └──Cancelar──►      Cancelar──►  [cancelado]

[emitido] ──Cancelar NF-e (c/ justificativa)──► [cancelado]
```

### Emissão (POST `acao=emitir` em `pedidos/ver.php`)

1. `NfeStorage::proximoNfe($serie)` — reserva número
2. `NfeStorage::listarItens($id)` — carrega itens
3. `NfeClient::emitir(...)` — XML → assinatura → SEFAZ
4. **Sucesso:** `NfeStorage::emitirPedido(...)` — grava chave, protocolo, XML autorizado
5. **Falha:** reverte número via `NfeStorage::definirUltimoNfe($serie, $nNF - 1)` → exibe erro; status permanece `aprovado` (pode tentar novamente)

### Cancelamento (POST `acao=cancelar_nfe` em `pedidos/ver.php`)

1. Valida: `strlen($xJust) >= 15`
2. `NfeClient::cancelar($chave, $xJust, $nProt)`
3. `NfeStorage::registrarEvento(...)` — persiste evento
4. `NfeStorage::cancelarPedido($id)` — status = `cancelado`

### DANFE (GET `acao=danfe` em `pedidos/ver.php`)

```php
$xml  = $storage->buscarPedido($id)['nfe_xml_autorizado'];
$pdf  = new NFePHP\DA\NFe\Danfe($xml);
$pdf->monta();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="nfe-' . $chave . '.pdf"');
echo $pdf->render();
exit;
```

DANFE gerado sob demanda do XML armazenado — nenhum arquivo PDF em disco.

---

## 6. Interface Web

### `pedidos/index.php`

- Filtro por status (select) + busca por cliente (texto)
- Tabela: #ID | Cliente | Valor Total | Itens | Status | Ver
- Valor total calculado via SQL: `SUM(quantidade × valor_unitario − COALESCE(valor_desconto, 0))`

### `pedidos/form.php`

**Cabeçalho:**
- Cliente (select dos clientes ativos)
- Natureza da operação (input text, default "Venda de mercadoria")
- Presença (select)
- Consumidor final (checkbox — flag informativa)
- Informações adicionais (textarea)

**Tabela de itens (JS vanilla, sem framework):**
- Colunas: # | Descrição | NCM | CFOP | Unidade | Qtd | Valor Unit. | Desconto | Total | ✕
- Linha colapsável por item com campos fiscais: Cód. Produto, CSOSN, PIS CST, COFINS CST
- Botão "＋ Adicionar Item" no rodapé da tabela
- Total geral calculado em tempo real
- POST serializa itens como `itens[0][descricao]`, `itens[0][ncm]`, etc.

**Validações server-side:**
- Cliente obrigatório
- Pelo menos 1 item
- NCM: 8 dígitos numéricos
- CFOP: 4 dígitos (5xxx ou 6xxx)
- Quantidade e valor unitário > 0

### `pedidos/ver.php`

- Card cliente (razão social, CPF/CNPJ, endereço)
- Card cabeçalho (natureza, presença, consumidor final, informações adicionais)
- Tabela de itens com total por linha e total geral
- Após emissão: chave de acesso formatada (grupos de 4 dígitos) e protocolo
- Botões por status:
  - **rascunho:** [Editar] [Aprovar] [Cancelar]
  - **aprovado:** [Emitir NF-e] [Cancelar]
  - **emitido:** [⬇ DANFE PDF] [Cancelar NF-e ▼] (expande campo justificativa)
  - **cancelado:** histórico de eventos

---

## 7. Configuração (`.env`)

```dotenv
# NF-e de produto
NFE_AMBIENTE=2     # 2=homologação (padrão seguro), 1=produção
NFE_SERIE=1        # série da NF-e (normalmente 1)
```

O certificado (`CERT_PATH`, `CERT_PASS`) e CNPJ (`PRESTADOR_CNPJ`) são compartilhados com o módulo NFS-e — nenhuma configuração nova de certificado.

---

## 8. CI e Dependências

**`composer.json`** — novas dependências de produção:
```json
"nfephp-org/sped-nfe": "^5.2",
"nfephp-org/sped-da":  "^1.2"
```

**`.github/workflows/ci.yml`** — adicionar `soap` à lista de extensões:
```yaml
extensions: curl, dom, json, libxml, openssl, pdo, pdo_sqlite, simplexml, soap
```

**VPS** — instalar extensão soap:
```bash
sudo apt install php8.2-soap
```

**Syntax check no CI** — incluir `public/pages/pedidos/` (já coberto pelo `find src/ public/`).

---

## 9. Segurança

Segue os mesmos padrões do módulo NFS-e existente:
- CSRF token em todos os POSTs
- `$auth->guard()` em todas as páginas
- `h()` em todo output HTML
- Validação server-side antes de qualquer chamada SEFAZ
- XML autorizado armazenado no SQLite (não exposto via HTTP)
- DANFE servido inline sem revelar caminho de arquivo

---

## 10. Fora do Escopo

| Feature | Motivo |
|---|---|
| NFC-e modelo 65 | Cupom fiscal — fluxo e requisitos diferentes |
| Cálculo automático de DIFAL | Decisão contábil — fica com o contador |
| Carta de Correção Eletrônica (CC-e) | Pode ser adicionada numa fase 2 |
| Contingência (FS-DA / EPEC / SVC) | YAGNI — adicionar se SEFAZ ficar instável |
| Inutilização de numeração | Raro — executar manualmente via API sped-nfe se necessário |
| NF-e complementar / devolução | Fase 2 se necessário |
| IPI, ICMS-ST | Não aplicável ao perfil da Lumina |
