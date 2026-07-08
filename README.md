# Emissor NFS-e / NF-e Goiânia — Lumina

Sistema open source em PHP para emissão de **NFS-e** (Nota Fiscal de Serviços) integrado ao **SGISS da Prefeitura de Goiânia** (provedor ISSNet, layout ABRASF 2.04) e **NF-e modelo 55** (Nota Fiscal de Produto) integrado à **SEFAZ-GO via SVRS**, com interface web completa para gestão de clientes, serviços, orçamentos, pedidos NF-e e dashboard.

Desde 01/12/2025 a emissão de NFS-e em Goiânia só pode ser feita por software próprio ou de mercado integrado ao SGISS — é exatamente o que este projeto faz.

---

## Módulos

| Módulo | Tipo | Descrição |
|---|---|---|
| **NFS-e** | Serviços | Emissão via SGISS/ISSNet, ABRASF 2.04 |
| **NF-e** | Produtos | Emissão via SEFAZ-GO (SVRS), modelo 55, sped-nfe 5.x |
| **Interface web** | UI | Clientes, serviços, orçamentos, pedidos NF-e, dashboard |
| **API HTTP** | Integração | JSON REST para emissão/consulta de NFS-e |

---

## Parâmetros oficiais (NFS-e — FAQ SGISS Goiânia)

| Parâmetro | Valor |
|---|---|
| WebService de produção | `https://nfse.issnetonline.com.br/abrasf204/goiania/nfse.asmx` |
| Código do município | `5208707` |
| Série do RPS | `1` |
| Layout | ABRASF 2.04 |
| Consulta/portal | `https://www.issnetonline.com.br/goiania/` |
| Suporte técnico | suporte.goiania@notacontrol.com.br — (67) 3041-2075 |

**Não existe ambiente de homologação para NFS-e** — o WebService é só de produção. Use `--dry-run` para validar o XML localmente antes de emitir.

---

## Pré-requisitos

1. **Certificado digital A1 (e-CNPJ, .pfx)** com o **mesmo CNPJ** do cadastro na Prefeitura (CAE). Certificado de CNPJ raiz da matriz não serve para filial.
2. **Inscrição Municipal (CAE)** ativa em Goiânia (para NFS-e).
3. **Faixa de RPS liberada no SGISS** (para NFS-e): acesse o portal em *Solicitação de documentos fiscais > Solicitação > Modelo: RPS*.
4. Ubuntu 22.04/24.04 com **PHP ≥ 8.2** e extensões: `curl`, `dom`, `gd`, `json`, `libxml`, `mbstring`, `openssl`, `pdo`, `pdo_sqlite`, `simplexml`, `soap`, `zlib`.

---

## Instalação

```bash
git clone <seu-repositorio> emissor-nfse-goiania
cd emissor-nfse-goiania
bash install.sh
nano .env   # preencha certificado, CNPJ, IM, dados do emitente NF-e
```

Instalação manual equivalente:

```bash
sudo apt install php8.2-cli php8.2-curl php8.2-xml php8.2-mbstring \
                 php8.2-sqlite3 php8.2-soap php8.2-gd composer
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env
chmod +x bin/nfse
```

---

## Interface Web

```bash
php -S 0.0.0.0:8080 public/web.php
```

Acesse `http://localhost:8080` no navegador. Para expor externamente:

```bash
ngrok http 8080
```

### Módulos da interface

- **Dashboard** — visão geral: estatísticas de NFS-e e NF-e por status
- **Clientes** — cadastro de pessoas físicas e jurídicas
- **Serviços** — templates de serviço para emissão rápida de NFS-e
- **Orçamentos** — geração e emissão de NFS-e
- **Pedidos NF-e** — ciclo completo de NF-e de produto:
  - Criação com tabela dinâmica de itens (NCM, CFOP, CSOSN, PIS/COFINS por item)
  - Workflow: rascunho → aprovado → emitido → cancelado
  - DANFE PDF gerado sob demanda (inline no navegador), com logo da empresa opcional (`LOGO_PATH`)
  - Cancelamento com justificativa enviado à SEFAZ

---

## NF-e de Produto (modelo 55)

### Configuração mínima no `.env`

```dotenv
# Certificado (compartilhado com NFS-e)
CERT_PATH=/caminho/para/certificado.pfx
CERT_PASS=senha_do_certificado
PRESTADOR_CNPJ=00000000000000

# NF-e
NFE_AMBIENTE=2          # 2=homologação (padrão seguro), 1=produção
NFE_SERIE=1

# Dados do emitente
PRESTADOR_RAZAO_SOCIAL=Empresa LTDA
PRESTADOR_IE=1234567890123
PRESTADOR_LOGRADOURO=Rua Exemplo
PRESTADOR_NUMERO=100
PRESTADOR_BAIRRO=Centro
PRESTADOR_CODIGO_MUNICIPIO=5208707
PRESTADOR_MUNICIPIO=Goiânia
PRESTADOR_UF=GO
PRESTADOR_CEP=74000000

# Logo da empresa no DANFE (opcional; PNG ou JPG). Deixe vazio para gerar sem logo.
LOGO_PATH=
```

### Perfil fiscal Lumina

| Tributo | Regime |
|---|---|
| ICMS | CSOSN 400 — Simples Nacional sem débito |
| PIS | CST 07 — operação isenta |
| COFINS | CST 07 — operação isenta |
| SEFAZ | SEFAZ-GO (cUF=52) via SVRS |

CFOP 5.xxx (operações internas) e 6.xxx (interestaduais) suportados. DIFAL não calculado automaticamente — consulte seu contador.

---

## NFS-e via CLI

```bash
# Valida certificado + comunicação com o SGISS (não emite nada)
bin/nfse dados-cadastrais

# Confere a faixa de RPS liberada pela Prefeitura
bin/nfse rps-disponivel

# Gera e assina o XML localmente, sem enviar
bin/nfse emitir --arquivo examples/nota.json --dry-run

# Emite de verdade
bin/nfse emitir --arquivo examples/nota.json
```

### Consultas e cancelamento

```bash
bin/nfse consultar-rps --numero 15
bin/nfse notas --inicio 2026-07-01 --fim 2026-07-31
bin/nfse listar
bin/nfse url --nfse 123
bin/nfse cancelar --nfse 123 --codigo 1   # 1=erro, 2=não prestado, 4=duplicidade
```

Regras de cancelamento (IN SMF 16/2025): pelo WebService **até o 5º dia do mês subsequente**; depois disso, somente por processo administrativo (SEI) no Atende Fácil.

---

## API HTTP JSON (integração com sistema de gestão)

```bash
php -S 127.0.0.1:8080 public/api.php
```

Todos os endpoints exigem `Authorization: Bearer <API_TOKEN>` (defina no `.env`).

| Método | Endpoint | Descrição |
|---|---|---|
| POST | `/emitir` | Emite NFS-e |
| GET | `/rps/{n}` | Consulta NFS-e pelo número do RPS |
| GET | `/notas?inicio=&fim=` | Lista notas por período |
| POST | `/cancelar` | Cancela NFS-e |
| GET | `/url/{nfse}` | URL do DANFSe |
| GET | `/historico` | Histórico local |

```bash
curl -X POST http://127.0.0.1:8080/emitir \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d @examples/nota.json
```

---

## Numeração

**RPS (NFS-e):** contador local (SQLite) atômico. Para sincronizar com sistema anterior:

```bash
bin/nfse set-rps --numero <ultimo_numero_usado>
```

**NF-e:** numeração atômica via tabela `rps_sequencia` (chave `nfe:1`). Em caso de rejeição SEFAZ, o número é revertido automaticamente.

---

## Estrutura do projeto

```
bin/nfse                      CLI NFS-e
public/
  web.php                     Interface web (router + bootstrap)
  api.php                     API HTTP JSON
  pages/
    dashboard.php             Dashboard NFS-e + NF-e
    clientes/                 Cadastro de clientes
    servicos/                 Templates de serviço
    orcamentos/               Orçamentos e emissão NFS-e
    pedidos/                  Pedidos NF-e (index, form, ver)
src/
  Config.php                  Leitura do .env
  Auth.php                    Autenticação de usuários
  Cadastro.php                CRUD: clientes, serviços, orçamentos
  NfeStorage.php              CRUD NF-e: pedidos, itens, eventos, numeração
  NfeXmlFactory.php           Monta XML NF-e via NFePHP\NFe\Make
  NfeClient.php               Assina XML, comunica com SEFAZ, cancela NF-e
  XmlFactory.php              Monta XMLs NFS-e (ABRASF 2.04)
  NfseClient.php              Assina e envia NFS-e via SOAP
  ResponseParser.php          Interpreta retornos do SGISS
  Storage.php                 SQLite: histórico NFS-e + numeração RPS
storage/                      Banco SQLite + XMLs (faça backup!)
tests/                        PHPUnit — 23 testes, 65 assertions
```

---

## Obrigações que continuam no portal SGISS

Este sistema cuida da **emissão/consulta/cancelamento** de NFS-e. Encerramento mensal da competência (até o dia 05), geração do DUAM, REST/aceite de notas tomadas e declarações especiais são feitos no portal: `https://www.issnetonline.com.br/goiania/`. Para optantes do Simples, o ISS é recolhido via DAS normalmente.

---

## Licença e avisos

MIT. Software fornecido "como está", sem garantias. Valide as regras tributárias (alíquota, item da lista, retenções, exigibilidade, CFOP, CSOSN) com o seu contador — o sistema transmite o que você informar. O layout/endpoint pode ser alterado pela Prefeitura/provedor ou pela SEFAZ; acompanhe os comunicados e o fórum do projeto ACBr.
