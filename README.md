# Emissor NFS-e Goiânia — SGISS / ABRASF 2.04

Sistema open source em PHP para emissão de Nota Fiscal de Serviços Eletrônica (NFS-e) integrado ao **SGISS da Prefeitura de Goiânia** (provedor ISSNet), no layout **ABRASF 2.04**, construído sobre os componentes do projeto **NFePHP** (`nfephp-org/sped-common`) para leitura do certificado A1 e assinatura digital XMLDSig.

Desde 01/12/2025 a emissão de NFS-e em Goiânia só pode ser feita por software próprio ou de mercado integrado ao SGISS — é exatamente o que este projeto faz.

## Parâmetros oficiais (FAQ SGISS — Prefeitura de Goiânia)

| Parâmetro | Valor |
|---|---|
| WebService de produção | `https://nfse.issnetonline.com.br/abrasf204/goiania/nfse.asmx` |
| Código do município | `5208707` |
| Série do RPS | `1` |
| Layout | ABRASF 2.04 |
| Consulta/portal | `https://www.issnetonline.com.br/goiania/` |
| Suporte técnico | suporte.goiania@notacontrol.com.br — (67) 3041-2075 |

**Não existe ambiente de homologação** — o WebService é só de produção. Use `--dry-run` para validar o XML localmente antes de emitir de verdade.

## Pré-requisitos

1. **Certificado digital A1 (e-CNPJ, .pfx)** com o **mesmo CNPJ** do cadastro na Prefeitura (CAE). Certificado de CNPJ raiz da matriz não serve para filial.
2. **Inscrição Municipal (CAE)** ativa em Goiânia.
3. **Faixa de RPS liberada no SGISS**: acesse o portal e vá em *Solicitação de documentos fiscais > Solicitação > Modelo: RPS*. Depois da primeira solicitação, o sistema libera novos lotes automaticamente conforme o seu uso do WebService. Você pode conferir a faixa liberada com `bin/nfse rps-disponivel`.
4. Ubuntu 22.04/24.04 com PHP ≥ 8.1.

## Instalação

```bash
git clone <seu-repositorio> emissor-nfse-goiania   # ou copie a pasta
cd emissor-nfse-goiania
bash install.sh
nano .env        # preencha certificado, CNPJ, IM etc.
```

Instalação manual equivalente:

```bash
sudo apt install php-cli php-curl php-xml php-mbstring php-sqlite3 composer
composer install --no-dev
cp .env.example .env && nano .env
chmod +x bin/nfse
```

## Primeiros testes

```bash
# 1. Valida certificado + comunicação com o SGISS (não emite nada)
bin/nfse dados-cadastrais

# 2. Confere a faixa de RPS liberada pela Prefeitura
bin/nfse rps-disponivel

# 3. Gera e assina o XML localmente, sem enviar
bin/nfse emitir --arquivo examples/nota.json --dry-run
```

## Emitindo uma nota

Edite `examples/nota.json` com os dados reais e:

```bash
bin/nfse emitir --arquivo examples/nota.json
```

Saída esperada:

```
RPS reservado: 1 (série 1)
NFS-e AUTORIZADA!
  Número:              123
  Código verificação:  ABCD-1234
  Data de emissão:     2026-07-06T14:32:10
```

Todos os XMLs enviados e recebidos ficam salvos em `storage/xml/` e o histórico em SQLite (`storage/nfse.sqlite`). **Guarde esses arquivos** — o Decreto 2.824/2025 exige a guarda do RPS/XML pelo prazo decadencial.

### Campos do JSON da nota

| Campo | Obrigatório | Observação |
|---|---|---|
| `servico.valor_servicos` | sim | aceita `"3500.00"` ou `"3.500,00"` |
| `servico.item_lista_servico` | sim | subitem da LC 116/2003 (ex.: `7.02`) vinculado ao seu CNAE no cadastro municipal |
| `servico.discriminacao` | sim | descrição do serviço |
| `servico.iss_retido` | sim | `2` = não retido; `1` = retido pelo tomador (aí `aliquota` vira obrigatória) |
| `servico.aliquota` | condicional | Simples Nacional sem retenção pode deixar vazio (FAQ, item 24); em %, ex.: `5.00` |
| `servico.codigo_cnae` | recomendado | CNAE da atividade |
| `tomador.cpf_cnpj` | sim p/ PJ | identificação do tomador PJ é exigida |
| `competencia` | não | default = hoje; retroativa até 365 dias |

## Consultas, cancelamento e impressão

```bash
bin/nfse consultar-rps --numero 15                 # NFS-e gerada a partir do RPS 15
bin/nfse notas --inicio 2026-07-01 --fim 2026-07-31
bin/nfse listar                                     # histórico local
bin/nfse url --nfse 123                             # link do DANFS-e para impressão
bin/nfse cancelar --nfse 123 --codigo 1             # 1=erro, 2=serviço não prestado, 4=duplicidade
```

Regras de cancelamento (IN SMF 16/2025): pelo WebService/SGISS **até o 5º dia do mês subsequente** à emissão; depois disso, somente por processo administrativo (SEI) no Atende Fácil. Erros só na *descrição* do serviço podem ser corrigidos por Carta de Correção no portal, sem cancelar.

## API HTTP (integração com seu sistema de gestão)

```bash
php -S 127.0.0.1:8080 public/api.php
```

```bash
curl -X POST http://127.0.0.1:8080/emitir \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -d @examples/nota.json
```

Endpoints: `POST /emitir`, `GET /rps/{n}`, `GET /notas?inicio=&fim=`, `POST /cancelar`, `GET /url/{nfse}`, `GET /historico`. Autenticação por `Authorization: Bearer <API_TOKEN>` (defina no `.env`). Para produção contínua, coloque atrás de nginx + php-fpm e restrinja à rede interna.

## Numeração de RPS

O contador local (SQLite) reserva o próximo número de forma atômica a cada emissão. Se você já emitiu RPS por outro sistema, sincronize com:

```bash
bin/nfse set-rps --numero <ultimo_numero_usado>
```

Lembre: o RPS deve ser convertido em NFS-e **em até 10 dias** da emissão (ou até o dia 5 do mês seguinte, o que vier primeiro). Como este sistema usa o `GerarNfse` síncrono, a conversão é imediata.

## Obrigações que continuam no portal SGISS

Este sistema cuida da **emissão/consulta/cancelamento** de NFS-e. Encerramento mensal da competência (até o dia 05), geração do DUAM, REST/aceite de notas tomadas e declarações especiais são feitos no portal: `https://www.issnetonline.com.br/goiania/`. Para optantes do Simples, o ISS é recolhido via DAS normalmente.

## Estrutura do projeto

```
bin/nfse              CLI
public/api.php        API HTTP JSON
src/Config.php        leitura do .env
src/XmlFactory.php    montagem dos XMLs ABRASF 2.04
src/NfseClient.php    assinatura (NFePHP Signer) + SOAP 1.1 (curl)
src/ResponseParser.php  interpretação dos retornos
src/Storage.php       SQLite: numeração de RPS + histórico
storage/              banco + XMLs (faça backup!)
```

## Licença e avisos

MIT. Software fornecido "como está", sem garantias. Valide as regras tributárias (alíquota, item da lista, retenções, exigibilidade) com o seu contador — o sistema transmite o que você informar. O layout/endpoint pode ser alterado pela Prefeitura/provedor; acompanhe os comunicados da SEFAZ Goiânia e o fórum do projeto ACBr, que costuma noticiar mudanças rapidamente.
