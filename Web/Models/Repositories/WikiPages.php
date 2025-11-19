<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Entities\{WikiPage, WikiRevision, User};

final class WikiPages
{
    private $context;
    private $pages;
    private $revisions;
    private $pins;

    public function __construct()
    {
        $this->context   = DatabaseConnection::i()->getContext();
        $this->pages     = $this->context->table('wiki_pages');
        $this->revisions = $this->context->table('wiki_revisions');
        $this->pins      = $this->context->table('wiki_pins');
    }

    private function toPage(?ActiveRow $ar): ?WikiPage
    {
        return is_null($ar) ? null : new WikiPage($ar);
    }

    private function toRevision(?ActiveRow $ar): ?WikiRevision
    {
        return is_null($ar) ? null : new WikiRevision($ar);
    }

    public function getById(int $id): ?WikiPage
    {
        return $this->toPage($this->pages->get($id));
    }

    public function getByGroupAndSlug(int $groupId, string $slug): ?WikiPage
    {
        $row = $this->pages->where(['group_id' => $groupId, 'slug' => $slug])->fetch();
        return $this->toPage($row);
    }

    public function listByGroup(int $groupId): \Traversable
    {
        foreach ($this->pages->where('group_id', $groupId)->order('title ASC') as $row) {
            yield new WikiPage($row);
        }
    }

    public function getRevisions(WikiPage $page, int $limit = 100): \Traversable
    {
        foreach ($this->revisions->where('page_id', $page->getId())->order('rev DESC')->limit($limit) as $row) {
            yield new WikiRevision($row);
        }
    }

    public function listPinned(int $groupId, int $limit = 3): \Traversable
    {
        $rows = $this->pins->where('club_id', $groupId)->order('sort ASC')->limit($limit);
        foreach ($rows as $pin) {
            $page = $this->getById((int)$pin->page_id);
            if ($page) yield $page;
        }
    }

    public function listPinnedIds(int $groupId): array
    {
        $ids = [];
        foreach ($this->pins->where('club_id', $groupId) as $pin) {
            $ids[] = (int)$pin->page_id;
        }
        return $ids;
    }

    public function pin(int $groupId, int $pageId, int $sort = 0): void
    {
        // allow only single pinned page per club
        $this->pins->where(['club_id' => $groupId])->delete();
        $this->pins->insert(['club_id' => $groupId, 'page_id' => $pageId, 'sort' => $sort]);
    }

    public function unpin(int $groupId, int $pageId): void
    {
        $this->pins->where(['club_id' => $groupId, 'page_id' => $pageId])->delete();
    }

    public function reorder(int $groupId, int $pageId, int $sort): void
    {
        $this->pins->where(['club_id' => $groupId, 'page_id' => $pageId])->update(['sort' => $sort]);
    }

    public function getLastRevNumber(int $pageId): int
    {
        $rev = $this->revisions->where('page_id', $pageId)->order('rev DESC')->limit(1)->fetch();
        return $rev ? (int)$rev['rev'] : 0;
    }

    public function addRevision(WikiPage $page, int $authorId, string $bodyWiki, ?string $bodyHtml): WikiRevision
    {
        $revNo = $this->getLastRevNumber($page->getId()) + 1;
        $row = $this->revisions->insert([
            'page_id'   => $page->getId(),
            'rev'       => $revNo,
            'author_id' => $authorId,
            'body_wiki' => $bodyWiki,
            'body_html' => $bodyHtml,
            'created_at'=> time(),
        ]);
        return $this->toRevision($row);
    }

    public function createOrUpdate(int $groupId, string $slug, string $title, string $bodyWiki, ?string $bodyHtml, int $visibility = 0): WikiPage
    {
        $now = time();
        $existing = $this->getByGroupAndSlug($groupId, $slug);
        if ($existing) {
            $existing->setTitle($title);
            $existing->setBodyWiki($bodyWiki);
            $existing->setBodyHtml($bodyHtml);
            $existing->setVisibility($visibility);
            $existing->setUpdatedAt($now);
            $existing->save();
            return $existing;
        }
        $row = $this->pages->insert([
            'group_id'   => $groupId,
            'slug'       => $slug,
            'title'      => $title,
            'body_wiki'  => $bodyWiki,
            'body_html'  => $bodyHtml,
            'is_published' => 1,
            'visibility' => $visibility,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->toPage($row);
    }

    public function delete(WikiPage $page): void
    {
        $pageId = $page->getId();
        $this->pins->where('page_id', $pageId)->delete();
        $this->revisions->where('page_id', $pageId)->delete();
        $this->pages->where('id', $pageId)->delete();
    }
}
