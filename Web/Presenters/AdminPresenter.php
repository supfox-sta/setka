<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Database\Log;
use Chandler\Database\Logs;
use openvk\Web\Models\Entities\{Voucher, Gift, GiftCategory, User, BannedLink};
use openvk\Web\Models\Repositories\{Audios,
    ChandlerGroups,
    ChandlerUsers,
    Users,
    Clubs,
    Util\EntityStream,
    Vouchers,
    Gifts,
    BannedLinks,
    Bans,
    Photos,
    Posts,
    Videos};
use openvk\Web\Models\Repositories\Artists as ArtistsRepo;
use openvk\Web\Models\Entities\Photo;
use Chandler\Database\DatabaseConnection;
use Exception;

final class AdminPresenter extends OpenVKPresenter
{
    private $users;
    private $clubs;
    private $vouchers;
    private $gifts;
    private $bannedLinks;
    private $chandlerGroups;
    private $audios;
    private $logs;
    private $artists;

    public function __construct(Users $users, Clubs $clubs, Vouchers $vouchers, Gifts $gifts, BannedLinks $bannedLinks, ChandlerGroups $chandlerGroups, Audios $audios)
    {
        $this->users    = $users;
        $this->clubs    = $clubs;
        $this->vouchers = $vouchers;
        $this->gifts    = $gifts;
        $this->bannedLinks = $bannedLinks;
        $this->chandlerGroups = $chandlerGroups;
        $this->audios = $audios;
        $this->logs = DatabaseConnection::i()->getContext()->table("ChandlerLogs");
        $this->artists = new ArtistsRepo();

        parent::__construct();
    }

    private function warnIfNoCommerce(): void
    {
        if (!OPENVK_ROOT_CONF["openvk"]["preferences"]["commerce"]) {
            $this->flash("warn", tr("admin_commerce_disabled"), tr("admin_commerce_disabled_desc"));
        }
    }

    private function warnIfLongpoolBroken(): void
    {
        bdump(is_writable(CHANDLER_ROOT . '/tmp/events.bin'));
        if (file_exists(CHANDLER_ROOT . '/tmp/events.bin') == false || is_writable(CHANDLER_ROOT . '/tmp/events.bin') == false) {
            $this->flash("warn", tr("admin_longpool_broken"), tr("admin_longpool_broken_desc", CHANDLER_ROOT . '/tmp/events.bin'));
        }
    }

    private function searchResults(object $repo, &$count)
    {
        $query = $this->queryParam("q") ?? "";
        $page  = (int) ($this->queryParam("p") ?? 1);

        $count = $repo->find($query)->size();
        return $repo->find($query)->page($page, 20);
    }

    private function searchPlaylists(&$count)
    {
        $query = $this->queryParam("q") ?? "";
        $page  = (int) ($this->queryParam("p") ?? 1);

        $count = $this->audios->findPlaylists($query)->size();
        return $this->audios->findPlaylists($query)->page($page, 20);
    }

    public function onStartup(): void
    {
        parent::onStartup();

        $this->assertPermission("admin", "access", -1);
    }

    public function renderIndex(): void
    {
        $this->warnIfLongpoolBroken();
    }

    public function renderUsers(): void
    {
        $this->template->users = $this->searchResults($this->users, $this->template->count);
    }

    public function renderUser(int $id): void
    {
        $user = $this->users->get($id);
        if (!$user) {
            $this->notFound();
        }

        $this->template->user = $user;
        $this->template->c_groups_list = (new ChandlerGroups())->getList();
        $this->template->c_memberships = $this->chandlerGroups->getUsersMemberships($user->getChandlerGUID());

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        if ($this->template->mode === 'artist_requests') {
            $page = (int) ($this->queryParam('p') ?? 1);
            $perPage = 25;
            $q = trim((string) ($this->queryParam('q') ?? ''));
            $tickets = $ctx->table('tickets')->where('deleted', 0)->where('type', 0);
            if ($q !== '') {
                $tickets = $tickets->where('(name COLLATE utf8mb4_unicode_ci LIKE ? OR text COLLATE utf8mb4_unicode_ci LIKE ?)', "%$q%", "%$q%");
            }
            $tickets = $tickets->order('created DESC');
            if (($this->queryParam('format') ?? '') === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                $rows = iterator_to_array($tickets->page($page, $perPage));
                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'id' => (int)$r->id,
                        'user_id' => (int)$r->user_id,
                        'name' => (string)$r->name,
                        'text' => (string)$r->text,
                        'created' => (int)$r->created,
                    ];
                }
                echo json_encode(['items' => $out, 'page' => $page], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rows = iterator_to_array($tickets->page($page, $perPage));
            if (count($rows) === 0) {
                // fallback: show last 10 tickets for diagnostics
                $rows = iterator_to_array($ctx->table('tickets')->order('id DESC')->limit(10));
                $this->template->count = 10;
            } else {
                $this->template->count = $ctx->table('tickets')->where('deleted',0)->where('type',0)->count('*');
            }
            $this->template->artistTickets = $rows;
            // debug: last tickets snapshot
            $this->template->debugTickets = iterator_to_array($ctx->table('tickets')->order('id DESC')->limit(3));
            $this->template->page = $page;
            $this->template->q = $q;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->assertNoCSRF();
                $op = $this->postParam('act') ?? '';
                $tid = (int) ($this->postParam('ticket_id') ?? 0);
                $ticket = $tid > 0 ? $ctx->table('tickets')->get($tid) : null;
                if ($ticket && str_starts_with((string)$ticket->name, 'Заявка:') && (int)$ticket->type === 0) {
                    if ($op === 'approve_artist_request') {
                        $text = (string) $ticket->text;
                        $name = null; $bio = null;
                        if (preg_match('/Имя исполнителя:\\s*(.+)/u', $text, $m)) { $name = trim($m[1]); }
                        if (preg_match('/Био:\\s*(.+)/u', $text, $m2)) { $bio = trim($m2[1]); }
                        if (!$name || $name === '') { $this->flash('err', tr('error'), 'Имя исполнителя не найдено в заявке'); $this->redirect('/admin/music?act=artist_requests'); }
                        // create artist
                        $row = $ctx->table('artists')->insert([
                            'name' => $name,
                            'bio' => $bio ?: null,
                            'created' => time(),
                            'edited' => time(),
                        ]);
                        $artistId = (int) $row->id;
                        // grant membership
                        try {
                            $ctx->table('artist_members')->insert([
                                'artist_id' => $artistId,
                                'user_id' => (int)$ticket->user_id,
                                'role' => 'owner',
                                'created' => time(),
                            ]);
                        } catch (Exception $e) {}
                        // add PD comment and mark as answered
                        try {
                            $ctx->table('tickets_comments')->insert([
                                'user_id' => $this->user->id,
                                'user_type' => 1,
                                'text' => 'Заявка одобрена. Создан артист ID ' . $artistId,
                                'ticket_id' => (int)$ticket->id,
                                'created' => time(),
                            ]);
                        } catch (\Throwable $e) {}
                        $ticket->update(['type' => 1]);
                        $this->flash('succ', tr('changes_saved'));
                    } elseif ($op === 'reject_artist_request') {
                        // add PD comment and mark as answered
                        try {
                            $ctx->table('tickets_comments')->insert([
                                'user_id' => $this->user->id,
                                'user_type' => 1,
                                'text' => 'Заявка отклонена.',
                                'ticket_id' => (int)$ticket->id,
                                'created' => time(),
                            ]);
                        } catch (\Throwable $e) {}
                        $ticket->update(['type' => 1]);
                        $this->flash('succ', tr('changes_saved'));
                    }
                }
                $this->redirect('/admin/music?act=artist_requests&p=' . $page . ($q!==''?('&q='.urlencode($q)) : ''));
            }
            return;
        }

        if ($this->template->mode === 'artist_requests') {
            $page = (int) ($this->queryParam('p') ?? 1);
            $perPage = 25;
            $q = trim((string) ($this->queryParam('q') ?? ''));
            $tickets = $ctx->table('tickets')->where('deleted', 0)->where('type', 0)
                ->where('(name = ? OR name COLLATE utf8mb4_unicode_ci LIKE ?)', 'Заявка: Стать исполнителем', 'Заявка:%');
            if ($q !== '') {
                $tickets = $tickets->where('text COLLATE utf8mb4_unicode_ci LIKE ?', "%$q%");
            }
            $tickets = $tickets->order('created DESC');
            $this->template->artistTickets = iterator_to_array($tickets->page($page, $perPage));
            $this->template->count = $ctx->table('tickets')->where('deleted',0)->where('type',0)
                ->where('(name = ? OR name COLLATE utf8mb4_unicode_ci LIKE ?)', 'Заявка: Стать исполнителем', 'Заявка:%')->count('*');
            $this->template->page = $page;
            $this->template->q = $q;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->assertNoCSRF();
                $op = $this->postParam('act') ?? '';
                $tid = (int) ($this->postParam('ticket_id') ?? 0);
                $ticket = $tid > 0 ? $ctx->table('tickets')->get($tid) : null;
                if ($ticket && str_starts_with((string)$ticket->name, 'Заявка:') && (int)$ticket->type === 0) {
                    if ($op === 'approve_artist_request') {
                        $text = (string) $ticket->text;
                        $name = null; $bio = null;
                        if (preg_match('/Имя исполнителя:\\s*(.+)/u', $text, $m)) { $name = trim($m[1]); }
                        if (preg_match('/Био:\\s*(.+)/u', $text, $m2)) { $bio = trim($m2[1]); }
                        if (!$name || $name === '') { $this->flash('err', tr('error'), 'Имя исполнителя не найдено в заявке'); $this->redirect('/admin/music?act=artist_requests'); }
                        $row = $ctx->table('artists')->insert([
                            'name' => $name,
                            'bio' => $bio ?: null,
                            'created' => time(),
                            'edited' => time(),
                        ]);
                        $artistId = (int) $row->id;
                        try {
                            $ctx->table('artist_members')->insert([
                                'artist_id' => $artistId,
                                'user_id' => (int)$ticket->user_id,
                                'role' => 'owner',
                                'created' => time(),
                            ]);
                        } catch (\Throwable $e) {}
                        try {
                            $ctx->table('tickets_comments')->insert([
                                'user_id' => $this->user->id,
                                'user_type' => 1,
                                'text' => 'Заявка одобрена. Создан артист ID ' . $artistId,
                                'ticket_id' => (int)$ticket->id,
                                'created' => time(),
                            ]);
                        } catch (\Throwable $e) {}
                        $ticket->update(['type' => 1]);
                        $this->flash('succ', tr('changes_saved'));
                    } elseif ($op === 'reject_artist_request') {
                        try {
                            $ctx->table('tickets_comments')->insert([
                                'user_id' => $this->user->id,
                                'user_type' => 1,
                                'text' => 'Заявка отклонена.',
                                'ticket_id' => (int)$ticket->id,
                                'created' => time(),
                            ]);
                        } catch (\Throwable $e) {}
                        $ticket->update(['type' => 1]);
                        $this->flash('succ', tr('changes_saved'));
                    }
                }
                $this->redirect('/admin/music?act=artist_requests&p=' . $page . ($q!==''?('&q='.urlencode($q)) : ''));
            }
            return;
        }

        switch ($_POST["act"] ?? "info") {
            default:
            case "info":
                $user->setFirst_Name($this->postParam("first_name"));
                $user->setLast_Name($this->postParam("last_name"));
                $user->setPseudo($this->postParam("nickname"));
                $user->setStatus($this->postParam("status"));
                $user->setHide_Global_Feed(empty($this->postParam("hide_global_feed") ? 0 : 1));
                if (!$user->setShortCode(empty($this->postParam("shortcode")) ? null : $this->postParam("shortcode"))) {
                    $this->flash("err", tr("error"), tr("error_shorturl_incorrect"));
                }
                $user->changeEmail($this->postParam("email"));
                if ($user->onlineStatus() != $this->postParam("online")) {
                    $user->setOnline(intval($this->postParam("online")));
                }
                $user->setVerified(empty($this->postParam("verify") ? 0 : 1));
                if ($this->postParam("add-to-group")) {
                    if (!(new ChandlerGroups())->isUserAMember($this->postParam("add-to-group"), $user->getChandlerGUID())) {
                        $query = "INSERT INTO `ChandlerACLRelations` (`user`, `group`) VALUES ('" . $user->getChandlerGUID() . "', '" . $this->postParam("add-to-group") . "')";
                        DatabaseConnection::i()->getConnection()->query($query);
                    } else {
                        $this->flash("err", tr("error"), tr("c_user_is_already_in_group"));
                    }
                }
                if ($this->postParam("password")) {
                    $user->getChandlerUser()->updatePassword($this->postParam("password"));
                }

                $user->save();

                break;
        }
    }

    public function renderClubs(): void
    {
        $this->template->clubs = $this->searchResults($this->clubs, $this->template->count);
    }

    public function renderClub(int $id): void
    {
        $club = $this->clubs->get($id);
        if (!$club) {
            $this->notFound();
        }

        $this->template->mode = in_array($this->queryParam("act"), ["main", "ban", "followers"]) ? $this->queryParam("act") : "main";

        $this->template->club = $club;

        $this->template->followers = $this->template->club->getFollowers((int) ($this->queryParam("p") ?? 1));

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        switch ($this->queryParam("act")) {
            default:
            case "main":
                $club->setOwner($this->postParam("id_owner"));
                $club->setName($this->postParam("name"));
                $club->setAbout($this->postParam("about"));
                $club->setShortCode($this->postParam("shortcode"));
                $club->setVerified(empty($this->postParam("verify") ? 0 : 1));
                $club->setHide_From_Global_Feed(empty($this->postParam("hide_from_global_feed") ? 0 : 1));
                $club->setEnforce_Hiding_From_Global_Feed(empty($this->postParam("enforce_hiding_from_global_feed") ? 0 : 1));
                $club->save();
                break;
            case "ban":
                $reason = mb_strlen(trim($this->postParam("ban_reason"))) > 0 ? $this->postParam("ban_reason") : null;
                $club->setBlock_reason($reason);
                $club->save();
                break;
        }
    }

    public function renderVouchers(): void
    {
        $this->warnIfNoCommerce();

        $this->template->count    = $this->vouchers->size();
        $this->template->vouchers = iterator_to_array($this->vouchers->enumerate((int) ($this->queryParam("p") ?? 1)));
    }

    public function renderVoucher(int $id): void
    {
        $this->warnIfNoCommerce();

        $voucher = null;
        $this->template->form = (object) [];
        if ($id === 0) {
            $this->template->form->id     = 0;
            $this->template->form->token  = null;
            $this->template->form->coins  = 0;
            $this->template->form->rating = 0;
            $this->template->form->usages = -1;
            $this->template->form->users  = [];
        } else {
            $voucher = $this->vouchers->get($id);
            if (!$voucher) {
                $this->notFound();
            }

            $this->template->form->id     = $voucher->getId();
            $this->template->form->token  = $voucher->getToken();
            $this->template->form->coins  = $voucher->getCoins();
            $this->template->form->rating = $voucher->getRating();
            $this->template->form->usages = $voucher->getRemainingUsages();
            $this->template->form->users  = iterator_to_array($voucher->getUsers());

            if ($this->template->form->usages === INF) {
                $this->template->form->usages = -1;
            } else {
                $this->template->form->usages = (int) $this->template->form->usages;
            }
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $voucher ??= new Voucher();
        $voucher->setCoins((int) $this->postParam("coins"));
        $voucher->setRating((int) $this->postParam("rating"));
        $voucher->setRemainingUsages($this->postParam("usages") === '-1' ? INF : ((int) $this->postParam("usages")));
        if (!empty($tok = $this->postParam("token")) && strlen($tok) === 24) {
            $voucher->setToken($tok);
        }

        $voucher->save();

        $this->redirect("/admin/vouchers/id" . $voucher->getId());
    }

    public function renderGiftCategories(): void
    {
        $this->warnIfNoCommerce();

        $this->template->act        = $this->queryParam("act") ?? "list";
        $this->template->categories = iterator_to_array($this->gifts->getCategories((int) ($this->queryParam("p") ?? 1), null, $this->template->count));
    }

    public function renderGiftCategory(string $slug, int $id): void
    {
        $this->warnIfNoCommerce();

        $cat = null;
        $gen = false;
        if ($id !== 0) {
            $cat = $this->gifts->getCat($id);
            if (!$cat) {
                $this->notFound();
            } elseif ($cat->getSlug() !== $slug) {
                $this->redirect("/admin/gifts/" . $cat->getSlug() . "." . $id . ".meta");
            }
        } else {
            $gen = true;
            $cat = new GiftCategory();
        }

        $this->template->form = (object) [];
        $this->template->form->id        = $id;
        $this->template->form->languages = [];
        foreach (getLanguages() as $language) {
            $language = (object) $language;
            $this->template->form->languages[$language->code] = (object) [];

            $this->template->form->languages[$language->code]->name        = $gen ? "" : ($cat->getName($language->code, true) ?? "");
            $this->template->form->languages[$language->code]->description = $gen ? "" : ($cat->getDescription($language->code, true) ?? "");
        }

        $this->template->form->languages["master"] = (object) [
            "name"        => $gen ? "Unknown Name" : $cat->getName(),
            "description" => $gen ? "" : $cat->getDescription(),
        ];

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        if ($gen) {
            $cat->setAutoQuery(null);
            $cat->save();
        }

        $cat->setName("_", $this->postParam("name_master"));
        $cat->setDescription("_", $this->postParam("description_master"));
        foreach (getLanguages() as $language) {
            $code = $language["code"];
            if (!empty($this->postParam("name_$code") ?? null)) {
                $cat->setName($code, $this->postParam("name_$code"));
            }

            if (!empty($this->postParam("description_$code") ?? null)) {
                $cat->setDescription($code, $this->postParam("description_$code"));
            }
        }

        $this->redirect("/admin/gifts/" . $cat->getSlug() . "." . $cat->getId() . ".meta");
    }

    public function renderGifts(string $catSlug, int $catId): void
    {
        $this->warnIfNoCommerce();

        $cat = $this->gifts->getCat($catId);
        if (!$cat) {
            $this->notFound();
        } elseif ($cat->getSlug() !== $catSlug) {
            $this->redirect("/admin/gifts/" . $cat->getSlug() . "." . $catId . "/");
        }

        $this->template->cat   = $cat;
        $this->template->gifts = iterator_to_array($cat->getGifts((int) ($this->queryParam("p") ?? 1), null, $this->template->count));
    }

    public function renderGift(int $id): void
    {
        $this->warnIfNoCommerce();

        $gift = $this->gifts->get($id);
        $act  = $this->queryParam("act") ?? "edit";
        switch ($act) {
            case "delete":
                $this->assertNoCSRF();
                if (!$gift) {
                    $this->notFound();
                }

                $gift->delete();
                $this->flashFail("succ", tr("admin_gift_moved_successfully"), tr("admin_gift_moved_to_recycle"));
                break;
            case "copy":
            case "move":
                $this->assertNoCSRF();
                if (!$gift) {
                    $this->notFound();
                }

                $catFrom = $this->gifts->getCat((int) ($this->queryParam("from") ?? 0));
                $catTo   = $this->gifts->getCat((int) ($this->queryParam("to") ?? 0));
                if (!$catFrom || !$catTo || !$catFrom->hasGift($gift)) {
                    $this->badRequest();
                }

                if ($act === "move") {
                    $catFrom->removeGift($gift);
                }

                $catTo->addGift($gift);

                $name = $catTo->getName();
                $this->flash("succ", tr("admin_gift_moved_successfully"), "This gift will now be in <b>$name</b>.");
                $this->redirect("/admin/gifts/" . $catTo->getSlug() . "." . $catTo->getId() . "/");
                break;
            default:
            case "edit":
                $gen = false;
                if (!$gift) {
                    $gen  = true;
                    $gift = new Gift();
                }

                $this->template->form = (object) [];
                $this->template->form->id     = $id;
                $this->template->form->name   = $gen ? "New Gift (1)" : $gift->getName();
                $this->template->form->price  = $gen ? 0 : $gift->getPrice();
                $this->template->form->usages = $gen ? 0 : $gift->getUsages();
                $this->template->form->limit  = $gen ? -1 : ($gift->getLimit() === INF ? -1 : $gift->getLimit());
                $this->template->form->pic    = $gen ? null : $gift->getImage(Gift::IMAGE_URL);

                if ($_SERVER["REQUEST_METHOD"] !== "POST") {
                    return;
                }

                $limit = $this->postParam("limit") ?? $this->template->form->limit;
                $limit = $limit == "-1" ? INF : (float) $limit;
                $gift->setLimit($limit, is_null($this->postParam("reset_limit")) ? Gift::PERIOD_SET_IF_NONE : Gift::PERIOD_SET);

                $gift->setName($this->postParam("name"));
                $gift->setPrice((int) $this->postParam("price"));
                $gift->setUsages((int) $this->postParam("usages"));
                if (isset($_FILES["pic"]) && $_FILES["pic"]["error"] === UPLOAD_ERR_OK) {
                    if (!$gift->setImage($_FILES["pic"]["tmp_name"])) {
                        $this->flashFail("err", tr("error_when_saving_gift"), tr("error_when_saving_gift_bad_image"));
                    }
                } elseif ($gen) {
                    # If there's no gift pic but it's newly created
                    $this->flashFail("err", tr("error_when_saving_gift"), tr("error_when_saving_gift_no_image"));
                }

                $gift->save();

                if ($gen && !is_null($cat = $this->postParam("_cat"))) {
                    $cat = $this->gifts->getCat((int) $cat);
                    if (!is_null($cat)) {
                        $cat->addGift($gift);
                    }
                }

                $this->redirect("/admin/gifts/id" . $gift->getId());
        }
    }

    public function renderFiles(): void {}

    public function renderQuickBan(int $id): void
    {
        $this->assertNoCSRF();

        if (str_contains($this->queryParam("reason"), "*")) {
            exit(json_encode([ "error" => "Incorrect reason" ]));
        }

        $unban_time = strtotime($this->queryParam("date")) ?: "permanent";

        $user = $this->users->get($id);
        if (!$user) {
            exit(json_encode([ "error" => "User does not exist" ]));
        }

        if ($this->queryParam("incr")) {
            $unban_time = time() + $user->getNewBanTime();
        }

        $user->ban($this->queryParam("reason"), true, $unban_time, $this->user->identity->getId());
        exit(json_encode([ "success" => true, "reason" => $this->queryParam("reason") ]));
    }

    public function renderQuickUnban(int $id): void
    {
        $this->assertNoCSRF();

        $user = $this->users->get($id);
        if (!$user) {
            exit(json_encode([ "error" => "User does not exist" ]));
        }

        $ban = (new Bans())->get((int) $user->getRawBanReason());
        if (!$ban || $ban->isOver()) {
            exit(json_encode([ "error" => "User is not banned" ]));
        }

        $ban->setRemoved_Manually(true);
        $ban->setRemoved_By($this->user->identity->getId());
        $ban->save();

        $user->setBlock_Reason(null);
        // $user->setUnblock_time(NULL);
        $user->save();
        exit(json_encode([ "success" => true ]));
    }

    public function renderQuickWarn(int $id): void
    {
        $this->assertNoCSRF();

        $user = $this->users->get($id);
        if (!$user) {
            exit(json_encode([ "error" => "User does not exist" ]));
        }

        $user->adminNotify("⚠️ " . $this->queryParam("message"));
        exit(json_encode([ "message" => $this->queryParam("message") ]));
    }

    public function renderBannedLinks(): void
    {
        $this->template->links = $this->bannedLinks->getList((int) $this->queryParam("p") ?: 1);
        $this->template->users = new Users();
    }

    public function renderBannedLink(int $id): void
    {
        $this->template->form = (object) [];

        if ($id === 0) {
            $this->template->form->id     = 0;
            $this->template->form->link   = null;
            $this->template->form->reason = null;
        } else {
            $link = (new BannedLinks())->get($id);
            if (!$link) {
                $this->notFound();
            }

            $this->template->form->id     = $link->getId();
            $this->template->form->link   = $link->getDomain();
            $this->template->form->reason = $link->getReason();
            $this->template->form->regexp = $link->getRawRegexp();
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $link = (new BannedLinks())->get($id);

        $new_domain = parse_url($this->postParam("link"))["host"];
        $new_reason = $this->postParam("reason") ?: null;

        $lid = $id;

        if ($link) {
            $link->setDomain($new_domain ?? $this->postParam("link"));
            $link->setReason($new_reason);
            $link->setRegexp_rule(mb_strlen(trim($this->postParam("regexp"))) > 0 ? $this->postParam("regexp") : "");
            $link->save();
        } else {
            if (!$new_domain) {
                $this->flashFail("err", tr("error"), tr("admin_banned_link_not_specified"));
            }

            $link = new BannedLink();
            $link->setDomain($new_domain);
            $link->setReason($new_reason);
            $link->setRegexp_rule(mb_strlen(trim($this->postParam("regexp"))) > 0 ? $this->postParam("regexp") : "");
            $link->setInitiator($this->user->identity->getId());
            $link->save();

            $lid = $link->getId();
        }

        $this->redirect("/admin/bannedLink/id" . $lid);
    }

    public function renderUnbanLink(int $id): void
    {
        $link = (new BannedLinks())->get($id);

        if (!$link) {
            $this->flashFail("err", tr("error"), tr("admin_banned_link_not_found"));
        }

        $link->delete(false);

        $this->redirect("/admin/bannedLinks");
    }

    public function renderBansHistory(int $user_id): void
    {
        $user = (new Users())->get($user_id);
        if (!$user) {
            $this->notFound();
        }

        $this->template->bans = (new Bans())->getByUser($user_id);
    }

    public function renderChandlerGroups(): void
    {
        $this->template->groups = (new ChandlerGroups())->getList();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $req = "INSERT INTO `ChandlerGroups` (`name`) VALUES ('" . $this->postParam("name") . "')";
        DatabaseConnection::i()->getConnection()->query($req);
    }

    public function renderChandlerGroup(string $UUID): void
    {
        $DB = DatabaseConnection::i()->getConnection();

        if (is_null($DB->query("SELECT * FROM `ChandlerGroups` WHERE `id` = '$UUID'")->fetch())) {
            $this->flashFail("err", tr("error"), tr("c_group_not_found"));
        }

        $this->template->group = (new ChandlerGroups())->get($UUID);
        $this->template->mode = in_array(
            $this->queryParam("act"),
            [
                "main",
                "members",
                "permissions",
                "removeMember",
                "removePermission",
                "delete",
            ]
        ) ? $this->queryParam("act") : "main";
        $this->template->members = (new ChandlerGroups())->getMembersById($UUID);
        $this->template->perms = (new ChandlerGroups())->getPermissionsById($UUID);

        if ($this->template->mode == "removeMember") {
            $where = "`user` = '" . $this->queryParam("uid") . "' AND `group` = '$UUID'";

            if (is_null($DB->query("SELECT * FROM `ChandlerACLRelations` WHERE " . $where)->fetch())) {
                $this->flashFail("err", tr("error"), tr("c_user_is_not_in_group"));
            }

            $DB->query("DELETE FROM `ChandlerACLRelations` WHERE " . $where);
            $this->flashFail("succ", tr("changes_saved"), tr("c_user_removed_from_group"));
        } elseif ($this->template->mode == "removePermission") {
            $where = "`model` = '" . trim(addslashes($this->queryParam("model"))) . "' AND `permission` = '" . $this->queryParam("perm") . "' AND `group` = '$UUID'";

            if (is_null($DB->query("SELECT * FROM `ChandlerACLGroupsPermissions WHERE $where`"))) {
                $this->flashFail("err", tr("error"), tr("c_permission_not_found"));
            }

            $DB->query("DELETE FROM `ChandlerACLGroupsPermissions` WHERE $where");
            $this->flashFail("succ", tr("changes_saved"), tr("c_permission_removed_from_group"));
        } elseif ($this->template->mode == "delete") {
            $DB->query("DELETE FROM `ChandlerGroups` WHERE `id` = '$UUID'");
            $DB->query("DELETE FROM `ChandlerACLGroupsPermissions` WHERE `group` = '$UUID'");
            $DB->query("DELETE FROM `ChandlerACLRelations` WHERE `group` = '$UUID'");

            $this->flashFail("succ", tr("changes_saved"), tr("c_group_removed"));
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $req = "";

        if ($this->template->mode == "main") {
            if ($this->postParam("delete")) {
                $req = "DELETE FROM `ChandlerGroups` WHERE `id`='$UUID'";
            } else {
                $req = "UPDATE `ChandlerGroups` SET `name`='" . $this->postParam('name') . "' , `color`='" . $this->postParam("color") . "' WHERE `id`='$UUID'";
            }
        }

        if ($this->template->mode == "members") {
            if ($this->postParam("uid")) {
                if (is_null((new ChandlerUsers())->getById($this->postParam("uid")))) {
                    $this->flashFail("err", tr("error"), tr("profile_not_found"));
                }
                if ((new ChandlerGroups())->isUserAMember($UUID, $this->postParam("uid"))) {
                    $this->flashFail("err", tr("error"), tr("c_user_is_already_in_group"));
                }
            }
        }

        $req = "INSERT INTO `ChandlerACLRelations` (`user`, `group`, `priority`) VALUES ('" . $this->postParam("uid") . "', '$UUID', 32)";

        if ($this->template->mode == "permissions") {
            $req = "INSERT INTO `ChandlerACLGroupsPermissions` (`group`, `model`, `permission`, `context`) VALUES ('$UUID', '" . trim(addslashes($this->postParam("model"))) . "', '" . $this->postParam("permission") . "', 0)";
        }

        $DB->query($req);
        $this->flashFail("succ", tr("changes_saved"));
    }

    public function renderChandlerUser(string $UUID): void
    {
        if (!$UUID) {
            $this->notFound();
        }

        $c_user = (new ChandlerUsers())->getById($UUID);
        $user = $this->users->getByChandlerUser($c_user);
        if (!$user) {
            $this->notFound();
        }

        $this->redirect("/admin/users/id" . $user->getId());
    }

    public function renderMusic(): void
    {
        $act = $this->queryParam("act");
        $this->template->mode = in_array($act, ["audios", "playlists", "artists", "artist", "moderation", "artist_requests"]) ? $act : "audios";

        if ($this->template->mode === "audios") {
            $this->template->audios = $this->searchResults($this->audios, $this->template->count);
            return;
        }

        if ($this->template->mode === "playlists") {
            $this->template->playlists = $this->searchPlaylists($this->template->count);
            return;
        }

        $ctx = DatabaseConnection::i()->getContext();
        if ($this->template->mode === "artists") {
            $page = (int) ($this->queryParam("p") ?? 1);
            $q = trim((string) ($this->queryParam("q") ?? ""));
            $tbl = $ctx->table('artists');
            if ($q !== '') {
                $tbl = $tbl->where('name LIKE ?', "%$q%");
            }
            $tbl = $tbl->order('id DESC');
            $this->template->artists = iterator_to_array($tbl->page($page, 20));
            $this->template->count = $ctx->table('artists')->count('*');
            $this->template->q = $q;
            return;
        }

        if ($this->template->mode === "moderation") {
            $page = (int) ($this->queryParam('p') ?? 1);
            $perPage = 25;
            $q = trim((string) ($this->queryParam('q') ?? ''));
            $tbl = $ctx->table('audios')->where('status', 'pending')->where(['deleted' => 0]);
            if ($q !== '') {
                $like = "%$q%";
                $tbl = $tbl->where('CONCAT_WS(" ", performer, name) LIKE ?', $like);
            }
            $tbl = $tbl->order('created DESC');
            $this->template->pendingAudios = iterator_to_array($tbl->page($page, $perPage));
            $this->template->count = $ctx->table('audios')->where('status','pending')->count('*');
            $this->template->page = $page;
            $this->template->q = $q;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->assertNoCSRF();
                $op = $this->postParam('act') ?? '';
                $aid = (int) ($this->postParam('audio_id') ?? 0);
                if ($aid > 0 && in_array($op, ['approve_audio','reject_audio'])) {
                    $audio = $this->audios->get($aid);
                    if ($audio) {
                        $audio->setStatus($op === 'approve_audio' ? 'approved' : 'rejected');
                        $audio->save();
                        $this->flash('succ', tr('changes_saved'));
                    }
                }
                $this->redirect('/admin/music?act=moderation&p=' . $page . ($q!==''?('&q='.urlencode($q)) : ''));
            }
            return;
        }

        if ($this->template->mode === "artist") {
            $id = (int) ($this->queryParam('id') ?? 0);
            $artist = $id > 0 ? $ctx->table('artists')->get($id) : null;
            if ($id > 0 && !$artist) {
                $this->notFound();
            }
            $this->template->artist = $artist;
            $this->template->id = $id;
            // Avatar preview and tracks list
            $this->template->artistAvatarUrl = null;
            if ($artist && !empty($artist->avatar_photo_id)) {
                try {
                    $ph = (new Photos())->get((int) $artist->avatar_photo_id);
                    if ($ph) $this->template->artistAvatarUrl = $ph->getURLBySizeId("tiny");
                } catch (\Throwable $e) {}
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->assertNoCSRF();
                $op = $this->postParam('act') ?? 'save';
                $this->flash('succ', 'POST artist op=' . $op);
                if ($op === 'attach_tracks') {
                    // Minimal, reliable handler right here to avoid falling through
                    // Parse primary IDs only (Variant B)
                    $audioIdsRaw = (string) ($this->postParam('audio_ids') ?? '');
                    preg_match_all('/\d+/', $audioIdsRaw, $m);
                    $ids = array_values(array_unique(array_map('intval', $m[0] ?? [])));
                    if (count($ids) === 0) {
                        $this->flash('err', tr('error'), 'Не указаны корректные ID треков');
                        $this->redirect('/admin/music?act=artist&id=' . $id);
                        return;
                    }
                    // Ensure IDs exist
                    $existingRows = iterator_to_array($ctx->table('audios')->where('id', $ids)->select('id'));
                    $existingIds = array_map(fn($r) => (int)$r->id, $existingRows);
                    if (count($existingIds) === 0) {
                        $this->flash('err', tr('error'), 'Треки с указанными ID не найдены');
                        $this->redirect('/admin/music?act=artist&id=' . $id);
                        return;
                    }
                    $ok = 0; $fail = [];
                    foreach ($existingIds as $aid) {
                        try {
                            $ctx->getConnection()->query("UPDATE `audios` SET `artist_id`={$id}, `type`=1 WHERE `id`={$aid}");
                            $row = $ctx->table('audios')->get($aid);
                            if ($row && (int)$row->artist_id === (int)$id) { $ok++; } else { $fail[] = $aid; }
                        } catch (\Throwable $e) { $fail[] = $aid; }
                    }
                    if ($ok > 0) $this->flash('succ', 'Привязано треков: ' . $ok);
                    if (count($fail) > 0) $this->flash('err', tr('error'), 'Не удалось привязать ID: ' . implode(',', $fail));
                    $this->redirect('/admin/music?act=artist&id=' . $id);
                    return;
                }
                if ($op === 'attach_album') {
                    $pid = (int) ($this->postParam('playlist_id') ?? 0);
                    if ($pid <= 0) {
                        $this->flash('err', tr('error'), 'Укажите ID плейлиста');
                        $this->redirect('/admin/music?act=artist&id=' . $id);
                        return;
                    }
                    $pl = $this->audios->getPlaylist($pid);
                    if (!$pl) {
                        $this->flash('err', tr('error'), 'Плейлист не найден');
                        $this->redirect('/admin/music?act=artist&id=' . $id);
                        return;
                    }
                    $ok = 0; $fail = 0;
                    try {
                        $all = iterator_to_array($pl->fetch(1, $pl->size()));
                        // Создаём альбом у артиста (копируем метаданные)
                        $newRow = $ctx->table('playlists')->insert([
                            'name' => $pl->getName(),
                            'description' => $pl->getDescription(),
                            'owner' => $this->user->id,
                            'owner_artist_id' => $id,
                            'is_album' => 1,
                            'deleted' => 0,
                            'created' => time(),
                            'edited' => time(),
                            'cover_photo_id' => $pl->getCoverPhotoId() ?: null,
                            'length' => 0,
                        ]);
                        $newPlaylist = new \openvk\Web\Models\Entities\Playlist($newRow);
                        foreach ($all as $audio) {
                            try {
                                $aid = (int) $audio->getId();
                                $ctx->getConnection()->query("UPDATE `audios` SET `artist_id`={$id}, `type`=1 WHERE `id`={$aid}");
                                $row = $ctx->table('audios')->get($aid);
                                if ($row && (int)$row->artist_id === (int)$id) {
                                    $ok++;
                                    try { $newPlaylist->add($audio); } catch (\Throwable $e2) {}
                                } else {
                                    $fail++;
                                }
                            } catch (\Throwable $e) { $fail++; }
                        }
                    } catch (\Throwable $e) {}
                    if ($ok > 0) $this->flash('succ', 'Импортировано треков: ' . $ok);
                    if ($fail > 0) $this->flash('err', tr('error'), 'Ошибок: ' . $fail);
                    $this->redirect('/admin/music?act=artist&id=' . $id);
                    return;
                }
                if ($op === 'save') {
                    $name = trim((string) $this->postParam('name'));
                    if ($name === '') {
                        $this->flash('err', tr('error'), 'Name required');
                        return;
                    }
                    $bio = $this->postParam('bio');
                    $avatar = $this->postParam('avatar_photo_id');
                    $avatarId = $avatar ? (int) $avatar : null;
                    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                        if (!str_starts_with($_FILES['avatar']['type'] ?? '', 'image')) {
                            $this->flash('err', tr('error'), tr('not_a_photo'));
                            return;
                        }
                        try {
                            $ph = Photo::fastMake($this->user->identity->getId(), 'Artist avatar', $_FILES['avatar'], null, true);
                            $avatarId = $ph->getId();
                        } catch (\Throwable $e) {
                            $this->flash('err', tr('error'), tr('invalid_cover_photo'));
                            return;
                        }
                    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $this->flash('err', tr('error'), 'Upload failed');
                        return;
                    }
                    if ($id === 0) {
                        $row = $ctx->table('artists')->insert([
                            'name' => $name,
                            'bio' => $bio ?: null,
                            'avatar_photo_id' => $avatarId,
                            'created' => time(),
                            'edited' => time(),
                        ]);
                        $id = (int) $row->id;
                        $this->redirect('/admin/music?act=artist&id=' . $id);
                    } else {
                        $ctx->table('artists')->where('id', $id)->update([
                            'name' => $name,
                            'bio' => $bio ?: null,
                            'avatar_photo_id' => $avatarId,
                            'edited' => time(),
                        ]);
                        $this->flash('succ', tr('changes_saved'));
                    }
                    $this->flash('succ', tr('changes_saved'));
                } elseif ($op === 'detach_track') {
                    $aid = (int) ($this->postParam('audio_id') ?? 0);
                    if ($aid > 0) {
                        $audio = $this->audios->get($aid);
                        if ($audio) {
                            $audio->setArtistId(null);
                            $audio->setType(0);
                            $audio->save();
                            $this->flash('succ', tr('changes_saved'));
                        }
                    }
                } elseif ($op === 'approve_request') {
                    $rid = (int) ($this->postParam('request_id') ?? 0);
                    if ($rid > 0) {
                        $req = $ctx->table('artist_link_requests')->get($rid);
                        if ($req && (int)$req->artist_id === $id) {
                            $audio = $this->audios->get((int)$req->audio_id);
                            if ($audio) {
                                $audio->setArtistId($id);
                                $audio->setType(1);
                                $audio->save();
                                $req->update(['status' => 'approved']);
                                $this->flash('succ', tr('changes_saved'));
                            }
                        }
                    }
                } elseif ($op === 'reject_request') {
                    $rid = (int) ($this->postParam('request_id') ?? 0);
                    if ($rid > 0) {
                        $req = $ctx->table('artist_link_requests')->get($rid);
                        if ($req && (int)$req->artist_id === $id) {
                            $req->update(['status' => 'rejected']);
                            $this->flash('succ', tr('changes_saved'));
                        }
                    }
                } elseif ($op === 'add_alias') {
                    $alias = trim((string) $this->postParam('alias'));
                    if ($alias !== '') {
                        try {
                            $ctx->table('artist_aliases')->insert([
                                'artist_id' => $id,
                                'alias' => $alias,
                                'created' => time(),
                            ]);
                            $this->flash('succ', tr('changes_saved'));
                        } catch (\Throwable $e) {
                            $this->flash('err', tr('error'), 'Alias exists');
                        }
                    }
                } elseif ($op === 'remove_alias') {
                    $aid = (int) ($this->postParam('alias_id') ?? 0);
                    if ($aid > 0) {
                        $row = $ctx->table('artist_aliases')->get($aid);
                        if ($row && (int)$row->artist_id === $id) {
                            $row->delete();
                            $this->flash('succ', tr('changes_saved'));
                        }
                    }
                } elseif ($op === 'add_member') {
                    $this->assertNoCSRF();
                    $userId = (int) ($this->postParam('member_user_id') ?? 0);
                    $role = in_array(($this->postParam('member_role') ?? 'editor'), ['owner','editor']) ? $this->postParam('member_role') : 'editor';
                    if ($id > 0 && $userId > 0) {
                        try {
                            // prevent duplicates
                            $exists = $ctx->table('artist_members')->where('artist_id', $id)->where('user_id', $userId)->fetch();
                            if (!$exists) {
                                $ctx->table('artist_members')->insert([
                                    'artist_id' => $id,
                                    'user_id' => $userId,
                                    'role' => $role,
                                    'created' => time(),
                                ]);
                            }
                            $this->flash('succ', tr('changes_saved'));
                        } catch (\Throwable $e) {
                            $this->flash('err', tr('error'), 'Cannot add member');
                        }
                    }
                } elseif ($op === 'remove_member') {
                    $this->assertNoCSRF();
                    $memberRowId = (int) ($this->postParam('member_id') ?? 0);
                    $userId = (int) ($this->postParam('member_user_id') ?? 0);
                    $q = $ctx->table('artist_members')->where('artist_id', $id);
                    if ($memberRowId > 0) $q = $q->where('id', $memberRowId);
                    elseif ($userId > 0) $q = $q->where('user_id', $userId);
                    try { $q->delete(); $this->flash('succ', tr('changes_saved')); } catch (\Throwable $e) {}
                } elseif ($op === 'update_member_role') {
                    $this->assertNoCSRF();
                    $memberRowId = (int) ($this->postParam('member_id') ?? 0);
                    $role = in_array(($this->postParam('member_role') ?? 'editor'), ['owner','editor']) ? $this->postParam('member_role') : 'editor';
                    if ($memberRowId > 0) {
                        try { $ctx->table('artist_members')->where('id', $memberRowId)->where('artist_id', $id)->update(['role' => $role]); $this->flash('succ', tr('changes_saved')); } catch (\Throwable $e) {}
                    }
                }
            }
            // Load tracks attached to artist, regardless of moderation status (after POST handling)
            $this->template->artistTracks = [];
            if ($id > 0) {
                try {
                    $rows = iterator_to_array($ctx->table('audios')->where('artist_id', $id)->order('id DESC')->limit(200));
                    $tracks = [];
                    foreach ($rows as $r) {
                        $ent = $this->audios->get((int)$r->id);
                        if ($ent) { $tracks[] = $ent; }
                    }
                    $this->template->artistTracks = $tracks;
                } catch (\Throwable $e) {
                    $this->template->artistTracks = [];
                }
            }

            // Fetch requests, aliases, and members for UI
            $this->template->linkRequests = iterator_to_array(
                $ctx->table('artist_link_requests')->where(['artist_id' => $id, 'status' => 'pending'])->order('created DESC')->limit(100)
            );
            $this->template->aliases = iterator_to_array(
                $ctx->table('artist_aliases')->where('artist_id', $id)->order('id DESC')->limit(100)
            );
            $this->template->members = iterator_to_array(
                $ctx->table('artist_members')->where('artist_id', $id)->order('id DESC')
            );
            return;
        }
    }

    public function renderEditMusic(int $audio_id): void
    {
        $audio = $this->audios->get($audio_id);
        $this->template->audio = $audio;

        try {
            $this->template->owner = $audio->getOwner()->getId();
        } catch (\Throwable $e) {
            $this->template->owner = 1;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $audio->setName($this->postParam("name"));
            $audio->setPerformer($this->postParam("performer"));
            $audio->setLyrics($this->postParam("text"));
            $audio->setGenre($this->postParam("genre"));
            $audio->setOwner((int) $this->postParam("owner"));
            $audio->setExplicit(!empty($this->postParam("explicit")));
            $audio->setDeleted(!empty($this->postParam("deleted")));
            $audio->setWithdrawn(!empty($this->postParam("withdrawn")));
            $audio->save();
        }
    }

    public function renderEditPlaylist(int $playlist_id): void
    {
        $playlist = $this->audios->getPlaylist($playlist_id);
        $this->template->playlist = $playlist;

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $playlist->setName($this->postParam("name"));
            $playlist->setDescription($this->postParam("description"));
            $playlist->setCover_Photo_Id((int) $this->postParam("photo"));
            $playlist->setOwner((int) $this->postParam("owner"));
            $playlist->setDeleted(!empty($this->postParam("deleted")));
            $playlist->save();
        }
    }

    public function renderArtists(): void
    {
        $this->template->_template = 'Admin/Artists.xml';
        $ctx = DatabaseConnection::i()->getContext();
        $page = (int) ($this->queryParam("p") ?? 1);
        $q = trim((string) ($this->queryParam("q") ?? ""));
        $tbl = $ctx->table('artists');
        if ($q !== '') {
            $tbl = $tbl->where('name LIKE ?', "%$q%");
        }
        $tbl = $tbl->order('id DESC');
        $this->template->artists = iterator_to_array($tbl->page($page, 20));
        $this->template->count = $ctx->table('artists')->count('*');
        $this->template->q = $q;
    }

    public function renderArtist(int $id): void
    {
        $this->template->_template = 'Admin/Artist.xml';
        $ctx = DatabaseConnection::i()->getContext();
        $artist = $id > 0 ? $ctx->table('artists')->get($id) : null;
        if ($id > 0 && !$artist) {
            $this->notFound();
        }
        $this->template->artist = $artist;
        $this->template->id = $id;
        // Avatar preview and tracks list
        $this->template->artistAvatarUrl = null;
        if ($artist && !empty($artist->avatar_photo_id)) {
            try {
                $ph = (new Photos())->get((int) $artist->avatar_photo_id);
                if ($ph) $this->template->artistAvatarUrl = $ph->getURLBySizeId("tiny");
            } catch (\Throwable $e) {}
        }
        if ($id > 0) {
            try {
                $this->template->artistTracks = iterator_to_array($this->artists->getTracks($id)->page(1, 50));
            } catch (\Throwable $e) {
                $this->template->artistTracks = [];
            }
        } else {
            $this->template->artistTracks = [];
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $act = $this->postParam('act') ?? 'save';
        if ($act === 'save') {
            $name = trim((string) $this->postParam('name'));
            if ($name === '') {
                $this->flash('err', tr('error'), 'Name required');
                return;
            }
            $bio = $this->postParam('bio');
            $avatar = $this->postParam('avatar_photo_id');
            $avatarId = $avatar ? (int) $avatar : null;
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                if (!str_starts_with($_FILES['avatar']['type'] ?? '', 'image')) {
                    $this->flash('err', tr('error'), tr('not_a_photo'));
                    return;
                }
                try {
                    $ph = Photo::fastMake($this->user->identity->getId(), 'Artist avatar', $_FILES['avatar'], null, true);
                    $avatarId = $ph->getId();
                } catch (\Throwable $e) {
                    $this->flash('err', tr('error'), tr('invalid_cover_photo'));
                    return;
                }
            } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $this->flash('err', tr('error'), 'Upload failed');
                return;
            }
            if ($id === 0) {
                $row = $ctx->table('artists')->insert([
                    'name' => $name,
                    'bio' => $bio ?: null,
                    'avatar_photo_id' => $avatarId,
                    'created' => time(),
                    'edited' => time(),
                ]);
                $id = (int) $row->id;
                $this->redirect('/admin/artist' . $id);
            } else {
                $ctx->table('artists')->where('id', $id)->update([
                    'name' => $name,
                    'bio' => $bio ?: null,
                    'avatar_photo_id' => $avatarId,
                    'edited' => time(),
                ]);
                $this->flash('succ', tr('changes_saved'));
            }
        } elseif ($act === 'attach_tracks') {
            $audioIdsRaw = (string) $this->postParam('audio_ids');
            // Variant B: берём только первичные ID из audios.id, извлекаем все числа
            preg_match_all('/\d+/', $audioIdsRaw, $m);
            $ids = array_values(array_unique(array_map('intval', $m[0] ?? [])));
            if (count($ids) === 0) {
                $this->flash('err', tr('error'), 'Не указаны корректные ID треков');
                // fall through to render with flashes
            }
            // Проверяем существующие ID
            $existingRows = iterator_to_array($ctx->table('audios')->where('id', $ids)->select('id'));
            $existingIds = array_map(fn($r) => (int)$r->id, $existingRows);
            if (count($existingIds) === 0 && count($ids) > 0) {
                $this->flash('err', tr('error'), 'Треки с указанными ID не найдены');
                // fall through to render with flashes
            }

            $ok = [];
            $miss = array_values(array_diff($ids, $existingIds));
            $fail = [];

            // Транзакция для надёжности
            $conn = $ctx->getConnection();
            try { $conn->beginTransaction(); } catch (\Throwable $e) {}

            foreach ($existingIds as $aid) {
                $updOk = false;
                try {
                    $res = $ctx->table('audios')->where('id', $aid)->update(['artist_id' => $id, 'type' => 1]);
                    // в некоторых драйверах update() может вернуть 0/false, верифицируем выборкой
                    $row = $ctx->table('audios')->get($aid);
                    if ($row && (int)$row->artist_id === (int)$id) {
                        $updOk = true;
                    }
                } catch (\Throwable $e) {}
                if (!$updOk) {
                    // сырой SQL как запасной путь
                    try {
                        $conn->query("UPDATE `audios` SET `artist_id`={$id}, `type`=1 WHERE `id`={$aid}");
                        $row = $ctx->table('audios')->get($aid);
                        $updOk = $row && (int)$row->artist_id === (int)$id;
                    } catch (\Throwable $e2) {}
                }
                if ($updOk) $ok[] = $aid; else $fail[] = $aid;
            }

            try { $conn->commit(); } catch (\Throwable $e) {}

            if (count($ok) > 0) {
                $this->flash('succ', 'Привязано треков: ' . count($ok));
            }
            if (count($miss) > 0) {
                $this->flash('warn', 'Не найдены ID: ' . implode(',', $miss));
            }
            if (count($fail) > 0) {
                $this->flash('err', tr('error'), 'Не удалось привязать ID: ' . implode(',', $fail));
            }
            // expose debug info to template
            $this->template->attachDebug = (object) [
                'inputIds' => $ids,
                'existing' => $existingIds,
                'ok' => $ok,
                'miss' => $miss,
                'fail' => $fail,
            ];
            // do not redirect to show debug output, fall through to render
        } elseif ($act === 'detach_track') {
            $aid = (int) ($this->postParam('audio_id') ?? 0);
            if ($aid > 0) {
                $affected = 0;
                try {
                    $res = $ctx->table('audios')->where('id', $aid)->update(['artist_id' => null, 'type' => 0]);
                    // в некоторых драйверах update() может вернуть 0/false, верифицируем выборкой
                    $row = $ctx->table('audios')->get($aid);
                    if ($row && (int)$row->artist_id === null) {
                        $affected = 1;
                    }
                } catch (\Throwable $e) {}
                if ($affected === 0) {
                    // сырой SQL как запасной путь
                    try {
                        $conn->query("UPDATE `audios` SET `artist_id`=NULL, `type`=0 WHERE `id`={$aid}");
                        $row = $ctx->table('audios')->get($aid);
                        $affected = $row && (int)$row->artist_id === null ? 1 : 0;
                    } catch (\Throwable $e2) {}
                }
                $this->flash($affected > 0 ? 'succ' : 'err', $affected > 0 ? tr('changes_saved') : 'Не удалось отвязать');
                $this->template->detachDebug = (object) [ 'audioId' => $aid, 'affected' => $affected ];
                // fall through to render
            }
        }
        // Load tracks attached to artist, regardless of moderation status (after POST handling)
        $this->template->artistTracks = [];
        if ($id > 0) {
            try {
                $rows = iterator_to_array($ctx->table('audios')->where('artist_id', $id)->order('id DESC')->limit(200));
                $tracks = [];
                foreach ($rows as $r) {
                    $ent = $this->audios->get((int)$r->id);
                    if ($ent) { $tracks[] = $ent; }
                }
                $this->template->artistTracks = $tracks;
            } catch (\Throwable $e) {
                $this->template->artistTracks = [];
            }
        }
    }

    public function renderLogs(): void
    {
        $filter = [];

        if ($this->queryParam("id")) {
            $id = (int) $this->queryParam("id");
            $filter["id"] = $id;
            $this->template->id = $id;
        }
        if ($this->queryParam("type") !== null && $this->queryParam("type") !== "any") {
            $type = in_array($this->queryParam("type"), [0, 1, 2, 3]) ? (int) $this->queryParam("type") : 0;
            $filter["type"] = $type;
            $this->template->type = $type;
        }
        if ($this->queryParam("uid")) {
            $user = $this->queryParam("uid");
            $filter["user"] = $user;
            $this->template->user = $user;
        }
        if ($this->queryParam("obj_id")) {
            $obj_id = (int) $this->queryParam("obj_id");
            $filter["object_id"] = $obj_id;
            $this->template->obj_id = $obj_id;
        }
        if ($this->queryParam("obj_type") !== null && $this->queryParam("obj_type") !== "any") {
            $obj_type = "openvk\\Web\\Models\\Entities\\" . $this->queryParam("obj_type");
            $filter["object_model"] = $obj_type;
            $this->template->obj_type = $obj_type;
        }

        $logs = iterator_to_array((new Logs())->search($filter));
        $this->template->logs = $logs;
        $this->template->object_types = (new Logs())->getTypes();
    }

    public function apiArtists(): void
    {
        // Autocomplete endpoint: GET /admin/api/artists?q=...
        $ctx = DatabaseConnection::i()->getContext();
        $q = trim((string) ($this->queryParam('q') ?? ''));
        $tbl = $ctx->table('artists');
        if ($q !== '') {
            $tbl = $tbl->where('name LIKE ?', "%$q%");
        }
        $rows = $tbl->order('id DESC')->limit(20);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [ 'id' => (int) $r->id, 'name' => (string) $r->name ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
