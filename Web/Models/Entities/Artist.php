<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;

class Artist
{
    private $record;

    public function __construct($row)
    {
        $this->record = $row;
    }

    public function getId(): int { return (int) $this->record->id; }
    public function getName(): string { return (string) $this->record->name; }
    public function getBio(): ?string { return $this->record->bio ?? null; }
    public function getAvatarPhotoId(): ?int { return isset($this->record->avatar_photo_id) ? (int) $this->record->avatar_photo_id : null; }
    public function getCreated(): int { return (int) $this->record->created; }
    public function getEdited(): int { return (int) $this->record->edited; }
}
