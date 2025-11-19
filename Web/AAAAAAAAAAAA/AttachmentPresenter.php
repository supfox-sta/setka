<?php
declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\Users;
use Nette\Utils\Random;

/**
 * Presenter для загрузки и отдачи вложений сообщений.
 * Сохраняет файлы в public/uploads/messages/YYYY/MM/DD.
 *
 * Примечание: для определения публичного WWW-пути использует константу OPENVK_WWW_DIR,
 * если её нет — пытается вычислить относительный путь к public/.
 */
final class AttachmentPresenter extends OpenVKPresenter
{
    private $db;

    public function __construct(\Chandler\Database\DatabaseConnection $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    // POST /attachment/apiUpload?dialog_id=...
    public function renderApiUpload(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $files = $_FILES['file'] ?? null;
        if (!$files || !isset($files['tmp_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'No file']);
            exit;
        }

        $size = (int)$files['size'];
        $maxSize = 20 * 1024 * 1024; // 20 MB
        $allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','application/pdf'];

        $mime = mime_content_type($files['tmp_name']) ?: ($files['type'] ?? 'application/octet-stream');
        if ($size > $maxSize) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'File too large']);
            exit;
        }
        if (!in_array($mime, $allowed, true)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unsupported file type']);
            exit;
        }

        // Определяем www-директорию
        $wwwDir = defined('OPENVK_WWW_DIR') ? OPENVK_WWW_DIR : (dirname(__DIR__, 4) . '/public');
        $subdir = date('Y/m/d');
        $uploadDir = rtrim($wwwDir, '/') . '/uploads/messages/' . $subdir;
        @mkdir($uploadDir, 0755, true);

        $origName = $files['name'];
        $safeName = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
        $dest = $uploadDir . '/' . $safeName;

        if (!move_uploaded_file($files['tmp_name'], $dest)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            exit;
        }

        // Вставляем запись в attachments (используйте ваш слой репозиториев/DB)
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare('INSERT INTO attachments (owner_id, filename, mime, size, path, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $ownerId = $this->user->identity->getId();
        $relPath = 'uploads/messages/' . $subdir . '/' . $safeName;
        $stmt->execute([$ownerId, $origName, $mime, $size, $relPath]);
        $attachmentId = (int)$conn->lastInsertId();

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'attachment' => [
                'id' => $attachmentId,
                'url' => '/attachment/serve/' . $attachmentId,
                'filename' => $origName,
                'mime' => $mime,
                'size' => $size,
            ]
        ]);
        exit;
    }

    // GET /attachment/serve/{id}
    public function renderServe(int $id): void
    {
        $row = $this->db->getConnection()->query('SELECT * FROM attachments WHERE id = ' . (int)$id)->fetch();
        if (!$row) {
            $this->notFound();
        }

        $wwwDir = defined('OPENVK_WWW_DIR') ? OPENVK_WWW_DIR : (dirname(__DIR__, 4) . '/public');
        $filePath = rtrim($wwwDir, '/') . '/' . $row['path'];
        if (!is_file($filePath)) {
            $this->notFound();
        }

        // Простейшая проверка доступа: владелец или участник разговора/группы.
        $uid = $this->user->identity->getId();
        if ($uid !== (int)$row['owner_id']) {
            if (!empty($row['message_id'])) {
                // Здесь нужно проверить, что пользователь является участником переписки;
                //используйте существующие репозитории Messages/Correspondence, например:
                // $correspondence = new \openvk\Web\Models\Entities\Correspondence(...);
                // if (! $correspondence->userIsParticipant($uid)) $this->forbidden();
                // Пока — допуск только владельца:
                $this->forbidden();
            } else {
                $this->forbidden();
            }
        }

        header('Content-Type: ' . $row['mime']);
        header('Content-Disposition: inline; filename="' . basename($row['filename']) . '"');
        readfile($filePath);
        exit;
    }
}