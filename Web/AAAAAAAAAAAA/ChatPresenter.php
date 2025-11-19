<?php
declare(strict_types=1);

namespace openvk\Web\Presenters;

/**
 * Presenter для создания групповых чатов (до 50 участников), добавление по username.
 * POST /chat/create  payload: title, members[] (usernames)
 */
final class ChatPresenter extends OpenVKPresenter
{
    private $db;

    public function __construct(\Chandler\Database\DatabaseConnection $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    public function renderCreate(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $title = trim($this->postParam('title') ?? '');
        $members = $this->postParam('members') ?? [];

        if ($title === '') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Title required']);
            exit;
        }
        if (!is_array($members)) $members = [];

        $conn = $this->db->getConnection();
        $resolved = [];

        foreach ($members as $uname) {
            $uname = trim((string)$uname);
            if ($uname === '') continue;
            $q = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $q->execute([$uname]);
            $r = $q->fetch();
            if ($r) $resolved[] = (int)$r['id'];
        }

        $creator = $this->user->identity->getId();
        if (!in_array($creator, $resolved, true)) array_unshift($resolved, $creator);

        if (count($resolved) > 50) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Group cannot have more than 50 members']);
            exit;
        }

        $stmt = $conn->prepare('INSERT INTO chat_groups (title, creator_id, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$title, $creator]);
        $groupId = (int)$conn->lastInsertId();

        $ins = $conn->prepare('INSERT INTO chat_group_members (group_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())');
        foreach ($resolved as $uid) {
            $role = ($uid === $creator) ? 'admin' : 'member';
            $ins->execute([$groupId, $uid, $role]);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'group' => ['id' => $groupId, 'title' => $title]]);
        exit;
    }
}