<?php

namespace App\Database\Repositories;

use App\Database\DB;
use App\Storage\Filesystem;
use App\Storage\FileNotFoundException;

class MediaRepository
{
    public const PER_PAGE = 21;
    public const PER_PAGE_ADMIN = 27;
    public const ORDER_TIME = 0;
    public const ORDER_NAME = 1;
    public const ORDER_SIZE = 2;

    private $userId;
    private $orderBy = self::ORDER_TIME;
    private string $orderMode = 'DESC';
    private ?string $text = null;
    private ?int $tagId = null;
    private string $type = '';
    private string $from = '';
    private string $to = '';
    private float $pages = 0;
    private array $media = [];

    public function __construct(private DB $db, private Filesystem $storage, private bool $isAdmin)
    {
    }
    public static function make(DB $db, Filesystem $storage, bool $isAdmin): self
    {
        return new self($db, $storage, $isAdmin);
    }
    public function withUserId($id): self
    {
        $this->userId = $id;
        return $this;
    }
    public function orderBy($type = null, $mode = 'ASC'): self
    {
        $this->orderBy = $type ?? self::ORDER_TIME;
        $this->orderMode = strtoupper($mode) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }
    public function search(?string $text): self
    {
        $this->text = $text;
        return $this;
    }
    public function filterByTag($id): self
    {
        $this->tagId = $id !== null && $id !== '' ? (int) $id : null;
        return $this;
    }
    public function filters(string $type = '', string $from = '', string $to = ''): self
    {
        foreach ([$from, $to] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date)) {
                throw new \InvalidArgumentException('Invalid search date.');
            }
        }
        $this->type = $type;
        $this->from = $from;
        $this->to = $to;
        return $this;
    }

    public function run(int $page): self
    {
        $conditions = ['1=1'];
        $params = [];
        if (!$this->isAdmin) {
            $conditions[] = 'u.user_id = ?';
            $params[] = $this->userId;
        }
        if ($this->tagId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM uploads_tags p WHERE p.upload_id = u.id AND p.tag_id = ?)';
            $params[] = $this->tagId;
        }
        foreach (array_slice(preg_split('/\s+/u', trim($this->text ?? '')), 0, 10) as $word) {
            if ($word === '') {
                continue;
            }
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)).'%';
            $fields = ['u.filename', 'u.code', 'u.game', 'u.description', 'u.recorded_at', 'u.timestamp'];
            $clauses = [];
            foreach ($fields as $field) {
                $clauses[] = "LOWER($field) LIKE ? ESCAPE '!'";
                $params[] = $term;
            }
            $clauses[] = "EXISTS (SELECT 1 FROM uploads_tags p JOIN tags t ON t.id=p.tag_id WHERE p.upload_id=u.id AND LOWER(t.name) LIKE ? ESCAPE '!')";
            $params[] = $term;
            $conditions[] = '('.implode(' OR ', $clauses).')';
        }
        if ($this->type === 'video') {
            $extensions = ['mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v', 'mpeg', 'mpg', 'wmv'];
            $conditions[] = '('.implode(' OR ', array_fill(0, count($extensions), 'LOWER(u.storage_path) LIKE ?')).')';
            foreach ($extensions as $ext) {
                $params[] = '%.'.$ext;
            }
        }
        if ($this->from !== '') {
            $conditions[] = 'u.timestamp >= ?';
            $params[] = $this->from.' 00:00:00';
        }
        if ($this->to !== '') {
            $conditions[] = 'u.timestamp < ?';
            $params[] = date('Y-m-d', strtotime($this->to.' +1 day')).' 00:00:00';
        }
        $where = implode(' AND ', $conditions);
        $limit = $this->isAdmin ? self::PER_PAGE_ADMIN : self::PER_PAGE;
        $this->pages = (int) $this->db->query('SELECT COUNT(*) AS count FROM uploads u WHERE '.$where, $params)->fetch()->count / $limit;
        $sql = 'SELECT u.*, users.user_code, users.username FROM uploads u LEFT JOIN users ON u.user_id=users.id WHERE '.$where;
        $sort = $this->orderBy === self::ORDER_NAME ? 'u.filename' : 'u.timestamp';
        if ($this->orderBy !== self::ORDER_SIZE) {
            $sql .= ' ORDER BY '.$sort.' '.$this->orderMode.', u.id '.$this->orderMode.' LIMIT ? OFFSET ?';
            $params = array_merge($params, [$limit, max(0, $page) * $limit]);
        }
        $rows = $this->db->query($sql, $params)->fetchAll();
        foreach ($rows as $row) {
            try {
                $row->size_bytes = $this->storage->getSize($row->storage_path);
                $row->mimetype = $this->storage->getMimetype($row->storage_path);
                $row->size = humanFileSize($row->size_bytes);
            } catch (FileNotFoundException) {
                $row->size_bytes = 0;
                $row->size = null;
                $row->mimetype = null;
            }
            $row->extension = pathinfo($row->storage_path, PATHINFO_EXTENSION);
            $row->tags = [];
        }
        if ($this->orderBy === self::ORDER_SIZE) {
            usort($rows, fn ($a, $b) => ($a->size_bytes <=> $b->size_bytes) * ($this->orderMode === 'ASC' ? 1 : -1));
            $rows = array_slice($rows, max(0, $page) * $limit, $limit);
        }
        $ids = array_column($rows, 'id');
        if ($ids) {
            $tags = $this->db->query('SELECT p.upload_id,t.id,t.name FROM uploads_tags p JOIN tags t ON t.id=p.tag_id WHERE p.upload_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY t.name', $ids)->fetchAll();
            foreach ($rows as $row) {
                foreach ($tags as $tag) {
                    if ($tag->upload_id === $row->id) {
                        $row->tags[$tag->id] = $tag->name;
                    }
                }
            }
        }
        $this->media = $rows;
        return $this;
    }
    public function getMedia(): array
    {
        return $this->media;
    }
    public function getPages(): float
    {
        return $this->pages;
    }
}
