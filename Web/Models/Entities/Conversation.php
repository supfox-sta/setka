<?php
declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use openvk\Web\Models\RowModel;

/**
 * Conversation (group chat) entity.
 */
class Conversation extends RowModel
{
    protected $tableName = "conversations";

    public function getId()
    {
        return $this->getRecord()->id;
    }

    public function getTitle()
    {
        return $this->getRecord()->title;
    }

    public function getOwnerId()
    {
        return $this->getRecord()->owner_id;
    }

    public function toArray()
    {
        return [
            'id' => (int)$this->getId(),
            'title' => $this->getTitle(),
            'owner_id' => (int)$this->getOwnerId(),
        ];
    }
}
