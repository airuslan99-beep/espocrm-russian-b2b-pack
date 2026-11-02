<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Log;
use Espo\Custom\Classes\Telegram\Api;
use Espo\Custom\Classes\Telegram\CallbackHandler;
use Espo\Custom\Classes\Telegram\Keyboard;
use Espo\Custom\Classes\Telegram\ReminderMessage;
use Espo\Custom\Classes\Telegram\UpdatesPoller;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class SendTelegramReminders implements JobDataLess
{
    private const ENTITY_TYPES = ['Meeting', 'Call', 'Task'];
    private const CLOSED_STATUSES = [
        'Task' => ['Completed', 'Canceled'],
        'Call' => ['Held', 'Not Held'],
        'Meeting' => ['Held', 'Not Held'],
    ];
    private const PARTICIPANTS_LIMIT = 5;
    private const POLL_TIMEOUT = 40;

    private Api $api;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Language $language,
        private Log $log
    ) {
        $this->api = new Api($config, $log);
    }

    public function run(): void
    {
        try {
            $this->sendDue();
        } finally {
            $handler = new CallbackHandler($this->entityManager, $this->config, $this->api, $this->log);
            (new UpdatesPoller($this->api, $handler, $this->log))->poll(self::POLL_TIMEOUT);
        }
    }

    private function sendDue(): void
    {
        $now = (new \DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        $nowShifted = (new \DateTime())->modify('-1 hour')
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $collection = $this->entityManager
            ->getRDBRepository('Reminder')
            ->where([
                'type' => 'Telegram',
                'remindAt<=' => $now,
                'startAt>' => $nowShifted,
            ])
            ->order('remindAt')
            ->limit(0, 50)
            ->find();

        $total = count($collection);
        $processed = 0;
        $sent = [];

        foreach ($collection as $reminder) {
            $processed++;
            $entityType = $reminder->get('entityType');
            $key = $entityType . ':' . $reminder->get('entityId');

            if (!in_array($entityType, self::ENTITY_TYPES, true) || isset($sent[$key])) {
                $this->entityManager->removeEntity($reminder);
                continue;
            }

            $entity = $this->entityManager
                ->getEntityById($entityType, $reminder->get('entityId'));

            if (
                !$entity ||
                in_array($entity->get('status'), self::CLOSED_STATUSES[$entityType], true)
            ) {
                $this->entityManager->removeEntity($reminder);
                continue;
            }

            try {
                $data = $this->collect($entity, $entityType);
                $url = $data['url'];
                $data['url'] = null;

                $text = ReminderMessage::build($data);
                $markup = Keyboard::main($entityType, $entity->getId(), $url);
            } catch (Throwable $e) {
                $this->log->error('SendTelegramReminders: ' . $entityType . ' ' . $entity->getId() . ': ' . $e->getMessage());
                $this->entityManager->removeEntity($reminder);
                continue;
            }

            if (!$this->send($text, $markup)) {
                $this->log->warning(sprintf(
                    'SendTelegramReminders: Telegram недоступен, %d напоминаний отложено до следующего запуска (первое: %s %s).',
                    $total - $processed + 1,
                    $entityType,
                    $entity->getId()
                ));

                break;
            }

            $sent[$key] = true;
            $this->entityManager->removeEntity($reminder);
        }
    }

    private function collect(Entity $entity, string $entityType): array
    {
        [$deal, $account] = $this->resolveContext($entity);

        $participants = in_array($entityType, ['Call', 'Meeting'], true)
            ? $this->relatedContacts($entityType, $entity, self::PARTICIPANTS_LIMIT + 1)
            : [];

        $contact = $participants[0] ?? $this->resolveContact($entity, $deal);
        $site = rtrim((string) $this->config->get('siteUrl'), '/');

        $data = [
            'type' => $entityType,
            'title' => $entity->get('name'),
            'when' => $this->formatWhen($entity, $entityType),
            'account' => $account?->get('name'),
            'contact' => $contact
                ? ReminderMessage::contactLine($contact->get('name'), $this->phone($contact))
                : null,
            'deal' => $deal?->get('name'),
            'stage' => $deal ? $this->translateStage($deal->get('stage')) : null,
            'amount' => $deal ? ReminderMessage::money($deal->get('amount'), $deal->get('amountCurrency')) : null,
            'description' => $entity->get('description'),
            'url' => $site !== '' ? $site . '/#' . $entityType . '/view/' . $entity->getId() : null,
        ];

        if ($entityType === 'Meeting') {
            $others = [];

            foreach (array_slice($participants, 1, self::PARTICIPANTS_LIMIT) as $item) {
                $name = trim((string) $item->get('name'));

                if ($name !== '') {
                    $others[] = $name;
                }
            }

            $data['participants'] = $others ? implode(', ', $others) : null;
            $data['location'] = $entity->hasAttribute('location') ? $entity->get('location') : null;
        }

        return $data;
    }

    /**
     * @return array{0: ?Entity, 1: ?Entity}
     */
    private function resolveContext(Entity $entity): array
    {
        $deal = null;
        $account = null;
        $parentType = $entity->get('parentType');
        $parentId = $entity->get('parentId');

        if ($parentId && $parentType === 'Opportunity') {
            $deal = $this->entityManager->getEntityById('Opportunity', $parentId);

            if ($deal && $deal->get('accountId')) {
                $account = $this->entityManager->getEntityById('Account', $deal->get('accountId'));
            }
        } elseif ($parentId && $parentType === 'Account') {
            $account = $this->entityManager->getEntityById('Account', $parentId);
        }

        if (!$account && $entity->hasAttribute('accountId') && $entity->get('accountId')) {
            $account = $this->entityManager->getEntityById('Account', $entity->get('accountId'));
        }

        return [$deal, $account];
    }

    private function resolveContact(Entity $entity, ?Entity $deal): ?Entity
    {
        if ($entity->hasAttribute('contactId') && $entity->get('contactId')) {
            $contact = $this->entityManager->getEntityById('Contact', $entity->get('contactId'));

            if ($contact) {
                return $contact;
            }
        }

        if ($entity->get('parentType') === 'Contact' && $entity->get('parentId')) {
            $contact = $this->entityManager->getEntityById('Contact', $entity->get('parentId'));

            if ($contact) {
                return $contact;
            }
        }

        if (!$deal) {
            return null;
        }

        if ($deal->hasAttribute('contactId') && $deal->get('contactId')) {
            $contact = $this->entityManager->getEntityById('Contact', $deal->get('contactId'));

            if ($contact) {
                return $contact;
            }
        }

        return $this->relatedContacts('Opportunity', $deal, 1)[0] ?? null;
    }

    /**
     * @return Entity[]
     */
    private function relatedContacts(string $entityType, Entity $entity, int $limit): array
    {
        try {
            $collection = $this->entityManager
                ->getRDBRepository($entityType)
                ->getRelation($entity, 'contacts')
                ->limit(0, $limit)
                ->find();
        } catch (Throwable $e) {
            return [];
        }

        $result = [];

        foreach ($collection as $item) {
            $result[] = $item;
        }

        return $result;
    }

    private function phone(Entity $contact): ?string
    {
        try {
            $numbers = $this->entityManager
                ->getRDBRepository('Contact')
                ->getRelation($contact, 'phoneNumbers')
                ->find();

            foreach ($numbers as $number) {
                if ($number->get('type') === 'Mobile' && trim((string) $number->get('name')) !== '') {
                    return trim((string) $number->get('name'));
                }
            }
        } catch (Throwable $e) {
        }

        $primary = trim((string) $contact->get('phoneNumber'));

        return $primary !== '' ? $primary : null;
    }

    private function translateStage(?string $stage): ?string
    {
        if (!$stage) {
            return null;
        }

        try {
            return $this->language->translateOption($stage, 'stage', 'Opportunity');
        } catch (Throwable $e) {
            return $stage;
        }
    }

    private function formatWhen(Entity $entity, string $entityType): string
    {
        if ($entityType === 'Task') {
            if ($entity->get('dateEndDate')) {
                $date = \DateTime::createFromFormat('!Y-m-d', (string) $entity->get('dateEndDate'));

                return $date ? $date->format('d.m') . ' (весь день)' : (string) $entity->get('dateEndDate');
            }

            return $this->local($entity->get('dateEnd'), 'd.m H:i');
        }

        $start = $this->local($entity->get('dateStart'), 'd.m H:i');

        if ($entityType === 'Call') {
            $minutes = (int) round(((int) $entity->get('duration')) / 60);

            return $minutes > 0 ? $start . ' · ' . $minutes . ' мин' : $start;
        }

        $end = $entity->get('dateEnd');

        if (!$end) {
            return $start;
        }

        if ($this->local($end, 'd.m') === $this->local($entity->get('dateStart'), 'd.m')) {
            return $start . '–' . $this->local($end, 'H:i');
        }

        return $start . ' – ' . $this->local($end, 'd.m H:i');
    }

    private function local(?string $value, string $format): string
    {
        if (!$value) {
            return '';
        }

        $tz = $this->config->get('timeZone') ?: 'UTC';

        try {
            $dt = new \DateTime($value, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz));

            return $dt->format($format);
        } catch (Throwable $e) {
            return (string) $value;
        }
    }

    private function send(string $text, array $markup): bool
    {
        if (!$this->api->isConfigured()) {
            return true;
        }

        $params = [
            'chat_id' => $this->api->chatId(),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup,
        ];

        if ($this->api->call('sendMessage', $params) !== null) {
            return true;
        }

        unset($params['reply_markup']);

        return $this->api->call('sendMessage', $params) !== null;
    }
}
