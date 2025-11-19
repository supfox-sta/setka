<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Entities\Artist;
use openvk\Web\Models\Entities\Audio;
use openvk\Web\Models\Repositories\Util\EntityStream;

class Artists
{
    private $ctx;
    private $artists;
    private $audios;
    private $playlists;

    public function __construct()
    {
        $this->ctx = DatabaseConnection::i()->getContext();
        $this->artists = $this->ctx->table('artists');
        $this->audios = $this->ctx->table('audios');
        $this->playlists = $this->ctx->table('playlists');
    }

    public function get(int $id): ?Artist
    {
        $row = $this->artists->get($id);
        if (!$row) return null;
        return new Artist($row);
    }

    public function getTracks(int $artistId, int $page = 1, int $perPage = 10): EntityStream
    {
        $search = $this->audios->where([
            'artist_id' => $artistId,
            'deleted' => 0,
            'withdrawn' => 0,
        ])->order('created DESC');
        return new EntityStream('Audio', $search);
    }

    public function getTopTracks(int $artistId, int $limit = 5): EntityStream
    {
        $search = $this->audios->where([
            'artist_id' => $artistId,
            'deleted' => 0,
            'withdrawn' => 0,
        ])->order('listens DESC')->limit($limit);
        return new EntityStream('Audio', $search);
    }

    public function getAlbums(int $artistId, int $page = 1, int $perPage = 10): EntityStream
    {
        $search = $this->playlists->where([
            'owner_artist_id' => $artistId,
            'is_album' => 1,
            'deleted' => 0,
        ])->order('id DESC');
        return new EntityStream('Playlist', $search);
    }
}
