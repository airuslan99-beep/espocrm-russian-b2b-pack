<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Document;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Classes\Tochka\Api as TochkaApi;
use Espo\Custom\Classes\WorkOrder\OrderFinanceCalculator;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Polls GET /invoice/v1.0/bills/{customerCode}/{documentId}/payment-status in Tochka API
 * for unpaid invoices that have tochka_document_id.
 */
final class TochkaBillStatusSync
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sync(): array
    {
        $api = new TochkaApi($this->config);
        if (!$api->isConfigured()) {
            return [];
        }

        $pdo = $this->entityManager->getPDO();
        $stmt = $pdo->prepare(
            "SELECT d.id, d.name, d.amount, d.tochka_document_id, d.tochka_customer_code,
                    d.counterparty_id, d.work_order_id, d.contract_id, a.name AS account_name
             FROM document d
             LEFT JOIN account a ON a.id = d.counterparty_id AND a.deleted = 0
             WHERE d.deleted = 0
               AND d.type IN (?, ?)
               AND (d.payment_status IS NULL OR d.payment_status <> ?)
               AND (d.status IS NULL OR d.status <> 'Canceled')
               AND d.tochka_document_id IS NOT NULL AND d.tochka_document_id <> ''"
        );
        $stmt->execute([Types::ADVANCE_INVOICE, Types::FINAL_INVOICE, Types::PAID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $paidByTochka = [];
        $orderCalc = new OrderFinanceCalculator($this->entityManager);

        foreach ($rows as $r) {
            $docId = (string) $r['id'];
            $tochkaId = trim((string) ($r['tochka_document_id'] ?? ''));
            $custCode = trim((string) ($r['tochka_customer_code'] ?? ''));

            if ($tochkaId === '') {
                continue;
            }

            if ($custCode === '') {
                try {
                    $custCode = $api->customerCode();
                } catch (Throwable) {
                    continue;
                }
            }

            try {
                $remoteStatus = $api->getInvoicePaymentStatus($custCode, $tochkaId);
            } catch (Throwable $e) {
                $this->log->warning("TochkaBillStatusSync [{$docId}]: " . $e->getMessage());
                continue;
            }

            if ($remoteStatus !== 'payment_paid') {
                continue;
            }

            $invoice = $this->entityManager->getEntityById('Document', $docId);
            if ($invoice === null || $invoice->get('paymentStatus') === Types::PAID) {
                continue;
            }

            $amount = round((float) ($invoice->get('amount') ?? 0), 2);
            $invoice->set([
                'paymentStatus' => Types::PAID,
                'paidAmount' => number_format($amount, 2, '.', ''),
                'paidAmountCurrency' => $invoice->get('amountCurrency') ?: 'RUB',
                'paidDate' => $invoice->get('paidDate') ?: $today,
            ]);

            $this->entityManager->saveEntity($invoice, [
                'silent' => true,
                InvoicePayments::RECALC_OPTION => true,
            ]);

            $orderIds = $orderCalc->findOrderIdsForDocument(
                $docId,
                (string) ($invoice->get('workOrderId') ?? '') ?: null
            );
            $orderCalc->recalcOrders($orderIds);
            $orderCalc->recalcContractHierarchy((string) ($invoice->get('contractId') ?? '') ?: null);

            $paidByTochka[] = [
                'operationId' => 'tochka_bill_' . $docId,
                'operationDate' => (string) ($invoice->get('paidDate') ?: $today),
                'operationAmount' => $amount,
                'description' => 'Статус счёта в API Точки: payment_paid',
                'accountName' => (string) ($r['account_name'] ?? ''),
                'invoiceId' => $docId,
                'invoiceName' => (string) ($r['name'] ?? ''),
                'invoiceAmount' => $amount,
                'status' => Types::PAID,
                'paidAmount' => $amount,
            ];
        }

        return $paidByTochka;
    }
}
