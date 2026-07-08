# Logo da Empresa no DANFE — Design Spec
**Projeto:** emissor-nfse-goiania (Lumina)
**Data:** 2026-07-08
**Status:** Aprovado

---

## 1. Visão Geral

Hoje a DANFE (representação impressa da NF-e) é gerada em `public/pages/pedidos/ver.php:15-16` via `NFePHP\DA\NFe\Danfe($xml)->monta()`, sem logotipo. A biblioteca `sped-da` já suporta logo através do parâmetro de `monta($logo)`, que aceita um caminho de arquivo PNG ou JPG (validação e conversão são feitas internamente por `DaCommon::adjustImage()`).

Esta mudança adiciona uma chave de configuração opcional `LOGO_PATH` no `.env` e passa esse caminho para `monta()`. Sem a chave configurada, o comportamento é idêntico ao atual (sem logo) — mudança pequena e retrocompatível, sem UI nova.

---

## 2. Configuração

Nova chave opcional em `.env` e `.env.example`, na seção "Dados do emitente para NF-e":

```
# Logo da empresa no DANFE (opcional; PNG ou JPG). Deixe vazio para gerar sem logo.
LOGO_PATH=
```

Resolução de caminho segue o padrão já usado por `XML_DIR` (`Config::path()`): se `LOGO_PATH` não começar com `/`, é resolvido relativo à raiz do projeto.

---

## 3. Mudança de Código

Em `public/pages/pedidos/ver.php`, no bloco de geração da DANFE:

```php
$xml      = (string) $danfePedido['nfe_xml_autorizado'];
$danfe    = new NFePHP\DA\NFe\Danfe($xml);
$logoPath = $config->path('LOGO_PATH', '');
$danfe->monta(is_file($logoPath) ? $logoPath : '');
```

`$config` já está disponível nesse escopo (herdado via `require` a partir de `public/web.php:22`).

**Nota:** `Config::path('LOGO_PATH', '')` não retorna string vazia quando a chave não existe — resolve o default `''` contra `baseDir`, retornando algo como `/opt/.../emissor-nfse-goiania/` (um diretório). É por isso que o `is_file($logoPath)` é indispensável: `is_file()` numa string de diretório retorna `false`, então o fallback para "sem logo" funciona corretamente mesmo nesse caso. Não simplificar removendo o `is_file()`.

Alinhamento do logo (esquerda/centro/direita) permanece no padrão da biblioteca (`logoAlign = 'C'`, centralizado) — não é exposto como configuração, pois não há necessidade identificada de customizá-lo.

---

## 4. Tratamento de Erro

- `LOGO_PATH` vazio, ou chave ausente do `.env`, ou arquivo inexistente → `monta('')`, comportamento idêntico ao atual (DANFE sem logo). Não é um erro.
- Arquivo existe mas não é PNG/JPG → `DaCommon::adjustImage()` lança `\Exception` com mensagem clara ("O formato da imagem não é aceitável! Somente PNG ou JPG podem ser usados."). Deixamos propagar: é erro de configuração a ser corrigido pelo usuário, não algo para mascarar silenciosamente.

---

## 5. Teste

Não há suíte automatizada cobrindo geração de DANFE (é renderização de PDF via biblioteca externa). Verificação será manual:

1. Configurar `LOGO_PATH` apontando para um PNG de teste, gerar a DANFE de um pedido emitido e confirmar visualmente que a logo aparece centralizada no cabeçalho.
2. Sem `LOGO_PATH` configurado (ou removendo a chave), gerar a DANFE novamente e confirmar que o layout permanece idêntico ao atual (sem logo, sem erro).
3. Apontar `LOGO_PATH` para um arquivo de formato inválido (ex.: `.gif` ou `.pdf`) e confirmar que a exceção da biblioteca é exibida de forma compreensível (não um erro fatal sem contexto).

---

## 6. Fora de Escopo

- Upload de logo pela interface web (descartado — configuração via `.env` é suficiente para o caso de uso atual).
- Configuração de alinhamento/tamanho do logo.
- Logo em outros documentos (ex.: RPS/NFS-e, que usa layout próprio do SGISS sem suporte a logo).
