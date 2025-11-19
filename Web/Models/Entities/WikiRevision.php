<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use openvk\Web\Models\RowModel;

final class WikiRevision extends RowModel
{
    protected $tableName = "wiki_revisions";

    public function getId(): int { return (int)$this->getRecord()->id; }
    public function getPageId(): int { return (int)$this->getRecord()->page_id; }
    public function getRev(): int { return (int)$this->getRecord()->rev; }
    public function getAuthorId(): int { return (int)$this->getRecord()->author_id; }
    public function getBodyWiki(): string { return (string)$this->getRecord()->body_wiki; }
    public function getBodyHtml(): ?string { return $this->getRecord()->body_html; }
    public function getCreatedAt(): int { return (int)$this->getRecord()->created_at; }

    public function setPageId(int $id): void { $this->changes['page_id'] = $id; }
    public function setRev(int $rev): void { $this->changes['rev'] = $rev; }
    public function setAuthorId(int $id): void { $this->changes['author_id'] = $id; }
    public function setBodyWiki(string $src): void { $this->changes['body_wiki'] = $src; }
    public function setBodyHtml(?string $html): void { $this->changes['body_html'] = $html; }
    public function setCreatedAt(int $ts): void { $this->changes['created_at'] = $ts; }
}
