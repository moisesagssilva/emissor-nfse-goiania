<?php

/**
 * Persistência das entidades do módulo web: usuários, clientes, serviços e orçamentos.
 */

declare(strict_types=1);

namespace EmissorGyn;

final class Cadastro
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

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS usuarios (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                nome        TEXT    NOT NULL,
                email       TEXT    NOT NULL UNIQUE,
                senha_hash  TEXT    NOT NULL,
                criado_em   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS login_tentativas (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ip            TEXT    NOT NULL,
                tentativas    INTEGER NOT NULL DEFAULT 1,
                bloqueado_ate TEXT,
                ultima_em     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_login_ip ON login_tentativas (ip);
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
            CREATE TABLE IF NOT EXISTS rps_sequencia (
                serie         TEXT PRIMARY KEY,
                ultimo_numero INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS emissoes (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                criado_em           TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                rps_numero          INTEGER NOT NULL,
                rps_serie           TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT 'enviando',
                nfse_numero         TEXT,
                codigo_verificacao  TEXT,
                valor_servicos      TEXT,
                tomador_doc         TEXT,
                tomador_razao       TEXT,
                xml_envio           TEXT,
                xml_retorno         TEXT,
                erro                TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_emissoes_nfse ON emissoes (nfse_numero);
            CREATE INDEX IF NOT EXISTS idx_emissoes_rps  ON emissoes (rps_numero, rps_serie);
            CREATE TABLE IF NOT EXISTS orcamentos (
                id                            INTEGER PRIMARY KEY AUTOINCREMENT,
                cliente_id                    INTEGER NOT NULL REFERENCES clientes(id),
                servico_id                    INTEGER REFERENCES servicos(id),
                status                        TEXT NOT NULL DEFAULT 'rascunho',
                competencia                   TEXT NOT NULL,
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
                nfse_numero                   TEXT,
                emissao_id                    INTEGER,
                criado_por                    INTEGER REFERENCES usuarios(id),
                aprovado_por                  INTEGER REFERENCES usuarios(id),
                criado_em                     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                aprovado_em                   TEXT,
                emitido_em                    TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_orcamentos_cliente ON orcamentos (cliente_id);
            CREATE INDEX IF NOT EXISTS idx_orcamentos_status  ON orcamentos (status);
        SQL);
        try {
            $this->pdo->exec('ALTER TABLE clientes ADD COLUMN municipio TEXT');
        } catch (\PDOException) {
        }
    }

    // ─── Usuários ────────────────────────────────────────────────────────────

    public function contarUsuarios(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function buscarUsuarioPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function inserirUsuario(string $nome, string $email, string $senhaHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$nome, $email, $senhaHash]);
        return (int) $this->pdo->lastInsertId();
    }

    // ─── Rate limiting ───────────────────────────────────────────────────────

    public function registrarTentativa(string $ip): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO login_tentativas (ip, tentativas, ultima_em)
            VALUES (?, 1, datetime('now','localtime'))
            ON CONFLICT(ip) DO UPDATE SET
                tentativas    = tentativas + 1,
                bloqueado_ate = CASE
                    WHEN tentativas + 1 >= 5
                    THEN datetime('now', '+15 minutes', 'localtime')
                    ELSE bloqueado_ate
                END,
                ultima_em = datetime('now','localtime')
        SQL)->execute([$ip]);
    }

    public function verificarBloqueio(string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM login_tentativas
              WHERE ip = ? AND bloqueado_ate > datetime('now','localtime')"
        );
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() !== false;
    }

    public function limparTentativas(string $ip): void
    {
        $this->pdo->prepare(
            'DELETE FROM login_tentativas WHERE ip = ?'
        )->execute([$ip]);
    }

    // ─── Clientes ────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarClientes(string $busca = ''): array
    {
        if ($busca !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM clientes WHERE ativo = 1
                  AND (razao_social LIKE ? OR cpf_cnpj LIKE ?)
                  ORDER BY razao_social'
            );
            $stmt->execute(["%{$busca}%", "%{$busca}%"]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT * FROM clientes WHERE ativo = 1 ORDER BY razao_social'
            );
        }
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarCliente(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirCliente(array $dados): int
    {
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
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarCliente(int $id, array $dados): void
    {
        $this->pdo->prepare(
            'UPDATE clientes SET
                razao_social = ?, cpf_cnpj = ?, email = ?, telefone = ?,
                logradouro = ?, numero = ?, complemento = ?, bairro = ?,
                codigo_municipio = ?, municipio = ?, uf = ?, cep = ?
             WHERE id = ?'
        )->execute([
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
            $id,
        ]);
    }

    public function desativarCliente(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE clientes SET ativo = 0 WHERE id = ?'
        )->execute([$id]);
    }

    // ─── Serviços ────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarServicos(): array
    {
        return $this->pdo->query(
            'SELECT * FROM servicos WHERE ativo = 1 ORDER BY nome'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarServico(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM servicos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirServico(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO servicos
                (nome, item_lista_servico, codigo_cnae, codigo_tributacao_municipio,
                 discriminacao, aliquota, exigibilidade_iss, iss_retido)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $dados['nome'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarServico(int $id, array $dados): void
    {
        $this->pdo->prepare(
            'UPDATE servicos SET
                nome = ?, item_lista_servico = ?, codigo_cnae = ?,
                codigo_tributacao_municipio = ?, discriminacao = ?, aliquota = ?,
                exigibilidade_iss = ?, iss_retido = ?
             WHERE id = ?'
        )->execute([
            $dados['nome'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $id,
        ]);
    }

    public function desativarServico(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE servicos SET ativo = 0 WHERE id = ?'
        )->execute([$id]);
    }

    // ─── Orçamentos ──────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarOrcamentos(string $status = '', string $busca = ''): array
    {
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($busca !== '') {
            $where[] = 'c.razao_social LIKE ?';
            $params[] = "%{$busca}%";
        }
        $sql = 'SELECT o.*, c.razao_social, c.cpf_cnpj FROM orcamentos o
                JOIN clientes c ON c.id = o.cliente_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarOrcamento(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.razao_social, c.cpf_cnpj,
                    c.email AS cliente_email, c.telefone,
                    c.logradouro, c.numero, c.complemento,
                    c.bairro, c.codigo_municipio, c.uf, c.cep
               FROM orcamentos o
               JOIN clientes c ON c.id = o.cliente_id
              WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirOrcamento(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orcamentos
                (cliente_id, servico_id, competencia, valor_servicos,
                 item_lista_servico, codigo_cnae, codigo_tributacao_municipio,
                 discriminacao, aliquota, exigibilidade_iss, iss_retido,
                 valor_deducoes, valor_pis, valor_cofins, valor_inss,
                 valor_ir, valor_csll, desconto_incondicionado, desconto_condicionado,
                 criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $svcId = (isset($dados['servico_id']) && $dados['servico_id'] !== '')
            ? (int) $dados['servico_id']
            : null;
        $stmt->execute([
            (int) $dados['cliente_id'],
            $svcId,
            $dados['competencia'],
            $dados['valor_servicos'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $dados['valor_deducoes'] ?? null,
            $dados['valor_pis'] ?? null,
            $dados['valor_cofins'] ?? null,
            $dados['valor_inss'] ?? null,
            $dados['valor_ir'] ?? null,
            $dados['valor_csll'] ?? null,
            $dados['desconto_incondicionado'] ?? null,
            $dados['desconto_condicionado'] ?? null,
            (int) $dados['criado_por'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarOrcamento(int $id, array $dados): void
    {
        $svcId = (isset($dados['servico_id']) && $dados['servico_id'] !== '')
            ? (int) $dados['servico_id']
            : null;
        $this->pdo->prepare(
            "UPDATE orcamentos SET
                cliente_id = ?, servico_id = ?, competencia = ?, valor_servicos = ?,
                item_lista_servico = ?, codigo_cnae = ?, codigo_tributacao_municipio = ?,
                discriminacao = ?, aliquota = ?, exigibilidade_iss = ?, iss_retido = ?,
                valor_deducoes = ?, valor_pis = ?, valor_cofins = ?, valor_inss = ?,
                valor_ir = ?, valor_csll = ?, desconto_incondicionado = ?,
                desconto_condicionado = ?
             WHERE id = ? AND status = 'rascunho'"
        )->execute([
            (int) $dados['cliente_id'],
            $svcId,
            $dados['competencia'],
            $dados['valor_servicos'],
            $dados['item_lista_servico'],
            $dados['codigo_cnae'] ?? null,
            $dados['codigo_tributacao_municipio'] ?? null,
            $dados['discriminacao'],
            $dados['aliquota'] ?? null,
            (int) ($dados['exigibilidade_iss'] ?? 1),
            (int) ($dados['iss_retido'] ?? 2),
            $dados['valor_deducoes'] ?? null,
            $dados['valor_pis'] ?? null,
            $dados['valor_cofins'] ?? null,
            $dados['valor_inss'] ?? null,
            $dados['valor_ir'] ?? null,
            $dados['valor_csll'] ?? null,
            $dados['desconto_incondicionado'] ?? null,
            $dados['desconto_condicionado'] ?? null,
            $id,
        ]);
    }

    public function aprovarOrcamento(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos
                SET status = 'aprovado',
                    aprovado_por = ?,
                    aprovado_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'rascunho'"
        )->execute([$usuarioId, $id]);
    }

    public function emitirOrcamento(int $id, int $emissaoId, string $nfseNumero): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos
                SET status      = 'emitido',
                    emissao_id  = ?,
                    nfse_numero = ?,
                    emitido_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'aprovado'"
        )->execute([$emissaoId, $nfseNumero, $id]);
    }

    public function cancelarOrcamento(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE orcamentos SET status = 'cancelado'
              WHERE id = ? AND status IN ('rascunho','aprovado')"
        )->execute([$id]);
    }

    /** @return array<string,mixed> */
    public function estatisticas(): array
    {
        $porStatus = $this->pdo->query(
            'SELECT status, COUNT(*) AS total FROM orcamentos GROUP BY status'
        )->fetchAll();

        $statusMap = ['rascunho' => 0, 'aprovado' => 0, 'emitido' => 0, 'cancelado' => 0];
        foreach ($porStatus as $row) {
            $statusMap[(string) $row['status']] = (int) $row['total'];
        }

        $totalClientes = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM clientes WHERE ativo = 1'
        )->fetchColumn();

        try {
            $ultimasEmissoes = $this->pdo->query(
                "SELECT id, criado_em, nfse_numero, valor_servicos, tomador_razao, status
                   FROM emissoes WHERE status = 'autorizada' ORDER BY id DESC LIMIT 5"
            )->fetchAll();
        } catch (\PDOException) {
            $ultimasEmissoes = [];
        }

        return [
            'total_clientes'   => $totalClientes,
            'orcamentos'       => $statusMap,
            'ultimas_emissoes' => $ultimasEmissoes,
        ];
    }
}
