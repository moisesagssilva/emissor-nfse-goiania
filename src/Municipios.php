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
