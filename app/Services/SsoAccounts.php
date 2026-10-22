<?php

namespace App\Services;

use App\Database\DB;
use RuntimeException;

class SsoAccounts
{
    public function __construct(private DB $db)
    {
    }

    public function resolve(string $issuer, array $claims, ?int $linkUserId = null, bool $autoRegister = false): object
    {
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new RuntimeException('The provider returned an invalid subject.');
        }
        $key = hash('sha256', json_encode([$issuer, $claims['sub']], JSON_THROW_ON_ERROR));
        $identity = $this->db->query('SELECT * FROM sso_identities WHERE identity_key=?', $key)->fetch();
        if ($identity) {
            if ($linkUserId !== null && $identity->user_id !== $linkUserId) {
                throw new RuntimeException('This identity is linked to another account.');
            }
            $user = $this->db->query('SELECT * FROM users WHERE id=?', $identity->user_id)->fetch();
        } elseif ($linkUserId !== null) {
            $user = $this->db->query('SELECT * FROM users WHERE id=?', $linkUserId)->fetch();
        } else {
            $email = $claims['email'] ?? '';
            if (!$autoRegister || ($claims['email_verified'] ?? false) !== true || !is_string($email) ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 30 ||
                $this->db->query('SELECT id FROM users WHERE LOWER(email)=LOWER(?)', $email)->fetch()) {
                throw new RuntimeException('Sign in locally and link Authentik from your profile, or ask an administrator to enable SSO registration. New accounts require a verified email of at most 30 bytes.');
            }
            $name = substr(preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($claims['preferred_username'] ?? strtok($email, '@'))), 0, 20) ?: 'sso';
            while ($this->db->query('SELECT id FROM users WHERE username=?', $name)->fetch()) {
                $name = substr($name, 0, 20).'-'.bin2hex(random_bytes(3));
            }
            do {
                $userCode = humanRandomString(5);
            } while ($this->db->query('SELECT id FROM users WHERE user_code=?', $userCode)->fetch());
            $defaultQuota = $this->db->query("SELECT value FROM settings WHERE `key`='default_user_quota'")->fetch()->value ?? -1;
            $this->db->query('INSERT INTO users(email,username,password,user_code,token,active,is_admin,max_disk_quota) VALUES(?,?,?,?,?,1,0,?)', [
                $email, $name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $userCode, bin2hex(random_bytes(32)), (int) $defaultQuota,
            ]);
            $user = $this->db->query('SELECT * FROM users WHERE id=?', (int) $this->db->getPdo()->lastInsertId())->fetch();
        }
        if (!$user || !$user->active) {
            throw new RuntimeException('This account is disabled.');
        }
        if (!$identity) {
            $this->db->query('INSERT INTO sso_identities(identity_key,user_id,issuer,subject) VALUES(?,?,?,?)', [$key, $user->id, $issuer, $claims['sub']]);
        }
        return $user;
    }
}
