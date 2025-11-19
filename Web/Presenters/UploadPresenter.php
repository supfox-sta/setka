<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Throwable;

final class UploadPresenter extends OpenVKPresenter
{
    public function renderPhotoAction(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
        }

        if (!isset($_FILES['file'])) {
            exit(json_encode(['ok' => false, 'error' => 'No file uploaded']));
        }

        $description = (string)($_POST['description'] ?? '');

        try {
            $photo = \openvk\Web\Models\Entities\Photo::fastMake($this->user->id, $description, $_FILES['file']);
        } catch (Throwable $ex) {
            exit(json_encode(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]));
        }

        $url = '';
        try {
            if (method_exists($photo, 'getURLBySizeId')) {
                $url = $photo->getURLBySizeId('normal');
            } elseif (method_exists($photo, 'getUrl')) {
                $url = $photo->getUrl();
            }
        } catch (Throwable $e) {
            $url = '';
        }

        exit(json_encode([
            'ok' => true,
            'type' => 'photo',
            'id' => $photo->getId(),
            'url' => $url,
        ]));
    }

    public function renderVideoAction(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
        }

        if (!isset($_FILES['file'])) {
            exit(json_encode(['ok' => false, 'error' => 'No file uploaded']));
        }

        $name = (string)($_POST['name'] ?? 'Uploaded video');
        $description = (string)($_POST['description'] ?? '');

        try {
            $video = \openvk\Web\Models\Entities\Video::fastMake($this->user->id, $name, $description, $_FILES['file']);
        } catch (Throwable $ex) {
            exit(json_encode(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]));
        }

        exit(json_encode([
            'ok' => true,
            'type' => 'video',
            'id' => $video->getId(),
        ]));
    }

    public function renderAudioAction(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
        }

        if (!isset($_FILES['file'])) {
            exit(json_encode(['ok' => false, 'error' => 'No file uploaded']));
        }

        $name = (string)($_POST['name'] ?? 'Uploaded audio');
        $description = (string)($_POST['description'] ?? '');

        try {
            // Временное решение без обработки ffmpeg
            $audio = new \openvk\Web\Models\Entities\Audio();
            $audio->setOwner($this->user->id);
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
        } catch (Throwable $ex) {
            exit(json_encode(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]));
        }

        exit(json_encode([
            'ok' => true,
            'type' => 'audio',
            'id' => $audio->getId(),
        ]));
    }

    public function renderDocumentAction(): void
    {
        $this->assertUserLoggedIn();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
        }

        if (!isset($_FILES['file'])) {
            exit(json_encode(['ok' => false, 'error' => 'No file uploaded']));
        }

        $name = (string)($_POST['name'] ?? 'Uploaded document');
        $description = (string)($_POST['description'] ?? '');

        try {
            $document = new \openvk\Web\Models\Entities\Document();
            $document->setOwner($this->user->id);
            $document->setName($name);
            $document->setFile(array_merge($_FILES['file'], ['preview_owner' => $this->user->id]));
            $document->save();
        } catch (Throwable $ex) {
            exit(json_encode(['ok' => false, 'error' => 'Upload failed', 'message' => $ex->getMessage()]));
        }

        exit(json_encode([
            'ok' => true,
            'type' => 'document',
            'id' => $document->getId(),
        ]));
    }
}
