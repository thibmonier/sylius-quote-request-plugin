<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;
use Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop\QuoteMessageType;
use Tmonier\SyliusQuoteRequestPlugin\Pdf\QuotePdfGeneratorInterface;
use Tmonier\SyliusQuoteRequestPlugin\Repository\QuoteRequestRepositoryInterface;
use Tmonier\SyliusQuoteRequestPlugin\Workflow\QuoteRequestStatuses;

final class QuoteRequestShowController extends AbstractController
{
    use ShopQuoteHelperTrait;

    public function __construct(
        private readonly QuoteRequestRepositoryInterface $quoteRequestRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly CustomerContextInterface $customerContext,
        private readonly QuotePdfGeneratorInterface $pdfGenerator,
    ) {
    }

    public function showByTokenAction(Request $request, string $token): Response
    {
        return $this->renderQuote($this->findByToken($token), false);
    }

    public function pdfAction(string $token): Response
    {
        $quoteRequest = $this->findByToken($token);
        if (!$this->pdfGenerator->isAvailable() || !in_array($quoteRequest->getStatus(), QuoteRequestStatuses::WITH_PROPOSAL, true)) {
            throw new NotFoundHttpException('No PDF for this quote.');
        }

        return new Response($this->pdfGenerator->generate($quoteRequest), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->pdfGenerator->getFilename($quoteRequest)),
        ]);
    }

    public function accountIndexAction(): Response
    {
        $customer = $this->shopCustomer();
        if (!$customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/account/index.html.twig', [
            'quote_requests' => $this->quoteRequestRepository->findByCustomer($customer),
        ]);
    }

    public function accountShowAction(Request $request, string $number): Response
    {
        $customer = $this->shopCustomer();
        if (!$customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            return $this->redirectToRoute('sylius_shop_login');
        }

        $quoteRequest = $this->quoteRequestRepository->findOneByNumberAndCustomer($number, $customer);
        if (!$quoteRequest instanceof QuoteRequestInterface) {
            throw new NotFoundHttpException('Quote request not found.');
        }

        return $this->renderQuote($quoteRequest, true);
    }

    private function renderQuote(QuoteRequestInterface $quoteRequest, bool $fromAccount): Response
    {
        $messageForm = $this->createForm(QuoteMessageType::class, null, [
            'action' => $this->generateUrl('tmonier_quote_request_shop_message', ['token' => $quoteRequest->getToken()]),
        ]);

        return $this->render('@TmonierSyliusQuoteRequestPlugin/shop/quote_request/show.html.twig', [
            'quote_request' => $quoteRequest,
            'message_form' => $this->isOpen($quoteRequest) ? $messageForm->createView() : null,
            'from_account' => $fromAccount,
        ]);
    }
}
