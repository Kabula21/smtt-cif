<?php
/** @var array{nome:string,cpf:string,matricula:string,codigo:string,tipo:string,data_emissao:string} $term */
/** @var string $logoDataUri */
$e = static fn(string $value): string => esc_html($value);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Termo de Entrega CIF - <?= $e($term['codigo']) ?></title>
<style>
@page { size: A4 portrait; margin: 18mm 22mm 18mm 22mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #edf2f4; color: #182a36; font-family: Arial, Helvetica, sans-serif; }
.toolbar { max-width: 210mm; margin: 12px auto; text-align: right; }
.toolbar button { padding: 10px 18px; border: 0; border-radius: 5px; background: #057fa6; color: white; font-weight: bold; cursor: pointer; }
.paper { width: 210mm; min-height: 297mm; margin: 0 auto 18px; padding: 18mm 22mm; background: white; box-shadow: 0 4px 24px #283b4f23; }
.head { display: flex; align-items: center; gap: 15mm; border-bottom: 1px solid #8b959b; padding-bottom: 9mm; }
.head img { width: 37mm; height: auto; flex-shrink: 0; }
.head .institucional { font-family: Georgia, 'Times New Roman', serif; font-weight: bold; font-size: 8pt; line-height: 1.32; text-transform: uppercase; color: #435464; }
h1 { margin: 17mm 0 19mm; font-size: 15pt; line-height: 1.28; text-align: center; color: #0d2536; }
.declaracao { margin: 0; font-size: 12pt; line-height: 1.9; text-align: left; text-indent: 15mm; }
.declaracao strong { font-weight: 700; }
.compromisso { margin-top: 9mm; }
.local { margin-top: 24mm; text-align: right; font-size: 11.3pt; }
.assinaturas { display: flex; justify-content: space-between; gap: 14mm; margin-top: 33mm; }
.assinatura { flex: 1; min-width: 0; text-align: center; }
.assinatura .linha { border-top: 1px solid #404e56; }
.assinatura .rotulo { margin-top: 3mm; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
@media print { body { background: white; } .toolbar { display: none; } .paper { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; } }
@media screen and (max-width: 820px) { .paper { width: 100%; padding: 8%; min-height: 100vh; } .head img { width: 30%; } .head { gap: 5%; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Imprimir / salvar em PDF</button></div>
<main class="paper">
  <header class="head">
    <img src="<?= esc_attr($logoDataUri) ?>" alt="SMTT Aracaju">
    <div class="institucional">Estado de Sergipe<br>Prefeitura Municipal de Aracaju<br>Superintendência Municipal de Transportes e Trânsito - S.M.T.T.</div>
  </header>
  <h1>TERMO DE ENTREGA DA CARTEIRA DE<br>IDENTIFICAÇÃO FUNCIONAL</h1>
  <p class="declaracao">Eu, <strong><?= $e($term['nome']) ?></strong>, inscrito(a) no CPF sob o nº <strong><?= $e($term['cpf']) ?></strong>, matrícula funcional nº <strong><?= $e($term['matricula']) ?></strong>, declaro, para os devidos fins, que recebi da Superintendência Municipal de Transportes e Trânsito de Aracaju - SMTT a <strong>Carteira de Identificação Funcional (CIF)</strong>, identificada pelo código <strong><?= $e($term['codigo']) ?></strong>, referente à <strong><?= $e($term['tipo']) ?></strong>, emitida em <strong><?= $e($term['data_emissao']) ?></strong>. Declaro, ainda, que conferi os dados constantes da carteira e recebi o documento em condições adequadas de uso.</p>
  <p class="declaracao compromisso">Comprometo-me a zelar pela guarda e conservação da carteira, a utilizá-la exclusivamente para as finalidades institucionais e a comunicar à SMTT eventual perda, extravio, dano ou qualquer outra circunstância que exija providências administrativas, conforme as normas institucionais aplicáveis.</p>
  <p class="local">Aracaju/SE, ______ de ________________________ de __________.</p>
  <div class="assinaturas">
    <div class="assinatura"><div class="linha"></div><div class="rotulo">Assinatura do agente recebedor</div></div>
    <div class="assinatura"><div class="linha"></div><div class="rotulo">Assinatura do responsável RH-SMTT</div></div>
  </div>
</main>
</body>
</html>
