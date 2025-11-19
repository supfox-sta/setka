<?php
declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\Users;

class PaymentPresenter extends OpenVKPresenter
{
    /**
     * Страница с кнопкой YooMoney
     */
    public function actionDonate(): void
    {
        $this->assertUserLoggedIn();
        $user = $this->getUser();

        $this->template->user = $user;
        $this->template->title = "Пополнить голоса";
        $this->render('donate');
    }

    /**
     * Callback от YooMoney
     */
    public function actionCallback(): void
    {
        $secret = 'МОЙ_СЕКРЕТНЫЙ_КЛЮЧ'; // секретное слово YooMoney

        $notification_type = $_POST['notification_type'] ?? '';
        $operation_id      = $_POST['operation_id'] ?? '';
        $amount            = $_POST['amount'] ?? '';
        $currency          = $_POST['currency'] ?? '';
        $datetime          = $_POST['datetime'] ?? '';
        $sender            = $_POST['sender'] ?? '';
        $codepro           = $_POST['codepro'] ?? '';
        $label             = $_POST['label'] ?? '';
        $sha1_hash         = $_POST['sha1_hash'] ?? '';

        $str = "$notification_type&$operation_id&$amount&$currency&$datetime&$sender&$codepro&$secret&$label";
        $hash = sha1($str);

        if ($hash !== $sha1_hash) {
            http_response_code(400);
            echo "bad hash";
            return;
        }

        $amountFloat = (float)$amount;
        if ($amountFloat <= 0) {
            http_response_code(400);
            echo "invalid amount";
            return;
        }

        if (!preg_match('/^user_(\d+)$/', $label, $matches)) {
            http_response_code(400);
            echo "invalid label";
            return;
        }

        $userId = (int)$matches[1];
        $users = new Users();
        $user = $users->get($userId);

        if (!$user) {
            http_response_code(404);
            echo "user not found";
            return;
        }

        // Начисляем монеты
        $newCoins = $user->getCoins() + $amountFloat;
        $user->stateChanges("coins", $newCoins);
        $user->save();

        echo "OK";
    }
}
