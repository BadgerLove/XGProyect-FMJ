<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Messenger;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class Messenger
{
    use PreparesLegacySql;

    public function sendMessage(MessagesOptions $options): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement(
            "INSERT INTO `{$prefix}messages` SET
                `message_receiver` = ?,
                `message_sender` = ?,
                `message_time` = ?,
                `message_type` = ?,
                `message_from` = ?,
                `message_subject` = ?,
                `message_text` = ?",
            [
                $options->getTo(),
                $options->getSender(),
                $options->getTime(),
                $options->getType(),
                $options->getFrom(),
                $options->getSubject(),
                $options->getMessageText(),
            ]
        );
    }
}
