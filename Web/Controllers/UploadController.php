<?php
declare(strict_types=1);

namespace Web\Controllers;

use Exception;

/**
 * UploadController
 *
 * Endpoints:
 *  POST /api/upload/photo  -> photoAction
 *     - multipart/form-data: file (required), description (optional)
 *
 *  POST /api/upload/video  -> videoAction
 *     - multipart/form-data: file (required), name (required), description (optional)
 *
 *  POST /api/upload/audio  -> audioAction
 *     - multipart/form-data: file (required), name (required), description (optional)
 *
 *  POST /api/upload/document  -> documentAction
 *     - multipart/form-data: file (required), name (required), description (optional)
 *
 * Requires:
 *  - $this->getAuthenticator()->getUser() returning current user object with getId()
 *  - Photo::fastMake($ownerId, $description, $_FILES['file'])
 *  - Video::fastMake($ownerId, $name, $description, $_FILES['file'])
 *  - $this->sendJson(array) helper
 */
class UploadController extends BaseController
{
    public function photoAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        if (!$me) {
            http_response_code(401);
            $this->sendJson(['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->sendJson(['ok' => false, 'error' => 'No file uploaded']);
            return;
        }

        $description = (string)$this->request->getPost('description', '');

        try {
            // Используем существующий фабричный метод в вашем движке
            $photo = \openvk\Web\Models\Entities\Photo::fastMake($me->getId(), $description, $_FILES['file']);
        } catch (\Throwable $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]);
            return;
        }

        $url = '';
        try {
            // попытка получить url предпросмотра (если есть)
            if (method_exists($photo, 'getURLBySizeId')) {
                $url = $photo->getURLBySizeId('normal');
            } elseif (method_exists($photo, 'getUrl')) {
                $url = $photo->getUrl();
            }
        } catch (\Throwable $e) {
            $url = '';
        }

        $this->sendJson([
            'ok' => true,
            'type' => 'photo',
            'id' => $photo->getId(),
            'url' => $url,
        ]);
    }

    public function videoAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        if (!$me) {
            http_response_code(401);
            $this->sendJson(['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->sendJson(['ok' => false, 'error' => 'No file uploaded']);
            return;
        }

        $name = (string)$this->request->getPost('name', 'Uploaded video');
        $description = (string)$this->request->getPost('description', '');

        try {
            $video = \openvk\Web\Models\Entities\Video::fastMake($me->getId(), $name, $description, $_FILES['file']);
        } catch (Exception $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]);
            return;
        }

        $this->sendJson([
            'ok' => true,
            'type' => 'video',
            'id' => $video->getId(),
        ]);
    }

    public function audioAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        if (!$me) {
            http_response_code(401);
            $this->sendJson(['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->sendJson(['ok' => false, 'error' => 'No file uploaded']);
            return;
        }

        $name = (string)$this->request->getPost('name', 'Uploaded audio');
        $description = (string)$this->request->getPost('description', '');

        try {
            // Временное решение без обработки ffmpeg
            $audio = new \openvk\Web\Models\Entities\Audio();
            $audio->setOwner($me->getId());
            $audio->setName($name);
            $audio->setPerformer('Unknown Artist');

            // Простое копирование файла без обработки
            $hash = hash_file("whirlpool", $_FILES['file']['tmp_name']);
            $audio->stateChanges("hash", $hash);
            $audio->stateChanges("length", 180); // Заглушка 3 минуты
            $audio->stateChanges("processed", 1); // Отмечаем как обработанный

            // Копируем файл в storage
            $dir = $audio->getBaseDir() . substr($hash, 0, 2);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $targetPath = "$dir/$hash.mp3";
            move_uploaded_file($_FILES['file']['tmp_name'], $targetPath);

            $audio->save();
        } catch (Exception $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]);
            return;
        }

        $this->sendJson([
            'ok' => true,
            'type' => 'audio',
            'id' => $audio->getId(),
        ]);
    }

    public function documentAction()
    {
        $auth = $this->getAuthenticator();
        $me = $auth->getUser();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->sendJson(['ok' => false, 'error' => 'Method not allowed']);
            return;
        }

        if (!$me) {
            http_response_code(401);
            $this->sendJson(['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->sendJson(['ok' => false, 'error' => 'No file uploaded']);
            return;
        }

        $name = (string)$this->request->getPost('name', 'Uploaded document');
        $description = (string)$this->request->getPost('description', '');

        try {
            $document = new \openvk\Web\Models\Entities\Document();
            $document->setOwner($me->getId());
            $document->setName($name);
            $document->setFile(array_merge($_FILES['file'], ['preview_owner' => $me->getId()]));
            $document->save();
        } catch (Exception $ex) {
            $this->sendJson(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]);
            return;
        }

        $this->sendJson([
            'ok' => true,
            'type' => 'document',
            'id' => $document->getId(),
        ]);
    }
}
