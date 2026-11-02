<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Telegram;

use Espo\Core\Utils\Log;
use Throwable;

class UpdatesPoller
{
    private const OFFSET_FILE = 'data/telegram-updates.offset';
    private const LOCK_FILE = 'data/telegram-updates.lock';

    public function __construct(
        private Api $api,
        private CallbackHandler $handler,
        private Log $log
    ) {}

    public function poll(int $timeout): void
    {
        if (!$this->api->isConfigured() || $this->api->ownerId() === null) {
            return;
        }

        $lock = @fopen(self::LOCK_FILE, 'c');

        if (!$lock) {
            return;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        try {
            $offset = (int) @file_get_contents(self::OFFSET_FILE);
            $params = [
                'timeout' => $timeout,
                'allowed_updates' => ['callback_query', 'message'],
            ];

            if ($offset > 0) {
                $params['offset'] = $offset;
            }

            $data = $this->api->call('getUpdates', $params, $timeout + 10, false);

            foreach (($data['result'] ?? []) as $update) {
                if (!is_array($update)) {
                    continue;
                }

                $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                @file_put_contents(self::OFFSET_FILE, (string) $offset);

                try {
                    $this->handler->handle($update);
                } catch (Throwable $e) {
                    $this->log->error('Telegram update ' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
