<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\{Clubs, WikiPages};
use openvk\Web\Models\Utils\WikiParser;

final class WikiPresenter extends OpenVKPresenter
{
    private $clubs;
    private $wiki;
    private $parser;
    protected $presenterName = "wiki";

    public function __construct(Clubs $clubs, WikiPages $wiki, WikiParser $parser)
    {
        $this->clubs = $clubs;
        $this->wiki  = $wiki;
        $this->parser = $parser;
        parent::__construct();
    }

    public function renderHelp($group): void
    {
        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        $this->template->club = $club;
        $this->template->pages = iterator_to_array($this->wiki->listByGroup($club->getId()));
        $this->template->canModify = !is_null($this->user) && !is_null($this->user->identity) && $club->canBeModifiedBy($this->user->identity);
    }

    private function resolveClub($group)
    {
        if (is_numeric($group)) {
            return $this->clubs->get((int)$group);
        }
        return $this->clubs->getByShortURL((string)$group);
    }

    private function sanitizeSlug(string $input): string
    {
        $slug = preg_replace('~[^A-Za-z0-9._-]+~', '-', $input);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'page';
        }
        return mb_substr($slug, 0, 128);
    }

    private function canViewPage($club, $page): bool
    {
        $viewer = $this->user->identity ?? null;
        return $club->canUserAccessWiki($viewer, $page->getVisibility());
    }

    public function renderList($group): void
    {
        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }

        $viewer = $this->user->identity ?? null;
        if (!$club->canUserAccessWiki($viewer)) {
            $this->flashFail("err", tr("forbidden"), tr("forbidden_comment"));
        }

        $all = iterator_to_array($this->wiki->listByGroup($club->getId()));
        $filtered = [];
        foreach ($all as $p) {
            if ($this->canViewPage($club, $p)) $filtered[] = $p;
        }
        $this->template->club = $club;
        $this->template->canModify = !is_null($this->user) && !is_null($this->user->identity) && $club->canBeModifiedBy($this->user->identity);
        $pinned = $this->wiki->listPinnedIds($club->getId());
        $this->template->pinnedIds = $pinned;
        $this->template->pinnedMap = array_flip($pinned);
        $this->template->pages = $filtered;
    }

    public function renderPage($group, string $slug): void
    {
        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }

        $viewer = $this->user->identity ?? null;
        if (!$club->canUserAccessWiki($viewer)) {
            $this->flashFail("err", tr("forbidden"), tr("forbidden_comment"));
        }

        $page = $this->wiki->getByGroupAndSlug($club->getId(), $slug);
        if (!$page) {
            // Auto-create: managers can go straight to editor to create the page
            if (!is_null($this->user) && !is_null($this->user->identity) && $club->canBeModifiedBy($this->user->identity)) {
                $this->redirect($club->getURL() . "/wiki/" . $slug . "/edit");
            }
            $this->template->club = $club;
            $this->template->slug = $slug;
            $this->template->title = $slug;
            $this->template->html = null;
            $this->template->pages = iterator_to_array($this->wiki->listByGroup($club->getId()));
            $this->template->canModify = !is_null($this->user) && !is_null($this->user->identity) && $club->canBeModifiedBy($this->user->identity);
            $this->template->showMenu = true;
            return;
        }

        if (!$this->canViewPage($club, $page)) {
            $this->flashFail("err", tr("forbidden"), tr("forbidden_comment"));
        }
        $this->template->club = $club;
        $this->template->slug = $page->getSlug();
        $this->template->title = $page->getTitle();
        $this->template->html = $page->getBodyHtml();
        $rawBody = $page->getBodyWiki();
        $this->template->showMenu = stripos($rawBody, '{{notoc}}') === false;
        $all = iterator_to_array($this->wiki->listByGroup($club->getId()));
        $filtered = [];
        foreach ($all as $p) {
            if ($this->canViewPage($club, $p)) $filtered[] = $p;
        }
        $this->template->pages = $filtered;
        $this->template->canModify = !is_null($this->user) && !is_null($this->user->identity) && $club->canBeModifiedBy($this->user->identity);
    }

    public function renderPin($group, int $pageId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();
        $club = $this->resolveClub($group);
        if (!$club) $this->notFound();
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }
        $page = $this->wiki->getById($pageId);
        if (!$page || $page->getGroupId() !== $club->getId()) $this->notFound();
        $this->wiki->pin($club->getId(), $pageId, 0);
        $this->redirect($club->getURL() . "/wiki");
    }

    public function renderUnpin($group, int $pageId): void
    {
        $this->assertUserLoggedIn();
        $club = $this->resolveClub($group);
        if (!$club) $this->notFound();
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }
        $page = $this->wiki->getById($pageId);
        if (!$page || $page->getGroupId() !== $club->getId()) $this->notFound();
        $this->wiki->unpin($club->getId(), $pageId);
        $this->redirect($club->getURL() . "/wiki");
    }

    public function renderDelete($group, string $slug): void
    {
        $this->assertUserLoggedIn();

        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }

        $page = $this->wiki->getByGroupAndSlug($club->getId(), $slug);
        if (!$page) {
            $this->notFound();
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            $this->template->_template = "Wiki/Delete.xml";
            $this->template->club = $club;
            $this->template->page = $page;
            $this->template->slug = $slug;
            return;
        }

        $this->willExecuteWriteAction();
        $this->assertNoCSRF();
        $this->wiki->delete($page);
        $this->flash("succ", tr("information_-1"), tr("wiki_page_deleted"));
        $this->redirect($club->getURL() . "/wiki");
    }

    public function renderEdit($group, string $slug): void
    {
        $this->assertUserLoggedIn();
        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }

        $this->template->club = $club;
        $this->template->slug = $slug;

        $existing = $this->wiki->getByGroupAndSlug($club->getId(), $slug);
        $isPlaceholder = !$existing && $slug === 'new';

        $rawBody = $existing ? $existing->getBodyWiki() : "";
        $hasNotoc = stripos($rawBody, '{{notoc}}') !== false;

        $this->template->title = $existing ? $existing->getTitle() : ($isPlaceholder ? $slug : $slug);
        $this->template->body_wiki = $rawBody;
        $this->template->visibility = $existing ? $existing->getVisibility() : $club->getWikiVisibility();
        $this->template->show_menu = !$hasNotoc;

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->assertNoCSRF();
            $title = trim((string)($this->postParam("title") ?? ""));
            $body  = (string)($this->postParam("body_wiki") ?? "");
            if ($title === "") {
                $this->flashFail("err", tr("error"), tr("error_segmentation"));
            }
            if (strlen($body) > 98304) {
                $this->flashFail("err", tr("error"), tr("error_on_server_side"));
            }
            $visibility = (int)($this->postParam("visibility") ?? $club->getWikiVisibility());
            if ($visibility < 0 || $visibility > 2) $visibility = 0;
            $wantMenu = !is_null($this->postParam('show_menu'));
            if ($wantMenu) {
                $body = preg_replace('/\{\{\s*notoc\s*\}\}\s*/i', '', $body);
            } else {
                if (stripos($body, '{{notoc}}') === false) {
                    $body = "{{notoc}}\n" . ltrim($body);
                }
            }
            $safeHtml = $this->parser->render($body, $club);
            $targetSlug = $isPlaceholder ? $this->sanitizeSlug($title) : $slug;
            $page = $this->wiki->createOrUpdate($club->getId(), $targetSlug, $title, $body, $safeHtml, $visibility);
            $this->wiki->addRevision($page, $this->user->id, $body, $safeHtml);
            $this->flash("succ", tr("changes_saved"), tr("changes_saved"));
            $this->redirect($club->getURL() . "/wiki/" . $targetSlug);
        }
    }

    public function renderHistory($group, string $slug): void
    {
        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        $page = $this->wiki->getByGroupAndSlug($club->getId(), $slug);
        if (!$page) {
            $this->notFound();
        }
        $this->template->club = $club;
        $this->template->slug = $slug;
        $this->template->revisions = iterator_to_array($this->wiki->getRevisions($page));
    }

    public function renderRevert($group, string $slug, int $rev): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();
        $this->assertNoCSRF();

        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }
        $page = $this->wiki->getByGroupAndSlug($club->getId(), $slug);
        if (!$page) {
            $this->notFound();
        }
        $revRow = null;
        foreach ($this->wiki->getRevisions($page) as $r) {
            if ($r->getRev() === $rev) { $revRow = $r; break; }
        }
        if (!$revRow) {
            $this->notFound();
        }
        $this->wiki->createOrUpdate($club->getId(), $slug, $page->getTitle(), $revRow->getBodyWiki(), $revRow->getBodyHtml());
        $this->wiki->addRevision($page, $this->user->id, $revRow->getBodyWiki(), $revRow->getBodyHtml());
        $this->flash("succ", tr("changes_saved"), tr("changes_saved"));
        $this->redirect($club->getURL() . "/wiki/" . $slug);
    }

    public function renderPreview($group): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("HTTP/1.1 400 Bad Request");
            exit;
        }

        $club = $this->resolveClub($group);
        if (!$club) {
            $this->notFound();
        }
        if (is_null($this->user) || is_null($this->user->identity) || !$club->canBeModifiedBy($this->user->identity)) {
            $this->flashFail("err", tr("error_access_denied_short"), tr("error_access_denied"));
        }

        if (\openvk\Web\Util\EventRateLimiter::i()->tryToLimit($this->user->identity, "wiki.preview")) {
            header("HTTP/1.1 429 Too Many Requests");
            exit;
        }

        $title = trim((string)($this->postParam("title") ?? ""));
        $body  = (string)($this->postParam("body_wiki") ?? "");
        if ($title === "" || $body === "") {
            header("HTTP/1.1 400 Bad Request");
            exit;
        }

        if (strlen($body) > 98304) {
            header("HTTP/1.1 413 Payload Too Large");
            exit;
        }

        $this->template->title = $title;
        $this->template->html  = $this->parser->render($body, $club);
    }
}
