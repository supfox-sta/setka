<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

final class PushPresenter extends OpenVKPresenter
{
    protected $presenterName = "push";

    public function renderSw(): void
    {
        header('Content-Type: application/javascript; charset=UTF-8');
        $js = <<<JS
self.addEventListener('install', function(event) {
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function(event) {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch(e) {}
  const title = data.title || 'Setka';
  const body  = data.body  || 'Новое уведомление';
  const icon  = data.icon  || '/assets/packages/static/openvk/img/icon.png';
  const tag   = data.tag   || 'setka-push';
  const url   = data.url   || '/';

  event.waitUntil(self.registration.showNotification(title, {
    body: body,
    icon: icon,
    tag: tag,
    data: { url: url }
  }));
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const url = (event.notification && event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if ('focus' in client) { client.focus(); break; }
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
JS;
        exit($js);
    }

    public function renderSubscribe(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);

        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true) ?: [];
        if (!isset($json['endpoint'])) {
            $this->returnJson([ 'success' => false, 'error' => 'bad_subscription' ]);
        }

        $file = OPENVK_ROOT . '/tmp/push_subscriptions.json';
        $data = [];
        if (file_exists($file)) {
            $cur = json_decode((string)@file_get_contents($file), true);
            if (is_array($cur)) $data = $cur;
        }
        $uid = (string)$this->user->identity->getId();
        if (!isset($data[$uid])) $data[$uid] = [];
        // de-dup by endpoint
        $exists = false;
        foreach ($data[$uid] as $sub) { if (($sub['endpoint'] ?? '') === $json['endpoint']) { $exists = true; break; } }
        if (!$exists) $data[$uid][] = $json;
        @file_put_contents($file, json_encode($data));

        $this->returnJson([ 'success' => true ]);
    }

    public function renderUnsubscribe(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);

        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true) ?: [];
        $endpoint = $json['endpoint'] ?? '';
        $file = OPENVK_ROOT . '/tmp/push_subscriptions.json';
        if (!file_exists($file)) {
            $this->returnJson([ 'success' => true ]);
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) $data = [];
        $uid = (string)$this->user->identity->getId();
        if (isset($data[$uid]) && is_array($data[$uid])) {
            $data[$uid] = array_values(array_filter($data[$uid], function($sub) use ($endpoint){ return ($sub['endpoint'] ?? '') !== $endpoint; }));
        }
        @file_put_contents($file, json_encode($data));
        $this->returnJson([ 'success' => true ]);
    }
}
