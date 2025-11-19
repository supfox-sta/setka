<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

final class DevPresenter extends OpenVKPresenter
{
    protected $banTolerant = true;
    protected $activationTolerant = true;
    protected $deactivationTolerant = true;

    public function renderApi(): void
    {
    }

    public function renderApiAuth(): void {}
    public function renderApiMessages(): void {}
    public function renderApiUsers(): void {}
    public function renderApiGroups(): void {}
    public function renderApiPhotos(): void {}
    public function renderApiVideos(): void {}
    public function renderApiAudio(): void {}
    public function renderApiFeed(): void {}
}
