<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;

final class ArtifactRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function emissionAssetsByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT
    e.id, e.uuid, e.processo_id, e.codigo_validacao, e.sequencial_sistema, e.template_version,
    e.qr_token_ciphertext, e.qr_key_id, e.signatario_id, e.emitida_em,
    COALESCE(e.ciclo_numero_agente, c.numero) AS ciclo_numero,
    s.nome_completo, s.matricula, s.naturalidade, s.data_nascimento,
    s.cpf_ciphertext, s.cpf_key_id, s.tipo_sanguineo, s.data_admissao,
    s.situacao_funcional, s.signatario_nome, s.signatario_cargo,
    es.status_validade, es.status_fisico,
    fp.storage_key AS foto_storage_key, fp.key_id AS foto_key_id, fp.sha256 AS foto_sha256, fp.mime_type AS foto_mime,
    fa.storage_key AS agente_ass_storage_key, fa.key_id AS agente_ass_key_id, fa.sha256 AS agente_ass_sha256, fa.mime_type AS agente_ass_mime,
    fs.storage_key AS signer_ass_storage_key, fs.key_id AS signer_ass_key_id, fs.sha256 AS signer_ass_sha256, fs.mime_type AS signer_ass_mime
FROM emissoes_cif e
LEFT JOIN ciclos_emissao c ON c.id=e.ciclo_id
JOIN emissoes_cif_snapshot s ON s.emissao_id=e.id
JOIN emissoes_cif_estado es ON es.emissao_id=e.id
JOIN emissoes_cif_biometrias ebf ON ebf.emissao_id=e.id AND ebf.finalidade='FOTO'
JOIN biometrias bf ON bf.id=ebf.biometria_id
JOIN arquivos_privados fp ON fp.id=bf.arquivo_id
JOIN emissoes_cif_biometrias eba ON eba.emissao_id=e.id AND eba.finalidade='ASSINATURA_TITULAR'
JOIN biometrias ba ON ba.id=eba.biometria_id
JOIN arquivos_privados fa ON fa.id=ba.arquivo_id
JOIN signatarios_institucionais si ON si.id=e.signatario_id
JOIN arquivos_privados fs ON fs.id=si.assinatura_arquivo_id
WHERE e.uuid=?
LIMIT 1
SQL);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }


    public function lockEmission(int $emissionId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM emissoes_cif WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $stmt->get_result()->free();
        $stmt->close();
    }

    public function physicalStatus(int $emissionId): string
    {
        $stmt = $this->db->prepare('SELECT status_fisico FROM emissoes_cif_estado WHERE emissao_id=? LIMIT 1');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? (string) $row['status_fisico'] : '';
    }

    /** @return array<string,mixed>|null */
    public function qrDestination(int $emissionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM emissoes_qr_destinos WHERE emissao_id=? LIMIT 1');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockQrDestination(int $emissionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM emissoes_qr_destinos WHERE emissao_id=? FOR UPDATE');
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function insertQrDestination(int $emissionId, string $baseUrl, string $urlHash, int $actorId): void
    {
        $stmt = $this->db->prepare('INSERT INTO emissoes_qr_destinos (emissao_id, base_url, url_hash, definida_por, definida_em) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $stmt->bind_param('issi', $emissionId, $baseUrl, $urlHash, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function configuration(string $key): mixed
    {
        $stmt = $this->db->prepare('SELECT valor FROM configuracoes WHERE chave=? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        return json_decode((string) $row['valor'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function setConfiguration(string $key, mixed $value, string $description, int $actorId): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO configuracoes (chave, valor, descricao, atualizado_por, atualizado_em)
VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE valor=VALUES(valor), descricao=VALUES(descricao), atualizado_por=VALUES(atualizado_por), atualizado_em=VALUES(atualizado_em)
SQL);
        $stmt->bind_param('sssi', $key, $json, $description, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null */
    public function latestArtifact(int $emissionId, string $type): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT a.*, f.uuid AS arquivo_uuid, f.storage_key, f.key_id, f.sha256 AS arquivo_sha256, f.mime_type, f.tamanho_bytes
FROM artefatos_emissao a
JOIN arquivos_privados f ON f.id=a.arquivo_id
WHERE a.emissao_id=? AND a.tipo=?
ORDER BY a.versao DESC, a.id DESC
LIMIT 1
SQL);
        $stmt->bind_param('is', $emissionId, $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function artifacts(int $emissionId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT a.*, f.uuid AS arquivo_uuid, f.mime_type, f.tamanho_bytes, u.nome_snapshot AS gerado_por_nome
FROM artefatos_emissao a
JOIN arquivos_privados f ON f.id=a.arquivo_id
JOIN usuarios_cif u ON u.id=a.gerado_por
WHERE a.emissao_id=?
ORDER BY a.gerado_em DESC, a.id DESC
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows=[];
        while ($row=$result->fetch_assoc()) {$rows[]=$row;}
        $result->free();
        $stmt->close();
        return $rows;
    }

    /** @param array<string,mixed> $file */
    public function insertPrivateFile(array $file, int $actorId): int
    {
        $encrypted=1; $status='ATIVO';
        $stmt=$this->db->prepare(<<<'SQL'
INSERT INTO arquivos_privados (
 uuid,categoria,storage_backend,storage_key,nome_original,mime_type,tamanho_bytes,sha256,criptografado,key_id,status,criado_por,criado_em
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('ssssssisissi',
            $file['uuid'],$file['categoria'],$file['storage_backend'],$file['storage_key'],$file['nome_original'],$file['mime_type'],
            $file['size'],$file['sha256'],$encrypted,$file['key_id'],$status,$actorId
        );
        $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
    }

    public function insertArtifact(int $emissionId, string $type, int $version, int $fileId, string $templateVersion, string $sha256, int $actorId): int
    {
        $stmt=$this->db->prepare(<<<'SQL'
INSERT INTO artefatos_emissao (emissao_id,tipo,versao,arquivo_id,template_version,sha256,gerado_por,gerado_em)
VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('isiissi',$emissionId,$type,$version,$fileId,$templateVersion,$sha256,$actorId);
        $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
    }

    /** @return array<string,mixed>|null */
    public function artifactByIdForEmission(int $artifactId, int $emissionId): ?array
    {
        $stmt=$this->db->prepare(<<<'SQL'
SELECT a.*, f.uuid AS arquivo_uuid, f.storage_key, f.key_id, f.sha256 AS arquivo_sha256, f.mime_type, f.tamanho_bytes
FROM artefatos_emissao a JOIN arquivos_privados f ON f.id=a.arquivo_id
WHERE a.id=? AND a.emissao_id=? LIMIT 1
SQL);
        $stmt->bind_param('ii',$artifactId,$emissionId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return is_array($row)?$row:null;
    }

    public function nextPrintAttempt(int $emissionId): int
    {
        $stmt=$this->db->prepare('SELECT COALESCE(MAX(tentativa),0)+1 AS n FROM impressoes_cif WHERE emissao_id=?');
        $stmt->bind_param('i',$emissionId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int)($row['n']??1);
    }

    /** @return array<string,mixed>|null */
    public function openPrintAttemptForEmission(int $emissionId): ?array
    {
        $status='ABERTA';
        $stmt=$this->db->prepare('SELECT * FROM impressoes_cif WHERE emissao_id=? AND status=? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('is',$emissionId,$status); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return is_array($row)?$row:null;
    }

    public function insertPrintAttempt(int $emissionId, int $artifactId, int $attempt, ?string $printer, int $actorId): int
    {
        $status='ABERTA';
        $stmt=$this->db->prepare(<<<'SQL'
INSERT INTO impressoes_cif (emissao_id,artefato_id,tentativa,status,motivo,impressora,solicitada_por,solicitada_em,concluida_em,erro)
VALUES (?,?,?,?,NULL,?,?,UTC_TIMESTAMP(6),NULL,NULL)
SQL);
        $stmt->bind_param('iiissi',$emissionId,$artifactId,$attempt,$status,$printer,$actorId);
        $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
    }

    /** @return array<string,mixed>|null */
    public function printAttempt(int $id, int $emissionId): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM impressoes_cif WHERE id=? AND emissao_id=? LIMIT 1');
        $stmt->bind_param('ii',$id,$emissionId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return is_array($row)?$row:null;
    }

    public function finishPrintAttempt(int $id, int $emissionId, string $status, ?string $reason, ?string $error): bool
    {
        $stmt=$this->db->prepare(<<<'SQL'
UPDATE impressoes_cif SET status=?, motivo=?, erro=?, concluida_em=UTC_TIMESTAMP(6)
WHERE id=? AND emissao_id=? AND status='ABERTA'
SQL);
        $stmt->bind_param('sssii',$status,$reason,$error,$id,$emissionId); $stmt->execute(); $changed=$stmt->affected_rows===1; $stmt->close(); return $changed;
    }

    public function insertEmissionEvent(
        int $emissionId,
        ?int $processId,
        string $type,
        ?string $statusBefore,
        ?string $statusAfter,
        array $data,
        int $actorId
    ): void {
        $uuid = \SMTT\CIF\Gestao\Support\Uuid::v4();
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO eventos_cif (uuid, emissao_id, processo_id, tipo_evento, status_anterior, status_novo, dados, usuario_id, ocorrido_em)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('siissssi', $uuid, $emissionId, $processId, $type, $statusBefore, $statusAfter, $json, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Ao confirmar uma impressão física concluída pela primeira vez, a CIF deixa
     * de estar NAO_PRODUZIDA e passa a ficar sob guarda da SMTT.
     */
    public function markProducedIfNeeded(int $emissionId): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE emissoes_cif_estado
SET status_fisico='EM_GUARDA_SMTT', lock_version=lock_version+1, atualizado_em=UTC_TIMESTAMP(6)
WHERE emissao_id=? AND status_fisico='NAO_PRODUZIDA'
SQL);
        $stmt->bind_param('i', $emissionId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        return $changed;
    }

    /** @return list<array<string,mixed>> */
    public function printAttempts(int $emissionId): array
    {
        $stmt=$this->db->prepare(<<<'SQL'
SELECT i.*, u.nome_snapshot AS solicitada_por_nome, a.tipo AS artefato_tipo, a.versao AS artefato_versao
FROM impressoes_cif i
JOIN usuarios_cif u ON u.id=i.solicitada_por
JOIN artefatos_emissao a ON a.id=i.artefato_id
WHERE i.emissao_id=? ORDER BY i.tentativa DESC
SQL);
        $stmt->bind_param('i',$emissionId); $stmt->execute(); $res=$stmt->get_result(); $rows=[]; while($r=$res->fetch_assoc()){$rows[]=$r;} $res->free(); $stmt->close(); return $rows;
    }
}
