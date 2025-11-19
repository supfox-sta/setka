<?php
declare(strict_types=1);

namespace openvk\Web\Presenters;

final class ConversationsApiPresenter extends OpenVKPresenter
{
    protected $presenterName = "conversationsapi";
    private $api;

    public function __construct()
    {
        parent::__construct();
        $this->api = new \Web\Controllers\ConversationsApi();
    }

    public function createAction(): void { $this->api->createAction(); }
    public function addMemberAction(): void { $this->api->addMemberAction(); }
    public function sendAction(): void { $this->api->sendAction(); }

    public function generateInviteAction(): void { $this->api->generateInviteAction(); }
    public function revokeInviteAction(): void { $this->api->revokeInviteAction(); }
    public function joinByInviteAction(): void { $this->api->joinByInviteAction(); }
    public function membersAction(): void { $this->api->membersAction(); }
    public function removeMemberAction(): void { $this->api->removeMemberAction(); }
    public function setRoleAction(): void { $this->api->setRoleAction(); }
}
