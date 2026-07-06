# Cadastro de Clientes e Orçamentos — Design Spec
**Projeto:** emissor-nfse-goiania (Lumina)
**Data:** 2026-07-06
**Status:** Aprovado

---

## 1. Visão Geral

Módulo web integrado ao emissor NFS-e da Lumina que permite:
- Cadastrar clientes (tomadores) recorrentes
- Criar templates de serviço reutilizáveis
- Gerenciar orçamentos com fluxo rascunho → aprovado → emitido
- Emitir NFS-e diretamente da interface, com link para o DANFS-e oficial (ISSNet)
- Acesso remoto via internet com autenticação de usuários e HTTPS

A interface é PHP puro, sem framework, servida por Nginx + PHP-FPM em VPS Linux.

---

## 2. Modelo de Dados

Quatro novas tabelas no mesmo arquivo `storage/nfse.sqlite`. Adicionadas via `migrate()` em `Cadastro.php`.

### 2.1 `usuarios`

```sql
CREATE TABLE IF NOT EXISTS usuarios (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nome        TEXT    NOT NULL,
    email       TEXT    NOT NULL UNIQUE,
    senha_hash  TEXT    NOT NULL,
    criado_em   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
```

### 2.2 `login_tentativas`

```sql
CREATE TABLE IF NOT EXISTS login_tentativas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ip          TEXT    NOT NULL,
    tentativas  INTEGER NOT NULL DEFAULT 1,
    bloqueado_ate TEXT,
    ultima_em   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_login_ip ON login_tentativas (ip);
```

### 2.3 `clientes`

```sql
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
```

### 2.4 `servicos`

Templates de serviço reutilizáveis, usados como ponto de partida para orçamentos.

```sql
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
```

### 2.5 `orcamentos`

Os campos de serviço são **copiados** do template no momento da criação — alterações posteriores no template não afetam orçamentos já criados.

```sql
CREATE TABLE IF NOT EXISTS orcamentos (
    id                            INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id                    INTEGER NOT NULL REFERENCES clientes(id),
    servico_id                    INTEGER REFERENCES servicos(id),
    status                        TEXT NOT NULL DEFAULT 'rascunho',
    competencia                   TEXT NOT NULL,
    -- campos do serviço (copiados/editáveis)
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
    -- vínculo com a emissão
    emissao_id                    INTEGER REFERENCES emissoes(id),
    -- auditoria
    criado_por                    INTEGER REFERENCES usuarios(id),
    aprovado_por                  INTEGER REFERENCES usuarios(id),
    criado_em                     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    aprovado_em                   TEXT,
    emitido_em                    TEXT
);
CREATE INDEX IF NOT EXISTS idx_orcamentos_cliente ON orcamentos (cliente_id);
CREATE INDEX IF NOT EXISTS idx_orcamentos_status  ON orcamentos (status);
```

**Valores válidos de `status`:** `rascunho | aprovado | emitido | cancelado`

---

## 3. Estrutura de Arquivos

```
public/
  web.php                    ← router + bootstrap (session_start, auth guard, dispatch)
  pages/
    login.php                ← login + cadastro de usuário
    dashboard.php            ← cartões de resumo + últimas emissões
    clientes/
      index.php              ← listagem com busca
      form.php               ← criar / editar
    servicos/
      index.php              ← listagem de templates
      form.php               ← criar / editar
    orcamentos/
      index.php              ← listagem com filtro por status
      form.php               ← criar / editar (só rascunho)
      ver.php                ← detalhe + botões de workflow

src/
  Cadastro.php               ← queries das 4 novas tabelas + migrate()
  Auth.php                   ← sessão, password_hash/verify, guard, rate limit

nginx/
  lumina-nfse.conf           ← vhost Nginx pronto para produção

storage/
  nfse.sqlite                ← mesmo arquivo; novas tabelas adicionadas automaticamente
```

---

## 4. Roteamento

`public/web.php` é o único entry point web. Despacha por `$_GET['p']`:

```
p=                       → dashboard
p=login                  → login / cadastro
p=clientes               → listagem
p=clientes/form          → criar / editar (?id=X para editar)
p=servicos               → listagem
p=servicos/form          → criar / editar
p=orcamentos             → listagem
p=orcamentos/form        → criar / editar
p=orcamentos/ver&id=X    → detalhe + workflow
```

Todas as rotas exceto `login` exigem sessão ativa — `Auth::guard()` redireciona para `?p=login` se não autenticado.

Ações de workflow chegam via POST no mesmo `?p=orcamentos/ver&id=X` com campo `acao` (`aprovar | emitir | cancelar`).

---

## 5. Autenticação

**Classe `Auth`** (`src/Auth.php`):

| Método | Responsabilidade |
|---|---|
| `login(email, senha)` | Verifica rate limit → valida credenciais → `session_regenerate_id()` → grava sessão |
| `logout()` | Destroi sessão |
| `guard()` | Redireciona para login se não autenticado |
| `usuarioAtual()` | Retorna array com id/nome do usuário logado |
| `registrar(nome, email, senha)` | Primeiro usuário: livre. Demais: exige sessão ativa |
| `registrarTentativa(ip)` | Incrementa contador; bloqueia IP por 15 min após 5 falhas |

**Configuração da sessão** (aplicada antes de `session_start()`):
```php
session_name('lumina_sid');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
```

**CSRF:** token UUID gerado na sessão ao iniciar, validado em todo POST via campo oculto `_csrf`.

---

## 6. Fluxo de Orçamento

```
[Criar orçamento]
  → escolhe cliente
  → escolhe template (opcional) → campos pré-preenchidos mas editáveis
  → salva como rascunho

[rascunho] ──Aprovar──► [aprovado] ──Emitir NFS-e──► [emitido]
    │                       │
    └──Cancelar──►      Cancelar──►  [cancelado]
```

### Emissão (POST `acao=emitir`)

1. Carrega dados do cliente e do orçamento do SQLite
2. Monta array da nota no formato de `examples/nota.json`
3. Chama `NfseClient::gerarNfse()` diretamente (sem round-trip HTTP)
4. **Sucesso:** `status = 'emitido'`, grava `emissao_id` e `emitido_em`; exibe número da NFS-e e botão "DANFS-e Oficial"
5. **Falha:** exibe mensagem de erro; `status` permanece `aprovado` (pode tentar novamente)

### DANFS-e

Botão "DANFS-e Oficial" chama `NfseClient::consultarUrlNfse()`, extrai a URL do XML de retorno e abre em nova aba. Não há geração de PDF local — o DANFS-e do portal ISSNet é o documento oficial válido juridicamente.

---

## 7. Segurança

| Camada | Medida |
|---|---|
| Transporte | HTTPS obrigatório (Let's Encrypt via Certbot, renovação automática) |
| Nginx | Document root restrito a `public/`; `src/`, `storage/`, `.env`, certificados inacessíveis |
| CSRF | Token UUID por sessão, validado em todo POST |
| Sessão | `Secure`, `HttpOnly`, `SameSite=Strict`, `use_strict_mode`, nome customizado, regeneração de ID no login |
| Força bruta | Rate limit por IP: 5 tentativas → bloqueio de 15 min (tabela `login_tentativas`) |
| Senhas | `password_hash(PASSWORD_BCRYPT)`, mínimo 8 caracteres |
| Headers HTTP | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` |

---

## 8. Deployment (VPS Linux)

### Stack
```
Ubuntu/Debian
  Nginx 1.18+
  PHP 8.2-FPM
  Certbot (Let's Encrypt)
```

### Nginx (`nginx/lumina-nfse.conf`)

```nginx
server {
    listen 443 ssl http2;
    server_name seu-dominio.com.br;

    ssl_certificate     /etc/letsencrypt/live/seu-dominio.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/seu-dominio.com.br/privkey.pem;

    root /opt/Lumina/emissor-nfse-goiania/public;
    index web.php;

    location / {
        try_files $uri /web.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Bloqueia acesso direto a tudo fora de public/
    location ~* \.(env|sqlite|pfx|p12|pem|key|xml)$ {
        deny all;
    }
}

server {
    listen 80;
    server_name seu-dominio.com.br;
    return 301 https://$host$request_uri;
}
```

### Passos de instalação
1. `sudo apt install nginx php8.2-fpm php8.2-{curl,dom,sqlite3,mbstring} certbot python3-certbot-nginx`
2. Copiar `nginx/lumina-nfse.conf` para `/etc/nginx/sites-available/` e habilitar
3. `sudo certbot --nginx -d seu-dominio.com.br`
4. `composer install --no-dev` no diretório do projeto
5. Copiar `.env.example` → `.env` e preencher
6. Apontar `DB_PATH` para caminho absoluto com permissão de escrita para `www-data`

---

## 9. Páginas — Resumo Funcional

| Página | Rota | Funcionalidade |
|---|---|---|
| Login / Cadastro | `?p=login` | Formulário de login; link "Criar conta" cria novo usuário |
| Dashboard | `?p=` | Cartões: clientes, orçamentos por status, últimas 5 emissões |
| Clientes — lista | `?p=clientes` | Tabela com busca por razão social / CPF-CNPJ; botão novo |
| Clientes — form | `?p=clientes/form` | Criar / editar todos os campos do tomador |
| Serviços — lista | `?p=servicos` | Templates ativos; botão novo |
| Serviços — form | `?p=servicos/form` | Criar / editar template de serviço |
| Orçamentos — lista | `?p=orcamentos` | Filtro por status; busca por cliente |
| Orçamentos — form | `?p=orcamentos/form` | Criar (escolhe cliente + template); editar (só rascunho) |
| Orçamentos — ver | `?p=orcamentos/ver&id=X` | Detalhe completo + botões de workflow + DANFS-e |

---

## 10. Fora do Escopo

- Geração de PDF local (usar DANFS-e oficial do ISSNet)
- Múltiplos perfis de usuário / permissões (todos os usuários têm acesso total)
- Envio de e-mail ao cliente
- Integração com sistemas externos (ERP, CRM)
- Ambiente de homologação (SGISS Goiânia não possui)
