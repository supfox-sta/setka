<?php

declare(strict_types=1);

namespace openvk\Web\Events;

use openvk\Web\Models\Entities\Message;
use openvk\Web\Models\Repositories\Messages;

class NewMessageEvent implements ILPEmitable
{
    protected $payload;

    public function __construct(Message $message)
    {
        $this->payload = $message->simplify();
    }

    public function getLongPoolSummary(): object
    {
        // Implement if needed for long-poll summary object
        return (object)$this->payload;
    }

    public function getVKAPISummary(int $userId): array
    {
        $msg  = (new Messages())->get($this->payload["uuid"]);
        $peer = $msg->getSender()->getId();
        $convId = method_exists($msg, 'getConversationId') ? $msg->getConversationId() : null;
        if (!is_null($convId)) {
            $peer = $convId;
        } else {
            if ($peer === $userId) {
                $peer = $msg->getRecipient()->getId();
            }
        }

        /*
         * Source:
         * https://github.com/danyadev/longpoll-doc
         */

        return [
            4,                                # event type
            $msg->getId(),                    # messageId
            256,                              # checked for spam flag
            $peer,                            # conversation/peer id
            $msg->getSendTime()->timestamp(), # creation time in unix
            $msg->getText(),                  # text (formatted)
            [],                               # empty additional info
            $msg->simplify()["attachments"],  # attachments
            $msg->getId() << 2,               # id as random_id
            $peer,                            # conversation id
            0,                                # not edited yet
        ];
    }
}
