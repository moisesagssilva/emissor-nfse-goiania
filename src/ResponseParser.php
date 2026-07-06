<?php

declare(strict_types=1);

namespace EmissorGyn;

/**
 * Interpreta os XMLs de retorno do padrão ABRASF 2.04.
 */
final class ResponseParser
{
    /**
     * @return array{sucesso:bool, nfse_numero:?string, codigo_verificacao:?string,
     *               data_emissao:?string, erros:array<int,array{codigo:string,mensagem:string,correcao:string}>}
     */
    public static function parseGerarNfse(string $xml): array
    {
        $dom = self::load($xml);
        $erros = self::listarMensagens($dom);

        $numero = self::texto($dom, 'Numero', 'InfNfse');
        $codVerif = self::texto($dom, 'CodigoVerificacao');
        $dataEmissao = self::texto($dom, 'DataEmissao', 'InfNfse');

        return [
            'sucesso' => $numero !== null && empty($erros),
            'nfse_numero' => $numero,
            'codigo_verificacao' => $codVerif,
            'data_emissao' => $dataEmissao,
            'erros' => $erros,
        ];
    }

    /**
     * @return array{sucesso:bool, data_hora:?string, erros:array<int,array{codigo:string,mensagem:string,correcao:string}>}
     */
    public static function parseCancelamento(string $xml): array
    {
        $dom = self::load($xml);
        $erros = self::listarMensagens($dom);
        $dataHora = self::texto($dom, 'DataHora');
        return [
            'sucesso' => $dataHora !== null && empty($erros),
            'data_hora' => $dataHora,
            'erros' => $erros,
        ];
    }

    /** @return array<int,array{codigo:string,mensagem:string,correcao:string}> */
    public static function listarMensagens(\DOMDocument $dom): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('MensagemRetorno') as $msg) {
            $item = ['codigo' => '', 'mensagem' => '', 'correcao' => ''];
            foreach ($msg->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                match ($child->localName) {
                    'Codigo' => $item['codigo'] = trim($child->textContent),
                    'Mensagem' => $item['mensagem'] = trim($child->textContent),
                    'Correcao' => $item['correcao'] = trim($child->textContent),
                    default => null,
                };
            }
            if ($item['codigo'] !== '' || $item['mensagem'] !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Formata as mensagens de erro para exibição. */
    public static function formatarErros(array $erros): string
    {
        $linhas = [];
        foreach ($erros as $e) {
            $l = "[{$e['codigo']}] {$e['mensagem']}";
            if (!empty($e['correcao'])) {
                $l .= " | Correção: {$e['correcao']}";
            }
            $linhas[] = $l;
        }
        return implode(PHP_EOL, $linhas);
    }

    public static function load(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('XML de retorno inválido: ' . substr($xml, 0, 500));
        }
        return $dom;
    }

    /**
     * Retorna o texto do primeiro elemento $tag; se $dentroDe for informado,
     * busca apenas dentro do primeiro elemento com esse nome.
     */
    private static function texto(\DOMDocument $dom, string $tag, ?string $dentroDe = null): ?string
    {
        $contexto = $dom;
        if ($dentroDe !== null) {
            $pai = $dom->getElementsByTagName($dentroDe)->item(0);
            if ($pai === null) {
                return null;
            }
            $contexto = $pai;
        }
        $el = $contexto->getElementsByTagName($tag)->item(0);
        $v = $el !== null ? trim($el->textContent) : '';
        return $v === '' ? null : $v;
    }
}
