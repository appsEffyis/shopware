<?php declare(strict_types=1);

namespace LodinPayment;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class LodinPayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->createPaymentMethod($installContext->getContext());
    }

    private function createPaymentMethod(Context $context): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        $existing = $paymentMethodRepository->search(
            (new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria())
                ->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('handlerIdentifier', \LodinPayment\Core\Checkout\Payment\LodinPaymentHandler::class)),
            $context
        );

        if ($existing->count() > 0) {
            return;
        }

        $paymentMethodRepository->create([
    [
        'name' => 'Lodin Payment',
        'technicalName' => 'lodin_payment',
        'description' => 'Custom Lodin API Payment',
        'handlerIdentifier' => \LodinPayment\Core\Checkout\Payment\LodinPaymentHandler::class,
        'active' => true,
        'position' => 1,
    ]
], $context);
    }
}
