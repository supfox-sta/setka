<?php
// app/controllers/GroupChat.php
// Пример простого «проксирующего» контроллера для использования готовых api/*.php
// Подкорректируй $MODULE_DIR под реальный путь к custom_groupchat в проекте.

class GroupChat
{
    protected $MODULE_DIR;

    public function __construct()
    {
        // Предполагаем, что контроллер находится в app/controllers/
        // и custom_groupchat лежит в корне проекта рядом с app/
        $this->MODULE_DIR = realpath(__DIR__ . '/../../custom_groupchat'); // поправь путь при необходимости
    }

    protected function includeApi($script)
    {
        $file = $this->MODULE_DIR . '/api/' . $script;
        if (!file_exists($file)) {
            header('Content-Type: application/json', true, 404);
            echo json_encode(['error' => 'api_not_found', 'file'=>$file]);
            return;
        }
        // Включаем файл — он сам вернёт JSON/заголовки
        require $file;
    }

    public function uploadAction()
    {
        // multipart upload -> /api/upload.php
        // Не трогаем $_FILES — upload.php ожидает их напрямую
        $this->includeApi('upload.php');
    }

    public function createAction()
    {
        // POST JSON -> groups.php?action=create
        $_GET['action'] = 'create';
        $this->includeApi('groups.php');
    }

    public function getAction()
    {
        // Принято, что роутер положит id в $_REQUEST или $_GET,
        // но часто framework пробрасывает {num} как GET-параметр.
        // Попробуем взять его из $_GET или из PATH_INFO.
        if (!isset($_GET['id'])) {
            // если ваше роутер кладёт {num} в REQUEST_URI, можно извлечь, но обычно он уже в $_GET
        }
        $_GET['action'] = 'get';
        $this->includeApi('groups.php');
    }

    public function sendAction()
    {
        // multipart/form-data -> messages.php?action=send
        $_GET['action'] = 'send';
        $this->includeApi('messages.php');
    }

    public function listAction()
    {
        // GET -> messages.php?action=list&group_id=...
        $_GET['action'] = 'list';
        $this->includeApi('messages.php');
    }
}
