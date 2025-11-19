<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use openvk\Web\Models\RowModel;

final class WikiPage extends RowModel
{
    protected $tableName = "wiki_pages";

    public function getId(): int { return (int)$this->getRecord()->id; }
    public function getGroupId(): int { return (int)$this->getRecord()->group_id; }
    public function getSlug(): string { return (string)$this->getRecord()->slug; }
    public function getTitle(): string { return (string)$this->getRecord()->title; }
    public function getBodyWiki(): string { return (string)$this->getRecord()->body_wiki; }
    public function getBodyHtml(): ?string { return $this->getRecord()->body_html; }
    public function isPublished(): bool { return (bool)$this->getRecord()->is_published; }
    public function getVisibility(): int { return (int)($this->getRecord()->visibility ?? 0); }
    public function getCreatedAt(): int { return (int)$this->getRecord()->created_at; }
    public function getUpdatedAt(): int { return (int)$this->getRecord()->updated_at; }

    public function setGroupId(int $id): void { $this->changes['group_id'] = $id; }
    public function setSlug(string $slug): void { $this->changes['slug'] = $slug; }
    public function setTitle(string $title): void { $this->changes['title'] = $title; }
    public function setBodyWiki(string $src): void { $this->changes['body_wiki'] = $src; }
    public function setBodyHtml(?string $html): void { $this->changes['body_html'] = $html; }
    public function setIsPublished(bool $v): void { $this->changes['is_published'] = (int)$v; }
    public function setVisibility(int $v): void { $this->changes['visibility'] = $v; }
    public function setCreatedAt(int $ts): void { $this->changes['created_at'] = $ts; }
    public function setUpdatedAt(int $ts): void { $this->changes['updated_at'] = $ts; }
}
