<?php declare(strict_types=1);
namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Entities\Notifications\CoinsTransferNotification;
use Chandler\Database\DatabaseConnection;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use DestyK\UMoney\QuickPay;

final class YooMoneyPresenter extends OpenVKPresenter
{
    public function actionNotify(): void
    {
        $request = $this->getHttpRequest();
        if ($request->getMethod() !== IRequest::POST) {
            $this->getHttpResponse()->setCode(IResponse::S405_METHOD_NOT_ALLOWED);
            $this->sendText("Method Not Allowed");
            return;
        }

        $ym = OPENVK_ROOT_CONF["openvk"]["preferences"]["yoomoney"];
        if (!($ym["enabled"] ?? false)) {
            $this->getHttpResponse()->setCode(IResponse::S403_FORBIDDEN);
            $this->sendText("YooMoney disabled");
            return;
        }

        $post = $request->getPost();
        $sha1 = $post["sha1_hash"] ?? null;
        $operationId = $post["operation_id"] ?? null;
        $amountRub = isset($post["amount"]) ? (float)$post["amount"] : 0.0;
        $label = $post["label"] ?? "";

        // 1) Подпись
        try {
            $qp = new QuickPay($ym["secretKey"]);
            if (!$sha1 || !$qp->checkNotificationSignature($sha1, $post)) {
                $this->getHttpResponse()->setCode(IResponse::S400_BAD_REQUEST);
                $this->sendText("Bad signature");
                return;
            }
        } catch (\Throwable $e) {
            $this->getHttpResponse()->setCode(IResponse::S500_INTERNAL_SERVER_ERROR);
            $this->sendText("Signature error");
            return;
        }

        // 2) Idempotency — уже было?
        $db = DatabaseConnection::i()->getContext();
        $exists = $db->table("yoomoney_transactions")->where("operation_id", $operationId)->fetch();
        if ($exists) {
            $this->getHttpResponse()->setCode(IResponse::S200_OK);
            $this->sendText("OK");
            return;
        }

        // 3) Кого пополнять — из label = "UID_123"
        $userId = null;
        if (preg_match('/^UID_(\d+)$/', (string)$label, $m)) {
            $userId = (int)$m[1];
        }

        if (!$userId || $amountRub <= 0) {
            $this->getHttpResponse()->setCode(IResponse::S400_BAD_REQUEST);
            $this->sendText("Bad data");
            return;
        }

        $rate = (float)($ym["rate"] ?? 1.0);
        $coins = $amountRub * $rate;

        $user = (new Users)->get($userId);
        if (!$user) {
            $this->getHttpResponse()->setCode(IResponse::S404_NOT_FOUND);
            $this->sendText("User not found");
            return;
        }

        // 4) Зачисление монет
        $user->setCoins($user->getCoins() + $coins);
        $user->save();

        (new CoinsTransferNotification(
            $user,
            (new Users)->get(OPENVK_ROOT_CONF["openvk"]["preferences"]["support"]["adminAccount"]),
            0,
            "Via YooMoney QuickPay"
        ))->emit();

        // 5) Логируем операцию
        $db->table("yoomoney_transactions")->insert([
            "operation_id" => $operationId,
            "label" => $label,
            "amount" => $amountRub,
        ]);

        $this->getHttpResponse()->setCode(IResponse::S200_OK);
        $this->sendText("OK");
    }

    private function sendText(string $text): void
    {
        $this->sendResponse(new \Nette\Application\Responses\TextResponse($text));
    }
}
