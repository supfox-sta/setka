<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\Artists;
use openvk\Web\Models\Repositories\Audios;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Repositories\Photos;

final class ArtistPresenter extends OpenVKPresenter
{
    private Artists $artists;
    private Audios $audios;

    public function __construct()
    {
        $this->artists = new Artists();
        $this->audios = new Audios();
    }

    public function apiSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $q = trim((string) ($this->queryParam('q') ?? ''));
            $ctx = \Chandler\Database\DatabaseConnection::i()->getContext();
            $tbl = $ctx->table('artists');
            if ($q !== '') {
                // force compatible collation to avoid mix issues
                $tbl = $tbl->where('name COLLATE utf8mb4_unicode_ci LIKE ?', "%$q%");
            }
            $rows = $tbl->order('id DESC')->limit(20);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [ 'id' => (int) $r->id, 'name' => (string) $r->name ];
            }
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode([]);
        }
        exit;
    }

    public function renderApiSearch(): void
    {
        // Router compatibility wrapper
        $this->apiSearch();
    }

    public function renderTracks(int $id): void
    {
        $this->assertUserLoggedIn();
        $artist = $this->artists->get($id);
        if (!$artist) { $this->notFound(); }

        $page = (int) ($this->queryParam('p') ?? 1);
        $tracksStream = $this->artists->getTracks($artist->getId());
        $perPage = 25;
        $tracks = iterator_to_array($tracksStream->page($page, $perPage));
        $tracksCount = $tracksStream->size();
        $albumsCount = $this->artists->getAlbums($artist->getId())->size();

        $this->template->_template = 'Artist/Tracks.xml';
        $this->template->artist = $artist;
        $this->template->tracks = $tracks;
        $this->template->page = $page;
        $this->template->tracksCount = $tracksCount;
        $this->template->albumsCount = $albumsCount;
        $this->template->perPage = $perPage;
        // Owner (uploader)
        $this->template->ownerUser = null;
        try {
            $ctx = \Chandler\Database\DatabaseConnection::i()->getContext();
            $m = $ctx->table('artist_members')->where('artist_id', $artist->getId())->where('role', 'owner')->order('created ASC')->limit(1)->fetch();
            if ($m) { $this->template->ownerUser = (new Users())->get((int) $m->user_id); }
        } catch (\Throwable $e) {}
        // Avatar URL if present
        $this->template->artistAvatarUrl = null;
        try {
            $avatarId = $artist->getAvatarPhotoId();
            if ($avatarId) {
                $ph = (new Photos())->get($avatarId);
                if ($ph) $this->template->artistAvatarUrl = $ph->getURL();
            }
        } catch (\Throwable $e) {}
    }

    public function renderView(int $id): void
    {
        $this->assertUserLoggedIn();
        $artist = $this->artists->get($id);
        if (!$artist) { $this->notFound(); }

        $page = (int) ($this->queryParam('p') ?? 1);
        $topTracks = iterator_to_array($this->artists->getTopTracks($artist->getId(), 5));
        $albumsStream = $this->artists->getAlbums($artist->getId());
        $albums = iterator_to_array($albumsStream->page(1, 12));
        $tracksCount = $this->artists->getTracks($artist->getId())->size();
        $albumsCount = $albumsStream->size();

        $this->template->_template = 'Artist/View.xml';
        $this->template->artist = $artist;
        $this->template->topTracks = $topTracks;
        $this->template->albums = $albums;
        $this->template->page = $page;
        $this->template->tracksCount = $tracksCount;
        $this->template->albumsCount = $albumsCount;
        // Owner (uploader)
        $this->template->ownerUser = null;
        try {
            $ctx = \Chandler\Database\DatabaseConnection::i()->getContext();
            $m = $ctx->table('artist_members')->where('artist_id', $artist->getId())->where('role', 'owner')->order('created ASC')->limit(1)->fetch();
            if ($m) { $this->template->ownerUser = (new Users())->get((int) $m->user_id); }
        } catch (\Throwable $e) {}
        // Avatar URL if present
        $this->template->artistAvatarUrl = null;
        try {
            $avatarId = $artist->getAvatarPhotoId();
            if ($avatarId) {
                $ph = (new Photos())->get($avatarId);
                if ($ph) $this->template->artistAvatarUrl = $ph->getURLBySizeId('normal');
            }
        } catch (\Throwable $e) {}
    }

    public function renderAlbums(int $id): void
    {
        $this->assertUserLoggedIn();
        $artist = $this->artists->get($id);
        if (!$artist) { $this->notFound(); }

        $page = (int) ($this->queryParam('p') ?? 1);
        $albumsStream = $this->artists->getAlbums($artist->getId());
        $albums = iterator_to_array($albumsStream->page($page, 10));
        $tracksCount = $this->artists->getTracks($artist->getId())->size();
        $albumsCount = $albumsStream->size();

        $this->template->_template = 'Artist/Albums.xml';
        $this->template->artist = $artist;
        $this->template->albums = $albums;
        $this->template->page = $page;
        $this->template->tracksCount = $tracksCount;
        $this->template->albumsCount = $albumsCount;
        // Owner (uploader)
        $this->template->ownerUser = null;
        try {
            $ctx = \Chandler\Database\DatabaseConnection::i()->getContext();
            $m = $ctx->table('artist_members')->where('artist_id', $artist->getId())->where('role', 'owner')->order('created ASC')->limit(1)->fetch();
            if ($m) { $this->template->ownerUser = (new Users())->get((int) $m->user_id); }
        } catch (\Throwable $e) {}
        // Avatar URL if present
        $this->template->artistAvatarUrl = null;
        try {
            $avatarId = $artist->getAvatarPhotoId();
            if ($avatarId) {
                $ph = (new Photos())->get($avatarId);
                if ($ph) $this->template->artistAvatarUrl = $ph->getURL();
            }
        } catch (\Throwable $e) {}
    }

    public function renderBecome(): void
    {
        $this->assertUserLoggedIn();
        $this->template->_template = 'Artist/Become.xml';
    }

    public function renderCabinet(): void
    {
        $this->assertUserLoggedIn();
        $ctx = \Chandler\Database\DatabaseConnection::i()->getContext();
        $uid = $this->user->identity->getId();
        $requestedId = (int) ($this->queryParam('id') ?? 0);
        $row = null;
        if ($requestedId > 0) {
            $row = $ctx->table('artist_members')->where('user_id', $uid)->where('artist_id', $requestedId)->fetch();
        }
        if (!$row) {
            // pick first artist where current user is member
            $row = $ctx->table('artist_members')->where('user_id', $uid)->order('created ASC')->limit(1)->fetch();
        }
        if (!$row) {
            // no access page
            $this->template->_template = 'Artist/CabinetNoAccess.xml';
            return;
        }
        $artist = $this->artists->get((int)$row->artist_id);
        if (!$artist) {
            $this->template->_template = 'Artist/CabinetNoAccess.xml';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->assertNoCSRF();
            $op = (string) ($this->postParam('act') ?? 'save_profile');
            // Infer avatar-only update when file is uploaded and no explicit op provided
            try {
                if (($op === 'save_profile' || $op === '' || $op === null) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK && empty($this->postParam('name'))) {
                    $op = 'update_avatar';
                }
            } catch (\Throwable $e) {}
            if ($op === 'attach_liked_tracks') {
                // Attach selected liked tracks to this artist
                $idsRaw = $_POST['audio_ids'] ?? [];
                if (!is_array($idsRaw)) $idsRaw = [$idsRaw];
                $ids = array_values(array_unique(array_map('intval', $idsRaw)));
                if (count($ids) === 0) { $this->flash('err', tr('error'), 'Выберите треки'); $this->redirect('/artists/cabinet'); }
                $ok = 0; $fail = [];
                foreach ($ids as $aid) {
                    try {
                        $ctx->getConnection()->query("UPDATE `audios` SET `artist_id`={$artist->getId()}, `type`=1 WHERE `id`={$aid}");
                        $row = $ctx->table('audios')->get($aid);
                        if ($row && (int)$row->artist_id === (int)$artist->getId()) $ok++; else $fail[] = $aid;
                    } catch (\Throwable $e) { $fail[] = $aid; }
                }
                if ($ok > 0) $this->flash('succ', 'Добавлено треков: ' . $ok);
                if (count($fail) > 0) $this->flash('err', tr('error'), 'Не удалось: ' . implode(',', $fail));
                $this->redirect('/artists/cabinet');
            } elseif ($op === 'attach_liked_playlist') {
                // Attach all tracks from selected liked playlist to artist
                $pid = (int) ($this->postParam('playlist_id') ?? 0);
                if ($pid <= 0) { $this->flash('err', tr('error'), 'Выберите альбом'); $this->redirect('/artists/cabinet'); }
                $pl = $this->audios->getPlaylist($pid);
                if (!$pl) { $this->flash('err', tr('error'), 'Альбом не найден'); $this->redirect('/artists/cabinet'); }
                $ok = 0; $fail = 0;
                try {
                    $all = iterator_to_array($pl->fetch(1, $pl->size()));
                    // Create artist album (duplicate metadata)
                    $newRow = $ctx->table('playlists')->insert([
                        'name' => $pl->getName(),
                        'description' => $pl->getDescription(),
                        'owner' => $uid,
                        'owner_artist_id' => $artist->getId(),
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
                            $ctx->getConnection()->query("UPDATE `audios` SET `artist_id`={$artist->getId()}, `type`=1 WHERE `id`={$aid}");
                            $row = $ctx->table('audios')->get($aid);
                            if ($row && (int)$row->artist_id === (int)$artist->getId()) {
                                $ok++;
                                // also add to artist album
                                try { $newPlaylist->add($audio); } catch (\Throwable $e2) {}
                            } else {
                                $fail++;
                            }
                        } catch (\Throwable $e) { $fail++; }
                    }
                } catch (\Throwable $e) {}
                if ($ok > 0) $this->flash('succ', 'Добавлено треков: ' . $ok);
                if ($fail > 0) $this->flash('err', tr('error'), 'Ошибок: ' . $fail);
                $this->redirect('/artists/cabinet');
            } elseif ($op === 'update_avatar') {
                // Update only avatar, do not require name
                $avatarId = null;
                try {
                    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                        if (!str_starts_with($_FILES['avatar']['type'] ?? '', 'image')) {
                            $this->flash('err', tr('error'), tr('not_a_photo'));
                            $this->redirect('/artists/cabinet');
                        }
                        try {
                            $ph = \openvk\Web\Models\Entities\Photo::fastMake($uid, 'Artist avatar', $_FILES['avatar'], null, true);
                            $avatarId = $ph->getId();
                        } catch (\Throwable $e) {
                            $avatarId = null;
                        }
                    }
                } catch (\Throwable $e) {}

                if ($avatarId) {
                    $ctx->table('artists')->where('id', $artist->getId())->update([
                        'avatar_photo_id' => $avatarId,
                        'edited' => time(),
                    ]);
                    $this->flash('succ', tr('changes_saved'));
                } else {
                    $this->flash('err', tr('error'), tr('not_a_photo'));
                }
                $this->redirect('/artists/cabinet');
            } else {
                // Save profile
                $name = trim((string) $this->postParam('name'));
                if ($name === '') {
                    $this->flash('err', tr('error'), 'Укажите имя исполнителя');
                } else {
                    $bio = $this->postParam('bio');
                    $avatarId = null;
                    try {
                        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                            if (!str_starts_with($_FILES['avatar']['type'] ?? '', 'image')) {
                                $this->flash('err', tr('error'), tr('not_a_photo'));
                                $this->redirect('/artists/cabinet');
                            }
                            try {
                                $ph = \openvk\Web\Models\Entities\Photo::fastMake($uid, 'Artist avatar', $_FILES['avatar'], null, true);
                                $avatarId = $ph->getId();
                            } catch (\Throwable $e) {
                                $avatarId = null;
                            }
                        }
                    } catch (\Throwable $e) {}

                    $upd = [ 'name' => $name, 'bio' => $bio ?: null, 'edited' => time() ];
                    if ($avatarId) { $upd['avatar_photo_id'] = $avatarId; }
                    $ctx->table('artists')->where('id', $artist->getId())->update($upd);
                    $this->flash('succ', tr('changes_saved'));
                    $this->redirect('/artists/cabinet');
                }
            }
        }

        // tracks overview
        try {
            $tracks = iterator_to_array($this->artists->getTracks($artist->getId())->page(1, 50));
        } catch (\Throwable $e) {
            $tracks = [];
        }
        // albums overview
        try {
            $albums = iterator_to_array($this->artists->getAlbums($artist->getId())->page(1, 50));
        } catch (\Throwable $e) { $albums = []; }

        $this->template->_template = 'Artist/Cabinet.xml';
        $this->template->artist = $artist;
        $this->template->tracks = $tracks;
        $this->template->albums = $albums;
        // Liked lists for attach UI
        try {
            $this->template->liked_tracks = iterator_to_array($this->audios->getByUser($this->user->identity, 1, 200));
        } catch (\Throwable $e) { $this->template->liked_tracks = []; }
        try {
            $this->template->liked_playlists = iterator_to_array($this->audios->getPlaylistsByUser($this->user->identity, 1, 100));
        } catch (\Throwable $e) { $this->template->liked_playlists = []; }
        // Avatar URL
        $this->template->artistAvatarUrl = null;
        try {
            $avatarId = $artist->getAvatarPhotoId();
            if ($avatarId) {
                $ph = (new Photos())->get($avatarId);
                if ($ph) $this->template->artistAvatarUrl = $ph->getURLBySizeId('normal');
            }
        } catch (\Throwable $e) {}
    }
}
