<?php

declare(strict_types=1);

namespace EmissorGyn;

final class NfeStorage
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

    /** Somente para testes — permite criar tabelas de dependências in-memory. */
    public function getPdoForTest(): \PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rps_sequencia (
                serie         TEXT PRIMARY KEY,
                ultimo_numero INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS pedidos (
                id                     INTEGER PRIMARY KEY AUTOINCREMENT,
                cliente_id             INTEGER NOT NULL,
                status                 TEXT    NOT NULL DEFAULT 'rascunho',
                natureza_operacao      TEXT    NOT NULL DEFAULT 'Venda de mercadoria',
                consumidor_final       INTEGER NOT NULL DEFAULT 0,
                presenca               INTEGER NOT NULL DEFAULT 1,
                informacoes_adicionais TEXT,
                nfe_chave              TEXT,
                nfe_numero             INTEGER,
                nfe_serie              TEXT,
                nfe_protocolo          TEXT,
                nfe_xml_autorizado     TEXT,
                criado_por             INTEGER,
                aprovado_por           INTEGER,
                criado_em              TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                aprovado_em            TEXT,
                emitido_em             TEXT,
                cancelado_em           TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_pedidos_cliente ON pedidos (cliente_id);
            CREATE INDEX IF NOT EXISTS idx_pedidos_status  ON pedidos (status);
            CREATE INDEX IF NOT EXISTS idx_pedidos_chave   ON pedidos (nfe_chave);
            CREATE TABLE IF NOT EXISTS pedido_itens (
                id                          INTEGER PRIMARY KEY AUTOINCREMENT,
                pedido_id                   INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
                numero_item                 INTEGER NOT NULL,
                codigo_produto              TEXT,
                descricao                   TEXT    NOT NULL,
                ncm                         TEXT    NOT NULL,
                cfop                        TEXT    NOT NULL,
                unidade                     TEXT    NOT NULL DEFAULT 'UN',
                quantidade                  TEXT    NOT NULL,
                valor_unitario              TEXT    NOT NULL,
                valor_desconto              TEXT,
                csosn                       TEXT    NOT NULL DEFAULT '400',
                pis_cst                     TEXT    NOT NULL DEFAULT '07',
                cofins_cst                  TEXT    NOT NULL DEFAULT '07',
                informacoes_adicionais_item TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_pedido_itens_pedido ON pedido_itens (pedido_id);
            CREATE TABLE IF NOT EXISTS pedido_eventos (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                pedido_id   INTEGER NOT NULL REFERENCES pedidos(id),
                tipo        TEXT    NOT NULL,
                protocolo   TEXT,
                descricao   TEXT,
                xml_evento  TEXT,
                xml_retorno TEXT,
                criado_em   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            );
        SQL);
    }

    // ─── Numeração ───────────────────────────────────────────────────────────

    public function proximoNfe(string $serie): int
    {
        $chave = "nfe:{$serie}";
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO rps_sequencia (serie, ultimo_numero) VALUES (?, 1)
                 ON CONFLICT(serie) DO UPDATE SET ultimo_numero = ultimo_numero + 1'
            )->execute([$chave]);
            $stmt = $this->pdo->prepare(
                'SELECT ultimo_numero FROM rps_sequencia WHERE serie = ?'
            );
            $stmt->execute([$chave]);
            $n = (int) $stmt->fetchColumn();
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function definirUltimoNfe(string $serie, int $ultimo): void
    {
        $this->pdo->prepare(
            'UPDATE rps_sequencia SET ultimo_numero = ? WHERE serie = ?'
        )->execute([$ultimo, "nfe:{$serie}"]);
    }

    // ─── Pedidos ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarPedidos(string $status = '', string $busca = ''): array
    {
        $where  = [];
        $params = [];
        if ($status !== '') {
            $where[]  = 'p.status = ?';
            $params[] = $status;
        }
        if ($busca !== '') {
            $where[]  = 'c.razao_social LIKE ?';
            $params[] = "%{$busca}%";
        }
        $sql = <<<'SQL'
            SELECT p.*,
                   c.razao_social, c.cpf_cnpj,
                   ROUND(COALESCE((
                       SELECT SUM(CAST(pi.quantidade AS REAL) * CAST(pi.valor_unitario AS REAL)
                                  - COALESCE(CAST(pi.valor_desconto AS REAL), 0))
                       FROM pedido_itens pi WHERE pi.pedido_id = p.id
                   ), 0), 2) AS valor_total,
                   (SELECT COUNT(*) FROM pedido_itens pi WHERE pi.pedido_id = p.id) AS total_itens
              FROM pedidos p
              JOIN clientes c ON c.id = p.cliente_id
        SQL;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function buscarPedido(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.razao_social, c.cpf_cnpj
               FROM pedidos p
               JOIN clientes c ON c.id = p.cliente_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $dados */
    public function inserirPedido(array $dados): int
    {
        $this->pdo->prepare(
            'INSERT INTO pedidos
                (cliente_id, natureza_operacao, consumidor_final, presenca,
                 informacoes_adicionais, criado_por)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $dados['cliente_id'],
            $dados['natureza_operacao'] ?? 'Venda de mercadoria',
            (int) ($dados['consumidor_final'] ?? 0),
            (int) ($dados['presenca'] ?? 1),
            $dados['informacoes_adicionais'] ?? null,
            (int) $dados['criado_por'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $dados */
    public function atualizarPedido(int $id, array $dados): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos SET
                cliente_id = ?, natureza_operacao = ?, consumidor_final = ?,
                presenca = ?, informacoes_adicionais = ?
             WHERE id = ? AND status = 'rascunho'"
        )->execute([
            (int) $dados['cliente_id'],
            $dados['natureza_operacao'] ?? 'Venda de mercadoria',
            (int) ($dados['consumidor_final'] ?? 0),
            (int) ($dados['presenca'] ?? 1),
            $dados['informacoes_adicionais'] ?? null,
            $id,
        ]);
    }

    public function aprovarPedido(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status = 'aprovado',
                    aprovado_por = ?,
                    aprovado_em  = datetime('now','localtime')
              WHERE id = ? AND status = 'rascunho'"
        )->execute([$usuarioId, $id]);
    }

    public function emitirPedido(
        int $id,
        string $chave,
        int $numero,
        string $serie,
        string $protocolo,
        string $xmlAutorizado
    ): void {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status             = 'emitido',
                    nfe_chave          = ?,
                    nfe_numero         = ?,
                    nfe_serie          = ?,
                    nfe_protocolo      = ?,
                    nfe_xml_autorizado = ?,
                    emitido_em         = datetime('now','localtime')
              WHERE id = ? AND status = 'aprovado'"
        )->execute([$chave, $numero, $serie, $protocolo, $xmlAutorizado, $id]);
    }

    public function cancelarPedido(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE pedidos
                SET status = 'cancelado',
                    cancelado_em = datetime('now','localtime')
              WHERE id = ?"
        )->execute([$id]);
    }

    // ─── Itens ───────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listarItens(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pedido_itens WHERE pedido_id = ? ORDER BY numero_item'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    /** @param array<int,array<string,mixed>> $itens */
    public function substituirItens(int $pedidoId, array $itens): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM pedido_itens WHERE pedido_id = ?'
            )->execute([$pedidoId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO pedido_itens
                    (pedido_id, numero_item, codigo_produto, descricao, ncm, cfop,
                     unidade, quantidade, valor_unitario, valor_desconto,
                     csosn, pis_cst, cofins_cst, informacoes_adicionais_item)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($itens as $item) {
                $stmt->execute([
                    $pedidoId,
                    (int) $item['numero_item'],
                    $item['codigo_produto'] ?? null,
                    $item['descricao'],
                    $item['ncm'],
                    $item['cfop'],
                    $item['unidade'] ?? 'UN',
                    $item['quantidade'],
                    $item['valor_unitario'],
                    $item['valor_desconto'] ?? null,
                    $item['csosn'] ?? '400',
                    $item['pis_cst'] ?? '07',
                    $item['cofins_cst'] ?? '07',
                    $item['informacoes_adicionais_item'] ?? null,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ─── Eventos ─────────────────────────────────────────────────────────────

    public function registrarEvento(
        int $pedidoId,
        string $tipo,
        string $protocolo,
        string $descricao,
        string $xmlEvento,
        string $xmlRetorno
    ): void {
        $this->pdo->prepare(
            'INSERT INTO pedido_eventos
                (pedido_id, tipo, protocolo, descricao, xml_evento, xml_retorno)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pedidoId, $tipo, $protocolo, $descricao, $xmlEvento, $xmlRetorno]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listarEventos(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pedido_eventos WHERE pedido_id = ? ORDER BY id'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    // ─── Estatísticas ────────────────────────────────────────────────────────

    /** @return array<string,int> */
    public function estatisticas(): array
    {
        $map = ['rascunho' => 0, 'aprovado' => 0, 'emitido' => 0, 'cancelado' => 0];
        try {
            $rows = $this->pdo->query(
                'SELECT status, COUNT(*) AS total FROM pedidos GROUP BY status'
            )->fetchAll();
            foreach ($rows as $row) {
                $map[(string) $row['status']] = (int) $row['total'];
            }
        } catch (\PDOException) {
        }
        return $map;
    }
}
