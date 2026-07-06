<?php

/**
 * Entry point da interface web Lumina — router e bootstrap.
 */

declare(strict_types=1);

use EmissorGyn\Auth;
use EmissorGyn\Cadastro;
use EmissorGyn\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$config   = new Config(dirname(__DIR__));
$cadastro = new Cadastro($config->path('DB_PATH', 'storage/nfse.sqlite'));
$auth     = new Auth($cadastro);
$auth->iniciarSessao();

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$p = preg_replace('/[^a-z\/]/', '', strtolower(trim($_GET['p'] ?? '')));

$rotas = [
    ''                => 'dashboard',
    'login'           => 'login',
    'clientes'        => 'clientes/index',
    'clientes/form'   => 'clientes/form',
    'servicos'        => 'servicos/index',
    'servicos/form'   => 'servicos/form',
    'orcamentos'      => 'orcamentos/index',
    'orcamentos/form' => 'orcamentos/form',
    'orcamentos/ver'  => 'orcamentos/ver',
];

$pagina = $rotas[$p] ?? null;
if ($pagina === null) {
    http_response_code(404);
    exit('Página não encontrada.');
}

if ($pagina !== 'login') {
    $auth->guard();
}

define('PAGES_DIR', __DIR__ . '/pages');

require __DIR__ . '/pages/' . $pagina . '.php';
