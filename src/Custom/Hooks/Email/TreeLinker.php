<?php

declare(strict_types=1);

namespace Espo\Custom\Hooks\Email;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Config;
use Espo\Custom\Classes\Email\TreeResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

class TreeLinker implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        try {
            $status = (string) ($entity->get('status') ?? '');
            if ($status === 'Draft') {
                return;
            }
            $allowAi = in_array($status, ['Sent', 'Archived'], true);
            (new TreeResolver($this->entityManager, $this->config))->linkEmail($entity, $allowAi);
        } catch (Throwable $e) {
            error_log('[Email/TreeLinker] ' . $e->getMessage());
        }
    }
}
