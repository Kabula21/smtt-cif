<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Service;

use InvalidArgumentException;
use RuntimeException;
use SMTT\CIF\Gestao\Audit\AuditLogger;
use SMTT\CIF\Gestao\Database\TransactionManager;
use SMTT\CIF\Gestao\Repository\ArtifactRepository;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Security\DataProtection;
use SMTT\CIF\Gestao\Storage\PrivateStorage;
use SMTT\CIF\Gestao\Support\Uuid;

final class CardArtifactService
{
    private const CONFIG_URL='public.validation_base_url';
    private const ARTIFACT_TYPE='ARTE_IMPRESSAO_HTML';

    public function __construct(
        private readonly ArtifactRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly PrivateStorage $storage,
        private readonly DataProtection $protection,
        private readonly CardTemplateRenderer $renderer,
        private readonly string $qrBundlePath
    ) {}

    public function validationBaseUrl(): ?string
    {
        $value=$this->repository->configuration(self::CONFIG_URL);
        return is_string($value)&&$value!==''?$value:null;
    }

    public function setValidationBaseUrl(string $url, CifUser $actor): string
    {
        $url=$this->normalizeBaseUrl($url);
        $old=$this->validationBaseUrl();
        $this->repository->setConfiguration(self::CONFIG_URL,$url,'URL base oficial usada para formar o destino do QR das novas emissões/artefatos.',$actor->id);
        $this->audit->log('CONFIGURACAO_URL_VALIDACAO_ALTERADA','configuracao',null,null,$actor,'WEB',['url'=>$old],['url'=>$url]);
        return $url;
    }

    /** @return array<string,mixed> */
    public function summary(string $emissionUuid): array
    {
        $emission=$this->repository->emissionAssetsByUuid($emissionUuid);
        if($emission===null){throw new RuntimeException('Emissão CIF não localizada para geração do artefato.');}
        $destination=$this->repository->qrDestination((int)$emission['id']);
        $artifact=$this->repository->latestArtifact((int)$emission['id'],self::ARTIFACT_TYPE);
        return [
            'qr_base_url'=>$destination['base_url']??null,
            'qr_url'=>$destination? $this->buildQrUrl((string)$destination['base_url'],$this->decryptToken($emission)) : null,
            'artifact'=>$artifact,
            'prints'=>$this->repository->printAttempts((int)$emission['id']),
        ];
    }

    /** @return array<string,mixed> */
    public function generate(string $emissionUuid, CifUser $actor): array
    {
        $emission=$this->repository->emissionAssetsByUuid($emissionUuid);
        if($emission===null){throw new RuntimeException('Emissão CIF não localizada.');}
        $existing=$this->repository->latestArtifact((int)$emission['id'],self::ARTIFACT_TYPE);
        if($existing!==null && (string)($existing['template_version']??'')===CardTemplateRenderer::TEMPLATE_VERSION){
            return $existing;
        }
        if((string)$emission['status_fisico']==='INUTILIZADA'){
            throw new RuntimeException('Não é permitido gerar arte de impressão para uma CIF inutilizada.');
        }
        if($existing!==null && (string)$emission['status_fisico']!=='NAO_PRODUZIDA'){
            // Depois da produção física, preservamos exatamente o template usado.
            return $existing;
        }
        $token=$this->decryptToken($emission);
        $base=$this->transactions->run(function() use($emission,$actor,$token):string{
            $this->repository->lockEmission((int)$emission['id']);
            $current=$this->repository->lockQrDestination((int)$emission['id']);
            if($current!==null){return (string)$current['base_url'];}
            $configured=$this->validationBaseUrl();
            if($configured===null){throw new RuntimeException('Configure primeiro a URL oficial de validação em Emissões > Preparação para emissão.');}
            $url=$this->buildQrUrl($configured,$token);
            $this->repository->insertQrDestination((int)$emission['id'],$configured,hash('sha256',$url,true),$actor->id);
            return $configured;
        });
        $qrUrl=$this->buildQrUrl($base,$token);
        $photo=$this->readAsset($emission,'foto');
        $agentSig=$this->readAsset($emission,'agente_ass');
        $signerSig=$this->readAsset($emission,'signer_ass');
        $cpf=$this->protection->decryptCpf((string)$emission['cpf_ciphertext'],(string)$emission['cpf_key_id']);
        $bundle=@file_get_contents($this->qrBundlePath);
        if(!is_string($bundle)||$bundle===''){throw new RuntimeException('Componente local de QR Code não foi localizado no plugin.');}
        $frontBackground=$this->readTemplateBackground('cif-card-front-v3.png');
        $backBackground=$this->readTemplateBackground('cif-card-back-v3.png');
        $html=$this->renderer->render([
            ...$emission,
            'foto_bytes'=>$photo['bytes'],'foto_mime'=>$photo['mime'],
            'agente_ass_bytes'=>$agentSig['bytes'],'agente_ass_mime'=>$agentSig['mime'],
            'signer_ass_bytes'=>$signerSig['bytes'],'signer_ass_mime'=>$signerSig['mime'],
            'cpf_formatado'=>$this->formatCpf($cpf),
            'qr_url'=>$qrUrl,
            'back_url'=>home_url('/cif/emissoes/'.rawurlencode($emissionUuid)),
            'card_front_bg_bytes'=>$frontBackground['bytes'],'card_front_bg_mime'=>$frontBackground['mime'],
            'card_back_bg_bytes'=>$backBackground['bytes'],'card_back_bg_mime'=>$backBackground['mime'],
        ],$bundle);
        $stored=$this->storage->storeBytes($html,'text/html; charset=UTF-8','artefato_cif','html');
        try{
            $artifact=$this->transactions->run(function() use($emission,$emissionUuid,$actor,$stored,$html,$qrUrl):array{
                $this->repository->lockEmission((int)$emission['id']);
                $existing=$this->repository->latestArtifact((int)$emission['id'],self::ARTIFACT_TYPE);
                if($existing!==null && (string)($existing['template_version']??'')===CardTemplateRenderer::TEMPLATE_VERSION){return $existing;}
                $physical=$this->repository->physicalStatus((int)$emission['id']);
                if($existing!==null && $physical!=='NAO_PRODUZIDA'){return $existing;}
                if($existing===null && $physical==='INUTILIZADA'){throw new RuntimeException('Não é permitido gerar arte de impressão para uma CIF inutilizada.');}
                $version=$existing!==null ? ((int)$existing['versao']+1) : 1;
                $fileId=$this->repository->insertPrivateFile([
                    ...$stored,'categoria'=>'ARTEFATO_CIF','nome_original'=>'CIF-'.(string)$emission['codigo_validacao'].'-v'.$version.'.html'
                ],$actor->id);
                $artifactId=$this->repository->insertArtifact((int)$emission['id'],self::ARTIFACT_TYPE,$version,$fileId,CardTemplateRenderer::TEMPLATE_VERSION,hash('sha256',$html,true),$actor->id);
                $this->repository->insertEmissionEvent(
                    (int)$emission['id'],
                    isset($emission['processo_id']) ? (int)$emission['processo_id'] : null,
                    'ARTEFATO_CIF_GERADO',
                    null,
                    null,
                    ['artefato_id'=>$artifactId,'tipo'=>self::ARTIFACT_TYPE,'template'=>CardTemplateRenderer::TEMPLATE_VERSION],
                    $actor->id
                );
                $this->audit->log('ARTEFATO_CIF_GERADO','emissao',(int)$emission['id'],$emissionUuid,$actor,'WEB',null,[
                    'artefato_id'=>$artifactId,'tipo'=>self::ARTIFACT_TYPE,'template'=>CardTemplateRenderer::TEMPLATE_VERSION,
                    'qr_url_sha256'=>hash('sha256',$qrUrl),
                ]);
                return $this->repository->artifactByIdForEmission($artifactId,(int)$emission['id']) ?? ['id'=>$artifactId];
            });
        }catch(\Throwable $e){$this->storage->delete((string)$stored['storage_key']);throw $e;}
        if(isset($artifact['storage_key']) && (string)$artifact['storage_key']!==(string)$stored['storage_key']){
            $this->storage->delete((string)$stored['storage_key']);
        }
        return $artifact;
    }

    /** @return array{html:string,artifact:array<string,mixed>} */
    public function preview(string $emissionUuid, CifUser $actor): array
    {
        $emission = $this->repository->emissionAssetsByUuid($emissionUuid);
        if ($emission === null) {
            throw new RuntimeException('Emissão CIF não localizada.');
        }

        $artifact = $this->generate($emissionUuid, $actor);
        if (!isset($artifact['id'])) {
            throw new RuntimeException('Artefato da CIF não pôde ser identificado.');
        }
        $file = $this->repository->artifactByIdForEmission((int) $artifact['id'], (int) $emission['id']);
        if ($file === null) {
            throw new RuntimeException('Arquivo do artefato CIF não localizado.');
        }
        $html = $this->storage->read((string) $file['storage_key'], (string) $file['key_id'], (string) $file['arquivo_sha256']);
        $html = $this->withGraphicPdfExporter($this->previewHtml($html));

        $this->audit->log(
            'ARTEFATO_CIF_VISUALIZADO',
            'emissao',
            (int) $emission['id'],
            $emissionUuid,
            $actor,
            'WEB',
            null,
            ['artefato_id' => (int) $artifact['id'], 'template' => (string) ($artifact['template_version'] ?? '')]
        );

        return ['html' => $html, 'artifact' => $file];
    }

    /** @return array{html:string,artifact:array<string,mixed>,attempt:array<string,mixed>} */
    public function openPrint(string $emissionUuid, string $printer, CifUser $actor): array
    {
        $emission=$this->repository->emissionAssetsByUuid($emissionUuid);
        if($emission===null){throw new RuntimeException('Emissão CIF não localizada.');}
        if(!in_array((string)$emission['status_fisico'],['NAO_PRODUZIDA','EM_GUARDA_SMTT'],true)){
            throw new RuntimeException('Não é permitido produzir outra via física desta mesma emissão após entrega, extravio, recolhimento ou inutilização. Abra uma nova emissão quando a regra administrativa exigir nova via.');
        }
        if(in_array((string)$emission['status_validade'],['SUSPENSA','ENCERRADA'],true)){
            throw new RuntimeException('Não é permitido imprimir fisicamente uma emissão suspensa ou encerrada.');
        }
        $artifact=$this->generate($emissionUuid,$actor);
        if(!isset($artifact['id'])){throw new RuntimeException('Artefato de impressão não pôde ser identificado.');}
        $printer=$this->cleanText($printer,150);
        $attempt=$this->transactions->run(function() use($emission,$artifact,$printer,$actor,$emissionUuid):array{
            $this->repository->lockEmission((int)$emission['id']);
            $open=$this->repository->openPrintAttemptForEmission((int)$emission['id']);
            if($open!==null){
                return $open;
            }
            $number=$this->repository->nextPrintAttempt((int)$emission['id']);
            $id=$this->repository->insertPrintAttempt((int)$emission['id'],(int)$artifact['id'],$number,$printer!==''?$printer:null,$actor->id);
            $this->repository->insertEmissionEvent(
                (int)$emission['id'],
                isset($emission['processo_id']) ? (int)$emission['processo_id'] : null,
                'IMPRESSAO_CIF_ABERTA',
                null,
                null,
                ['tentativa'=>$number,'impressao_id'=>$id,'impressora'=>$printer?:null],
                $actor->id
            );
            $this->audit->log('IMPRESSAO_CIF_ABERTA','emissao',(int)$emission['id'],$emissionUuid,$actor,'WEB',null,['tentativa'=>$number,'impressao_id'=>$id,'impressora'=>$printer?:null]);
            return $this->repository->printAttempt($id,(int)$emission['id']) ?? ['id'=>$id,'tentativa'=>$number];
        });
        $artifactId=isset($attempt['artefato_id'])?(int)$attempt['artefato_id']:(int)$artifact['id'];
        $file=$this->repository->artifactByIdForEmission($artifactId,(int)$emission['id']);
        if($file===null){throw new RuntimeException('Arquivo do artefato de impressão não localizado.');}
        $html=$this->storage->read((string)$file['storage_key'],(string)$file['key_id'],(string)$file['arquivo_sha256']);
        return ['html'=>$html,'artifact'=>$file,'attempt'=>$attempt];
    }

    /** @return array{html:string,attempt:array<string,mixed>} */
    public function printView(string $emissionUuid, int $attemptId): array
    {
        $emission=$this->repository->emissionAssetsByUuid($emissionUuid);
        if($emission===null){throw new RuntimeException('Emissão CIF não localizada.');}
        $attempt=$this->repository->printAttempt($attemptId,(int)$emission['id']);
        if($attempt===null){throw new RuntimeException('Tentativa de impressão não localizada.');}
        if((string)$attempt['status']!=='ABERTA'){throw new RuntimeException('Esta tentativa de impressão já foi encerrada. Abra uma nova tentativa, se ainda for permitido produzir a mesma emissão.');}
        if(!in_array((string)$emission['status_fisico'],['NAO_PRODUZIDA','EM_GUARDA_SMTT'],true) || in_array((string)$emission['status_validade'],['SUSPENSA','ENCERRADA'],true)){
            throw new RuntimeException('A situação atual da CIF não permite abrir uma arte para produção física.');
        }
        $file=$this->repository->artifactByIdForEmission((int)$attempt['artefato_id'],(int)$emission['id']);
        if($file===null){throw new RuntimeException('Artefato vinculado à impressão não localizado.');}
        $html=$this->storage->read((string)$file['storage_key'],(string)$file['key_id'],(string)$file['arquivo_sha256']);
        return ['html'=>$this->withGraphicPdfExporter($html),'attempt'=>$attempt];
    }

    public function finishPrint(string $emissionUuid, int $attemptId, string $result, string $reason, CifUser $actor): void
    {
        $emission=$this->repository->emissionAssetsByUuid($emissionUuid);
        if($emission===null){throw new RuntimeException('Emissão CIF não localizada.');}
        $result=strtoupper(trim($result));
        if(!in_array($result,['CONCLUIDA','FALHOU'],true)){throw new InvalidArgumentException('Resultado de impressão inválido.');}
        $reason=$this->cleanText($reason,1000);
        if($result==='FALHOU' && $this->length($reason)<3){throw new InvalidArgumentException('Informe o motivo da falha de impressão.');}

        $this->transactions->run(function() use($emission,$emissionUuid,$attemptId,$result,$reason,$actor):void{
            $this->repository->lockEmission((int)$emission['id']);
            $attempt=$this->repository->printAttempt($attemptId,(int)$emission['id']);
            if($attempt===null){throw new RuntimeException('Tentativa de impressão não localizada.');}
            $changed=$this->repository->finishPrintAttempt(
                $attemptId,
                (int)$emission['id'],
                $result,
                $result==='FALHOU'?'FALHA_OPERACIONAL':null,
                $result==='FALHOU'?$reason:null
            );
            if(!$changed){throw new RuntimeException('A tentativa já foi encerrada ou alterada por outro usuário.');}

            $eventType='IMPRESSAO_CIF_'.($result==='CONCLUIDA'?'CONCLUIDA':'FALHOU');
            $this->repository->insertEmissionEvent(
                (int)$emission['id'],
                isset($emission['processo_id']) ? (int)$emission['processo_id'] : null,
                $eventType,
                null,
                null,
                ['tentativa'=>(int)$attempt['tentativa'],'impressao_id'=>$attemptId,'motivo'=>$reason!==''?$reason:null],
                $actor->id
            );

            $produced=false;
            if($result==='CONCLUIDA'){
                $produced=$this->repository->markProducedIfNeeded((int)$emission['id']);
                if($produced){
                    $this->repository->insertEmissionEvent(
                        (int)$emission['id'],
                        isset($emission['processo_id']) ? (int)$emission['processo_id'] : null,
                        'CIF_PRODUZIDA',
                        'NAO_PRODUZIDA',
                        'EM_GUARDA_SMTT',
                        ['origem'=>'IMPRESSAO_CONFIRMADA','tentativa'=>(int)$attempt['tentativa']],
                        $actor->id
                    );
                }
            }

            $after=['status'=>$result,'tentativa'=>(int)$attempt['tentativa']];
            if($produced){$after['status_fisico']='EM_GUARDA_SMTT';}
            $this->audit->log(
                $eventType,
                'emissao',
                (int)$emission['id'],
                $emissionUuid,
                $actor,
                'WEB',
                ['status'=>(string)$attempt['status']],
                $after,
                $reason!==''?$reason:null
            );
        });
    }


    /**
     * Adiciona a exportação CMYK na apresentação, sem alterar o HTML cifrado e
     * imutável de emissões anteriores nem o controle de tentativas de impressão.
     */
    private function withGraphicPdfExporter(string $html): string
    {
        if (!str_contains($html, '</body>') || str_contains($html, 'smtt-cif-export-cmyk')) {
            return $html;
        }
        $path = SMTT_CIF_GESTAO_PATH . 'assets/js/cif-grafica-cmyk.js';
        $script = @file_get_contents($path);
        if (!is_string($script) || $script === '') {
            // A impressão original continua disponível se o arquivo estiver ausente.
            return $html;
        }
        $script = str_ireplace('</script', '<\/script', $script);
        return str_replace('</body>', '<script>' . $script . '</script></body>', $html);
    }

    private function previewHtml(string $html): string
    {
        $html = str_replace(
            '<button onclick="window.print()">Imprimir / Salvar como PDF</button>',
            '',
            $html
        );
        $html = str_replace(
            '<small>Arte CR80',
            '<small><strong>Pré-visualização da carteira — não registra tentativa de impressão.</strong><br>Arte CR80',
            $html
        );
        return $html;
    }

    /** @return array{bytes:string,mime:string} */
    private function readTemplateBackground(string $file): array
    {
        $path = SMTT_CIF_GESTAO_PATH . 'assets/img/' . $file;
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Fundo gráfico da Carteira de Identificação Funcional não foi localizado no plugin.');
        }
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    private function decryptToken(array $emission): string{return $this->protection->decryptQrToken((string)$emission['qr_token_ciphertext'],(string)$emission['qr_key_id']);}
    /** @return array{bytes:string,mime:string} */
    private function readAsset(array $emission,string $prefix):array{
        $bytes=$this->storage->read((string)$emission[$prefix.'_storage_key'],(string)$emission[$prefix.'_key_id'],(string)$emission[$prefix.'_sha256']);
        return ['bytes'=>$bytes,'mime'=>(string)$emission[$prefix.'_mime']];
    }
    private function buildQrUrl(string $base,string $token):string{return rtrim($base,'/').'/'.rawurlencode($token);}
    private function normalizeBaseUrl(string $url):string{
        $url=trim($url); if($url===''||strlen($url)>450){throw new InvalidArgumentException('Informe uma URL de validação válida.');}
        $parts=wp_parse_url($url); if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host'])){throw new InvalidArgumentException('A URL de validação deve ser absoluta, por exemplo https://portal.exemplo.gov.br/validar.');}
        $scheme=strtolower((string)$parts['scheme']);
        $host=strtolower(trim((string)$parts['host'],'[]'));
        $env=function_exists('wp_get_environment_type')?wp_get_environment_type():'production';
        $localHost=$host==='localhost'||$host==='127.0.0.1'||$host==='::1'||str_ends_with($host,'.local')||str_ends_with($host,'.test');
        $httpAllowed=$scheme==='http' && ($env!=='production' || $localHost);
        if($scheme!=='https' && !$httpAllowed){throw new InvalidArgumentException('Em produção, a URL oficial de validação deve usar HTTPS. HTTP é aceito somente em ambiente/local de desenvolvimento.');}
        if(isset($parts['query'])||isset($parts['fragment'])){throw new InvalidArgumentException('A URL base de validação não pode conter query string nem fragmento.');}
        return rtrim($url,'/');
    }
    private function formatCpf(string $digits):string{return preg_match('/^\d{11}$/',$digits)===1?substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2):$digits;}
    private function cleanText(string $v,int $max):string{$v=trim(preg_replace('/\s+/u',' ',$v)??$v);if($this->length($v)>$max){throw new InvalidArgumentException('Campo excede o tamanho permitido.');}return $v;}
    private function length(string $v):int{return function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);}
}

