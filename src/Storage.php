<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Persistência local em SQLite:
 *  - controle da numeração sequencial de RPS (com transação, evita duplicidade)
 *  - registro de cada emissão (XML enviado, resposta, número da NFS-e, código de verificação)
 */
final class Storage
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS rps_sequencia (
                serie TEXT PRIMARY KEY,
                ultimo_numero INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS emissoes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                rps_numero INTEGER NOT NULL,
                rps_serie TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'enviando',
                nfse_numero TEXT,
                codigo_verificacao TEXT,
                valor_servicos TEXT,
                tomador_doc TEXT,
                tomador_razao TEXT,
                xml_envio TEXT,
                xml_retorno TEXT,
                erro TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_emissoes_nfse ON emissoes (nfse_numero);
            CREATE INDEX IF NOT EXISTS idx_emissoes_rps  ON emissoes (rps_numero, rps_serie);
        SQL);
    }

    /**
     * Reserva o próximo número de RPS da série, de forma atômica.
     */
    public function proximoRps(string $serie): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO rps_sequencia (serie, ultimo_numero) VALUES (?, 0)
                 ON CONFLICT(serie) DO NOTHING'
            )->execute([$serie]);

            $this->pdo->prepare(
                'UPDATE rps_sequencia SET ultimo_numero = ultimo_numero + 1 WHERE serie = ?'
            )->execute([$serie]);

            $stmt = $this->pdo->prepare('SELECT ultimo_numero FROM rps_sequencia WHERE serie = ?');
            $stmt->execute([$serie]);
            $numero = (int) $stmt->fetchColumn();

            $this->pdo->commit();
            return $numero;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Define manualmente o último número de RPS usado (ex.: migração de sistema). */
    public function definirUltimoRps(string $serie, int $numero): void
    {
        $this->pdo->prepare(
            'INSERT INTO rps_sequencia (serie, ultimo_numero) VALUES (?, ?)
             ON CONFLICT(serie) DO UPDATE SET ultimo_numero = excluded.ultimo_numero'
        )->execute([$serie, $numero]);
    }

    public function registrarEnvio(
        int $rpsNumero,
        string $rpsSerie,
        string $valorServicos,
        string $tomadorDoc,
        string $tomadorRazao,
        string $xmlEnvio
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO emissoes
                (rps_numero, rps_serie, valor_servicos, tomador_doc, tomador_razao, xml_envio)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$rpsNumero, $rpsSerie, $valorServicos, $tomadorDoc, $tomadorRazao, $xmlEnvio]);
        return (int) $this->pdo->lastInsertId();
    }

    public function registrarSucesso(int $id, string $nfseNumero, string $codVerificacao, string $xmlRetorno): void
    {
        $this->pdo->prepare(
            "UPDATE emissoes
                SET status = 'autorizada', nfse_numero = ?, codigo_verificacao = ?, xml_retorno = ?
              WHERE id = ?"
        )->execute([$nfseNumero, $codVerificacao, $xmlRetorno, $id]);
    }

    public function registrarErro(int $id, string $erro, ?string $xmlRetorno = null): void
    {
        $this->pdo->prepare(
            "UPDATE emissoes SET status = 'erro', erro = ?, xml_retorno = ? WHERE id = ?"
        )->execute([$erro, $xmlRetorno, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listar(int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, criado_em, rps_numero, rps_serie, status, nfse_numero,
                    codigo_verificacao, valor_servicos, tomador_doc, tomador_razao, erro
               FROM emissoes ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscarPorRps(int $numero, string $serie): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM emissoes WHERE rps_numero = ? AND rps_serie = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$numero, $serie]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
