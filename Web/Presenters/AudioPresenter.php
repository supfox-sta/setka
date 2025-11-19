<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Entities\Audio;
use openvk\Web\Models\Entities\Club;
use openvk\Web\Models\Entities\Photo;
use openvk\Web\Models\Repositories\Photos;
use openvk\Web\Models\Entities\Playlist;
use openvk\Web\Models\Repositories\Audios;
use openvk\Web\Models\Repositories\Artists as ArtistsRepo;
use openvk\Web\Models\Repositories\Clubs;
use openvk\Web\Models\Repositories\Users;

final class AudioPresenter extends OpenVKPresenter
{
    private $audios;
    protected $presenterName = "audios";

    public const MAX_AUDIO_SIZE = 25000000;

    public function __construct(Audios $audios)
    {
        $this->audios = $audios;
    }

    public function renderPopular(): void
    {
        $this->renderList(null, "popular");
    }

    public function renderNew(): void
    {
        $this->renderList(null, "new");
    }

    public function renderHome(): void
    {
        $this->renderList(null, "home");
    }

    public function renderLiked(): void
    {
        $this->renderList(null, "liked");
    }

    public function renderList(?int $owner = null, ?string $mode = "list"): void
    {
        $this->assertUserLoggedIn();
        $this->template->_template = "Audio/List.xml";
        $page = (int) ($this->queryParam("p") ?? 1);
        $audios = [];

        if ($mode === "list") {
            $entity = null;
            if ($owner < 0) {
                $entity = (new Clubs())->get($owner * -1);
                if (!$entity || $entity->isBanned()) {
                    $this->redirect("/audios" . $this->user->id);
                }

                $audios = $this->audios->getByClub($entity, $page, 10);
                $audiosCount = $this->audios->getClubCollectionSize($entity);
            } else {
                $entity = (new Users())->get($owner);
                if (!$entity || $entity->isDeleted() || $entity->isBanned()) {
                    $this->redirect("/audios" . $this->user->id);
                }

                if (!$entity->getPrivacyPermission("audios.read", $this->user->identity)) {
                    $this->flashFail("err", tr("forbidden"), tr("forbidden_comment"));
                }

                $audios = $this->audios->getByUser($entity, $page, 10);
                $audiosCount = $this->audios->getUserCollectionSize($entity);
            }

            if (!$entity) {
                $this->notFound();
            }

            $this->template->owner = $entity;
            $this->template->ownerId = $owner;
            $this->template->club = $owner < 0 ? $entity : null;
            $this->template->isMy = ($owner > 0 && ($entity->getId() === $this->user->id));
            $this->template->isMyClub = ($owner < 0 && $entity->canBeModifiedBy($this->user->identity));
        } elseif ($mode === "new") {
            $audios = $this->audios->getNew();
            $audiosCount = $audios->size();
        } elseif ($mode === "uploaded") {
            $stream = $this->audios->getByUploader($this->user->identity);
            $audios = $stream->page($page, 10);
            $audiosCount = $stream->size();
        } elseif ($mode === "playlists") {
            if ($owner < 0) {
                $entity = (new Clubs())->get(abs($owner));
                if (!$entity || $entity->isBanned()) {
                    $this->redirect("/playlists" . $this->user->id);
                }

                $playlists = $this->audios->getPlaylistsByClub($entity, $page, OPENVK_DEFAULT_PER_PAGE);
                $playlistsCount = $this->audios->getClubPlaylistsCount($entity);
            } else {
                $entity = (new Users())->get($owner);
                if (!$entity || $entity->isDeleted() || $entity->isBanned()) {
                    $this->redirect("/playlists" . $this->user->id);
                }

                if (!$entity->getPrivacyPermission("audios.read", $this->user->identity)) {
                    $this->flashFail("err", tr("forbidden"), tr("forbidden_comment"));
                }

                $playlists = $this->audios->getPlaylistsByUser($entity, $page, OPENVK_DEFAULT_PER_PAGE);
                $playlistsCount = $this->audios->getUserPlaylistsCount($entity);
            }

            $this->template->playlists = iterator_to_array($playlists);
            $this->template->playlistsCount = $playlistsCount;
            $this->template->owner = $entity;
            $this->template->ownerId = $owner;
            $this->template->club = $owner < 0 ? $entity : null;
            $this->template->isMy = ($owner > 0 && ($entity->getId() === $this->user->id));
            $this->template->isMyClub = ($owner < 0 && $entity->canBeModifiedBy($this->user->identity));
        } elseif ($mode === "home") {
            // Build Home tab blocks
            $ctx = DatabaseConnection::i()->getContext();
            // Top genres: two-step (avoid joins if unsupported)
            $rels = $ctx->table("audio_relations")->select("audio")
                ->where("entity", $this->user->id)
                ->limit(500);
            $ids = [];
            foreach ($rels as $rel) { $ids[] = (int) $rel->audio; }
            $genreCounts = [];
            if (!empty($ids)) {
                $audq = $ctx->table("audios")
                    ->select("genre")
                    ->where("id", $ids)
                    ->where(["deleted" => 0, "withdrawn" => 0]);
                foreach ($audq as $row) {
                    $g = (string) ($row->genre ?? "Other");
                    if (!isset($genreCounts[$g])) $genreCounts[$g] = 0;
                    $genreCounts[$g]++;
                }
            }
            arsort($genreCounts);
            $topGenres = [];
            foreach ($genreCounts as $g => $c) {
                if ($g === "Other") continue;
                $topGenres[] = $g;
                if (count($topGenres) >= 5) break;
            }
            // Fallback if not enough genres
            if (count($topGenres) < 5) {
                $fallback = ["Pop", "Rock", "Hip Hop", "Electronic", "Jazz", "Metal", "R&B", "Indie"];
                foreach ($fallback as $fg) { if (!in_array($fg, $topGenres, true)) $topGenres[] = $fg; if (count($topGenres) >= 5) break; }
            }

            // Build playlist-like cards for UI (links lead to existing pages)
            $cards = [];
            $slugify = function(string $s){
                $s = strtolower($s);
                $s = preg_replace('/[^a-z0-9]+/','_', $s);
                return trim($s, '_');
            };
            foreach ($topGenres as $g) {
                $slug = $slugify($g);
                $cards[] = [
                    'title' => $g,
                    'href'  => "/search?section=audios&genre=" . rawurlencode($g),
                    'cover' => "/themepack/mobile_ovk/0.0.1.0/resource/covers/genres/{$slug}.jpg",
                ];
            }
            // Favorites
            $cards[] = [
                'title' => 'Любимое',
                'href'  => '/audios/liked',
                'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/favorites.jpg',
            ];
            // New
            $cards[] = [
                'title' => 'Новое',
                'href'  => '/search?section=audios',
                'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/new.jpg',
            ];
            // Top-50
            $cards[] = [
                'title' => 'Топ‑50',
                'href'  => '/search?section=audios&order=listens',
                'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/top50.jpg',
            ];

            $this->template->home_cards = $cards;

            // New home layout data
            // 1) Banner (placeholder, no link)
            $this->template->home_banner_src = '/assets/packages/static/openvk/img/banner_song.jpg';
            
            // 2) Albums collections (Любимое / Новое / Топ‑50)
            $this->template->home_albums = [
                [ 'title' => 'Любимое', 'href' => '/audios/liked', 'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/favorites.jpg' ],
                [ 'title' => 'Новое',   'href' => '/search?section=audios', 'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/new.jpg' ],
                [ 'title' => 'Топ‑50',  'href' => '/search?section=audios&order=listens', 'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/collections/top50.jpg' ],
            ];

            // 3) Genres top 8
            $genres8 = array_slice($topGenres, 0, 8);
            $genresOut = [];
            $slugify = function(string $s){ $s = strtolower($s); $s = preg_replace('/[^a-z0-9]+/','_', $s); return trim($s, '_'); };
            foreach ($genres8 as $g) {
                $genresOut[] = [
                    'title' => $g,
                    'href'  => '/search?section=audios&genre=' . rawurlencode($g),
                    'cover' => '/themepack/mobile_ovk/0.0.1.0/resource/covers/genres/' . $slugify($g) . '.jpg',
                ];
            }
            $this->template->home_genres = $genresOut;

            // 4) Vertical artists (photo, name, counts)
            try {
                $artistsRows = $ctx->table('artists')->order('id DESC')->limit(12);
                $homeArtists = [];
                $artistsRepo = new ArtistsRepo();
                foreach ($artistsRows as $r) {
                    $avatarUrl = null;
                    if (!empty($r->avatar_photo_id)) {
                        try {
                            $ph = (new Photos())->get((int)$r->avatar_photo_id);
                            if ($ph) {
                                $avatarUrl = $ph->getURLBySizeId('normal') ?? $ph->getURL();
                            }
                        } catch (\Throwable $e) {}
                    }
                    // Counts
                    $tracksCount = 0; $albumsCount = 0;
                    try { $tracksCount = $artistsRepo->getTracks((int)$r->id)->size(); } catch (\Throwable $e) {}
                    try { $albumsCount = $artistsRepo->getAlbums((int)$r->id)->size(); } catch (\Throwable $e) {}
                    $homeArtists[] = [
                        'id' => (int)$r->id,
                        'name' => (string)$r->name,
                        'avatar' => $avatarUrl,
                        'tracks' => (int)$tracksCount,
                        'albums' => (int)$albumsCount,
                    ];
                }
                $this->template->home_artists = $homeArtists;
            } catch (\Throwable $e) {}

            // 5) Favourite albums (max 8)
            try {
                $favPlaylists = iterator_to_array($this->audios->getPlaylistsByUser($this->user->identity, 1, 8));
                $this->template->home_fav_albums = $favPlaylists;
            } catch (\Throwable $e) { $this->template->home_fav_albums = []; }

            // 6) Actions
            try {
                $isArtist = (bool) $ctx->table('artist_members')->where('user_id', $this->user->id)->limit(1)->fetch();
            } catch (\Throwable $e) { $isArtist = false; }
            $actions = [];
            if ($isArtist) {
                $actions[] = [ 'title' => 'Открыть кабинет', 'href' => '/artists/cabinet', 'cover' => '/assets/packages/static/openvk/img/actions/cabinet.jpg' ];
            } else {
                $actions[] = [ 'title' => 'Стать исполнителем', 'href' => '/artists/become', 'cover' => '/assets/packages/static/openvk/img/actions/become.jpg' ];
            }
            $actions[] = [ 'title' => 'Загрузить трек', 'href' => '/player/upload', 'cover' => '/assets/packages/static/openvk/img/actions/upload.jpg' ];
            $actions[] = [ 'title' => 'Создать альбом', 'href' => '/audios/newPlaylist', 'cover' => '/assets/packages/static/openvk/img/actions/new_album.jpg' ];
            $actions[] = [ 'title' => 'Старый интерфейс', 'href' => '/audios' . $this->user->id, 'cover' => '/assets/packages/static/openvk/img/actions/legacy.jpg' ];
            $this->template->home_actions = $actions;
        } elseif ($mode === "liked") {
            $this->template->liked_playlists = iterator_to_array($this->audios->getPlaylistsByUser($this->user->identity, 1, 50));
            $this->template->liked_audios    = iterator_to_array($this->audios->getByUser($this->user->identity, 1, 50));
        } elseif ($mode === 'alone_audio') {
            $audios = [$this->template->alone_audio];
            $audiosCount = 1;

            $this->template->owner   = $this->user->identity;
            $this->template->ownerId = $this->user->id;
        }

        // $this->renderApp("owner=$owner");
        if ($audios !== []) {
            $this->template->audios = iterator_to_array($audios);
            $this->template->audiosCount = $audiosCount;
        }

        $this->template->mode = $mode;
        $this->template->page = $page;

        if (in_array($mode, ["list", "new", "popular"]) && $this->user->identity && $page < 2) {
            $this->template->friendsAudios = $this->user->identity->getBroadcastList("all", true);
        }
    }

    public function renderUploaded()
    {
        $this->assertUserLoggedIn();
        $page = (int) ($this->queryParam('p') ?? 1);
        $perPage = OPENVK_DEFAULT_PER_PAGE;
        $status = (string) ($this->queryParam('status') ?? 'all');
        $stream = $this->audios->getByUploader($this->user->identity);
        // materialize to filter by status reliably
        $all = iterator_to_array($stream);
        if (in_array($status, ['pending','approved','rejected'])) {
            $all = array_values(array_filter($all, function($a) use ($status){
                try { return $a->getStatus() === $status; } catch (\Throwable $e) { return false; }
            }));
        }
        $total = sizeof($all);
        $offset = max(0, ($page - 1) * $perPage);
        $audios = array_slice($all, $offset, $perPage);
        $this->template->audios = $audios;
        $this->template->audiosCount = $total;
        $this->template->page = $page;
        $this->template->perPage = $perPage;
        $this->template->filterStatus = $status;
        $this->template->mode = 'uploaded';
        $this->template->owner = $this->user->identity;
        $this->template->ownerId = $this->user->id;
    }

    public function renderEmbed(int $owner, int $id): void
    {
        $audio = $this->audios->getByOwnerAndVID($owner, $id);
        if (!$audio) {
            header("HTTP/1.1 404 Not Found");
            exit("<b>" . tr("audio_embed_not_found") . ".</b>");
        } elseif ($audio->isDeleted()) {
            header("HTTP/1.1 410 Not Found");
            exit("<b>" . tr("audio_embed_deleted") . ".</b>");
        } elseif ($audio->isWithdrawn()) {
            header("HTTP/1.1 451 Unavailable for legal reasons");
            exit("<b>" . tr("audio_embed_withdrawn") . ".</b>");
        } elseif (!$audio->canBeViewedBy(null)) {
            header("HTTP/1.1 403 Forbidden");
            exit("<b>" . tr("audio_embed_forbidden") . ".</b>");
        } elseif (!$audio->isAvailable()) {
            header("HTTP/1.1 425 Too Early");
            exit("<b>" . tr("audio_embed_processing") . ".</b>");
        }

        $this->template->audio = $audio;
    }

    public function renderUpload(): void
    {
        $this->assertUserLoggedIn();

        $group = null;
        $playlist = null;
        $isAjax = $this->postParam("ajax", false) == 1;

        if (!is_null($this->queryParam("gid")) && !is_null($this->queryParam("playlist"))) {
            $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
        }

        if (!is_null($this->queryParam("gid"))) {
            $gid   = (int) $this->queryParam("gid");
            $group = (new Clubs())->get($gid);
            if (!$group) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }

            if (!$group->canUploadAudio($this->user->identity)) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }
        }

        if (!is_null($this->queryParam("playlist"))) {
            $playlist_id = (int) $this->queryParam("playlist");
            $playlist = (new Audios())->getPlaylist($playlist_id);
            if (!$playlist || $playlist->isDeleted()) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }

            if (!$playlist->canBeModifiedBy($this->user->identity)) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }

            $this->template->playlist = $playlist;
            $this->template->owner = $playlist->getOwner();
        }

        $this->template->group = $group;

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $upload = $_FILES["blob"];
        if (isset($upload) && file_exists($upload["tmp_name"])) {
            if ($upload["size"] > self::MAX_AUDIO_SIZE) {
                $this->flashFail("err", tr("error"), tr("media_file_corrupted_or_too_large"), null, $isAjax);
            }
        } else {
            $err = !isset($upload) ? 65536 : $upload["error"];
            $err = str_pad(dechex($err), 9, "0", STR_PAD_LEFT);
            $readableError = tr("error_generic");

            switch ($upload["error"]) {
                default:
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $readableError = tr("file_too_big");
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $readableError = tr("file_loaded_partially");
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $readableError = tr("file_not_uploaded");
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $readableError = "Missing a temporary folder.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                case UPLOAD_ERR_EXTENSION:
                    $readableError = "Failed to write file to disk. ";
                    break;
            }

            $this->flashFail("err", tr("error"), $readableError . " " . tr("error_code", $err), null, $isAjax);
        }

        $performer = $this->postParam("performer");
        $name      = $this->postParam("name");
        $lyrics    = $this->postParam("lyrics");
        $genre     = empty($this->postParam("genre")) ? "Other" : $this->postParam("genre");
        $nsfw      = ($this->postParam("explicit") ?? "off") === "on";
        $is_unlisted = ($this->postParam("unlisted") ?? "off") === "on";

        if (empty($performer) || empty($name) || iconv_strlen($performer . $name) > 128) { # FQN of audio must not be more than 128 chars
            $this->flashFail("err", tr("error"), tr("error_insufficient_info"), null, $isAjax);
        }

        $audio = new Audio();
        $audio->setOwner($this->user->id);
        $audio->setName($name);
        $audio->setPerformer($performer);
        $audio->setLyrics(empty($lyrics) ? null : $lyrics);
        $audio->setGenre($genre);
        $audio->setExplicit($nsfw);
        $audio->setUnlisted($is_unlisted);

        try {
            $audio->setFile($upload);
        } catch (\DomainException $ex) {
            $e = $ex->getMessage();
            $this->flashFail("err", tr("error"), tr("media_file_corrupted_or_too_large") . " $e.", null, $isAjax);
        } catch (\RuntimeException $ex) {
            $this->flashFail("err", tr("error"), tr("ffmpeg_timeout"), null, $isAjax);
        } catch (\BadMethodCallException $ex) {
            $this->flashFail("err", tr("error"), "хз", null, $isAjax);
        } catch (\Exception $ex) {
            $this->flashFail("err", tr("error"), tr("ffmpeg_not_installed"), null, $isAjax);
        }

        // Optional cover upload
        if (isset($_FILES["cover"]) && $_FILES["cover"]["error"] === UPLOAD_ERR_OK) {
            if (!str_starts_with($_FILES["cover"]["type"] ?? "", "image")) {
                $this->flashFail("err", tr("error"), tr("not_a_photo"), null, $isAjax);
            }
            try {
                $photo = Photo::fastMake($this->user->id, "Audio cover", $_FILES["cover"], null, true);
                $audio->setCoverPhoto($photo);
            } catch (\Throwable $e) {
                $this->flashFail("err", tr("error"), tr("invalid_cover_photo"), null, $isAjax);
            }
        }

        $audio->save();

        if ($playlist) {
            $playlist->add($audio);
        } else {
            $audio->add($group ?? $this->user->identity);
        }

        if (!$isAjax) {
            $this->redirect(is_null($group) ? "/audios" . $this->user->id : "/audios-" . $group->getId());
        } else {
            $redirectLink = "/audios";

            if (!is_null($group)) {
                $redirectLink .= $group->getRealId();
            } else {
                $redirectLink .= $this->user->id;
            }

            if ($playlist) {
                $redirectLink = "/playlist" . $playlist->getPrettyId();
            }

            $this->returnJson([
                "success" => true,
                "redirect_link" => $redirectLink,
            ]);
        }
    }

    public function renderAloneAudio(int $owner_id, int $audio_id): void
    {
        $this->assertUserLoggedIn();

        $found_audio = $this->audios->get($audio_id);
        if (!$found_audio || $found_audio->isDeleted() || !$found_audio->canBeViewedBy($this->user->identity)) {
            $this->notFound();
        }

        $this->template->alone_audio = $found_audio;
        $this->renderList(null, 'alone_audio');
    }

    public function renderListen(int $id): void
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->assertNoCSRF();

            if (is_null($this->user)) {
                $this->returnJson(["success" => false]);
            }

            $audio = $this->audios->get($id);

            if ($audio && !$audio->isDeleted() && !$audio->isWithdrawn()) {
                if (!empty($this->postParam("playlist"))) {
                    $playlist = (new Audios())->getPlaylist((int) $this->postParam("playlist"));

                    if (!$playlist || $playlist->isDeleted() || !$playlist->canBeViewedBy($this->user->identity) || !$playlist->hasAudio($audio)) {
                        $playlist = null;
                    }
                }

                $listen = $audio->listen($this->user->identity, $playlist);

                $returnArr = ["success" => $listen];

                if ($playlist) {
                    $returnArr["new_playlists_listens"] = $playlist->getListens();
                }

                $this->returnJson($returnArr);
            }

            $this->returnJson(["success" => false]);
        } else {
            $this->redirect("/");
        }
    }

    public function renderSearch(): void
    {
        $this->redirect("/search?section=audios");
    }

    public function renderNewPlaylist(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);

        $owner = $this->user->id;

        if ($this->requestParam("gid")) {
            $club = (new Clubs())->get((int) abs((int) $this->requestParam("gid")));
            if (!$club || $club->isBanned() || !$club->canBeModifiedBy($this->user->identity)) {
                $this->redirect("/audios" . $this->user->id);
            }

            $owner = ($club->getId() * -1);

            $this->template->club = $club;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $title = $this->postParam("title");
            $description = $this->postParam("description");
            $is_unlisted = (int) $this->postParam('is_unlisted');
            $is_ajax = (int) $this->postParam('ajax') == 1;
            $audios = array_slice(explode(",", $this->postParam("audios")), 0, 1000);

            if (empty($title) || iconv_strlen($title) < 1) {
                $this->flashFail("err", tr("error"), tr("set_playlist_name"), null, $is_ajax);
            }

            $playlist = new Playlist();
            $playlist->setOwner($owner);
            $playlist->setName(substr($title, 0, 125));
            $playlist->setDescription(substr($description, 0, 2045));
            if ($is_unlisted == 1) {
                $playlist->setUnlisted(true);
            }

            if ($_FILES["cover"]["error"] === UPLOAD_ERR_OK) {
                if (!str_starts_with($_FILES["cover"]["type"], "image")) {
                    $this->flashFail("err", tr("error"), tr("not_a_photo"), null, $is_ajax);
                }

                try {
                    $playlist->fastMakeCover($this->user->id, $_FILES["cover"]);
                } catch (\Throwable $e) {
                    $this->flashFail("err", tr("error"), tr("invalid_cover_photo"), null, $is_ajax);
                }
            }

            $playlist->save();

            foreach ($audios as $audio) {
                $audio = $this->audios->get((int) $audio);
                if (!$audio || $audio->isDeleted()) {
                    continue;
                }

                $playlist->add($audio);
            }

            $playlist->bookmark($club ?? $this->user->identity);
            if ($is_ajax) {
                $this->returnJson([
                    'success' => true,
                    'redirect' => '/playlist' . $owner . "_" . $playlist->getId(),
                ]);
            }
            $this->redirect("/playlist" . $owner . "_" . $playlist->getId());
        } else {
            $this->template->owner = $owner;
        }
    }

    public function renderPlaylistAction(int $id)
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);
        $this->assertNoCSRF();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("HTTP/1.1 405 Method Not Allowed");
            $this->redirect("/");
        }

        $playlist = $this->audios->getPlaylist($id);

        if (!$playlist || $playlist->isDeleted()) {
            $this->flashFail("err", "error", tr("invalid_playlist"), null, true);
        }

        switch ($this->queryParam("act")) {
            case "bookmark":
                if (!$playlist->isBookmarkedBy($this->user->identity)) {
                    $playlist->bookmark($this->user->identity);
                } else {
                    $this->flashFail("err", "error", tr("playlist_already_bookmarked"), null, true);
                }

                break;
            case "unbookmark":
                if ($playlist->isBookmarkedBy($this->user->identity)) {
                    $playlist->unbookmark($this->user->identity);
                } else {
                    $this->flashFail("err", "error", tr("playlist_not_bookmarked"), null, true);
                }

                break;
            case "delete":
                if ($playlist->canBeModifiedBy($this->user->identity)) {
                    $tmOwner = $playlist->getOwner();
                    $playlist->delete();
                } else {
                    $this->flashFail("err", "error", tr("access_denied"), null, true);
                }

                $this->returnJson(["success" => true, "id" => $tmOwner->getRealId()]);
                break;
            default:
                break;
        }

        $this->returnJson(["success" => true]);
    }

    public function renderEditPlaylist(int $owner_id, int $virtual_id)
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $playlist = $this->audios->getPlaylistByOwnerAndVID($owner_id, $virtual_id);
        if (!$playlist || $playlist->isDeleted() || !$playlist->canBeModifiedBy($this->user->identity)) {
            $this->notFound();
        }

        $this->template->playlist = $playlist;

        $audios = iterator_to_array($playlist->fetch(1, $playlist->size()));
        $this->template->audios = array_slice($audios, 0, 1000);
        $this->template->ownerId = $owner_id;
        $this->template->owner = $playlist->getOwner();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $is_ajax = (int) $this->postParam('ajax') == 1;
        $title = $this->postParam("title");
        $description = $this->postParam("description");
        $is_unlisted = (int) $this->postParam('is_unlisted');
        $new_audios = !empty($this->postParam("audios")) ? explode(",", rtrim($this->postParam("audios"), ",")) : null;

        if (empty($title) || iconv_strlen($title) < 1) {
            $this->flashFail("err", tr("error"), tr("set_playlist_name"));
        }

        $playlist->setName(ovk_proc_strtr($title, 125));
        $playlist->setDescription(ovk_proc_strtr($description, 2045));
        $playlist->setEdited(time());
        $playlist->resetLength();
        $playlist->setUnlisted((bool) $is_unlisted);

        if ($_FILES["cover"]["error"] === UPLOAD_ERR_OK) {
            if (!str_starts_with($_FILES["cover"]["type"], "image")) {
                $this->flashFail("err", tr("error"), tr("not_a_photo"));
            }

            try {
                $playlist->fastMakeCover($this->user->id, $_FILES["cover"]);
            } catch (\Throwable $e) {
                $this->flashFail("err", tr("error"), tr("invalid_cover_photo"));
            }
        }

        $playlist->save();

        DatabaseConnection::i()->getContext()->table("playlist_relations")->where([
            "collection" => $playlist->getId(),
        ])->delete();

        if (!is_null($new_audios)) {
            foreach ($new_audios as $new_audio) {
                $audio = (new Audios())->get((int) $new_audio);
                if (!$audio || $audio->isDeleted()) {
                    continue;
                }

                $playlist->add($audio);
            }
        }

        if ($is_ajax) {
            $this->returnJson([
                'success' => true,
                'redirect' => '/playlist' . $playlist->getPrettyId(),
            ]);
        }
        $this->redirect("/playlist" . $playlist->getPrettyId());
    }

    public function renderPlaylist(int $owner_id, int $virtual_id): void
    {
        $this->assertUserLoggedIn();
        $playlist = $this->audios->getPlaylistByOwnerAndVID($owner_id, $virtual_id);
        $page = (int) ($this->queryParam("p") ?? 1);
        if (!$playlist || $playlist->isDeleted()) {
            $this->notFound();
        }

        $this->template->playlist = $playlist;
        $this->template->page = $page;
        $this->template->cover = $playlist->getCoverPhoto();
        $this->template->cover_url = $this->template->cover ? $this->template->cover->getURL() : "/assets/packages/static/openvk/img/song.jpg";
        $this->template->audios = iterator_to_array($playlist->fetch($page, 10));
        $this->template->ownerId = $owner_id;
        $this->template->owner = $playlist->getOwner();
        $this->template->isBookmarked = $this->user->identity && $playlist->isBookmarkedBy($this->user->identity);
        $this->template->isMy = $this->user->identity &&  $playlist->getOwner()->getId() === $this->user->id;
        $this->template->canEdit = $this->user->identity && $playlist->canBeModifiedBy($this->user->identity);
        $this->template->count = $playlist->size();
    }

    public function renderAction(int $audio_id): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);
        $this->assertNoCSRF();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("HTTP/1.1 405 Method Not Allowed");
            $this->redirect("/");
        }

        $audio = $this->audios->get($audio_id);

        if (!$audio || $audio->isDeleted()) {
            $this->flashFail("err", "error", tr("invalid_audio"), null, true);
        }

        switch ($this->queryParam("act")) {
            case "add":
                if ($audio->isWithdrawn()) {
                    $this->flashFail("err", "error", tr("invalid_audio"), null, true);
                }

                if (!$audio->isInLibraryOf($this->user->identity)) {
                    $audio->add($this->user->identity);
                } else {
                    $this->flashFail("err", "error", tr("do_have_audio"), null, true);
                }

                break;

            case "remove":
                if ($audio->isInLibraryOf($this->user->identity)) {
                    $audio->remove($this->user->identity);
                } else {
                    $this->flashFail("err", "error", tr("do_not_have_audio"), null, true);
                }

                break;
            case "remove_club":
                $club = (new Clubs())->get((int) $this->postParam("club"));

                if (!$club || !$club->canBeModifiedBy($this->user->identity)) {
                    $this->flashFail("err", "error", tr("access_denied"), null, true);
                }

                if ($audio->isInLibraryOf($club)) {
                    $audio->remove($club);
                } else {
                    $this->flashFail("err", "error", tr("group_hasnt_audio"), null, true);
                }

                break;
            case "add_to_club":
                $detailed = [];
                if ($audio->isWithdrawn()) {
                    $this->flashFail("err", "error", tr("invalid_audio"), null, true);
                }

                if (empty($this->postParam("clubs"))) {
                    $this->flashFail("err", "error", 'clubs not passed', null, true);
                }

                $clubs_arr = explode(',', $this->postParam("clubs"));
                $count     = sizeof($clubs_arr);
                if ($count < 1 || $count > 10) {
                    $this->flashFail("err", "error", tr('too_many_or_to_lack'), null, true);
                }

                foreach ($clubs_arr as $club_id) {
                    $club = (new Clubs())->get((int) $club_id);
                    if (!$club || !$club->canBeModifiedBy($this->user->identity)) {
                        continue;
                    }

                    if (!$audio->isInLibraryOf($club)) {
                        $detailed[$club_id] = true;
                        $audio->add($club);
                    } else {
                        $detailed[$club_id] = false;
                        continue;
                    }
                }

                $this->returnJson(["success" => true, 'detailed' => $detailed]);
                break;
            case "add_to_playlist":
                $detailed = [];
                if ($audio->isWithdrawn()) {
                    $this->flashFail("err", "error", tr("invalid_audio"), null, true);
                }

                if (empty($this->postParam("playlists"))) {
                    $this->flashFail("err", "error", 'playlists not passed', null, true);
                }

                $playlists_arr = explode(',', $this->postParam("playlists"));
                $count = sizeof($playlists_arr);
                if ($count < 1 || $count > 10) {
                    $this->flashFail("err", "error", tr('too_many_or_to_lack'), null, true);
                }

                foreach ($playlists_arr as $playlist_id) {
                    $pid = explode('_', $playlist_id);
                    $playlist = (new Audios())->getPlaylistByOwnerAndVID((int) $pid[0], (int) $pid[1]);
                    if (!$playlist || !$playlist->canBeModifiedBy($this->user->identity)) {
                        continue;
                    }

                    if (!$playlist->hasAudio($audio)) {
                        $playlist->add($audio);
                        $detailed[$playlist_id] = true;
                    } else {
                        $detailed[$playlist_id] = false;
                        continue;
                    }
                }

                $this->returnJson(["success" => true, 'detailed' => $detailed]);
                break;
            case "delete":
                if ($audio->canBeModifiedBy($this->user->identity)) {
                    $audio->delete();
                } else {
                    $this->flashFail("err", "error", tr("access_denied"), null, true);
                }

                break;
            case "edit":
                $audio = $this->audios->get($audio_id);
                if (!$audio || $audio->isDeleted() || $audio->isWithdrawn()) {
                    $this->flashFail("err", "error", tr("invalid_audio"), null, true);
                }

                if ($audio->getOwner()->getId() !== $this->user->id) {
                    $this->flashFail("err", "error", tr("access_denied"), null, true);
                }

                $performer = $this->postParam("performer");
                $name      = $this->postParam("name");
                $lyrics    = $this->postParam("lyrics");
                $genre     = empty($this->postParam("genre")) ? "undefined" : $this->postParam("genre");
                $nsfw      = (int) ($this->postParam("explicit") ?? 0) === 1;
                $unlisted  = (int) ($this->postParam("unlisted") ?? 0) === 1;
                if (empty($performer) || empty($name) || iconv_strlen($performer . $name) > 128) { # FQN of audio must not be more than 128 chars
                    $this->flashFail("err", tr("error"), tr("error_insufficient_info"), null, true);
                }

                $audio->setName($name);
                $audio->setPerformer($performer);
                $audio->setLyrics(empty($lyrics) ? null : $lyrics);
                $audio->setGenre($genre);
                $audio->setExplicit($nsfw);
                $audio->setSearchability($unlisted);
                // Optional cover change
                if (isset($_FILES["cover"]) && $_FILES["cover"]["error"] === UPLOAD_ERR_OK) {
                    if (!str_starts_with($_FILES["cover"]["type"] ?? "", "image")) {
                        $this->flashFail("err", tr("error"), tr("not_a_photo"), null, true);
                    }
                    try {
                        $photo = Photo::fastMake($this->user->id, "Audio cover", $_FILES["cover"], null, true);
                        $audio->setCoverPhoto($photo);
                    } catch (\Throwable $e) {
                        $this->flashFail("err", tr("error"), tr("invalid_cover_photo"), null, true);
                    }
                }

                $audio->setEdited(time());
                $audio->save();

                // Put track into moderation queue
                try {
                    $audio->setStatus('pending');
                    $audio->save();
                } catch (\Throwable $e) {}

                // Optional: create artist link request automatically from upload metadata (robust resolution)
                $maybeArtistId = (int) ($this->postParam("artist_id") ?? 0);
                $maybeArtistName = trim((string) ($this->postParam("artist_name") ?? ""));
                {
                    $ctx = DatabaseConnection::i()->getContext();
                    $artistId = $maybeArtistId;
                    // Build candidate names: explicit provided name, then tokens from performer
                    $candidateNames = [];
                    if ($maybeArtistName !== "") $candidateNames[] = $maybeArtistName;
                    $perf = trim((string) $audio->getPerformer());
                    if ($perf !== "") {
                        // strip parentheses content
                        $np = preg_replace('/\s*\([^\)]*\)\s*/u', ' ', $perf);
                        // split by common delimiters: feat/ft/featuring, &, comma, x, -, —
                        $tokens = preg_split('/\s*(?:feat\.|featuring|ft\.|&|,|×|x|\-|—|\+|;|\/)\s*/iu', (string) $np, -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($tokens as $t) { $t = trim($t); if ($t !== '') $candidateNames[] = $t; }
                        // also try full performer as last resort
                        $candidateNames[] = $perf;
                    }
                    // Try to resolve artist by exact lower(name), then aliases, then LIKE
                    if ($artistId <= 0) {
                        foreach ($candidateNames as $cand) {
                            if ($cand === '') continue;
                            // exact lower on artists
                            $row = $ctx->table('artists')->where('LOWER(name) = LOWER(?)', $cand)->limit(1)->fetch();
                            if ($row) { $artistId = (int) $row->id; break; }
                            // exact lower on aliases
                            $al = $ctx->table('artist_aliases')->where('LOWER(alias) = LOWER(?)', $cand)->limit(1)->fetch();
                            if ($al) { $artistId = (int) $al->artist_id; break; }
                        }
                    }
                    if ($artistId <= 0) {
                        foreach ($candidateNames as $cand) {
                            if ($cand === '' || mb_strlen($cand) < 3) continue;
                            $row = $ctx->table('artists')->where('name LIKE ?', "%$cand%")->limit(1)->fetch();
                            if ($row) { $artistId = (int) $row->id; break; }
                            $al = $ctx->table('artist_aliases')->where('alias LIKE ?', "%$cand%")->limit(1)->fetch();
                            if ($al) { $artistId = (int) $al->artist_id; break; }
                        }
                    }
                    if ($artistId > 0) {
                        $exists = $ctx->table('artist_link_requests')->where([
                            'audio_id' => $audio->getId(),
                            'artist_id' => $artistId,
                            'status' => 'pending',
                        ])->fetch();
                        if (!$exists) {
                            $ctx->table('artist_link_requests')->insert([
                                'audio_id' => $audio->getId(),
                                'artist_id' => $artistId,
                                'user_id' => $this->user->id,
                                'status' => 'pending',
                                'created' => time(),
                            ]);
                        }
                    }
                }

                $this->returnJson(["success" => true, "new_info" => [
                    "name" => ovk_proc_strtr($audio->getTitle(), 40),
                    "performer" => ovk_proc_strtr($audio->getPerformer(), 40),
                    "lyrics" => nl2br($audio->getLyrics() ?? ""),
                    "lyrics_unformatted" => $audio->getLyrics() ?? "",
                    "explicit" => $audio->isExplicit(),
                    "genre" => $audio->getGenre(),
                    "unlisted" => $audio->isUnlisted(),
                    "cover_url" => $audio->getCoverURL() ?? "/themepack/mobile_ovk/0.0.1.0/resource/icons/note.svg",
                ]]);
                break;

            case "request_artist_link":
                // Temporarily disabled for regular users
                $this->returnJson(["success" => false, "error" => "disabled" ]);
                break;

            default:
                break;
        }

        $this->returnJson(["success" => true]);
    }

    public function renderPlaylists(int $owner)
    {
        $this->renderList($owner, "playlists");
    }

    public function renderApiGetContext()
    {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("HTTP/1.1 405 Method Not Allowed");
            $this->redirect("/");
        }

        $ctx_type = $this->postParam("context");
        $ctx_id = (int) ($this->postParam("context_entity"));
        $page = (int) ($this->postParam("page") ?? 1);
        $perPage = 10;

        switch ($ctx_type) {
            default:
            case "entity_audios":
                if ($ctx_id >= 0) {
                    $entity = $ctx_id != 0 ? (new Users())->get($ctx_id) : $this->user->identity;

                    if (!$entity || !$entity->getPrivacyPermission("audios.read", $this->user->identity)) {
                        $this->flashFail("err", "Error", "Can't get queue", 80, true);
                    }

                    $audios = $this->audios->getByUser($entity, $page, $perPage);
                    $audiosCount = $this->audios->getUserCollectionSize($entity);
                } else {
                    $entity = (new Clubs())->get(abs($ctx_id));

                    if (!$entity || $entity->isBanned()) {
                        $this->flashFail("err", "Error", "Can't get queue", 80, true);
                    }

                    $audios = $this->audios->getByClub($entity, $page, $perPage);
                    $audiosCount = $this->audios->getClubCollectionSize($entity);
                }
                break;
            case "new_audios":
                $audios = $this->audios->getNew();
                $audiosCount = $audios->size();
                break;
            case "popular_audios":
                $audios = $this->audios->getPopular();
                $audiosCount = $audios->size();
                break;
            case "playlist_context":
                $playlist = $this->audios->getPlaylist($ctx_id);

                if (!$playlist || $playlist->isDeleted()) {
                    $this->flashFail("err", "Error", "Can't get queue", 80, true);
                }

                $audios = $playlist->fetch($page, 10);
                $audiosCount = $playlist->size();
                break;
            case "search_context":
                $stream = $this->audios->search($this->postParam("query"), 2, $this->postParam("type") === "by_performer");
                $audios = $stream->page($page, 10);
                $audiosCount = $stream->size();
                break;
            case "classic_search_context":
                $data = json_decode($this->postParam("context_entity"), true);

                $params = [];
                $order = [
                    "type" => $data['order'] ?? 'id',
                    "invert" => (int) $data['invert'] == 1 ? true : false,
                ];

                if ($data['genre'] && $data['genre'] != 'any') {
                    $params['genre'] = $data['genre'];
                }

                if ($data['only_performers'] && (int) $data['only_performers'] == 1) {
                    $params['only_performers'] = '1';
                }

                if ($data['with_lyrics'] && (int) $data['with_lyrics'] == 1) {
                    $params['with_lyrics'] = '1';
                }

                $stream = $this->audios->find($data['query'], $params, $order);
                $audios = $stream->page($page, 10);
                $audiosCount = $stream->size();
                break;
            case 'alone_audio':
                $found_audio = $this->audios->get($ctx_id);
                if (!$found_audio || $found_audio->isDeleted() || !$found_audio->canBeViewedBy($this->user->identity)) {
                    $this->flashFail("err", "Error", "Not found", 89, true);
                }

                $audios = [$found_audio];
                $audiosCount = 1;
                break;
            case "uploaded":
                $stream = $this->audios->getByUploader($this->user->identity);
                $audios = $stream->page($page, $perPage);
                $audiosCount = $stream->size();
        }

        $pagesCount = ceil($audiosCount / $perPage);

        # костылёк для получения плееров в пикере аудиозаписей
        if ((int) ($this->postParam("returnPlayers")) === 1) {
            $this->template->audios = $audios;
            $this->template->page = $page;
            $this->template->pagesCount = $pagesCount;
            $this->template->count = $audiosCount;

            return 0;
        }

        $audiosArr = [];

        foreach ($audios as $audio) {
            $output_array = [];
            $output_array['id'] = $audio->getId();
            $output_array['name'] = $audio->getTitle();
            $output_array['performer'] = $audio->getPerformer();

            if (!$audio->isWithdrawn()) {
                $output_array['keys'] = $audio->getKeys();
                $output_array['url'] = $audio->getUrl();
            }

            $output_array['length'] = $audio->getLength();
            $output_array['available'] = $audio->isAvailable();
            $output_array['withdrawn'] = $audio->isWithdrawn();

            $audiosArr[] = $output_array;
        }

        $resultArr = [
            "success" => true,
            "page" => $page,
            "perPage" => $perPage,
            "pagesCount" => $pagesCount,
            "count" => $audiosCount,
            "items" => $audiosArr,
        ];

        $this->returnJson($resultArr);
    }
}
