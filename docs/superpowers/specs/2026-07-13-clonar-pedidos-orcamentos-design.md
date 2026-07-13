# Clonar Pedidos e Orçamentos — Design Spec
**Projeto:** emissor-nfse-goiania (Lumina)
**Data:** 2026-07-13
**Status:** Aprovado

---

## 1. Visão Geral

Adiciona uma ação "Clonar" para pedidos NF-e (`pedidos`) e orçamentos NFS-e (`orcamentos`), permitindo reaproveitar os dados de um registro existente — cliente, itens/serviço, valores — como ponto de partida para um novo, sem redigitar tudo do zero.

Ao clicar em "Clonar", um novo registro é criado imediatamente (status `rascunho`) e a tela de edição do novo registro é aberta em seguida, pronta para ajustes antes de aprovar/emitir. Não existe modo "pré-preencher sem salvar" — o clone sempre gera um registro real no banco.

São duas implementações independentes (uma por módulo), sem abstração compartilhada entre `NfeStorage` e `Cadastro` — os modelos de dados são diferentes o suficiente para que uma interface comum não traria benefício real, só complexidade.

---

## 2. Clonar Pedido (NF-e)

### 2.1 Método

```php
// src/NfeStorage.php
public function clonarPedido(int $pedidoId, int $usuarioId): int
```

Busca o pedido original (`buscarPedido()`) e seus itens (`listarItens()`). Se `buscarPedido()` retornar `null` (pedido inexistente), lança `\RuntimeException('Pedido não encontrado.')` — ver §5 para como o chamador trata isso. Caso contrário, chama `inserirPedido()` com os campos copiados abaixo, depois `substituirItens()` no novo ID com os itens originais (mantendo `numero_item`, código, descrição, NCM, CFOP, unidade, quantidade, valores, CSOSN, CST PIS/COFINS).

### 2.2 Campos

| Campo | Comportamento |
|---|---|
| `cliente_id` | Copiado |
| `natureza_operacao` | Copiado |
| `consumidor_final` | Copiado |
| `presenca` | Copiado |
| `informacoes_adicionais` | Copiado |
| Todos os itens (`pedido_itens`) | Copiados integralmente |
| `status` | Resetado para `'rascunho'` |
| `criado_por` | Resetado para o `$usuarioId` (usuário logado que clonou) |
| `criado_em` | Resetado para agora (automático, via `inserirPedido()`) |
| `nfe_chave`, `nfe_numero`, `nfe_serie`, `nfe_protocolo`, `nfe_xml_autorizado` | `null` |
| `aprovado_por`, `aprovado_em` | `null` |
| `emitido_em` | `null` |
| `cancelado_em` | `null` |

---

## 3. Clonar Orçamento (NFS-e)

### 3.1 Método

```php
// src/Cadastro.php
public function clonarOrcamento(int $orcamentoId, int $usuarioId): int
```

Busca o orçamento original (`buscarOrcamento()` ou equivalente já existente). Se retornar `null`, lança `\RuntimeException('Orçamento não encontrado.')` — mesmo tratamento do pedido, ver §5. Caso contrário, chama `inserirOrcamento()` com os campos copiados abaixo.

### 3.2 Campos

| Campo | Comportamento |
|---|---|
| `cliente_id` | Copiado |
| `servico_id` | Copiado |
| `valor_servicos` | Copiado |
| `item_lista_servico` | Copiado |
| `codigo_cnae` | Copiado |
| `codigo_tributacao_municipio` | Copiado |
| `discriminacao` | Copiado |
| `aliquota` | Copiado |
| `exigibilidade_iss` | Copiado |
| `iss_retido` | Copiado |
| `valor_deducoes`, `valor_pis`, `valor_cofins`, `valor_inss`, `valor_ir`, `valor_csll` | Copiados |
| `desconto_incondicionado`, `desconto_condicionado` | Copiados |
| `competencia` | **Não copiado** — resetado para o mês atual (`date('Y-m')`), pois um serviço clonado presumivelmente será prestado agora, não no mês de competência do original |
| `status` | Resetado para `'rascunho'` |
| `criado_por` | Resetado para o `$usuarioId` |
| `criado_em` | Resetado para agora (automático, via `inserirOrcamento()`) |
| `nfse_numero`, `emissao_id` | `null` |
| `aprovado_por`, `aprovado_em` | `null` |
| `emitido_em` | `null` |

---

## 4. Interface (UI)

Botão **"Clonar"** em `public/pages/pedidos/ver.php` e `public/pages/orcamentos/ver.php`, ao lado dos botões de ação já existentes (Editar/Aprovar/Cancelar/DANFE ou DANFS-e). Visível **em qualquer status** (rascunho, aprovado, emitido, cancelado).

Implementado como `<form method="post">` com campo oculto `acao=clonar` — mesmo padrão dos outros botões de ação nessas páginas (não é um link GET, pois cria um registro novo: é um efeito colateral, não deve ser cacheável/repetível por engano).

O handler:
1. Lê `$auth->usuarioAtual()` (já disponível globalmente na página, mesmo padrão de `form.php`).
2. Chama `clonarPedido()`/`clonarOrcamento()`.
3. Redireciona para `?p=pedidos/ver&id=<novoId>` (ou `?p=orcamentos/ver&id=<novoId>`) com uma mensagem flash: "Pedido clonado com sucesso — revise antes de aprovar." (ou "Orçamento clonado...").

---

## 5. Tratamento de Erro

- ID original inexistente (rota adulterada manualmente): `clonarPedido()`/`clonarOrcamento()` lançam `\RuntimeException`. O handler `acao=clonar` em `ver.php` já tem `$pedido`/`$orcamento` carregado nesse ponto (a página só chega lá se o registro existir — mesmo padrão de `buscarPedido() === null → http_response_code(404)` já usado no topo de `ver.php`), então esse `catch` é defensivo (cobre corrida entre carregar a página e o POST, ex.: o registro foi apagado nesse meio-tempo), não o caminho principal.
- Para pedidos: `inserirPedido()` e `substituirItens()` são chamadas sequenciais, não envolvidas numa transação única cobrindo as duas. `substituirItens()` já abre sua própria transação internamente. Se `inserirPedido()` funcionar mas `substituirItens()` falhar (ex.: erro de banco), o pedido clonado fica criado sem itens — aceitável, pois é um rascunho editável/excluível pelo usuário, não uma emissão real.

---

## 6. Teste

Testes automatizados (PHPUnit):

- `NfeStorage::clonarPedido()`: clona um pedido com itens e confirma que (a) os campos de negócio foram copiados, (b) `status` é `'rascunho'`, (c) `criado_por` é o novo usuário informado, (d) os campos de numeração/emissão/aprovação são `null`, (e) os itens do novo pedido têm os mesmos dados do original.
- `Cadastro::clonarOrcamento()`: clona um orçamento e confirma (a) campos de negócio copiados, (b) `status='rascunho'`, (c) `criado_por` correto, (d) `competencia` é o mês atual (não o do original), (e) campos de numeração/emissão são `null`.

Não há teste de UI automatizado (o projeto não tem suíte de testes de interface — mesma situação já registrada para geração de DANFE). Verificação da UI é manual: clicar em "Clonar" num pedido/orçamento existente e confirmar que a tela de edição do novo registro abre com os dados esperados.

---

## 7. Fora de Escopo

- Botão "Clonar" na listagem (`index.php`) — só na tela de detalhe (`ver.php`).
- Clonar itens/serviço para um cliente diferente automaticamente (o usuário troca o cliente manualmente na edição, se quiser).
- Qualquer abstração/interface compartilhada entre o clone de pedido e o de orçamento.
