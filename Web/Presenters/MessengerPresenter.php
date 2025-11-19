<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages, Photos, Videos, Audios, Documents};
use openvk\Web\Models\Entities\{Message, Correspondence};
use Chandler\Database\DatabaseConnection;

final class MessengerPresenter extends OpenVKPresenter
{
    private $messages;
    private $signaler;
    protected $presenterName = "messenger";

    private $db;

    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
        $this->signaler = SignalManager::i();
        $this->db       = DatabaseConnection::i()->getContext();

        parent::__construct();
    }

    /**
     * Normalize URL scheme to https if current request is https and host matches.
     */
    private function normalizeUrl(?string $url): string
    {
        $u = (string)($url ?? '');
        if ($u === '') return '';
        try {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
            if ($isHttps && strncmp($u, 'http://', 7) === 0) {
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $h = parse_url($u, PHP_URL_HOST) ?: '';
                if ($host === '' || $h === $host) {
                    return 'https://' . substr($u, 7);
                }
            }
        } catch (\Throwable $e) {}
        return $u;
    }

    public function renderApiDialogsRecent(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        try {
            $uid = (int)$this->user->id;
            // collect distinct peer user IDs from messages where it's a direct dialog (conversation_id null or 0)
            $peers = [];
            // sent by me
            try {
                $rows = $this->db->table('messages')->where('sender_id', $uid)->where('conversation_id IS NULL OR conversation_id = 0')->select('recipient_id')->group('recipient_id')->fetchAll();
                foreach ($rows as $r) { $pid = (int)($r['recipient_id'] ?? 0); if ($pid > 0 && $pid !== $uid) $peers[$pid] = true; }
            } catch (\Throwable $e) {}
            // received by me
            try {
                $rows = $this->db->table('messages')->where('recipient_id', $uid)->where('conversation_id IS NULL OR conversation_id = 0')->select('sender_id')->group('sender_id')->fetchAll();
                foreach ($rows as $r) { $pid = (int)($r['sender_id'] ?? 0); if ($pid > 0 && $pid !== $uid) $peers[$pid] = true; }
            } catch (\Throwable $e) {}
            $ids = array_keys($peers);
            // fallback: include friends if no peers found
            if (count($ids) === 0) {
                $conn = \Chandler\Database\DatabaseConnection::i()->getConnection();
                $model = 'openvk\\Web\\Models\\Entities\\User';
                $sql = "SELECT s1.target AS id FROM subscriptions s1 WHERE s1.follower = ? AND s1.model = ? AND EXISTS (SELECT 1 FROM subscriptions s2 WHERE s2.follower = s1.target AND s2.target = ? AND s2.model = ?)";
                $res = $conn->query($sql, $uid, $model, $uid, $model);
                foreach ($res as $r) { $ids[] = (int)$r->id; }
                $ids = array_values(array_unique($ids));
            }
            $items = [];
            $usersRepo = new Users();
            foreach ($ids as $pid) {
                $u = $usersRepo->get($pid);
                if (!$u) continue;
                $items[] = [ 'id' => $pid, 'name' => $u->getFirstName(), 'avatar' => $u->getAvatarUrl() ];
            }
            exit(json_encode(['ok'=>true,'items'=>$items]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'List failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiConversationsList(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        // list conversations where current user is a member
        try {
            $uid = (int)$this->user->id;
            $rows = $this->db->table('conversation_members')->where('member_id', $uid)->fetchAll();
            $ids = [];
            foreach ($rows as $r) { $ids[] = (int)$r['conversation_id']; }
            $ids = array_values(array_unique($ids));
            $out = [];
            foreach ($ids as $cid) {
                $conv = $this->db->table('conversations')->where('id', $cid)->fetch();
                if (!$conv) continue;
                $out[] = [
                    'id' => (int)$conv['id'],
                    'title' => (string)($conv['title'] ?? ('ID ' . (int)$conv['id'])),
                    'avatar_url' => (string)($conv['avatar_url'] ?? ''),
                ];
            }
            exit(json_encode(['ok'=>true,'items'=>$out]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'List failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiMessagesDelete(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) { header('HTTP/1.1 400 Bad Request'); exit(json_encode(['ok'=>false,'error'=>'No ids'])); }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $usersRepo = new Users(); // for potential future use
            foreach ($ids as $mid) {
                $row = $this->db->table('messages')->where('id', $mid)->fetch();
                if (!$row) continue;
                $senderId = (int)$row['sender_id'];
                $convId   = isset($row['conversation_id']) ? (int)$row['conversation_id'] : 0;
                $allowed = false;
                if ($senderId === (int)$this->user->id) {
                    $allowed = true;
                } elseif ($convId > 0) {
                    $role = $this->roleOfConv($convId, (int)$this->user->id);
                    if (in_array($role, ['owner','moderator'], true)) $allowed = true;
                }
                if (!$allowed) continue;
                // mark as deleted via message_attachments (non-destructive)
                $exists = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'deleted')->fetch();
                if (!$exists) {
                    $this->db->table('message_attachments')->insert([
                        'message_id' => $mid,
                        'attachable_type' => 'deleted',
                        'attachable_id' => 1,
                    ]);
                }
            }
            exit(json_encode(['ok'=>true]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Delete failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiMyPhotos(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        try {
            $offset = (int)($_GET['offset'] ?? 0);
            $limit  = (int)($_GET['limit']  ?? 5);
            if ($limit < 1 || $limit > 100) $limit = 5;

            $repo = new Photos();
            $items = [];
            foreach ($repo->getEveryUserPhoto($this->user->identity, $offset, $limit) as $photo) {
                $url = method_exists($photo, 'getURLBySizeId') ? $photo->getURLBySizeId('normal') : $photo->getURL();
                if (!$url) { // fallback to generic URL method if size-id not available
                    try { $url = $photo->getURL(); } catch (\Throwable $e) { $url = ''; }
                }
                if ($url === null) $url = '';
                $url = $this->normalizeUrl($url);
                $items[] = [
                    'id' => $photo->getId(),
                    'type' => 'photo',
                    'url' => (string)$url,
                    'title' => (string)$photo->getDescription(),
                ];
            }
            exit(json_encode(['ok'=>true,'items'=>$items]));
        } catch (\Throwable $e) {
            exit(json_encode(['ok'=>false,'items'=>[], 'error'=>'photos_failed']));
        }
    }

    public function renderApiMyVideos(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        try {
            $offset = (int)($_GET['offset'] ?? 0);
            $limit  = (int)($_GET['limit']  ?? 5);
            if ($limit < 1 || $limit > 100) $limit = 5;

            $repo = new Videos();
            $items = [];
            foreach ($repo->getByUserLimit($this->user->identity, $offset, $limit) as $video) {
                $thumb = '';
                try { $thumb = (string)$video->getThumbnailURL(); } catch (\Throwable $e) { $thumb = ''; }
                $thumb = $this->normalizeUrl($thumb);
                $url = $thumb; // fallback so frontend can use (thumb || url)
                $items[] = [
                    'id' => $video->getId(),
                    'type' => 'video',
                    'thumb' => $thumb,
                    'url' => $url,
                    'title' => (string)$video->getName(),
                ];
            }
            exit(json_encode(['ok'=>true,'items'=>$items]));
        } catch (\Throwable $e) {
            exit(json_encode(['ok'=>false,'items'=>[], 'error'=>'videos_failed']));
        }
    }

    public function renderApiMyAudios(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        try {
            $offset = (int)($_GET['offset'] ?? 0);
            $limit  = (int)($_GET['limit']  ?? 5);
            if ($limit < 1 || $limit > 100) $limit = 5;

            $repo = new Audios();
            $user = $this->user->identity;
            $items = [];
            foreach ($repo->getUserCollection($user, $offset, $limit) as $audio) {
                $items[] = [
                    'id' => $audio->getId(),
                    'type' => 'audio',
                    'title' => (string)$audio->getName(),
                    'artist' => (string)$audio->getPerformer(),
                    'duration' => $audio->getLength(),
                ];
            }
            exit(json_encode(['ok'=>true,'items'=>$items]));
        } catch (\Throwable $e) {
            exit(json_encode(['ok'=>false,'items'=>[], 'error'=>'audios_failed']));
        }
    }

    public function renderApiMyDocuments(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        try {
            $offset = (int)($_GET['offset'] ?? 0);
            $limit  = (int)($_GET['limit']  ?? 5);
            if ($limit < 1 || $limit > 100) $limit = 5;

            $repo = new Documents();
            $items = [];
            foreach ($repo->getUserDocuments($this->user->identity, $offset, $limit) as $document) {
                $url = '';
                try { $url = (string)$document->getURL(); } catch (\Throwable $e) { $url = ''; }
                $url = $this->normalizeUrl($url);
                $items[] = [
                    'id' => $document->getId(),
                    'type' => 'document',
                    'title' => (string)$document->getName(),
                    'size' => $document->getFileSize(),
                    'url' => $url,
                ];
            }
            exit(json_encode(['ok'=>true,'items'=>$items]));
        } catch (\Throwable $e) {
            exit(json_encode(['ok'=>false,'items'=>[], 'error'=>'documents_failed']));
        }
    }

    private function getCorrespondent(int $id): object
    {
        if ($id > 0) {
            return (new Users())->get($id);
        } elseif ($id < 0) {
            return (new Clubs())->get(abs($id));
        } elseif ($id === 0) {
            return $this->user->identity;
        }
    }

    /* === Group conversations API (under Messenger presenter) === */
    public function renderApiConversationsMembers(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        $convId = (int)($this->queryParam('conversation_id') ?? 0);
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        if (!$this->isMemberOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $rows = $this->db->table('conversation_members')->where('conversation_id', $convId)->order('role DESC, joined ASC')->fetchAll();
        $members = [];
        $usersRepo = new Users();
        foreach ($rows as $r) {
            $uid = (int)$r['member_id'];
            $u = $usersRepo->get($uid);
            $members[] = [
                'user_id' => $uid,
                'role' => (string)$r['role'],
                'joined_at' => (string)$r['joined'],
                'name' => $u ? ($u->getFirstName() . ' ' . $u->getLastName()) : ('ID ' . $uid),
                'avatar' => $u ? $u->getAvatarUrl() : '/assets/packages/static/openvk/img/dialog_group.png',
            ];
        }
        exit(json_encode(['ok'=>true,'members'=>$members]));
    }

    public function renderApiConversationsInviteGenerate(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $token = bin2hex(random_bytes(16));
        $this->db->table('conversations')->where('id', $convId)->update(['invite_token' => $token]);
        exit(json_encode(['ok'=>true,'invite_token'=>$token]));
    }

    public function renderApiConversationsInviteRevoke(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $this->db->table('conversations')->where('id', $convId)->update(['invite_token' => null]);
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsJoin(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET') header('Content-Type: application/json');
        // accept token from GET or POST
        $token = (string)($this->queryParam('token') ?? ($this->postParam('token') ?? ''));
        if ($token === '') {
            if ($method === 'GET') { header('HTTP/1.1 400 Bad Request'); exit('Missing token'); }
            exit(json_encode(['ok'=>false,'error'=>'Missing token']));
        }
        $conv = $this->db->table('conversations')->where('invite_token', $token)->fetch();
        if (!$conv) {
            if ($method === 'GET') { header('HTTP/1.1 404 Not Found'); exit('Invalid link'); }
            exit(json_encode(['ok'=>false,'error'=>'Invalid link']));
        }
        $convId = (int)$conv['id'];
        // deny if banned
        if ($this->isBanned($convId, (int)$this->user->id)) {
            if ($method === 'GET') { header('HTTP/1.1 403 Forbidden'); exit('You are banned from this conversation'); }
            exit(json_encode(['ok'=>false,'error'=>'Banned']));
        }
        if ($this->memberCountConv($convId) >= 50) {
            if ($method === 'GET') { header('HTTP/1.1 400 Bad Request'); exit('Members limit reached'); }
            exit(json_encode(['ok'=>false,'error'=>'Members limit reached']));
        }
        $exists = $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $this->user->id)->fetch();
        if (!$exists) {
            $this->db->table('conversation_members')->insert([
                'conversation_id' => $convId,
                'member_id' => (int)$this->user->id,
                'role' => 'member',
                'joined' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($method === 'GET') {
            header('Location: /grp' . $convId);
            exit;
        }
        exit(json_encode(['ok'=>true,'conversation_id'=>$convId]));
    }

    public function renderApiConversationsRemoveMember(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        $userId = (int)($this->postParam('user_id') ?? 0);
        if (!$convId || !$userId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id or user_id'])); }
        $myRole = $this->roleOfConv($convId, (int)$this->user->id);
        $targetRole = $this->roleOfConv($convId, $userId);
        if (!$myRole) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        if ($myRole === 'owner' || ($myRole === 'moderator' && $targetRole === 'member')) {
            $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->delete();
            exit(json_encode(['ok'=>true]));
        }
        exit(json_encode(['ok'=>false,'error'=>'Forbidden']));
    }

    public function renderApiConversationsLeave(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        // Must be member
        $myRole = $this->roleOfConv($convId, (int)$this->user->id);
        if (!$myRole) { exit(json_encode(['ok'=>false,'error'=>'Not a member'])); }
        // If owner and there are other members, forbid simple leave
        $count = $this->memberCountConv($convId);
        if ($myRole === 'owner' && $count > 1) {
            exit(json_encode(['ok'=>false,'error'=>'Owner must transfer ownership before leaving']));
        }
        // Remove membership
        $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', (int)$this->user->id)->delete();
        // If owner and last member -> delete conversation fully
        if ($myRole === 'owner' && $count <= 1) {
            $this->db->table('conversations')->where('id', $convId)->delete();
        }
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsSetRole(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        $userId = (int)($this->postParam('user_id') ?? 0);
        $role   = (string)($this->postParam('role') ?? '');
        if (!$convId || !$userId || !in_array($role, ['moderator','member'], true)) { exit(json_encode(['ok'=>false,'error'=>'Bad params'])); }
        if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->where('role != ?', 'owner')->update(['role' => $role]);
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsTransferOwner(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        $newOwnerId = (int)($this->postParam('user_id') ?? 0);
        if (!$convId || !$newOwnerId) { exit(json_encode(['ok'=>false,'error'=>'Missing params'])); }
        if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        // ensure target is member
        $isMember = $this->isMemberOfConv($convId, $newOwnerId);
        if (!$isMember) { exit(json_encode(['ok'=>false,'error'=>'User is not a member'])); }
        // set previous owner to member
        $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', (int)$this->user->id)->update(['role' => 'member']);
        // promote new owner
        $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $newOwnerId)->update(['role' => 'owner']);
        // update conversations.owner_id
        $this->db->table('conversations')->where('id', $convId)->update(['owner_id' => $newOwnerId]);
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsBan(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        $userId = (int)($this->postParam('user_id') ?? 0);
        if (!$convId || !$userId) { exit(json_encode(['ok'=>false,'error'=>'Missing params'])); }
        $myRole = $this->roleOfConv($convId, (int)$this->user->id);
        if (!in_array($myRole, ['owner','moderator'], true)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        // remove from members if present
        try { $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->delete(); } catch (\Throwable $e) {}
        // upsert ban row
        $exists = $this->db->table('conversation_bans')->where('conversation_id', $convId)->where('user_id', $userId)->fetch();
        if (!$exists) {
            $this->db->table('conversation_bans')->insert([
                'conversation_id' => $convId,
                'user_id' => $userId,
                'created' => date('Y-m-d H:i:s'),
            ]);
        }
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsUnban(): void
    {
        $this->assertUserLoggedIn(); $this->willExecuteWriteAction();
        header('Content-Type: application/json');
        $convId = (int)($this->postParam('conversation_id') ?? 0);
        $userId = (int)($this->postParam('user_id') ?? 0);
        if (!$convId || !$userId) { exit(json_encode(['ok'=>false,'error'=>'Missing params'])); }
        $myRole = $this->roleOfConv($convId, (int)$this->user->id);
        if (!in_array($myRole, ['owner','moderator'], true)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $this->db->table('conversation_bans')->where('conversation_id', $convId)->where('user_id', $userId)->delete();
        exit(json_encode(['ok'=>true]));
    }

    public function renderApiConversationsBans(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        $convId = (int)($this->queryParam('conversation_id') ?? 0);
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        if (!$this->isMemberOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
        $rows = $this->db->table('conversation_bans')->where('conversation_id', $convId)->fetchAll();
        $out = [];
        $usersRepo = new Users();
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $u = $usersRepo->get($uid);
            $out[] = [
                'user_id' => $uid,
                'name' => $u ? $u->getFirstName() : ('ID ' . $uid),
                'avatar' => $u ? $u->getAvatarUrl() : '/assets/packages/static/openvk/img/dialog_group.png',
                'created' => (string)($r['created'] ?? ''),
            ];
        }
        exit(json_encode(['ok'=>true,'bans'=>$out]));
    }

    // helpers for conversations
    private function isOwnerOfConv(int $convId, int $userId): bool
    {
        $row = $this->db->table('conversations')->where('id', $convId)->where('owner_id', $userId)->fetch();
        return (bool)$row;
    }
    private function isMemberOfConv(int $convId, int $userId): bool
    {
        $row = $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->fetch();
        return (bool)$row;
    }
    private function roleOfConv(int $convId, int $userId): ?string
    {
        $row = $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->fetch();
        return $row ? (string)$row['role'] : null;
    }
    private function memberCountConv(int $convId): int
    {
        $row = $this->db->table('conversation_members')->where('conversation_id', $convId)->select('COUNT(*) AS cnt')->fetch();
        return $row ? (int)$row['cnt'] : 0;
    }

    public function renderApiConversationsRename(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('HTTP/1.1 405 Method Not Allowed'); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }
            $convId = (int)($this->postParam('conversation_id') ?? 0);
            $title  = trim((string)($this->postParam('title') ?? ''));
            if (!$convId) exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id']));
            if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) exit(json_encode(['ok'=>false,'error'=>'Forbidden']));
            $this->db->table('conversations')->where('id', $convId)->update(['title'=>$title]);
            exit(json_encode(['ok'=>true,'conversation_id'=>$convId,'title'=>$title]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Rename failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiConversationsSetAvatar(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('HTTP/1.1 405 Method Not Allowed'); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }
            $convId  = (int)($this->postParam('conversation_id') ?? 0);
            $photoId = (int)($this->postParam('photo_id') ?? 0);
            $photoUrl = (string)($this->postParam('photo_url') ?? '');
            if (!$convId || !$photoId) exit(json_encode(['ok'=>false,'error'=>'Missing params']));
            if (!$this->isOwnerOfConv($convId, (int)$this->user->id)) exit(json_encode(['ok'=>false,'error'=>'Forbidden']));
            // allow null column if migration not applied; wrap in try; also try to store direct avatar_url if column exists
            $upd = ['avatar_photo_id'=>$photoId];
            if ($photoUrl !== '') { $upd['avatar_url'] = $photoUrl; }
            try { $this->db->table('conversations')->where('id', $convId)->update($upd); }
            catch (\Throwable $e) { $this->db->table('conversations')->where('id', $convId)->update(['avatar_photo_id'=>$photoId]); }
            // resolve url for immediate UI update
            $avatarUrl = $photoUrl !== '' ? $photoUrl : null;
            if ($avatarUrl === null) {
                try {
                    $p = (new \openvk\Web\Models\Repositories\Photos())->get($photoId);
                    if ($p) $avatarUrl = $p->getURL();
                } catch (\Throwable $e) {}
            }
            exit(json_encode(['ok'=>true,'avatar_url'=>$avatarUrl]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Set avatar failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiFriends(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        $uid = (int)$this->user->id;
        $convId = (int)($this->queryParam('conversation_id') ?? 0);
        // Base: subscriptions mutual
        $conn = \Chandler\Database\DatabaseConnection::i()->getConnection();
        $model = 'openvk\\Web\\Models\\Entities\\User';
        $sql = "SELECT s1.target AS id FROM subscriptions s1 WHERE s1.follower = ? AND s1.model = ? AND EXISTS (SELECT 1 FROM subscriptions s2 WHERE s2.follower = s1.target AND s2.target = ? AND s2.model = ?)";
        $res = $conn->query($sql, $uid, $model, $uid, $model);
        $ids = [];
        foreach ($res as $r) { $ids[] = (int)$r->id; }
        // Exclude already members of conversation (if provided)
        if ($convId && count($ids) > 0) {
            $memRows = $this->db->table('conversation_members')->where('conversation_id', $convId)->fetchAll();
            $mem = [];
            foreach ($memRows as $m) { $mem[] = (int)$m['member_id']; }
            $ids = array_values(array_diff($ids, $mem));
        }
        $out = [];
        $usersRepo = new Users();
        foreach ($ids as $fid) {
            $u = $usersRepo->get($fid);
            if (!$u) continue;
            $out[] = [ 'id'=>$fid, 'name'=>$u->getFirstName(), 'avatar'=>$u->getAvatarUrl() ];
        }
        exit(json_encode(['ok'=>true,'items'=>$out]));
    }

    public function renderApiConversationsMessages(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');
        $convId = (int)($this->queryParam('conversation_id') ?? 0);
        $after  = (int)($this->queryParam('after') ?? 0); // last known id; return newer than this if provided
        $limit  = (int)($this->queryParam('limit') ?? 50);
        if ($limit < 1 || $limit > 200) $limit = 50;
        if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
        if (!$this->isMemberOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }

        $q = $this->db->table('messages')->where('conversation_id', $convId)->select('id, sender_id, content, created, conversation_id, reply_to_id');
        if ($after > 0) $q = $q->where('id > ?', $after);
        $rows = $q->order('id DESC')->limit($limit)->fetchAll();
        $rows = array_reverse($rows);

        $usersRepo = new Users();
        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['sender_id'];
            $u   = $usersRepo->get($uid);
            // reply summary (support both reply_to_id and reply_to column names)
            $reply = null;
            $replyCol = null;
            if (isset($r['reply_to_id']) && $r['reply_to_id']) {
                $replyCol = (int)$r['reply_to_id'];
            } elseif (isset($r['reply_to']) && $r['reply_to']) {
                $replyCol = (int)$r['reply_to'];
            }
            if (!empty($replyCol)) {
                $orig = $this->db->table('messages')->where('id', $replyCol)->fetch();
                if ($orig) {
                    $ou = $usersRepo->get((int)$orig['sender_id']);
                    $reply = [
                        'uuid' => (int)$orig['id'],
                        'sender' => [
                            'id' => (int)$orig['sender_id'],
                            'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']),
                        ],
                        'text' => (string)($orig['content'] ?? ''),
                    ];
                }
            } else {
                // fallback for legacy: check message_attachments
                try {
                    $att = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'reply')->fetch();
                    if ($att && isset($att['attachable_id'])) {
                        $orig = $this->db->table('messages')->where('id', (int)$att['attachable_id'])->fetch();
                        if ($orig) {
                            $ou = $usersRepo->get((int)$orig['sender_id']);
                            $reply = [
                                'uuid' => (int)$orig['id'],
                                'sender' => [
                                    'id' => (int)$orig['sender_id'],
                                    'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']),
                                ],
                                'text' => (string)($orig['content'] ?? ''),
                            ];
                        }
                    }
                } catch (\Throwable $e) {}
            }
            // forwards, media and deleted
            $attachments = [];
            // forward attachments
            try {
                $fwds = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'forward')->fetchAll();
                if ($fwds && count($fwds) > 0) {
                    foreach ($fwds as $f) {
                        $orig = $this->db->table('messages')->where('id', (int)$f['attachable_id'])->fetch();
                        if ($orig) {
                            $ou = $usersRepo->get((int)$orig['sender_id']);
                            $attachments[] = [
                                'type' => 'forward',
                                'id'   => (int)$f['attachable_id'],
                                'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                                'text' => (string)($orig['content'] ?? ''),
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {}
            // photo attachments
            try {
                $phs = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'photo')->fetchAll();
                if ($phs && count($phs) > 0) {
                    $photosRepo = new Photos();
                    foreach ($phs as $p) {
                        $pid = (int)$p['attachable_id'];
                        try {
                            $ph = $photosRepo->get($pid);
                            if ($ph) {
                                $url = method_exists($ph, 'getURLBySizeId') ? $ph->getURLBySizeId('normal') : $ph->getURL();
                                $url = $this->normalizeUrl($url);
                                if ($url) {
                                    $attachments[] = [
                                        'type' => 'photo',
                                        'id'   => $pid,
                                        'link' => $url,
                                        'photo'=> [ 'url' => $url, 'caption' => (string)$ph->getDescription() ],
                                    ];
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
                }
                // fallback for plural type
                $phs2 = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'photos')->fetchAll();
                if ($phs2 && count($phs2) > 0) {
                    $photosRepo = isset($photosRepo) ? $photosRepo : new Photos();
                    foreach ($phs2 as $p) {
                        $pid = (int)$p['attachable_id'];
                        try {
                            $ph = $photosRepo->get($pid);
                            if ($ph) {
                                $url = method_exists($ph, 'getURLBySizeId') ? $ph->getURLBySizeId('normal') : $ph->getURL();
                                if ($url) {
                                    $attachments[] = [
                                        'type' => 'photo',
                                        'id'   => $pid,
                                        'link' => $url,
                                        'photo'=> [ 'url' => $url, 'caption' => (string)$ph->getDescription() ],
                                    ];
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            } catch (\Throwable $e) {}
            // video attachments
            try {
                $vds = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'video')->fetchAll();
                if ($vds && count($vds) > 0) {
                    $videosRepo = new Videos();
                    foreach ($vds as $v) {
                        $vid = (int)$v['attachable_id'];
                        try {
                            $vv = $videosRepo->get($vid);
                            if ($vv && $vv->getThumbnailURL() && $vv->getName()) {
                                $thumb = $this->normalizeUrl((string)$vv->getThumbnailURL());
                                $page  = $this->normalizeUrl((string)$vv->getPageURL());
                                $attachments[] = [
                                    'type'  => 'video',
                                    'id'    => $vid,
                                    'url'   => $page,
                                    'thumb' => $thumb,
                                    'title' => (string)$vv->getName(),
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
                // fallback for plural type
                $vds2 = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'videos')->fetchAll();
                if ($vds2 && count($vds2) > 0) {
                    $videosRepo = isset($videosRepo) ? $videosRepo : new Videos();
                    foreach ($vds2 as $v) {
                        $vid = (int)$v['attachable_id'];
                        try {
                            $vv = $videosRepo->get($vid);
                            if ($vv && $vv->getThumbnailURL() && $vv->getName()) {
                                $attachments[] = [
                                    'type'  => 'video',
                                    'id'    => $vid,
                                    'url'   => $vv->getPageURL(),
                                    'thumb' => $vv->getThumbnailURL(),
                                    'title' => (string)$vv->getName(),
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            } catch (\Throwable $e) {}
            // audio attachments
            try {
                $ads = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'audio')->fetchAll();
                if ($ads && count($ads) > 0) {
                    $audiosRepo = new Audios();
                    foreach ($ads as $a) {
                        $aid = (int)$a['attachable_id'];
                        try {
                            $aa = $audiosRepo->get($aid);
                            if ($aa && $aa->getName()) {
                                $owner = $aa->getOwner();
                                $ownerId = $owner ? $owner->getId() * ($owner instanceof \openvk\Web\Models\Entities\Club ? -1 : 1) : 0;
                                $attachments[] = [
                                    'type' => 'audio',
                                    'id' => $aid,
                                    'owner_id' => $ownerId,
                                    'url' => '/audio' . $aa->getPrettyId(),
                                    'title' => (string)$aa->getName(),
                                    'artist' => (string)$aa->getPerformer(),
                                    'duration' => $aa->getLength(),
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
                // fallback for plural type
                $ads2 = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'audios')->fetchAll();
                if ($ads2 && count($ads2) > 0) {
                    $audiosRepo = isset($audiosRepo) ? $audiosRepo : new Audios();
                    foreach ($ads2 as $a) {
                        $aid = (int)$a['attachable_id'];
                        try {
                            $aa = $audiosRepo->get($aid);
                            if ($aa && $aa->getName()) {
                                $owner = $aa->getOwner();
                                $ownerId = $owner ? $owner->getId() * ($owner instanceof \openvk\Web\Models\Entities\Club ? -1 : 1) : 0;
                                $attachments[] = [
                                    'type' => 'audio',
                                    'id' => $aid,
                                    'owner_id' => $ownerId,
                                    'url' => '/audio' . $aa->getPrettyId(),
                                    'title' => (string)$aa->getName(),
                                    'artist' => (string)$aa->getPerformer(),
                                    'duration' => $aa->getLength(),
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            } catch (\Throwable $e) {}
            // document attachments
            try {
                $docs = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'document')->fetchAll();
                if ($docs && count($docs) > 0) {
                    $documentsRepo = new Documents();
                    foreach ($docs as $d) {
                        $did = (int)$d['attachable_id'];
                        try {
                            $dd = $documentsRepo->get($did);
                            if ($dd && $dd->getName()) {
                                $url = $this->normalizeUrl((string)$dd->getURL());
                                $attachments[] = [
                                    'type' => 'document',
                                    'id' => $did,
                                    'title' => (string)$dd->getName(),
                                    'size' => $dd->getFileSize(),
                                    'url' => $url,
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
                // fallback for plural type
                $docs2 = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'docs')->fetchAll();
                if ($docs2 && count($docs2) > 0) {
                    $documentsRepo = isset($documentsRepo) ? $documentsRepo : new Documents();
                    foreach ($docs2 as $d) {
                        $did = (int)$d['attachable_id'];
                        try {
                            $dd = $documentsRepo->get($did);
                            if ($dd && $dd->getName()) {
                                $attachments[] = [
                                    'type' => 'document',
                                    'id' => $did,
                                    'title' => (string)$dd->getName(),
                                    'size' => $dd->getFileSize(),
                                    'url' => $dd->getURL(),
                                ];
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            } catch (\Throwable $e) {}

            // deleted flag
            $deleted = false;
            try { $del = $this->db->table('message_attachments')->where('message_id', (int)$r['id'])->where('attachable_type', 'deleted')->fetch(); if ($del) $deleted = true; } catch (\Throwable $e) {}

            $out[] = [
                'uuid'   => (int)$r['id'],
                'sender' => [
                    'id'     => $uid,
                    'link'   => $u ? ($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $u->getURL()) : '#',
                    'avatar' => $u ? $u->getAvatarUrl() : '/assets/packages/static/openvk/img/dialog_group.png',
                    'name'   => $u ? $u->getFirstName() : ('ID ' . $uid),
                ],
                'timing' => [
                    'sent'   => date('H:i', is_numeric($r['created']) ? (int)$r['created'] : strtotime((string)$r['created'])),
                    'edited' => null,
                ],
                'text'        => $deleted ? '' : (string)$r['content'],
                'read'        => true,
                'attachments' => $attachments,
                'reply'       => $reply,
                'deleted'     => $deleted,
            ];
        }
        exit(json_encode(['ok'=>true,'items'=>$out]));
    }

    public function renderApiConversationsSend(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                exit(json_encode(['ok'=>false,'error'=>'Method not allowed']));
            }
            $convId = (int)($this->postParam('conversation_id') ?? 0);
            $text   = (string)($this->postParam('text') ?? '');
            $attachments = $_POST['attachments'] ?? [];
            // replies: detect a pseudo-attachment {type:'reply', id:<msgId>}
            $replyToId = 0;
            if (is_array($attachments)) {
                $tmp = [];
                foreach ($attachments as $att) {
                    if (is_array($att) && ($att['type'] ?? '') === 'reply' && isset($att['id'])) {
                        $replyToId = (int)$att['id'];
                        continue; // do not persist as attachment
                    }
                    $tmp[] = $att;
                }
                $attachments = $tmp;
            }
            if (!$convId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id'])); }
            if (!$this->isMemberOfConv($convId, (int)$this->user->id)) { exit(json_encode(['ok'=>false,'error'=>'Not a member of conversation'])); }
            $now = time();
            // insert message including required columns per schema
            $data = [
                'conversation_id' => $convId,
                'sender_type'     => 'openvk\\Web\\Models\\Entities\\User',
                'sender_id'       => (int)$this->user->id,
                'recipient_type'  => 'openvk\\Web\\Models\\Entities\\User',
                'recipient_id'    => 0,
                'content'         => $text,
                'created'         => $now,
                'unread'          => 0,
            ];
            $msg = $this->db->table('messages')->insert($data);
            $messageId = (int)$msg['id'];
            // persist reply_to if needed (apply to either reply_to_id or reply_to)
            if ($replyToId > 0) {
                try { $this->db->table('messages')->where('id', $messageId)->update(['reply_to_id' => $replyToId]); }
                catch (\Throwable $e) { try { $this->db->table('messages')->where('id', $messageId)->update(['reply_to' => $replyToId]); } catch (\Throwable $e2) {} }
            }
            // also persist reply link in attachments for consistency with dialogs
            if ($replyToId > 0) {
                try {
                    $this->db->table('message_attachments')->insert([
                        'message_id' => $messageId,
                        'attachable_type' => 'reply',
                        'attachable_id' => $replyToId,
                    ]);
                } catch (\Throwable $e) {}
            }
            if (is_array($attachments) && count($attachments) > 0) {
                foreach ($attachments as $att) {
                    if (!is_array($att)) continue;
                    $type = $att['type'] ?? null;
                    $id   = isset($att['id']) ? (int)$att['id'] : null;
                    if (!$type || !$id) continue;
                    // normalize common aliases
                    if ($type === 'photos') $type = 'photo';
                    if ($type === 'videos') $type = 'video';
                    if ($type === 'audios') $type = 'audio';
                    if ($type === 'docs') $type = 'document';
                    $this->db->table('message_attachments')->insert([
                        'message_id' => $messageId,
                        'attachable_type' => $type,
                        'attachable_id' => $id,
                    ]);
                }
            }
            // reply summary for response
            $replySummary = null;
            if ($replyToId > 0) {
                $orig = $this->db->table('messages')->where('id', $replyToId)->fetch();
                if ($orig) {
                    $ou = (new \openvk\Web\Models\Repositories\Users())->get((int)$orig['sender_id']);
                    $replySummary = [
                        'uuid' => (int)$orig['id'],
                        'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                        'text' => (string)($orig['content'] ?? ''),
                    ];
                }
            }
            // echo back persisted attachments in simple form
            $attOut = [];
            if (is_array($attachments) && count($attachments) > 0) {
                foreach ($attachments as $att) {
                    if (!is_array($att)) continue;
                    $t = $att['type'] ?? null; $i = isset($att['id']) ? (int)$att['id'] : null;
                    if ($t && $i) { $attOut[] = ['type'=>$t,'id'=>$i]; }
                }
            }
            exit(json_encode(['ok'=>true,'message'=>[
                'id' => $messageId,
                'conversation_id' => $convId,
                'author_id' => (int)$this->user->id,
                'text' => $text,
                'created_at' => $now,
                'reply' => $replySummary,
                'attachments' => $attOut,
            ]]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Send failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiCreateConversation(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                exit(json_encode(['ok'=>false,'error'=>'Method not allowed']));
            }
            $title = (string)($this->postParam('title') ?? '');
            $memberIds = $_POST['members'] ?? [];
            if (!is_array($memberIds)) $memberIds = [];
            $creatorId = (int)$this->user->id;
            if (!in_array($creatorId, $memberIds, true)) array_unshift($memberIds, $creatorId);
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
            if (count($memberIds) > 50) {
                exit(json_encode(['ok'=>false,'error'=>'Members limit is 50']));
            }
            // ensure friends for direct add (except creator)
            foreach ($memberIds as $uid) {
                if ($uid !== $creatorId && !$this->areFriends($creatorId, (int)$uid)) {
                    exit(json_encode(['ok'=>false,'error'=>'Only friends can be added directly']));
                }
            }
            $now = date('Y-m-d H:i:s');
            // insert conversation
            $conv = $this->db->table('conversations')->insert([
                'title' => $title,
                'owner_id' => $creatorId,
                'created' => $now,
                'is_group' => 1,
            ]);
            $convId = (int)$conv['id'];
            // insert members
            foreach ($memberIds as $uid) {
                $this->db->table('conversation_members')->insert([
                    'conversation_id' => $convId,
                    'member_id' => (int)$uid,
                    'role' => ($uid === $creatorId ? 'owner' : 'member'),
                    'joined' => $now,
                ]);
            }
            exit(json_encode(['ok'=>true,'conversation'=>['id'=>$convId,'title'=>$title,'members'=>$memberIds,'created_at'=>$now]]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Create failed','message'=>$e->getMessage()]));
        }
    }

    public function renderApiAddMember(): void
    {
        header('Content-Type: application/json');
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                exit(json_encode(['ok'=>false,'error'=>'Method not allowed']));
            }
            $convId = (int)($this->postParam('conversation_id') ?? 0);
            $userId = (int)($this->postParam('user_id') ?? 0);
            if (!$convId || !$userId) { exit(json_encode(['ok'=>false,'error'=>'Missing conversation_id or user_id'])); }
            $myRole = $this->roleOfConv($convId, (int)$this->user->id);
            if (!in_array($myRole, ['owner','moderator'], true)) { exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }
            if ($this->memberCountConv($convId) >= 50) { exit(json_encode(['ok'=>false,'error'=>'Members limit reached'])); }
            if ($this->isBanned($convId, $userId)) { exit(json_encode(['ok'=>false,'error'=>'User is banned'])); }
            if (!$this->areFriends((int)$this->user->id, $userId)) { exit(json_encode(['ok'=>false,'error'=>'Only friends can be added directly'])); }
            $exists = $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', $userId)->fetch();
            if ($exists) { exit(json_encode(['ok'=>false,'error'=>'User already member'])); }
            $this->db->table('conversation_members')->insert([
                'conversation_id' => $convId,
                'member_id' => $userId,
                'role' => 'member',
                'joined' => date('Y-m-d H:i:s'),
            ]);
            exit(json_encode(['ok'=>true,'conversation_id'=>$convId,'user_id'=>$userId]));
        } catch (\Throwable $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode(['ok'=>false,'error'=>'Add member failed','message'=>$e->getMessage()]));
        }
    }

    private function areFriends(int $a, int $b): bool
    {
        if ($a === $b) return true;
        $model = 'openvk\\Web\\Models\\Entities\\User';
        $s1 = $this->db->table('subscriptions')->where('follower', $a)->where('target', $b)->where('model', $model)->fetch();
        if (!$s1) return false;
        $s2 = $this->db->table('subscriptions')->where('follower', $b)->where('target', $a)->where('model', $model)->fetch();
        return (bool)$s2;
    }

    private function isBanned(int $convId, int $userId): bool
    {
        try {
            $row = $this->db->table('conversation_bans')->where('conversation_id', $convId)->where('user_id', $userId)->fetch();
            return (bool)$row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }

        $page = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));

        // #КакаоПрокакалось

        $this->template->corresps = $correspondences;
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity),
            "page"    => (int) ($_GET["p"] ?? 1),
            "amount"  => sizeof($this->template->corresps),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
        ];

        // Provide groups list for Groups tab (include empty groups)
        $tab = isset($_GET['tab']) ? (string)$_GET['tab'] : '';
        if ($tab === 'groups') {
            $groups = [];
            // find all conversations where current user is a member and is_group=1
            $rows = $this->db->table('conversation_members')->where('member_id', (int)$this->user->id)->fetchAll();
            foreach ($rows as $m) {
                $conv = $this->db->table('conversations')->where('id', (int)$m['conversation_id'])->where('is_group', 1)->fetch();
                if ($conv) {
                    // last message
                    $last = $this->db->table('messages')->where('conversation_id', (int)$conv['id'])->order('id DESC')->limit(1)->fetch();
                    $lastText = '';
                    if ($last) {
                        $author = (new \openvk\Web\Models\Repositories\Users())->get((int)$last['sender_id']);
                        $authorName = $author ? $author->getFirstName() : ('ID ' . (int)$last['sender_id']);
                        $text = trim((string)($last['content'] ?? ''));
                        if ($text === '') $text = tr('no_messages');
                        $lastText = $authorName . ': ' . $text;
                    }
                    $avatarUrl = null;
                    if (!empty($conv['avatar_url'])) {
                        $avatarUrl = (string)$conv['avatar_url'];
                    } elseif (isset($conv['avatar_photo_id']) && (int)$conv['avatar_photo_id'] > 0) {
                        try { $p = (new \openvk\Web\Models\Repositories\Photos())->get((int)$conv['avatar_photo_id']); if ($p) { $avatarUrl = $p->getURL(); } } catch (\Throwable $e) {}
                    }
                    $groups[] = [
                        'id' => (int)$conv['id'],
                        'title' => (string)($conv['title'] ?? ''),
                        'avatar_photo_id' => isset($conv['avatar_photo_id']) ? (int)$conv['avatar_photo_id'] : null,
                        'avatar_url' => $avatarUrl,
                        'last' => $lastText,
                        'role' => (string)$this->roleOfConv((int)$conv['id'], (int)$this->user->id) ?: 'member',
                    ];
                }
            }
            $this->template->groups = $groups;
        }
    }

    public function renderApp(int $sel): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        if (!$this->user->identity->getPrivacyPermission('messages.write', $correspondent)) {
            $this->flash("err", tr("warning"), tr("user_may_not_reply"));
        }

        $this->template->disable_ajax  = 1;
        $this->template->selId         = $sel;
        $this->template->correspondent = $correspondent;
    }

    // Pretty route for groups: /grp{convId}
    public function renderGroup(int $convId): void
    {
        $this->assertUserLoggedIn();
        // Allow only conversation members to view
        $row = $this->db->table('conversation_members')->where('conversation_id', $convId)->where('member_id', (int)$this->user->id)->fetch();
        if (!$row) {
            $this->notFound();
        }
        $conv = $this->db->table('conversations')->where('id', $convId)->fetch();
        // Reuse App template; App.xml reads conv from URL (/grp<ID> or ?conv=)
        $this->template->_template     = "Messenger/App.xml";
        $this->template->disable_ajax  = 1;
        $this->template->selId         = 0;
        $this->template->correspondent = $this->user->identity;
        $this->template->groupTitle    = $conv ? (string)$conv['title'] : ("Group #" . $convId);
        $this->template->convId        = $convId;
        // group avatar url (if any)
        $avatarUrl = null;
        if ($conv && !empty($conv['avatar_url'])) {
            $avatarUrl = (string)$conv['avatar_url'];
        } elseif ($conv && isset($conv['avatar_photo_id']) && (int)$conv['avatar_photo_id'] > 0) {
            try {
                $p = (new \openvk\Web\Models\Repositories\Photos())->get((int)$conv['avatar_photo_id']);
                if ($p) $avatarUrl = $p->getURL();
            } catch (\Throwable $e) {}
        }
        $this->template->groupAvatarUrl = $avatarUrl;
        $this->template->groupIsOwner   = $conv && isset($conv['owner_id']) && (int)$conv['owner_id'] === (int)$this->user->id;
    }

    public function renderEvents(int $randNum): void
    {
        $this->assertUserLoggedIn();

        header("Content-Type: application/json");
        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id);
    }

    public function renderVKEvents(int $id): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json");

        if ($this->queryParam("act") !== "a_check") {
            header("HTTP/1.1 400 Bad Request");
            exit();
        } elseif (!$this->queryParam("key")) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $key       = $this->queryParam("key");
        $payload   = hex2bin(substr($key, 0, 16));
        $signature = hex2bin(substr($key, 16));
        if (($signature ^ (~CHANDLER_ROOT_CONF["security"]["secret"] | ((string) $id))) !== $payload) {
            exit(json_encode([
                "failed" => 3,
            ]));
        }

        $legacy = $this->queryParam("version") < 3;

        $time = intval($this->queryParam("wait"));

        if ($time > 60) {
            $time = 60;
        } elseif ($time == 0) {
            $time = 25;
        } // default

        $this->signaler->listen(function ($event, $eId) use ($id) {
            exit(json_encode([
                "ts"      => time(),
                "updates" => [
                    $event->getVKAPISummary($id),
                ],
            ]));
        }, $id, $time);
    }

    public function renderApiGetMessages(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        $messages       = [];
        $correspondence = new Correspondence($this->user->identity, $correspondent);
        foreach ($correspondence->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $messages[] = $message->simplify();
        }

        // enrich replies and forwards from message_attachments per message
        try {
            $usersRepo = new Users();
            foreach ($messages as &$m) {
                $mid = isset($m['uuid']) ? (int)$m['uuid'] : 0;
                if (!$mid) continue;
                // reply
                $att = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'reply')->fetch();
                if ($att && isset($att['attachable_id'])) {
                    $orig = $this->db->table('messages')->where('id', (int)$att['attachable_id'])->fetch();
                    if ($orig) {
                        $ou = $usersRepo->get((int)$orig['sender_id']);
                        $m['reply'] = [
                            'uuid' => (int)$orig['id'],
                            'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                            'text' => (string)($orig['content'] ?? ''),
                        ];
                    }
                }
                // deleted flag
                try { $del = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'deleted')->fetch(); if ($del) { $m['deleted'] = true; $m['text'] = ''; $m['attachments'] = []; } } catch (\Throwable $e) {}
                // forwards list
                try {
                    $fwds = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'forward')->fetchAll();
                    if ($fwds && count($fwds) > 0) {
                        if (!isset($m['attachments']) || !is_array($m['attachments'])) $m['attachments'] = [];
                        foreach ($fwds as $f) {
                            $orig = $this->db->table('messages')->where('id', (int)$f['attachable_id'])->fetch();
                            if (!$orig) continue;
                            $ou = $usersRepo->get((int)$orig['sender_id']);
                            $m['attachments'][] = [
                                'type' => 'forward',
                                'id'   => (int)$f['attachable_id'],
                                'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                                'text' => (string)($orig['content'] ?? ''),
                            ];
                        }
                    }
                } catch (\Throwable $e) {}
                // video attachments
                try {
                    $vds = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'video')->fetchAll();
                    if ($vds && count($vds) > 0) {
                        if (!isset($m['attachments']) || !is_array($m['attachments'])) $m['attachments'] = [];
                        $videosRepo = new Videos();
                        foreach ($vds as $v) {
                            $vid = (int)$v['attachable_id'];
                            try {
                                $vv = $videosRepo->get($vid);
                                if ($vv) {
                                    $m['attachments'][] = [
                                        'type'  => 'video',
                                        'id'    => $vid,
                                        'url'   => $vv->getPageURL(),
                                        'thumb' => $vv->getThumbnailURL(),
                                        'title' => (string)$vv->getName(),
                                    ];
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                } catch (\Throwable $e) {}
                // audio attachments
                try {
                    $ads = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'audio')->fetchAll();
                    if ($ads && count($ads) > 0) {
                        if (!isset($m['attachments']) || !is_array($m['attachments'])) $m['attachments'] = [];
                        $audiosRepo = new Audios();
                        foreach ($ads as $a) {
                            $aid = (int)$a['attachable_id'];
                            try {
                                $aa = $audiosRepo->get($aid);
                                if ($aa) {
                                    $owner = $aa->getOwner();
                                    $ownerId = $owner ? $owner->getId() * ($owner instanceof \openvk\Web\Models\Entities\Club ? -1 : 1) : 0;
                                    $m['attachments'][] = [
                                        'type' => 'audio',
                                        'id' => $aid,
                                        'owner_id' => $ownerId,
                                        'url' => '/audio' . $aa->getPrettyId(),
                                        'title' => (string)$aa->getName(),
                                        'artist' => (string)$aa->getPerformer(),
                                        'duration' => $aa->getLength(),
                                    ];
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                } catch (\Throwable $e) {}
                // document attachments
                try {
                    $docs = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'document')->fetchAll();
                    if ($docs && count($docs) > 0) {
                        if (!isset($m['attachments']) || !is_array($m['attachments'])) $m['attachments'] = [];
                        $documentsRepo = new Documents();
                        foreach ($docs as $d) {
                            $did = (int)$d['attachable_id'];
                            try {
                                $dd = $documentsRepo->get($did);
                                if ($dd) {
                                    $m['attachments'][] = [
                                        'type' => 'document',
                                        'id' => $did,
                                        'title' => (string)$dd->getName(),
                                        'size' => $dd->getFileSize(),
                                        'url' => $dd->getURL(),
                                    ];
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                } catch (\Throwable $e) {}

            }
            unset($m);
        } catch (\Throwable $e) {}

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    public function renderApiWriteMessage(int $sel): void
    {
        header("Content-Type: application/json");
        try {
            $this->assertUserLoggedIn();
            $this->willExecuteWriteAction();

            // parse attachments early to allow messages without text when there are attachments (e.g., forwards)
            $attachments = $_POST['attachments'] ?? null;
            $hasAttachments = false;
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (is_array($att)) { $hasAttachments = true; break; }
                }
            }
            if (empty($this->postParam("content")) && !$hasAttachments) {
                header("HTTP/1.1 400 Bad Request");
                exit(json_encode(['ok'=>false,'error'=>"Argument error: 'content' is required"]));
            }

            $selEntity = $this->getCorrespondent($sel);
            if ($selEntity->getId() !== $this->user->id && !$selEntity->getPrivacyPermission('messages.write', $this->user->identity)) {
                header("HTTP/1.1 403 Forbidden");
                exit(json_encode(['ok'=>false,'error'=>'Forbidden']));
            }

            $cor = new Correspondence($this->user->identity, $selEntity);
            $msg = new Message();
            $msg->setContent($this->postParam("content"));
            $cor->sendMessage($msg);

            // attachments (legacy messenger)
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (!is_array($att)) continue;
                    // handle both flat and nested shapes
                    $type = isset($att['type']) ? (string)$att['type'] : (isset($att[0]['type']) ? (string)$att[0]['type'] : null);
                    $id   = isset($att['id']) ? (int)$att['id']   : (isset($att[0]['id'])   ? (int)$att[0]['id']   : null);
                    if (!$type || !$id) continue;

        if ($type === 'photo') {
            $entity = (new Photos())->get($id);
        } elseif ($type === 'video') {
            $entity = (new Videos())->get($id);
        } elseif ($type === 'audio') {
            $entity = (new Audios())->get($id);
        } elseif ($type === 'document') {
            $entity = (new Documents())->get($id);
        } else {
            $entity = null;
        }
        if ($entity) {
            $msg->attach($entity);
        } elseif ($type === 'reply') {
                        // persist reply as attachment for dialogs
                        try {
                            $simpl = $msg->simplify();
                            if (isset($simpl['uuid'])) {
                                $this->db->table('message_attachments')->insert([
                                    'message_id' => (int)$simpl['uuid'],
                                    'attachable_type' => 'reply',
                                    'attachable_id' => $id,
                                ]);
                            }
                        } catch (\Throwable $e) {}
                    } elseif ($type === 'forward') {
                        // persist forward link to original message
                        try {
                            $simpl = $msg->simplify();
                            if (isset($simpl['uuid'])) {
                                $this->db->table('message_attachments')->insert([
                                    'message_id' => (int)$simpl['uuid'],
                                    'attachable_type' => 'forward',
                                    'attachable_id' => $id,
                                ]);
                            }
                        } catch (\Throwable $e) {}
                    }
                }
            }

            // include reply and forwarded blocks in response (if any)
            $out = $msg->simplify();
            try {
                $mid = isset($out['uuid']) ? (int)$out['uuid'] : 0;
                if ($mid) {
                    $att = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'reply')->fetch();
                    if ($att) {
                        $orig = $this->db->table('messages')->where('id', (int)$att['attachable_id'])->fetch();
                        if ($orig) {
                            $ou = (new Users())->get((int)$orig['sender_id']);
                            $out['reply'] = [
                                'uuid' => (int)$orig['id'],
                                'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                                'text' => (string)($orig['content'] ?? ''),
                            ];
                        }
                    }
                    // enrich forwarded attachments in response
                    try {
                        $fwds = $this->db->table('message_attachments')->where('message_id', $mid)->where('attachable_type', 'forward')->fetchAll();
                        if ($fwds && count($fwds) > 0) {
                            if (!isset($out['attachments']) || !is_array($out['attachments'])) $out['attachments'] = [];
                            foreach ($fwds as $f) {
                                $orig = $this->db->table('messages')->where('id', (int)$f['attachable_id'])->fetch();
                                if (!$orig) continue;
                                $ou = (new Users())->get((int)$orig['sender_id']);
                                $out['attachments'][] = [
                                    'type' => 'forward',
                                    'id'   => (int)$f['attachable_id'],
                                    'sender' => [ 'id' => (int)$orig['sender_id'], 'name' => $ou ? $ou->getFirstName() : ('ID ' . (int)$orig['sender_id']) ],
                                    'text' => (string)($orig['content'] ?? ''),
                                ];
                            }
                        }
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {}

            header("HTTP/1.1 202 Accepted");
            exit(json_encode($out));
        } catch (\Throwable $e) {
            header("HTTP/1.1 500 Internal Server Error");
            exit(json_encode(['ok'=>false,'error'=>'Send failed','message'=>$e->getMessage()]));
        }
    }
}
