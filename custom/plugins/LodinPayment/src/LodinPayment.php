<?php

namespace LodinPayment;

use LodinPayment\Core\Checkout\Payment\LodinPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class LodinPayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertPaymentMethod($installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->upsertPaymentMethod($activateContext->getContext(), true);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->upsertPaymentMethod($updateContext->getContext());
    }

    private function upsertPaymentMethod(Context $context, bool $forceActive = false): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('handlerIdentifier', LodinPaymentHandler::class)
        );

        $existing = $paymentMethodRepository->search($criteria, $context)->first();

        $payload = [
            'name' => 'Lodin Payment',
            'technicalName' => 'lodin_payment',
            'description' => 'Lodin payment integration',
            'handlerIdentifier' => LodinPaymentHandler::class,
            'position' => 1,
        ];

        if ($existing !== null) {
            $payload['id'] = $existing->getId();

            if ($forceActive) {
                $payload['active'] = true;
            }

            $paymentMethodRepository->update([$payload], $context);
            return;
        }

        $payload['active'] = true;
        $paymentMethodRepository->create([$payload], $context);
    }
}