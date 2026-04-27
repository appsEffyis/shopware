<?php declare(strict_types=1);

namespace LodinPayment\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class LodinWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger
    ) {
    }
    
    #[Route(
        path: '/lodin/webhook',
        name: 'frontend.lodin.webhook',
        methods: ['POST']
    )]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $rawBody = (string) $request->getContent();

        $this->logger->info('Lodin Webhook: inbound', [
            'method' => $request->getMethod(),
            'content_type' => (string) $request->headers->get('Content-Type'),
            'content_length' => strlen($rawBody),
            'body_preview' => $this->snippet($rawBody),
        ]);         

        try {
            $payload = [];

            if ($rawBody !== '') {
                $trimmed = ltrim($rawBody);

                if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    try {
                        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                        $payload = is_array($decoded) ? $decoded : [];
                    } catch (\Throwable $e) {
                        $this->logger->warning('Lodin Webhook: JSON parse failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if ($payload === []) {
                    parse_str($rawBody, $parsed);
                    if (is_array($parsed) && $parsed !== []) {
                        $payload = $parsed;
                    }
                }
            }

            if ($payload === []) {
                $payload = $request->request->all();
            }

            if ($payload === []) {
                $payload = $request->query->all();
            }

            $data = $payload;
            if (isset($payload['data']) && is_array($payload['data'])) {
                $data = $payload['data'];
            }

            $eventType = $this->firstNonEmptyString([
                $data['eventType'] ?? null,
                $payload['eventType'] ?? null,
                $data['eventName'] ?? null,
                $payload['eventName'] ?? null,
                $data['event'] ?? null,
                $payload['event'] ?? null,
                $data['type'] ?? null,
                $payload['type'] ?? null,
            ]);

            $invoiceId = $this->firstNonEmptyString([
                $data['invoiceId'] ?? null,
                $data['invoice_id'] ?? null,
                $payload['invoiceId'] ?? null,
                $payload['invoice_id'] ?? null,
            ]);

            $transactionId = $this->firstNonEmptyString([
                $data['transactionId'] ?? null,
                $data['transaction_id'] ?? null,
                $payload['transactionId'] ?? null,
                $payload['transaction_id'] ?? null,
            ]);

            $cardId = $this->firstNonEmptyString([
                $data['cardId'] ?? null,
                $data['card_id'] ?? null,
                $payload['cardId'] ?? null,
                $payload['card_id'] ?? null,
            ]);

            $status = strtolower((string) (
                $payload['status']
                ?? $payload['paymentStatus']
                ?? $data['status']
                ?? $data['paymentStatus']
                ?? ''
            ));

            $boolSuccess = $this->coerceBool(
                $payload['success']
                ?? $data['success']
                ?? $payload['paymentSuccess']
                ?? null
            );

            $this->logger->info('Lodin Webhook: parsed', [
                'eventType' => $eventType,
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'cardId' => $cardId,
                'status' => $status,
                'success' => $boolSuccess,
            ]);

            $transaction = $this->findOrderTransaction($invoiceId, $transactionId, $cardId, $context);

            if ($transaction === null) {
                return new JsonResponse(['ok' => false, 'message' => 'transaction not found'], 404);
            }

            $order = $transaction->getOrder();

            if ($order === null) {
                return new JsonResponse(['ok' => false, 'message' => 'order not found'], 404);
            }

            $shopwareAmount = (float) $transaction->getAmount()->getTotalPrice();

            $lodinAmount = (float) (
                $payload['amount']
                ?? $data['amount']
                ?? 0
            );

            if (round($shopwareAmount, 2) !== round($lodinAmount, 2)) {

                $this->logger->error('Amount mismatch', [
                    'shopware' => $shopwareAmount,
                    'lodin' => $lodinAmount,
                ]);

                return new JsonResponse([
                    'ok' => false,
                    'message' => 'amount mismatch'
                ], 400);
            }

            if ($transaction === null) {
                $this->logger->warning('Lodin Webhook: order transaction not found', [
                    'invoiceId' => $invoiceId,
                    'transactionId' => $transactionId,
                    'cardId' => $cardId,
                ]);

                return new JsonResponse(['ok' => true, 'message' => 'transaction not found']);
            }

            $transitionAction = $this->resolveTransitionAction($eventType, $status, $boolSuccess);

            if ($transitionAction === null) {
                $this->logger->warning('Lodin Webhook: no state change', [
                    'transactionId' => $transaction->getId(),
                    'eventType' => $eventType,
                    'status' => $status,
                    'success' => $boolSuccess,
                ]);

                return new JsonResponse(['ok' => true, 'message' => 'no state change']);
            }

            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderTransactionDefinition::ENTITY_NAME,
                    $transaction->getId(),
                    $transitionAction,
                    'stateId'
                ),
                $context
            );

            $this->logger->info('Lodin Webhook: transition applied', [
                'transactionId' => $transaction->getId(),
                'transition' => $transitionAction,
            ]);

            return new JsonResponse([
                'ok' => true,
                'transition' => $transitionAction,
                'transactionId' => $transaction->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Lodin Webhook error', [
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function findOrderTransaction(
        string $invoiceId,
        string $transactionId,
        string $cardId,
        Context $context
    ): ?object {
        $filters = [];

        if ($invoiceId !== '') {
            $filters[] = new EqualsFilter('customFields.lodinInvoiceId', $invoiceId);
        }

        if ($transactionId !== '') {
            $filters[] = new EqualsFilter('customFields.lodinTransactionId', $transactionId);
        }

        if ($cardId !== '') {
            $filters[] = new EqualsFilter('customFields.lodinCardId', $cardId);
        }

        if ($filters === []) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, $filters));

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    private function resolveTransitionAction(
        string $eventType,
        string $status,
        ?bool $success
    ): ?string {
        $event = strtolower($eventType);

        $isSuccess =
            $success === true
            || str_contains($event, 'payment.succeeded')
            || str_contains($event, 'payment.completed')
            || str_contains($event, 'success')
            || str_contains($event, 'completed')
            || in_array($status, [
                'succeeded', 'completed', 'paid', 'success', 'authorized', 'approved'
            ], true);

        $isFailed =
            $success === false
            || str_contains($event, 'payment.failed')
            || str_contains($event, 'declined')
            || str_contains($event, 'failed')
            || in_array($status, [
                'failed', 'declined', 'cancelled', 'canceled', 'error', 'rejected'
            ], true);

        if ($isSuccess) {
            if (in_array($status, ['authorized', 'approved'], true)) {
                return StateMachineTransitionActions::ACTION_AUTHORIZE;
            }

            return StateMachineTransitionActions::ACTION_PAID;
        }

        if ($isFailed) {
            if (in_array($status, ['cancelled', 'canceled'], true)) {
                return StateMachineTransitionActions::ACTION_CANCEL;
            }

            return StateMachineTransitionActions::ACTION_FAIL;
        }

        if (in_array($status, ['processing', 'in_progress'], true)) {
            return 'process';
        }

        return null;
    }

    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $s = trim((string) $value); 
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }

    private function snippet(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? (string) mb_substr($raw, 0, 1000, 'UTF-8')
            : (string) substr($raw, 0, 1000);
    }

    private function coerceBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1 ? true : (((int) $value) === 0 ? false : null);
        }

        $s = strtolower(trim((string) $value));

        if (in_array($s, ['1', 'true', 'yes', 'ok', 'success'], true)) {
            return true;
        }

        if (in_array($s, ['0', 'false', 'no', 'failed', 'fail'], true)) {
            return false;
        }

        return null;
    }
}