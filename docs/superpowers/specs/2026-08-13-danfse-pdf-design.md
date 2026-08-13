# Geração Local de DANFS-e em PDF — Design Spec
**Projeto:** emissor-nfse-goiania (Lumina)
**Data:** 2026-08-13
**Status:** Aprovado

---

## 1. Visão Geral

Hoje, `orcamentos/ver.php` oferece um botão "Abrir DANFS-e Oficial" que chama `NfseClient::consultarUrlNfse()` — uma operação SOAP proprietária do ISSNet (`ConsultarUrlNfse`), não documentada publicamente, e que se mostrou quebrada nesta instalação: toda tentativa retorna `[E160] Arquivo em desacordo com o XML Schema`, mesmo depois de testar múltiplas variações plausíveis de estrutura contra o servidor de produção. Não existe layout de DANFS-e padronizado nacionalmente (diferente do DANFE de NF-e) para reaproveitar de uma lib — cada prefeitura publica o seu.

Esta mudança elimina a dependência desse endpoint quebrado: gera o PDF **localmente**, a partir do XML de retorno (`InfNfse`) que a SGISS já devolve na emissão e que já fica salvo em `emissoes.xml_retorno`. O layout é modelado a partir de um DANFS-e real, oficial, baixado do portal ISSNet (`https://www.issnetonline.com.br/goiania/online/`) para a mesma nota (RPS 1, código de verificação `FD3563DD9`) — não é uma cópia pixel-perfect certificada pela prefeitura, é um comprovante próprio no mesmo formato visual.

**Duas lacunas conhecidas e aceitas** (decididas com o usuário durante o brainstorming):
- **QR codes**: omitidos. O modelo real tem QR codes perto do cabeçalho e do código de autenticidade, mas não há como saber com segurança qual URL o ISSNet codifica ali sem documentação — um QR com link errado é pior que não ter QR. O código de verificação e o link do portal continuam presentes em texto.
- **Chave de acesso no Ambiente de Dados Nacional** (linha vermelha de 50 dígitos no rodapé do modelo): omitida. Esse dado não existe em nenhum lugar da resposta do webservice `abrasf204/goiania/nfse.asmx` que este projeto consome — não temos como reproduzi-la corretamente.

---

## 2. Nova Dependência

```bash
composer require dompdf/dompdf
```

Pure-PHP, sem binário externo — mesmo perfil de deploy do resto do projeto. Justificativa sobre a alternativa (reaproveitar o FPDF já vendorado via `nfephp-org/sped-da`): o modelo é essencialmente uma grade de caixas com borda e uma tabela de tributos com colunas estreitas — HTML/CSS com `<table border>` mapeia isso naturalmente e é muito mais barato de ajustar depois (o layout foi reconstruído por engenharia reversa de um PDF, não é uma especificação fechada — vai precisar de retoques). Cell-by-cell em FPDF exigiria matemática manual de posição para cada campo.

`isRemoteEnabled` fica `false` (padrão do Dompdf) — nenhuma imagem é carregada por URL; a logo (quando configurada) é embutida como `data:` URI a partir do arquivo local, mesmo padrão de resolução de caminho que `LOGO_PATH` já usa para o DANFE de NF-e (`docs/superpowers/specs/2026-07-08-logo-danfe-design.md`).

---

## 3. Novos Componentes

### 3.1 `src/Danfse.php`

Lógica pura de extração e formatação — sem dependência do Dompdf, totalmente testável.

```php
final class Danfse
{
    /** @return array<string,mixed> */
    public static function extrair(string $xmlRetorno): array { ... }

    /** Renderiza o template com os dados já extraídos. */
    public static function renderizar(array $dados, string $logoPath = ''): string { ... }
}
```

`extrair()` faz parse do `InfNfse` (`DOMDocument`, mesmo padrão de `ResponseParser`) e devolve um array já com todos os valores formatados para exibição (não valores crus) — datas em `dd/mm/aaaa`, CPF/CNPJ/CEP/telefone com máscara, valores em `R$ x.xxx,xx`, município resolvido via `Municipios`. Lança `\InvalidArgumentException` se o XML não tiver `<InfNfse>` (nota não encontrada/mal formada).

`renderizar()` inclui `src/templates/danfse.php` com `$d` (o array de `extrair()`) em escopo, capturado via output buffering, e devolve a string HTML.

### 3.2 `src/templates/danfse.php`

Template HTML/CSS puro, sem lógica de negócio — usa `h()` (já global, definida em `public/web.php`) para escape. Reproduz as caixas do modelo de referência: cabeçalho (texto, sem brasão — ver §6), Dados do Prestador de Serviço, Identificação da NFS-e, Dados do Tomador de Serviços, Dados do Intermediário (só renderiza a caixa se `$d['intermediario']` não for vazio), Descrição dos Serviços, Detalhamento dos Tributos, Informações Adicionais.

### 3.3 `src/Municipios.php`

```php
final class Municipios
{
    /** "Belo Horizonte - Minas Gerais" ou o código cru se não encontrado. */
    public static function nomeUf(string $codigoIbge): string { ... }
}
```

Carrega `data/municipios_ibge.json` uma vez (variável estática), lookup O(1) por código. Fallback: se o código não existir na tabela, devolve o próprio código (não lança exceção — é campo de exibição, não deve derrubar a geração do PDF por um código não catalogado).

### 3.4 `data/municipios_ibge.json`

Tabela oficial do IBGE, todos os ~5.570 municípios brasileiros:

```json
{
  "5208707": {"nome": "Goiânia", "uf": "GO"},
  "3106200": {"nome": "Belo Horizonte", "uf": "MG"}
}
```

Fonte: API pública do IBGE (`servicodados.ibge.gov.br/api/v1/localidades/municipios`) — domínio público, dado de referência oficial do governo federal. Gerada uma vez durante a implementação (script auxiliar descartável, não faz parte do runtime da aplicação).

### 3.5 `src/Storage.php` — novo método

```php
/** @return array<string,mixed>|null */
public function buscarEmissao(int $id): ?array
{
    $stmt = $this->pdo->prepare('SELECT * FROM emissoes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}
```

Não existe hoje um jeito de buscar uma emissão específica por id (só `listar()`, que nem seleciona `xml_retorno`). Necessário para ligar `orcamento.emissao_id` → `emissoes.xml_retorno`.

---

## 4. Mapeamento de Campos (InfNfse → PDF)

Ponto central da spec — a maior parte é mapeamento direto, mas há três regras derivadas não óbvias.

| Campo no PDF | Origem no XML (dentro de `InfNfse`) |
|---|---|
| Número da Nota Fiscal | `Numero` |
| Data de Geração da NFS-e | `DataEmissao` |
| Cód. de Autenticidade | `CodigoVerificacao` |
| Data de Competência | `DeclaracaoPrestacaoServico/InfDeclaracaoPrestacaoServico/Competencia` |
| Razão Social / Nome Fantasia / Endereço / Contato do prestador | `PrestadorServico/*` |
| **CNPJ / Inscrição Municipal do prestador** | `DeclaracaoPrestacaoServico/InfDeclaracaoPrestacaoServico/Prestador/*` — **não** está em `PrestadorServico` (esse bloco não carrega `CpfCnpj`/`InscricaoMunicipal` na resposta real, apesar do nome sugerir isso) |
| Natureza da Operação | derivado de `Servico/ExigibilidadeISS` (1=Exigível...7=Exig. suspensa — mesma tabela de `orcamentos/form.php`'s `$exigOpcoes`) |
| Número/Série do RPS, Data de Emissão do RPS | `.../Rps/IdentificacaoRps/*`, `.../Rps/DataEmissao` |
| Local dos Serviços | `Municipios::nomeUf(Servico/CodigoMunicipio)` |
| Município Incidência | `Municipios::nomeUf(Servico/MunicipioIncidencia)` |
| Dados do Tomador (todos os campos) | `.../TomadorServico/*` |
| Dados do Intermediário | `.../Intermediario/*` (nunca enviado por `XmlFactory` hoje — bloco fica vazio) |
| Descrição dos Serviços | `Servico/Discriminacao` |
| Atividade do Município (célula longa) | `CodigoTributacaoMunicipio` + `" - "` + `DescricaoCodigoTributacaoMunicípio` (ambos em `InfNfse`, calculados pela própria SGISS — nosso `XmlFactory` nem sempre envia `CodigoTributacaoMunicipio`, a prefeitura preenche sozinha) |
| Item da LC116/2003 | `Servico/ItemListaServico` |
| Cód. CNAE / Cód. NBS | `Servico/CodigoCnae` / `Servico/CodigoNbs` (NBS fica em branco — não implementado em `XmlFactory`) |
| Vl. Total dos Serviços, Desconto Incondicionado, Deduções | `Servico/Valores/ValorServicos`, `DescontoIncondicionado`, `ValorDeducoes` |
| Base de Cálculo | `ValoresNfse/BaseCalculo` |
| PIS/COFINS/INSS/IRRF/CSLL/Outras Retenções | `Servico/Valores/ValorPis`, `ValorCofins`, `ValorInss`, `ValorIr`, `ValorCsll`, `OutrasRetencoes` |
| Desconto Condicionado | `Servico/Valores/DescontoCondicionado` |
| ISSQN Retido (Sim/Não) | `Servico/IssRetido` (1→Sim, 2→Não) |
| Vl. Líquido da Nota Fiscal | `ValoresNfse/ValorLiquidoNfse` |
| Informações Adicionais | `OutrasInformacoes` |

**Regra derivada 1 — Responsável pela Retenção**: `IssRetido=2` → campo em branco (não há retenção). `IssRetido=1` → `ResponsavelRetencao=1` → "Tomador"; `ResponsavelRetencao=2` → "Intermediário".

**Regra derivada 2 — Total do ISSQN vs. Vl. ISSQN Retido**: o modelo real mostra `ValorIss` (744.18 no XML) na coluna **"Vl. ISSQN Retido"**, com **"Total do ISSQN" zerado**, porque a nota tinha `IssRetido=1`. Regra: se `IssRetido=1`, `ValorIss` vai para "Vl. ISSQN Retido" e "Total do ISSQN" mostra `R$ 0,00`; se `IssRetido=2`, é o oposto (`ValorIss` vai para "Total do ISSQN", "Vl. ISSQN Retido" mostra `R$ 0,00`).

**Nota — não presumir estrutura sem checar**: os dois primeiros mapeamentos desta tabela que pareciam óbvios (CNPJ do prestador em `PrestadorServico`, chave ADN no rodapé) estavam errados até conferir o XML real. Os campos desta tabela foram todos verificados contra `storage/xml/20260812-191307-rps1-retorno.xml` — não presumir mais nenhum campo por analogia com o modelo visual sem confirmar no XML durante a implementação.

---

## 5. Fluxo / Wiring

`orcamentos/ver.php` já tem um bloco `if (($_GET['acao'] ?? '') === 'danfse' && ...)` nas linhas 25-44, executado **antes** de `_head.php` (padrão idêntico ao early-path de `pedidos/ver.php`). Trocamos o corpo desse bloco — a URL/nome da ação continuam `?acao=danfse`, só a implementação muda:

```php
if (($_GET['acao'] ?? '') === 'danfse' && $orcamento['status'] === 'emitido') {
    $storage = new Storage($config->path('DB_PATH', 'storage/nfse.sqlite'));
    $emissao = !empty($orcamento['emissao_id'])
        ? $storage->buscarEmissao((int) $orcamento['emissao_id'])
        : null;

    if ($emissao !== null && !empty($emissao['xml_retorno'])) {
        try {
            $dados    = Danfse::extrair((string) $emissao['xml_retorno']);
            $logoPath = $config->path('LOGO_PATH', '');
            $html     = Danfse::renderizar($dados, is_file($logoPath) ? $logoPath : '');

            $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="nfse-' . $dados['numero'] . '.pdf"');
            echo $dompdf->output();
            exit;
        } catch (\Throwable $e) {
            $flash = ['tipo' => 'danger', 'msg' => 'Erro ao gerar DANFS-e: ' . $e->getMessage()];
        }
    } else {
        $flash = ['tipo' => 'warning', 'msg' => 'XML de retorno da NFS-e não encontrado para este orçamento.'];
    }
}
```

Se cair no `else`/`catch`, o fluxo segue normalmente para o resto da página (mesmo padrão do bloco atual) — não é um `exit` incondicional, só quando o PDF é efetivamente gerado.

**Botão** (linhas 252-260): mesma URL (`?acao=danfse`), rótulo ajustado de "Abrir DANFS-e Oficial" para **"Baixar DANFS-e (PDF)"** — evita dar a entender que é o documento oficial pixel-a-pixel gerado pela prefeitura.

**Imports novos em `orcamentos/ver.php`**: `use EmissorGyn\Danfse;` (junto aos `use` já existentes — `Storage` já está importado).

---

## 6. Fora de Escopo

- **Brasão da Prefeitura de Goiânia e logo "Nota Fiscal Eletrônica"**: só texto no cabeçalho (ex.: "Prefeitura Municipal de Goiânia - GO — Secretaria Municipal da Fazenda"), sem os elementos gráficos do modelo — não temos os arquivos de imagem oficiais e não é o foco funcional do documento.
- **QR codes** — decidido: omitidos (ver §1).
- **Chave de acesso no Ambiente de Dados Nacional** — decidido: omitida, dado não disponível (ver §1).
- **Geração em lote / múltiplas notas de uma vez** — só a nota de um orçamento por vez, mesmo padrão do DANFE de NF-e.
- **Cache do PDF gerado** — gerado sob demanda a cada clique, mesmo padrão do DANFE de NF-e (não grava em disco, só stream).

---

## 7. Tratamento de Erro

- Orçamento não `emitido`, sem `emissao_id`, ou `emissoes.xml_retorno` vazio/nulo → aviso (`flash warning`), sem tentar gerar.
- `Danfse::extrair()` lança `\InvalidArgumentException` se o XML não tiver `<InfNfse>` → capturado, `flash danger` com a mensagem.
- Código de município fora da tabela do IBGE → `Municipios::nomeUf()` devolve o código cru (não lança, não quebra a geração).
- Falha do Dompdf ao renderizar (ex.: HTML malformado por bug futuro no template) → propaga como `\Throwable` genérico, capturado pelo mesmo `catch` acima.

---

## 8. Teste

`tests/DanfseTest.php`:
1. `Danfse::extrair()` contra o `xml_retorno` real já salvo em `storage/xml/20260812-191307-rps1-retorno.xml` (fixture copiada para `tests/fixtures/`) — assert nos campos mapeados da tabela do §4, incluindo as três regras derivadas (retenção, total-ISSQN-vs-retido, natureza da operação).
2. `Danfse::extrair()` com IssRetido=2 (nota fictícia sem retenção) — assert que a regra derivada 2 inverte corretamente.
3. `Danfse::extrair()` com XML sem `<InfNfse>` → assert `\InvalidArgumentException`.
4. `Municipios::nomeUf()` com código conhecido (Goiânia, Belo Horizonte) e código inexistente (fallback pro código cru).

Sem asserção sobre bytes de PDF (não é signal útil) — a verificação de layout é manual: gerar o PDF da nota real (RPS 1) e comparar visualmente com o modelo de referência.
