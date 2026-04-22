<?php declare(strict_types=1);

namespace LodinPayment\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

class LodinReturnController
{
    #[Route('/lodin/return', name: 'lodin.return', methods: ['GET'])]
    public function return(Request $request): RedirectResponse
    {
        $orderId = $request->query->get('orderId');

        return new RedirectResponse(
            '/checkout/finish?orderId=' . $orderId
        );
    }
}
