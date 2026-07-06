<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Carrega a configuração a partir do arquivo .env na raiz do projeto.
 * Implementação própria e minimalista para evitar dependências extras.
 */
final class Config
{
    /** @var array<string,string> */
    private array $data = [];

    public readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__);
        $envFile = $this->baseDir . '/.env';
        if (!is_file($envFile)) {
            throw new \RuntimeException(
                "Arquivo .env não encontrado em {$this->baseDir}. " .
                'Copie .env.example para .env e preencha os dados.'
            );
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $value = trim($value, "\"'");
            $this->data[$key] = $value;
        }
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->data[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Configuração obrigatória ausente no .env: {$key}");
        }
        return $value;
    }

    public function getInt(string $key, int $default): int
    {
        return (int) ($this->data[$key] ?? $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = strtolower($this->data[$key] ?? ($default ? '1' : '0'));
        return in_array($v, ['1', 'true', 'yes', 'sim'], true);
    }

    /** Resolve um caminho relativo à raiz do projeto. */
    public function path(string $key, string $default): string
    {
        $p = $this->data[$key] ?? $default;
        if (!str_starts_with($p, '/')) {
            $p = $this->baseDir . '/' . $p;
        }
        return $p;
    }
}
