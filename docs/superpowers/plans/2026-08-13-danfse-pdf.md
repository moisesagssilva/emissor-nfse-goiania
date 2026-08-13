# DANFS-e PDF Local — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken `ConsultarUrlNfse` remote call with a locally-generated DANFS-e PDF, built from the `InfNfse` XML already stored in `emissoes.xml_retorno`.

**Architecture:** A pure-logic class (`Danfse::extrair()`) parses the stored `InfNfse` XML into a display-ready array (masked/formatted values, derived business rules). A plain PHP/HTML template renders that array. `Dompdf` converts the rendered HTML to PDF bytes, streamed inline from `orcamentos/ver.php`'s existing early-path GET handler.

**Tech Stack:** PHP 8.2+, `dompdf/dompdf` (new dependency), `DOMDocument` (already used elsewhere in the codebase), PHPUnit.

## Global Constraints

- Namespace for all new PHP classes: `EmissorGyn` (PSR-4 root `src/`). Tests: `EmissorGynTest` (PSR-4 root `tests/`).
- `declare(strict_types=1);` at the top of every new PHP file — matches every existing file in `src/`.
- No QR codes anywhere in the generated PDF (decided in spec §1 — cannot verify the real ISSNet QR payload format).
- No "Chave de acesso no Ambiente de Dados Nacional" field anywhere in the generated PDF (decided in spec §1 — this data does not exist anywhere in the SGISS response this project consumes).
- Do not commit real customer data, certificates, or signatures into test fixtures — `storage/xml/*` (where real SGISS responses land) is already gitignored; test fixtures must use synthetic data in the same style as `examples/nota.json` (e.g. "Cliente Exemplo LTDA", CNPJ `11222333000181`).
- Follow the spec's field mapping table exactly (`docs/superpowers/specs/2026-08-13-danfse-pdf-design.md` §4) — several fields live in non-obvious places in the XML (e.g. the prestador's CNPJ is in `DeclaracaoPrestacaoServico/InfDeclaracaoPrestacaoServico/Prestador`, not in the top-level `PrestadorServico` block).

---

### Task 1: Add the Dompdf dependency

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (auto-updated by Composer, do not hand-edit)

**Interfaces:**
- Produces: `Dompdf\Dompdf` class available via Composer autoload, consumed by Task 7.

- [ ] **Step 1: Install the dependency**

Run: `cd /opt/Lumina/emissor-nfse-goiania && composer require dompdf/dompdf`

Expected: Composer resolves and installs `dompdf/dompdf` (pulls in `phenx/php-font-lib` and `phenx/php-svg-lib` as transitive deps), updates `composer.json`'s `require` block and `composer.lock`.

- [ ] **Step 2: Verify autoload**

Run: `php -r "require 'vendor/autoload.php'; var_dump(class_exists('Dompdf\\Dompdf'));"`
Expected: `bool(true)`

- [ ] **Step 3: Run the existing test suite to confirm nothing broke**

Run: `vendor/bin/phpunit`
Expected: Same pass/fail counts as before this change (32 tests, 1 pre-existing unrelated failure in `NfeClientTest::testBuildToolsConfigJsonPassesSefazSchemaValidation` — an OpenSSL/CSR environment issue, not caused by this change).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build(nfse): add dompdf/dompdf for local DANFS-e PDF rendering"
```

---

### Task 2: Generate the IBGE municipality lookup table

**Files:**
- Create: `data/municipios_ibge.json`

**Interfaces:**
- Produces: a JSON file at `data/municipios_ibge.json` shaped as `{"<codigo_ibge_7_digitos>": {"nome": "<cidade>", "uf": "<sigla_2_letras>", "uf_nome": "<estado_por_extenso>"}, ...}`, consumed by Task 3 (`Municipios`).

- [ ] **Step 1: Download the source data (public domain, IBGE-derived) from GitHub**

```bash
mkdir -p /opt/Lumina/emissor-nfse-goiania/data
gh api "repos/kelvins/municipios-brasileiros/git/blobs/52a8263869c066654af12d97f1489c793831b2b2" --jq '.content' | base64 -d > /tmp/municipios_ibge_raw.json
gh api "repos/kelvins/municipios-brasileiros/contents/json/estados.json" --jq '.content' | base64 -d > /tmp/estados_ibge_raw.json
```

Expected: `/tmp/municipios_ibge_raw.json` is ~1.5MB with 5571 entries; `/tmp/estados_ibge_raw.json` is ~3.5KB with 27 entries. Verify with:

```bash
php -r '
$raw = preg_replace("/^\xEF\xBB\xBF/", "", file_get_contents("/tmp/municipios_ibge_raw.json"));
echo count(json_decode($raw, true)) . " municipios\n";
$raw2 = preg_replace("/^\xEF\xBB\xBF/", "", file_get_contents("/tmp/estados_ibge_raw.json"));
echo count(json_decode($raw2, true)) . " estados\n";
'
```

Expected output: `5571 municipios` and `27 estados`.

- [ ] **Step 2: Merge into the final format**

Both source files start with a UTF-8 BOM (`\xEF\xBB\xBF`) that must be stripped before `json_decode`, or decoding silently returns `null`.

```bash
php -r '
$raw1 = preg_replace("/^\xEF\xBB\xBF/", "", file_get_contents("/tmp/municipios_ibge_raw.json"));
$municipios = json_decode($raw1, true);

$raw2 = preg_replace("/^\xEF\xBB\xBF/", "", file_get_contents("/tmp/estados_ibge_raw.json"));
$estados = json_decode($raw2, true);

$ufPorCodigo = [];
foreach ($estados as $e) {
    $ufPorCodigo[(int) $e["codigo_uf"]] = ["sigla" => $e["uf"], "nome" => $e["nome"]];
}

$out = [];
foreach ($municipios as $m) {
    $codigo = (string) $m["codigo_ibge"];
    $estado = $ufPorCodigo[(int) $m["codigo_uf"]] ?? ["sigla" => "", "nome" => ""];
    $out[$codigo] = [
        "nome"    => $m["nome"],
        "uf"      => $estado["sigla"],
        "uf_nome" => $estado["nome"],
    ];
}
ksort($out);
file_put_contents(
    "/opt/Lumina/emissor-nfse-goiania/data/municipios_ibge.json",
    json_encode($out, JSON_UNESCAPED_UNICODE)
);
echo "Escrito: " . count($out) . " municipios\n";
'
```

Expected output: `Escrito: 5571 municipios`

- [ ] **Step 3: Verify the two codes this project actually uses resolve correctly**

```bash
php -r '
$dados = json_decode(file_get_contents("/opt/Lumina/emissor-nfse-goiania/data/municipios_ibge.json"), true);
print_r($dados["5208707"]);
print_r($dados["3106200"]);
'
```

Expected:
```
Array
(
    [nome] => Goiânia
    [uf] => GO
    [uf_nome] => Goiás
)
Array
(
    [nome] => Belo Horizonte
    [uf] => MG
    [uf_nome] => Minas Gerais
)
```

- [ ] **Step 4: Commit**

```bash
cd /opt/Lumina/emissor-nfse-goiania
git add data/municipios_ibge.json
git commit -m "feat(nfse): bundle IBGE municipality code lookup table for DANFS-e PDF"
```

---

### Task 3: `src/Municipios.php` — IBGE code lookup helper

**Files:**
- Create: `src/Municipios.php`
- Test: `tests/MunicipiosTest.php`

**Interfaces:**
- Consumes: `data/municipios_ibge.json` (Task 2).
- Produces: `Municipios::nome(string $codigo): string` and `Municipios::cidadeEstado(string $codigo): string`, both consumed by Task 5 (`Danfse::extrair()`).

- [ ] **Step 1: Write the failing test**

Create `tests/MunicipiosTest.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Municipios;
use PHPUnit\Framework\TestCase;

final class MunicipiosTest extends TestCase
{
    public function testNomeGoiania(): void
    {
        $this->assertSame('Goiânia', Municipios::nome('5208707'));
    }

    public function testNomeBeloHorizonte(): void
    {
        $this->assertSame('Belo Horizonte', Municipios::nome('3106200'));
    }

    public function testCidadeEstadoBeloHorizonte(): void
    {
        $this->assertSame('Belo Horizonte - Minas Gerais', Municipios::cidadeEstado('3106200'));
    }

    public function testCodigoDesconhecidoDevolveOProprioCodigo(): void
    {
        $this->assertSame('0000000', Municipios::nome('0000000'));
        $this->assertSame('0000000', Municipios::cidadeEstado('0000000'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/MunicipiosTest.php`
Expected: FAIL — `Class "EmissorGyn\Municipios" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Municipios.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGyn;

/** Consulta de nome de município a partir do código IBGE (data/municipios_ibge.json). */
final class Municipios
{
    /** @var array<string,array{nome:string,uf:string,uf_nome:string}>|null */
    private static ?array $tabela = null;

    /** @return array{nome:string,uf:string,uf_nome:string}|null */
    private static function buscar(string $codigo): ?array
    {
        if (self::$tabela === null) {
            $arquivo = __DIR__ . '/../data/municipios_ibge.json';
            self::$tabela = json_decode((string) file_get_contents($arquivo), true) ?? [];
        }
        return self::$tabela[$codigo] ?? null;
    }

    /** "Goiânia", ou o próprio código se não encontrado na tabela. */
    public static function nome(string $codigo): string
    {
        return self::buscar($codigo)['nome'] ?? $codigo;
    }

    /** "Belo Horizonte - Minas Gerais", ou o próprio código se não encontrado. */
    public static function cidadeEstado(string $codigo): string
    {
        $m = self::buscar($codigo);
        return $m !== null ? $m['nome'] . ' - ' . $m['uf_nome'] : $codigo;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/MunicipiosTest.php`
Expected: PASS (4 tests, 4 assertions)

- [ ] **Step 5: Commit**

```bash
git add src/Municipios.php tests/MunicipiosTest.php
git commit -m "feat(nfse): add Municipios IBGE code lookup helper"
```

---

### Task 4: `Storage::buscarEmissao()`

**Files:**
- Modify: `src/Storage.php`
- Test: `tests/StorageTest.php` (new file)

**Interfaces:**
- Produces: `Storage::buscarEmissao(int $id): ?array`, consumed by Task 7 (wiring in `orcamentos/ver.php`).

- [ ] **Step 1: Write the failing test**

Create `tests/StorageTest.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private Storage $storage;

    protected function setUp(): void
    {
        $this->storage = new Storage(':memory:');
    }

    public function testBuscarEmissaoInexistenteDevolveNull(): void
    {
        $this->assertNull($this->storage->buscarEmissao(999));
    }

    public function testBuscarEmissaoDevolveXmlRetorno(): void
    {
        $id = $this->storage->registrarEnvio(1, '1', '100.00', '11222333000181', 'Cliente Exemplo', '<xml/>');
        $this->storage->registrarSucesso($id, '1', 'ABC123', '<InfNfse>...</InfNfse>');

        $emissao = $this->storage->buscarEmissao($id);

        $this->assertNotNull($emissao);
        $this->assertSame('<InfNfse>...</InfNfse>', $emissao['xml_retorno']);
        $this->assertSame('autorizada', $emissao['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/StorageTest.php`
Expected: FAIL — `Call to undefined method EmissorGyn\Storage::buscarEmissao()`

- [ ] **Step 3: Implement `buscarEmissao()`**

In `src/Storage.php`, add this method right after `registrarErro()` (currently ends at line 121, per the version at the start of this plan — confirm exact line with `grep -n "function registrarErro" src/Storage.php` before editing if the file has changed):

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

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/StorageTest.php`
Expected: PASS (2 tests, 5 assertions)

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: Same result as Task 1 Step 3, plus the new `MunicipiosTest` and `StorageTest` passing.

- [ ] **Step 6: Commit**

```bash
git add src/Storage.php tests/StorageTest.php
git commit -m "feat(nfse): add Storage::buscarEmissao() to fetch a single emissão by id"
```

---

### Task 5: `src/Danfse.php` — extract and format NFS-e data

This is the core of the feature — the field mapping from spec §4, including the two derived business rules. Build the synthetic fixture first (no real customer/certificate data — `storage/xml/*` is gitignored specifically because real responses contain that).

**Files:**
- Create: `tests/fixtures/nfse-retorno.xml`
- Create: `src/Danfse.php`
- Test: `tests/DanfseTest.php`

**Interfaces:**
- Consumes: `Municipios::nome()`, `Municipios::cidadeEstado()` (Task 3).
- Produces: `Danfse::extrair(string $xmlRetorno): array` — the exact array shape is defined in Step 3 below and is consumed as-is by Task 6 (`Danfse::renderizar()` / the HTML template).

- [ ] **Step 1: Create the synthetic fixture**

Create `tests/fixtures/nfse-retorno.xml` (structure matches a real `GerarNfseResposta`, values are fictional — same style as `examples/nota.json`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <ListaNfse>
    <CompNfse>
      <Nfse versao="2.04">
        <InfNfse>
          <Numero>1</Numero>
          <CodigoVerificacao>ABC123XYZ</CodigoVerificacao>
          <DataEmissao>2026-08-12T16:13:07</DataEmissao>
          <OutrasInformacoes>I - "DOCUMENTO EMITIDO POR ME OU EPP OPTANTE PELO SIMPLES NACIONAL"; e II - "NAO GERA DIREITO A CREDITO FISCAL DE IPI."</OutrasInformacoes>
          <ValoresNfse>
            <BaseCalculo>37209.00</BaseCalculo>
            <ValorLiquidoNfse>36464.82</ValorLiquidoNfse>
          </ValoresNfse>
          <CodigoTributacaoMunicipio>702</CodigoTributacaoMunicipio>
          <DescricaoCodigoTributacaoMunicípio>07.02 - Execucao, por administracao, empreitada ou subempreitada, de obras de construcao civil.</DescricaoCodigoTributacaoMunicípio>
          <ValorCredito>0</ValorCredito>
          <PrestadorServico>
            <RazaoSocial>Empresa Exemplo Ltda</RazaoSocial>
            <NomeFantasia>Exemplo Solar</NomeFantasia>
            <Endereco>
              <Endereco>Avenida Portugal</Endereco>
              <Numero>1148</Numero>
              <Complemento>Sala C2501</Complemento>
              <Bairro>Setor Marista</Bairro>
              <CodigoMunicipio>5208707</CodigoMunicipio>
              <Uf>GO</Uf>
              <Cep>74150030</Cep>
            </Endereco>
            <Contato>
              <Telefone>62981274500</Telefone>
              <Email>contato@exemplosolar.com.br</Email>
            </Contato>
          </PrestadorServico>
          <OrgaoGerador>
            <CodigoMunicipio>5208707</CodigoMunicipio>
            <Uf>GO</Uf>
          </OrgaoGerador>
          <DeclaracaoPrestacaoServico>
            <InfDeclaracaoPrestacaoServico Id="rps1s1">
              <Rps>
                <IdentificacaoRps>
                  <Numero>1</Numero>
                  <Serie>1</Serie>
                  <Tipo>1</Tipo>
                </IdentificacaoRps>
                <DataEmissao>2026-08-12</DataEmissao>
                <Status>1</Status>
              </Rps>
              <Competencia>2026-08-12</Competencia>
              <Servico>
                <Valores>
                  <ValorServicos>37209.00</ValorServicos>
                  <ValorIss>744.18</ValorIss>
                  <Aliquota>2.00</Aliquota>
                </Valores>
                <IssRetido>1</IssRetido>
                <ResponsavelRetencao>1</ResponsavelRetencao>
                <ItemListaServico>07.02</ItemListaServico>
                <CodigoCnae>4321500</CodigoCnae>
                <CodigoTributacaoMunicipio>702</CodigoTributacaoMunicipio>
                <Discriminacao>Elaboracao de projeto eletrico, instalacao da expansao fotovoltaica.</Discriminacao>
                <CodigoMunicipio>3106200</CodigoMunicipio>
                <ExigibilidadeISS>1</ExigibilidadeISS>
                <MunicipioIncidencia>3106200</MunicipioIncidencia>
              </Servico>
              <Prestador>
                <CpfCnpj>
                  <Cnpj>11222333000181</Cnpj>
                </CpfCnpj>
                <InscricaoMunicipal>123456</InscricaoMunicipal>
              </Prestador>
              <TomadorServico>
                <IdentificacaoTomador>
                  <CpfCnpj>
                    <Cnpj>17452871000149</Cnpj>
                  </CpfCnpj>
                </IdentificacaoTomador>
                <RazaoSocial>Cliente Exemplo LTDA</RazaoSocial>
                <Endereco>
                  <Endereco>R Exemplo</Endereco>
                  <Numero>138</Numero>
                  <Bairro>Vila Paris</Bairro>
                  <CodigoMunicipio>3106200</CodigoMunicipio>
                  <Uf>MG</Uf>
                  <Cep>30380780</Cep>
                </Endereco>
                <Contato>
                  <Telefone>31996135712</Telefone>
                  <Email>cliente@exemplo.com.br</Email>
                </Contato>
              </TomadorServico>
              <OptanteSimplesNacional>1</OptanteSimplesNacional>
              <IncentivoFiscal>2</IncentivoFiscal>
            </InfDeclaracaoPrestacaoServico>
          </DeclaracaoPrestacaoServico>
        </InfNfse>
      </Nfse>
    </CompNfse>
  </ListaNfse>
</GerarNfseResposta>
```

- [ ] **Step 2: Write the failing tests**

Create `tests/DanfseTest.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGynTest;

use EmissorGyn\Danfse;
use PHPUnit\Framework\TestCase;

final class DanfseTest extends TestCase
{
    private string $xmlComRetencao;

    protected function setUp(): void
    {
        $this->xmlComRetencao = (string) file_get_contents(__DIR__ . '/fixtures/nfse-retorno.xml');
    }

    public function testCamposBasicos(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('1', $d['numero']);
        $this->assertSame('ABC123XYZ', $d['codigo_verificacao']);
        $this->assertSame('12/08/2026 16:13:07', $d['data_emissao']);
        $this->assertSame('12/08/2026', $d['competencia']);
        $this->assertSame('1', $d['rps_numero']);
        $this->assertSame('1', $d['rps_serie']);
        $this->assertSame('12/08/2026', $d['rps_data_emissao']);
        $this->assertSame('Belo Horizonte - Minas Gerais', $d['local_servicos']);
        $this->assertSame('Belo Horizonte - Minas Gerais', $d['municipio_incidencia']);
        $this->assertSame([], $d['intermediario']);
    }

    public function testDadosDoPrestador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('Empresa Exemplo Ltda', $d['prestador']['razao_social']);
        $this->assertSame('Exemplo Solar', $d['prestador']['nome_fantasia']);
        $this->assertSame('11.222.333/0001-81', $d['prestador']['cnpj']);
        $this->assertSame('123456', $d['prestador']['inscricao_municipal']);
        $this->assertSame('Avenida Portugal,1148 Sala C2501 - Setor Marista', $d['prestador']['endereco']);
        $this->assertSame('74150-030', $d['prestador']['cep']);
        $this->assertSame('Goiânia/ GO', $d['prestador']['municipio_uf']);
        $this->assertSame('(62)98127-4500', $d['prestador']['telefone']);
        $this->assertSame('contato@exemplosolar.com.br', $d['prestador']['email']);
    }

    public function testDadosDoTomador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('17.452.871/0001-49', $d['tomador']['cnpj_cpf']);
        $this->assertSame('Cliente Exemplo LTDA', $d['tomador']['razao_social']);
        $this->assertSame('R Exemplo', $d['tomador']['endereco']);
        $this->assertSame('138', $d['tomador']['numero']);
        $this->assertSame('', $d['tomador']['complemento']);
        $this->assertSame('Vila Paris', $d['tomador']['bairro']);
        $this->assertSame('Belo Horizonte/ MG', $d['tomador']['cidade_uf']);
        $this->assertSame('30380-780', $d['tomador']['cep']);
        $this->assertSame('(31)99613-5712', $d['tomador']['telefone']);
        $this->assertSame('cliente@exemplo.com.br', $d['tomador']['email']);
    }

    public function testServicoEValores(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame(
            'Elaboracao de projeto eletrico, instalacao da expansao fotovoltaica.',
            $d['discriminacao']
        );
        $this->assertSame(
            '702 - 07.02 - Execucao, por administracao, empreitada ou subempreitada, de obras de construcao civil.',
            $d['atividade_municipio']
        );
        $this->assertSame('2,00', $d['aliquota']);
        $this->assertSame('07.02', $d['item_lista_servico']);
        $this->assertSame('', $d['codigo_nbs']);
        $this->assertSame('4321500', $d['codigo_cnae']);
        $this->assertSame('R$ 37.209,00', $d['valor_total_servicos']);
        $this->assertSame('R$ 0,00', $d['desconto_incondicionado']);
        $this->assertSame('R$ 0,00', $d['deducoes_base_calculo']);
        $this->assertSame('R$ 37.209,00', $d['base_calculo']);
        $this->assertSame('R$ 0,00', $d['desconto_condicionado']);
        $this->assertSame('R$ 0,00', $d['pis']);
        $this->assertSame('R$ 36.464,82', $d['valor_liquido_nota']);
    }

    public function testRegraRetencaoIssRetidoPeloTomador(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);

        $this->assertSame('Sim', $d['issqn_retido']);
        $this->assertSame('Exigível', $d['natureza_operacao']);
        $this->assertSame('Tomador', $d['responsavel_retencao']);
        $this->assertSame('R$ 0,00', $d['total_issqn']);
        $this->assertSame('R$ 744,18', $d['valor_issqn_retido']);
    }

    public function testRegraRetencaoIssNaoRetido(): void
    {
        $xmlSemRetencao = str_replace(
            ['<IssRetido>1</IssRetido>', '<ResponsavelRetencao>1</ResponsavelRetencao>'],
            ['<IssRetido>2</IssRetido>', ''],
            $this->xmlComRetencao
        );

        $d = Danfse::extrair($xmlSemRetencao);

        $this->assertSame('Não', $d['issqn_retido']);
        $this->assertSame('', $d['responsavel_retencao']);
        $this->assertSame('R$ 744,18', $d['total_issqn']);
        $this->assertSame('R$ 0,00', $d['valor_issqn_retido']);
    }

    public function testXmlSemInfNfseLancaExcecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Danfse::extrair('<foo></foo>');
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/DanfseTest.php`
Expected: FAIL — `Class "EmissorGyn\Danfse" not found`

- [ ] **Step 4: Write the implementation**

Create `src/Danfse.php`:

```php
<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Extrai e formata os dados de uma NFS-e (InfNfse, resposta do GerarNfse)
 * para exibição em PDF. Ver docs/superpowers/specs/2026-08-13-danfse-pdf-design.md §4
 * para o mapeamento completo de campos.
 */
final class Danfse
{
    private const NATUREZA_OPERACAO = [
        '1' => 'Exigível',
        '2' => 'Não incidência',
        '3' => 'Isenção',
        '4' => 'Exportação',
        '5' => 'Imunidade',
        '6' => 'Exigibilidade suspensa por decisão judicial',
        '7' => 'Exigibilidade suspensa por processo administrativo',
    ];

    /** @return array<string,mixed> */
    public static function extrair(string $xmlRetorno): array
    {
        $dom = new \DOMDocument();
        $ok = @$dom->loadXML($xmlRetorno, LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
        $infNfse = $ok ? self::el($dom, 'InfNfse') : null;
        if ($infNfse === null) {
            throw new \InvalidArgumentException(
                'XML de retorno não contém InfNfse — nota não encontrada ou XML mal formado.'
            );
        }

        $prestadorServico = self::el($infNfse, 'PrestadorServico');
        $enderecoPrestador = self::el($prestadorServico, 'Endereco');
        $contatoPrestador = self::el($prestadorServico, 'Contato');

        $declaracao = self::el($infNfse, 'InfDeclaracaoPrestacaoServico');
        $rps = self::el($declaracao, 'Rps');
        $identRps = self::el($rps, 'IdentificacaoRps');
        $servico = self::el($declaracao, 'Servico');
        $valores = self::el($servico, 'Valores');
        $prestador = self::el($declaracao, 'Prestador');
        $tomador = self::el($declaracao, 'TomadorServico');
        $enderecoTomador = self::el($tomador, 'Endereco');
        $contatoTomador = self::el($tomador, 'Contato');
        $intermediarioNo = self::el($declaracao, 'Intermediario');
        $valoresNfse = self::el($infNfse, 'ValoresNfse');

        $issRetido = self::txt($servico, 'IssRetido');
        $responsavelRetencao = self::txt($servico, 'ResponsavelRetencao');
        $valorIss = self::txt($valores, 'ValorIss');

        if ($issRetido === '1') {
            $responsavelRetencaoTexto = $responsavelRetencao === '2' ? 'Intermediário' : 'Tomador';
            $totalIssqn = self::moeda('0');
            $valorIssqnRetido = self::moeda($valorIss);
        } else {
            $responsavelRetencaoTexto = '';
            $totalIssqn = self::moeda($valorIss);
            $valorIssqnRetido = self::moeda('0');
        }

        $codigoMunicipioServico = self::txt($servico, 'CodigoMunicipio');
        $municipioIncidencia = self::txt($servico, 'MunicipioIncidencia');

        return [
            'numero' => self::txt($infNfse, 'Numero'),
            'codigo_verificacao' => self::txt($infNfse, 'CodigoVerificacao'),
            'data_emissao' => self::dataHora(self::txt($infNfse, 'DataEmissao')),
            'competencia' => self::data(self::txt($declaracao, 'Competencia')),
            'informacoes_adicionais' => self::txt($infNfse, 'OutrasInformacoes'),

            'rps_numero' => self::txt($identRps, 'Numero'),
            'rps_serie' => self::txt($identRps, 'Serie'),
            'rps_data_emissao' => self::data(self::txt($rps, 'DataEmissao')),

            'local_servicos' => $codigoMunicipioServico !== '' ? Municipios::cidadeEstado($codigoMunicipioServico) : '',
            'municipio_incidencia' => $municipioIncidencia !== '' ? Municipios::cidadeEstado($municipioIncidencia) : '',

            'prestador' => [
                'razao_social' => self::txt($prestadorServico, 'RazaoSocial'),
                'nome_fantasia' => self::txt($prestadorServico, 'NomeFantasia'),
                'cnpj' => self::mascaraCnpjCpf(self::txt($prestador, 'Cnpj')),
                'inscricao_municipal' => self::txt($prestador, 'InscricaoMunicipal'),
                'endereco' => self::enderecoConcatenado($enderecoPrestador),
                'cep' => self::mascaraCep(self::txt($enderecoPrestador, 'Cep')),
                'municipio_uf' => self::cidadeUf($enderecoPrestador),
                'telefone' => self::mascaraTelefone(self::txt($contatoPrestador, 'Telefone')),
                'email' => self::txt($contatoPrestador, 'Email'),
            ],

            'tomador' => [
                'cnpj_cpf' => self::mascaraCnpjCpf(
                    self::txt($tomador, 'Cnpj') !== '' ? self::txt($tomador, 'Cnpj') : self::txt($tomador, 'Cpf')
                ),
                'inscricao_municipal' => self::txt($tomador, 'InscricaoMunicipal'),
                'razao_social' => self::txt($tomador, 'RazaoSocial'),
                'endereco' => self::txt($enderecoTomador, 'Endereco'),
                'numero' => self::txt($enderecoTomador, 'Numero'),
                'complemento' => self::txt($enderecoTomador, 'Complemento'),
                'bairro' => self::txt($enderecoTomador, 'Bairro'),
                'cidade_uf' => self::cidadeUf($enderecoTomador),
                'cep' => self::mascaraCep(self::txt($enderecoTomador, 'Cep')),
                'telefone' => self::mascaraTelefone(self::txt($contatoTomador, 'Telefone')),
                'email' => self::txt($contatoTomador, 'Email'),
            ],

            'intermediario' => $intermediarioNo !== null ? [
                'cnpj_cpf' => self::mascaraCnpjCpf(
                    self::txt($intermediarioNo, 'Cnpj') !== ''
                        ? self::txt($intermediarioNo, 'Cnpj')
                        : self::txt($intermediarioNo, 'Cpf')
                ),
                'inscricao_municipal' => self::txt($intermediarioNo, 'InscricaoMunicipal'),
                'razao_social' => self::txt($intermediarioNo, 'RazaoSocial'),
            ] : [],

            'discriminacao' => self::txt($servico, 'Discriminacao'),
            'atividade_municipio' => trim(
                self::txt($infNfse, 'CodigoTributacaoMunicipio') . ' - '
                . self::txt($infNfse, 'DescricaoCodigoTributacaoMunicípio'),
                ' -'
            ),
            'aliquota' => self::moedaSemPrefixo(self::txt($valores, 'Aliquota')),
            'item_lista_servico' => self::txt($servico, 'ItemListaServico'),
            'codigo_nbs' => self::txt($servico, 'CodigoNbs'),
            'codigo_cnae' => self::txt($servico, 'CodigoCnae'),

            'valor_total_servicos' => self::moeda(self::txt($valores, 'ValorServicos')),
            'desconto_incondicionado' => self::moeda(self::txt($valores, 'DescontoIncondicionado')),
            'deducoes_base_calculo' => self::moeda(self::txt($valores, 'ValorDeducoes')),
            'base_calculo' => self::moeda(self::txt($valoresNfse, 'BaseCalculo')),
            'desconto_condicionado' => self::moeda(self::txt($valores, 'DescontoCondicionado')),

            'pis' => self::moeda(self::txt($valores, 'ValorPis')),
            'cofins' => self::moeda(self::txt($valores, 'ValorCofins')),
            'inss' => self::moeda(self::txt($valores, 'ValorInss')),
            'irrf' => self::moeda(self::txt($valores, 'ValorIr')),
            'csll' => self::moeda(self::txt($valores, 'ValorCsll')),
            'outras_retencoes' => self::moeda(self::txt($valores, 'OutrasRetencoes')),

            'valor_liquido_nota' => self::moeda(self::txt($valoresNfse, 'ValorLiquidoNfse')),

            'issqn_retido' => $issRetido === '1' ? 'Sim' : 'Não',
            'natureza_operacao' => self::NATUREZA_OPERACAO[self::txt($servico, 'ExigibilidadeISS')] ?? '',
            'responsavel_retencao' => $responsavelRetencaoTexto,
            'total_issqn' => $totalIssqn,
            'valor_issqn_retido' => $valorIssqnRetido,
        ];
    }

    /** Renderiza o template com os dados já extraídos e devolve o HTML como string. */
    public static function renderizar(array $dados, string $logoPath = ''): string
    {
        $d = $dados;
        $logoDataUri = '';
        if ($logoPath !== '' && is_file($logoPath)) {
            $tipo = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
            $logoDataUri = 'data:' . $tipo . ';base64,' . base64_encode((string) file_get_contents($logoPath));
        }

        ob_start();
        require __DIR__ . '/templates/danfse.php';
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------ utils

    private static function el(\DOMDocument|\DOMElement|null $ctx, string $tag): ?\DOMElement
    {
        if ($ctx === null) {
            return null;
        }
        $node = $ctx->getElementsByTagName($tag)->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    private static function txt(\DOMDocument|\DOMElement|null $ctx, string $tag): string
    {
        return trim(self::el($ctx, $tag)?->textContent ?? '');
    }

    private static function moeda(string $valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    private static function moedaSemPrefixo(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    private static function data(string $isoData): string
    {
        if ($isoData === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', substr($isoData, 0, 10));
        return $dt !== false ? $dt->format('d/m/Y') : '';
    }

    private static function dataHora(string $isoDataHora): string
    {
        if ($isoDataHora === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $isoDataHora);
        if ($dt === false) {
            $dt = \DateTime::createFromFormat('Y-m-d', substr($isoDataHora, 0, 10));
        }
        return $dt !== false ? $dt->format('d/m/Y H:i:s') : '';
    }

    private static function mascaraCnpjCpf(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        if (strlen($digitos) === 14) {
            return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3)
                . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
        }
        if (strlen($digitos) === 11) {
            return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3)
                . '-' . substr($digitos, 9, 2);
        }
        return $digitos;
    }

    private static function mascaraCep(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        return strlen($digitos) === 8 ? substr($digitos, 0, 5) . '-' . substr($digitos, 5, 3) : $digitos;
    }

    private static function mascaraTelefone(string $digitos): string
    {
        $digitos = preg_replace('/\D/', '', $digitos) ?? '';
        $tamanho = strlen($digitos);
        if ($tamanho === 11) {
            return '(' . substr($digitos, 0, 2) . ')' . substr($digitos, 2, 5) . '-' . substr($digitos, 7, 4);
        }
        if ($tamanho === 10) {
            return '(' . substr($digitos, 0, 2) . ')' . substr($digitos, 2, 4) . '-' . substr($digitos, 6, 4);
        }
        return $digitos;
    }

    private static function enderecoConcatenado(?\DOMElement $endereco): string
    {
        if ($endereco === null) {
            return '';
        }
        $linha1 = self::txt($endereco, 'Endereco') . ',' . self::txt($endereco, 'Numero');
        $sufixo = trim(self::txt($endereco, 'Complemento') . ' - ' . self::txt($endereco, 'Bairro'), ' -');
        return trim($linha1 . ' ' . $sufixo);
    }

    private static function cidadeUf(?\DOMElement $endereco): string
    {
        if ($endereco === null) {
            return '';
        }
        $nome = Municipios::nome(self::txt($endereco, 'CodigoMunicipio'));
        return $nome . '/ ' . self::txt($endereco, 'Uf');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/DanfseTest.php`
Expected: PASS (7 tests)

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: Same as Task 4 Step 5, plus `DanfseTest` passing.

- [ ] **Step 7: Commit**

```bash
git add tests/fixtures/nfse-retorno.xml src/Danfse.php tests/DanfseTest.php
git commit -m "feat(nfse): add Danfse::extrair() — parse InfNfse XML into display-ready data"
```

---

### Task 6: `src/templates/danfse.php` — HTML template

**Files:**
- Create: `src/templates/danfse.php`
- Test: `tests/DanfseTest.php` (add a test to the existing file)

**Interfaces:**
- Consumes: the array shape produced by `Danfse::extrair()` (Task 5), available in scope as `$d`; `$logoDataUri` (string, possibly empty) from `Danfse::renderizar()`.
- Produces: HTML string via `Danfse::renderizar()`, consumed by Task 7 (Dompdf).

- [ ] **Step 1: Write the failing test**

Add to `tests/DanfseTest.php` (inside the existing `DanfseTest` class):

```php
    public function testRenderizarProduzHtmlComOsDadosDaNota(): void
    {
        $d = Danfse::extrair($this->xmlComRetencao);
        $html = Danfse::renderizar($d);

        $this->assertStringContainsString('Empresa Exemplo Ltda', $html);
        $this->assertStringContainsString('Cliente Exemplo LTDA', $html);
        $this->assertStringContainsString('R$ 37.209,00', $html);
        $this->assertStringContainsString('R$ 36.464,82', $html);
        $this->assertStringContainsString('ABC123XYZ', $html);
        $this->assertStringContainsString('Belo Horizonte - Minas Gerais', $html);
        // QR code e chave ADN nunca devem aparecer — decisão do spec §1.
        $this->assertStringNotContainsString('qrcode', strtolower($html));
        $this->assertStringNotContainsString('Ambiente de Dados Nacional', $html);
    }

    public function testRenderizarEscapaHtmlNosDados(): void
    {
        $xmlComTagNoNome = str_replace(
            'Cliente Exemplo LTDA',
            'Cliente <script>alert(1)</script>',
            $this->xmlComRetencao
        );
        $d = Danfse::extrair($xmlComTagNoNome);
        $html = Danfse::renderizar($d);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/DanfseTest.php`
Expected: FAIL — `require(...src/templates/danfse.php): Failed to open stream`

- [ ] **Step 3: Write the template**

Create `src/templates/danfse.php`:

```php
<?php
/**
 * Template do DANFS-e (comprovante próprio, não é o layout oficial pixel-a-pixel
 * do ISSNet). Recebe $d (array de Danfse::extrair()) e $logoDataUri em escopo.
 * Sem lógica de negócio aqui — só apresentação.
 */
declare(strict_types=1);

/** @var array<string,mixed> $d */
/** @var string $logoDataUri */

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    .caixa { border: 1px solid #000; margin-bottom: 4px; }
    .titulo { background: #e5e5e5; font-weight: bold; padding: 3px 5px; font-size: 10px; }
    .conteudo { padding: 5px; }
    .cabecalho-topo td { vertical-align: top; }
    .cabecalho-topo .prefeitura { font-size: 12px; font-weight: bold; }
    .cabecalho-topo .doc-info { text-align: right; font-size: 9px; }
    .campo-label { font-size: 7px; color: #444; text-transform: uppercase; }
    .campo-valor { font-size: 9px; margin-bottom: 3px; }
    .tabela-tributos td, .tabela-tributos th {
        border: 1px solid #000; padding: 2px 4px; font-size: 8px; text-align: left;
    }
    .logo { max-height: 50px; max-width: 90px; }
    .destaque { font-weight: bold; }
</style>
</head>
<body>

<table class="cabecalho-topo caixa">
    <tr>
        <td class="conteudo" style="width: 60%;">
            <div class="prefeitura">Prefeitura Municipal de Goiânia - GO</div>
            <div>Secretaria Municipal da Fazenda</div>
            <div>Fone: (62) 35243335 - https://www.goiania.go.gov.br/</div>
        </td>
        <td class="conteudo doc-info">
            <div>Série do Documento</div>
            <div class="destaque">Nota Fiscal de Serviço Eletrônica - NFS-e</div>
            <div>Número da Nota Fiscal</div>
            <div class="destaque"><?= $h($d['numero']) ?></div>
        </td>
    </tr>
</table>

<div class="caixa">
    <div class="titulo">Dados do Prestador de Serviço</div>
    <table class="conteudo">
        <tr>
            <td style="width: 15%;">
                <?php if ($logoDataUri !== '') : ?>
                <img class="logo" src="<?= $h($logoDataUri) ?>">
                <?php endif; ?>
            </td>
            <td style="width: 55%;">
                <div class="destaque"><?= $h($d['prestador']['razao_social']) ?></div>
                <?php if ($d['prestador']['nome_fantasia'] !== '') : ?>
                <div><?= $h($d['prestador']['nome_fantasia']) ?></div>
                <?php endif; ?>
                <div><?= $h($d['prestador']['endereco']) ?></div>
                <div>CEP <?= $h($d['prestador']['cep']) ?> - Fone: <?= $h($d['prestador']['telefone']) ?> - <?= $h($d['prestador']['municipio_uf']) ?></div>
                <div><?= $h($d['prestador']['email']) ?></div>
                <div>Inscrição Municipal <?= $h($d['prestador']['inscricao_municipal']) ?> - CPF/CNPJ <?= $h($d['prestador']['cnpj']) ?></div>
            </td>
            <td style="width: 30%;">
                <div class="campo-label">Data de Geração da NFS-e</div>
                <div class="campo-valor destaque"><?= $h($d['data_emissao']) ?></div>
                <div class="campo-label">Data de Competência</div>
                <div class="campo-valor destaque"><?= $h($d['competencia']) ?></div>
                <div class="campo-label">Cód. de Autenticidade</div>
                <div class="campo-valor destaque"><?= $h($d['codigo_verificacao']) ?></div>
                <div class="campo-label">Responsável pela Retenção</div>
                <div class="campo-valor destaque"><?= $h($d['responsavel_retencao']) ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="caixa">
    <div class="titulo">Identificação da Nota Fiscal Eletrônica</div>
    <table class="conteudo">
        <tr>
            <td>
                <div class="campo-label">Natureza da Operação</div>
                <div class="campo-valor"><?= $h($d['natureza_operacao']) ?></div>
            </td>
            <td>
                <div class="campo-label">Número do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_numero']) ?></div>
            </td>
            <td>
                <div class="campo-label">Série do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_serie']) ?></div>
            </td>
            <td>
                <div class="campo-label">Data de Emissão do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_data_emissao']) ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="campo-label">Local dos Serviços</div>
                <div class="campo-valor"><?= $h($d['local_servicos']) ?></div>
            </td>
            <td colspan="2">
                <div class="campo-label">Município Incidência</div>
                <div class="campo-valor"><?= $h($d['municipio_incidencia']) ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="caixa">
    <div class="titulo">Dados do Tomador de Serviços</div>
    <table class="conteudo">
        <tr>
            <td style="width: 50%;">
                <div><span class="campo-label">CNPJ/CPF:</span> <?= $h($d['tomador']['cnpj_cpf']) ?></div>
                <div><span class="campo-label">IM:</span> <?= $h($d['tomador']['inscricao_municipal']) ?></div>
                <div><span class="campo-label">Razão Social:</span> <?= $h($d['tomador']['razao_social']) ?></div>
                <div><span class="campo-label">Endereço:</span> <?= $h($d['tomador']['endereco']) ?></div>
                <div><span class="campo-label">Complemento:</span> <?= $h($d['tomador']['complemento']) ?></div>
                <div><span class="campo-label">CEP:</span> <?= $h($d['tomador']['cep']) ?></div>
            </td>
            <td style="width: 50%;">
                <div><span class="campo-label">Número:</span> <?= $h($d['tomador']['numero']) ?></div>
                <div><span class="campo-label">Bairro:</span> <?= $h($d['tomador']['bairro']) ?></div>
                <div><span class="campo-label">Cidade/UF:</span> <?= $h($d['tomador']['cidade_uf']) ?></div>
                <div><span class="campo-label">Telefone:</span> <?= $h($d['tomador']['telefone']) ?></div>
                <div><span class="campo-label">Email:</span> <?= $h($d['tomador']['email']) ?></div>
            </td>
        </tr>
    </table>
</div>

<?php if ($d['intermediario'] !== []) : ?>
<div class="caixa">
    <div class="titulo">Dados do Intermediário de Serviços</div>
    <table class="conteudo">
        <tr>
            <td><span class="campo-label">CNPJ/CPF:</span> <?= $h($d['intermediario']['cnpj_cpf']) ?></td>
            <td><span class="campo-label">Inscrição Municipal:</span> <?= $h($d['intermediario']['inscricao_municipal']) ?></td>
            <td><span class="campo-label">Razão Social:</span> <?= $h($d['intermediario']['razao_social']) ?></td>
        </tr>
    </table>
</div>
<?php endif; ?>

<div class="caixa">
    <div class="titulo">Descrição dos Serviços</div>
    <div class="conteudo"><?= nl2br($h($d['discriminacao'])) ?></div>
</div>

<div class="caixa">
    <div class="titulo">Detalhamento dos Tributos</div>
    <table class="tabela-tributos">
        <tr>
            <th>Atividade do Município</th>
            <th>Alíquota</th>
            <th>Item da LC116/2003</th>
            <th>Cód. NBS</th>
            <th>Cód. CNAE</th>
        </tr>
        <tr>
            <td><?= $h($d['atividade_municipio']) ?></td>
            <td><?= $h($d['aliquota']) ?></td>
            <td><?= $h($d['item_lista_servico']) ?></td>
            <td><?= $h($d['codigo_nbs']) ?></td>
            <td><?= $h($d['codigo_cnae']) ?></td>
        </tr>
        <tr>
            <th>Vl. Total dos Serviços</th>
            <th>Desconto Incondicionado</th>
            <th>Deduções Base Cálculo</th>
            <th>Base de Cálculo</th>
            <th></th>
        </tr>
        <tr>
            <td class="destaque"><?= $h($d['valor_total_servicos']) ?></td>
            <td><?= $h($d['desconto_incondicionado']) ?></td>
            <td><?= $h($d['deducoes_base_calculo']) ?></td>
            <td class="destaque"><?= $h($d['base_calculo']) ?></td>
            <td></td>
        </tr>
        <tr>
            <th>Total do ISSQN</th>
            <th>ISSQN Retido</th>
            <th>Desconto Condicionado</th>
            <th colspan="2"></th>
        </tr>
        <tr>
            <td><?= $h($d['total_issqn']) ?></td>
            <td><?= $h($d['issqn_retido']) ?></td>
            <td><?= $h($d['desconto_condicionado']) ?></td>
            <td colspan="2"></td>
        </tr>
        <tr>
            <th>PIS</th>
            <th>COFINS</th>
            <th>INSS</th>
            <th>IRRF</th>
            <th>CSLL</th>
        </tr>
        <tr>
            <td><?= $h($d['pis']) ?></td>
            <td><?= $h($d['cofins']) ?></td>
            <td><?= $h($d['inss']) ?></td>
            <td><?= $h($d['irrf']) ?></td>
            <td><?= $h($d['csll']) ?></td>
        </tr>
        <tr>
            <th>Outras Retenções</th>
            <th>Vl. ISSQN Retido</th>
            <th colspan="3">Vl. Líquido da Nota Fiscal</th>
        </tr>
        <tr>
            <td><?= $h($d['outras_retencoes']) ?></td>
            <td><?= $h($d['valor_issqn_retido']) ?></td>
            <td colspan="3" class="destaque"><?= $h($d['valor_liquido_nota']) ?></td>
        </tr>
    </table>
</div>

<?php if ($d['informacoes_adicionais'] !== '') : ?>
<div class="caixa">
    <div class="titulo">Informações Adicionais</div>
    <div class="conteudo"><?= nl2br($h($d['informacoes_adicionais'])) ?></div>
</div>
<?php endif; ?>

<p style="text-align: center; font-size: 8px;">
    Consulte a autenticidade deste documento (código <?= $h($d['codigo_verificacao']) ?>) em:
    https://www.issnetonline.com.br/goiania/online/
</p>

</body>
</html>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/DanfseTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: Same as Task 5 Step 6, plus the two new template tests passing.

- [ ] **Step 6: Commit**

```bash
git add src/templates/danfse.php tests/DanfseTest.php
git commit -m "feat(nfse): add DANFS-e HTML template and Danfse::renderizar()"
```

---

### Task 7: Wire into `orcamentos/ver.php` and generate the PDF with Dompdf

**Files:**
- Modify: `public/pages/orcamentos/ver.php:1-44` (imports + early-path GET handler)
- Modify: `public/pages/orcamentos/ver.php:252-260` (button label)

**Interfaces:**
- Consumes: `Storage::buscarEmissao()` (Task 4), `Danfse::extrair()` / `Danfse::renderizar()` (Tasks 5-6), `Dompdf\Dompdf` (Task 1).
- Produces: an HTTP response (`Content-Type: application/pdf`) — no PHP interface, this is the user-facing entry point. Verified manually (see Step 3), not by PHPUnit — same as the existing NF-e DANFE feature (`docs/superpowers/specs/2026-07-08-logo-danfe-design.md` §5: "Não há suíte automatizada cobrindo geração de DANFE").

- [ ] **Step 1: Replace the early-path GET handler**

Read the current state of `public/pages/orcamentos/ver.php` first — this plan was written against the version at commit `f902403`; if `git log -1 -- public/pages/orcamentos/ver.php` shows a newer commit, re-check lines 1-44 and 252-260 still match before editing.

Current top of file (lines 1-44):

```php
<?php

declare(strict_types=1);

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
if (($_GET['clonado'] ?? '') === '1') {
    $flash = ['tipo' => 'success', 'msg' => 'Orçamento clonado com sucesso — revise antes de aprovar.'];
}
$usuario = $auth->usuarioAtual();

// ── DANFS-e via redirect GET (não altera estado) ──────────────────────────────
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
            $flash = ['tipo' => 'warning', 'msg' => 'URL do DANFS-e não encontrada no retorno do SGISS.'];
        } catch (\Throwable $e) {
            $flash = ['tipo' => 'danger', 'msg' => 'Erro ao obter DANFS-e: ' . $e->getMessage()];
        }
    }
}
```

Use the Edit tool to replace it with:

```php
<?php

declare(strict_types=1);

use EmissorGyn\Config;
use EmissorGyn\Danfse;
use EmissorGyn\Storage;

$id        = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$orcamento = $id > 0 ? $cadastro->buscarOrcamento($id) : null;

if ($orcamento === null) {
    http_response_code(404);
    exit('Orçamento não encontrado.');
}

$flash   = null;
if (($_GET['clonado'] ?? '') === '1') {
    $flash = ['tipo' => 'success', 'msg' => 'Orçamento clonado com sucesso — revise antes de aprovar.'];
}
$usuario = $auth->usuarioAtual();

// ── DANFS-e em PDF, gerada localmente (não altera estado) ─────────────────────
if (($_GET['acao'] ?? '') === 'danfse' && $orcamento['status'] === 'emitido') {
    $cfg     = new Config(dirname(__DIR__, 3));
    $storage = new Storage($cfg->path('DB_PATH', 'storage/nfse.sqlite'));
    $emissao = !empty($orcamento['emissao_id'])
        ? $storage->buscarEmissao((int) $orcamento['emissao_id'])
        : null;

    if ($emissao !== null && !empty($emissao['xml_retorno'])) {
        try {
            $dados    = Danfse::extrair((string) $emissao['xml_retorno']);
            $logoPath = $cfg->path('LOGO_PATH', '');
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

Note: `Config`, `NfseClient`, `ResponseParser`, `XmlFactory` were only imported for the old remote-URL flow. `NfseClient`, `ResponseParser`, `XmlFactory` are no longer used anywhere else in this file — removing their `use` statements is intentional, not an oversight. `Storage` stays imported (still used by the `'emitir'` POST action further down in the file). `Config` stays imported too (used here and in the `'emitir'` action).

- [ ] **Step 2: Update the button label**

Current (lines 252-260):

```php
<?php if ($orcamento['status'] === 'emitido' && !empty($orcamento['nfse_numero'])) : ?>
<div class="alert alert-success mt-4">
    <strong>NFS-e emitida:</strong> <?= h($orcamento['nfse_numero']) ?>
    <a href="?p=orcamentos/ver&amp;id=<?= $id ?>&amp;acao=danfse"
       class="btn btn-sm btn-success ms-3">
        Abrir DANFS-e Oficial
    </a>
</div>
<?php endif; ?>
```

Replace with:

```php
<?php if ($orcamento['status'] === 'emitido' && !empty($orcamento['nfse_numero'])) : ?>
<div class="alert alert-success mt-4">
    <strong>NFS-e emitida:</strong> <?= h($orcamento['nfse_numero']) ?>
    <a href="?p=orcamentos/ver&amp;id=<?= $id ?>&amp;acao=danfse"
       class="btn btn-sm btn-success ms-3" target="_blank">
        Baixar DANFS-e (PDF)
    </a>
</div>
<?php endif; ?>
```

(Added `target="_blank"` too — matches the equivalent NF-e DANFE button pattern in `public/pages/pedidos/ver.php:306-308`, opens the PDF in a new tab instead of navigating away from the orçamento page.)

- [ ] **Step 3: Manual verification**

This wiring touches HTTP routing and can't be exercised by PHPUnit (no HTTP test harness in this project — same limitation as the NF-e DANFE feature). Verify by hand:

```bash
cd /opt/Lumina/emissor-nfse-goiania
# Restart the web service so the code change takes effect
kill $(pgrep -f "php -S 0.0.0.0:8080") 2>/dev/null
nohup bin/web > storage/web.log 2>&1 &
disown
sleep 1
curl -s -o /dev/null -w "web: HTTP %{http_code}\n" http://localhost:8080/
```

Then, logged into the web UI:
1. Open the orçamento that already has an emitted NFS-e (RPS 1, código de verificação `FD3563DD9` — the one used throughout the design spec).
2. Click "Baixar DANFS-e (PDF)".
3. Confirm a PDF opens in a new tab (not an error flash message).
4. Confirm the PDF shows: número 1, código de verificação `FD3563DD9`, prestador "Lumina Energia Sustentavel Ltda", tomador "MOSTEIRO DE NOSSA SENHORA DAS GRACAS", valor total R$ 37.209,00, valor líquido R$ 36.464,82, "Local dos Serviços" and "Município Incidência" both showing "Belo Horizonte - Minas Gerais", "Vl. ISSQN Retido" R$ 744,18 with "Total do ISSQN" R$ 0,00.
5. Confirm no QR code and no "Chave de acesso" line appear anywhere in the PDF.
6. Compare side-by-side against the reference DANFS-e image provided during brainstorming — layout doesn't need to be pixel-identical, but every field from Step 4 above must be present, correct, and legible.

- [ ] **Step 4: Run the full test suite one more time**

Run: `vendor/bin/phpunit`
Expected: Same result as Task 6 Step 5 (no regressions from the `ver.php` change — it isn't covered by PHPUnit, but this confirms the rest of the app is still intact).

- [ ] **Step 5: Commit**

```bash
git add public/pages/orcamentos/ver.php
git commit -m "feat(nfse): generate DANFS-e PDF locally instead of calling the broken ConsultarUrlNfse"
```

---

### Task 8: Update README

**Files:**
- Modify: `README.md`

**Interfaces:** None — documentation only.

- [ ] **Step 1: Add the feature to the Orçamentos bullet under "Módulos da interface"**

Find this line in `README.md` (in the "Módulos da interface" list, under "Orçamentos"):

```markdown
- **Orçamentos** — geração e emissão de NFS-e
```

Replace with:

```markdown
- **Orçamentos** — geração e emissão de NFS-e; DANFS-e em PDF gerado localmente sob demanda a partir do XML de retorno da SGISS (não depende de nenhum endpoint remoto da prefeitura)
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs(nfse): mention local DANFS-e PDF generation in README"
```

---

## Summary of New Files

- `data/municipios_ibge.json` — IBGE municipality code lookup table.
- `src/Municipios.php` + `tests/MunicipiosTest.php`
- `src/Danfse.php` + `tests/DanfseTest.php` + `tests/fixtures/nfse-retorno.xml`
- `src/templates/danfse.php`

## Summary of Modified Files

- `composer.json` / `composer.lock` — `dompdf/dompdf` added.
- `src/Storage.php` — `buscarEmissao()` added.
- `public/pages/orcamentos/ver.php` — DANFS-e action now generates a PDF locally instead of calling `ConsultarUrlNfse`; button relabeled.
- `README.md` — new capability documented.
