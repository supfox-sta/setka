<?php
declare(strict_types=1);

namespace Web\Controllers;

use Exception;
use DateTime;

/**
 * ConversationsApiController
 *
 * Endpoints:
 *  POST /api/conversations/create  -> createAction
 *     - title (optional), members[] (array of user ids)
 *
 *  POST /api/conversations/add-member -> addMemberAction
 *     - conversation_id, user_id
 *
 *  POST /api/conversations/send -> sendAction
 *     - conversation_id, text (optional), attachments[] (array of arrays with keys type and id)
 *
 * Notes:
 *  - uses $this->db (PDO) for DB operations; adjust queries to your schema if necessary.
 *  - Adjust table names if your project uses other names.
 */
class ConversationsApiController extends BaseController
{
    protected $pdo;

    public function __construct()
    {
        parent::__construct();
        // ожидаем, что $this->db хранит PDO (или замените на свой объект)
        $this->pdo = $this->db;
    }

    public function generateInviteAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }
        $convId = (int)$this->request->getPost('conversation_id', 0);
        if (!$convId) { $this->sendJson(['ok'=>false,'error'=>'Missing conversation_id']); return; }
        // only owner
        if (!$this->isOwner($convId, (int)$me->getId())) { $this->sendJson(['ok'=>false,'error'=>'Forbidden']); return; }
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare("UPDATE conversations SET invite_token = :tok WHERE id = :id");
        $stmt->execute([':tok'=>$token, ':id'=>$convId]);
        $this->sendJson(['ok'=>true,'invite_token'=>$token]);
    }

    public function revokeInviteAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }
        $convId = (int)$this->request->getPost('conversation_id', 0);
        if (!$convId) { $this->sendJson(['ok'=>false,'error'=>'Missing conversation_id']); return; }
        if (!$this->isOwner($convId, (int)$me->getId())) { $this->sendJson(['ok'=>false,'error'=>'Forbidden']); return; }
        $stmt = $this->pdo->prepare("UPDATE conversations SET invite_token = NULL WHERE id = :id");
        $stmt->execute([':id'=>$convId]);
        $this->sendJson(['ok'=>true]);
    }

    public function joinByInviteAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }
        $token = (string)$this->request->getPost('token', '');
        if ($token === '') { $this->sendJson(['ok'=>false,'error'=>'Missing token']); return; }
        $stmt = $this->pdo->prepare("SELECT id FROM conversations WHERE invite_token = :tok");
        $stmt->execute([':tok'=>$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { $this->sendJson(['ok'=>false,'error'=>'Invalid link']); return; }
        $convId = (int)$row['id'];
        // cap 50
        if ($this->memberCount($convId) >= 50) { $this->sendJson(['ok'=>false,'error'=>'Members limit reached']); return; }
        // join if not member
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :c AND member_id = :u");
        $stmt->execute([':c'=>$convId, ':u'=>$me->getId()]);
        if (!$stmt->fetch()) {
            $ins = $this->pdo->prepare("INSERT INTO conversation_members (conversation_id, member_id, role, joined) VALUES (:c,:u,'member',:j)");
            $ins->execute([':c'=>$convId, ':u'=>$me->getId(), ':j'=>$now]);
        }
        $this->sendJson(['ok'=>true,'conversation_id'=>$convId]);
    }

    public function membersAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        $convId = (int)($this->request->getQuery('conversation_id', 0));
        if (!$convId) { $this->sendJson(['ok'=>false,'error'=>'Missing conversation_id']); return; }
        // must be member to view
        if (!$this->isMember($convId, (int)$me->getId())) { $this->sendJson(['ok'=>false,'error'=>'Forbidden']); return; }
        $stmt = $this->pdo->prepare("SELECT member_id AS user_id, role, joined AS joined_at FROM conversation_members WHERE conversation_id = :c ORDER BY role DESC, joined ASC");
        $stmt->execute([':c'=>$convId]);
        $this->sendJson(['ok'=>true,'members'=>$stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    public function removeMemberAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); $this->sendJson(['ok'=>false,'error'=>'Method not allowed']); return; }
        $convId = (int)$this->request->getPost('conversation_id', 0);
        $userId = (int)$this->request->getPost('user_id', 0);
        if (!$convId || !$userId) { $this->sendJson(['ok'=>false,'error'=>'Missing conversation_id or user_id']); return; }
        $myRole = $this->roleOf($convId, (int)$me->getId());
        $targetRole = $this->roleOf($convId, $userId);
        if (!$myRole) { $this->sendJson(['ok'=>false,'error'=>'Forbidden']); return; }
        // owner can remove anyone; moderator can remove only members
        if ($myRole === 'owner' || ($myRole === 'moderator' && $targetRole === 'member')) {
            $stmt = $this->pdo->prepare("DELETE FROM conversation_members WHERE conversation_id = :c AND member_id = :u");
            $stmt->execute([':c'=>$convId, ':u'=>$userId]);
            $this->sendJson(['ok'=>true]);
        } else {
            $this->sendJson(['ok'=>false,'error'=>'Forbidden']);
        }
    }

    public function setRoleAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); $this->sendJson(['ok'=>false,'error'=>'Method not allowed']); return; }
        $convId = (int)$this->request->getPost('conversation_id', 0);
        $userId = (int)$this->request->getPost('user_id', 0);
        $role   = (string)$this->request->getPost('role', '');
        if (!$convId || !$userId || !in_array($role, ['moderator','member'], true)) { $this->sendJson(['ok'=>false,'error'=>'Bad params']); return; }
        if (!$this->isOwner($convId, (int)$me->getId())) { $this->sendJson(['ok'=>false,'error'=>'Forbidden']); return; }
        // cannot change owner here
        $stmt = $this->pdo->prepare("UPDATE conversation_members SET role = :r WHERE conversation_id = :c AND member_id = :u AND role <> 'owner'");
        $stmt->execute([':r'=>$role, ':c'=>$convId, ':u'=>$userId]);
        $this->sendJson(['ok'=>true]);
    }

    private function isOwner(int $convId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM conversations WHERE id = :c AND owner_id = :u");
        $stmt->execute([':c'=>$convId, ':u'=>$userId]);
        return (bool)$stmt->fetch();
    }

    private function isMember(int $convId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :c AND member_id = :u");
        $stmt->execute([':c'=>$convId, ':u'=>$userId]);
        return (bool)$stmt->fetch();
    }

    private function roleOf(int $convId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT role FROM conversation_members WHERE conversation_id = :c AND member_id = :u");
        $stmt->execute([':c'=>$convId, ':u'=>$userId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ? (string)$r['role'] : null;
    }

    private function memberCount(int $convId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM conversation_members WHERE conversation_id = :c");
        $stmt->execute([':c'=>$convId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ? (int)$r['cnt'] : 0;
    }

    private function isFriends(int $a, int $b): bool
    {
        if ($a === $b) return true;
        $model = "openvk\\\\Web\\\\Models\\\\Entities\\\\User";
        $q = 'SELECT 1 FROM subscriptions WHERE follower = :a AND target = :b AND model = :m LIMIT 1';
        $s1 = $this->pdo->prepare($q);
        $s1->execute([':a'=>$a, ':b'=>$b, ':m'=>$model]);
        if (!$s1->fetch()) return false;
        $s2 = $this->pdo->prepare($q);
        $s2->execute([':a'=>$b, ':b'=>$a, ':m'=>$model]);
        return (bool)$s2->fetch();
    }

    public function createAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $title = (string)$this->request->getPost('title', '');
        $memberIds = $this->request->getPost('members', []);
        if (!is_array($memberIds)) $memberIds = [];

        // Ensure creator is in members
        $creatorId = (int)$me->getId();
        if (!in_array($creatorId, $memberIds, true)) {
            array_unshift($memberIds, $creatorId);
        }

        try {
            $this->pdo->beginTransaction();

            $now = (new DateTime())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("INSERT INTO conversations (title, owner_id, created) VALUES (:title, :owner, :created)");
            $stmt->execute([':title' => $title, ':owner' => $creatorId, ':created' => $now]);
            $conversationId = (int)$this->pdo->lastInsertId();

            // cap 50: include creator in count
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
            if (count($memberIds) > 50) {
                throw new Exception('Members limit is 50');
            }

            $stmtMember = $this->pdo->prepare("INSERT INTO conversation_members (conversation_id, member_id, role, joined) VALUES (:conv, :user, :role, :joined)");
            foreach ($memberIds as $uid) {
                $uid = (int)$uid;
                if ($uid !== $creatorId) {
                    // only friends can be added directly at creation
                    if (!$this->isFriends($creatorId, $uid)) {
                        throw new Exception('Only friends can be added directly');
                    }
                }
                $role = ($uid === $creatorId) ? 'owner' : 'member';
                $stmtMember->execute([':conv' => $conversationId, ':user' => $uid, ':role' => $role, ':joined' => $now]);
            }

            $this->pdo->commit();

            $this->sendJson([
                'ok' => true,
                'conversation' => [
                    'id' => $conversationId,
                    'title' => $title,
                    'members' => $memberIds,
                    'created_at' => $now,
                ],
            ]);
            return;
        } catch (Exception $ex) {
            $this->pdo->rollBack();
            $this->sendJson(['ok' => false, 'error' => 'Create failed', 'message' => $ex->getMessage()]);
            return;
        }
    }

    public function addMemberAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $convId = (int)$this->request->getPost('conversation_id', 0);
        $userId = (int)$this->request->getPost('user_id', 0);
        if (!$convId || !$userId) {
            $this->sendJson(['ok' => false, 'error' => 'Missing conversation_id or user_id']);
            return;
        }

        try {
            // only owner/moderator can add
            $myRole = $this->roleOf($convId, (int)$me->getId());
            if (!in_array($myRole, ['owner','moderator'], true)) {
                $this->sendJson(['ok'=>false,'error'=>'Forbidden']);
                return;
            }
            // only friends can be added directly
            if (!$this->isFriends((int)$me->getId(), $userId)) {
                $this->sendJson(['ok'=>false,'error'=>'Only friends can be added directly']);
                return;
            }
            // cap 50
            if ($this->memberCount($convId) >= 50) {
                $this->sendJson(['ok'=>false,'error'=>'Members limit reached']);
                return;
            }
            $now = (new DateTime())->format('Y-m-d H:i:s');
            // check exists
            $stmt = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :conv AND user_id = :user");
            $stmt->execute([':conv' => $convId, ':user' => $userId]);
            if ($stmt->fetch()) {
                $this->sendJson(['ok' => false, 'error' => 'User already member']);
                return;
            }

            $stmtIns = $this->pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (:conv, :user, :role, :joined)");
            $stmtIns->execute([':conv' => $convId, ':user' => $userId, ':role' => 'member', ':joined' => $now]);

            $this->sendJson(['ok' => true, 'conversation_id' => $convId, 'user_id' => $userId]);
            return;
        } catch (Exception $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Add member failed', 'message' => $ex->getMessage()]);
            return;
        }
    }

    public function sendAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $convId = (int)$this->request->getPost('conversation_id', 0);
        $text = (string)$this->request->getPost('text', '');
        $attachments = $this->request->getPost('attachments', []); // expects array of arrays with keys type,id

        if (!$convId) {
            $this->sendJson(['ok' => false, 'error' => 'Missing conversation_id']);
            return;
        }

        try {
            // verify user is member (optional)
            $stmtCheck = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :conv AND member_id = :user");
            $stmtCheck->execute([':conv' => $convId, ':user' => $me->getId()]);
            if (!$stmtCheck->fetch()) {
                $this->sendJson(['ok' => false, 'error' => 'Not a member of conversation']);
                return;
            }

            $now = (new DateTime())->format('Y-m-d H:i:s');

            $this->pdo->beginTransaction();

            $stmtIns = $this->pdo->prepare("INSERT INTO messages (conversation_id, author_id, text, created_at) VALUES (:conv, :author, :text, :created)");
            $stmtIns->execute([':conv' => $convId, ':author' => $me->getId(), ':text' => $text, ':created' => $now]);
            $messageId = (int)$this->pdo->lastInsertId();

            // attachments: array of arrays ['type'=>..., 'id'=>...]
            if (is_array($attachments) && count($attachments) > 0) {
                $stmtAtt = $this->pdo->prepare("INSERT INTO message_attachments (message_id, attachable_type, attachable_id) VALUES (:msg, :type, :aid)");
                foreach ($attachments as $att) {
                    // in case attachments come as associative arrays from PHP, ensure fields exist
                    if (!is_array($att)) continue;
                    $type = isset($att['type']) ? (string)$att['type'] : (isset($att['0']['type']) ? (string)$att[0]['type'] : '');
                    $id = isset($att['id']) ? (int)$att['id'] : (isset($att['0']['id']) ? (int)$att[0]['id'] : 0);
                    if (!$type || !$id) {
                        // try alternative structure: attachments[0][type], attachments[0][id] => PHP will parse it as nested arrays normally
                        if (isset($att['type']) && isset($att['id'])) {
                            $type = (string)$att['type'];
                            $id = (int)$att['id'];
                        } else {
                            continue;
                        }
                    }
                    $stmtAtt->execute([':msg' => $messageId, ':type' => $type, ':aid' => $id]);
                }
            }

            $this->pdo->commit();

            // TODO: notify members via WS/push if you have signaling in the project.
            // Example: $this->realtime->publishConversationMessage($convId, [...]);

            // Build reply summary if present (attachable_type='reply')
            $replySummary = null;
            try {
                $stmtR = $this->pdo->prepare("SELECT attachable_id FROM message_attachments WHERE message_id = :mid AND attachable_type = 'reply' LIMIT 1");
                $stmtR->execute([':mid' => $messageId]);
                $rowR = $stmtR->fetch(\PDO::FETCH_ASSOC);
                if ($rowR && isset($rowR['attachable_id'])) {
                    $origId = (int)$rowR['attachable_id'];
                    $stmtOrig = $this->pdo->prepare("SELECT id, author_id, text FROM messages WHERE id = :oid");
                    $stmtOrig->execute([':oid' => $origId]);
                    $orig = $stmtOrig->fetch(\PDO::FETCH_ASSOC);
                    if ($orig) {
                        $replySummary = [
                            'uuid' => (int)$orig['id'],
                            'sender' => [ 'id' => (int)$orig['author_id'], 'name' => $me->getFirstName() ],
                            'text' => (string)$orig['text'],
                        ];
                    }
                }
            } catch (\Throwable $e) {}

            $this->sendJson([
                'ok' => true,
                'message' => [
                    'id' => $messageId,
                    'conversation_id' => $convId,
                    'author_id' => $me->getId(),
                    'text' => $text,
                    'created_at' => $now,
                    'reply' => $replySummary,
                ],
            ]);
            return;
        } catch (Exception $ex) {
            $this->pdo->rollBack();
            $this->sendJson(['ok' => false, 'error' => 'Send failed', 'message' => $ex->getMessage()]);
            return;
        }
    }
}
<?php
declare(strict_types=1);

namespace Web\Controllers;

use Exception;
use DateTime;

/**
 * ConversationsApiController
 *
 * Endpoints:
 *  POST /api/conversations/create  -> createAction
 *     - title (optional), members[] (array of user ids)
 *
 *  POST /api/conversations/add-member -> addMemberAction
 *     - conversation_id, user_id
 *
 *  POST /api/conversations/send -> sendAction
 *     - conversation_id, text (optional), attachments[] (array of arrays with keys type and id)
 *
 * Notes:
 *  - uses $this->db (PDO) for DB operations; adjust queries to your schema if necessary.
 *  - Adjust table names if your project uses other names.
 */
class ConversationsApiController extends BaseController
{
    protected $pdo;

    public function __construct()
    {
        parent::__construct();
        // ожидаем, что $this->db хранит PDO (или замените на свой объект)
        $this->pdo = $this->db;
    }

    public function createAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $title = (string)$this->request->getPost('title', '');
        $memberIds = $this->request->getPost('members', []);
        if (!is_array($memberIds)) $memberIds = [];

        // Ensure creator is in members
        $creatorId = (int)$me->getId();
        if (!in_array($creatorId, $memberIds, true)) {
            array_unshift($memberIds, $creatorId);
        }

        try {
            $this->pdo->beginTransaction();

            $now = (new DateTime())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("INSERT INTO conversations (title, owner_id, created_at) VALUES (:title, :owner, :created)");
            $stmt->execute([':title' => $title, ':owner' => $creatorId, ':created' => $now]);
            $conversationId = (int)$this->pdo->lastInsertId();

            $stmtMember = $this->pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (:conv, :user, :role, :joined)");
            foreach ($memberIds as $uid) {
                $uid = (int)$uid;
                $role = ($uid === $creatorId) ? 'owner' : 'member';
                $stmtMember->execute([':conv' => $conversationId, ':user' => $uid, ':role' => $role, ':joined' => $now]);
            }

            $this->pdo->commit();

            $this->sendJson([
                'ok' => true,
                'conversation' => [
                    'id' => $conversationId,
                    'title' => $title,
                    'members' => $memberIds,
                    'created_at' => $now,
                ],
            ]);
            return;
        } catch (Exception $ex) {
            $this->pdo->rollBack();
            $this->sendJson(['ok' => false, 'error' => 'Create failed', 'message' => $ex->getMessage()]);
            return;
        }
    }

    public function addMemberAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $convId = (int)$this->request->getPost('conversation_id', 0);
        $userId = (int)$this->request->getPost('user_id', 0);
        if (!$convId || !$userId) {
            $this->sendJson(['ok' => false, 'error' => 'Missing conversation_id or user_id']);
            return;
        }

        try {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            // check exists
            $stmt = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :conv AND user_id = :user");
            $stmt->execute([':conv' => $convId, ':user' => $userId]);
            if ($stmt->fetch()) {
                $this->sendJson(['ok' => false, 'error' => 'User already member']);
                return;
            }

            $stmtIns = $this->pdo->prepare("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (:conv, :user, :role, :joined)");
            $stmtIns->execute([':conv' => $convId, ':user' => $userId, ':role' => 'member', ':joined' => $now]);

            $this->sendJson(['ok' => true, 'conversation_id' => $convId, 'user_id' => $userId]);
            return;
        } catch (Exception $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Add member failed', 'message' => $ex->getMessage()]);
            return;
        }
    }

    public function sendAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        $convId = (int)$this->request->getPost('conversation_id', 0);
        $text = (string)$this->request->getPost('text', '');
        $attachments = $this->request->getPost('attachments', []); // expects array of arrays with keys type,id

        if (!$convId) {
            $this->sendJson(['ok' => false, 'error' => 'Missing conversation_id']);
            return;
        }

        try {
            // verify user is member (optional)
            $stmtCheck = $this->pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = :conv AND user_id = :user");
            $stmtCheck->execute([':conv' => $convId, ':user' => $me->getId()]);
            if (!$stmtCheck->fetch()) {
                $this->sendJson(['ok' => false, 'error' => 'Not a member of conversation']);
                return;
            }

            $now = (new DateTime())->format('Y-m-d H:i:s');

            $this->pdo->beginTransaction();

            $stmtIns = $this->pdo->prepare("INSERT INTO messages (conversation_id, author_id, text, created_at) VALUES (:conv, :author, :text, :created)");
            $stmtIns->execute([':conv' => $convId, ':author' => $me->getId(), ':text' => $text, ':created' => $now]);
            $messageId = (int)$this->pdo->lastInsertId();

            // attachments: array of arrays ['type'=>..., 'id'=>...]
            if (is_array($attachments) && count($attachments) > 0) {
                $stmtAtt = $this->pdo->prepare("INSERT INTO message_attachments (message_id, attachable_type, attachable_id) VALUES (:msg, :type, :aid)");
                foreach ($attachments as $att) {
                    // in case attachments come as associative arrays from PHP, ensure fields exist
                    if (!is_array($att)) continue;
                    $type = isset($att['type']) ? (string)$att['type'] : (isset($att['0']['type']) ? (string)$att[0]['type'] : '');
                    $id = isset($att['id']) ? (int)$att['id'] : (isset($att['0']['id']) ? (int)$att[0]['id'] : 0);
                    if (!$type || !$id) {
                        // try alternative structure: attachments[0][type], attachments[0][id] => PHP will parse it as nested arrays normally
                        if (isset($att['type']) && isset($att['id'])) {
                            $type = (string)$att['type'];
                            $id = (int)$att['id'];
                        } else {
                            continue;
                        }
                    }
                    $stmtAtt->execute([':msg' => $messageId, ':type' => $type, ':aid' => $id]);
                }
            }

            $this->pdo->commit();

            // TODO: notify members via WS/push if you have signaling in the project.
            // Example: $this->realtime->publishConversationMessage($convId, [...]);

            $this->sendJson([
                'ok' => true,
                'message' => [
                    'id' => $messageId,
                    'conversation_id' => $convId,
                    'author_id' => $me->getId(),
                    'text' => $text,
                    'created_at' => $now,
                ],
            ]);
            return;
        } catch (Exception $ex) {
            $this->pdo->rollBack();
            $this->sendJson(['ok' => false, 'error' => 'Send failed', 'message' => $ex->getMessage()]);
            return;
        }
    }
}
