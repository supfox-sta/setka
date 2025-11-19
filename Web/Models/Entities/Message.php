<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\Clubs;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Entities\{Photo, Video, Audio, Document};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * Message entity.
 */
class Message extends RowModel
{
    use Traits\TRichText;
    use Traits\TAttachmentHost;
    protected $tableName = "messages";

    /**
     * Get origin of the message.
     */
    public function getSender()
    {
        if ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->sender_id);
        } elseif ($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->sender_id);
        } else {
            return null;
        }
    }

    /**
     * Get the destination of the message.
     *
     * Returns either user or club.
     *
     * @returns User|Club
     */
    public function getRecipient(): ?RowModel
    {
        if ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\User') {
            return (new Users())->get($this->getRecord()->recipient_id);
        } elseif ($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\Club') {
            return (new Clubs())->get($this->getRecord()->recipient_id);
        } else {
            return null;
        }
    }

    public function getUnreadState(): int
    {
        trigger_error("TODO: use isUnread", E_USER_DEPRECATED);

        return (int) $this->isUnread();
    }

    /**
     * Get date of initial publication.
     *
     * @returns DateTime
     */
    public function getSendTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }

    public function getSendTimeHumanized(): string
    {
        $dateTime = new DateTime($this->getRecord()->created);

        if ($dateTime->format("%d.%m.%y") == ovk_strftime_safe("%d.%m.%y", time())) {
            return $dateTime->format("%T");
        } else {
            return $dateTime->format("%d.%m.%y");
        }
    }

    /**
     * Get date of last edit, if any edits were made, otherwise null.
     *
     * @returns DateTime|null
     */
    public function getEditTime(): ?DateTime
    {
        $edited = $this->getRecord()->edited;
        if (is_null($edited)) {
            return null;
        }

        return new DateTime($edited);
    }

    /**
     * Is this message an ad?
     *
     * Messages can never be ads.
     *
     * @returns false
     */
    public function isAd(): bool
    {
        return false;
    }

    public function isUnread(): bool
    {
        return (bool) $this->getRecord()->unread;
    }

    /**
     * Simplify to array
     *
     * @returns array
     */
    public function simplify(): array
    {
        $author = $this->getSender();

        $attachments = [];
        foreach ($this->getChildren() as $attachment) {
            if ($attachment instanceof Photo) {
                $attachments[] = [
                    "type"  => "photo",
                    "id"    => $attachment->getId(),
                    "link"  => "/photo" . $attachment->getPrettyId(),
                    "photo" => [
                        "url"     => $attachment->getURL(),
                        "caption" => $attachment->getDescription(),
                    ],
                ];
            } elseif ($attachment instanceof Video) {
                $attachments[] = [
                    "type"  => "video",
                    "id"    => $attachment->getId(),
                    "url"   => $attachment->getPageURL(),
                    "thumb" => $attachment->getThumbnailURL(),
                    "title" => $attachment->getName(),
                ];
            } elseif ($attachment instanceof Audio) {
                $attachments[] = [
                    "type"     => "audio",
                    "id"       => $attachment->getId(),
                    "url"      => "/audio" . $attachment->getPrettyId(),
                    "title"    => $attachment->getName(),
                    "artist"   => $attachment->getPerformer(),
                    "duration" => $attachment->getLength(),
                    "cover"    => $attachment->getCoverURL(),
                ];
            } elseif ($attachment instanceof Document) {
                $attachments[] = [
                    "type"  => "document",
                    "id"    => $attachment->getId(),
                    "title" => $attachment->getName(),
                    "size"  => $attachment->getFileSize(),
                    "url"   => $attachment->getURL(),
                ];
            } else {
                $attachments[] = [
                    "type"  => "unknown",
                ];
            }
        }

        return [
            "uuid"   => $this->getId(),
            "sender" => [
                "id"     => $author->getId(),
                "link"   => $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $author->getURL(),
                "avatar" => $author->getAvatarUrl(),
                "name"   => $author->getFirstName(),
            ],
            "timing" => [
                "sent"   => (string) $this->getSendTimeHumanized(),
                "edited" => is_null($this->getEditTime()) ? null : (string) $this->getEditTime(),
            ],
            "text"        => $this->getText(),
            "read"        => !$this->isUnread(),
            'conversation_id' => $this->getRecord()->conversation_id,
            "attachments" => $attachments,
        ];
    }

    public function getConversationId()
    {
        return isset($this->getRecord()->conversation_id) ? (int)$this->getRecord()->conversation_id : null;
    }
}
