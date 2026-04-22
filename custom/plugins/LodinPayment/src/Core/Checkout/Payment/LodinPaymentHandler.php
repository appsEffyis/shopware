<?php declare(strict_types=1);

namespace LodinPayment\Core\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class LodinPaymentHandler extends AbstractPaymentHandler
{
    private const API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        $salesChannelId = method_exists($context->getSource(), 'getSalesChannelId')
            ? $context->getSource()->getSalesChannelId()
            : null;

        $clientId = (string) $this->systemConfigService->get('LodinPayment.config.clientId', $salesChannelId);
        $clientSecret = (string) $this->systemConfigService->get('LodinPayment.config.clientSecret', $salesChannelId);

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Lodin payment configuration is incomplete.');
        }

        $orderTransactionId = $transaction->getOrderTransactionId();

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw new \RuntimeException('Order transaction not found.');
        }

        $order = $orderTransaction->getOrder();

        if ($order === null) {
            throw new \RuntimeException('Order not found.');
        }

        $amount = number_format((float) $orderTransaction->getAmount()->getTotalPrice(), 2, '.', '');
        $invoiceId = sprintf('ORDER-%s-%s', $order->getOrderNumber(), substr($orderTransactionId, 0, 8));

        $returnUrl = $transaction->getReturnUrl();
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields['lodinInvoiceId'] = $invoiceId;

        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'customFields' => $customFields,
            ],
        ], $context);

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $payload = $clientId . $timestamp . $amount . $invoiceId;
        $signature = $this->generateSignature($payload, $clientSecret);

        $body = [
            'amount' => (float) $amount,
            'invoiceId' => $invoiceId,
            'paymentType' => 'INST',
            'cardId' => $invoiceId,
            'description' => 'Shopware Order #' . $order->getOrderNumber(),
            'returnUrl' => $returnUrl,
        ];

        $headers = [
            'Content-Type: application/json',
            'X-Client-Id: ' . $clientId,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-Extension-Code: SHOPWARE',
        ];

        $ch = curl_init(self::API_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($rawResponse === false) {
            curl_close($ch);

            $this->logger->error('Lodin create payment curl error', [
                'transactionId' => $orderTransactionId,
                'error' => $curlError,
            ]);

            throw new \RuntimeException('Curl error: ' . $curlError);
        }

        curl_close($ch);

        $response = json_decode($rawResponse, true);

        if (!is_array($response)) {
            $this->logger->error('Lodin create payment invalid response', [
                'transactionId' => $orderTransactionId,
                'httpCode' => $httpCode,
                'response' => $rawResponse,
            ]);

            throw new \RuntimeException('Invalid API response from Lodin.');
        }

        $paymentUrl = (string) (
            $response['url']
            ?? $response['paymentUrl']
            ?? $response['data']['url']
            ?? $response['data']['paymentUrl']
            ?? ''
        );

        if ($paymentUrl === '') {
            $this->logger->error('Lodin create payment returned no redirect URL', [
                'transactionId' => $orderTransactionId,
                'httpCode' => $httpCode,
                'response' => $response,
            ]);

            throw new \RuntimeException('No payment URL returned by Lodin.');
        }

        $updatedCustomFields = $orderTransaction->getCustomFields() ?? [];
        $updatedCustomFields['lodinInvoiceId'] = $invoiceId;
        $updatedCustomFields['lodinTransactionId'] = $response['transactionId'] ?? null;
        $updatedCustomFields['lodinCardId'] = $response['cardId'] ?? null;

        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'customFields' => $updatedCustomFields,
            ],
        ], $context);

        return new RedirectResponse($paymentUrl);
    }

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $type === PaymentHandlerType::REDIRECT;
    }

    private function generateSignature(string $payload, string $secret): string
    {
        $raw = hash_hmac('sha256', $payload, $secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}