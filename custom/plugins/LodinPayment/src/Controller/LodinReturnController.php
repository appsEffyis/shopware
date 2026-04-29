<?php

namespace LodinPayment\Controller;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class LodinReturnController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    #[Route(
        path: '/lodin/return',
        name: 'frontend.lodin.return',
        methods: ['GET'],
        defaults: ['_csrf_protected' => false]
    )]
    public function return(Request $request, Context $context): RedirectResponse
    {
        $orderId = (string) $request->query->get('orderId', '');
        $transactionId = (string) $request->query->get('transactionId', '');

        if ($orderId === '') {
            return new RedirectResponse('/checkout/cart');
        }

        if ($transactionId === '') {
            return new RedirectResponse(
                '/checkout/finish?orderId=' . urlencode($orderId) . '&paymentFailed=1'
            );
        }

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('stateMachineState');

        $transaction = $this->orderTransactionRepository
            ->search($criteria, $context)
            ->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            return new RedirectResponse(
                '/checkout/finish?orderId=' . urlencode($orderId) . '&paymentFailed=1'
            );
        }

        $technicalName = $transaction->getStateMachineState()?->getTechnicalName();

        if (in_array($technicalName, ['paid', 'paid_partially', 'authorized'], true)) {
            return new RedirectResponse(
                '/checkout/finish?orderId=' . urlencode($orderId)
            );
        }

        if (in_array($technicalName, ['failed', 'cancelled', 'canceled'], true)) {
            return new RedirectResponse(
                '/checkout/finish?orderId=' . urlencode($orderId) . '&paymentFailed=1'
            );
        }

        return new RedirectResponse(
            '/checkout/finish?orderId=' . urlencode($orderId) . '&paymentFailed=1'
        );
    }
}