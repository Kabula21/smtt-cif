<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

final class CardTemplateRenderer
{
    public const TEMPLATE_VERSION = 'CIF-CR80-2026-10-GRAFICA-1MM';

    /** @param array<string,mixed> $d */
    public function render(array $d, string $qrBundle): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dataUri = static fn(string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);

        $photo = $dataUri($e((string)$d['foto_mime']), (string)$d['foto_bytes']);
        $agentSig = $this->signatureDataUri((string)$d['agente_ass_mime'], (string)$d['agente_ass_bytes']);
        $signerSig = $this->signatureDataUri((string)$d['signer_ass_mime'], (string)$d['signer_ass_bytes']);
        $frontBg = $dataUri($e((string)$d['card_front_bg_mime']), (string)$d['card_front_bg_bytes']);
        $backBg = $dataUri($e((string)$d['card_back_bg_mime']), (string)$d['card_back_bg_bytes']);

        $dob = $e($this->date((string)$d['data_nascimento']));
        $admission = $e($d['data_admissao'] ? $this->date((string)$d['data_admissao']) : '—');
        $blood = $e(mb_strtoupper((string)($d['tipo_sanguineo'] ?: '—'), 'UTF-8'));
        $qrUrl = $e((string)$d['qr_url']);
        $backUrl = $e((string)$d['back_url']);
        $code = $e((string)$d['codigo_validacao']);
        $name = $e(mb_strtoupper((string)$d['nome_completo'], 'UTF-8'));
        $registration = $e((string)$d['matricula']);
        $cpf = $e(mb_strtoupper((string)$d['cpf_formatado'], 'UTF-8'));
        $natural = $e(mb_strtoupper((string)$d['naturalidade'], 'UTF-8'));
        $template = $e(self::TEMPLATE_VERSION);
        $issued = $e($this->dateTime((string)$d['emitida_em']));
        $bundle = str_replace('</script', '<\/script', $qrBundle);

        return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>CIF {$code} - impressão CR80</title>
<style>
@page{size:85.60mm 53.98mm;margin:0}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#e8eef2;font-family:Arial,Helvetica,sans-serif;color:#101a1d}
.toolbar{max-width:980px;margin:18px auto;padding:14px 18px;background:#fff;border:1px solid #cbdce5;border-radius:12px;box-shadow:0 5px 22px #173b4b18}
.toolbar button,.toolbar a{display:inline-flex;align-items:center;padding:10px 14px;border:0;border-radius:8px;background:#087fa1;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}
.toolbar a{background:#eef6f9;color:#075d7a;border:1px solid #bfd6df;margin-left:6px}
.toolbar small{display:block;margin-top:9px;color:#607783;line-height:1.4}
.sheet{width:85.60mm;height:53.98mm;margin:15px auto;position:relative;overflow:hidden;background:transparent;box-shadow:0 5px 22px #173b4b25;page-break-after:always;border-radius:3.2mm}
.card-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:fill;display:block;z-index:0}
.layer{position:absolute;z-index:2;background:transparent}
.value{display:flex;align-items:center;padding:0 .35mm;color:#171b1d;font-weight:normal;font-size:6.20pt;line-height:1.05;overflow:hidden;text-align:left;white-space:nowrap;text-overflow:ellipsis;background:transparent!important;border:0!important;outline:0!important;box-shadow:none!important;border-radius:0!important}
.front-photo{left:8.1%;top:37.4%;width:20.6%;height:42.3%;object-fit:cover;border-radius:1.2mm;background:#fff}
.front-name{left:31.5%;top:46.6%;width:44.0%;height:8.9%;font-size:6.20pt;text-transform:uppercase;white-space:normal;line-height:1.02;align-items:flex-start;justify-content:flex-start;text-align:left;padding:.2mm .15mm 0 .15mm;overflow:hidden;text-overflow:clip}
.front-cpf{left:31.5%;top:61.0%;width:21.0%;height:5.6%;font-size:6.20pt;justify-content:flex-start;padding-left:.15mm}
.front-blood{left:55.8%;top:61.0%;width:18.5%;height:5.6%;font-size:6.20pt;justify-content:flex-start;padding-left:.15mm}
.front-admission{left:31.5%;top:75.2%;width:25.0%;height:5.5%;font-size:6.20pt;justify-content:flex-start;padding-left:.15mm}
.front-registration{left:9.7%;top:87.2%;width:20.0%;height:5.5%;font-size:6.20pt;justify-content:center;text-align:center;padding:0}
.front-signature{left:37.7%;top:80.7%;width:34.9%;height:9.5%;object-fit:contain;background:transparent!important;border:0!important;box-shadow:none!important;mix-blend-mode:multiply}
.back-natural{left:3.9%;top:43.7%;width:27.1%;height:5.9%;font-size:6.20pt}
.back-dob{left:34.4%;top:43.7%;width:29.2%;height:5.9%;font-size:6.20pt}
.back-issued{left:3.9%;top:57.7%;width:29.5%;height:6.0%;font-size:6.20pt}
.qrbox{left:73.0%;top:45.1%;width:18.7%;aspect-ratio:1/1;background:#fff;padding:.45mm;display:grid;place-items:center}
.qrbox svg{width:100%;height:100%;display:block}
.back-code{left:70.3%;top:76.5%;width:24.0%;height:4.7%;display:flex;align-items:center;justify-content:center;color:#171b1d;font-size:6.20pt;font-weight:normal;letter-spacing:.14pt;background:transparent!important}
.back-signature{left:30.2%;top:78.0%;width:35.5%;height:11.6%;object-fit:contain;background:transparent!important;border:0!important;box-shadow:none!important;mix-blend-mode:multiply}
.print-note{max-width:85.60mm;margin:0 auto 20px;color:#607783;font-size:11px;text-align:center}
@media print{
 html,body{background:#fff}
 .toolbar,.print-note{display:none!important}
 .sheet{margin:0;box-shadow:none;border-radius:0}
}
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Imprimir / Salvar como PDF</button>
    <a href="{$backUrl}">Voltar à emissão</a>
    <small>Arte CR80 em 85,60 × 53,98 mm. A arte institucional é fixa; fotografia, dados, assinaturas, código e QR Code são aplicados dinamicamente a partir do snapshot imutável desta emissão.</small>
</div>

<section class="sheet" aria-label="Frente da Carteira de Identificação Funcional">
    <img class="card-bg" src="{$frontBg}" alt="">
    <img class="layer front-photo" src="{$photo}" alt="Fotografia do titular">
    <div class="layer value front-name">{$name}</div>
    <div class="layer value front-cpf">{$cpf}</div>
    <div class="layer value front-blood">{$blood}</div>
    <div class="layer value front-admission">{$admission}</div>
    <div class="layer value front-registration">{$registration}</div>
    <img class="layer front-signature" src="{$agentSig}" data-clean-signature="1" alt="Assinatura do titular">
</section>

<section class="sheet" aria-label="Verso da Carteira de Identificação Funcional">
    <img class="card-bg" src="{$backBg}" alt="">
    <div class="layer value back-natural">{$natural}</div>
    <div class="layer value back-dob">{$dob}</div>
    <div class="layer value back-issued">{$issued}</div>
    <div class="layer qrbox" id="smtt-qr" data-url="{$qrUrl}"></div>
    <div class="layer back-code">{$code}</div>
    <img class="layer back-signature" src="{$signerSig}" data-clean-signature="1" alt="Assinatura institucional">
</section>

<div class="print-note">Template {$template} · frente e verso em padrão CR80 · QR exclusivo desta emissão.</div>
<script>{$bundle}</script>
<script>
(function(){
    var el=document.getElementById('smtt-qr');
    if(el&&window.SmttQr){el.innerHTML=window.SmttQr.svg(el.getAttribute('data-url'),{level:'M',quiet:2});}

    function cleanSignature(img){
        if(!img||img.dataset.signatureCleaned==='1'){return;}
        if(!img.complete||!img.naturalWidth||!img.naturalHeight){
            img.addEventListener('load',function(){cleanSignature(img);},{once:true});
            return;
        }
        try{
            var w=img.naturalWidth,h=img.naturalHeight;
            if(w<1||h<1||w*h>2500000){return;}
            var c=document.createElement('canvas');c.width=w;c.height=h;
            var x=c.getContext('2d',{willReadFrequently:true});if(!x){return;}
            x.drawImage(img,0,0);
            var d=x.getImageData(0,0,w,h),p=d.data;
            var minX=w,minY=h,maxX=-1,maxY=-1;
            for(var i=0;i<p.length;i+=4){
                var r=p[i],g=p[i+1],b=p[i+2],a=p[i+3];
                var lo=Math.min(r,g,b),hi=Math.max(r,g,b),chroma=hi-lo;
                if(lo>=246&&chroma<=18){a=0;}
                else if(lo>=225&&chroma<=16){a=Math.min(a,Math.max(0,255-Math.round(((lo-225)/21)*238)));}
                p[i+3]=a;
                if(a>22){var px=(i/4)%w,py=Math.floor((i/4)/w);if(px<minX)minX=px;if(px>maxX)maxX=px;if(py<minY)minY=py;if(py>maxY)maxY=py;}
            }
            x.putImageData(d,0,0);
            if(maxX<minX||maxY<minY){return;}
            var pad=Math.max(2,Math.round(Math.min(w,h)*0.025));
            var sx=Math.max(0,minX-pad),sy=Math.max(0,minY-pad);
            var sw=Math.min(w-sx,(maxX-minX+1)+(pad*2)),sh=Math.min(h-sy,(maxY-minY+1)+(pad*2));
            var out=document.createElement('canvas');out.width=sw;out.height=sh;
            var ox=out.getContext('2d');if(!ox){return;}
            ox.drawImage(c,sx,sy,sw,sh,0,0,sw,sh);
            img.dataset.signatureCleaned='1';
            img.src=out.toDataURL('image/png');
        }catch(e){/* preserva a imagem original como fallback seguro */}
    }
    document.querySelectorAll('[data-clean-signature="1"]').forEach(cleanSignature);
})();
</script>
</body>
</html>
HTML;
    }

    private function signatureDataUri(string $mime, string $bytes): string
    {
        $cleaned = $this->transparentSignaturePng($bytes);
        if ($cleaned !== null) {
            return 'data:image/png;base64,' . base64_encode($cleaned);
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Produz uma cópia derivada apenas para a composição da CIF.
     * O arquivo biométrico original permanece intacto no armazenamento privado.
     */
    private function transparentSignaturePng(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1 || ($width * $height) > 2_500_000) {
            imagedestroy($source);
            return null;
        }

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            imagedestroy($source);
            return null;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);

        $palette = [];
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($source, $x, $y);
                $rgba = imagecolorsforindex($source, $index);
                $r = (int) ($rgba['red'] ?? 255);
                $g = (int) ($rgba['green'] ?? 255);
                $b = (int) ($rgba['blue'] ?? 255);
                $originalAlpha = (int) ($rgba['alpha'] ?? 0);

                $low = min($r, $g, $b);
                $high = max($r, $g, $b);
                $chroma = $high - $low;
                $alpha = $originalAlpha;

                // Remove branco e quase-branco neutro do papel, preservando traços escuros/coloridos.
                if ($low >= 246 && $chroma <= 18) {
                    $alpha = 127;
                } elseif ($low >= 225 && $chroma <= 16) {
                    $fade = (int) round((($low - 225) / 21) * 118);
                    $alpha = max($originalAlpha, min(126, $fade));
                }

                $key = $r . ':' . $g . ':' . $b . ':' . $alpha;
                if (!isset($palette[$key])) {
                    $palette[$key] = imagecolorallocatealpha($canvas, $r, $g, $b, $alpha);
                }
                imagesetpixel($canvas, $x, $y, $palette[$key]);

                if ($alpha < 116) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        imagedestroy($source);

        if ($maxX < $minX || $maxY < $minY) {
            imagedestroy($canvas);
            return null;
        }

        // Recorta o excesso de papel/transparência para a rubrica ocupar corretamente a área da carteira.
        $padding = max(2, (int) round(min($width, $height) * 0.025));
        $cropX = max(0, $minX - $padding);
        $cropY = max(0, $minY - $padding);
        $cropW = min($width - $cropX, ($maxX - $minX + 1) + ($padding * 2));
        $cropH = min($height - $cropY, ($maxY - $minY + 1) + ($padding * 2));

        $output = imagecreatetruecolor($cropW, $cropH);
        if ($output === false) {
            imagedestroy($canvas);
            return null;
        }
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $outTransparent = imagecolorallocatealpha($output, 255, 255, 255, 127);
        imagefill($output, 0, 0, $outTransparent);
        imagecopy($output, $canvas, 0, 0, $cropX, $cropY, $cropW, $cropH);
        imagedestroy($canvas);

        ob_start();
        $written = imagepng($output, null, 9);
        $png = ob_get_clean();
        imagedestroy($output);

        return $written && is_string($png) && $png !== '' ? $png : null;
    }

    private function date(string $value): string
    {
        $ts = strtotime($value . ' UTC');
        return $ts === false ? '—' : gmdate('d/m/Y', $ts);
    }

    private function dateTime(string $value): string
    {
        $ts = strtotime($value . ' UTC');
        return $ts === false ? '—' : gmdate('d/m/Y', $ts);
    }
}
