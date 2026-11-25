<?php

declare(strict_types=1);

namespace SMTT\CIF\Gestao\Repository;

use mysqli;
use RuntimeException;
use SMTT\CIF\Gestao\Security\CifUser;
use SMTT\CIF\Gestao\Support\Uuid;

final class AuthorizationRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function countUsers(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM usuarios_cif WHERE removido_em IS NULL');
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }

    public function countActiveUsers(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM usuarios_cif WHERE ativo=1 AND removido_em IS NULL');
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }

    public function countActiveAdmins(): int
    {
        $result = $this->db->query(<<<'SQL'
SELECT COUNT(DISTINCT u.id) AS total
FROM usuarios_cif u
JOIN usuario_perfis up ON up.usuario_id=u.id
JOIN perfis_acesso p ON p.id=up.perfil_id
WHERE u.ativo=1 AND u.removido_em IS NULL AND p.ativo=1 AND p.codigo='ADMIN_CIF'
SQL);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }

    public function countConfiguredAdmins(): int
    {
        $result = $this->db->query(<<<'SQL'
SELECT COUNT(DISTINCT u.id) AS total
FROM usuarios_cif u
JOIN usuario_perfis up ON up.usuario_id=u.id
JOIN perfis_acesso p ON p.id=up.perfil_id
WHERE u.ativo=1 AND u.removido_em IS NULL AND u.senha_hash IS NOT NULL AND u.senha_hash<>''
  AND p.ativo=1 AND p.codigo='ADMIN_CIF'
SQL);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    }

    public function lockProfileByCode(string $code): void
    {
        $stmt = $this->db->prepare('SELECT id FROM perfis_acesso WHERE codigo=? FOR UPDATE');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $stmt->bind_result($id);
        if (!$stmt->fetch()) {
            $stmt->close();
            throw new RuntimeException('Perfil CIF não encontrado: ' . $code);
        }
        $stmt->close();
    }

    public function findById(int $id): ?CifUser
    {
        return $this->findOne('u.id=? AND u.removido_em IS NULL', 'i', [$id]);
    }

    public function findByLogin(string $login): ?CifUser
    {
        return $this->findOne('u.login=? AND u.removido_em IS NULL', 's', [$login]);
    }

    public function findByEmail(string $email): ?CifUser
    {
        return $this->findOne('u.email=? AND u.removido_em IS NULL', 's', [$email]);
    }

    /** @return array{user:CifUser,password_hash:?string,failures:int,locked_until:?string}|null */
    public function authenticationRecord(string $login): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
SELECT id, uuid, wp_user_id, login, nome_snapshot, email, setor, ativo, deve_trocar_senha,
       ultimo_login_em, senha_hash, falhas_login, bloqueado_ate
FROM usuarios_cif
WHERE login=? AND removido_em IS NULL
LIMIT 1
SQL);
        $stmt->bind_param('s', $login);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $user = $this->hydrateRow($row);
        return [
            'user' => $user,
            'password_hash' => isset($row['senha_hash']) && $row['senha_hash'] !== '' ? (string)$row['senha_hash'] : null,
            'failures' => (int)($row['falhas_login'] ?? 0),
            'locked_until' => isset($row['bloqueado_ate']) && $row['bloqueado_ate'] !== null ? (string)$row['bloqueado_ate'] : null,
        ];
    }

    public function findByWpUserId(int $wpUserId): ?CifUser
    {
        return $this->findOne('u.wp_user_id=? AND u.removido_em IS NULL', 'i', [$wpUserId]);
    }

    public function findByUuid(string $uuid): ?CifUser
    {
        return $this->findOne('u.uuid=? AND u.removido_em IS NULL', 's', [$uuid]);
    }

    public function findByUuidIncludingRemoved(string $uuid): ?CifUser
    {
        return $this->findOne('u.uuid=?', 's', [$uuid]);
    }

    /** @return list<CifUser> */
    public function listUsers(): array
    {
        $result = $this->db->query($this->baseSelect() . ' WHERE u.removido_em IS NULL ORDER BY u.nome_snapshot,u.login,u.id');
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $this->hydrateRow($row);
        }
        $result->free();
        return $users;
    }

    /** @return array{items:list<CifUser>,total:int} */
    public function searchUsers(string $query, int $page=1, int $perPage=25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;
        $query = trim($query);
        $joins = ' FROM usuarios_cif u LEFT JOIN usuario_perfis up ON up.usuario_id=u.id LEFT JOIN perfis_acesso p ON p.id=up.perfil_id ';
        $where = ' WHERE u.removido_em IS NULL';
        $params = [];
        if ($query !== '') {
            $where .= ' AND (u.nome_snapshot LIKE ? OR u.login LIKE ? OR u.email LIKE ? OR u.setor LIKE ? OR p.codigo LIKE ? OR p.nome LIKE ?)';
            $needle = '%' . $query . '%';
            $params = [$needle,$needle,$needle,$needle,$needle,$needle];
        }
        $count = $this->db->prepare('SELECT COUNT(DISTINCT u.id) total' . $joins . $where);
        $count->execute($params);
        $countRow = $count->get_result()->fetch_assoc();
        $count->close();
        $total = (int)($countRow['total'] ?? 0);

        $sql = 'SELECT DISTINCT u.id,u.uuid,u.wp_user_id,u.login,u.nome_snapshot,u.email,u.setor,u.ativo,u.deve_trocar_senha,u.ultimo_login_em'
            . $joins . $where . ' ORDER BY u.nome_snapshot,u.login,u.id LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $this->hydrateRow($row);
        }
        $result->free();
        $stmt->close();
        return ['items'=>$users,'total'=>$total];
    }

    /** @return list<array{code:string,name:string,module:string,profiles:list<string>}> */
    public function permissionMatrix(): array
    {
        $result = $this->db->query(<<<'SQL'
SELECT perm.codigo,perm.nome,perm.modulo,p.codigo perfil_codigo
FROM permissoes perm
LEFT JOIN perfil_permissoes pp ON pp.permissao_id=perm.id
LEFT JOIN perfis_acesso p ON p.id=pp.perfil_id AND p.ativo=1
WHERE perm.ativo=1
ORDER BY perm.modulo,perm.nome,p.id
SQL);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $code = (string)$row['codigo'];
            if (!isset($rows[$code])) {
                $rows[$code] = ['code'=>$code,'name'=>(string)$row['nome'],'module'=>(string)$row['modulo'],'profiles'=>[]];
            }
            if (!empty($row['perfil_codigo'])) {
                $rows[$code]['profiles'][] = (string)$row['perfil_codigo'];
            }
        }
        $result->free();
        return array_values($rows);
    }

    /** @return list<array{code:string,name:string,description:string}> */
    public function listActiveProfiles(): array
    {
        $result = $this->db->query("SELECT codigo,nome,COALESCE(descricao,'') descricao FROM perfis_acesso WHERE ativo=1 ORDER BY id");
        $profiles = [];
        while ($row = $result->fetch_assoc()) {
            $profiles[] = ['code'=>(string)$row['codigo'],'name'=>(string)$row['nome'],'description'=>(string)$row['descricao']];
        }
        $result->free();
        return $profiles;
    }

    public function firstUnconfiguredAdmin(): ?CifUser
    {
        $result = $this->db->query(<<<'SQL'
SELECT u.id,u.uuid,u.wp_user_id,u.login,u.nome_snapshot,u.email,u.setor,u.ativo,u.deve_trocar_senha,u.ultimo_login_em
FROM usuarios_cif u
JOIN usuario_perfis up ON up.usuario_id=u.id
JOIN perfis_acesso p ON p.id=up.perfil_id
WHERE u.removido_em IS NULL AND u.ativo=1 AND p.codigo='ADMIN_CIF' AND (u.senha_hash IS NULL OR u.senha_hash='')
ORDER BY u.id LIMIT 1
SQL);
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    public function createNativeUser(string $login, string $email, string $name, string $department, string $passwordHash, bool $active=true, bool $mustChange=true): int
    {
        $uuid = Uuid::v4();
        $activeInt = $active ? 1 : 0;
        $mustInt = $mustChange ? 1 : 0;
        $legacyWpId = null;
        $stmt = $this->db->prepare(<<<'SQL'
INSERT INTO usuarios_cif
(uuid,wp_user_id,login,email,setor,senha_hash,deve_trocar_senha,ativo,login_snapshot,nome_snapshot,criado_em,atualizado_em)
VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
SQL);
        $stmt->bind_param('sissssiiss', $uuid, $legacyWpId, $login, $email, $department, $passwordHash, $mustInt, $activeInt, $login, $name);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function configureNativeIdentity(int $id, string $login, string $email, string $name, string $department, string $passwordHash, bool $mustChange): void
    {
        $mustInt = $mustChange ? 1 : 0;
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE usuarios_cif
SET wp_user_id=NULL,login=?,email=?,setor=?,senha_hash=?,deve_trocar_senha=?,login_snapshot=?,nome_snapshot=?,
    falhas_login=0,bloqueado_ate=NULL,removido_em=NULL,removido_por=NULL,ativo=1,atualizado_em=UTC_TIMESTAMP(6)
WHERE id=?
SQL);
        $stmt->bind_param('ssssissi', $login, $email, $department, $passwordHash, $mustInt, $login, $name, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function updateIdentity(int $id, string $login, string $email, string $name, string $department): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios_cif SET login=?,email=?,setor=?,login_snapshot=?,nome_snapshot=?,atualizado_em=UTC_TIMESTAMP(6) WHERE id=? AND removido_em IS NULL');
        $stmt->bind_param('sssssi', $login, $email, $department, $login, $name, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function setPasswordHash(int $id, string $hash, bool $mustChange): void
    {
        $mustInt = $mustChange ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE usuarios_cif SET senha_hash=?,deve_trocar_senha=?,falhas_login=0,bloqueado_ate=NULL,atualizado_em=UTC_TIMESTAMP(6) WHERE id=? AND removido_em IS NULL');
        $stmt->bind_param('sii', $hash, $mustInt, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function passwordHash(int $id): ?string
    {
        $stmt = $this->db->prepare('SELECT senha_hash FROM usuarios_cif WHERE id=? AND removido_em IS NULL LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($hash);
        $found = $stmt->fetch();
        $stmt->close();
        return $found && is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function recordLoginFailure(int $id, int $maxFailures=5, int $lockMinutes=15): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
UPDATE usuarios_cif
SET falhas_login=falhas_login+1,
    bloqueado_ate=CASE WHEN falhas_login+1>=? THEN DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? MINUTE) ELSE bloqueado_ate END,
    atualizado_em=UTC_TIMESTAMP(6)
WHERE id=?
SQL);
        $stmt->bind_param('iii', $maxFailures, $lockMinutes, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function recordSuccessfulLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios_cif SET falhas_login=0,bloqueado_ate=NULL,ultimo_login_em=UTC_TIMESTAMP(6),ultimo_acesso_em=UTC_TIMESTAMP(6),atualizado_em=UTC_TIMESTAMP(6) WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    public function touchLastAccess(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios_cif SET ultimo_acesso_em=UTC_TIMESTAMP(6),atualizado_em=UTC_TIMESTAMP(6) WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    public function setActive(int $id, bool $active): void
    {
        $activeInt = $active ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE usuarios_cif SET ativo=?,atualizado_em=UTC_TIMESTAMP(6) WHERE id=? AND removido_em IS NULL');
        $stmt->bind_param('ii', $activeInt, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function hasHistoricalActivity(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM auditoria WHERE usuario_cif_id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if ($found) {
            return true;
        }

        // Descobre todas as FKs que apontam para usuarios_cif. Assim, novos módulos
        // também passam a preservar autoria sem depender de uma lista manual.
        $sql = <<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME = 'usuarios_cif'
  AND REFERENCED_COLUMN_NAME = 'id'
SQL;
        $result = $this->db->query($sql);
        if ($result === false) {
            // Em caso de dúvida, falhar fechado: não liberar exclusão potencialmente destrutiva.
            return true;
        }

        $ownedRowsThatCanBeRemoved = [
            'sessoes_cif.usuario_id' => true,
            'usuario_perfis.usuario_id' => true,
            'usuario_permissoes.usuario_id' => true,
        ];

        while ($row = $result->fetch_assoc()) {
            $table = (string)($row['TABLE_NAME'] ?? '');
            $column = (string)($row['COLUMN_NAME'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $result->free();
                return true;
            }
            if (isset($ownedRowsThatCanBeRemoved[$table . '.' . $column])) {
                continue;
            }

            $check = $this->db->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}`=? LIMIT 1");
            $check->bind_param('i', $id);
            $check->execute();
            $hit = $check->get_result()->fetch_assoc() !== null;
            $check->close();
            if ($hit) {
                $result->free();
                return true;
            }
        }
        $result->free();

        return false;
    }

    public function hardDeleteUser(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessoes_cif WHERE usuario_id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        $stmt = $this->db->prepare('DELETE FROM usuario_permissoes WHERE usuario_id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        $stmt = $this->db->prepare('DELETE FROM usuario_perfis WHERE usuario_id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        $stmt = $this->db->prepare('DELETE FROM usuarios_cif WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('O usuário CIF não pôde ser excluído definitivamente.');
        }
    }

    /** @param list<string> $profileCodes */
    public function replaceProfiles(int $cifUserId, array $profileCodes, ?int $grantedBy): void
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $profileCodes))));
        if ($normalized === []) {
            throw new RuntimeException('O usuário CIF deve possuir ao menos um perfil de acesso.');
        }
        $profileIds = [];
        foreach ($normalized as $code) {
            $profileId = $this->findActiveProfileId($code);
            if ($profileId === null) {
                throw new RuntimeException('Perfil CIF inválido ou inativo: ' . $code);
            }
            $profileIds[] = $profileId;
        }
        $stmt = $this->db->prepare('DELETE FROM usuario_perfis WHERE usuario_id=?');
        $stmt->bind_param('i', $cifUserId); $stmt->execute(); $stmt->close();
        $stmt = $this->db->prepare('INSERT INTO usuario_perfis(usuario_id,perfil_id,concedido_por,concedido_em) VALUES(?,?,?,UTC_TIMESTAMP(6))');
        foreach ($profileIds as $profileId) {
            $grantor = $grantedBy;
            $stmt->bind_param('iii', $cifUserId, $profileId, $grantor);
            $stmt->execute();
        }
        $stmt->close();
    }

    /** @return list<string> */
    public function profileCodes(int $id): array
    {
        $stmt = $this->db->prepare('SELECT p.codigo FROM usuario_perfis up JOIN perfis_acesso p ON p.id=up.perfil_id WHERE up.usuario_id=? AND p.ativo=1 ORDER BY p.id');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($code);
        $codes=[]; while ($stmt->fetch()) { $codes[]=(string)$code; } $stmt->close(); return $codes;
    }

    /** @return list<string> */
    public function effectivePermissions(int $id): array
    {
        $permissions=[];
        $stmt=$this->db->prepare('SELECT DISTINCT perm.codigo FROM usuario_perfis up JOIN perfis_acesso p ON p.id=up.perfil_id AND p.ativo=1 JOIN perfil_permissoes pp ON pp.perfil_id=p.id JOIN permissoes perm ON perm.id=pp.permissao_id AND perm.ativo=1 WHERE up.usuario_id=?');
        $stmt->bind_param('i',$id); $stmt->execute(); $stmt->bind_result($code); while($stmt->fetch()){$permissions[(string)$code]=true;} $stmt->close();
        $stmt=$this->db->prepare('SELECT perm.codigo,up.efeito FROM usuario_permissoes up JOIN permissoes perm ON perm.id=up.permissao_id AND perm.ativo=1 WHERE up.usuario_id=?');
        $stmt->bind_param('i',$id); $stmt->execute(); $stmt->bind_result($override,$effect); while($stmt->fetch()){if((string)$effect==='DENY'){unset($permissions[(string)$override]);}elseif((string)$effect==='ALLOW'){$permissions[(string)$override]=true;}} $stmt->close();
        $codes=array_keys($permissions); sort($codes,SORT_STRING); return $codes;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function authorizationSnapshot(int $id): array
    {
        $sql = <<<'SQL'
SELECT 'PROFILE' AS kind, p.codigo AS codigo, NULL AS efeito
FROM usuario_perfis up
JOIN perfis_acesso p ON p.id=up.perfil_id AND p.ativo=1
WHERE up.usuario_id=?
UNION ALL
SELECT 'PERMISSION' AS kind, perm.codigo AS codigo, 'ALLOW' AS efeito
FROM usuario_perfis up
JOIN perfis_acesso p ON p.id=up.perfil_id AND p.ativo=1
JOIN perfil_permissoes pp ON pp.perfil_id=p.id
JOIN permissoes perm ON perm.id=pp.permissao_id AND perm.ativo=1
WHERE up.usuario_id=?
UNION ALL
SELECT 'OVERRIDE' AS kind, perm.codigo AS codigo, up.efeito AS efeito
FROM usuario_permissoes up
JOIN permissoes perm ON perm.id=up.permissao_id AND perm.ativo=1
WHERE up.usuario_id=?
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iii', $id, $id, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $profiles = [];
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $kind = (string)($row['kind'] ?? '');
            $code = (string)($row['codigo'] ?? '');
            if ($code === '') { continue; }
            if ($kind === 'PROFILE') {
                $profiles[$code] = true;
                continue;
            }
            if ($kind === 'PERMISSION') {
                $permissions[$code] = true;
                continue;
            }
            if ($kind === 'OVERRIDE') {
                if ((string)($row['efeito'] ?? '') === 'DENY') { unset($permissions[$code]); }
                elseif ((string)($row['efeito'] ?? '') === 'ALLOW') { $permissions[$code] = true; }
            }
        }
        $result->free();
        $stmt->close();
        $profileCodes = array_keys($profiles);
        $permissionCodes = array_keys($permissions);
        sort($profileCodes, SORT_STRING);
        sort($permissionCodes, SORT_STRING);
        return [$profileCodes, $permissionCodes];
    }

    private function baseSelect(): string
    {
        return 'SELECT u.id,u.uuid,u.wp_user_id,u.login,u.nome_snapshot,u.email,u.setor,u.ativo,u.deve_trocar_senha,u.ultimo_login_em FROM usuarios_cif u';
    }

    /** @param list<int|string> $values */
    private function findOne(string $where, string $types, array $values): ?CifUser
    {
        $stmt = $this->db->prepare($this->baseSelect() . ' WHERE ' . $where . ' LIMIT 1');
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $this->hydrateRow($row) : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrateRow(array $row): CifUser
    {
        $id=(int)$row['id'];
        [$profiles, $permissions] = $this->authorizationSnapshot($id);
        return new CifUser(
            $id,
            (string)$row['uuid'],
            isset($row['wp_user_id']) && $row['wp_user_id'] !== null ? (int)$row['wp_user_id'] : null,
            (string)$row['login'],
            (string)$row['nome_snapshot'],
            isset($row['email']) && $row['email'] !== null && $row['email'] !== '' ? (string)$row['email'] : null,
            isset($row['setor']) && $row['setor'] !== null && $row['setor'] !== '' ? (string)$row['setor'] : null,
            (bool)$row['ativo'],
            (bool)($row['deve_trocar_senha'] ?? false),
            isset($row['ultimo_login_em']) && $row['ultimo_login_em'] !== null ? (string)$row['ultimo_login_em'] : null,
            $profiles,
            $permissions
        );
    }

    private function findActiveProfileId(string $code): ?int
    {
        $stmt=$this->db->prepare('SELECT id FROM perfis_acesso WHERE codigo=? AND ativo=1 LIMIT 1');
        $stmt->bind_param('s',$code); $stmt->execute(); $stmt->bind_result($id); $found=$stmt->fetch(); $stmt->close(); return $found?(int)$id:null;
    }
}
