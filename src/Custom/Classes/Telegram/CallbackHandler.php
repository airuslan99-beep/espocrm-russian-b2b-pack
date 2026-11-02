<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Classes\Opportunity\FollowUpSchedule;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class CallbackHandler
{
    private const DONE_STATUS = ['Task' => 'Completed', 'Call' => 'Held', 'Meeting' => 'Held'];
    private const DONE_LABEL = [
        'Task' => '✅ Выполнено',
        'Call' => '✔️ Звонок состоялся',
        'Meeting' => '✔️ Встреча состоялась',
    ];
    private const MARKER = '/#(Task|Call|Meeting):([A-Za-z0-9]{1,24})/';
    private const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Api $api,
        private Log $log
    ) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->callback($update['callback_query']);

            return;
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->message($update['message']);
        }
    }

    private function allowed(array $from): bool
    {
        $owner = $this->api->ownerId();

        return $owner !== null && (int) ($from['id'] ?? 0) === $owner;
    }

    private function callback(array $query): void
    {
        $queryId = (string) ($query['id'] ?? '');

        if (!$this->allowed($query['from'] ?? [])) {
            $this->answer($queryId, 'Нет доступа');

            return;
        }

        $parsed = Keyboard::parse((string) ($query['data'] ?? ''));
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chatId = $message['chat']['id'] ?? null;
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : null;

        if (!$parsed || !$chatId || !$messageId) {
            $this->answer($queryId);

            return;
        }

        $entity = $this->entityManager->getEntityById($parsed['type'], $parsed['id']);

        if (!$entity) {
            $this->answer($queryId, 'Запись не найдена');
            $this->finish($chatId, $messageId, $message, '⚠️ Запись удалена в CRM');

            return;
        }

        try {
            $this->dispatch($parsed, $entity, $queryId, $chatId, $messageId, $message);
        } catch (Throwable $e) {
            $this->log->error('Telegram callback ' . ($query['data'] ?? '') . ': ' . $e->getMessage());
            $this->answer($queryId, 'Ошибка, см. лог CRM');
        }
    }

    private function dispatch(array $p, Entity $entity, string $queryId, $chatId, int $messageId, array $message): void
    {
        $type = $p['type'];
        $id = $p['id'];

        switch ($p['action']) {
            case 'done':
                $entity->set('status', self::DONE_STATUS[$type]);
                $this->entityManager->saveEntity($entity);

                $markup = $type === 'Task' && $entity->get('parentType') === 'Opportunity' && $entity->get('parentId')
                    ? Keyboard::next($id)
                    : null;

                $this->answer($queryId, 'Готово');
                $this->finish($chatId, $messageId, $message, self::DONE_LABEL[$type] . ' · ' . $this->nowTime(), $markup);

                return;

            case 'nh':
                $entity->set('status', 'Not Held');
                $this->entityManager->saveEntity($entity);
                $this->answer($queryId, 'Готово');

                if ($type === 'Call') {
                    $this->finish(
                        $chatId,
                        $messageId,
                        $message,
                        '✖️ Не дозвонился · ' . $this->nowTime() . "\nПерезвонить:",
                        Keyboard::postpone('Call', $id, 'rc')
                    );

                    return;
                }

                $this->finish($chatId, $messageId, $message, '✖️ Встреча отменена · ' . $this->nowTime());

                return;

            case 'pm':
                $this->answer($queryId);
                $this->editMarkup($chatId, $messageId, Keyboard::postpone($type, $id));

                return;

            case 'back':
                $this->answer($queryId);
                $this->editMarkup($chatId, $messageId, Keyboard::main($type, $id, $this->url($type, $id)));

                return;

            case 'p':
                $target = Keyboard::target((string) $p['arg'], new DateTimeImmutable('now'), $this->timeZone());

                if (!$target) {
                    $this->answer($queryId);

                    return;
                }

                $this->reschedule($entity, $type, $target);
                $this->answer($queryId, 'Перенесено');
                $this->finish($chatId, $messageId, $message, '⏰ Перенесено на ' . $this->local($target));

                return;

            case 'rc':
                $target = Keyboard::target((string) $p['arg'], new DateTimeImmutable('now'), $this->timeZone());

                if (!$target || $type !== 'Call') {
                    $this->answer($queryId);

                    return;
                }

                $this->copyCall($entity, $target);
                $this->answer($queryId, 'Звонок создан');
                $this->finish($chatId, $messageId, $message, '📞 Перезвонить ' . $this->local($target));

                return;

            case 'sn':
                $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour');
                $this->snooze($entity, $type, $at);
                $this->answer($queryId, 'Напомню через час');
                $this->finish($chatId, $messageId, $message, '🔕 Напомню в ' . $this->local($at, 'H:i'));

                return;

            case 'cm':
                $this->answer($queryId);
                $this->api->call('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => '💬 Комментарий к «' . ReminderMessage::shortTitle($entity->get('name')) . "»\n"
                        . "Ответьте на это сообщение текстом комментария.\n"
                        . '#' . $type . ':' . $id,
                    'reply_to_message_id' => $messageId,
                    'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'Комментарий'],
                ]);

                return;

            case 'nx':
                $task = $type === 'Task' ? $this->nextTask($entity) : null;
                $this->answer($queryId, $task ? 'Задача создана' : 'Не удалось создать задачу');
                $this->finish(
                    $chatId,
                    $messageId,
                    $message,
                    $task
                        ? '➕ Следующая задача: ' . $this->local(new DateTimeImmutable((string) $task->get('dateEnd'), new DateTimeZone('UTC')))
                        : '⚠️ Следующая задача не создана'
                );

                return;
        }

        $this->answer($queryId);
    }

    private function message(array $message): void
    {
        if (!$this->allowed($message['from'] ?? [])) {
            return;
        }

        $reply = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = $message['chat']['id'] ?? null;

        if (!$reply || $text === '' || !$chatId) {
            return;
        }

        if (!preg_match(self::MARKER, (string) ($reply['text'] ?? ''), $match)) {
            return;
        }

        $entity = $this->entityManager->getEntityById($match[1], $match[2]);

        if (!$entity) {
            $this->api->call('sendMessage', ['chat_id' => $chatId, 'text' => '⚠️ Запись не найдена в CRM']);

            return;
        }

        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'post' => $text,
            'parentType' => $match[1],
            'parentId' => $match[2],
        ]);
        $this->entityManager->saveEntity($note);

        $this->api->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => '💬 Комментарий добавлен',
            'reply_to_message_id' => (int) ($message['message_id'] ?? 0),
        ]);
    }

    private function reschedule(Entity $entity, string $type, DateTimeImmutable $target): void
    {
        if ($type === 'Task') {
            $entity->set('dateEndDate', null);
            $entity->set('dateEnd', $target->format(self::DB_FORMAT));
        } else {
            $duration = $this->duration($entity);
            $entity->set('dateStart', $target->format(self::DB_FORMAT));
            $entity->set('dateEnd', $target->modify('+' . $duration . ' seconds')->format(self::DB_FORMAT));
        }

        $entity->set('reminders', [['type' => 'Telegram', 'seconds' => 0]]);
        $this->entityManager->saveEntity($entity);
    }

    private function copyCall(Entity $call, DateTimeImmutable $target): Entity
    {
        $duration = $this->duration($call);
        $new = $this->entityManager->getNewEntity('Call');

        $new->set([
            'name' => $call->get('name'),
            'status' => 'Planned',
            'direction' => $call->get('direction') ?: 'Outbound',
            'parentType' => $call->get('parentType'),
            'parentId' => $call->get('parentId'),
            'assignedUserId' => $call->get('assignedUserId'),
            'description' => $call->get('description'),
            'dateStart' => $target->format(self::DB_FORMAT),
            'dateEnd' => $target->modify('+' . $duration . ' seconds')->format(self::DB_FORMAT),
            'reminders' => [['type' => 'Telegram', 'seconds' => 0]],
        ]);

        if (method_exists($call, 'getLinkMultipleIdList')) {
            $new->set('usersIds', $call->getLinkMultipleIdList('users'));
            $new->set('contactsIds', $call->getLinkMultipleIdList('contacts'));
        }

        $this->entityManager->saveEntity($new);

        return $new;
    }

    private function duration(Entity $entity): int
    {
        $start = $entity->get('dateStart');
        $end = $entity->get('dateEnd');

        if ($start && $end) {
            $utc = new DateTimeZone('UTC');

            return max(0, (new DateTimeImmutable($end, $utc))->getTimestamp() - (new DateTimeImmutable($start, $utc))->getTimestamp());
        }

        return max(0, (int) $entity->get('duration'));
    }

    private function snooze(Entity $entity, string $type, DateTimeImmutable $at): void
    {
        $reminder = $this->entityManager->getNewEntity('Reminder');
        $value = $at->format(self::DB_FORMAT);

        $reminder->set([
            'type' => 'Telegram',
            'entityType' => $type,
            'entityId' => $entity->getId(),
            'userId' => $entity->get('assignedUserId'),
            'remindAt' => $value,
            'startAt' => $value,
            'seconds' => 0,
        ]);

        $this->entityManager->saveEntity($reminder);
    }

    private function nextTask(Entity $task): ?Entity
    {
        if ($task->get('parentType') !== 'Opportunity' || !$task->get('parentId')) {
            return null;
        }

        $deal = $this->entityManager->getEntityById('Opportunity', $task->get('parentId'));

        if (!$deal) {
            return null;
        }

        $accountName = $deal->get('accountName');

        if (!$accountName && $deal->get('accountId')) {
            $accountName = $this->entityManager->getEntityById('Account', $deal->get('accountId'))?->get('name');
        }

        $new = $this->entityManager->getNewEntity('Task');
        $new->set([
            'name' => FollowUpSchedule::buildName($accountName, $deal->get('name')),
            'status' => 'Not Started',
            'assignedUserId' => $task->get('assignedUserId') ?: $deal->get('assignedUserId'),
            'parentType' => 'Opportunity',
            'parentId' => $deal->getId(),
            'dateEndDate' => null,
            'dateEnd' => FollowUpSchedule::dueAtUtc(new DateTimeImmutable('now'), $this->timeZone()),
            'reminders' => [['type' => 'Telegram', 'seconds' => 0]],
        ]);

        $this->entityManager->saveEntity($new);

        return $new;
    }

    private function finish($chatId, int $messageId, array $message, string $status, ?array $markup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => rtrim((string) ($message['text'] ?? '')) . "\n\n" . $status,
            'disable_web_page_preview' => true,
        ];

        if (!empty($message['entities']) && is_array($message['entities'])) {
            $params['entities'] = $message['entities'];
        }

        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }

        $this->api->call('editMessageText', $params, 15, false);
    }

    private function editMarkup($chatId, int $messageId, array $markup): void
    {
        $this->api->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $markup,
        ], 15, false, false);
    }

    private function answer(string $queryId, ?string $text = null): void
    {
        if ($queryId === '') {
            return;
        }

        $params = ['callback_query_id' => $queryId];

        if ($text !== null) {
            $params['text'] = $text;
        }

        $this->api->call('answerCallbackQuery', $params, 10, false, false);
    }

    private function url(string $type, string $id): ?string
    {
        $site = rtrim((string) $this->config->get('siteUrl'), '/');

        return $site !== '' ? $site . '/#' . $type . '/view/' . $id : null;
    }

    private function timeZone(): string
    {
        return $this->config->get('timeZone') ?: 'Europe/Moscow';
    }

    private function nowTime(): string
    {
        return $this->local(new DateTimeImmutable('now', new DateTimeZone('UTC')), 'H:i');
    }

    private function local(DateTimeImmutable $value, string $format = 'd.m H:i'): string
    {
        return $value->setTimezone(new DateTimeZone($this->timeZone()))->format($format);
    }
}
