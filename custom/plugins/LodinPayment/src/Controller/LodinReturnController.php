<?php declare(strict_types=1);

namespace LodinPayment\Controller;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class LodinReturnController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    #[Route(path: '/lodin/return', name: 'frontend.lodin.return', methods: ['GET'])]
    public function handleReturn(Request $request, Context $context): RedirectResponse
    {
        $transactionId = (string) $request->query->get('transactionId', '');

        if ($transactionId === '') {
            return new RedirectResponse('/lodin/fail');
        }

        for ($i = 0; $i < 5; $i++) {
            $criteria = new Criteria([$transactionId]);
            $criteria->addAssociation('stateMachineState');

            $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

            if (!$transaction instanceof OrderTransactionEntity) {
                sleep(1);
                continue;
            }

            $stateEntity = $transaction->getStateMachineState();

            if ($stateEntity === null) {
                sleep(1);
                continue;
            }

            $state = $stateEntity->getTechnicalName();

            if (in_array($state, ['paid', 'authorized'], true)) {
                return new RedirectResponse('/lodin/success');
            }

            if (in_array($state, ['failed', 'cancelled'], true)) {
                return new RedirectResponse('/lodin/fail');
            }

            if (in_array($state, ['open', 'in_progress', 'unconfirmed'], true)) {
                sleep(1);
                continue;
            }

            return new RedirectResponse('/lodin/fail');
        }

        return new RedirectResponse('/lodin/fail');
    }
}
