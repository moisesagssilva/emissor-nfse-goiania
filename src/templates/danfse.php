<?php
/**
 * Template do DANFS-e (comprovante próprio, não é o layout oficial pixel-a-pixel
 * do ISSNet). Recebe $d (array de Danfse::extrair()) e $logoDataUri em escopo.
 * Sem lógica de negócio aqui — só apresentação.
 */
declare(strict_types=1);

/** @var array<string,mixed> $d */
/** @var string $logoDataUri */

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    .caixa { border: 1px solid #000; margin-bottom: 4px; }
    .titulo { background: #e5e5e5; font-weight: bold; padding: 3px 5px; font-size: 10px; }
    .conteudo { padding: 5px; }
    .cabecalho-topo td { vertical-align: top; }
    .cabecalho-topo .prefeitura { font-size: 12px; font-weight: bold; }
    .cabecalho-topo .doc-info { text-align: right; font-size: 9px; }
    .campo-label { font-size: 7px; color: #444; text-transform: uppercase; }
    .campo-valor { font-size: 9px; margin-bottom: 3px; }
    .tabela-tributos td, .tabela-tributos th {
        border: 1px solid #000; padding: 2px 4px; font-size: 8px; text-align: left;
    }
    .logo { max-height: 50px; max-width: 90px; }
    .destaque { font-weight: bold; }
</style>
</head>
<body>

<table class="cabecalho-topo caixa">
    <tr>
        <td class="conteudo" style="width: 60%;">
            <div class="prefeitura">Prefeitura Municipal de Goiânia - GO</div>
            <div>Secretaria Municipal da Fazenda</div>
            <div>Fone: (62) 35243335 - https://www.goiania.go.gov.br/</div>
        </td>
        <td class="conteudo doc-info">
            <div>Série do Documento</div>
            <div class="destaque">Nota Fiscal de Serviço Eletrônica - NFS-e</div>
            <div>Número da Nota Fiscal</div>
            <div class="destaque"><?= $h($d['numero']) ?></div>
        </td>
    </tr>
</table>

<div class="caixa">
    <div class="titulo">Dados do Prestador de Serviço</div>
    <table class="conteudo">
        <tr>
            <td style="width: 15%;">
                <?php if ($logoDataUri !== '') : ?>
                <img class="logo" src="<?= $h($logoDataUri) ?>">
                <?php endif; ?>
            </td>
            <td style="width: 55%;">
                <div class="destaque"><?= $h($d['prestador']['razao_social']) ?></div>
                <?php if ($d['prestador']['nome_fantasia'] !== '') : ?>
                <div><?= $h($d['prestador']['nome_fantasia']) ?></div>
                <?php endif; ?>
                <div><?= $h($d['prestador']['endereco']) ?></div>
                <div>CEP <?= $h($d['prestador']['cep']) ?> - Fone: <?= $h($d['prestador']['telefone']) ?> - <?= $h($d['prestador']['municipio_uf']) ?></div>
                <div><?= $h($d['prestador']['email']) ?></div>
                <div>Inscrição Municipal <?= $h($d['prestador']['inscricao_municipal']) ?> - CPF/CNPJ <?= $h($d['prestador']['cnpj']) ?></div>
            </td>
            <td style="width: 30%;">
                <div class="campo-label">Data de Geração da NFS-e</div>
                <div class="campo-valor destaque"><?= $h($d['data_emissao']) ?></div>
                <div class="campo-label">Data de Competência</div>
                <div class="campo-valor destaque"><?= $h($d['competencia']) ?></div>
                <div class="campo-label">Cód. de Autenticidade</div>
                <div class="campo-valor destaque"><?= $h($d['codigo_verificacao']) ?></div>
                <div class="campo-label">Responsável pela Retenção</div>
                <div class="campo-valor destaque"><?= $h($d['responsavel_retencao']) ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="caixa">
    <div class="titulo">Identificação da Nota Fiscal Eletrônica</div>
    <table class="conteudo">
        <tr>
            <td>
                <div class="campo-label">Natureza da Operação</div>
                <div class="campo-valor"><?= $h($d['natureza_operacao']) ?></div>
            </td>
            <td>
                <div class="campo-label">Número do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_numero']) ?></div>
            </td>
            <td>
                <div class="campo-label">Série do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_serie']) ?></div>
            </td>
            <td>
                <div class="campo-label">Data de Emissão do RPS</div>
                <div class="campo-valor"><?= $h($d['rps_data_emissao']) ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="campo-label">Local dos Serviços</div>
                <div class="campo-valor"><?= $h($d['local_servicos']) ?></div>
            </td>
            <td colspan="2">
                <div class="campo-label">Município Incidência</div>
                <div class="campo-valor"><?= $h($d['municipio_incidencia']) ?></div>
            </td>
        </tr>
    </table>
</div>

<div class="caixa">
    <div class="titulo">Dados do Tomador de Serviços</div>
    <table class="conteudo">
        <tr>
            <td style="width: 50%;">
                <div><span class="campo-label">CNPJ/CPF:</span> <?= $h($d['tomador']['cnpj_cpf']) ?></div>
                <div><span class="campo-label">IM:</span> <?= $h($d['tomador']['inscricao_municipal']) ?></div>
                <div><span class="campo-label">Razão Social:</span> <?= $h($d['tomador']['razao_social']) ?></div>
                <div><span class="campo-label">Endereço:</span> <?= $h($d['tomador']['endereco']) ?></div>
                <div><span class="campo-label">Complemento:</span> <?= $h($d['tomador']['complemento']) ?></div>
                <div><span class="campo-label">CEP:</span> <?= $h($d['tomador']['cep']) ?></div>
            </td>
            <td style="width: 50%;">
                <div><span class="campo-label">Número:</span> <?= $h($d['tomador']['numero']) ?></div>
                <div><span class="campo-label">Bairro:</span> <?= $h($d['tomador']['bairro']) ?></div>
                <div><span class="campo-label">Cidade/UF:</span> <?= $h($d['tomador']['cidade_uf']) ?></div>
                <div><span class="campo-label">Telefone:</span> <?= $h($d['tomador']['telefone']) ?></div>
                <div><span class="campo-label">Email:</span> <?= $h($d['tomador']['email']) ?></div>
            </td>
        </tr>
    </table>
</div>

<?php if ($d['intermediario'] !== []) : ?>
<div class="caixa">
    <div class="titulo">Dados do Intermediário de Serviços</div>
    <table class="conteudo">
        <tr>
            <td><span class="campo-label">CNPJ/CPF:</span> <?= $h($d['intermediario']['cnpj_cpf']) ?></td>
            <td><span class="campo-label">Inscrição Municipal:</span> <?= $h($d['intermediario']['inscricao_municipal']) ?></td>
            <td><span class="campo-label">Razão Social:</span> <?= $h($d['intermediario']['razao_social']) ?></td>
        </tr>
    </table>
</div>
<?php endif; ?>

<div class="caixa">
    <div class="titulo">Descrição dos Serviços</div>
    <div class="conteudo"><?= nl2br($h($d['discriminacao'])) ?></div>
</div>

<div class="caixa">
    <div class="titulo">Detalhamento dos Tributos</div>
    <table class="tabela-tributos">
        <tr>
            <th>Atividade do Município</th>
            <th>Alíquota</th>
            <th>Item da LC116/2003</th>
            <th>Cód. NBS</th>
            <th>Cód. CNAE</th>
        </tr>
        <tr>
            <td><?= $h($d['atividade_municipio']) ?></td>
            <td><?= $h($d['aliquota']) ?></td>
            <td><?= $h($d['item_lista_servico']) ?></td>
            <td><?= $h($d['codigo_nbs']) ?></td>
            <td><?= $h($d['codigo_cnae']) ?></td>
        </tr>
        <tr>
            <th>Vl. Total dos Serviços</th>
            <th>Desconto Incondicionado</th>
            <th>Deduções Base Cálculo</th>
            <th>Base de Cálculo</th>
            <th></th>
        </tr>
        <tr>
            <td class="destaque"><?= $h($d['valor_total_servicos']) ?></td>
            <td><?= $h($d['desconto_incondicionado']) ?></td>
            <td><?= $h($d['deducoes_base_calculo']) ?></td>
            <td class="destaque"><?= $h($d['base_calculo']) ?></td>
            <td></td>
        </tr>
        <tr>
            <th>Total do ISSQN</th>
            <th>ISSQN Retido</th>
            <th>Desconto Condicionado</th>
            <th colspan="2"></th>
        </tr>
        <tr>
            <td><?= $h($d['total_issqn']) ?></td>
            <td><?= $h($d['issqn_retido']) ?></td>
            <td><?= $h($d['desconto_condicionado']) ?></td>
            <td colspan="2"></td>
        </tr>
        <tr>
            <th>PIS</th>
            <th>COFINS</th>
            <th>INSS</th>
            <th>IRRF</th>
            <th>CSLL</th>
        </tr>
        <tr>
            <td><?= $h($d['pis']) ?></td>
            <td><?= $h($d['cofins']) ?></td>
            <td><?= $h($d['inss']) ?></td>
            <td><?= $h($d['irrf']) ?></td>
            <td><?= $h($d['csll']) ?></td>
        </tr>
        <tr>
            <th>Outras Retenções</th>
            <th>Vl. ISSQN Retido</th>
            <th colspan="3">Vl. Líquido da Nota Fiscal</th>
        </tr>
        <tr>
            <td><?= $h($d['outras_retencoes']) ?></td>
            <td><?= $h($d['valor_issqn_retido']) ?></td>
            <td colspan="3" class="destaque"><?= $h($d['valor_liquido_nota']) ?></td>
        </tr>
    </table>
</div>

<?php if ($d['informacoes_adicionais'] !== '') : ?>
<div class="caixa">
    <div class="titulo">Informações Adicionais</div>
    <div class="conteudo"><?= nl2br($h($d['informacoes_adicionais'])) ?></div>
</div>
<?php endif; ?>

<p style="text-align: center; font-size: 8px;">
    Consulte a autenticidade deste documento (código <?= $h($d['codigo_verificacao']) ?>) em:
    https://www.issnetonline.com.br/goiania/online/
</p>

</body>
</html>
